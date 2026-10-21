--TEST--
Bug #81720 (Uninitialized array in pg_query_params() leading to RCE) - CVE-2022-31625
--SKIPIF--
<?php include("skipif.inc"); ?>
--FILE--
<?php
// Adapted for PHP 7.3. Unlike 7.4+ (where converting an object without
// __toString() throws a catchable Error), 7.3 raises E_RECOVERABLE_ERROR.
// A user error handler is installed to swallow it so execution continues.
// In that case 7.3's convert_to_string() yields the placeholder string
// "Object", so the buggy error branch is not taken; without a handler the
// engine bails out of the whole request. Either way PHP must not crash or
// free uninitialized heap pointers (UAF). This test exercises both
// pg_query_params() and pg_send_execute() with an unconvertible parameter
// and verifies clean behavior (the upstream fix frees only the first i
// initialized entries on the error path).
set_error_handler(function($errno, $errstr) {
    echo $errstr, PHP_EOL;
    return true;
});

include('config.inc');

$conn = pg_connect($conn_str);

pg_query_params($conn, 'SELECT $1, $2', array(1, new stdClass()));

pg_send_prepare($conn, "my_query", 'SELECT $1, $2');
pg_get_result($conn);
pg_send_execute($conn, "my_query", array(1, new stdClass()));

echo "done\n";
?>
--EXPECT--
Object of class stdClass could not be converted to string
Object of class stdClass could not be converted to string
done
