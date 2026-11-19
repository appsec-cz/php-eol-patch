--TEST--
GHSA-8mjj-2r7p-4hg2: backtrace args of normal, variadic and __call/__callStatic calls
--FILE--
<?php
function args_of($frame) { return json_encode($frame['args']); }

function f($a, $b) { return args_of(debug_backtrace()[0]); }
function v($a, ...$rest) { return args_of(debug_backtrace()[0]); }
function g() { $x = 42; return args_of(debug_backtrace()[0]); }
function h($a) { $s = 'sym'; extract(['q' => 1]); return args_of(debug_backtrace()[0]); }

class C {
    function __call($n, $args) { return $n . ' ' . args_of(debug_backtrace()[0]); }
    static function __callStatic($n, $args) { return $n . ' ' . args_of(debug_backtrace()[0]); }
    function thrower($a) { throw new Exception('x'); }
    function __get($n) { return $this->thrower($n, 'extra', [3]); }
}

echo f(1, 2), "\n";
echo f(1, 2, 3, 'four'), "\n";
echo v(1), "\n";
echo v(1, 2, 3, 'four'), "\n";
echo g(1, 2, 3), "\n";
echo h(1, 2, 3), "\n";
$c = new C;
echo $c->foo(), "\n";
echo $c->foo(1, 2, 3, 'four'), "\n";
echo C::bar(1, 2, 3, 'four'), "\n";
echo call_user_func([$c, 'baz'], 5, 6, 7), "\n";
echo call_user_func_array(['C', 'qux'], [8, 9]), "\n";
try {
    $c->prop;
} catch (Exception $e) {
    echo json_encode($e->getTrace()[0]['args']), "\n";
    echo $e->getTraceAsString(), "\n";
}
?>
--EXPECTF--
[1,2]
[1,2,3,"four"]
[1]
[1,2,3,"four"]
[1,2,3]
[1,2,3]
foo ["foo",[]]
foo ["foo",[1,2,3,"four"]]
bar ["bar",[1,2,3,"four"]]
baz ["baz",[5,6,7]]
qux ["qux",[8,9]]
["prop","extra",[3]]
#0 %s(%d): C->thrower('prop', 'extra', Array)
#1 %s(%d): C->__get('prop')
#2 {main}
