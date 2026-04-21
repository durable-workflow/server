#!/usr/bin/env sh
set -eu

# The runtime needs an internal application key. Keep the public server
# contract under DW_* names: DW_SERVER_KEY pins it when required, otherwise
# generate a container-local key.
if [ -n "${DW_SERVER_KEY:-}" ]; then
    APP_KEY="$DW_SERVER_KEY"
    export APP_KEY
elif [ -z "${APP_KEY:-}" ]; then
    APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
    export APP_KEY
fi

/usr/local/bin/server-ensure-sqlite

# Cache config at runtime so environment variables (DB_HOST, REDIS_HOST, etc.)
# are resolved from the container environment, not from build-time defaults.
php artisan config:cache

# Audit the environment against the DW_* contract. Warnings for unknown
# DW_* vars (typos, silent-drop renames) and deprecated legacy names land
# in the container log before any request is served. Non-zero exit is
# suppressed by default — set DW_ENV_AUDIT_STRICT=1 to fail container
# boot on drift.
if [ "${DW_ENV_AUDIT_STRICT:-0}" = "1" ]; then
    php artisan env:audit --strict >&2
else
    php artisan env:audit >&2 || true
fi

exec "$@"
