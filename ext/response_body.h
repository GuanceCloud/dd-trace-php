#ifndef DD_RESPONSE_BODY_H
#define DD_RESPONSE_BODY_H

#include "ddtrace.h"

void ddtrace_response_body_minit(void);
void ddtrace_response_body_mshutdown(void);
void ddtrace_response_body_rinit(void);
void ddtrace_response_body_rshutdown(void);
void ddtrace_response_body_add_to_span(ddtrace_span_data *span);

#endif
