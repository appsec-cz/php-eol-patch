# GH-25089 — PHAR zip alias length

## Failure mechanism

When a zip-based phar contains `.phar/alias.txt`, `phar_parse_zipfile()` set the
alias length from the uncompressed size declared in the zip headers. It did this
before reading the data. The data actually read can be shorter. `phar_validate_alias()`
and the stored alias then use the declared length on the shorter buffer and read
past it. If those bytes contain no forbidden characters, `Phar::getAlias()` returns
heap memory to the caller.

This affects any code that opens an untrusted zip archive with `Phar` or `PharData`,
such as code that inspects uploaded archives.

## Backport

The port sets the alias length after the alias has been read and decompressed. It
uses the length actually obtained, as upstream does. The surrounding code is
unchanged in both branches.

- **PHP 7.3.33** [0227](../patches/php-7.3/0227-GH-25089-phar-zip-alias-length.patch)
- **PHP 7.4.33** [0227](../patches/php-7.4/0227-GH-25089-phar-zip-alias-length.patch)

Key source files

- `ext/phar/zip.c`

## Verification

Before the patch, both 7.3.33 and 7.4.33 rejected the upstream sample with
`invalid alias "ABCD"`. Validation had read 4096 bytes from the 4-byte alias and
hit a forbidden character in unrelated heap memory. The upstream test
`ext/phar/tests/zip/gh25089.phpt` is included in both test trees, with a
`--SKIPIF--` section in place of `--EXTENSIONS--`.

[Upstream issue](https://github.com/php/php-src/issues/25089) ·
[Upstream fix](https://github.com/php/php-src/commit/61edcf9be7d16a325ff94d714f1208bcb00e90a4)
