--TEST--
GHSA-f6gg-rchx-xw8j: mixed-case HTTPS URLs must use TLS
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
$request = fread($conn, 1);
if ($request === "\x16") {
    $result = 'TLS';
} else {
    while (($line = stream_get_line($conn, 8192, "\r\n")) !== false) {
        $request .= $line . "\r\n";
        if ($line === '') {
            break;
        }
    }
    $request .= stream_get_contents($conn, strlen('opensec-sensitive-body'));
    $result = strpos($request, 'Authorization: Basic ') !== false
        && strpos($request, 'X-Opensec-Request: validation') !== false
        && strpos($request, 'opensec-sensitive-body') !== false
            ? 'plaintext credentials and body'
            : 'unexpected plaintext request';
}
fclose($conn);
fclose($server);
fwrite(STDOUT, $result . "\n");
CODE;

$script = __DIR__ . '/ghsa-f6gg-rchx-xw8j-server.tmp.php';
file_put_contents($script, "<?php\n" . $serverCode);
$proc = proc_open(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$addr = trim(fgets($pipes[1]));

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'content' => 'opensec-sensitive-body',
        'header' => "X-Opensec-Request: validation\r\n",
        'timeout' => 10,
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
]);
var_dump(@file_get_contents("HTTPS://alice:secret@$addr/secret", false, $context));
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
TLS
