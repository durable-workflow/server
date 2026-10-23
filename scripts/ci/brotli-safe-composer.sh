#!/bin/sh
# Prints a local image for running Composer with a fixed Brotli decoder
# (CVE-2026-111497). It is the pinned Composer image after Alpine's signed
# brotli-libs 1.2.1-r0 or later: the image's libcurl decodes Brotli responses
# with that library, and the pinned image ships 1.2.0-r1. The image is built
# once per Docker host and checked again on every use, so a stale or
# replaced local tag is refused rather than trusted.
#
# Use it through run-composer.sh, which also sets the checked CA pair
# (GHSA-6q48-6c7w-9gw2).
#
# Usage: image=$(sh scripts/ci/brotli-safe-composer.sh)
#        sh scripts/ci/brotli-safe-composer.sh --check IMAGE
set -eu

base=composer:2.10.3@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee
image=dw-composer-brotli-floor:2.10.3-da624c2c705d
floor='brotli-libs>=1.2.1-r0'

# Refuses IMAGE unless its installed brotli-libs meets the floor and every
# Brotli decoder its PHP links belongs to that package.
check() {
    docker run --rm --network none --user 0 --entrypoint sh "$1" -c '
        set -eu
        apk info -e "$1" > /dev/null || { echo "Composer image lacks $1" >&2; exit 1; }
        for library in $(ldd "$(command -v php)" | awk "/libbrotlidec/ {print \$3}"); do
            apk info --who-owns "$library" | grep -q "brotli-libs-" || { echo "Unowned decoder $library" >&2; exit 1; }
        done
        composer --version > /dev/null 2>&1
    ' sh "$floor" >&2
}

if test "${1:-}" = --check; then
    check "${2:?Usage: brotli-safe-composer.sh --check IMAGE}"
    exit 0
fi

if ! docker image inspect "$image" > /dev/null 2>&1; then
    printf 'FROM %s\nLABEL dw.composer.base="%s"\nRUN apk add --no-cache --upgrade "%s" && apk info -e "%s"\n' \
        "$base" "$base" "$floor" "$floor" \
        | docker build --quiet --tag "$image" - > /dev/null
fi
# The derived image must still be the pinned one plus the fixed decoder.
parent=$(docker image inspect --format '{{index .Config.Labels "dw.composer.base"}}' "$image")
test "$parent" = "$base" || { echo "$image was not built from $base" >&2; exit 1; }
check "$image"
printf '%s\n' "$image"
