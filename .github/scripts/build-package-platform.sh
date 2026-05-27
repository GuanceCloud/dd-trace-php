#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 4 ]]; then
    echo "Usage: $0 <architecture> <host-os> <docker-platform> <suffix>" >&2
    exit 1
fi

architecture=$1
host_os=$2
docker_platform=$3
suffix=$4

workspace=${GITHUB_WORKSPACE:-$(pwd)}
make_jobs=${MAKE_JOBS:-$(nproc)}
bootstrap_version=${BOOTSTRAP_PHP_VERSION:-8.1}

all_versions=(7.0 7.1 7.2 7.3 7.4 8.0 8.1 8.2 8.3 8.4 8.5)
profiler_versions=(7.1 7.2 7.3 7.4 8.0 8.1 8.2 8.3 8.4 8.5)

declare -A abi_by_version=(
    [7.0]=20151012
    [7.1]=20160303
    [7.2]=20170718
    [7.3]=20180731
    [7.4]=20190902
    [8.0]=20200930
    [8.1]=20210902
    [8.2]=20220829
    [8.3]=20230831
    [8.4]=20240924
    [8.5]=20250925
)

case "$host_os" in
    linux-gnu)
        image_template='datadog/dd-trace-ci:php-%s_centos-7'
        triplet="${architecture}-unknown-linux-gnu"
        ;;
    linux-musl)
        image_template='datadog/dd-trace-ci:php-compile-extension-alpine-%s'
        triplet="${architecture}-alpine-linux-musl"
        ;;
    *)
        echo "Unsupported host os: $host_os" >&2
        exit 1
        ;;
esac

rm -rf \
    "$workspace"/extensions_* \
    "$workspace"/standalone_* \
    "$workspace"/appsec_* \
    "$workspace"/datadog-profiling \
    "$workspace"/libddtrace_php_* \
    "$workspace"/ddtrace_*.ldflags \
    "$workspace"/dd_library_loader-*.so

run_in_image() {
    local version=$1
    local command=$2
    local image

    image=$(printf "$image_template" "$version")

    local setup_command

    case "$host_os" in
        linux-gnu)
            setup_command='yum install -y file patchelf >/dev/null'
            ;;
        linux-musl)
            setup_command='apk add --no-cache file patchelf >/dev/null'
            ;;
    esac

    docker run --rm \
        --platform "$docker_platform" \
        -e PHP_VERSION="$version" \
        -e HOST_OS="$host_os" \
        -e MAKE_JOBS="$make_jobs" \
        -e BASH_ENV=/tmp/ddtrace-gha-bashenv \
        -e CARGO_TARGET_DIR=/work/tmp/cargo-target \
        -e CI_PROJECT_DIR=/work \
        -v "$workspace:/work" \
        -w /work \
        "$image" \
        bash -lc "set -euo pipefail; touch \"\$BASH_ENV\"; git config --global --add safe.directory /work; $setup_command; $command"
}

echo "Building shared sidecar and loader artifacts for ${architecture}/${host_os}"
run_in_image "$bootstrap_version" ".gitlab/build-sidecar.sh '$suffix'"
run_in_image "$bootstrap_version" ".gitlab/build-loader.sh"

for version in "${all_versions[@]}"; do
    echo "Building tracing/appsec for PHP ${version} on ${architecture}/${host_os}"
    run_in_image "$version" ".gitlab/build-tracing.sh '$suffix'"
    if [[ "$host_os" == "linux-musl" ]]; then
        run_in_image "$version" "apk add --no-cache cmake gcc g++ git python3 autoconf coreutils; .gitlab/build-appsec.sh '$suffix'"
    else
        run_in_image "$version" ".gitlab/build-appsec.sh '$suffix'"
    fi
done

for version in "${profiler_versions[@]}"; do
    abi=${abi_by_version[$version]}
    echo "Building profiler for PHP ${version} (${abi}) on ${architecture}/${host_os}"
    run_in_image "$version" ".gitlab/build-profiler.sh 'datadog-profiling/${triplet}/lib/php/${abi}' nts"
    run_in_image "$version" ".gitlab/build-profiler.sh 'datadog-profiling/${triplet}/lib/php/${abi}' zts"
done

echo "Linking tracing extensions for ${architecture}/${host_os}"
run_in_image "$bootstrap_version" ".gitlab/link-tracing-extension.sh '$suffix'"

echo "Build completed for ${architecture}/${host_os}"
