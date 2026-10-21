--TEST--
OOM during request parsing does not leak into the next request (PHP 7.4 backport)
--SKIPIF--
<?php
if (!function_exists('proc_open')) die('skip proc_open not available');
?>
--FILE--
<?php
include "php_cli_server.inc";
php_cli_server_start(<<<'PHP'
spl_autoload_register(function ($class) {
    eval("class $class {}");
});
ob_start();
echo get_class(new Foo), "\n";
trigger_error("Bar", E_USER_WARNING);
ob_end_flush();
PHP
, 'index.php', ['-d', 'memory_limit=8M', '-d', 'post_max_size=64M', '-d', 'html_errors=0', '-d', 'display_startup_errors=1']);

function send_req(string $request): string {
    $fp = fsockopen(PHP_CLI_SERVER_HOSTNAME, PHP_CLI_SERVER_PORT);
    fwrite($fp, $request);
    $response = stream_get_contents($fp);
    fclose($fp);

    return $response;
}

$body = 'a=' . str_repeat('a', 6 * 1024 * 1024);
$response = send_req("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
var_dump(strpos($response, 'Allowed memory size of 8388608 bytes exhausted') !== false);

$response = send_req("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
echo substr($response, strpos($response, "\r\n\r\n") + 4);
?>
--EXPECTF--
bool(true)
Foo

Warning: Bar in %s on line %d
