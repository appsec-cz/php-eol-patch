--TEST--
GHSA-gr4f-wgx5-jpfv: in-request error_log timestamps keep the configured timezone
--INI--
date.timezone=Asia/Tokyo
log_errors=1
display_errors=0
error_log="{TMP}/GHSA-gr4f-wgx5-jpfv-localtime.log"
--FILE--
<?php
$log = ini_get('error_log');
if (file_exists($log)) {
    unlink($log);
}

function dump_log($log) {
    foreach (file($log, FILE_IGNORE_NEW_LINES) as $line) {
        if (!preg_match('/^\[([^\]]+) ([A-Za-z_\/]+)\] (.*)$/', $line, $m)) {
            echo "unexpected line: $line\n";
            continue;
        }
        /* The timestamp must be the current time expressed in the zone it names. */
        $dt = DateTime::createFromFormat('d-M-Y H:i:s', $m[1], new DateTimeZone($m[2]));
        $ok = $dt && abs($dt->getTimestamp() - time()) < 300 ? 'ok' : 'WRONG TIME';
        echo "$m[2] $ok: $m[3]\n";
    }
}

class D {
    public $log;
    function __destruct() {
        trigger_error("destructor", E_USER_WARNING);
        dump_log($this->log);
    }
}

trigger_error("main script", E_USER_WARNING);

date_default_timezone_set('Asia/Kolkata');
trigger_error("runtime timezone", E_USER_WARNING);
date_default_timezone_set('Asia/Tokyo');

register_shutdown_function(function () {
    trigger_error("shutdown function", E_USER_WARNING);
});

$d = new D;
$d->log = $log;
echo "Done\n";
?>
--CLEAN--
<?php
@unlink(sys_get_temp_dir() . '/GHSA-gr4f-wgx5-jpfv-localtime.log');
?>
--EXPECTF--
Done
Asia/Tokyo ok: PHP Warning:  main script in %s on line %d
Asia/Kolkata ok: PHP Warning:  runtime timezone in %s on line %d
Asia/Tokyo ok: PHP Warning:  shutdown function in %s on line %d
Asia/Tokyo ok: PHP Warning:  destructor in %s on line %d
