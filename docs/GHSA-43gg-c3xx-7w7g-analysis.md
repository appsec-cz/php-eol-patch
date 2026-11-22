# GHSA-43gg-c3xx-7w7g — PCRE2 legacy arm64 SIMD subject reads

## Failure mechanism

The old NEON fast-forward path has different subject bounds from the scalar matcher and can
read beyond the available subject.

## Backport

PHP 7.4's bundled library disables that legacy SIMD path and uses the generic path. The code
is absent in PHP 7.3's bundled 10.32. This upstream advisory does not have a CVE identifier
in the patch header.

- **PHP 7.3.33** bundles a PCRE2 version without the affected code.
- **PHP 7.4.33** [0214](../patches/php-7.4/0214-CVE-2026-103111-pcre2-bundled-security-fixes.patch)

Key source files

- `ext/pcre/pcre2lib/pcre2_jit_compile.c`

## Verification

This is a bundled-library backport. The documented PHP builds do not independently verify the affected standalone PCRE2 interface or configuration. An external PCRE2 build depends on the system library.

[Upstream reference](https://github.com/PCRE2Project/pcre2/security/advisories/GHSA-43gg-c3xx-7w7g)
