<?php

use swoole_http_server;

final class LegacyAliasSwooleDemo
{
    /** @var swoole_http_server */
    private $swoole;

    public function __construct(
        string $host = '0.0.0.0',
        int $port = 9501,
        int $mode = SWOOLE_PROCESS,
        int $socketType = SWOOLE_SOCK_TCP
    ) {
        // Keep the constructor form aligned with the customer's usage.
        $this->swoole = new swoole_http_server($host, $port, $mode, $socketType);

        $this->swoole->set([
            'worker_num' => 1,
            'daemonize' => 0,
        ]);

        $this->swoole->on('request', function ($request, $response): void {
            $path = $request->server['request_uri'] ?? '/';

            if ($path === '/diag') {
                $response->status(200);
                $response->header('content-type', 'application/json');
                $response->end(json_encode($this->buildDiag($request), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return;
            }

            if ($path === '/error') {
                throw new RuntimeException('legacy alias demo error');
            }

            $response->status(200);
            $response->header('content-type', 'text/plain');
            $response->end("legacy alias demo ok\n");
        });
    }

    public function start(): void
    {
        $this->swoole->start();
    }

    private function buildDiag($request): array
    {
        $rootSpan = function_exists('DDTrace\\root_span') ? \DDTrace\root_span() : null;

        return [
            'php_sapi' => PHP_SAPI,
            'swoole_version' => function_exists('swoole_version') ? swoole_version() : null,
            'extension_loaded' => [
                'swoole' => extension_loaded('swoole'),
                'ddtrace' => extension_loaded('ddtrace'),
            ],
            'class_info' => [
                'legacy_alias_exists' => class_exists('swoole_http_server', false) || class_exists('swoole_http_server'),
                'constructor_class' => 'swoole_http_server',
                'runtime_class' => get_class($this->swoole),
                'has_construct' => (new ReflectionClass('swoole_http_server'))->hasMethod('__construct'),
            ],
            'ddtrace' => [
                'cli_enabled_env' => getenv('DD_TRACE_CLI_ENABLED') !== false ? getenv('DD_TRACE_CLI_ENABLED') : null,
                'trace_enabled_env' => getenv('DD_TRACE_ENABLED') !== false ? getenv('DD_TRACE_ENABLED') : null,
                'debug_env' => getenv('DD_TRACE_DEBUG') !== false ? getenv('DD_TRACE_DEBUG') : null,
                'has_integration_check_api' => function_exists('ddtrace_config_integration_enabled'),
                'integration_swoole_enabled' => function_exists('ddtrace_config_integration_enabled')
                    ? ddtrace_config_integration_enabled('swoole')
                    : null,
                'has_root_span_api' => function_exists('DDTrace\\root_span'),
                'root_span_present_in_request_callback' => $rootSpan !== null,
                'root_span' => $rootSpan ? [
                    'name' => $rootSpan->name ?? null,
                    'service' => $rootSpan->service ?? null,
                    'resource' => $rootSpan->resource ?? null,
                    'type' => $rootSpan->type ?? null,
                    'trace_id' => $rootSpan->traceId ?? null,
                    'span_id' => $rootSpan->id ?? null,
                    'meta.http.method' => $rootSpan->meta['http.method'] ?? null,
                    'meta.http.url' => $rootSpan->meta['http.url'] ?? null,
                    'meta.component' => $rootSpan->meta['component'] ?? null,
                    'meta.span.kind' => $rootSpan->meta['span.kind'] ?? null,
                ] : null,
            ],
            'request' => [
                'method' => $request->server['request_method'] ?? null,
                'uri' => $request->server['request_uri'] ?? null,
                'remote_addr' => $request->server['remote_addr'] ?? null,
            ],
            'expected' => [
                'if_ddtrace_hooked' => [
                    'root_span_present_in_request_callback' => true,
                    'root_span.name' => 'web.request',
                    'root_span.resource' => ($request->server['request_method'] ?? 'GET') . ' ' . ($request->server['request_uri'] ?? '/diag'),
                ],
            ],
        ];
    }
}

$host = $argv[1] ?? '0.0.0.0';
$port = isset($argv[2]) ? (int) $argv[2] : 9501;
$mode = defined('SWOOLE_PROCESS') ? SWOOLE_PROCESS : 3;
$socketType = defined('SWOOLE_SOCK_TCP') ? SWOOLE_SOCK_TCP : 1;

(new LegacyAliasSwooleDemo($host, $port, $mode, $socketType))->start();
