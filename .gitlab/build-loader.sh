#!/usr/bin/env bash
set -e -o pipefail

MAKE_JOBS=${MAKE_JOBS:-$(nproc)}

shopt -s expand_aliases

if [[ "${HOST_OS}" == "linux-musl" ]]; then
  php_pkg_suffix=$(printf '%s' "${PHP_VERSION:-8.1}" | tr -d '.')
  php_pkg_base="php${php_pkg_suffix}"
  apk add --no-cache \
    autoconf \
    coreutils \
    g++ \
    gcc \
    make \
    "${php_pkg_base}" \
    "${php_pkg_base}-dev"

  for tool in php phpize php-config; do
    versioned="/usr/bin/${tool}${php_pkg_suffix}"
    if [[ -x "${versioned}" ]]; then
      ln -sf "${versioned}" "/usr/bin/${tool}"
    fi
  done
fi

echo 'export PHP_API=$(php -i | grep "PHP Extension => " | sed "s/PHP Extension => //g")' >> "$BASH_ENV"
source "${BASH_ENV}"

cd loader
phpize
./configure
make clean
make -j "${MAKE_JOBS}" all ECHO_ARG="-e" CFLAGS="-std=gnu11 -O2 -g -Wall -Wextra -Werror -DPHP_DD_LIBRARY_LOADER_VERSION='\"$(cat ../VERSION)\"'"
cp modules/dd_library_loader.so "../dd_library_loader-$(uname -m)-${HOST_OS}.so"
