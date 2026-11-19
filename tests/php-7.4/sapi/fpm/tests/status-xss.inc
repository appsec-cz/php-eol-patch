<?php

/*
 * Helpers for the FPM status XSS tests (GHSA-7qg2-v9fj-4mwv,
 * GHSA-3mg3-cr28-46gc) on PHP 7.x. The 7.x FPM test framework has no
 * Tester::multiRequest(), so concurrent requests are issued here directly
 * through the bundled FastCGI client, with the same request parameters as
 * upstream's Tester::getRequestParams().
 */

require_once "tester.inc";

/**
 * Issue a set of concurrent requests and return their responses in order.
 *
 * Each request is an array with the optional keys 'uri', 'query', 'headers'
 * and 'delay' (microseconds to wait before sending it), like upstream's
 * Tester::multiRequest().
 *
 * @param FPM\Tester $tester
 * @param array $requests
 * @return FPM\Response[]
 */
function fpm_status_xss_multi_request(FPM\Tester $tester, array $requests)
{
    list($host, $port) = explode(':', $tester->getAddr());
    $connections = [];
    foreach ($requests as $request) {
        $scriptFilename = $tester->makeSourceFile();
        $uri = $request['uri'] ?? '/' . basename($scriptFilename);
        $query = $request['query'] ?? '';
        $params = array_merge(
            [
                'GATEWAY_INTERFACE' => 'FastCGI/1.0',
                'REQUEST_METHOD'    => 'GET',
                'SCRIPT_FILENAME'   => $scriptFilename,
                'SCRIPT_NAME'       => $uri,
                'QUERY_STRING'      => $query,
                'REQUEST_URI'       => $uri . ($query ? '?' . $query : ""),
                'DOCUMENT_URI'      => $uri,
                'SERVER_SOFTWARE'   => 'php/fcgiclient',
                'REMOTE_ADDR'       => '127.0.0.1',
                'REMOTE_PORT'       => '7777',
                'SERVER_ADDR'       => '127.0.0.1',
                'SERVER_PORT'       => '80',
                'SERVER_NAME'       => php_uname('n'),
                'SERVER_PROTOCOL'   => 'HTTP/1.1',
                'DOCUMENT_ROOT'     => __DIR__,
                'CONTENT_TYPE'      => '',
                'CONTENT_LENGTH'    => 0,
            ],
            $request['headers'] ?? []
        );
        if (isset($request['delay'])) {
            usleep($request['delay']);
        }
        $client = new Adoy\FastCGI\Client($host, $port);
        $connections[] = [$client, $client->async_request($params, false)];
    }

    $responses = [];
    foreach ($connections as list($client, $requestId)) {
        $responses[] = new FPM\Response($client->wait_for_response_data($requestId));
    }

    return $responses;
}
