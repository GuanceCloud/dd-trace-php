#include <php.h>
#include <SAPI.h>
#include <ctype.h>
#include <string.h>

#include "response_body.h"
#include "configuration.h"
#include "span.h"
#include <hook/hook.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

/* Tee the SAPI writer instead of installing another output buffer. This leaves
 * flush, nested buffers and discarded output under the application's control. */
static size_t (*dd_response_body_original_write)(const char *, size_t);
static int (*dd_response_body_original_send_headers)(sapi_headers_struct *);
ZEND_TLS bool dd_response_body_sending_headers;

static int dd_response_body_send_headers(sapi_headers_struct *headers) {
    dd_response_body_sending_headers = true;
    int result = dd_response_body_original_send_headers(headers);
    dd_response_body_sending_headers = false;
    return result;
}

static void dd_response_body_finish_request(zend_ulong invocation, zend_execute_data *frame, zval *retval,
                                            void *auxiliary, void *dynamic) {
    (void)invocation;
    (void)frame;
    (void)auxiliary;
    (void)dynamic;
    if (retval && Z_TYPE_P(retval) == IS_TRUE) {
        /* FPM reports successful writes even after its FastCGI stream closes. */
        DDTRACE_G(response_body_capture) = false;
    }
}

static bool dd_response_body_media_type(const char *value, size_t length, const char *type) {
    size_t type_length = strlen(type);
    return length >= type_length && !strncasecmp(value, type, type_length)
        && (length == type_length || value[type_length] == ';' || isspace((unsigned char)value[type_length]));
}

static bool dd_response_body_headers_supported(void) {
    const char *content_type = SG(sapi_headers).mimetype;
    size_t content_type_length = content_type ? strlen(content_type) : 0;
    sapi_header_struct *header;
    zend_llist_position position;
    for (header = zend_llist_get_first_ex(&SG(sapi_headers).headers, &position); header;
         header = zend_llist_get_next_ex(&SG(sapi_headers).headers, &position)) {
        const char *value = memchr(header->header, ':', header->header_len);
        if (!value) {
            continue;
        }
        size_t name_length = value - header->header;
        size_t value_length = header->header_len - name_length - 1;
        ++value;
        while (value_length && isspace((unsigned char)*value)) {
            ++value;
            --value_length;
        }
        if (name_length == sizeof("Content-Type") - 1
            && !strncasecmp(header->header, "Content-Type", name_length)) {
            content_type = value;
            content_type_length = value_length;
        } else if (name_length == sizeof("Content-Encoding") - 1
                   && !strncasecmp(header->header, "Content-Encoding", name_length)) {
            while (value_length && isspace((unsigned char)value[value_length - 1])) {
                --value_length;
            }
            /* At this layer PHP-side compressed output is already binary.
             * Do not store compressed bytes as text. Proxy compression is fine. */
            if (value_length && !(value_length == 8 && !strncasecmp(value, "identity", 8))) {
                return false;
            }
        }
    }
    return content_type && (dd_response_body_media_type(content_type, content_type_length, "application/json")
                           || dd_response_body_media_type(content_type, content_type_length, "text/plain"));
}

static bool dd_response_body_url_excluded(void) {
    const char *uri = SG(request_info).request_uri;
    /* FPM may rewrite request_info.request_uri to the script path. Use the
     * original URI from _SERVER, as the HTTP span serializer does. */
    zval *server = &PG(http_globals)[TRACK_VARS_SERVER];
    if (Z_TYPE_P(server) == IS_ARRAY || zend_is_auto_global_str(ZEND_STRL("_SERVER"))) {
        zval *original_uri = zend_hash_str_find(Z_ARRVAL_P(server), ZEND_STRL("REQUEST_URI"));
        if (original_uri && Z_TYPE_P(original_uri) == IS_STRING) {
            uri = Z_STRVAL_P(original_uri);
        }
    }
    if (!uri) {
        return false;
    }
    size_t path_length = strcspn(uri, "?");
    zend_string *pattern;
    ZEND_HASH_FOREACH_STR_KEY(get_DD_TRACE_RESPONSE_BODY_BLACKLIST_URLS(), pattern) {
        if (!pattern || !ZSTR_LEN(pattern)) {
            continue;
        }
        size_t length = ZSTR_LEN(pattern);
        bool prefix = ZSTR_VAL(pattern)[length - 1] == '*';
        if (prefix) {
            --length;
        }
        if ((prefix ? path_length >= length : path_length == length)
            && !memcmp(uri, ZSTR_VAL(pattern), length)) {
            return true;
        }
    } ZEND_HASH_FOREACH_END();
    return false;
}

