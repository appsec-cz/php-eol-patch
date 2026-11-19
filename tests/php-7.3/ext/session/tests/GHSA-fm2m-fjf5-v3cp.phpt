--TEST--
GHSA-fm2m-fjf5-v3cp (CVE-2026-126714): interrupted session file write must not leave the tail of the old session behind
--SKIPIF--
<?php
include('skipif.inc');
if (substr(PHP_OS, 0, 3) == 'WIN') die('skip not for Windows');
if (!function_exists('posix_setrlimit') || !function_exists('posix_getrlimit')) die('skip posix_setrlimit() not available');
$l = posix_getrlimit();
if ($l['hard filesize'] !== 'unlimited' && $l['hard filesize'] < 65536) die('skip RLIMIT_FSIZE hard limit too low');
?>
--INI--
session.save_handler=files
session.serialize_handler=php
session.use_strict_mode=0
session.use_cookies=0
session.use_only_cookies=0
session.use_trans_sid=0
session.cache_limiter=
session.gc_probability=0
session.lazy_write=1
--FILE--
<?php
/*
 * ps_files_write() overwrites the session file in place with pwrite() at
 * offset 0. Before the fix it truncated the file only when the new data was
 * shorter than the old file. A write that is cut short (here: by
 * RLIMIT_FSIZE, in production e.g. by a crash, kill, ENOSPC or quota) then
 * leaves "<prefix of new data><tail of old data>" on disk. The old data is
 * shaped like attacker-controlled session content, so the spliced file
 * decodes into an extra session variable holding an object.
 */
$dir = __DIR__ . '/GHSA-fm2m-fjf5-v3cp.d';
@mkdir($dir, 0700);
session_save_path($dir);
$id = 'ghsafm2mfjf5v3cp' . bin2hex(random_bytes(4));
$file = "$dir/sess_$id";

$cut = 1024;                         /* RLIMIT_FSIZE during the second write */
$inject = '";x|O:8:"stdClass":1:{s:3:"pwn";b:1;}';

/* Request 1: old session data. Header 'a|s:2000:"' is 10 bytes; the
 * payload starts at file offset 1500. */
session_id($id);
session_start();
$_SESSION['a'] = str_pad(str_repeat('A', 1490) . $inject, 2000, 'A');
session_write_close();
clearstatcache();
$old_size = filesize($file);
var_dump($old_size);

/* Request 2: new, longer session data. 'a' is again 10 bytes of header plus
 * 1490 bytes of content, so its declared end (offset 1500) lies past the cut
 * point and lines up with the payload in the old file. */
session_id($id);
session_start();
$_SESSION['a'] = str_repeat('B', 1490);
$_SESSION['b'] = str_repeat('C', 3000);

if (function_exists('pcntl_signal') && defined('SIGXFSZ')) {
	pcntl_signal(SIGXFSZ, SIG_IGN);
}
$lim = posix_getrlimit();
$soft = $lim['soft filesize'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $lim['soft filesize'];
$hard = $lim['hard filesize'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $lim['hard filesize'];
var_dump(posix_setrlimit(POSIX_RLIMIT_FSIZE, $cut, $hard));
session_write_close();
var_dump(posix_setrlimit(POSIX_RLIMIT_FSIZE, $soft, $hard));

clearstatcache();
$raw = file_get_contents($file);
echo "size after interrupted write: ", strlen($raw), "\n";
echo "new data prefix intact: ", (substr($raw, 0, 10) === 'a|s:1490:"' ? 'yes' : 'no'), "\n";
echo "old data left in file: ", (strpos($raw, 'A') !== false || strpos($raw, 'stdClass') !== false ? 'yes' : 'no'), "\n";

/* Request 3: what the next request would unserialize. */
session_id($id);
@session_start();
echo "injected variable present: ", (isset($_SESSION['x']) ? 'yes (' . get_class($_SESSION['x']) . ')' : 'no'), "\n";
@session_abort();

@unlink($file);
@rmdir($dir);
echo "Done\n";
?>
--CLEAN--
<?php
$dir = __DIR__ . '/GHSA-fm2m-fjf5-v3cp.d';
foreach (glob("$dir/sess_*") ?: [] as $f) {
	@unlink($f);
}
@rmdir($dir);
?>
--EXPECTF--
int(2012)
bool(true)

Warning: session_write_close(): write wrote less bytes than requested in %s on line %d

Warning: session_write_close(): Failed to write session data (files). Please verify that the current setting of session.save_path is correct (%s) in %s on line %d
bool(true)
size after interrupted write: 1024
new data prefix intact: yes
old data left in file: no
injected variable present: no
Done
