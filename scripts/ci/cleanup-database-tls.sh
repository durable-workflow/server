#!/usr/bin/env bash
set -euo pipefail

: "${TLS_TEST_ROOT:?Set the owned TLS fixture parent.}"
: "${RUNNER_TEMP:?Set the runner temporary directory.}"
tls_directory="${TLS_TEST_ROOT#"$RUNNER_TEMP"/}"
if [[ "$TLS_TEST_ROOT" != "$RUNNER_TEMP"/dw-server-tls-* || ! "$tls_directory" =~ ^dw-server-tls-[a-zA-Z0-9_-]+$ ]]; then
    echo 'Refusing to remove a TLS fixture directory outside its runner scope.' >&2
    exit 1
fi
rm -rf -- "$TLS_TEST_ROOT"
