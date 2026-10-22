--TEST--
CVE-2024-3096: password_hash() rejects bcrypt passwords containing null bytes
--FILE--
<?php
// Adapted from the upstream regression addition to
// ext/standard/tests/password/password_bcrypt_errors.phpt (commit
// 0ba5229a3f7572846e91c8f5382e87785f543826). PHP 7.4 reports
// password_hash() input errors as warning + NULL instead of ValueError.
var_dump(password_hash("null\0password", PASSWORD_BCRYPT));

// The ATO scenario from GHSA-h746-cjrr-wfmr: on unpatched PHP,
// password_hash("\x00...", PASSWORD_BCRYPT) silently hashed an empty
// password, so password_verify('', $hash) returned true. Verify normal
// behaviour of password_verify() is unaffected by the fix.
$hash = password_hash("secret", PASSWORD_BCRYPT);
var_dump(password_verify('', $hash));
var_dump(password_verify('secret', $hash));
?>
--EXPECTF--
Warning: password_hash(): Bcrypt password must not contain null character in %s on line %d
NULL
bool(false)
bool(true)
