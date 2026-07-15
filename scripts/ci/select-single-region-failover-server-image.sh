#!/usr/bin/env sh

set -eu

requested_image="${REQUESTED_IMAGE:-}"

if [ -n "$requested_image" ]; then
    printf '%s\n' "$requested_image"
    exit 0
fi

release_tag="$(for candidate in $(git tag --list --sort=-version:refname); do
    if printf '%s' "$candidate" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
        printf '%s\n' "$candidate"
        break
    fi
done)"

if [ -z "$release_tag" ]; then
    printf '%s\n' 'No exact stable server release tag is available for the failover rehearsal.' >&2
    exit 1
fi

printf 'durableworkflow/server:%s\n' "$release_tag"
