#!/bin/sh
# Refuses unless the CA trust Composer is about to use excludes the roots
# withdrawn under GHSA-6q48-6c7w-9gw2. Run it before a Composer request in
# an image whose store is not otherwise checked.
#
# With COMPOSER_CAFILE and COMPOSER_CAPATH (see checked-ca.sh), Composer
# trusts only that file, so the directory must be empty and the file clean.
# Without them, PHP's curl reads the system bundle and also the hashed
# system directory, so both are checked.
#
# Usage: composer-trust-guard.sh
set -eu

here=$(dirname "$0")
if [ -n "${COMPOSER_CAFILE:-}" ] || [ -n "${COMPOSER_CAPATH:-}" ]; then
    if [ -z "${COMPOSER_CAFILE:-}" ] || [ -z "${COMPOSER_CAPATH:-}" ]; then
        echo "Set COMPOSER_CAFILE and COMPOSER_CAPATH together; a CA file alone adds to curl's CA directory." >&2
        exit 1
    fi
    if [ -n "$(ls -A "$COMPOSER_CAPATH")" ]; then
        echo "COMPOSER_CAPATH must be an empty directory." >&2
        exit 1
    fi
    exec python3 -I "$here/blocked_roots.py" "$COMPOSER_CAFILE"
fi

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
find -L /etc/ssl/certs -maxdepth 1 -type f \( -name '*.pem' -o -name '*.crt' -o -name '*.[0-9]' \) \
    -exec cat {} + > "$work/directory.pem"
python3 -I "$here/blocked_roots.py" /etc/ssl/certs/ca-certificates.crt "$work/directory.pem"
