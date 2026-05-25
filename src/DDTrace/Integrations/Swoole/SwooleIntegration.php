<?php

namespace DDTrace\Integrations\Swoole;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Log\LoggingTrait;
use DDTrace\SpanStack;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use function DDTrace\consume_distributed_tracing_headers;
use function DDTrace\extract_ip_from_headers;
use function DDTrace\Internal\handle_fork;

class SwooleIntegration extends Integration
{
    use LoggingTrait;

    const NAME = 'swoole';

    /**
     * {@inheritdoc}
     */
    public static function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return false;
    }

    private static function inferScheme(Request $request): string
    {
        $server = $request->server ?? [];
        $headers = $request->header ?? [];

        $https = $server['https'] ?? null;
        if ($https !== null && \in_array(strtolower((string) $https), ['1', 'on'], true)) {
            return 'https://';
        }

        $forwardedProto = $headers['x-forwarded-proto'] ?? null;
        if ($forwardedProto !== null && strtolower((string) $forwardedProto) === 'https') {
            return 'https://';
        }

        if (($server['server_port'] ?? null) == 443) {
            return 'https://';
        }

        return 'http://';
    }

    public static function instrumentRequestStart(callable $callback, Server $server)
    {
        \DDTrace\install_hook(
            $callback,
            static function (HookData $hook) use ($server) {
                /** @var Request $request */
                $request = $hook->args[0];
                self::logDebug(
                    'Swoole request callback entered for '
                    . (($request->server['request_method'] ?? 'UNKNOWN') . ' ' . ($request->server['request_uri'] ?? '/'))
                );

                $rootSpan = $hook->span(new SpanStack());
                $rootSpan->name = "web.request";
                $rootSpan->service = \ddtrace_config_app_name('swoole');
                $rootSpan->type = Type::WEB_SERVLET;
                $rootSpan->meta[Tag::COMPONENT] = self::NAME;
                $rootSpan->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_SERVER;
                self::addTraceAnalyticsIfEnabled($rootSpan);

                $headers = [];
                $allowedHeaders = \dd_trace_env_config('DD_TRACE_HEADER_TAGS');
                foreach ($request->header as $name => $value) {
                    $headers[strtolower($name)] = $value;
                    $normalizedHeader = preg_replace("([^a-z0-9-])", "_", strtolower($name));
                    if (\array_key_exists($normalizedHeader, $allowedHeaders)) {
                        $rootSpan->meta["http.request.headers.$normalizedHeader"] = $value;
                    }
                }
                consume_distributed_tracing_headers(static function ($key) use ($headers) {
                    return $headers[$key] ?? null;
                });

                if (\dd_trace_env_config("DD_TRACE_CLIENT_IP_ENABLED")) {
                    $res = extract_ip_from_headers($headers + ['REMOTE_ADDR' => $request->server['remote_addr']]);
                    $rootSpan->meta += $res;
                }

                if (isset($headers["user-agent"])) {
                    $rootSpan->meta["http.useragent"] = $headers["user-agent"];
                }

                $rawContent = $request->rawContent();
                if ($rawContent) {
                    // The raw content will always be populated if the request is a POST request, independent of the
                    // Content-Type header.
                    // However, it may not be json-decodable
                    $postFields = json_decode($rawContent, true);
                    if (is_null($postFields)) {
                        // Fallback to the post fields, which is an array
                        // This array is not always populated, depending on the Content-Type header
                        $postFields = $request->post;
                    }
                }
                if (!empty($postFields)) {
                    $postFields = Normalizer::sanitizePostFields($postFields);
                    foreach ($postFields as $key => $value) {
                        $rootSpan->meta["http.request.post.$key"] = $value;
                    }
                }

                $normalizedPath = Normalizer::uriNormalizeincomingPath(
                    $request->server['request_uri']
                    ?? $request->server['path_info']
                    ?? '/'
                );
                $rootSpan->resource = $request->server['request_method'] . ' ' . $normalizedPath;
                $rootSpan->meta[Tag::HTTP_METHOD] = $request->server['request_method'];

                $host = $headers['host'] ?? ($request->server['remote_addr'] . ':' . $request->server['server_port']);
                $path = $request->server['request_uri'] ?? $request->server['path_info'] ?? '';
                $query = isset($request->server['query_string']) ? '?' . $request->server['query_string'] : '';
                $scheme = self::inferScheme($request);
                $url = $scheme . $host . $path . $query;
                $rootSpan->meta[Tag::HTTP_URL] = Normalizer::urlSanitize($url);

                self::logDebug("Created Swoole root span {$rootSpan->name} with resource {$rootSpan->resource}");
                unset($rootSpan->meta['closure.declaration']);
            }
        );
    }

    public static function instrumentWorkerStart(callable $callback, Server $server)
    {
        \DDTrace\install_hook(
            $callback,
            static function (HookData $hook) use ($server) {
                self::logDebug('Swoole workerstart callback entered; invoking handle_fork()');
                handle_fork();
            }
        );
    }

    public static function init(): int
    {
        if (!function_exists('swoole_version')) {
            self::logDebug('Swoole integration not loaded because swoole_version() is unavailable');
            return Integration::NOT_LOADED;
        }

        $version = swoole_version();
        self::logDebug("Initializing Swoole integration for ext-swoole {$version}");

        if (version_compare($version, '4.8.9', '<')) {
            self::logDebug("Swoole integration not loaded because ext-swoole {$version} is below 4.8.9");
            return Integration::NOT_LOADED;
        }

        ini_set("datadog.trace.auto_flush_enabled", 1);
        ini_set("datadog.trace.generate_root_span", 0);
        self::logDebug('Swoole integration enabled auto_flush and disabled generated root span');

        self::logDebug('Installing Swoole observer hook on Swoole\\Http\\Server::__construct');
        \DDTrace\hook_method(
            'Swoole\Http\Server',
            '__construct',
            null,
            static function (HookData $hook) {
                $server = $hook->instance;
                self::logDebug('Swoole server constructed; installing noop workerstart listener for fork handling');
                $server->on('workerstart', static function () { });
            }
        );

        self::logDebug('Installing Swoole observer hook on Swoole\\Http\\Server::on');
        \DDTrace\hook_method(
            'Swoole\Http\Server',
            'on',
            null,
            static function (HookData $hook) {
                $server = $hook->instance;
                $args = $hook->args;
                $retval = $hook->returned;
                if ($retval === false) {
                    self::logDebug('Swoole server->on() returned false; callback was not registered');
                    return; // Callback wasn't set
                }

                list($eventName, $callback) = $args;

                $eventName = strtolower($eventName);
                self::logDebug("Swoole server registered event handler for {$eventName}");
                switch ($eventName) {
                    case 'request':
                        self::logDebug('Installing Swoole request callback instrumentation');
                        self::instrumentRequestStart($callback, $server);
                        break;
                    case 'workerstart':
                        self::logDebug('Installing Swoole workerstart callback instrumentation');
                        self::instrumentWorkerStart($callback, $server);
                        break;
                    default:
                        self::logDebug("Ignoring unsupported Swoole event {$eventName} for tracing");
                        break;
                }

            }
        );

        self::logDebug('Installing Swoole observer hook on Swoole\\Http\\Response::end');
        \DDTrace\hook_method(
            'Swoole\Http\Response',
            'end',
            static function (HookData $hook) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan === null) {
                    self::logDebug('Swoole response->end() observed without an active root span');
                    return;
                }

                // Note: The response's body can be retrieved here, from the args

                if (!$rootSpan->exception
                    && ((int)$rootSpan->meta[Tag::HTTP_STATUS_CODE]) >= 500
                    && $ex = \DDTrace\find_active_exception()
                ) {
                    $rootSpan->exception = $ex;
                    self::logDebug('Attached active exception to Swoole root span on response end');
                }
            },
            null
        );

        self::logDebug('Installing Swoole observer hook on Swoole\\Http\\Response::header');
        \DDTrace\hook_method(
            'Swoole\Http\Response',
            'header',
            static function (HookData $hook) {
                $rootSpan = \DDTrace\root_span();
                $args = $hook->args;
                if ($rootSpan === null || \count($args) < 2) {
                    return;
                }

                /** @var string[] $args */
                list($key, $value) = $args;

                $allowedHeaders = \dd_trace_env_config("DD_TRACE_HEADER_TAGS");
                $normalizedHeader = preg_replace("([^a-z0-9-])", "_", strtolower($key));
                if (\array_key_exists($normalizedHeader, $allowedHeaders)) {
                    $rootSpan->meta["http.response.headers.$normalizedHeader"] = $value;
                    self::logDebug("Captured Swoole response header {$normalizedHeader} on root span");
                }
            },
            null
        );

        self::logDebug('Installing Swoole observer hook on Swoole\\Http\\Response::status');
        \DDTrace\hook_method(
            'Swoole\Http\Response',
            'status',
            static function (HookData $hook) {
                $rootSpan = \DDTrace\root_span();
                $args = $hook->args;
                if ($rootSpan && \count($args) > 0) {
                    $rootSpan->meta[Tag::HTTP_STATUS_CODE] = $args[0];
                    self::logDebug('Captured Swoole response status ' . $args[0]);
                }
            },
            null
        );

        self::logDebug('Swoole integration hooks installed successfully');
        return Integration::LOADED;
    }
}
