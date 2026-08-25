#!/usr/bin/env sh

set -eu

dockerhub_image="${DOCKERHUB_IMAGE:-durableworkflow/server}"
ghcr_image="${GHCR_IMAGE:-ghcr.io/durable-workflow/server}"
release_tag=""
missing_credentials=""

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

append_missing_credential() {
    if [ -z "$missing_credentials" ]; then
        missing_credentials="$1"
    else
        missing_credentials="${missing_credentials}, $1"
    fi
}

is_semver_tag() {
    printf '%s' "$1" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$'
}

case "${GITHUB_EVENT_NAME:-}" in
    push)
        case "${GITHUB_REF:-}" in
            refs/tags/*)
                release_tag="${GITHUB_REF#refs/tags/}"
                ;;
            *)
                fail "Release publish context required" "Docker image publication is restricted to tag pushes or workflow_dispatch with a tag input. Ref '${GITHUB_REF:-<unset>}' is not a release tag; pull-request and branch validation must use non-push image builds without registry credentials."
                ;;
        esac
        ;;
    workflow_dispatch)
        release_tag="${INPUT_TAG:-}"
        if [ -z "$release_tag" ]; then
            case "${GITHUB_REF:-}" in
                refs/tags/*)
                    release_tag="${GITHUB_REF#refs/tags/}"
                    ;;
            esac
        fi

        if [ -z "$release_tag" ]; then
            fail "Release publish context required" "Manual Docker image publication requires a tag input. Pull-request and branch validation must use non-push image builds without registry credentials."
        fi
        ;;
    *)
        fail "Release publish context required" "Docker image publication is restricted to release events. Event '${GITHUB_EVENT_NAME:-<unset>}' must not run registry publish steps."
        ;;
esac

if ! printf '%s' "$release_tag" | grep -Eq '^[0-9A-Za-z._-]+$'; then
    fail "Invalid release image tag" "Release image tag '${release_tag}' contains characters that are not safe for Docker tags."
fi

source_release_version="${SOURCE_RELEASE_VERSION:-}"
if [ -n "$source_release_version" ]; then
    if ! is_semver_tag "$source_release_version"; then
        fail "Invalid source release identity" "Authoritative Server source release '${source_release_version}' is not an exact SemVer value."
    fi
    if [ "$release_tag" != "$source_release_version" ]; then
        fail "Release tag does not match source" "Release tag '${release_tag}' does not match authoritative Server source release '${source_release_version}'. Run node scripts/ci/sync-source-release.mjs --write before tagging."
    fi
fi

if [ -z "${DOCKERHUB_USERNAME:-}" ]; then
    append_missing_credential "DOCKERHUB_USERNAME"
fi

if [ -z "${DOCKERHUB_TOKEN:-}" ]; then
    append_missing_credential "DOCKERHUB_TOKEN"
fi

if [ -z "${GHCR_TOKEN:-}" ]; then
    append_missing_credential "GHCR_TOKEN"
fi

if [ -n "$missing_credentials" ]; then
    fail "Release registry credentials missing" "Release blocked: cannot publish ${dockerhub_image}:${release_tag} and ${ghcr_image}:${release_tag}; missing ${missing_credentials}. Registry credentials are release handoff inputs, so pull-request validation must not run this publish path."
fi

if [ -n "${GITHUB_OUTPUT:-}" ]; then
    {
        printf 'tag=%s\n' "$release_tag"
        if is_semver_tag "$release_tag"; then
            printf 'is_semver=true\n'
        else
            printf 'is_semver=false\n'
        fi
    } >> "$GITHUB_OUTPUT"
fi

printf 'Release image publish context validated for %s:%s and %s:%s\n' "$dockerhub_image" "$release_tag" "$ghcr_image" "$release_tag"
