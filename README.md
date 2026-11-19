# php-eol-patch

Security patch backports for **end-of-life PHP versions** (7.3, 7.4) that are
still exposed to the internet — for example bundled inside products that pin a
specific PHP version (such as IceWarp Server, which ships PHP 7.3 FPM).

These patches are for defenders who **cannot upgrade PHP** but must close
remotely exploitable holes. Every patch is verified to apply against the
official vanilla tarballs from php.net and to compile.

> **Disclaimer:** This is an unofficial community effort. It is not endorsed
> by the PHP Group. The only correct fix is upgrading to a supported PHP
> version; these patches exist for situations where that is not (yet) possible.

## Repository layout

```
patches/
  php-7.3/          patch series for PHP 7.3.33 (apply in numeric order)
  php-7.4/          patch series for PHP 7.4.33
tests/              regression tests (.phpt) adapted for EOL versions
docs/               per-CVE analysis, patch-comparison notes, backlog
```

## CVE-2026-109618 & CVE-2026-109619 ("state corruption via bailout")

Disclosed 2026-10-21. Unauthenticated remote code execution affecting all PHP
versions prior to 8.2.35 / 8.3.27 / 8.4.27 / 8.5.x:

- **CVE-2026-109618** (GHSA-w8hw-w9fx-j79w): when a memory-limit bailout fires
  in the middle of `multipart/form-data` request parsing (`rfc1867`), partially
  initialized structures survive and the engine continues with corrupted state.
- **CVE-2026-109619** (GHSA-6wmf-4rv5-p3rp): stale global state from an aborted
  request (session status, SAPI auth pointers, `PG(modules_activated)`, …)
  leaks into subsequent requests handled by the same PHP-FPM worker, enabling
  session fixation / attacker-controlled `$_SESSION` deserialization.

Both are reachable **pre-auth** through any PHP endpoint that accepts POST,
which includes every IceWarp web-facing PHP script.

## PHP 7.3.33 — the patch

`patches/php-7.3/0001-CVE-2026-109618-109619-avoid-state-corruption.patch`

This is a **full backport of the upstream 8.2.35 fix** (commit `01a40e89fbfc`,
"Avoid state corruption via bailout") to PHP 7.3.33, extended with the
additional hardening that the official EOL minimal mitigation carries
(CONCAT/FAST_CONCAT integer-overflow checks in the VM, `HT_HASH_RESET`
small-table fix, `zend_llist_destroy()` NULLing, executor/SAPI stale-state
resets, `ZSTR_MAX_LEN` compat). One self-contained patch, applied on top of
the vanilla tarball:

```sh
tar -xzf php-7.3.33.tar.gz
cd php-7.3.33
patch -p1 < /path/to/patches/php-7.3/0001-CVE-2026-109618-109619-avoid-state-corruption.patch
patch -p1 < /path/to/patches/php-7.3/0002-CVE-2023-0662-max-multipart-body-parts.patch
# then configure && make as usual
```

### How the full backport behaves

- **Soft memory-limit breach** (e.g. `memory_limit` hit during request
  parsing): the OOM is recorded as *delayed OOM*, parsing unwinds in an
  orderly way, and a normal `Fatal error: Allowed memory size of N bytes
  exhausted … in Unknown on line 0` is raised at a safe point. **The FPM
  worker survives** and can serve the next request.
- **Hard limit breach**: the hard limit is computed as `2 × memory_limit`
  for limits below 1 GiB, `memory_limit + 1 GiB` for larger limits (capped
  at `SIZE_MAX >> 1`, never below current usage). Crossing it prints
  `Hard memory limit of N bytes exhausted …` to stderr and force-exits the
  worker with `_exit(137)` — corruption is impossible, but the request (and
  worker process) is deliberately sacrificed. php-fpm respawns the worker.
- **Session upload progress stays functional when it is enabled**: progress
  updates serialize with `incomplete_classes_only`, reject the reserved
  `__PHP_Incomplete_Class` bookkeeping property names, and never run user
  code; a `$_SESSION` materialized mid-parse is removed again at
  `MULTIPART_EVENT_END`. Since patch `0110` (CVE-2026-115560, 2026-11-19)
  the feature is **off by default**, as in upstream PHP 8.2.36+; see
  "Behaviour changes" below before enabling it.
- **Multipart parser** keeps only `Content-Disposition` / `Content-Type`
  headers, drops duplicates, and checks the delayed-OOM flag at every
  state-mutating step.
- **Cross-request hygiene**: freed SAPI pointers (`auth_user`, …) are NULLed,
  `PG(http_globals)[i]` are UNDEFed at shutdown, `PG(modules_activated)` is
  cleared, and executor error/exception handler state and object-store
  pointers are reset — nothing stale can leak into the next request.

`0002-CVE-2023-0662-max-multipart-body-parts.patch` adds the
`max_multipart_body_parts` INI setting (default `-1` = `max_input_vars` +
`max_file_uploads`) and closes the related multipart body-part DoS
(CVE-2023-0662). It applies on top of `0001` (or on vanilla — it is
independent).

