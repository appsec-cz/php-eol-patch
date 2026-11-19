--TEST--
GHSA-8mjj-2r7p-4hg2 (soft OOM while sending args to a __callStatic trampoline)
--INI--
zend.exception_ignore_args=0
memory_limit=4M
--SKIPIF--
<?php
if (getenv("USE_ZEND_ALLOC") === "0") die("skip requires ZendMM");
?>
--FILE--
<?php

class C {
    static function __callStatic($name, $args)
    {
    }
}

$a = str_repeat('a', 1*1024*1024);

// Soft OOM during CONCAT will be handled by DO_FCALL
C::test(1, 2, 3, 4, $a.$a);

?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted %S(tried to allocate %d bytes) in %s on line 12
