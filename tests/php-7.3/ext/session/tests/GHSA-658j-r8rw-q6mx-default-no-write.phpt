--TEST--
GHSA-658j-r8rw-q6mx: with nothing configured, upload progress writes no session record
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
session.upload_progress.cleanup=0
session.upload_progress.freq=0
session.upload_progress.min_freq=0
--SKIPIF--
<?php include('skipif.inc'); ?>
--COOKIE--
PHPSESSID=ghsa658jdefaultnowrite
--POST_RAW--
Content-Type: multipart/form-data; boundary=---------------------------20896060251896012921717172737
-----------------------------20896060251896012921717172737
Content-Disposition: form-data; name="PHP_SESSION_UPLOAD_PROGRESS"

probe
-----------------------------20896060251896012921717172737
Content-Disposition: form-data; name="file1"; filename="file1.txt"

1
-----------------------------20896060251896012921717172737--
--FILE--
<?php
ob_start();
/* session.upload_progress.enabled is deliberately absent from --INI--, and
 * cleanup is Off so that a record written during request startup would still
 * be there. */
var_dump(ini_get('session.upload_progress.enabled'));
var_dump(file_exists(sys_get_temp_dir() . '/sess_ghsa658jdefaultnowrite'));
session_start();
var_dump(session_id());
var_dump($_SESSION);
session_destroy();
echo "Done\n";
?>
--CLEAN--
<?php
@unlink(sys_get_temp_dir() . '/sess_ghsa658jdefaultnowrite');
?>
--EXPECT--
string(1) "0"
bool(false)
string(22) "ghsa658jdefaultnowrite"
array(0) {
}
Done
