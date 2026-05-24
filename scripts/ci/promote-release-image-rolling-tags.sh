#!/usr/bin/env sh

set -eu

release_tag="${RELEASE_TAG:-}"
dockerhub_image="${DOCKERHUB_IMAGE:-durableworkflow/server}"
ghcr_image="${GHCR_IMAGE:-ghcr.io/durable-workflow/server}"
docker_bin="${DOCKER:-docker}"
tag_source_url="${RELEASE_IMAGE_TAG_SOURCE_URL:-}"

fail() {
    printf '::error title=%s::%s\n' "$1" "$2" >&2
    printf '%s\n' "$2" >&2
    exit 1
}

is_stable_semver_tag() {
    printf '%s' "$1" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'
}

write_output() {
    if [ -n "${GITHUB_OUTPUT:-}" ]; then
        printf '%s=%s\n' "$1" "$2" >> "$GITHUB_OUTPUT"
    fi
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
        return
    fi

    if ! raw_tags="$(git ls-remote --tags --refs "$tag_source_url" 2>/tmp/release-image-promote-tags.err)"; then
        error_detail="$(cat /tmp/release-image-promote-tags.err 2>/dev/null || true)"
        fail "Release tag source unavailable" "Cannot list release tags from ${tag_source_url} before promoting rolling image aliases. ${error_detail}"
    fi

    printf '%s\n' "$raw_tags" | normalize_tag_lines
}

latest_known_stable_tag() {
    {
        collect_release_tags
        printf '%s\n' "$release_tag"
    } | while IFS= read -r candidate; do
        if is_stable_semver_tag "$candidate"; then
            printf '%s\n' "$candidate"
        fi
    done | sort -u -V | tail -n 1
}

if [ "${ROLLING_SHOULD_PROMOTE:-true}" != "true" ]; then
    printf 'Rolling image alias promotion skipped for %s.\n' "${release_tag:-<unset>}"
    exit 0
fi

if [ -z "$release_tag" ]; then
    fail "Release tag required" "Cannot promote rolling image aliases without RELEASE_TAG."
fi

if ! is_stable_semver_tag "$release_tag"; then
    fail "Stable semver tag required" "Rolling image aliases can only be promoted from stable semver tags; got '${release_tag}'."
fi

major="${release_tag%%.*}"
minor_patch="${release_tag#*.}"
minor="${minor_patch%%.*}"
minor_alias="${major}.${minor}"
major_alias="$major"

if [ -n "${RELEASE_IMAGE_KNOWN_TAGS:-}" ] || [ -n "$tag_source_url" ]; then
    latest_tag="$(latest_known_stable_tag)"

    if [ "$latest_tag" != "$release_tag" ]; then
        write_output "rolling_should_promote" "false"
        write_output "artifact_status" "superseded"
        write_output "superseded_by" "$latest_tag"
        printf 'Release image tag %s was superseded by %s before alias promotion; rolling aliases were not changed.\n' "$release_tag" "$latest_tag"
        exit 0
    fi
fi

promote_image() {
    image="$1"

    "$docker_bin" buildx imagetools create \
        --tag "${image}:${minor_alias}" \
        --tag "${image}:${major_alias}" \
        --tag "${image}:latest" \
        "${image}:${release_tag}"
}

promote_image "$dockerhub_image"
promote_image "$ghcr_image"

write_output "rolling_should_promote" "true"
write_output "artifact_status" "current"
write_output "superseded_by" ""

printf 'Promoted rolling image aliases %s, %s, and latest from exact tag %s for %s and %s.\n' \
    "$minor_alias" "$major_alias" "$release_tag" "$dockerhub_image" "$ghcr_image"
