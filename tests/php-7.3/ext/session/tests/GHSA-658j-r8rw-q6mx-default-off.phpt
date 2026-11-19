--TEST--
GHSA-658j-r8rw-q6mx: session.upload_progress.enabled is Off by default, and session.* can then be changed at runtime as before
--SKIPIF--
<?php include('skipif.inc'); ?>
--INI--
session.save_path=
session.save_handler=files
--FILE--
<?php
ob_start();
var_dump(ini_get("session.upload_progress.enabled"));
/* PHP_INI_PERDIR: a script cannot switch it on */
var_dump(ini_set("session.upload_progress.enabled", "1"));
var_dump(ini_get("session.upload_progress.enabled"));

var_dump(ini_set("session.serialize_handler", "php_serialize"));
var_dump(ini_get("session.serialize_handler"));
var_dump(session_name("OTHER"));
var_dump(session_name());
var_dump(ini_set("session.cookie_samesite", "Lax"));
var_dump(session_set_cookie_params(["samesite" => "Strict"]));
var_dump(ini_get("session.cookie_samesite"));
var_dump(session_start(["use_strict_mode" => "1", "lazy_write" => "0", "use_trans_sid" => "0"]));
var_dump(ini_get("session.use_strict_mode"), ini_get("session.lazy_write"));
session_destroy();
ini_restore("session.serialize_handler");
var_dump(ini_get("session.serialize_handler"));
echo "Done\n";
?>
--EXPECT--
string(1) "0"
bool(false)
string(1) "0"
string(3) "php"
string(13) "php_serialize"
string(9) "PHPSESSID"
string(5) "OTHER"
string(0) ""
bool(true)
string(6) "Strict"
bool(true)
string(1) "1"
string(1) "0"
string(3) "php"
Done
