#!/usr/bin/env bash
# Package the tracer-only PHP 8.1 NTS Linux x86_64 customer evaluation build.
set -euo pipefail
cd "$(dirname "$0")/../.."
output_dir=${1:?Usage: package-response-body.sh /absolute/output/directory}
mkdir -p "$output_dir"
output_dir=$(realpath "$output_dir")
version=$(cat VERSION)
extension=tmp/build_extension/modules/ddtrace.so
test -f "$extension"
test "$(php -n -r 'echo PHP_MAJOR_VERSION, PHP_MINOR_VERSION;')" = 81
test "$(php -n -r 'echo (int) PHP_ZTS;')" = 0
test "$(php -n -r 'echo (int) PHP_DEBUG;')" = 0
test "$(uname -m)" = x86_64
getconf GNU_LIBC_VERSION > /dev/null
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/dd-library-php/trace/ext/20210902"
cp VERSION "$stage/dd-library-php/"
cp -a src "$stage/dd-library-php/trace/"
cp "$extension" "$stage/dd-library-php/trace/ext/20210902/ddtrace.so"
strip --strip-debug "$stage/dd-library-php/trace/ext/20210902/ddtrace.so"
cp LICENSE NOTICE "$stage/dd-library-php/"
archive="dd-library-php-${version}-x86_64-linux-gnu-20210902.tar.gz"
tar -czf "$output_dir/$archive" -C "$stage" dd-library-php
sh tooling/bin/generate-installers.sh "$version" "$output_dir"
cp docs/response-body-deployment.zh-CN.md "$output_dir/README.zh-CN.md"
cp examples/response-body/Dockerfile "$output_dir/Dockerfile"
cp examples/response-body/Dockerfile.build "$output_dir/Dockerfile.build"
cp examples/response-body/zz-guance-response-body.conf "$output_dir/"
(cd "$output_dir" && sha256sum "$archive" datadog-setup.php > SHA256SUMS)
printf 'Created %s/%s\n' "$output_dir" "$archive"
