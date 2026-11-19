--TEST--
GHSA-f6gg-rchx-xw8j: mixed-case FTPS URLs must enable TLS
--SKIPIF--
<?php
if (!extension_loaded('openssl')) die('skip openssl not available');
if (!function_exists('proc_open')) die('skip proc_open() not available');
if (!defined('PHP_BINARY') || PHP_BINARY === '') die('skip PHP_BINARY not available');
?>
--FILE--
<?php
$serverCode = <<<'CODE'
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
$conn = stream_socket_accept($server, 30);
fwrite($conn, "220 Ready\r\n");
$command = stream_get_line($conn, 8192, "\r\n");
fclose($conn);
fclose($server);
fwrite(STDOUT, $command . "\n");
CODE;

$script = __DIR__ . '/ghsa-f6gg-rchx-xw8j-server.tmp.php';
file_put_contents($script, "<?php\n" . $serverCode);
$proc = proc_open(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$addr = trim(fgets($pipes[1]));

var_dump(@fopen("FTPS://$addr/secret", 'r'));
echo trim(fgets($pipes[1])), "\n";

fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
?>
--CLEAN--
<?php
@unlink(__DIR__ . '/ghsa-f6gg-rchx-xw8j-server.tmp.php');
?>
--EXPECT--
bool(false)
AUTH TLS
