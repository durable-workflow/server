#!/bin/sh
# Refuses an image whose TLS trust still includes a root withdrawn under
# GHSA-6q48-6c7w-9gw2. `system` checks the operating system store before a
# build stage's first download. `final` also checks every runtime the Server
# image ships: PHP's OpenSSL defaults, Composer, Node and a fresh Python venv.
set -eu

here=$(dirname "$(readlink -f "$0")")
count() { php "$here/count-blocked.php" "$@"; }

count /etc/ssl/certs/ca-certificates.crt
if [ "${1:-final}" = system ]; then
    exit 0
fi

# PHP's OpenSSL defaults: Debian ships the directory, not the default file.
for store in $(php -r '$l = openssl_get_cert_locations(); foreach (["default_cert_file", "default_cert_dir"] as $k) { if (file_exists($l[$k])) { echo realpath($l[$k]), PHP_EOL; } }'); do
    count "$store"
done

# Composer 2.10.3 or later, selecting the system file.
selected=$(php "$here/composer-trust.php")
if [ "$selected" != /etc/ssl/certs/ca-certificates.crt ]; then
    echo "Composer selects $selected, not the system CA file." >&2
    exit 1
fi

# Exactly one Node, the fixed runtime copied from the pinned stage.
if [ "$(command -v node)" != /usr/local/bin/node ] || [ -e /usr/bin/node ] || [ -e /usr/bin/nodejs ]; then
    echo "Only /usr/local/bin/node may provide Node." >&2
    exit 1
fi
node "$here/node-trust.js"

# A fresh venv's ssl and pip stores.
venv=$(mktemp -d)
python3 -m venv "$venv"
stores=$("$venv/bin/python" "$here/python-trust.py")
rm -rf "$venv"
if [ -z "$stores" ]; then
    echo "A fresh venv reports no CA store." >&2
    exit 1
fi
for store in $stores; do
    count "$store"
done
