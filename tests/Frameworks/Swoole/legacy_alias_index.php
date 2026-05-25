<?php

require __DIR__ . '/../../vendor/autoload.php';

$serverClass = class_exists('swoole_http_server', false) ? 'swoole_http_server' : Swoole\Http\Server::class;

$http = new $serverClass("0.0.0.0", $argv[1]);
$http->set([
    'worker_num' => 2
]);
$http->on('request', function ($request, $response) {
    $requestUri = $request->server['request_uri'];

    try {
        if ($requestUri == "/server_class") {
            $response->status(200);
            $response->header('content-type', 'application/json');
            $response->end(json_encode([
                'constructor_class' => $GLOBALS['serverClass'],
                'runtime_class' => get_class($GLOBALS['http']),
            ]));
            return;
        }

        if ($requestUri == "/error") {
            throw new \Exception("Error page");
        }

        $response->status(200);
        $response->end('Hello Swoole!');
    } catch (\Throwable $e) {
        $response->status(500);
        $response->end('Something Went Wrong!');
    }
});

$http->start();
