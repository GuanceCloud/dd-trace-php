# Replace with the customer's existing PHP 8.1 NTS x86_64 Debian image.
# Confirm the extension's libc dependencies match that image before deployment.
ARG PHP_BASE_IMAGE=php:8.1.34-fpm@sha256:a3118db1911fdd3b3ac66605122ddc859286688ced86fc860fec6d19cc2d6c55
FROM ${PHP_BASE_IMAGE}
COPY dd-library-php-1.20.7-gtrace-rc.1-x86_64-linux-gnu-20210902.tar.gz datadog-setup.php SHA256SUMS /tmp/guance-ddtrace/
RUN cd /tmp/guance-ddtrace \
    && sha256sum -c SHA256SUMS \
    && php datadog-setup.php \
       --file=/tmp/guance-ddtrace/dd-library-php-1.20.7-gtrace-rc.1-x86_64-linux-gnu-20210902.tar.gz \
       --php-bin=/usr/local/bin/php --php-bin=/usr/local/sbin/php-fpm \
    && php --ri ddtrace \
    && rm -rf /tmp/guance-ddtrace
COPY zz-guance-response-body.conf /usr/local/etc/php-fpm.d/
ENV DD_TRACE_RESPONSE_BODY_ENABLED=true \
    DD_TRACE_RESPONSE_BODY_MAX_SIZE=8192
