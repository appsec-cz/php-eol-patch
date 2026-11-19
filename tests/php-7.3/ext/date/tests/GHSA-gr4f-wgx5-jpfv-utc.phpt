--TEST--
GHSA-gr4f-wgx5-jpfv: error_log lines written outside an active request are timestamped in UTC
--SKIPIF--
<?php
if (!extension_loaded('zend-test')) die('skip zend-test extension not available');
/* The log is written before the test script runs; start from a clean file. */
@unlink(sys_get_temp_dir() . '/GHSA-gr4f-wgx5-jpfv-utc.log');
?>
--CGI--
--ENV--
QUERY_STRING=a=1&b=2
--INI--
date.timezone=Europe/Prague
error_log="{TMP}/GHSA-gr4f-wgx5-jpfv-utc.log"
log_errors=1
display_errors=0
max_input_vars=1
zend_test.warn_on_startup=1
--FILE--
<?php
trigger_error("in request", E_USER_WARNING);
foreach (file(ini_get('error_log'), FILE_IGNORE_NEW_LINES) as $line) {
    echo preg_replace('/^\[[^\]]* ([A-Za-z_\/]+)\]/', '[$1]', $line), "\n";
}
?>
--CLEAN--
<?php
@unlink(sys_get_temp_dir() . '/GHSA-gr4f-wgx5-jpfv-utc.log');
?>
--EXPECTF--
[UTC] PHP Warning:  Test startup warning from zend_test in Unknown on line 0
[UTC] PHP Warning:  Unknown: Input variables exceeded 1. To increase the limit change max_input_vars in php.ini. in Unknown on line 0
[Europe/Prague] PHP Warning:  in request in %s on line %d
