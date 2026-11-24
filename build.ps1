<#
.SYNOPSIS
    Builds patched PHP 7.3.33 / 7.4.33 for Windows with every patch in this repository.

.DESCRIPTION
    For each requested PHP version, thread-safety variant and architecture the script:

      1. downloads the official PHP source tarball and verifies its SHA-256,
      2. applies every patch from patches/php-<series>/ in ascending filename
         order (each patch is dry-run first; no fuzz is allowed),
      3. downloads the PHP SDK binary tools and the prebuilt vc15 dependency
         libraries published on windows.php.net,
      4. configures PHP with the same options as the official windows.php.net
         snapshot builds and runs "nmake snap", which produces the release zip
         with the same layout as the official one
         (php-<version>[-nts]-Win32-<VC15|vc15>-<arch>.zip, plus the debug,
         devel and test packs),
      5. verifies the result: extracts the zip, checks that php.exe runs, that
         every bundled extension loads, and runs this repository's PHPT tests
         with the built binaries and, as a baseline, with the official
         unpatched build of the same release. A test that passes on the
         official build but fails on the patched one fails the verification.

    Differences from the official builds: the Oracle extensions (oci8_12c,
    pdo_oci) are not built because they need the proprietary Oracle Instant
    Client SDK, and the binaries are not PGO-optimized.

    Requires Visual Studio 2022 (or Build Tools) with the MSVC v141 (14.16)
    toolset. Run build-install-deps.ps1 first to install it.

.PARAMETER Version
    PHP series to build: 7.3, 7.4 or both (default).

.PARAMETER ThreadSafety
    nts, ts or both (default).

.PARAMETER Arch
    x64, x86 (32-bit) or both (default).

.PARAMETER WorkDir
    Directory for downloads, sources, dependencies and objects. Must not
    contain spaces (the PHP build system does not support them).
    Defaults to .\build next to this script.

.PARAMETER OutputDir
    Directory the resulting zip files are copied to. Defaults to .\dist.

.PARAMETER SkipTests
    Do not run the post-build verification.

.PARAMETER VerifyOnly
    Skip building; re-run the verification of builds already present in WorkDir.

.PARAMETER TestTimeoutSeconds
    Time limit for a single PHPT test during verification (default 120 s).
    Unpatched baseline binaries can hang on some security tests.

.EXAMPLE
    .\build.ps1
    Builds all eight variants: 7.3 and 7.4, NTS and TS, x64 and x86.

.EXAMPLE
    .\build.ps1 -Version 7.4 -ThreadSafety nts -Arch x86
