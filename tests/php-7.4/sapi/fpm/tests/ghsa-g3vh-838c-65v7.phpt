--TEST--
FPM: GHSA-g3vh-838c-65v7 terminates a worker after interrupted session shutdown
--SKIPIF--
<?php
include "skipif.inc";
if (!extension_loaded('session')) die('skip session extension required');
?>
--FILE--
<?php
require_once "tester.inc";

$sessionDir = __DIR__ . '/ghsa-g3vh-838c-65v7.sessions';
@mkdir($sessionDir, 0700);

$cfg = <<<EOT
[global]
error_log = {{FILE:LOG}}
log_level = notice
[unconfined]
listen = {{ADDR:UDS}}
pm = static
pm.max_children = 1
php_admin_value[session.save_path] = $sessionDir
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = off
EOT;

$code = <<<'PHP'
<?php
if ($_SERVER['QUERY_STRING'] !== 'producer') {
    echo getmypid();
    return;
}

class ShutdownStream {
    public $context;

    public function stream_open($path, $mode, $options, &$openedPath) {
        return true;
    }

    public function stream_write($data) {
        return strlen($data);
    }

    public function stream_close() {
        trigger_error('session shutdown bailout', E_USER_ERROR);
    }
}

stream_wrapper_register('shutdown-test', 'ShutdownStream');
session_id('g3vh' . getmypid());
session_start();
$_SESSION['stream'] = fopen('shutdown-test://session', 'w');
unset($_SESSION);

// Finish the response before the fatal RSHUTDOWN callback runs.
echo 'producer';
fastcgi_finish_request();
PHP;

$tester = new FPM\Tester($cfg, $code);
$tester->start();
$tester->expectLogStartNotices();
$pid = (int) $tester->request('', [], null, '{{ADDR:UDS}}')->getBody();
$tester->request('producer', [], null, '{{ADDR:UDS}}')->expectBody('producer');
// The log helpers of this tester wait only a few seconds for a line. Give a
// loaded machine more time: wait until the master has reaped the worker (it
// logs the exit right after that). Without the fix the worker stays alive
// and this loop simply runs out.
if ($pid > 0 && is_dir('/proc/self')) {
    for ($i = 0; $i < 100 && file_exists('/proc/' . $pid); $i++) {
        usleep(100000);
    }
}
$tester->expectLogWarning(
    'child ' . $pid . ' exited with code 1 after \\d+\\.\\d+ seconds from start',
    'unconfined'
);
$tester->expectLogNotice('child \\d+ started', 'unconfined');
$replacement = (int) $tester->request('', [], null, '{{ADDR:UDS}}')->getBody();
var_dump($pid > 0 && $replacement > 0 && $replacement !== $pid);
$tester->terminate();
$tester->expectLogTerminatingNotices();
$tester->close();
?>
Done
--EXPECT--
bool(true)
Done
--CLEAN--
<?php
require_once "tester.inc";
FPM\Tester::clean();
$sessionDir = __DIR__ . '/ghsa-g3vh-838c-65v7.sessions';
foreach (glob($sessionDir . '/sess_*') as $file) {
    unlink($file);
}
@rmdir($sessionDir);
?>
