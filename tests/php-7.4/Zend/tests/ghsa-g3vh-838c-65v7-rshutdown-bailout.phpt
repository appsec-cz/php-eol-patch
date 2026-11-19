--TEST--
GHSA-g3vh-838c-65v7: the process does not continue after an interrupted RSHUTDOWN
--SKIPIF--
<?php
if (!extension_loaded('session')) die('skip session extension required');
if (!function_exists('proc_open')) die('skip proc_open() required');
// The child processes run with -n, so session must be compiled in.
$check = shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg('echo (int) extension_loaded("session");'));
if (trim((string) $check) !== '1') die('skip session extension must be compiled in statically');
?>
--FILE--
<?php
$dir = __DIR__ . '/ghsa-g3vh-838c-65v7-rshutdown';
@mkdir($dir, 0700);
$script = $dir . '/child.php';

$prelude = <<<'PHP'
<?php
class ShutdownStream {
    public static $exit = false;
    public $context;
    public function stream_open($path, $mode, $options, &$openedPath) { return true; }
    public function stream_close() {
        if (self::$exit) {
            exit(7);
        }
        trigger_error('rshutdown bailout', E_USER_ERROR);
    }
}
stream_wrapper_register('shutdown-test', 'ShutdownStream');
PHP;

$cases = [
    // a fatal error escapes from the session module's RSHUTDOWN
    'session' => 'session_start(); $_SESSION["s"] = fopen("shutdown-test://s", "w"); unset($_SESSION);',
    // the same with exit(): no error is reported at all
    'session-exit' => 'ShutdownStream::$exit = true; session_start(); $_SESSION["s"] = fopen("shutdown-test://s", "w"); unset($_SESSION);',
    // a fatal error escapes from an earlier module's RSHUTDOWN (ext/standard),
    // which used to skip the session module's RSHUTDOWN altogether
    'standard' => 'session_start(); $_SESSION["a"] = 1; function t() {} register_tick_function("t", fopen("shutdown-test://s", "w"));',
    // controls: none of these interrupts a module's RSHUTDOWN
    'script-fatal' => 'session_start(); $_SESSION["a"] = 1; trigger_error("script fatal", E_USER_ERROR);',
    'script-exit' => 'session_start(); $_SESSION["a"] = 1; echo "done"; exit(7);',
    'sleep-fatal' => 'class S { function __sleep() { trigger_error("sleep fatal", E_USER_ERROR); } } session_start(); $_SESSION["o"] = new S;',
    'clean' => 'session_start(); $_SESSION["a"] = 1;',
];

$diag = 'request shutdown was interrupted';

foreach ($cases as $name => $body) {
    file_put_contents($script, $prelude . "\n" . $body . "\necho 'done';\n");
    $cmd = [PHP_BINARY, '-n', '-d', 'session.save_path=' . $dir, '-d', 'display_errors=0', '-d', 'log_errors=0', $script];
    $cmd = implode(' ', array_map('escapeshellarg', $cmd));
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $status = proc_close($proc);
    printf("%s: out=%s exit=%d diagnostic=%s\n", $name, $out, $status, strpos($err, $diag) !== false ? 'yes' : 'no');
}
?>
--CLEAN--
<?php
$dir = __DIR__ . '/ghsa-g3vh-838c-65v7-rshutdown';
foreach ((array) glob($dir . '/*') as $file) {
    unlink($file);
}
@rmdir($dir);
?>
--EXPECT--
session: out=done exit=1 diagnostic=yes
session-exit: out=done exit=1 diagnostic=yes
standard: out=done exit=1 diagnostic=yes
script-fatal: out= exit=255 diagnostic=no
script-exit: out=done exit=7 diagnostic=no
sleep-fatal: out=done exit=255 diagnostic=no
clean: out=done exit=0 diagnostic=no
