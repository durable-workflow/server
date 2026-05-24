#!/usr/bin/env sh

set -eu

release_tag="${RELEASE_TAG:-}"
dockerhub_image="${DOCKERHUB_IMAGE:-durableworkflow/server}"
ghcr_image="${GHCR_IMAGE:-ghcr.io/durable-workflow/server}"
tag_source_url="${RELEASE_IMAGE_TAG_SOURCE_URL:-}"

fail() {
    title="$1"
    message="$2"

    if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
        {
            printf '## %s\n\n' "$title"
            printf '%s\n' "$message"
        } >> "$GITHUB_STEP_SUMMARY"
    fi

    printf '::error title=%s::%s\n' "$title" "$message" >&2
    printf '%s\n' "$message" >&2
    exit 1
}

write_output() {
    if [ -n "${GITHUB_OUTPUT:-}" ]; then
        printf '%s=%s\n' "$1" "$2" >> "$GITHUB_OUTPUT"
    fi
}

write_multiline_output() {
    if [ -n "${GITHUB_OUTPUT:-}" ]; then
        {
            printf '%s<<__release_image_%s__\n' "$1" "$1"
            printf '%s\n' "$2"
            printf '__release_image_%s__\n' "$1"
        } >> "$GITHUB_OUTPUT"
    fi
}

is_stable_semver_tag() {
    printf '%s' "$1" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'
}

normalize_tag_lines() {
    while IFS= read -r line; do
        case "$line" in
            *refs/tags/*)
                line="${line##*refs/tags/}"
                ;;
        esac

        line="${line%\^\{\}}"

        if [ -n "$line" ]; then
            printf '%s\n' "$line"
        fi
    done
}

collect_release_tags() {
    if [ -n "${RELEASE_IMAGE_KNOWN_TAGS:-}" ]; then
        printf '%s\n' "$RELEASE_IMAGE_KNOWN_TAGS" | normalize_tag_lines
        return
    fi

    if [ -z "$tag_source_url" ]; then
        tag_source_url="$(git config --get remote.origin.url 2>/dev/null || true)"
    fi

    if [ -z "$tag_source_url" ]; then
        fail "Release tag source unavailable" "Cannot resolve release tags before promoting rolling image aliases."
    fi

    if ! raw_tags="$(git ls-remote --tags --refs "$tag_source_url" 2>/tmp/release-image-tags.err)"; then
        error_detail="$(cat /tmp/release-image-tags.err 2>/dev/null || true)"
        fail "Release tag source unavailable" "Cannot list release tags from ${tag_source_url}. ${error_detail}"
    fi

    printf '%s\n' "$raw_tags" | normalize_tag_lines
}

if [ -z "$release_tag" ]; then
    fail "Release tag required" "Cannot resolve rolling image aliases without RELEASE_TAG."
fi

if ! is_stable_semver_tag "$release_tag"; then
    write_output "rolling_eligible" "false"
    write_output "rolling_should_promote" "false"
    write_output "artifact_status" "current"
    write_output "superseded_by" ""
    printf 'Release image tag %s is not a stable semver tag; exact image tags are current and rolling aliases are unchanged.\n' "$release_tag"
    exit 0
fi

major="${release_tag%%.*}"
minor_patch="${release_tag#*.}"
minor="${minor_patch%%.*}"
minor_alias="${major}.${minor}"
major_alias="$major"

stable_tags="$(
    {
        collect_release_tags
        printf '%s\n' "$release_tag"
    } | while IFS= read -r candidate; do
        if is_stable_semver_tag "$candidate"; then
            printf '%s\n' "$candidate"
        fi
    done | sort -u -V
)"

latest_tag="$(printf '%s\n' "$stable_tags" | tail -n 1)"

if [ -z "$latest_tag" ]; then
    fail "Release tag source unavailable" "No stable semver release tags were found while resolving rolling image aliases."
fi

rolling_refs="$(printf '%s:%s\n%s:%s\n%s:%s\n%s:%s\n%s:%s\n%s:%s' \
    "$dockerhub_image" "$minor_alias" \
    "$dockerhub_image" "$major_alias" \
    "$dockerhub_image" "latest" \
    "$ghcr_image" "$minor_alias" \
    "$ghcr_image" "$major_alias" \
    "$ghcr_image" "latest")"

write_output "rolling_eligible" "true"
write_output "major_alias" "$major_alias"
write_output "minor_alias" "$minor_alias"
write_multiline_output "rolling_refs" "$rolling_refs"

if [ "$latest_tag" = "$release_tag" ]; then
    write_output "rolling_should_promote" "true"
    write_output "artifact_status" "current"
    write_output "superseded_by" ""
    printf 'Release image tag %s is current; rolling aliases will be promoted from the exact image tag.\n' "$release_tag"
else
    write_output "rolling_should_promote" "false"
    write_output "artifact_status" "superseded"
    write_output "superseded_by" "$latest_tag"
    printf 'Release image tag %s is superseded by %s; exact image tags remain published and rolling aliases will not move backward.\n' "$release_tag" "$latest_tag"
fi
