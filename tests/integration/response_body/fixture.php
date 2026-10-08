<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
DDTrace\root_span()->meta['test.case'] = $_SERVER['REQUEST_URI'];
header('Content-Type: application/json; charset=UTF-8');
switch ($path) {
    case '/json':
        echo '{"message":"你好","ok":true}';
        break;
    case '/plain':
        header('Content-Type: Text/Plain; charset=UTF-8');
        echo 'plain response';
        break;
    case '/child':
        function emit_response_body() { echo '{"child":true}'; }
        DDTrace\trace_function('emit_response_body', function ($span) { $span->meta['test.child'] = 'true'; });
        emit_response_body();
        break;
    case '/chunks':
        echo '{"a":';
        flush();
        echo '1,"b":2}';
        flush();
        break;
    case '/buffered':
        ob_start();
        echo 'discard this';
        ob_end_clean();
        ob_start(function ($body) { return strtoupper($body); });
        echo '{"ok":true}';
        ob_end_flush();
        break;
    case '/large':
        header('Content-Type: text/plain');
        echo str_repeat('x', 10000);
        break;
    case '/exact':
        header('Content-Type: text/plain');
        echo str_repeat('x', 8192);
        break;
    case '/html':
        header('Content-Type: text/html');
        echo '<p>not captured</p>';
        break;
    case '/binary':
        header('Content-Type: application/octet-stream');
        echo "\x00\x01\x02";
        break;
    case '/empty':
        break;
    case '/head':
        echo '{"invisible":true}';
        break;
    case '/encoded':
        header('Content-Encoding: gzip');
        echo gzencode('{"ok":true}');
        break;
    case '/identity':
        header('Content-Encoding: identity');
        echo '{"ok":true}';
        break;
    case '/php-gzip':
        ob_start('ob_gzhandler');
        echo '{"ok":true}';
        ob_end_flush();
        break;
    case '/error':
        http_response_code(500);
        echo '{"error":"example"}';
        break;
    case '/finished':
        echo '{"finished":true}';
        fastcgi_finish_request();
        echo 'not sent to the client';
        break;
    case '/disable-mid-request':
        echo '{"before":true}';
        flush();
        ini_set('datadog.trace.enabled', '0');
        echo 'after';
        break;
    default:
        echo '{"path":' . json_encode($path) . '}';
}
