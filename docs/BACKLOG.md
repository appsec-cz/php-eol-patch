# Backport backlog for PHP 7.3.33 / 7.4.33

Research date: 2026-10-21. Presence of vulnerable code verified against the
vanilla `php-7.3.33` tree. Numbering scheme: Tier 1 → `0101+`,
Tier 2 → `0201+`, Tier 3 → `0301+`. Status: ☐ open / ◐ in progress / ☑ done.

## Tier 1 — pre-auth RCE / memory corruption

| # | CVE | Component | Bug | Upstream fix | Status |
|---|---|---|---|---|---|
| 0101 | CVE-2026-91768 | `sapi/fpm` (main/fastcgi.c:1346) | IPv6 `listen.allowed_clients` compared with `memcmp(…, 12)` → ACL bypass | `dcdfcf86fcf7` | ☑ (0101) |
| 0102 | CVE-2026-6722 (GHSA-85c2-q967-79q5) | ext/soap | `soap_add_xml_ref` / `SOAP_GLOBAL(ref_map)` use-after-free → RCE, pre-auth on any SoapServer endpoint | `aee3b3ac9b81` | ☑ (0102) |
| 0103 | CVE-2023-0662 | main/rfc1867.c | unlimited multipart body parts → CPU/disk DoS, pre-auth | `95b33b624c` + `89a20c2ccb` | ☑ (shipped as `0002`) |
| 0104 | CVE-2022-31626 | ext/mysqlnd | heap overflow (off-by-4) on malicious MySQL server auth packet; public exploit exists | `58006537fc5f` | ☑ (0104) |
| 0105 | CVE-2023-3824 | ext/phar | `phar_dir_read()` stack buffer overflow by 2 bytes via `phar://` | `80316123f3e9` | ☑ (0105) |
| 0106 | CVE-2022-31625 | ext/pgsql | `pg_query_params()` UAF on conversion failure (uninitialized params array) | `f724f8e393` | ☑ (0106) |

## Tier 2 — logic bypasses, info leaks, DoS

| # | CVE | Component | Bug | Upstream fix | Status |
|---|---|---|---|---|---|
| 0201 | CVE-2024-2756 + CVE-2022-31629 | main/php_variables.c | variable-name mangling lets attacker forge `__Host-`/`__Secure-` cookies (31629 fix was incomplete) | `c332950a8683` + `093c08af25fb` (apply both) | ☑ (0201) |
| 0202 | CVE-2024-5458 | ext/filter | `FILTER_VALIDATE_URL` accepts IPv6+userinfo URLs; advisory explicitly lists 7.3.27–7.3.33 | `7e0e3cc820c4` | ☑ (0202) |
| 0203 | CVE-2024-3096 + CVE-2023-0567 | ext/standard (crypt) | `password_verify("", "\x00…")` returns true; invalid bcrypt accepted | GHSA-h746-cjrr-wfmr + `7882d12ff2` | ☑ (0203) |
| 0204 | CVE-2024-8929 | ext/mysqlnd | heap over-read in `php_mysqlnd_rset_field_read` leaks previous query data; FPM persistent heap amplifies | GHSA-h35g-vwh6-m678 (`6c0e2eb2f839`) | ☑ (0204) |
| 0205 | CVE-2025-14181 | ext/soap | `php_http.c:1471` int overflow (`http_buf_size + buf_size + 1 < 0`) → heap overflow | `b11bd1d9390b` | ☑ (0205) |
| 0206 | CVE-2026-91765 | ext/soap | `cleanup_xml_node` unbounded recursion → pre-auth SoapServer crash, single request | `3655b79c7bfa` | ☑ (0206) |
| 0207 | CVE-2024-8925 | main/rfc1867.c | `php_ap_memstr` boundary misparsing loses uploaded data | `d65a1e6f9171` (merge after 0001/0002) | ☑ (0207) |

## Tier 3 — trivial, cheap (not yet scheduled)

CVE-2026-7258 (urldecode OOB),
CVE-2024-11233 (qprint filter OOB), CVE-2026-92842 (filter NUL),
CVE-2022-31630 (imageloadfont OOB), CVE-2022-37454 (SHA3 overflow),
CVE-2023-0568 (virtual cwd off-by-one), CVE-2026-91767 (openssl wildcard CN),
CVE-2025-1220 (fsockopen/dns NUL byte), CVE-2022-31628 (phar gzip quine DoS,
requires regression fix `432bf196d5` as well).

