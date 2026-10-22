--TEST--
ghsa-wpj3-hf5j-x4v4 - unterminated '[' reassembles __Host-/__Secure- prefix after the mangling loop (CVE-2024-2756 distinguishing test)
--COOKIE--
.[Host-test=forged_host; _[Secure-test=forged_secure; ..Host-test2=forged_dots; __Host-legit=ok;
--FILE--
<?php
var_dump($_COOKIE);
?>
--EXPECT--
array(1) {
  ["__Host-legit"]=>
  string(2) "ok"
}
