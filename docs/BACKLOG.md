# Backport backlog for PHP 7.3.33

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

CVE-2026-6735 (FPM status XSS), CVE-2026-7258 (urldecode OOB),
CVE-2024-11233 (qprint filter OOB), CVE-2026-92842 (filter NUL),
CVE-2022-31630 (imageloadfont OOB), CVE-2022-37454 (SHA3 overflow),
CVE-2023-0568 (virtual cwd off-by-one), CVE-2026-91767 (openssl wildcard CN),
CVE-2025-1220 (fsockopen/dns NUL byte), CVE-2022-31628 (phar gzip quine DoS,
requires regression fix `432bf196d5` as well).

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
