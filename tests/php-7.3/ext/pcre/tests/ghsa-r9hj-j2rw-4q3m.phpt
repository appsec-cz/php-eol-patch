--TEST--
GHSA-r9hj-j2rw-4q3m (PCRE2 JIT: large single stack allocation on a growable JIT stack)
--DESCRIPTION--
Regression pattern and subject taken verbatim from PCRE2's testdata/testinput17
(added by PCRE2 commit 2b4038298072684b0fae29b15bedfb1a75bda46d):
  /((?(DEFINE)\[()\g{-1}]{1400}).{1}(?R)|)/expand,jit
      AAAAAA\=jitstack=192
pcre2test's "expand" modifier repeats "()\g{-1}" 1400 times; str_repeat()
does the same here. PHP assigns a growable 32K..192K JIT stack, which matches
the jitstack=192 setting of the upstream test. Upstream's expected result is
"Failed: error -46: JIT stack limit reached".
On 32-bit builds the JIT frames are smaller, the match fits into the 192K
stack and succeeds like the interpreter does. Both outcomes are correct; an
unpatched build crashes instead.
--SKIPIF--
<?php
if (ini_get("pcre.jit") === FALSE) {
	die("skip no jit built");
}
?>
--INI--
pcre.jit=1
--FILE--
<?php
$pattern = '/((?(DEFINE)' . str_repeat('()\g{-1}', 1400) . ').{1}(?R)|)/';
$r = preg_match($pattern, 'AAAAAA');
$e = preg_last_error();
if (PHP_INT_SIZE === 8) {
    var_dump($r === false && $e === PREG_JIT_STACKLIMIT_ERROR);
} else {
    var_dump(($r === false && $e === PREG_JIT_STACKLIMIT_ERROR) || ($r === 1 && $e === PREG_NO_ERROR));
}
?>
--EXPECT--
bool(true)
