#!/usr/bin/env bash
# Runs Composer for CI and conformance scripts, never in an image under test:
# - in the pinned Composer image after Alpine's fixed Brotli decoder
#   (brotli-safe-composer.sh, CVE-2026-111497);
# - trusting only the pinned image's checked CA file and an empty CA
#   directory (checked-ca.sh, GHSA-6q48-6c7w-9gw2);
# - as the invoking user unless the caller passes its own --user.
#
# The caller's docker run options come first and cannot replace the CA pair,
# the image or the entrypoint, which this script sets after them.
#
# Usage: run-composer.sh [docker run options...] -- COMPOSER_ARGUMENTS...
# Example: run-composer.sh --rm -v "$PWD:/app" -w /app -- install --no-dev
set -euo pipefail

here=$(dirname "$0")
options=()
while (($#)) && [[ $1 != -- ]]; do
    case "$1" in
        *COMPOSER_CAFILE* | *COMPOSER_CAPATH* | --entrypoint* | --env-file* | --volumes-from*)
            echo "run-composer.sh sets the CA pair and the entrypoint itself; refusing $1" >&2
            exit 2 ;;
        */checked-ca*)
            echo "run-composer.sh reserves /checked-ca for its checked CA pair; refusing $1" >&2
            exit 2 ;;
        *) ;;
    esac
    options+=("$1")
    shift
done
if (($# == 0)); then
    echo 'Usage: run-composer.sh [docker run options...] -- COMPOSER_ARGUMENTS...' >&2
    exit 2
fi
shift

image=$(sh "$here/brotli-safe-composer.sh")
ca=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/dw-composer-ca.XXXXXX")
errors=$(mktemp "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/dw-composer-stderr.XXXXXX")
trap 'rm -rf -- "$ca" "$errors"' EXIT
sh "$here/checked-ca.sh" "$ca"
chmod 0755 "$ca"

# A locked install only downloads pinned dists, so it is safe to repeat. Retry
# it, at most three attempts in all, only when Composer reports a transient
# download failure such as GitHub codeload's HTTP 429 rate limit. Any other
# failure, every other Composer command and every kept (named, not --rm)
# container fails on its first attempt.
transient='could not be downloaded \((HTTP/[0-9.]+ (429|5[0-9][0-9])|curl error (6|7|28|35|52|56))'
attempts=1
if [[ $1 == install ]] && [[ " ${options[*]} " == *" --rm "* ]]; then attempts=3; fi
for attempt in $(seq "$attempts"); do
    # Composer's stderr reaches the caller unchanged and is kept for the check.
    set +o errexit
    { docker run --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer "${options[@]}" \
        -e COMPOSER_CAFILE=/checked-ca/ca.pem -e COMPOSER_CAPATH=/checked-ca/no-ca-directory \
        -v "$ca:/checked-ca:ro" --entrypoint composer "$image" "$@" 2>&1 1>&3 3>&- | tee "$errors" >&2 3>&-; } 3>&1
    statuses=("${PIPESTATUS[@]}")
    set -o errexit
    status=${statuses[0]}
    ((statuses[1] == 0)) || status=${statuses[1]}
    if ((status == 0 || attempt == attempts)) || ! grep -Eq "$transient" "$errors"; then
        break
    fi
    echo "run-composer.sh: transient download failure on attempt $attempt of $attempts; retrying in $((attempt * 20))s" >&2
    sleep $((attempt * 20))
done
exit "$status"
