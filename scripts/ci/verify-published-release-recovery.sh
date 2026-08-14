#!/usr/bin/env bash

set -euo pipefail

release_tag="${RELEASE_TAG:-}"
release_commit="${RELEASE_COMMIT:-}"
repository="${GITHUB_REPOSITORY:-}"
dockerhub_image="${DOCKERHUB_IMAGE:-durableworkflow/server}"
ghcr_image="${GHCR_IMAGE:-ghcr.io/durable-workflow/server}"
evidence_path="${RELEASE_RECOVERY_EVIDENCE:-published-release-recovery-evidence.json}"
gh_cli="${GH_CLI:-gh}"
source_status="unverified"
images_status="unverified"
github_release_status="unverified"
image_digest=""
github_release_url=""
github_release_id=""
github_release_target=""
exact_output=""

cleanup() {
    if [ -n "$exact_output" ]; then
        rm -f "$exact_output"
    fi
}
trap cleanup EXIT HUP INT TERM

write_evidence() {
    local outcome="$1"
    local failure_kind="$2"
    local message="$3"
    local checked_at run_url

    checked_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
    run_url=""
    if [ -n "${GITHUB_SERVER_URL:-}" ] && [ -n "$repository" ] && [ -n "${GITHUB_RUN_ID:-}" ]; then
        run_url="${GITHUB_SERVER_URL}/${repository}/actions/runs/${GITHUB_RUN_ID}"
    fi

    jq -n \
        --arg schema "durable-workflow.server.published-release-recovery-evidence/v1" \
        --arg checked_at "$checked_at" \
        --arg outcome "$outcome" \
        --arg failure_kind "$failure_kind" \
        --arg message "$message" \
        --arg repository "$repository" \
        --arg tag "$release_tag" \
        --arg commit "$release_commit" \
        --arg source_status "$source_status" \
        --arg images_status "$images_status" \
        --arg dockerhub_ref "${dockerhub_image}:${release_tag}" \
        --arg ghcr_ref "${ghcr_image}:${release_tag}" \
        --arg image_digest "$image_digest" \
        --arg github_release_status "$github_release_status" \
        --arg github_release_url "$github_release_url" \
        --arg github_release_id "$github_release_id" \
        --arg github_release_target "$github_release_target" \
        --arg tooling_commit "${GITHUB_SHA:-}" \
        --arg run_id "${GITHUB_RUN_ID:-}" \
        --arg run_attempt "${GITHUB_RUN_ATTEMPT:-}" \
        --arg run_url "$run_url" \
        '{
          schema: $schema,
          checked_at: $checked_at,
          outcome: $outcome,
          status: (if $outcome == "pass" then "success" else "failure" end),
          failure_kind: (($failure_kind | select(length > 0)) // null),
          message: (($message | select(length > 0)) // null),
          requested_identity: {
            repository: $repository,
            tag: $tag,
            commit: $commit
          },
          source_tag: {
            status: $source_status,
            tag: $tag,
            commit: $commit
          },
          images: {
            status: $images_status,
            digest: (($image_digest | select(length > 0)) // null),
            required_platforms: ["linux/amd64", "linux/arm64"],
            references: [
              {registry: "docker_hub", ref: $dockerhub_ref},
              {registry: "ghcr", ref: $ghcr_ref}
            ]
          },
          github_release: {
            status: $github_release_status,
            id: (($github_release_id | select(length > 0)) // null),
            tag: $tag,
            target_commitish: (($github_release_target | select(length > 0)) // null),
            url: (($github_release_url | select(length > 0)) // null)
          },
          mutation_policy: {
            mode: "verification_only",
            image_mutation: false,
            tag_mutation: false,
            github_release_mutation: false
          },
          trusted_tooling: {
            default_branch_commit: (($tooling_commit | select(length > 0)) // null),
            run_id: (($run_id | select(length > 0)) // null),
            run_attempt: (($run_attempt | select(length > 0)) // null),
            run_url: (($run_url | select(length > 0)) // null)
          }
        }' > "$evidence_path"
}

fail() {
    local failure_kind="$1"
    local message

    message="$(printf '%s' "$2" | LC_ALL=C cut -c 1-2000)"
    write_evidence "fail" "$failure_kind" "$message"
    printf 'Published release recovery verification failed: %s\n' "$message" >&2
    exit 1
}

[[ "$release_tag" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z][0-9A-Za-z.-]*)?$ ]] \
    || fail "invalid_requested_tag" "RELEASE_TAG must be an exact release version."
[[ "$release_commit" =~ ^[0-9a-f]{40}$ ]] \
    || fail "invalid_requested_commit" "RELEASE_COMMIT must be a full lowercase Git commit SHA."
[[ "$repository" =~ ^[0-9A-Za-z_.-]+/[0-9A-Za-z_.-]+$ ]] \
    || fail "invalid_repository" "GITHUB_REPOSITORY must identify the release repository."

if ! source_detail="$(scripts/ci/verify-release-tag-source.sh 2>&1)"; then
    fail "source_tag_mismatch" "$source_detail"
fi
source_status="verified"

if ! release_json="$(
    "$gh_cli" api "repos/$repository/releases/tags/$release_tag" \
        --jq '{tag_name: .tag_name, draft: .draft, prerelease: .prerelease, html_url: .html_url, target_commitish: .target_commitish, id: .id}' \
        2>&1
)"; then
    fail "github_release_missing" "$release_json"
fi

if ! jq -e --arg tag "$release_tag" \
    '.tag_name == $tag and .draft == false and (.html_url | type == "string" and startswith("https://github.com/"))' \
    >/dev/null 2>&1 <<< "$release_json"; then
    fail "github_release_identity_mismatch" "GitHub Release metadata is not a public release for exact tag $release_tag."
fi
github_release_status="verified"
github_release_url="$(jq -r '.html_url' <<< "$release_json")"
github_release_id="$(jq -r '.id | tostring' <<< "$release_json")"
github_release_target="$(jq -r '.target_commitish // empty' <<< "$release_json")"

exact_output="$(mktemp "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/published-release-recovery.XXXXXX")"
if ! image_detail="$(
    GITHUB_OUTPUT="$exact_output" \
    RELEASE_IMAGE_VERIFICATION_ONLY=true \
    RELEASE_TAG="$release_tag" \
    RELEASE_COMMIT="$release_commit" \
    DOCKERHUB_IMAGE="$dockerhub_image" \
    GHCR_IMAGE="$ghcr_image" \
        scripts/ci/verify-release-exact-images.sh 2>&1
)"; then
    fail "published_image_mismatch" "$image_detail"
fi

image_digest="$(sed -n 's/^image_digest=//p' "$exact_output" | tail -n 1)"
[[ "$image_digest" =~ ^sha256:[0-9a-f]{64}$ ]] \
    || fail "published_image_digest_missing" "Published image verification did not return one immutable digest."
images_status="verified"

if ! source_detail="$(scripts/ci/verify-release-tag-source.sh 2>&1)"; then
    source_status="changed_during_verification"
    fail "source_tag_moved" "$source_detail"
fi

write_evidence "pass" "" ""
printf 'Verified existing Server release %s at %s across Docker Hub, GHCR, and GitHub Release.\n' \
    "$release_tag" "$release_commit"
