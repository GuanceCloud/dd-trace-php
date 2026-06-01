<?php

namespace DDTrace\Integrations\ElasticSearch;

use DDTrace\Integrations\DatabaseIntegrationHelper;
use DDTrace\SpanData;
use DDTrace\Tag;

/**
 * Utility class containing business logic shared between the legacy and the sandboxed api.
 */
class ElasticSearchCommon
{
    /**
     * @param string $methodName
     * @param array|null $params
     * @return string
     */
    public static function buildResourceName($methodName, $params)
    {
        if (!is_array($params)) {
            return $methodName;
        }

        $resourceFragments = [$methodName];
        $relevantParamNames = ['index', 'type'];

        foreach ($relevantParamNames as $relevantParamName) {
            if (empty($params[$relevantParamName])) {
                continue;
            }
            $resourceFragments[] = $relevantParamName . ':' . $params[$relevantParamName];
        }

        return implode(' ', $resourceFragments);
    }

    public static function enrichSpanWithInstanceInfo(SpanData $span, $target = null, $fallbackUrl = null)
    {
        list($host, $port) = self::extractHostAndPort($fallbackUrl);
        if (!$host) {
            list($host, $port) = self::extractHostAndPort($target);
        }

        if (!$host) {
            return;
        }

        $span->meta[Tag::TARGET_HOST] = $host;
        if ($port !== null && $port !== '') {
            $span->meta[Tag::TARGET_PORT] = (string) $port;
        }
        $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
    }

    private static function extractHostAndPort($target, $depth = 0)
    {
        if ($depth > 3 || $target === null) {
            return [null, null];
        }

        if (is_string($target)) {
            return self::extractHostAndPortFromString($target);
        }

        if (is_array($target)) {
            return self::extractHostAndPortFromArray($target, $depth);
        }

        if (!is_object($target)) {
            return [null, null];
        }

        foreach (['getHost', 'getHostname'] as $method) {
            if (method_exists($target, $method)) {
                try {
                    $host = $target->$method();
                    if (is_string($host) && $host !== '') {
                        $port = self::extractPortFromObject($target);
                        return [$host, $port];
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        foreach (['getUri', 'getURI', 'getConnection', 'getTransport', 'getNode', 'getConnectionParams', 'getHosts'] as $method) {
            if (method_exists($target, $method)) {
                try {
                    list($host, $port) = self::extractHostAndPort($target->$method(), $depth + 1);
                    if ($host) {
                        return [$host, $port];
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        return self::extractHostAndPortFromArray(get_object_vars($target), $depth);
    }

    private static function extractPortFromObject($target)
    {
        foreach (['getPort'] as $method) {
            if (method_exists($target, $method)) {
                try {
                    $port = $target->$method();
                    if (is_scalar($port) && $port !== '') {
                        return $port;
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        $vars = get_object_vars($target);
        return $vars['port'] ?? null;
    }

    private static function extractHostAndPortFromArray(array $target, $depth)
    {
        foreach (['host', 'hostname'] as $key) {
            if (!empty($target[$key]) && is_string($target[$key])) {
                return [$target[$key], isset($target['port']) ? $target['port'] : null];
            }
        }

        foreach (['uri', 'url'] as $key) {
            if (!empty($target[$key])) {
                list($host, $port) = self::extractHostAndPort($target[$key], $depth + 1);
                if ($host) {
                    return [$host, $port];
                }
            }
        }

        foreach (['connection', 'transport', 'node', 'nodes', 'connectionParams', 'hosts'] as $key) {
            if (!empty($target[$key])) {
                list($host, $port) = self::extractHostAndPort($target[$key], $depth + 1);
                if ($host) {
                    return [$host, $port];
                }
            }
        }

        foreach ($target as $value) {
            list($host, $port) = self::extractHostAndPort($value, $depth + 1);
            if ($host) {
                return [$host, $port];
            }
        }

        return [null, null];
    }

    private static function extractHostAndPortFromString($target)
    {
        $parts = parse_url($target);
        if (is_array($parts) && !empty($parts['host'])) {
            return [$parts['host'], isset($parts['port']) ? $parts['port'] : null];
        }

        if (preg_match('/^([^\/:]+):(\d+)$/', $target, $matches)) {
            return [$matches[1], $matches[2]];
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/', $target)) {
            return [$target, null];
        }

        return [null, null];
    }
}
