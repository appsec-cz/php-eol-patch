--TEST--
PharData reads consecutive TAR entries across a block boundary
--SKIPIF--
<?php
if (!extension_loaded('phar')) {
    die('skip phar required');
}
?>
--FILE--
<?php
$path = __DIR__ . '/regular-entry-boundaries.tar';
$data = str_repeat('a', 513);
$archive = new PharData($path);
$archive['first.txt'] = $data;
$archive['second.txt'] = 'end';
unset($archive);
$archive = new PharData($path);
var_dump($archive['first.txt']->getContent() === $data);
var_dump($archive['second.txt']->getContent());
?>
--CLEAN--
<?php
@unlink(__DIR__ . '/regular-entry-boundaries.tar');
?>
--EXPECT--
bool(true)
string(3) "end"
