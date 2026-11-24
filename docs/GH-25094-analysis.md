# GH-25094 — SOAP document-style function lookup with missing parts

## Failure mechanism

`SoapServer::handle()` resolves a document-style operation in `get_doc_function()`
by walking the WSDL request parts and the request Body elements in parallel. The
walk advanced the Body node with `node->next` without checking whether it had run
out of elements. A request whose Body contains fewer elements than the operation
declares parts dereferences a NULL node and crashes the worker.

The request needs no authentication. Any document-style SoapServer whose WSDL
declares an operation with two or more request parts is reachable with a single
request. Upstream fixed it without an advisory.

## Backport

The port adds the upstream NULL check at the start of the parameter walk. A
request with missing parts no longer matches that operation. The server then
answers with the existing "Procedure not present" SOAP fault.

- **PHP 7.3.33** [0225](../patches/php-7.3/0225-GH-25094-soap-doc-function-missing-parts.patch)
- **PHP 7.4.33** [0225](../patches/php-7.4/0225-GH-25094-soap-doc-function-missing-parts.patch)

Key source files

- `ext/soap/soap.c`

## Verification

Before the patch, both 7.3.33 and 7.4.33 Windows builds, with the rest of the
series applied, terminated with an access violation (0xC0000005) on a Body that
carried only the first of two declared parts. The upstream test
`ext/soap/tests/gh25094.phpt` (with its WSDL) is included in both test trees,
with a `--SKIPIF--` section in place of `--EXTENSIONS--`.

[Upstream issue](https://github.com/php/php-src/issues/25094) ·
[Upstream fix](https://github.com/php/php-src/commit/3589066c7c0e0534d87925df3941045578fcaab1)
