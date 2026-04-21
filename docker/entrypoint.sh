#!/usr/bin/env sh
set -eu

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