static size_t dd_response_body_write(const char *data, size_t length) {
    size_t written = dd_response_body_original_write(data, length);
    if (dd_response_body_sending_headers || !DDTRACE_G(response_body_capture) || !written || !DDTRACE_G(request_initialized)
        || !get_DD_TRACE_ENABLED() || !get_DD_TRACE_RESPONSE_BODY_ENABLED()) {
        return written;
    }
    if (!DDTRACE_G(response_body_headers_checked)) {
        DDTRACE_G(response_body_headers_checked) = true;
        if (!dd_response_body_headers_supported() || SG(request_info).headers_only) {
            DDTRACE_G(response_body_capture) = false;
            return written;
        }
    }
    size_t available = DDTRACE_G(response_body_limit) - DDTRACE_G(response_body_length);
    size_t count = MIN(MIN(written, length), available);
    if (count) {
        if (!DDTRACE_G(response_body_buffer)) {
            DDTRACE_G(response_body_buffer) = emalloc(DDTRACE_G(response_body_limit));
        }
        memcpy(DDTRACE_G(response_body_buffer) + DDTRACE_G(response_body_length), data, count);
        DDTRACE_G(response_body_length) += count;
    }
    if (MIN(written, length) > available) {
        DDTRACE_G(response_body_truncated) = true;
    }
    return written;
}

void ddtrace_response_body_minit(void) {
    dd_response_body_original_send_headers = sapi_module.send_headers;
    if (dd_response_body_original_send_headers) {
        sapi_module.send_headers = dd_response_body_send_headers;
    }
    dd_response_body_original_write = sapi_module.ub_write;
    if (dd_response_body_original_write) {
        sapi_module.ub_write = dd_response_body_write;
    }
}

void ddtrace_response_body_mshutdown(void) {
    if (sapi_module.send_headers == dd_response_body_send_headers) {
        sapi_module.send_headers = dd_response_body_original_send_headers;
    }
    if (sapi_module.ub_write == dd_response_body_write) {
        sapi_module.ub_write = dd_response_body_original_write;
    }
}

void ddtrace_response_body_rinit(void) {
    ddtrace_response_body_rshutdown();
    DDTRACE_G(response_body_headers_checked) = false;
    DDTRACE_G(response_body_truncated) = false;
    DDTRACE_G(response_body_buffer) = NULL;
    DDTRACE_G(response_body_length) = 0;
    DDTRACE_G(response_body_root_id) = 0;
    /* Worker runtimes have a separate user-request lifecycle. Enable only
     * SAPIs whose module request lifecycle matches a single HTTP request. */
    if (strcmp(sapi_module.name, "fpm-fcgi") && strcmp(sapi_module.name, "cgi-fcgi")
        && strcmp(sapi_module.name, "apache2handler") && strcmp(sapi_module.name, "cli-server")) {
        return;
    }
    zend_long limit = get_DD_TRACE_RESPONSE_BODY_MAX_SIZE();
    if (!get_DD_TRACE_RESPONSE_BODY_ENABLED() || limit <= 0 || dd_response_body_url_excluded()
        || !DDTRACE_G(active_stack) || !DDTRACE_G(active_stack)->root_span) {
        return;
    }
    /* Keep even accidentally oversized configuration bounded to 1 MiB. */
    DDTRACE_G(response_body_limit) = (size_t)MIN(limit, 1048576);
    DDTRACE_G(response_body_root_id) = DDTRACE_G(active_stack)->root_span->span_id;
    DDTRACE_G(response_body_capture) = true;
    if (!strcmp(sapi_module.name, "fpm-fcgi")) {
        zai_hook_install((zai_str)ZAI_STR_EMPTY, (zai_str)ZAI_STRL("fastcgi_finish_request"),
                         NULL, dd_response_body_finish_request, ZAI_HOOK_AUX_UNUSED, 0);
    }
}

void ddtrace_response_body_add_to_span(ddtrace_span_data *span) {
    if (span->span_id != DDTRACE_G(response_body_root_id) || !DDTRACE_G(response_body_length)
        || !get_DD_TRACE_RESPONSE_BODY_ENABLED()) {
        return;
    }
    zend_array *meta = ddtrace_property_array(&span->property_meta);
    zval body;
    ZVAL_STRINGL(&body, DDTRACE_G(response_body_buffer), DDTRACE_G(response_body_length));
    zend_hash_str_update(meta, ZEND_STRL("response_body"), &body);
    if (DDTRACE_G(response_body_truncated)) {
        zval truncated;
        ZVAL_STRING(&truncated, "true");
        zend_hash_str_update(meta, ZEND_STRL("response_body_truncated"), &truncated);
    }
}

void ddtrace_response_body_rshutdown(void) {
    dd_response_body_sending_headers = false;
    DDTRACE_G(response_body_capture) = false;
    DDTRACE_G(response_body_root_id) = 0;
    if (DDTRACE_G(response_body_buffer)) {
        efree(DDTRACE_G(response_body_buffer));
        DDTRACE_G(response_body_buffer) = NULL;
    }
    DDTRACE_G(response_body_length) = 0;
}
