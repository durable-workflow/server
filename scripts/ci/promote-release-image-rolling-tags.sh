#!/usr/bin/env sh

set -eu

release_tag="${RELEASE_TAG:-}"
dockerhub_image="${DOCKERHUB_IMAGE:-durableworkflow/server}"
ghcr_image="${GHCR_IMAGE:-ghcr.io/durable-workflow/server}"
docker_bin="${DOCKER:-docker}"
tag_source_url="${RELEASE_IMAGE_TAG_SOURCE_URL:-}"
verified_image_digest="${VERIFIED_IMAGE_DIGEST:-}"

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

# Native final releases (native-X.Y.Z git tags, Server #325) own latest once
# one is above this PHP release; a PHP release then keeps its own X and X.Y
# aliases and leaves latest alone, so latest never moves back to PHP.
native_final_owns_latest() {
    native_latest="$(collect_release_tags | sed -n 's/^native-\([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\)$/\1/p' | sort -u -V | tail -n 1)"
    [ -n "$native_latest" ] || return 1
    [ "$(printf '%s\n%s\n' "$native_latest" "$release_tag" | sort -V | tail -n 1)" != "$release_tag" ]
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

if [ -n "$verified_image_digest" ] && ! printf '%s' "$verified_image_digest" | grep -Eq '^sha256:[0-9a-f]{64}$'; then
    fail "Invalid verified image digest" "Rolling aliases require an exact lowercase sha256 digest when VERIFIED_IMAGE_DIGEST is supplied."
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

latest_alias="true"
if native_final_owns_latest; then
    latest_alias="false"
fi

promote_image() {
    image="$1"
    source_ref="${image}:${release_tag}"
    if [ -n "$verified_image_digest" ]; then
        source_ref="${image}@${verified_image_digest}"
    fi

    if [ "$latest_alias" = "true" ]; then
        "$docker_bin" buildx imagetools create \
            --tag "${image}:${minor_alias}" \
            --tag "${image}:${major_alias}" \
            --tag "${image}:latest" \
            "$source_ref"
    else
        "$docker_bin" buildx imagetools create \
            --tag "${image}:${minor_alias}" \
            --tag "${image}:${major_alias}" \
            "$source_ref"
    fi
}

promote_image "$dockerhub_image"
promote_image "$ghcr_image"

write_output "rolling_should_promote" "true"
write_output "artifact_status" "current"
write_output "superseded_by" ""
write_output "latest_alias" "$latest_alias"

if [ "$latest_alias" = "true" ]; then
    printf 'Promoted rolling image aliases %s, %s, and latest from exact tag %s for %s and %s.\n' \
        "$minor_alias" "$major_alias" "$release_tag" "$dockerhub_image" "$ghcr_image"
else
    printf 'Promoted rolling image aliases %s and %s from exact tag %s for %s and %s; native release %s owns latest.\n' \
        "$minor_alias" "$major_alias" "$release_tag" "$dockerhub_image" "$ghcr_image" "$native_latest"
fi
