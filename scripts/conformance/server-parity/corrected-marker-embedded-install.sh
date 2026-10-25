#!/usr/bin/env sh
# Assemble the application from the pinned image, then install the declared
# published role from its normal lock. Do not inherit the image's vendor tree.
set -eu
test -n "$DW_PARITY_EMBEDDED_PROFILE"
test -n "$DW_PARITY_EMBEDDED_LOCK_SHA256"
if ! test -e artisan; then
  for entry in app artisan bootstrap config database public routes scripts resources; do
    cp -R "/app/$entry" .
  done
fi
mkdir -p bootstrap/cache storage/logs storage/framework/cache/data \
  storage/framework/sessions storage/framework/views
cp "$DW_PARITY_EMBEDDED_PROFILE/composer.json" "$DW_PARITY_EMBEDDED_PROFILE/composer.lock" .
printf '%s  composer.lock\n' "$DW_PARITY_EMBEDDED_LOCK_SHA256" | sha256sum -c -
sh "$(dirname "$0")/../../ci/composer-trust-guard.sh" >&2
composer install --no-dev --no-scripts --no-cache --no-interaction --no-progress --prefer-dist --optimize-autoloader
if test -n "${DW_PARITY_EMBEDDED_RUNTIME_HASHES:-}"; then
  (cd vendor/durable-workflow/workflow && sha256sum -c "$DW_PARITY_EMBEDDED_RUNTIME_HASHES")
fi
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/routes-v7.php
php artisan package:discover --ansi
composer show durable-workflow/workflow --format=json
