--TEST--
AMQP integration traces Magento PublisherInterface enqueue path
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--INI--
datadog.trace.sources_path={PWD}/../../../../../../src
--FILE--
<?php

namespace {
    include __DIR__ . '/../../sandbox/dd_dumper.inc';
    \DDTrace\trace_method(
        'Tests\AMQP\TestScenario',
        'run',
        static function (\DDTrace\SpanData $span) {
            $span->name = 'test.scenario';
            $span->resource = 'Tests\\AMQP\\TestScenario::run';
        }
    );
}

namespace Tests\AMQP {
    final class Envelope
    {
        private $body;

        public function __construct(string $body)
        {
            $this->body = $body;
        }

        public function getBody(): string
        {
            return $this->body;
        }
    }

    final class TestScenario
    {
        public static function run(): void
        {
            $publisher = new \Magento\Framework\MessageQueue\Publisher(new \Magento\Framework\Amqp\Exchange());
            $publisher->publish('order.created', 'hello world');
        }
    }
}

namespace Magento\Framework\Amqp {
    final class Exchange
    {
        public function enqueue($topic, $envelope)
        {
            return [$topic, $envelope];
        }
    }
}

namespace Magento\Framework\MessageQueue {
    final class Publisher
    {
        private $exchange;

        public function __construct(\Magento\Framework\Amqp\Exchange $exchange)
        {
            $this->exchange = $exchange;
        }

        public function publish($topicName, $data)
        {
            $this->exchange->enqueue($topicName, new \Tests\AMQP\Envelope($data));
        }
    }
}

namespace {
    \Tests\AMQP\TestScenario::run();
    $spans = dd_clean_spans();
    $enqueueSpans = array_values(array_filter($spans, static function (array $span): bool {
        return ($span['name'] ?? null) === 'amqp.enqueue';
    }));
    var_dump(count($enqueueSpans));
    var_dump($enqueueSpans[0]['name']);
    var_dump($enqueueSpans[0]['resource']);
    var_dump($enqueueSpans[0]['service']);
    var_dump($enqueueSpans[0]['type']);
    var_dump($enqueueSpans[0]['meta']['component']);
    var_dump($enqueueSpans[0]['meta']['messaging.system']);
    var_dump($enqueueSpans[0]['meta']['messaging.operation']);
    var_dump($enqueueSpans[0]['meta']['messaging.rabbitmq.routing_key']);
    var_dump($enqueueSpans[0]['meta']['messaging.message_payload_size_bytes']);
}
?>
--EXPECTF--
int(1)
string(12) "amqp.enqueue"
string(21) "enqueue order.created"
string(4) "amqp"
string(5) "queue"
string(4) "amqp"
string(8) "rabbitmq"
string(4) "send"
string(13) "order.created"
string(2) "11"
