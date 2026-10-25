#!/bin/sh
# Audit an immutable source lock in a disposable, decoder-patched Composer
# image. Source stays read-only; the audit receives no publishing credentials.
set -eu

here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
source=$(realpath -- "${1:?Usage: audit-immutable-release.sh SOURCE_DIRECTORY}")
image=composer:2.10.3@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee
trust=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/release-audit.XXXXXXXX")
trap 'rm -rf "$trust"' EXIT HUP INT TERM

sh "$here/checked-ca.sh" "$trust"

docker run --rm --user 0:0 --entrypoint /bin/sh \
    --mount "type=bind,src=$source,dst=/app,readonly" \
    --mount "type=bind,src=$here,dst=/release-tools,readonly" \
    --mount "type=bind,src=$trust,dst=/release-trust,readonly" \
    -e COMPOSER_CAFILE=/release-trust/ca.pem \
    -e COMPOSER_CAPATH=/release-trust/no-ca-directory \
    -e SSL_CERT_FILE=/release-trust/ca.pem \
    -e SSL_CERT_DIR=/release-trust/no-ca-directory \
    -w /app "$image" /release-tools/audit-immutable-release-container.sh "$(id -u):$(id -g)"
