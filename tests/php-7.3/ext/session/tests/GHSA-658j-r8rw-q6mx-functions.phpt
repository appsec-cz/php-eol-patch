--TEST--
GHSA-658j-r8rw-q6mx: session_*() setters throw when session.upload_progress.enabled is On
--SKIPIF--
<?php include('skipif.inc'); ?>
--INI--
session.upload_progress.enabled=1
session.save_path=
session.name=NOTPHPSESSID
session.save_handler=files
session.serialize_handler=php_serialize
session.cache_limiter=nocache
session.cache_expire=180
session.use_cookies=1
session.use_strict_mode=0
session.cookie_lifetime=42
--FILE--
<?php
ob_start();

function attempt($label, callable $fn) {
    try {
        $ret = $fn();
        echo $label, ": no error, returned ", var_export($ret, true), "\n";
    } catch (\Throwable $e) {
        echo $label, ": ", get_class($e), ": ", $e->getMessage(), "\n";
    }
}

class Handler extends SessionHandler {}

echo "*** Setters ***\n";
attempt("session_name", function () { return session_name("PHPSESSID"); });
attempt("session_save_path", function () { return session_save_path("/tmp"); });
attempt("session_module_name", function () { return session_module_name("files"); });
attempt("session_cache_limiter", function () { return session_cache_limiter("public"); });
attempt("session_cache_expire", function () { return session_cache_expire(10); });
attempt("session_set_cookie_params", function () { return session_set_cookie_params(1, "/runtime"); });
attempt("session_set_cookie_params(array)", function () { return session_set_cookie_params(["samesite" => "Lax"]); });
attempt("session_set_save_handler(object)", function () { return session_set_save_handler(new Handler, false); });
attempt("session_set_save_handler(callables)", function () {
    $f = function () { return true; };
    return session_set_save_handler($f, $f, $f, $f, $f, $f);
});

echo "\n*** Nothing was changed, getters still work ***\n";
var_dump(session_name(), session_save_path(), session_module_name(), session_cache_limiter(), session_cache_expire());
var_dump(session_get_cookie_params()["lifetime"], session_get_cookie_params()["path"], session_get_cookie_params()["samesite"]);
var_dump(ini_get("session.save_handler"), ini_get("session.serialize_handler"));

echo "\n*** The session still starts with the configured settings ***\n";
session_id("ghsa658jfunctions");
var_dump(session_start(["read_and_close" => true]));
var_dump(session_status() === PHP_SESSION_NONE);
var_dump(session_module_name());

echo "Done\n";
?>
--CLEAN--
<?php
@unlink(sys_get_temp_dir() . '/sess_ghsa658jfunctions');
?>
--EXPECT--
*** Setters ***
session_name: Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
session_save_path: Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
session_module_name: Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
session_cache_limiter: Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
session_cache_expire: Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
session_set_cookie_params: Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
session_set_cookie_params(array): Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
session_set_save_handler(object): Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On
session_set_save_handler(callables): Error: Session ini settings cannot be changed at runtime when session.upload_progress.enabled is On

*** Nothing was changed, getters still work ***
string(12) "NOTPHPSESSID"
string(0) ""
string(5) "files"
string(7) "nocache"
int(180)
int(42)
string(1) "/"
string(0) ""
string(5) "files"
string(13) "php_serialize"

*** The session still starts with the configured settings ***
bool(true)
bool(true)
string(5) "files"
Done
