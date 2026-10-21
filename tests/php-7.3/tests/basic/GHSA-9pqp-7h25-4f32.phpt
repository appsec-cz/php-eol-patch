--TEST--
GHSA-9pqp-7h25-4f32 (CVE-2024-8925: multipart/form-data boundary larger than the read buffer)
--SKIPIF--
<?php
if (!getenv('TEST_PHP_CGI_EXECUTABLE')) {
    die("skip php-cgi not available");
}
?>
--FILE--
<?php

const FILLUNIT = 5 * 1024;

function test($boundaryLen) {
    printf("Boundary len: %d\n", $boundaryLen);

    /* PHP 7.3: proc_open() takes a string command (array commands were
     * added in PHP 7.4). display_startup_errors=1 is needed because PHP 7.3
     * suppresses errors raised during request startup (which includes
     * multipart POST parsing) unless it is enabled; upstream PHP 8.x shows
     * the warning with default settings. */
    $cmd = sprintf(
        '%s -C -n -d display_startup_errors=1 %s',
        escapeshellarg(getenv('TEST_PHP_CGI_EXECUTABLE')),
        escapeshellarg(__DIR__ . '/GHSA-9pqp-7h25-4f32.inc')
    );

    $boundary = str_repeat('A', $boundaryLen);
    $body = ""
        . "--$boundary\r\n"
        . "Content-Disposition: form-data; name=\"koko\"\r\n"
        . "\r\n"
        . "BBB\r\n--" . substr($boundary, 0, -1) . "CCC\r\n"
        . "--$boundary--\r\n"
        ;

    $env = array_merge($_ENV, [
        'REDIRECT_STATUS' => '1',
        'CONTENT_TYPE' => "multipart/form-data; boundary=$boundary",
        'CONTENT_LENGTH' => strlen($body),
        'REQUEST_METHOD' => 'POST',
        'SCRIPT_FILENAME' => __DIR__ . '/GHSA-9pqp-7h25-4f32.inc',
    ]);

    $spec = [
        0 => ['pipe', 'r'],
        1 => STDOUT,
        2 => STDOUT,
    ];

    $pipes = [];

    print "Starting...\n";

    $handle = proc_open($cmd, $spec, $pipes, getcwd(), $env);

    fwrite($pipes[0], $body);

    $status = proc_close($handle);

    print "\n";
}

for ($offset = -1; $offset <= 1; $offset++) {
    test(FILLUNIT - strlen("\r\n--") + $offset);
}

?>
--EXPECTF--
Boundary len: 5115
Starting...
X-Powered-By: %s
Content-type: text/html; charset=UTF-8

Hello world
array(1) {
  ["koko"]=>
  string(5124) "BBB
--AAA%sCCC"
}

Boundary len: 5116
Starting...
X-Powered-By: %s
Content-type: text/html; charset=UTF-8

Hello world
array(1) {
  ["koko"]=>
  string(5125) "BBB
--AAA%sCCC"
}

Boundary len: 5117
Starting...
X-Powered-By: %s
Content-type: text/html; charset=UTF-8

<br />
<b>Warning</b>:  Boundary too large in multipart/form-data POST data in <b>Unknown</b> on line <b>0</b><br />
Hello world
array(0) {
}

