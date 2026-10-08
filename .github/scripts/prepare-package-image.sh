#!/usr/bin/env bash

set -euo pipefail

touch "${BASH_ENV}"

case "${HOST_OS}" in
    linux-gnu)
        # CentOS 7 archive repositories reject HTTP downloads. Keep the
        # historical build baseline while using the supported HTTPS endpoint.
        sed -i 's|http://vault.centos.org|https://vault.centos.org|g' /etc/yum.repos.d/*.repo
        if ! yum install -y file patchelf >/dev/null; then
            # Some legacy images receive 403 responses from the Vault CDN even
            # over HTTPS. Use the same signed CentOS 7 packages on its archive
            # mirror, and discard image-baked repository metadata before retrying.
            sed -i \
                -e 's|https\?://vault.centos.org|https://archive.kernel.org/centos-vault|g' \
                -e 's#/altarch/\(\$releasever\|7\)/#/altarch/7.9.2009/#g' \
                -e 's#centos-vault/\(centos/\)\?\(\$releasever\|7\)/#centos-vault/7.9.2009/#g' \
                /etc/yum.repos.d/*.repo
            yum clean all >/dev/null
            yum install -y file patchelf >/dev/null
        fi
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
