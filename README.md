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
- **Session upload progress stays enabled and functional**, but progress
  updates serialize with `incomplete_classes_only`, reject the reserved
  `__PHP_Incomplete_Class` bookkeeping property names, and never run user
  code; a `$_SESSION` materialized mid-parse is removed again at
  `MULTIPART_EVENT_END`.
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

## PHP 7.4.33

`patches/php-7.4/CVE-2026-109618-109619-minimal-mitigation.patch` is the
official EOL commit from the php-src `PHP-7.4-CVE-2026-109618-109619` branch
(`712e60f9edfd…`). It applies to the vanilla 7.4.33 tarball as-is and already
includes `max_multipart_body_parts`. (A full-backport variant for 7.4 is not
planned — the official patch is sufficient there.)

## More CVEs

Additional backports for internet-facing EOL PHP live in the same numbered
series (`01xx` = pre-auth RCE / memory corruption, `02xx` = logic bypasses /
info leaks / DoS). See `docs/BACKLOG.md` for the full list, per-CVE upstream
commit references and current status.

## Verifying a patched build

Adapted upstream regression tests live in `tests/php-7.3/` (source layout
preserved). Copy them into the patched source tree and run:

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

- 7.3 lacks the fallible-allocator API audit that upstream did across the
  engine; under extreme OOM pressure a patched 7.3 can still die with
  `SIGSEGV` instead of a clean fatal error (availability impact only,
  no corruption).
- Only the exact tarballs named above are verified. Other 7.3.x/7.4.x
  releases may need fuzz.

## Attribution & license

- The 7.3 `0001` patch ports upstream php-src commit `01a40e89fbfc` (PHP
  Security Team) and incorporates hardening from the official EOL minimal
  mitigation (Arnaud Le Blanc, co-author Ilija Tovilo) as shipped in Ondřej
  Surý's php7.3 packaging.
- The 7.4 patch is the official EOL commit `712e60f9edfd…` (PHP Security Team).
- Series `01xx`/`02xx` patches port the upstream commits referenced in each
  patch header; see `docs/BACKLOG.md`.
- All patches are derived works of php-src and are distributed under the
  [PHP License 3.01](https://www.php.net/license/3_01.txt).
