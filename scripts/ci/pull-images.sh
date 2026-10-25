#!/usr/bin/env bash
# Pulls each named image before a job's first container, retrying registry
# errors. A registry briefly answering "unknown blob" for a pinned digest once
# failed 24 fixture jobs at their first `docker run` (run 40164159608); a pull
# that retries with backoff absorbs that, and later runs find the image local.
#
# Usage: pull-images.sh IMAGE...   (empty arguments are skipped)
# DW_PULL_ATTEMPTS (default 4) and DW_PULL_BACKOFF_SECONDS (default 15, doubled
# after each failed attempt) bound the wait; the last failure fails the step.
set -euo pipefail

attempts="${DW_PULL_ATTEMPTS:-4}"
backoff="${DW_PULL_BACKOFF_SECONDS:-15}"
[[ "$attempts" =~ ^[1-9][0-9]*$ && "$backoff" =~ ^[0-9]+$ ]] || {
    echo "pull-images.sh: DW_PULL_ATTEMPTS must be positive and DW_PULL_BACKOFF_SECONDS a whole number" >&2
    exit 2
}

for image in "$@"; do
    [[ -n "$image" ]] || continue
    wait="$backoff"
    for ((attempt = 1; ; attempt++)); do
        if docker pull --quiet "$image" >/dev/null; then
            echo "pulled $image (attempt $attempt)"
            break
        fi
        if ((attempt >= attempts)); then
            echo "pull-images.sh: could not pull $image after $attempts attempts" >&2
            exit 1
        fi
        echo "pull of $image failed (attempt $attempt of $attempts); retrying in ${wait}s" >&2
        sleep "$wait"
        wait=$((wait * 2))
    done
done
