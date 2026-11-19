--TEST--
GHSA-658j-r8rw-q6mx: ini_set() for session.* is not allowed when session.upload_progress.enabled is On
--SKIPIF--
<?php include('skipif.inc'); ?>
--INI--
session.upload_progress.enabled=1
session.save_path=
session.name=NOTPHPSESSID
session.save_handler=files
session.serialize_handler=php_serialize
session.cookie_lifetime=42
session.gc_probability=2
session.cookie_path=/startup
session.cookie_secure=1
session.cookie_samesite=Strict
session.use_trans_sid=1
session.lazy_write=0
session.sid_length=40
session.sid_bits_per_character=5
--FILE--
<?php
/* Port of the upstream test session_ini_set_upload_progress_enabled.phpt
 * (php-src 3f70f66611583bca4ec18385f7f407e2808fa61f) for PHP 7.x: no
 * --EXTENSIONS-- section, get_class() instead of ::class on an object, and
 * the three session settings that have their own modify handler in 7.x. */

/* The INI section above is applied at the startup stage, so these values must
 * have been applied even though session.upload_progress.enabled is On. */
echo "*** Settings applied at startup ***\n";
foreach ([
    "session.name",
    "session.serialize_handler",
    "session.cookie_lifetime",
    "session.gc_probability",
    "session.cookie_path",
    "session.cookie_secure",
    "session.cookie_samesite",
    "session.use_trans_sid",
    "session.lazy_write",
    "session.sid_length",
    "session.sid_bits_per_character",
] as $name) {
    printf("%s=%s\n", $name, var_export(ini_get($name), true));
}

echo "\n*** ini_set() is rejected at runtime ***\n";

function try_ini_set($name, $value) {
    try {
        var_dump(ini_set($name, $value));
    } catch (\Throwable $e) {
        echo get_class($e), ": ", $e->getMessage(), "\n";
    }
}

/* One entry per ini modify handler guarded by the check. PHP 7.x has three
 * handlers more than 8.2: OnUpdateTransSid, OnUpdateLazyWrite and the one for
 * session.cookie_samesite. */
try_ini_set("session.save_path", "/tmp");
try_ini_set("session.name", "PHPSESSID");
try_ini_set("session.save_handler", "user");
try_ini_set("session.serialize_handler", "php");
try_ini_set("session.cookie_lifetime", "1");
try_ini_set("session.gc_probability", "1");
try_ini_set("session.cookie_path", "/runtime");
try_ini_set("session.cookie_secure", "0");
try_ini_set("session.sid_length", "32");
try_ini_set("session.sid_bits_per_character", "4");
try_ini_set("session.use_trans_sid", "0");
try_ini_set("session.lazy_write", "1");
try_ini_set("session.cookie_samesite", "Lax");

echo "\n*** ini_restore() is rejected at runtime ***\n";
try {
    var_dump(ini_restore("session.name"));
} catch (\Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
var_dump(ini_get("session.name"));

echo "\n*** Values are unchanged ***\n";
foreach ([
    "session.name",
    "session.serialize_handler",
    "session.cookie_lifetime",
    "session.gc_probability",
    "session.cookie_path",
    "session.cookie_secure",
    "session.cookie_samesite",
    "session.use_trans_sid",
    "session.lazy_write",
    "session.sid_length",
    "session.sid_bits_per_character",
] as $name) {
    printf("%s=%s\n", $name, var_export(ini_get($name), true));
}

echo "\n*** Non-session ini settings are unaffected ***\n";
var_dump(ini_set("precision", "10"));
var_dump(ini_get("precision"));

echo "Done\n";
?>
--EXPECT--
*** Settings applied at startup ***
session.name='NOTPHPSESSID'
session.serialize_handler='php_serialize'
session.cookie_lifetime='42'
session.gc_probability='2'
session.cookie_path='/startup'
session.cookie_secure='1'
session.cookie_samesite='Strict'
session.use_trans_sid='1'
session.lazy_write='0'
session.sid_length='40'
session.sid_bits_per_character='5'

*** ini_set() is rejected at runtime ***
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On

*** ini_restore() is rejected at runtime ***
Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
string(12) "NOTPHPSESSID"

*** Values are unchanged ***
session.name='NOTPHPSESSID'
session.serialize_handler='php_serialize'
session.cookie_lifetime='42'
session.gc_probability='2'
session.cookie_path='/startup'
session.cookie_secure='1'
session.cookie_samesite='Strict'
session.use_trans_sid='1'
session.lazy_write='0'
session.sid_length='40'
session.sid_bits_per_character='5'

*** Non-session ini settings are unaffected ***
string(2) "14"
string(2) "10"
Done
