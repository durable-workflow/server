#!/usr/bin/env bash
# Fetches CLI 2.2.0's dw.phar once for a Shared Server fixtures run (#1103).
#
# Usage: fetch-parity-cli.sh SHA256 SIZE OUTPUT
#
# The release download URL is tried first. Only if that transfer itself fails
# is the same release asset fetched through its GitHub API URL. Either way the
# file is accepted only with the exact size and the committed SHA-256: a
# completed transfer with other bytes fails at once, with no second source.
#
# Every transfer is HTTPS-only, redirects included, with a 10 s connect and a
# 60 s per-attempt bound. curl retries with its doubling backoff inside a
# per-route window, and `timeout` caps each route (release URL 300 s, API URL
# 180 s), so the whole fetch ends within 480 s.
set -euo pipefail

want="${1:-}"
size="${2:-}"
out="${3:-}"
[[ "$want" =~ ^[0-9a-f]{64}$ && "$size" =~ ^[1-9][0-9]*$ && -n "$out" ]] || {
    echo "fetch-parity-cli.sh: usage: fetch-parity-cli.sh SHA256 SIZE OUTPUT" >&2
    exit 2
}

primary=https://github.com/durable-workflow/cli/releases/download/2.2.0/dw.phar
# Release 404473194 (tag 2.2.0), asset 614926795 "dw.phar", 626,966 bytes.
asset=https://api.github.com/repos/durable-workflow/cli/releases/assets/614926795

fetch() { # CEILING WINDOW URL [CURL OPTION...]
    rm -f "$out"
    timeout "$1" curl --fail --silent --show-error --proto '=https' --proto-redir '=https' --location \
        --connect-timeout 10 --max-time 60 --retry 8 --retry-all-errors --retry-max-time "$2" \
        --output "$out" "${@:4}" "$3"
}

mkdir -p "$(dirname "$out")"
if fetch 300 240 "$primary"; then
    echo "parity CLI transport: release download URL"
else
    status=$?
    echo "parity CLI transport: release download URL failed (exit $status); trying the release-asset API URL" >&2
    if ! fetch 180 120 "$asset" -H 'Accept: application/octet-stream'; then
        echo "fetch-parity-cli.sh: both transports failed" >&2
        exit 1
    fi
    echo "parity CLI transport: release-asset API URL"
fi

actual="$(stat -c %s "$out")"
[[ "$actual" = "$size" ]] || {
    echo "fetch-parity-cli.sh: $out is $actual bytes, expected $size" >&2
    exit 1
}
printf '%s  %s\n' "$want" "$out" | sha256sum -c
