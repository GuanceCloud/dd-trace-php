# Replace with the customer's existing PHP-FPM image.
# Select the release archive matching its architecture and libc.
ARG PHP_BASE_IMAGE=php:8.1.34-fpm@sha256:a3118db1911fdd3b3ac66605122ddc859286688ced86fc860fec6d19cc2d6c55
FROM ${PHP_BASE_IMAGE}
ARG DDTRACE_ARCHIVE=dd-library-php-1.20.7-gtrace-x86_64-linux-gnu.tar.gz
COPY ${DDTRACE_ARCHIVE} datadog-setup.php SHA256SUMS /tmp/guance-ddtrace/
RUN cd /tmp/guance-ddtrace \
    && sha256sum -c SHA256SUMS \
    && php datadog-setup.php \
       --file=/tmp/guance-ddtrace/${DDTRACE_ARCHIVE} \
       --php-bin=/usr/local/bin/php --php-bin=/usr/local/sbin/php-fpm \
    && php --ri ddtrace \
    && rm -rf /tmp/guance-ddtrace
COPY zz-guance-response-body.conf /usr/local/etc/php-fpm.d/
ENV DD_TRACE_RESPONSE_BODY_ENABLED=true \
    DD_TRACE_RESPONSE_BODY_MAX_SIZE=8192
