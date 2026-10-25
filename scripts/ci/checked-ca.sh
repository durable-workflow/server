#!/bin/sh
# Writes a checked CA file and an empty CA directory for tools that run in
# an image whose own store is not checked (GHSA-6q48-6c7w-9gw2). The file is
# the CA store of the pinned Composer image, refused if it holds a withdrawn
# root. Point Composer at both with COMPOSER_CAFILE=DIR/ca.pem and
# COMPOSER_CAPATH=DIR/no-ca-directory; a CA file alone adds to curl's
# compiled CA directory instead of replacing it.
#
# Usage: checked-ca.sh DIR
set -eu

here=$(dirname "$0")
directory=${1:?Usage: checked-ca.sh DIR}
image=composer:2.10.3@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee

mkdir -p "$directory/no-ca-directory"
docker run --rm --network none --entrypoint cat "$image" /etc/ssl/certs/ca-certificates.crt > "$directory/ca.pem.tmp"
python3 -I "$here/blocked_roots.py" "$directory/ca.pem.tmp" >&2
mv "$directory/ca.pem.tmp" "$directory/ca.pem"
chmod 0644 "$directory/ca.pem"
chmod 0555 "$directory/no-ca-directory"