CVE-2026-6735 (FPM status XSS) was closed on 2026-11-19 together with
CVE-2026-129292 by patch `0212` (see below).

## Explicitly out of scope for this server profile

Windows/CGI-only: CVE-2024-8927, CVE-2024-8926, CVE-2024-4577,
CVE-2024-1874, CVE-2024-5585. 32-bit only: CVE-2024-8932,
CVE-2024-11236. Not present in 7.3 (verified): CVE-2024-2757,
CVE-2024-11235, CVE-2025-14177, CVE-2025-14179, CVE-2026-17544,
CVE-2026-7263, CVE-2026-6104, CVE-2026-7259, CVE-2026-12184,
CVE-2026-93682. Already fixed in 7.3.33: CVE-2021-21703, CVE-2021-21707.
Needs a custom approach: CVE-2023-3823 (libxml XXE), CVE-2022-31631
(PDO::quote SQLite, ~2 GB input), CVE-2023-3247 (SOAP digest RNG,
client-side).

## PHP 7.4.33 status of the same list

Vulnerable code verified against the vanilla `php-7.4.33` tree
(2026-10-22). 7.4.33 postdates 7.3.33 by a year, so several fixes are
already upstream there.

| # | CVE | 7.4.33 status | Patch |
|---|---|---|---|
| 0001 | CVE-2026-109618 + CVE-2026-109619 | ☑ full backport (same design as 7.3) | `0001` |
| 0002 | CVE-2023-0662 | ☑ folded into 7.4 `0001` | — |
| 0101 | CVE-2026-91768 | ☑ ported (vanilla vulnerable) | `0101` |
| 0102 | CVE-2026-6722 | ☑ ported (vanilla vulnerable) | `0102` |
| 0104 | CVE-2022-31626 | not needed — fixed upstream in 7.4.30 | — |
| 0105 | CVE-2023-3824 | ☑ ported; 7.4 has `ssize_t` read op, so the upstream 8.0 fix applies verbatim (no 7.3 `return 0` workaround) | `0105` |
| 0106 | CVE-2022-31625 | not needed — fixed upstream in 7.4.30 | — |
| 0201 | CVE-2024-2756 | ☑ ported (only this half; CVE-2022-31629 is already in 7.4.33). Matches upstream final state: helper `php_is_forbidden_variable_name()` + insertion-point guards, early `strncmp` block removed | `0201` |
| 0202 | CVE-2024-5458 | ☑ ported | `0202` |
| 0203 | CVE-2024-3096 + CVE-2023-0567 | ☑ ported; NUL-byte check goes into `php_password_bcrypt_hash()` (7.4 already has the 8.0-style algo registry) | `0203` |
| 0204 | CVE-2024-8929 | ☑ ported near-textually (offsets + two `const` drift points); tests need mysqlnd compression disabled (handshake byte-matching) | `0204` |
| 0205 | CVE-2025-14181 | ☑ ported; `ZSTR_MAX_LEN` is native in 7.4 (compat shim dropped), `len_size`/`len_read` already wider | `0205` |
| 0206 | CVE-2026-91765 | ☑ ported on top of `0102` (shared `php_encoding.c` regions); vanilla SIGSEGVs on a deep href-chain, patched returns a clean SOAP fault | `0206` |
| 0207 | CVE-2024-8925 | ☑ ported; hunk lands inside the 0001-rewritten `rfc1867.c` (boundary setup region untouched by 0001) | `0207` |

Series order for 7.4: `0001` first, then the rest in numeric order —
verified as a zero-fuzz `patch -p1` chain on the vanilla tarball.

Whole-series notes: the full `patch -p1` chain (0001 + 01xx/02xx) applies
with zero fuzz on vanilla 7.4.33. Each ported patch was verified with:
patched build PASS / vanilla build FAIL (or ASAN/UBSAN demonstration) on
the CVE regression tests, plus a per-extension regression sweep with no
patched-only failures.

## 2026-11-19 release batch (PHP 8.2.36 / 8.3.37 / 8.4.28 / 8.5.13 / 8.6.0)

