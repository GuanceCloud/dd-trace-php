--TEST--
JSON request bodies should keep allowed post fields when request_body capture is enabled
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_POST_DATA_RAW_ENABLED=1
DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED=operationName,query,variables.cartId
HTTPS=off
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
CONTENT_TYPE=application/json; charset=utf-8
METHOD=POST
--POST--
{"operationName":"getCartQty","query":"query getCartQty { customer { cartQty } }","variables":{"cartId":"123","password":"secret"}}
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']['request_body']);
var_dump($spans[0]['meta']['http.request.post.operationName']);
var_dump($spans[0]['meta']['http.request.post.query']);
var_dump($spans[0]['meta']['http.request.post.variables.cartId']);
var_dump($spans[0]['meta']['http.request.post.variables.password']);
?>
--EXPECT--
string(121) "{"operationName":"getCartQty","query":"query getCartQty { customer { cartQty } }","variables":{"cartId":"123","password":"secret"}}"
string(10) "getCartQty"
string(39) "query getCartQty { customer { cartQty } }"
string(3) "123"
string(10) "<redacted>"
