#!/usr/bin/env bash

set -euo pipefail

fail() {
    printf 'GitHub Release publication failed: %s\n' "$1" >&2
    exit 1
}

validate_bounded_integer() {
    local name="$1"
    local value="$2"
    local minimum="$3"
    local maximum="$4"

    [[ "$value" =~ ^[0-9]{1,3}$ ]] \
        && [ "$value" -ge "$minimum" ] \
        && [ "$value" -le "$maximum" ] \
        || fail "$name must be an integer from $minimum through $maximum."
}

is_transient_http_failure() {
    local message="$1"

    [[ "$message" =~ HTTP[[:space:]]+(429|5[0-9][0-9])([^0-9]|$) ]]
}

exact_release_exists() {
    local delay
    local view_attempt
    local view_output

    for ((view_attempt = 1; view_attempt <= maximum_attempts; view_attempt++)); do
        if view_output="$("$gh_cli" release view "$release_tag" --json tagName 2>&1)"; then
            return 0
        fi

        if [[ "$view_output" =~ HTTP[[:space:]]+404([^0-9]|$) ]] \
            || [[ "$view_output" == *"release not found"* ]]; then
            return 1
        fi

        printf '%s\n' "$view_output" >&2
        if ! is_transient_http_failure "$view_output"; then
            return 2
        fi

        if [ "$view_attempt" -ge "$maximum_attempts" ]; then
            printf 'Transient GitHub API failure persisted for %s attempts while checking exact tag %s.\n' \
                "$maximum_attempts" "$release_tag" >&2
            return 2
        fi

        delay="$(retry_delay "$view_attempt")"
        printf 'Transient GitHub Release view failure for %s on attempt %s/%s; retrying after %ss.\n' \
            "$release_tag" "$view_attempt" "$maximum_attempts" "$delay" >&2
        if [ "$delay" -gt 0 ]; then
            sleep "$delay"
        fi
    done
}

retry_delay() {
    local failed_attempt="$1"
    local delay="$initial_delay"
    local exponent

    for ((exponent = 1; exponent < failed_attempt; exponent++)); do
        if [ "$delay" -ge "$maximum_delay" ] || [ "$delay" -gt $((maximum_delay / 2)) ]; then
            delay="$maximum_delay"
            break
        fi
        delay=$((delay * 2))
    done

    if [ "$delay" -gt "$maximum_delay" ]; then
        delay="$maximum_delay"
    fi

    printf '%s\n' "$delay"
}

release_tag="${RELEASE_TAG:-}"
gh_cli="${GH_CLI:-gh}"
maximum_attempts="${GITHUB_RELEASE_MAX_ATTEMPTS:-4}"
initial_delay="${GITHUB_RELEASE_RETRY_INITIAL_DELAY_SECONDS:-2}"
maximum_delay="${GITHUB_RELEASE_RETRY_MAX_DELAY_SECONDS:-30}"

[ -n "$release_tag" ] || fail 'RELEASE_TAG must name the exact source tag.'
validate_bounded_integer GITHUB_RELEASE_MAX_ATTEMPTS "$maximum_attempts" 1 10
validate_bounded_integer GITHUB_RELEASE_RETRY_INITIAL_DELAY_SECONDS "$initial_delay" 0 300
validate_bounded_integer GITHUB_RELEASE_RETRY_MAX_DELAY_SECONDS "$maximum_delay" 0 300
[ "$initial_delay" -le "$maximum_delay" ] \
    || fail 'GITHUB_RELEASE_RETRY_INITIAL_DELAY_SECONDS cannot exceed GITHUB_RELEASE_RETRY_MAX_DELAY_SECONDS.'

arguments=(--verify-tag --generate-notes --title "$release_tag")
if [[ "$release_tag" == *-* ]]; then
    arguments+=(--prerelease)
fi

if exact_release_exists; then
    printf 'GitHub Release for exact tag %s already exists.\n' "$release_tag"
    exit 0
else
    view_status=$?
    [ "$view_status" -eq 1 ] || fail "could not determine whether exact tag $release_tag already has a release."
fi

for ((attempt = 1; attempt <= maximum_attempts; attempt++)); do
    if create_output="$("$gh_cli" release create "$release_tag" "${arguments[@]}" 2>&1)"; then
        if [ -n "$create_output" ]; then
            printf '%s\n' "$create_output"
        fi
        exit 0
    else
        create_status=$?
    fi

    printf '%s\n' "$create_output" >&2
    if ! is_transient_http_failure "$create_output"; then
        exit "$create_status"
    fi

    if [ "$attempt" -ge "$maximum_attempts" ]; then
        fail "transient GitHub API failure persisted for $maximum_attempts attempts while creating exact tag $release_tag."
    fi

    delay="$(retry_delay "$attempt")"
    printf 'Transient GitHub Release API failure for %s on attempt %s/%s; checking the exact tag again after %ss.\n' \
        "$release_tag" "$attempt" "$maximum_attempts" "$delay" >&2
    if [ "$delay" -gt 0 ]; then
        sleep "$delay"
    fi

    if exact_release_exists; then
        printf 'GitHub Release for exact tag %s already exists; no retry is needed.\n' "$release_tag"
        exit 0
    else
        view_status=$?
        [ "$view_status" -eq 1 ] \
            || fail "could not determine whether exact tag $release_tag was created before retry."
    fi
done
