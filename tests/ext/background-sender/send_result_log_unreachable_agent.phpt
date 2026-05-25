--TEST--
Background sender emits a result log when trace delivery fails
--SKIPIF--
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There is no background sender on Windows'); ?>
<?php if (getenv('SKIP_ASAN') || getenv('USE_ZEND_ALLOC') === '0') die("skip: can intentionally leak memory depending on timing"); ?>
--ENV--
DD_TRACE_CLI_ENABLED=1
DD_TRACE_DEBUG=1
DD_AGENT_HOST=192.0.2.1
DD_TRACE_AGENT_PORT=18126
DD_TRACE_SIDECAR_TRACE_SENDER=0
DD_TRACE_SHUTDOWN_TIMEOUT=2000
DD_TRACE_AGENT_TIMEOUT=500
DD_TRACE_AGENT_CONNECT_TIMEOUT=500
DD_TRACE_AGENT_RETRIES=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_CRASHTRACKING_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
echo "Done\n";
?>
--EXPECTREGEX--
/Done
.*Flushing trace of size 1 to send-queue for http:\/\/192\.0\.2\.1:18126
.*\[bgs\] Failure while sending 1 \(size=[^)]+\) traces\. Total: 1, Received: 1, Sent: 0, Failed: 1\. curl error after 1 attempt\(s\): .*/s
