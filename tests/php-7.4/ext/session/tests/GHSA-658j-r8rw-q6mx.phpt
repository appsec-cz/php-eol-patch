--TEST--
GHSA-658j-r8rw-q6mx: a record written by upload progress cannot be read back with another serializer
--INI--
file_uploads=1
upload_max_filesize=1024
session.save_path=
session.name=PHPSESSID
session.use_strict_mode=0
session.use_cookies=1
session.use_only_cookies=0
session.cache_limiter=
session.save_handler=files
session.serialize_handler=php_serialize
session.upload_progress.enabled=1
session.upload_progress.cleanup=0
session.upload_progress.prefix=upload_progress_
session.upload_progress.name=PHP_SESSION_UPLOAD_PROGRESS
session.upload_progress.freq=0
session.upload_progress.min_freq=0
--SKIPIF--
<?php include('skipif.inc'); ?>
--COOKIE--
PHPSESSID=ghsa658jr8rwq6mx
--POST_RAW--
Content-Type: multipart/form-data; boundary=---------------------------20896060251896012921717172737
-----------------------------20896060251896012921717172737
Content-Disposition: form-data; name="PHP_SESSION_UPLOAD_PROGRESS"

|O:6:"Gadget":1:{s:3:"cmd";s:2:"id";}
-----------------------------20896060251896012921717172737
Content-Disposition: form-data; name="file1"; filename="file1.txt"

1
-----------------------------20896060251896012921717172737--
--FILE--
<?php
ob_start();

class Gadget {
    public $cmd;
    function __wakeup() { echo "Gadget::__wakeup(cmd={$this->cmd})\n"; }
    function __destruct() { echo "Gadget::__destruct\n"; }
}

/* The upload progress handler wrote the record during request startup, before
 * this script was compiled, with the serializer configured at that time
 * (php_serialize). Reading it back with the "php" serializer would hand the
 * part of the array key behind the '|' to unserialize(). */
try {
    var_dump(ini_set('session.serialize_handler', 'php'));
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_dump(ini_get('session.serialize_handler'));

session_start();
foreach ($_SESSION as $key => $value) {
    var_dump($key, is_object($value) ? get_class($value) : gettype($value));
}
session_destroy();
echo "Done\n";
?>
--CLEAN--
<?php
@unlink(sys_get_temp_dir() . '/sess_ghsa658jr8rwq6mx');
?>
--EXPECT--
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
string(13) "php_serialize"
string(53) "upload_progress_|O:6:"Gadget":1:{s:3:"cmd";s:2:"id";}"
string(5) "array"
Done