### Why not the official EOL minimal mitigation?

The PHP Security Team also ships a *minimal mitigation* for EOL branches
(php-src `PHP-7.4-CVE-2026-109618-109619`, Ondřej Surý's php7.3 package,
Ubuntu ESM USN-8948-2). It takes a different route: every allocation-overflow
path prints a fatal and immediately `_exit(1)`s (worker dies on any overflow),
and the rfc1867 session upload-progress feature is **hard-disabled** in the
C code regardless of `php.ini`. We ship the full backport instead because it
preserves the feature and lets workers survive handled soft-OOMs — while now
*including* every extra hardening item the minimal patch had (verified by
side-by-side source comparison; see
`docs/CVE-2026-109618-109619-analysis.md`). Note the official 7.3 minimal
patch also does not compile on the vanilla tarball without a `ZSTR_MAX_LEN`
shim and omits `max_multipart_body_parts` — both are handled here.

## PHP 7.4.33 — the patch

`patches/php-7.4/0001-CVE-2026-109618-109619-avoid-state-corruption.patch`

The **same full backport** as the 7.3 `0001`, adapted to the vanilla PHP
7.4.33 tarball: upstream 8.2.35 semantics (delayed OOM, hard limit,
feature-preserving session hardening, cross-request hygiene) plus every
hardening item from the official EOL minimal mitigation — a superset of both
approaches. The `max_multipart_body_parts` limit (CVE-2023-0662) is **folded
into this patch**, so the 7.4 series needs no separate `0002`:

```sh
tar -xzf php-7.4.33.tar.gz
cd php-7.4.33
patch -p1 < /path/to/patches/php-7.4/0001-CVE-2026-109618-109619-avoid-state-corruption.patch
# then configure && make as usual
```

7.4-specific notes: `ZSTR_MAX_LEN` already exists (no compat shim needed),
the `_SESSION` key is created without `ZSTR_INIT_LITERAL` (that macro is
8.0+), and the hardened unserializer path also covers the 7.4
`__unserialize()` / `__serialize()` mechanism. The behavior table in the 7.3
section above applies unchanged (soft OOM → clean fatal, worker survives;
hard limit → `_exit(137)`). The official EOL minimal mitigation commit it
supersedes is kept for reference in
`archive/CVE-2026-109618-109619-php-7.4-minimal-mitigation.patch`.

## More CVEs

Additional backports for internet-facing EOL PHP live in the same numbered
series (`01xx` = pre-auth RCE / memory corruption, `02xx` = logic bypasses /
info leaks / DoS) under **both** `patches/php-7.3/` and `patches/php-7.4/`,
with identical numbers for identical CVEs. See `docs/BACKLOG.md` for the full
list, per-CVE upstream commit references and current status.

### 2026-11-19 batch (PHP 8.2.36 / 8.3.37 / 8.4.28 / 8.5.13 / 8.6.0)

The security fixes released by the PHP project on 2026-11-19 are covered by
patches `0107`-`0114` and `0208`-`0215` for both versions:

| Patch | CVE | What |
|---|---|---|
| `0107` | CVE-2026-126714 | session files: always truncate before writing |
| `0108` | CVE-2026-129496 | error-log timestamp must not use ext/date request state outside a request |
| `0109` | CVE-2026-129289 | FPM/FastCGI: drain unread request bodies, refuse half-read STDIN records |
| `0110` | CVE-2026-115560 | `session.upload_progress.enabled` off by default, session INI frozen while it is on |
| `0111` | CVE-2026-129291 | FPM master: remove the stdio pipe event before closing the fd |
| `0113` | CVE-2026-129481 | do not continue after an interrupted request shutdown (hardening) |
| `0114` | CVE-2026-129474 | backtrace args of `__call`/`__callStatic` frames (hardening, upstream 8.5+ only) |
| `0208` | CVE-2026-128090 | phar: refuse keyless signature when a `.pubkey` exists |
| `0209` | CVE-2026-15353 | SOAP: empty `arraySize` out-of-bounds access |
| `0210` | CVE-2026-128064 | mixed-case `HTTPS://` / `FTPS://` no longer fetched in plaintext |
| `0212` | CVE-2026-129292 + CVE-2026-6735 | FPM status page: escape request fields in html/xml/json |
| `0213` | CVE-2026-129538 | php-cgi: PATH_INFO pointer underflow |
| `0214` | CVE-2026-103111 (+ further PCRE2 advisories) | bundled PCRE2 maintenance fixes |
| `0215` | CVE-2026-129506 | SOAP: keep the DTD node alive, never match it as an element (numbered `0215` because it must apply after `0206`) |

CVE-2026-127960 (folded HTTP headers DoS) does not affect 7.3/7.4 and has no
patch; number `0211` is unused. Four further advisories of that release
concern code that only exists in PHP 8.4/8.5.

**Behaviour changes** introduced by this batch (details in each patch
header and in `docs/BACKLOG.md`):

