#!/usr/bin/env sh
# Assemble an embedded application from published packages, separately from
# the unmodified PHP reference image and its older packaged engine.
set -eu
test -n "$DW_PARITY_EMBEDDED_PROFILE"
test -n "$DW_PARITY_EMBEDDED_LOCK_SHA256"
if ! test -e composer.json; then
  cp -R /app/. .
fi
cp "$DW_PARITY_EMBEDDED_PROFILE/composer.json" "$DW_PARITY_EMBEDDED_PROFILE/composer.lock" .
printf '%s  composer.lock\n' "$DW_PARITY_EMBEDDED_LOCK_SHA256" | sha256sum -c -
composer install --no-dev --no-scripts --no-cache --no-interaction --no-progress --prefer-dist --optimize-autoloader
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/routes-v7.php
php artisan package:discover --ansi
composer show durable-workflow/workflow --format=json
