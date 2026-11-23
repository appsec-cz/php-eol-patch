<#
.SYNOPSIS
    Installs the toolchain needed by build.ps1 to build patched PHP 7.3/7.4 for Windows.

.DESCRIPTION
    Official PHP 7.3 and 7.4 Windows builds are "vc15" builds, compiled with the
    MSVC v141 toolset (14.16, Visual Studio 2017). PHP 7.4's build system does not
    recognize newer compilers, and the prebuilt vc15 dependency libraries match
    that toolset, so the build requires it.

    This script uses winget to install Visual Studio 2022 Build Tools with:
      - MSVC v143 build tools (required by the PHP SDK to locate Visual Studio)
      - MSVC v141 build tools (14.16), the toolset actually used to compile PHP
      - Windows 11 SDK

    If a Visual Studio 2022 instance (any edition) is already present, the
    missing components are added to it with the Visual Studio Installer, which
    winget itself uses for these packages, instead of installing a second copy.

    Everything else the build needs (PHP SDK binary tools with patch, bison,
    re2c and 7-Zip, the PHP sources and the prebuilt dependency libraries) is
    downloaded by build.ps1.

    Installing or modifying Visual Studio requires administrator rights. The
    script requests elevation when needed.

.PARAMETER Edition
    Visual Studio edition winget installs when no Visual Studio 2022 is found.
    Defaults to BuildTools.
#>
[CmdletBinding()]
param(
    [ValidateSet('BuildTools', 'Community', 'Professional', 'Enterprise')]
    [string]$Edition = 'BuildTools',

    # Internal: set when the script restarts itself elevated.
    [switch]$Elevated
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RequiredComponents = @(
    'Microsoft.VisualStudio.Component.VC.Tools.x86.x64',
    'Microsoft.VisualStudio.Component.VC.v141.x86.x64',
    'Microsoft.VisualStudio.Component.Windows11SDK.22621'
)

function Test-Administrator {
    $principal = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-VsWherePath {
    $path = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio\Installer\vswhere.exe'
    if (Test-Path $path) { return $path }
    return $null
}

function Get-VsInstances {
    $vswhere = Get-VsWherePath
    if (-not $vswhere) { return @() }
    $json = & $vswhere -products * -version '[17.0,18.0)' -include packages -format json
    if (-not $json) { return @() }
    return @($json | Out-String | ConvertFrom-Json)
}

function Get-MissingComponents($instance) {
    $installed = @($instance.packages | ForEach-Object { $_.id })
    $missing = @()
    foreach ($component in $RequiredComponents) {
        if ($component -like '*Windows1*SDK*') {
            # Any Windows 10/11 SDK is fine, including a standalone one.
            $hasSdk = ($installed | Where-Object { $_ -match '^Microsoft\.VisualStudio\.Component\.Windows1[01]SDK\.' }) -or
                (Test-Path (Join-Path ${env:ProgramFiles(x86)} 'Windows Kits\10\Include\*\um\Windows.h'))
            if (-not $hasSdk) {
                $missing += $component
            }
        } elseif ($installed -notcontains $component) {
            $missing += $component
        }
    }
    return $missing
}

if (-not (Get-Command winget -ErrorAction SilentlyContinue)) {
    throw 'winget was not found. Install "App Installer" from the Microsoft Store and run this script again.'
}

$instances = Get-VsInstances
$target = $instances | Sort-Object { @(Get-MissingComponents $_).Count } | Select-Object -First 1

if ($target) {
    $missing = @(Get-MissingComponents $target)
    if ($missing.Count -eq 0) {
        Write-Host "Visual Studio '$($target.displayName)' at $($target.installationPath) already has all required components."
        Write-Host 'Nothing to install. You can run build.ps1 now.'
        exit 0
    }
}

if (-not (Test-Administrator)) {
    Write-Host 'Administrator rights are required. Restarting elevated...'
    $shell = (Get-Process -Id $PID).Path
    $arguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"", '-Edition', $Edition, '-Elevated')
    $process = Start-Process -FilePath $shell -ArgumentList $arguments -Verb RunAs -Wait -PassThru
    $log = Join-Path $env:TEMP 'php-build-install-deps.log'
    if (Test-Path $log) { Get-Content $log | Write-Host }
    exit $process.ExitCode
}

if ($Elevated) {
    # The elevated run has its own window; keep a log the caller can show.
    Start-Transcript -Path (Join-Path $env:TEMP 'php-build-install-deps.log') -Force | Out-Null
    # Keep the elevated window open so its output can be read.
    trap {
        Write-Host "ERROR: $_" -ForegroundColor Red
        Read-Host 'Press Enter to close'
        exit 1
    }
}

if ($target) {
    Write-Host "Adding components to '$($target.displayName)' at $($target.installationPath):"
    $missing | ForEach-Object { Write-Host "  $_" }

    # winget installs and updates Visual Studio through this installer; it does
    # not expose "modify", so call the installer directly for an existing instance.
    $installer = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio\Installer\setup.exe'
    $arguments = @('modify', '--installPath', "`"$($target.installationPath)`"", '--passive', '--norestart', '--nocache')
    foreach ($component in $missing) { $arguments += @('--add', $component) }
    $process = Start-Process -FilePath $installer -ArgumentList $arguments -Wait -PassThru
    # 3010 = success, reboot required
    if ($process.ExitCode -notin 0, 3010) {
        throw "Visual Studio Installer failed with exit code $($process.ExitCode)."
    }
} else {
    $packageId = "Microsoft.VisualStudio.2022.$Edition"
    $workload = if ($Edition -eq 'BuildTools') {
        'Microsoft.VisualStudio.Workload.VCTools'
    } else {
        'Microsoft.VisualStudio.Workload.NativeDesktop'
    }
    $override = "--wait --passive --norestart --add $workload " +
        (($RequiredComponents | ForEach-Object { "--add $_" }) -join ' ')

    Write-Host "Installing $packageId with winget..."
    & winget install --id $packageId --exact --source winget `
        --accept-package-agreements --accept-source-agreements `
        --override $override
    if ($LASTEXITCODE -ne 0) {
        throw "winget install $packageId failed with exit code $LASTEXITCODE."
    }
}

# Verify the result.
$instances = Get-VsInstances
$ok = $instances | Where-Object { @(Get-MissingComponents $_).Count -eq 0 } | Select-Object -First 1
if (-not $ok) {
    throw 'Visual Studio 2022 with the required components was not found after installation.'
}
Write-Host "Ready: '$($ok.displayName)' at $($ok.installationPath)."
Write-Host 'You can run build.ps1 now.'
if ($Elevated) { Read-Host 'Press Enter to close' | Out-Null }
