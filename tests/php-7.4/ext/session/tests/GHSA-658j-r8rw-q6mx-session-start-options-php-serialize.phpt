--TEST--
GHSA-658j-r8rw-q6mx: session_start() with refused options does not start the session and keeps the stored record (php_serialize)
--SKIPIF--
<?php include('skipif.inc'); ?>
--INI--
session.upload_progress.enabled=1
session.save_path=
session.save_handler=files
session.serialize_handler=php_serialize
session.use_strict_mode=0
session.use_cookies=0
session.cache_limiter=
session.cookie_lifetime=0
--FILE--
<?php
ob_start();

/* PHP 7.x cannot unserialize any object while an exception is pending. If
 * session_start() went on to read the session after an option was refused,
 * this record would fail to decode and be destroyed (php, php_binary) or be
 * written back empty (php_serialize). */
$file = sys_get_temp_dir() . '/sess_ghsa658jstartoptionsser';
$record = 'a:2:{s:1:"n";i:5;s:1:"w";O:8:"stdClass":1:{s:1:"a";i:1;}}';
file_put_contents($file, $record);
session_id('ghsa658jstartoptionsser');

try {
    var_dump(session_start(['cookie_lifetime' => '5']));
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_dump(session_status() === PHP_SESSION_NONE);
var_dump(ini_get('session.cookie_lifetime'));
var_dump(isset($_SESSION));
var_dump(file_exists($file) && file_get_contents($file) === $record);

echo "*** request end would not touch the record either ***\n";
session_write_close();
var_dump(file_exists($file) && file_get_contents($file) === $record);

echo "*** the session is still usable without ini options ***\n";
var_dump(session_start(['read_and_close' => true]));
var_dump(session_id());
var_dump($_SESSION['n'], get_class($_SESSION['w']), $_SESSION['w']->a);
var_dump(file_exists($file) && file_get_contents($file) === $record);
echo "Done\n";
?>
--CLEAN--
<?php
@unlink(sys_get_temp_dir() . '/sess_ghsa658jstartoptionsser');
?>
--EXPECTF--
Warning: session_start(): Setting option 'cookie_lifetime' failed in %s on line %d
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
bool(true)
string(1) "0"
bool(false)
bool(true)
*** request end would not touch the record either ***
bool(true)
*** the session is still usable without ini options ***
bool(true)
string(23) "ghsa658jstartoptionsser"
int(5)
string(8) "stdClass"
int(1)
bool(true)
Done