- `session.upload_progress.enabled` defaults to Off. If you enable it
  explicitly, every runtime change of a `session.*` setting throws an
  `Error` - check php.ini, pool files, `PHP_VALUE`, `.user.ini` and
  `.htaccess` for an explicit `On` before deploying (`0110`).
- With `error_log` set to a file, lines logged outside an active request
  (startup, request startup warnings such as "Input variables exceeded",
  late shutdown) are stamped in UTC (`0108`).
- A request whose shutdown is interrupted inside a module's RSHUTDOWN ends
  the process with status 1; php-fpm respawns the worker and that request
  gets no (or a truncated) response (`0113`).
- A phar with a `<archive>.pubkey` next to it must be OpenSSL-signed
  (`0208`).
- The FPM full status page escapes request method, URI, query string, user
  and script in html, xml and json (`0212`).
- `SoapServer::handle()` rejects requests carrying a DOCTYPE (`0215`).

Patches `0113` and `0114` go beyond what upstream shipped for PHP 8.2; they
are self-contained and can be left out without affecting the rest of the
series. Patch `0214` only matters for builds that use the bundled PCRE2.

7.4 specifics of the extended series:

- `0104` (CVE-2022-31626, mysqlnd) and `0106` (CVE-2022-31625, pgsql) are
  **not shipped for 7.4** — 7.4.33 already contains those upstream fixes
  (they landed in 7.4.30, before 7.4 EOL).
- `0201` for 7.4 covers **only CVE-2024-2756** — 7.4.33 already ships the
  CVE-2022-31629 part. The patch matches upstream's final state (the early
  `strncmp` block is removed when the `php_is_forbidden_variable_name()`
  helper is introduced).
- CVE-2023-0662 (`max_multipart_body_parts`, the 7.3 series' `0002`) is
  folded into the 7.4 `0001`, so the 7.4 series has no `0002`.

## Verifying a patched build

Adapted upstream regression tests live in `tests/php-7.3/` and
`tests/php-7.4/` (source layout preserved). Copy them into the patched
source tree and run (7.3 shown; substitute `php-7.4` paths for 7.4):

```sh
cp -r tests/php-7.3/tests/basic/oom_rfc1867* php-7.3.33/tests/basic/
cp tests/php-7.3/ext/session/tests/rfc1867_*.phpt php-7.3.33/ext/session/tests/
cd php-7.3.33
sapi/cli/php run-tests.php -q tests/basic/oom_rfc1867_*.phpt ext/session/tests/rfc1867_*.phpt
```

Expected on a patched build: clean `Fatal error: Allowed memory size …`
(soft limit) — **no** worker crash, no state leaking into the next request.
The same tests against an unpatched build demonstrate corruption/crash.

Notes on dynamic "UAF probe" scripts: simple probes that serialize/unserialize
crafted objects **can report a patched build as vulnerable** — the upstream fix
deliberately does not change `var_destroy` ordering, so probe results are not a
reliable patch indicator. We confirmed this false positive against an
officially patched PHP 8.4.27 server. Trust the behavioral OOM tests above.

## Known limitations

- 7.3 and 7.4 lack the fallible-allocator API audit that upstream did across
  the engine; under extreme OOM pressure a patched build can still die with
  `SIGSEGV` instead of a clean fatal error (availability impact only,
  no corruption).
- The `0001` hard limit changes the observable behavior of two upstream
  tests (`ext/standard/tests/streams/bug61115-1.phpt`,
  `ext/standard/tests/strings/wordwrap_memory_limit.phpt`): instead of a
  catchable `Allowed memory size …` fatal the worker is hard-killed once the
  hard limit trips. This is the deliberate fail-fast design, not a
  regression in request handling.
- Only the exact tarballs named above are verified. Other 7.3.x/7.4.x
  releases may need fuzz.
- The 2026-11-19 batch was built and tested on **linux/arm64** (Rocky
  Linux 8, gcc 8, OpenSSL 1.1.1). Architecture-specific code - notably the
  PCRE2 JIT touched by `0214` - was not executed on x86_64 as part of that
  verification.
- `0109` (CVE-2026-129289) ships without a regression test for the request
  sequence itself; it is the unmodified upstream change and was verified by
  regression sweeps and functional checks of ordinary requests.

## Attribution & license

- The 7.3 `0001` patch ports upstream php-src commit `01a40e89fbfc` (PHP
  Security Team) and incorporates hardening from the official EOL minimal
  mitigation (Arnaud Le Blanc, co-author Ilija Tovilo) as shipped in Ondřej
  Surý's php7.3 packaging.
- The 7.4 `0001` patch ports the same upstream commit `01a40e89fbfc` to
  7.4.33 and incorporates hardening from the official EOL commit
  `712e60f9edfd…` (PHP Security Team; the original minimal-mitigation patch
  is kept in `archive/`).
- Series `01xx`/`02xx` patches port the upstream commits referenced in each
  patch header; see `docs/BACKLOG.md`.
- All patches are derived works of php-src and are distributed under the
  [PHP License 3.01](https://www.php.net/license/3_01.txt).
