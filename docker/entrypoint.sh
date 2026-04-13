#!/usr/bin/env sh
set -eu

# Cache config at runtime so environment variables (DB_HOST, REDIS_HOST, etc.)
# are resolved from the container environment, not from build-time defaults.
php artisan config:cache

exec "$@"