#>
[CmdletBinding()]
param(
    [ValidateSet('7.3', '7.4')]
    [string[]]$Version = @('7.3', '7.4'),

    [ValidateSet('nts', 'ts')]
    [string[]]$ThreadSafety = @('nts', 'ts'),

    [ValidateSet('x64', 'x86')]
    [string[]]$Arch = @('x64', 'x86'),

    [string]$WorkDir,

    [string]$OutputDir,

    [switch]$SkipTests,

    # Re-run only the verification of an existing build in WorkDir.
    [switch]$VerifyOnly,

    # Per-test time limit for the PHPT verification.
    [int]$TestTimeoutSeconds = 120
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12

# --- Pinned inputs -----------------------------------------------------------

$PhpSources = @{
    '7.3' = @{ Version = '7.3.33'; Sha256 = '9a369c32c6f52036b0a890f290327f148a1904ee66aa56e2c9a7546da6525ec8' }
    '7.4' = @{ Version = '7.4.33'; Sha256 = '5a2337996f07c8a097e03d46263b5c98d2c8e355227756351421003bea8f463e' }
}
$SdkVersion = '2.9.0'
$SdkUrl = "https://github.com/php/php-sdk-binary-tools/archive/refs/tags/php-sdk-$SdkVersion.zip"
$Crt = 'vc15'
$VcToolset = '14.16'   # MSVC v141, the compiler used for official PHP 7.3/7.4 builds

# --- Helpers ----------------------------------------------------------------

function Write-Step([string]$Message) {
    Write-Host ''
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Invoke-Download([string]$Url, [string]$Destination) {
    if (Test-Path $Destination) { return }
    Write-Host "Downloading $Url"
    $partial = "$Destination.partial"
    for ($attempt = 1; ; $attempt++) {
        try {
            Invoke-WebRequest -Uri $Url -OutFile $partial -UseBasicParsing
            break
        } catch {
            if ($attempt -ge 3) { throw "Download of $Url failed: $_" }
            Start-Sleep -Seconds (5 * $attempt)
        }
    }
    Move-Item -Force $partial $Destination
}

function Reset-Directory([string]$Path) {
    if (Test-Path $Path) {
        # Long paths inside object trees can defeat Remove-Item; rd copes.
        cmd /c "rd /s /q `"$Path`"" | Out-Null
        if (Test-Path $Path) { Remove-Item -Recurse -Force $Path }
    }
    New-Item -ItemType Directory -Force $Path | Out-Null
}

function Assert-Toolchain {
    $vswhere = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio\Installer\vswhere.exe'
    $found = $null
    if (Test-Path $vswhere) {
        $found = & $vswhere -latest -products * `
            -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 Microsoft.VisualStudio.Component.VC.v141.x86.x64 `
            -property installationPath
    }
    if (-not $found) {
        throw 'Visual Studio 2022 with the MSVC v141 (14.16) toolset was not found. Run .\build-install-deps.ps1 first.'
    }
    Write-Host "Visual Studio: $found (toolset $VcToolset)"
}

function Get-Sdk {
    $sdkRoot = Join-Path $WorkDir "php-sdk-$SdkVersion"
    if (-not (Test-Path (Join-Path $sdkRoot 'phpsdk-starter.bat'))) {
        $zip = Join-Path $DownloadDir "php-sdk-binary-tools-$SdkVersion.zip"
        Invoke-Download $SdkUrl $zip
        $tmp = Join-Path $WorkDir 'php-sdk.tmp'
        Reset-Directory $tmp
        Expand-Archive -Path $zip -DestinationPath $tmp
        $inner = Get-ChildItem $tmp -Directory | Select-Object -First 1
        if (Test-Path $sdkRoot) { Remove-Item -Recurse -Force $sdkRoot }
        Move-Item $inner.FullName $sdkRoot
        Remove-Item -Recurse -Force $tmp
    }
    return $sdkRoot
}

# Runs a batch file inside the PHP SDK shell (vcvarsall + SDK tools on PATH).
function Invoke-SdkTask([string]$SdkRoot, [string]$Arch, [string]$TaskFile, [string]$LogFile) {
    $starter = Join-Path $SdkRoot 'phpsdk-starter.bat'
    $command = "`"$starter`" -c $Crt -a $Arch -s $VcToolset -t `"$TaskFile`" 2>&1"
    & cmd.exe /d /c $command | Tee-Object -FilePath $LogFile | Write-Host
    return $LASTEXITCODE
}

function Expand-PhpSource([string]$Series, [string]$Destination) {
    $info = $PhpSources[$Series]
    $tarball = Join-Path $DownloadDir "php-$($info.Version).tar.gz"
    Invoke-Download "https://www.php.net/distributions/php-$($info.Version).tar.gz" $tarball
    $hash = (Get-FileHash -Algorithm SHA256 $tarball).Hash.ToLowerInvariant()
    if ($hash -ne $info.Sha256) {
        Remove-Item -Force $tarball
        throw "SHA-256 mismatch for $tarball (got $hash, expected $($info.Sha256))."
    }
    Write-Host "Verified php-$($info.Version).tar.gz SHA-256 $hash"
    & tar.exe -xf $tarball -C $Destination
    if ($LASTEXITCODE -ne 0) { throw "Extracting $tarball failed." }
    return Join-Path $Destination "php-$($info.Version)"
}

function Install-Patches([string]$SdkRoot, [string]$Series, [string]$SourceDir) {
    # Native stderr redirected with 2>&1 must not become a terminating error.
    $ErrorActionPreference = 'Continue'
    $patchExe = Join-Path $SdkRoot 'msys2\usr\bin\patch.exe'
    $patches = @(Get-ChildItem (Join-Path $RepoRoot "patches\php-$Series") -Filter '*.patch' | Sort-Object Name)
    if ($patches.Count -eq 0) { throw "No patches found for PHP $Series." }
    foreach ($patch in $patches) {
        Write-Host "Applying $($patch.Name)"
        foreach ($dryRun in $true, $false) {
            $patchArgs = @('-d', $SourceDir, '-f', '-F', '0', '-p1', '-i', $patch.FullName)
            if ($dryRun) { $patchArgs = @('--dry-run') + $patchArgs }
            $output = & $patchExe @patchArgs 2>&1 | ForEach-Object { "$_" }
            if ($LASTEXITCODE -ne 0) {
                $output | Write-Host
                throw "Patch $($patch.Name) does not apply to PHP $Series."
            }
        }
        $output | Where-Object { $_ -notmatch '^(patching file|\(Stripping trailing CRs)' } |
            ForEach-Object { Write-Host "    $_" }
    }
    return $patches.Count
}

function Expand-PhpZip([string]$ZipPath, [string]$Destination) {
    Reset-Directory $Destination
    Expand-Archive -Path $ZipPath -DestinationPath $Destination
}

# Writes a php.ini enabling the given extensions, like a typical production setup.
function Set-TestIni([string]$PhpDir, [string[]]$Extensions) {
    $extDir = Join-Path $PhpDir 'ext'
    $ini = @("extension_dir=`"$extDir`"")
    foreach ($name in $Extensions) {
        # snmp prints MIB lookup noise into every script output when no MIBs are installed.
        if ($name -in 'php_opcache.dll', 'php_zend_test.dll', 'php_snmp.dll') { continue }
        if (Test-Path (Join-Path $extDir $name)) { $ini += "extension=$name" }
    }
    Set-Content -Path (Join-Path $PhpDir 'php.ini') -Value $ini -Encoding ascii
}

# Runs the PHPT files listed in $ListFile with the php.exe in $PhpDir and
# returns a hashtable: test path -> PASS/FAIL/SKIP/BORK/LEAK/...
function Invoke-Phpt([string]$PhpDir, [string]$SourceDir, [string]$ListFile, [string]$ResultFile) {
    $ErrorActionPreference = 'Continue'
    $php = Join-Path $PhpDir 'php.exe'
    $saved = @{}
    $vars = @{
        TEST_PHP_EXECUTABLE     = $php
        TEST_PHP_CGI_EXECUTABLE = (Join-Path $PhpDir 'php-cgi.exe')
        OPENSSL_CONF            = (Join-Path $PhpDir 'extras\ssl\openssl.cnf')
        NO_INTERACTION          = '1'
        SKIP_IO_CAPTURE_TESTS   = '1'
    }
    foreach ($key in $vars.Keys) {
        $saved[$key] = [Environment]::GetEnvironmentVariable($key)
        [Environment]::SetEnvironmentVariable($key, $vars[$key])
    }
    # run-tests.php's own per-test timeout does not work on Windows (it relies on
    # stream_select() on pipes), and an unpatched binary can loop forever on a
    # security test. A watchdog kills test processes of this PHP that run too long.
    $watchdog = Start-Job -ArgumentList $PhpDir, $TestTimeoutSeconds -ScriptBlock {
        param($dir, $limit)
        $prefix = (Join-Path $dir 'php').ToLowerInvariant()
        while ($true) {
            Start-Sleep -Seconds 5
            Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='php-cgi.exe'" |
                Where-Object {
                    $_.ExecutablePath -and $_.ExecutablePath.ToLowerInvariant().StartsWith($prefix) -and
                    $_.CommandLine -notmatch 'run-tests\.php' -and
                    ((Get-Date) - $_.CreationDate).TotalSeconds -gt $limit
                } |
                ForEach-Object {
                    Write-Output "killed after ${limit}s: $($_.CommandLine)"
                    Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
                }
        }
    }
    Push-Location $SourceDir
    try {
        $output = & $php run-tests.php -q -p $php -r $ListFile 2>&1 | ForEach-Object { "$_" }
    } finally {
        Pop-Location
        foreach ($key in $vars.Keys) { [Environment]::SetEnvironmentVariable($key, $saved[$key]) }
        Stop-Job $watchdog
        $killed = @(Receive-Job $watchdog)
        Remove-Job $watchdog -Force
    }
    foreach ($line in $killed) {
        if ($line -match '-f "?([^"]+)\.php"?') {
            Write-Host "  Watchdog: test $([IO.Path]::GetFileName($Matches[1])).phpt exceeded $TestTimeoutSeconds s and was killed"
        }
    }
    $output | Set-Content -Path $ResultFile -Encoding utf8
    $results = @{}
    foreach ($line in $output) {
        if ($line -match '^(PASS|FAIL|SKIP|BORK|LEAK|WARN|XFAIL)\s.*\[([^\]]+\.phpt)\]') {
            $results[$Matches[2]] = $Matches[1]
        }
    }
    return $results
}

function Test-Build([string]$Series, [string]$Variant, [string]$Arch, [string]$SourceDir, [string]$ZipPath, [string]$VerifyDir) {
    $phpDir = Join-Path $VerifyDir 'php'
    Expand-PhpZip $ZipPath $phpDir
    # Native stderr redirected with 2>&1 must not become a terminating error.
    $ErrorActionPreference = 'Continue'
    $php = Join-Path $phpDir 'php.exe'
    $failures = New-Object System.Collections.Generic.List[string]

    # 1. Binary runs and reports the expected version and thread safety.
    $versionOutput = (& $php -n -v) -join "`n"
    Write-Host $versionOutput
    if ($versionOutput -notmatch [regex]::Escape("PHP $($PhpSources[$Series].Version)")) {
        $failures.Add('php -v does not report the expected version')
    }
    $expectedTs = if ($Variant -eq 'ts') { 'ZTS' } else { 'NTS' }
    if ($versionOutput -notmatch "\(\s*$expectedTs") { $failures.Add("php -v does not report $expectedTs") }

    # 2. Every bundled extension loads without warnings. Some extensions need a
    #    third-party client library that is not part of the PHP distribution
    #    (the same applies to the official builds); they are reported only.
    $externalRuntime = @{ 'php_pdo_firebird.dll' = 'fbclient.dll'; 'php_interbase.dll' = 'fbclient.dll'; 'php_oci8_12c.dll' = 'oci.dll'; 'php_pdo_oci.dll' = 'oci.dll' }
    $extDir = Join-Path $phpDir 'ext'
    $extensions = @(Get-ChildItem $extDir -Filter 'php_*.dll' |
        Where-Object { $_.Name -ne 'php_phpdbg_webhelper.dll' })   # phpdbg-only module
    $loaded = @()
    foreach ($ext in $extensions) {
        $directive = if ($ext.Name -eq 'php_opcache.dll') { 'zend_extension' } else { 'extension' }
        $out = (& $php -n -d "extension_dir=$extDir" -d "$directive=$($ext.Name)" -d display_startup_errors=1 -r 'echo 42;' 2>&1) -join "`n"
        if ($LASTEXITCODE -eq 0 -and $out -match '42$' -and $out -notmatch 'Warning|Unable to load|Fatal') {
            $loaded += $ext.Name
        } elseif ($externalRuntime.ContainsKey($ext.Name) -and -not (Get-Command $externalRuntime[$ext.Name] -ErrorAction SilentlyContinue)) {
            Write-Host "  $($ext.Name) not loaded: requires $($externalRuntime[$ext.Name]), which is not part of PHP"
        } else {
            $failures.Add("extension $($ext.Name) failed to load: $out")
        }
    }
    Write-Host "Extensions loaded OK: $($loaded.Count)/$($extensions.Count)"

    # 3. This repository's PHPT tests, run with the patched build and with the
    #    official (unpatched) build of the same release as a baseline.
    #    PASS on patched + FAIL on official  -> fix confirmed
    #    FAIL on patched + PASS on official  -> regression (verification fails)
    #    FAIL on both                        -> test does not work on Windows
    $testsSource = Join-Path $RepoRoot "tests\php-$Series"
    if (-not (Test-Path $testsSource)) { return , $failures }
    Copy-Item -Recurse -Force (Join-Path $testsSource '*') $SourceDir
    $testFiles = @(Get-ChildItem $testsSource -Recurse -Filter '*.phpt' | ForEach-Object {
        $_.FullName.Substring($testsSource.Length + 1)
    })
    $listFile = Join-Path $VerifyDir 'tests.lst'
    Set-Content -Path $listFile -Value $testFiles -Encoding ascii

    Set-TestIni $phpDir $loaded
    $patched = Invoke-Phpt $phpDir $SourceDir $listFile (Join-Path $VerifyDir 'phpt-patched.txt')

    $zipName = Split-Path -Leaf $ZipPath
    $officialZip = Join-Path $DownloadDir "official-$zipName"
    $official = @{}
    try {
        Invoke-Download "https://windows.php.net/downloads/releases/archives/$zipName" $officialZip
        $officialDir = Join-Path $VerifyDir 'official'
        Expand-PhpZip $officialZip $officialDir
        Set-TestIni $officialDir $loaded
        $official = Invoke-Phpt $officialDir $SourceDir $listFile (Join-Path $VerifyDir 'phpt-official.txt')
    } catch {
        Write-Host "  Official baseline unavailable ($_); every FAIL counts as a failure." -ForegroundColor Yellow
    }

    $rows = foreach ($test in ($patched.Keys | Sort-Object)) {
        $p = $patched[$test]
        $o = if ($official.ContainsKey($test)) { $official[$test] } else { '-' }
        $verdict = switch ($true) {
            ($p -eq 'PASS' -and $o -in 'FAIL', 'BORK', 'LEAK') { 'fix confirmed'; break }
            ($p -eq 'PASS') { 'pass'; break }
            ($p -eq 'SKIP') { 'skipped'; break }
            ($p -in 'FAIL', 'BORK', 'LEAK' -and $o -eq 'PASS') { 'REGRESSION'; break }
            ($p -in 'FAIL', 'BORK', 'LEAK' -and $o -in 'FAIL', 'BORK', 'LEAK', 'SKIP') { 'fails on official too (environment)'; break }
            ($p -in 'FAIL', 'BORK', 'LEAK') { 'FAIL'; break }
            default { $p }
        }
        [pscustomobject]@{ Test = $test; Patched = $p; Official = $o; Verdict = $verdict }
    }
    $report = Join-Path $VerifyDir 'phpt-report.txt'
    $rows | Format-Table -AutoSize | Out-String -Width 300 | Set-Content -Path $report -Encoding utf8
    $rows | Format-Table -AutoSize | Out-String -Width 300 | Write-Host
    $rows | Group-Object Verdict | ForEach-Object { Write-Host ("  {0,-40} {1}" -f $_.Name, $_.Count) }
    Write-Host "PHPT report: $report"
    foreach ($row in $rows | Where-Object { $_.Verdict -in 'REGRESSION', 'FAIL' }) {
        $failures.Add("PHPT $($row.Verdict): $($row.Test)")
    }

    return , $failures
}
# --- Main --------------------------------------------------------------------

$RepoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $WorkDir) { $WorkDir = Join-Path $RepoRoot 'build' }
if (-not $OutputDir) { $OutputDir = Join-Path $RepoRoot 'dist' }
if ($WorkDir -match '\s') { throw "WorkDir '$WorkDir' contains whitespace, which the PHP build system does not support." }
New-Item -ItemType Directory -Force $WorkDir, $OutputDir | Out-Null
$WorkDir = (Resolve-Path $WorkDir).Path
$OutputDir = (Resolve-Path $OutputDir).Path
$DownloadDir = Join-Path $WorkDir 'downloads'
New-Item -ItemType Directory -Force $DownloadDir | Out-Null

Write-Step 'Checking toolchain'
Assert-Toolchain
$sdkRoot = Get-Sdk
Write-Host "PHP SDK: $sdkRoot"

$summary = @()
foreach ($series in $Version) {
    foreach ($targetArch in $Arch) {   # not $arch: PowerShell names are case-insensitive
        foreach ($variant in $ThreadSafety) {
            $name = "php-$series-$variant-$targetArch"
            $stamp = Get-Date
            $buildRoot = Join-Path $WorkDir $name
            $sourceDir = Join-Path $buildRoot "php-$($PhpSources[$series].Version)"
            $logFile = Join-Path $buildRoot 'build.log'
            $patchCount = @(Get-ChildItem (Join-Path $RepoRoot "patches\php-$series") -Filter '*.patch').Count
            if (-not $VerifyOnly) {
            Write-Step "Building $name"
            Reset-Directory $buildRoot

            $sourceDir = Expand-PhpSource $series $buildRoot
            $patchCount = Install-Patches $sdkRoot $series $sourceDir
            Write-Host "Applied $patchCount patches."

            $depsDir = Join-Path $WorkDir "deps\$series-$Crt-$targetArch"
            New-Item -ItemType Directory -Force $depsDir | Out-Null
            # configure requires the object directory to exist.
            New-Item -ItemType Directory -Force (Join-Path $buildRoot 'obj') | Out-Null

            $configureArgs = @(
                '--enable-snapshot-build',
                '--enable-debug-pack',
                '--enable-com-dotnet=shared',
                '--without-analyzer',
                '--enable-object-out-dir=..\obj\',
                "--with-php-build=$depsDir"
            )
            if ($variant -eq 'nts') { $configureArgs = @('--disable-zts') + $configureArgs }

            $taskFile = Join-Path $buildRoot 'build-task.bat'
            @(
                '@echo on',
                "cd /d `"$sourceDir`" || exit /b 1",
                "call phpsdk_deps.bat --update --no-backup --branch $series --crt $Crt --arch $targetArch --deps `"$depsDir`" || exit /b 1",
                # phpsdk_deps changes the working directory.
                "cd /d `"$sourceDir`" || exit /b 1",
                'call .\buildconf.bat --force || exit /b 1',
                "call .\configure.bat $($configureArgs -join ' ') || exit /b 1",
                'nmake || exit /b 1',
                'nmake snap || exit /b 1'
            ) | Set-Content -Path $taskFile -Encoding ascii

            $exitCode = Invoke-SdkTask $sdkRoot $targetArch $taskFile $logFile
            if ($exitCode -ne 0) { throw "Build of $name failed (exit code $exitCode). See $logFile." }
            }

            # nmake snap writes the archives into the build directory, e.g. obj\Release.
            $objDir = Join-Path $buildRoot 'obj'
            $fullVersion = $PhpSources[$series].Version
            $ntsPart = if ($variant -eq 'nts') { '-nts' } else { '' }
            $mainPattern = "php-$fullVersion$ntsPart-Win32-$Crt-$targetArch.zip"
            $mainZip = Get-ChildItem $objDir -Recurse -Filter '*.zip' |
                Where-Object { $_.Name -like $mainPattern } | Select-Object -First 1
            if (-not $mainZip) {
                if ($VerifyOnly) { throw "No existing build of $name found in $objDir." }
                throw "nmake snap did not produce $mainPattern. See $logFile."
            }
            $packages = @(Get-ChildItem $mainZip.DirectoryName -Filter 'php-*.zip')
            foreach ($package in $packages) {
                Copy-Item -Force $package.FullName $OutputDir
            }
            $outputZip = Join-Path $OutputDir $mainZip.Name

            $testFailures = @()
            if (-not $SkipTests) {
                Write-Step "Verifying $($mainZip.Name)"
                $testFailures = Test-Build $series $variant $targetArch $sourceDir $outputZip (Join-Path $buildRoot 'verify')
                if ($testFailures.Count -gt 0) {
                    $testFailures | ForEach-Object { Write-Host "  FAILED: $_" -ForegroundColor Red }
                }
            }

            $summary += [pscustomobject]@{
                Build    = $name
                Patches  = $patchCount
                Zip      = $outputZip
                SHA256   = (Get-FileHash -Algorithm SHA256 $outputZip).Hash.ToLowerInvariant()
                Verified = if ($SkipTests) { 'skipped' } elseif ($testFailures.Count -eq 0) { 'ok' } else { "$($testFailures.Count) failure(s)" }
                Minutes  = [math]::Round(((Get-Date) - $stamp).TotalMinutes, 1)
            }
        }
    }
}

Write-Step 'Summary'
$summary | Format-List | Out-String -Width 200 | Write-Host
if ($summary | Where-Object { $_.Verified -notin 'ok', 'skipped' }) {
    exit 1
}
