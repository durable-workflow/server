#!/usr/bin/env sh
# Assemble an embedded application from published packages, separately from
# the unmodified PHP reference image and its older packaged engine.
set -eu
test -n "$DW_PARITY_EMBEDDED_VERSION"
if ! test -e composer.json; then
  cp -R /app/. .
fi
composer config --unset repositories
composer require "durable-workflow/workflow:$DW_PARITY_EMBEDDED_VERSION" \
  --with-dependencies --update-no-dev --no-scripts --no-cache --no-interaction --no-progress --prefer-dist
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/routes-v7.php
php artisan package:discover --ansi
composer show durable-workflow/workflow --format=json
