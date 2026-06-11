--TEST--
PHPRedis deferred loading on connect preserves Redis target host tags
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php

class Redis
{
    private $host;

    public function connect($host, $port = 6379)
    {
        $this->host = $host;
        return true;
    }

    public function set($key, $value)
    {
        return true;
    }

    public function getHost()
    {
        return $this->host;
    }
}

$redis = new Redis();
$redis->connect('172.18.0.4', 6379);
$redis->set('k1', 'v1');

$getHostSpans = 0;
foreach (dd_trace_serialize_closed_spans() as $span) {
    if (($span['name'] ?? null) === 'Redis.getHost') {
        $getHostSpans++;
    }

    if (($span['name'] ?? null) !== 'Redis.set') {
        continue;
    }

    $meta = $span['meta'] ?? [];
    echo 'out.host=' . ($meta['out.host'] ?? 'missing') . PHP_EOL;
    echo 'peer_host=' . ($meta['peer_host'] ?? 'missing') . PHP_EOL;
}

echo 'getHost.spans=' . $getHostSpans . PHP_EOL;

?>
--EXPECT--
out.host=tcp://172.18.0.4
peer_host=172.18.0.4
getHost.spans=0
