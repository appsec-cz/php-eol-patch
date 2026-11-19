--TEST--
GHSA-gr4f-wgx5-jpfv: log line after date's RSHUTDOWN, then a pre-RINIT log line in the next request
--DESCRIPTION--
In 7.3 ext/date frees DATEG(tzcache) in its RSHUTDOWN, before EG(active) is
cleared. A warning raised from a user stream wrapper's stream_close() (run by
zend_close_rsrc_list() in shutdown_executor()) is logged in local time and
re-creates the cache in the request heap, which is then reset. Before the fix,
the next request's "Input variables exceeded" warning (logged before RINIT)
read that stale cache and crashed the server. The second request posts 101
variables of varying sizes so that the recycled memory is overwritten before
the warning is logged.
--SKIPIF--
<?php
include "skipif.inc";
?>
--FILE--
<?php
include "php_cli_server.inc";

/* The stale cache only becomes invalid when the Zend allocator recycles it. */
putenv('USE_ZEND_ALLOC=1');

$log = sys_get_temp_dir() . '/GHSA-gr4f-wgx5-jpfv-window.log';
@unlink($log);

$window = <<<'PHP'
<?php
class W {
    public $context;
    function stream_open($path, $mode, $options, &$opened) { return true; }
    function stream_close() { trigger_error("close from wrapper", E_USER_WARNING); }
    function stream_eof() { return true; }
    function stream_read($n) { return ''; }
}
stream_wrapper_register('wrapx', 'W');
$GLOBALS['fp'] = fopen('wrapx://x', 'r');
echo "end\n";
PHP;
file_put_contents(__DIR__ . '/gw.php', $window);
file_put_contents(__DIR__ . '/go.php', "<?php echo \"ok\\n\";\n");

/* No router: the scripts are served directly from the test directory. */
php_cli_server_start(null, null,
    "-d date.timezone=Asia/Tokyo -d error_log=$log -d log_errors=1 -d display_errors=0"
    . " -d max_input_vars=100 -d report_memleaks=0");

$lens = [20, 60, 150, 300, 700, 2000, 8000, 30000];
$vars = [];
for ($i = 0; $i < 101; $i++) {
    $vars[] = "v$i=" . str_repeat('A', $lens[$i % count($lens)]);
}
$body = implode('&', $vars);

function request($path, $body = null) {
    $fp = @fsockopen(PHP_CLI_SERVER_HOSTNAME, PHP_CLI_SERVER_PORT, $errno, $errstr, 2);
    if (!$fp) {
        return "connect failed";
    }
    if ($body === null) {
        $req = "GET $path HTTP/1.1\r\nHost: " . PHP_CLI_SERVER_HOSTNAME . "\r\nConnection: close\r\n\r\n";
    } else {
        $req = "POST $path HTTP/1.1\r\nHost: " . PHP_CLI_SERVER_HOSTNAME . "\r\nConnection: close\r\n"
             . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
    }
    fwrite($fp, $req);
    $response = stream_get_contents($fp);
    fclose($fp);
    if ($response === '' || $response === false) {
        return "no response";
    }
    return strtok($response, "\r\n");
}

for ($i = 1; $i <= 3; $i++) {
    echo "round $i: ", request('/gw.php'), " / ", request('/go.php', $body), "\n";
}

$lines = file_exists($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
echo "wrapper lines: ", count(preg_grep('/close from wrapper/', $lines)), "\n";
echo "input-vars lines: ", count(preg_grep('/Input variables exceeded/', $lines)), "\n";
?>
--CLEAN--
<?php
@unlink(sys_get_temp_dir() . '/GHSA-gr4f-wgx5-jpfv-window.log');
@unlink(__DIR__ . '/gw.php');
@unlink(__DIR__ . '/go.php');
?>
--EXPECT--
round 1: HTTP/1.1 200 OK / HTTP/1.1 200 OK
round 2: HTTP/1.1 200 OK / HTTP/1.1 200 OK
round 3: HTTP/1.1 200 OK / HTTP/1.1 200 OK
wrapper lines: 3
input-vars lines: 3
