#!/usr/bin/env bash

set -euo pipefail

touch "${BASH_ENV}"

case "${HOST_OS}" in
    linux-gnu)
        yum install -y file patchelf >/dev/null
        ;;
    linux-musl)
        apk add --no-cache file patchelf >/dev/null

        if ! command -v switch-php >/dev/null 2>&1 && ls -d /usr/local/php-* >/dev/null 2>&1; then
            cat >> "${BASH_ENV}" <<'EOS'
switch-php() {
    local requested=$1
    local suffix=
    local base=$requested

    if [ "${requested%-zts}" != "${requested}" ]; then
        suffix=-zts
        base=${requested%-zts}
    fi

    local php_dir
    if [ -n "${suffix}" ]; then
        php_dir=$(find /usr/local -maxdepth 1 -type d -name "php-${base}.*${suffix}" | sort | tail -n 1)
    else
        php_dir=$(find /usr/local -maxdepth 1 -type d -name "php-${base}.*" ! -name "*-zts" | sort | tail -n 1)
    fi

    if [ -z "${php_dir}" ]; then
        echo "switch-php: PHP ${requested} not found" >&2
        return 1
    fi

    export PATH="${php_dir}/bin:${PATH}"
}

if [ -n "${PHP_VERSION:-}" ]; then
    switch-php "${PHP_VERSION}"
fi
EOS
        fi
        ;;
    *)
        echo "Unsupported HOST_OS: ${HOST_OS}" >&2
        exit 1
        ;;
esac
