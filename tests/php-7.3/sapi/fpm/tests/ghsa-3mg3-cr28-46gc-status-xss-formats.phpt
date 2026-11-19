--TEST--
FPM: GHSA-3mg3-cr28-46gc / GHSA-7qg2-v9fj-4mwv - status escaping in html, xml, json and plain formats
--SKIPIF--
<?php
include "skipif.inc";
if (!extension_loaded('dom')) die('skip dom extension required');
if (!extension_loaded('json')) die('skip json extension required');
?>
--FILE--
<?php

require_once "tester.inc";
require_once "status-xss.inc";

$cfg = <<<EOT
[global]
error_log = {{FILE:LOG}}
[unconfined]
listen = {{ADDR}}
pm = static
pm.max_children = 6
pm.status_path = /status
catch_workers_output = yes
EOT;

$code = <<<EOT
<?php
usleep(1000000);
EOT;

$scriptA = __DIR__ . '/ghsa-3mg3-cr28-46gc-formats-"&<f>\'.php';
file_put_contents($scriptA, $code);

/* A: markup and JSON meta characters, a control character and valid UTF-8 */
$methodA = 'P"<m>&\'';
$uriA = "/a\"b\\c<u>&d'e\x01\u{e9}\u{1F600}/f.php";
$queryA = 'q=1"2\\3<q>&r=\'4\'';
$userA = 'u"<a>&\\';
/* B: invalid UTF-8 sequences */
$methodB = 'BAD';
$uriB = "/bad\xff<i>\x1f";
$queryB = "x=\xc3(";
$userB = "v\xe2\x82w";

$tester = new FPM\Tester($cfg, $code);
$tester->start();
$tester->expectLogStartNotices();
$responses = fpm_status_xss_multi_request($tester, [
    [
        'uri' => $uriA,
        'query' => $queryA,
        'headers' => [
            'REQUEST_METHOD' => $methodA,
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($userA . ':pw'),
            'SCRIPT_FILENAME' => $scriptA,
        ],
    ],
    [
        'uri' => $uriB,
        'query' => $queryB,
        'headers' => [
            'REQUEST_METHOD' => $methodB,
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($userB . ':pw'),
        ],
    ],
    ['uri' => '/status', 'query' => 'full&html', 'delay' => 200000],
    ['uri' => '/status', 'query' => 'full&xml', 'delay' => 100000],
    ['uri' => '/status', 'query' => 'full&json', 'delay' => 100000],
    ['uri' => '/status', 'query' => 'full', 'delay' => 100000],
]);

/* What the html/xml output must decode to: the raw value, with characters
 * not allowed in XML 1.0 replaced by U+FFFD and invalid UTF-8 giving "". */
$expectA = [
    'method' => $methodA,
    'uri' => str_replace("\x01", "\u{FFFD}", $uriA) . '?' . $queryA,
    'user' => $userA,
    'script' => $scriptA,
];
$expectB = [
    'method' => $methodB,
    'uri' => '?',
    'user' => '',
];

function check_rows($format, array $rows, array $expectA, array $expectB)
{
    /* the order of the workers in the scoreboard is not deterministic */
    $found = 0;
    foreach (['A' => $expectA, 'B' => $expectB] as $name => $expect) {
        foreach ($rows as $row) {
            if ($row['method'] === $expect['method']) {
                $found++;
                foreach ($expect as $key => $value) {
                    echo "$format $name $key: ", var_export($row[$key] === $value, true), "\n";
                }
            }
        }
    }
    echo "$format rows found: $found\n";
}

/* html (XHTML, so it must be well-formed XML) */
$html = (string) $responses[2]->getBody('text/html');
$doc = new DOMDocument();
$ok = @$doc->loadXML($html);
echo "html well-formed: ", var_export($ok, true), "\n";
$rows = [];
if ($ok) {
    foreach ($doc->getElementsByTagName('tr') as $tr) {
        $td = $tr->getElementsByTagName('td');
        if ($td->length > 10) {
            $rows[] = [
                'method' => $td->item(6)->textContent,
                'uri' => $td->item(7)->textContent,
                'user' => $td->item(9)->textContent,
                'script' => $td->item(10)->textContent,
            ];
        }
    }
}
check_rows('html', $rows, $expectA, $expectB);

/* xml */
$xml = (string) $responses[3]->getBody('text/xml');
$doc = new DOMDocument();
$ok = @$doc->loadXML($xml);
echo "xml well-formed: ", var_export($ok, true), "\n";
$rows = [];
if ($ok) {
    foreach ($doc->getElementsByTagName('process') as $p) {
        $rows[] = [
            'method' => $p->getElementsByTagName('request-method')->item(0)->textContent,
            'uri' => $p->getElementsByTagName('request-uri')->item(0)->textContent,
            'user' => $p->getElementsByTagName('user')->item(0)->textContent,
            'script' => $p->getElementsByTagName('script')->item(0)->textContent,
        ];
    }
}
check_rows('xml', $rows, $expectA, $expectB);

/* json: valid JSON that round-trips the raw values (invalid UTF-8 dropped) */
$json = (string) $responses[4]->getBody('application/json');
$status = json_decode($json, true);
echo "json valid: ", var_export(json_last_error() === JSON_ERROR_NONE, true), "\n";
$rows = [];
foreach ($status['processes'] ?? [] as $p) {
    $rows[] = [
        'method' => $p['request method'],
        'uri' => $p['request uri'],
        'user' => $p['user'],
        'script' => $p['script'],
    ];
}
check_rows('json', $rows, [
    'method' => $methodA,
    'uri' => $uriA . '?' . $queryA,
    'user' => $userA,
    'script' => $scriptA,
], [
    'method' => $methodB,
    'uri' => "/bad<i>\x1f?x=(",
    'user' => 'vw',
]);
/* byte-for-byte the escaping of ext/json with JSON_INVALID_UTF8_IGNORE */
foreach (['A' => $uriA . '?' . $queryA, 'B' => $uriB . '?' . $queryB] as $name => $raw) {
    $escaped = substr(json_encode($raw, JSON_INVALID_UTF8_IGNORE), 1, -1);
    echo "json $name matches json_encode: ",
        var_export(strpos($json, '"request uri":"' . $escaped . '"') !== false, true), "\n";
}

/* plain text is not markup and stays unescaped */
$plain = (string) $responses[5]->getBody('text/plain');
echo "plain raw method: ", var_export(strpos($plain, "request method:       $methodA\n") !== false, true), "\n";
echo "plain raw user: ", var_export(strpos($plain, "user:                 $userA\n") !== false, true), "\n";

$tester->terminate();
$tester->expectLogTerminatingNotices();
$tester->close();

?>
Done
--EXPECT--
html well-formed: true
html A method: true
html A uri: true
html A user: true
html A script: true
html B method: true
html B uri: true
html B user: true
html rows found: 2
xml well-formed: true
xml A method: true
xml A uri: true
xml A user: true
xml A script: true
xml B method: true
xml B uri: true
xml B user: true
xml rows found: 2
json valid: true
json A method: true
json A uri: true
json A user: true
json A script: true
json B method: true
json B uri: true
json B user: true
json rows found: 2
json A matches json_encode: true
json B matches json_encode: true
plain raw method: true
plain raw user: true
Done
--CLEAN--
<?php
require_once "tester.inc";
FPM\Tester::clean();
@unlink(__DIR__ . '/ghsa-3mg3-cr28-46gc-formats-"&<f>\'.php');
?>
