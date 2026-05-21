<?php

namespace DDTrace\Tests\Integrations\Swoole;

use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class LegacyAliasCommonScenariosTest extends CommonScenariosTest
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../Frameworks/Swoole/legacy_alias_index.php';
    }

    public function testLegacyAliasConstructorResolvesToCanonicalServerClass()
    {
        $response = null;
        $traces = $this->tracesFromWebRequest(function () use (&$response) {
            $response = $this->call(GetSpec::create('request', '/server_class'));
        });

        $this->assertNotFalse($response);
        $headerEnd = strpos($response, "\r\n\r\n");
        $payload = json_decode($headerEnd === false ? $response : substr($response, $headerEnd + 4), true);
        $this->assertIsArray($payload);
        $this->assertSame('Swoole\Http\Server', $payload['runtime_class'] ?? null);
        $this->assertContains($payload['constructor_class'] ?? null, ['swoole_http_server', 'Swoole\Http\Server']);
        $this->assertSame('GET /server_class', $traces[0][0]['resource']);
    }
}