Research and backport date: 2026-11-19. Every patch below ships for **both**
7.3.33 and 7.4.33 under the same number. Each one was produced against the
tree with the whole earlier series applied, reviewed by an independent
second pass, and then verified as part of the complete series (see "Whole-
series verification" at the end of this section).

### Tier 1 — remote code execution / memory corruption

| # | CVE | Component | Bug | Upstream fix | Status |
|---|---|---|---|---|---|
| 0107 | CVE-2026-126714 (GHSA-fm2m-fjf5-v3cp) | ext/session (mod_files) | session file only truncated when the new data is shorter; an interrupted write leaves new prefix + old tail, which decodes as attacker-shaped session data | `41c0941ae27d` | ☑ verbatim |
| 0108 | CVE-2026-129496 (GHSA-gr4f-wgx5-jpfv) | main/main.c | error-log timestamp formatted with the request timezone outside a request; ext/date's per-request timezone cache is used after its heap is gone (needs `error_log=<file>`) | `f986c5ca37c0` | ☑ verbatim + test-only zend_test INI switch |
| 0109 | CVE-2026-129289 (GHSA-qc49-hpvq-g2w6) | main/SAPI.c, main/fastcgi.c | unread request body left on a kept FastCGI connection is parsed as the next request | `28014fd092c7` | ☑ verbatim; **no regression test for the request sequence shipped** (see notes) |
| 0110 | CVE-2026-115560 (GHSA-658j-r8rw-q6mx) | ext/session | upload progress writes the session with the php.ini serializer before the application switches serializer at runtime → object injection | `3f70f6661158` | ☑ upstream semantics + 7.x-only guard points + one documented deviation |
| 0111 | CVE-2026-129291 (GHSA-jm4m-qfqx-967m) | sapi/fpm (fpm_children.c) | worker stdio pipe closed without removing its event; master keeps an event pointing into the freed child | `8c38b7914497` | ☑ verbatim |
| 0112 | CVE-2026-129506 (GHSA-4646-p932-gw34) | ext/soap | DTD node freed while attribute entity references still point into it | `66db5cda7df3` + [PR #25605](https://github.com/php/php-src/pull/25605) | ☑ **shipped as `0215`** — it must apply after `0206`, which rewrote the same function |
| 0113 | CVE-2026-129481 (GHSA-g3vh-838c-65v7) | Zend/zend_API.c | process continues after a bailout interrupted request shutdown, with later modules' RSHUTDOWN skipped | `ad9b44ae6119` (8.3+ only) | ☑ hardening half only; the literal upstream defect (`session_started_filename`) does not exist in 7.x |
| 0114 | CVE-2026-129474 (GHSA-8mjj-2r7p-4hg2) | Zend (debug_backtrace) | backtrace argument collection assumes relocated extra args for `__call`/`__callStatic` trampoline frames | `03c403286d94` (8.5+ only) | ☑ **hardening backport** — the faulty read pattern exists in 7.x, but no failure was demonstrated on 7.x |

### Tier 2 — logic bypasses, info leaks, DoS

| # | CVE | Component | Bug | Upstream fix | Status |
|---|---|---|---|---|---|
| 0208 | CVE-2026-128090 (GHSA-68fq-fh7h-f8gr) | ext/phar | OpenSSL-signed phar can be downgraded to a keyless hash signature | `7050cf338092` | ☑ adapted (7.x only knows `PHAR_SIG_OPENSSL`) |
| 0209 | CVE-2026-15353 (GHSA-q23r-hr55-xmpg) | ext/soap | empty SOAP 1.2 `arraySize` yields zero dimensions → out-of-bounds read (decode) and read/write + unbounded walk (encode) | `59e46404a744` | ☑ verbatim |
| 0210 | CVE-2026-128064 (GHSA-f6gg-rchx-xw8j) | ext/standard (http/ftp wrappers) | `HTTPS://` / `FTPS://` in mixed case fetched in plaintext | `cb68a63e8177` | ☑ verbatim |
| 0211 | CVE-2026-127960 (GHSA-mmf9-xm3v-hg2x) | ext/standard (http wrapper) | quadratic folded-header concatenation | `a303f697268d` | **not affected** — no patch; the folding parser only exists since the CVE-2025-1217 rewrite (8.2.28+). Measured linear on 7.3/7.4, quadratic on an unfixed 8.5.12 control |
| 0212 | CVE-2026-129292 (GHSA-3mg3-cr28-46gc) + CVE-2026-6735 (GHSA-7qg2-v9fj-4mwv) | sapi/fpm (fpm_status.c) | request method, URI, query string, auth user and script filename unescaped in html/xml/json full status | `1ecb92f2cdeb` + `99a5ad7441de` (+ JSON escaping of `5e64ead64ab5`) | ☑ one patch for both advisories; self-contained JSON escaper (ext/json is optional in 7.x) |
| 0213 | CVE-2026-129538 (GHSA-5j3f-64r7-f564) | sapi/cgi | PATH_INFO pointer underflow in `init_request_info()` | `964cdcfa5adf` | ☑ verbatim; php-cgi only (classic CGI and `php-cgi -b`), FPM already had the guard |
| 0214 | CVE-2026-103111 (GHSA-r9hj-j2rw-4q3m) and further PCRE2 advisories | ext/pcre/pcre2lib (bundled PCRE2 10.32 / 10.35) | PCRE2 maintenance fixes as applied by php-src `1e8a51d545d9` to its bundled copy | PCRE2 `release/pcre2-10.39` backports | ☑ 7.3: 4 advisories apply; 7.4: 6 ported, GHSA-9qww deliberately not (see notes) |

### Not applicable to 7.3 / 7.4 (code does not exist)

GHSA-x8r7-mg3g-74hg / CVE-2026-129501 (`Dom\XMLDocument` serializer, 8.4+),
GHSA-2fjv-p558-g82m / CVE-2026-129182 (fatal-error backtraces, 8.5+),
GHSA-mg3p-mxj9-g2mm / CVE-2026-129438 and CVE-2026-48841 (ext/uri, 8.5+).

### Behaviour changes operators must know about

- **0110** — `session.upload_progress.enabled` is now **Off by default**.
  If it is explicitly On anywhere (php.ini, scan dir, pool
  `php_flag`/`php_admin_flag`, `PHP_VALUE`, `.user.ini`, `.htaccess`), every
  runtime change of a `session.*` setting (`ini_set()`, `session_name($v)`,
  `session_save_path($v)`, `session_set_save_handler()`,
  `session_start([...])`, …) throws an `Error`. The patch header has the
  full operator notes. Deviation from upstream: `session_start()` returns
  early when such an Error is pending, because the 7.x unserializer would
  otherwise destroy a stored session that contains objects.
- **0108** — with `error_log` pointing to a file, lines logged outside a
  fully active request (module startup/shutdown, FPM worker init, request
  startup including "Input variables exceeded" / "POST Content-Length
  exceeds" warnings, the tail of request shutdown) carry a **UTC**
  timestamp instead of `date.timezone`. Script, shutdown-function,
  destructor and session write-back errors are unchanged. Same as upstream.
- **0113** — a request whose shutdown is interrupted by a fatal error or
  `exit()` inside a module's RSHUTDOWN now ends the process with exit
  status 1 (php-fpm: worker respawned, the response of that request is
  lost or truncated; CLI: status 1 regardless of `exit(N)`). A one-line
  diagnostic is written to stderr first (not in upstream). Repeated
  `exited with code 1` for the same script points at an application
  destructor/handler that fails during shutdown.
- **0208** — an archive with a `<archive>.pubkey` next to it must be
  OpenSSL-signed, also when that file is empty or unreadable.
- **0212** — the five request fields in the html/xml/json full status are
  escaped; JSON consumers decode the same values, raw-text greps see
  `\/` and `\uXXXX`.
- **0107** — a session write that fails outright now leaves an empty
  session file (session lost) instead of the previous content.
- **0109** — a kept FastCGI connection whose last STDIN record was not
  fully consumed is closed instead of reused; an unread body is drained at
  request end also when `php://input` was opened.
- **0215** — `SoapServer::handle()` without an argument now rejects any
  request carrying a DOCTYPE with a "DTD are not supported by SOAP" fault;
  7.x used to strip the DTD silently. Same as upstream. The patch includes
  the upstream follow-up from php-src PR #25605 (not merged when this was
  written): `node_is_equal_ex()` no longer matches non-element nodes, so a
  DTD kept in the tree and named like the root element (`<!DOCTYPE
  Envelope>`, `definitions`, `schema`) is not taken for it. Without that
  part the first upstream commit crashes `SoapServer::handle()` and WSDL /
  schema loading on such documents.

### Notes

- **0109** has no regression test for the request sequence itself: the two
  upstream tests (`unread-body-keep-conn.phpt`,
  `unread-body-partial-record.phpt`) were not ported. The patch is the
  unmodified upstream change on textually identical code and was verified
  by regression sweeps and functional checks of ordinary requests only.
- **0114** and **0113** go beyond what upstream shipped for its oldest
  supported branch (8.2); both are small and self-contained and can be
  left out of the series without affecting any other patch.
- **0214** only matters for builds using the bundled PCRE2
  (`nm -D php-fpm | grep php_pcre2_match`; `ldd` and `PCRE_VERSION` are
  misleading on RHEL 8). Only GHSA-r9hj (JIT stack) is reachable through
  ext/pcre on x86_64, and it needs an attacker-influenced *pattern*.
  GHSA-9qww is not ported to 7.4: it only matters with
  `PCRE2_MATCH_INVALID_UTF`, which ext/pcre never sets, and its interpreter
  hunk makes `\X` results depend on PHP's UTF-8 validity cache (php-src
  8.2.36 ships that hunk). The regression test crashes an unpatched build
  only intermittently (memory layout).
- 7.3's `run-tests.php` forces `USE_ZEND_ALLOC=1`, so AddressSanitizer runs
  through run-tests on 7.3 do not see request-heap bugs; decisive cases
  were run by hand with `USE_ZEND_ALLOC=0`.
- Number `0211` is intentionally unused; `0112` is shipped as `0215`.

### Open follow-ups found during this batch

- CVE-2025-1217 (folded HTTP response headers) is not backported. If it
  ever is, the port must already contain the CVE-2026-127960 fix
  (smart_str accumulation).
- CVE-2022-1586 / CVE-2022-1587 are unfixed in both bundled PCRE2 copies
  (PCRE2's own maintenance list names them as prerequisites).
- 7.x `ext/phar/zip.c` lacks the `phar.require_hash` "signature is
  missing" check that 8.x has (separate, older upstream change).
- 7.3 only: ext/date releases its timezone cache in RSHUTDOWN (7.4 uses a
  post-deactivate hook). User code that runs later in the same request
  (stream wrapper `stream_close`, late destructors) can still touch freed
  date state. Needs user PHP code; fix = move the teardown to a
  post-deactivate hook on top of `0108`.
- `tests/basic/oom_rfc1867_mb_encoding.phpt` (series `0001`) fails on the
  arm64 verification builds with and without this batch; cause not yet
  established.

### Whole-series verification (2026-11-19)

Environment: Rocky Linux 8, gcc 8, OpenSSL 1.1.1, libxml2 2.9.7,
**linux/arm64**; `-O2 -g` ("rel") and `--enable-debug -fsanitize=address`
("asan") builds of both versions from the vanilla php.net tarballs.

- The complete series (7.3: 28 patches, 7.4: 25 patches) applies in numeric
  order with GNU patch: no fuzz and no rejects anywhere, no line offsets in
  any patch of this batch.
- rel and asan builds succeed with the same compiler-warning counts as the
  series before this batch.
- All regression tests of this batch pass on the final rel builds
  (7.3: 36 `.phpt`, 7.4: 35 `.phpt`).
- Sweep of `Zend/tests`, `ext/{date,pcre,phar,session,soap}/tests`,
  `ext/standard/tests/{http,streams,general_functions}`,
  `sapi/{cgi,cli,fpm}/tests`, `tests/basic`, `tests/output` on rel, final
  series vs. the series before this batch (7.3: 5428 existing tests,
  7.4: 5580): no existing test changes status.
- Same comparison on the asan builds for the touched extension and SAPI
  directories plus `tests/basic` (7.3: 2495 existing tests, 7.4: 2393): no
  existing test changes status, all tests of this batch pass (one is
  skipped on 7.4 asan because it needs the Zend allocator), no
  AddressSanitizer report. See the note above about 7.3's run-tests and
  `USE_ZEND_ALLOC`.
- The two sweeps above were run with an earlier version of `0215`. The
  final `0215` touches only `ext/soap/php_xml.c`; with it `ext/soap/tests`
  was re-run on rel and asan for both versions (7.3: 541 tests, 7.4: 548):
  only the network-dependent `bug71610.phpt` fails, as before this batch.
  Of the six `0215` tests, four fail on the series without `0215` (the
  WSDL and schema tests pass there, because the DTD was still stripped),
  and the four tests from PR #25605 fail with only the first upstream
  commit applied.
