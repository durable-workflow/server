#!/usr/bin/env sh
set -eu

# Honor the DW_ prefix with legacy WORKFLOW_SERVER_ fallback while the
# deprecation window is open. See config/dw-contract.php.
retries="${DW_BOOTSTRAP_RETRIES:-${WORKFLOW_SERVER_BOOTSTRAP_RETRIES:-30}}"
delay="${DW_BOOTSTRAP_DELAY_SECONDS:-${WORKFLOW_SERVER_BOOTSTRAP_DELAY_SECONDS:-2}}"
attempt=1

while [ "$attempt" -le "$retries" ]; do
    if php artisan server:bootstrap --force "$@"; then
        exit 0
    fi

    if [ "$attempt" -eq "$retries" ]; then
        echo "Server bootstrap failed after ${retries} attempts." >&2
        exit 1
    fi

    echo "Bootstrap attempt ${attempt} failed. Retrying in ${delay}s..." >&2
    attempt=$((attempt + 1))
    sleep "$delay"
done
