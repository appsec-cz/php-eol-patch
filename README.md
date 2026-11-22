# PHP EOL security backports

This project provides complete, version-specific source patch series for
**PHP 7.3.33** and **PHP 7.4.33**. The series backport selected upstream
security fixes and hardening for deployments that still depend on these
end-of-life releases.

Coverage spans the PHP runtime and request lifecycle, FPM and CGI, sessions,
HTTP and TLS, SOAP and XML, PHAR, database drivers, and bundled PCRE2.
See the [coverage review and patch inventory](docs/BACKLOG.md#coverage-review-2026-11-22)
for included advisories, upstream references, and open gaps.

## Choose a patch series

| Official source release | Patch series | PHPT tests and fixtures |
| --- | --- | --- |
| PHP 7.3.33 | [37 patches](patches/php-7.3/) | [PHP 7.3 tests](tests/php-7.3/) |
| PHP 7.4.33 | [34 patches](patches/php-7.4/) | [PHP 7.4 tests](tests/php-7.4/) |

Each directory contains the full series for its source release. Apply every
patch in ascending filename order. Numbering gaps reflect version differences,
fixes already present in upstream sources, or earlier changes incorporated into
another patch.

## Apply and build

Download the exact [official PHP source archive](https://www.php.net/releases/)
and verify its published SHA-256 checksum. Run the following from the repository
checkout with GNU patch. For PHP 7.4, set `php_series=7.4` and use the matching
archive path.

```sh
repo_dir=$(pwd -P)
php_series=7.3
php_version="$php_series.33"
source_archive="/path/to/php-$php_version.tar.gz"
build_dir=$(mktemp -d)
src_dir="$build_dir/php-$php_version"
export LC_ALL=C

(
    set -eu
    tar -xf "$source_archive" -C "$build_dir"
    cd "$src_dir"
    for patch_file in "$repo_dir/patches/php-$php_series/"*.patch; do
        printf '%s\n' "Applying ${patch_file##*/}"
        patch --dry-run -f -F 0 -p1 < "$patch_file"
        patch -f -F 0 -p1 < "$patch_file"
    done
)
```

Review any line offsets against the [verification record](docs/BACKLOG.md#coverage-review-2026-11-22).
Once the full series applies, build the patched sources with the configure flags
and dependencies for your deployment. The example enables OpenSSL and PCNTL
for the compatibility checks below.

```sh
cd "$src_dir"
./configure --with-openssl --enable-pcntl && make -j4
```

## Verify before deployment

Copy the matching PHPT tests and fixtures into the patched tree. Use the PHP
binary you built to run tests for the extensions and SAPIs you deploy. This
example runs the four compatibility checks used in the coverage review.
The checks use OpenSSL, PHAR, PCNTL, POSIX, `proc_open()`, and local
loopback connections.

```sh
cd "$src_dir"
cp -R "$repo_dir/tests/php-$php_series/." .
TEST_PHP_EXECUTABLE="$PWD/sapi/cli/php" \
    sapi/cli/php run-tests.php -q \
    ext/openssl/tests/san_absent_cn_fallback.phpt \
    ext/phar/tests/tar/regular-entry-boundaries.phpt \
    ext/standard/tests/http/bug61548.phpt \
    ext/standard/tests/strings/metaphone.phpt
```

On 2026-11-22, both complete series applied to clean official sources and
built on Rocky Linux 8 arm64. All four compatibility tests passed on each
build. The [verification record](docs/BACKLOG.md#coverage-review-2026-11-22)
documents the tested configuration, earlier regression runs, and gaps,
including database-backed regressions for the follow-up batch.

Before rollout, review the [runtime and configuration changes](docs/BACKLOG.md#behaviour-changes-operators-must-know-about).
These include session upload progress defaulting to **Off** and changes to
request shutdown, FPM status output, and PHAR signature handling.

## Documentation and license

- [Technical analyses by CVE](docs/BACKLOG.md#technical-analyses) describe the affected code,
  the branch-specific backports, and the verification for each advisory.
- [Backlog and verification](docs/BACKLOG.md) lists per-version coverage,
  upstream fixes, known limitations, and pending work.
- [Request bailout and memory-limit analysis](docs/CVE-2026-109618-109619-analysis.md)
  explains the original runtime backport and its trade-offs.

Patch headers record upstream authorship and references where applicable.
The patches are derived from php-src and distributed under the
[PHP License 3.01](https://www.php.net/license/3_01.txt).
