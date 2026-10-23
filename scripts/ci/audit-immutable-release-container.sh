#!/bin/sh
# Runs only in the pinned disposable Composer image. Establish and verify the
# decoder floor before Composer or curl can decode any network response.
set -eu

identity=${1:?The nonprivileged audit UID:GID is required}
case "$identity" in
    *[!0-9:]*|:*|*:|*:*:*) echo 'Audit identity must be a nonprivileged UID:GID.' >&2; exit 1 ;;
    *:*) ;;
    *) echo 'Audit identity must contain both UID and GID.' >&2; exit 1 ;;
esac
test "${identity%%:*}" -gt 0
test "${identity#*:}" -gt 0
: "${COMPOSER_CAFILE:?A checked CA file is required}"
: "${COMPOSER_CAPATH:?An empty CA directory is required}"
test -s "$COMPOSER_CAFILE"
test -d "$COMPOSER_CAPATH"
test -z "$(ls -A "$COMPOSER_CAPATH")"

apk add --no-cache --upgrade 'brotli-libs>=1.2.1-r0' su-exec
apk info -e 'brotli-libs>=1.2.1-r0' >/dev/null

export COMPOSER_HOME=/tmp/release-composer
exec su-exec "$identity" composer --no-plugins --no-scripts audit --locked --no-dev --no-interaction
