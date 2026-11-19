--TEST--
GHSA-658j-r8rw-q6mx: every runtime-modifiable session setting is frozen when session.upload_progress.enabled is On
--SKIPIF--
<?php include('skipif.inc'); ?>
--INI--
session.upload_progress.enabled=1
session.save_path=
session.save_handler=files
--FILE--
<?php
/* Walks the INI table of the session module instead of a hand-written list,
 * so a setting that is added or re-wired without the check shows up here. */
$expected = 'Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On';
$entries = ini_get_all('session');
ksort($entries);

foreach ($entries as $name => $info) {
    if ($info['access'] & INI_USER) {
        try {
            $ret = ini_set($name, $info['local_value']);
            echo $name, ": NOT refused, ini_set() returned ", var_export($ret, true), "\n";
        } catch (Error $e) {
            echo $name, ": ", $e->getMessage() === $expected ? "refused" : "unexpected: " . $e->getMessage(), "\n";
        }
    } else {
        /* PHP_INI_PERDIR: never modifiable at runtime */
        echo $name, ": ", var_export(ini_set($name, $info['local_value']), true), "\n";
    }
}
echo "Done\n";
?>
--EXPECT--
session.auto_start: false
session.cache_expire: refused
session.cache_limiter: refused
session.cookie_domain: refused
session.cookie_httponly: refused
session.cookie_lifetime: refused
session.cookie_path: refused
session.cookie_samesite: refused
session.cookie_secure: refused
session.gc_divisor: refused
session.gc_maxlifetime: refused
session.gc_probability: refused
session.lazy_write: refused
session.name: refused
session.referer_check: refused
session.save_handler: refused
session.save_path: refused
session.serialize_handler: refused
session.sid_bits_per_character: refused
session.sid_length: refused
session.upload_progress.cleanup: false
session.upload_progress.enabled: false
session.upload_progress.freq: false
session.upload_progress.min_freq: false
session.upload_progress.name: false
session.upload_progress.prefix: false
session.use_cookies: refused
session.use_only_cookies: refused
session.use_strict_mode: refused
session.use_trans_sid: refused
Done
