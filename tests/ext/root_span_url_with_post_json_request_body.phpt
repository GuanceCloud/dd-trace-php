--TEST--
JSON request bodies should be stored in request_body when explicitly enabled
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_POST_DATA_RAW_ENABLED=1
DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED=
HTTPS=off
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
CONTENT_TYPE=application/json
METHOD=POST
--POST--
{"operationName":"getCartQty","query":"query getCartQty { customer { cartQty } }","variables":{"cartId":"123","password":"secret"}}
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']['request_body']);
var_dump(isset($spans[0]['meta']['http.request.post.operationName']));
?>
--EXPECT--
string(121) "{"operationName":"getCartQty","query":"query getCartQty { customer { cartQty } }","variables":{"cartId":"123","password":"secret"}}"
bool(false)
