#!/usr/bin/env sh

set -eu

release_tag="${RELEASE_TAG:-}"
dockerhub_image="${DOCKERHUB_IMAGE:-durableworkflow/server}"
ghcr_image="${GHCR_IMAGE:-ghcr.io/durable-workflow/server}"
docker_bin="${DOCKER:-docker}"
build_outcome="${DOCKER_BUILD_OUTCOME:-}"
built_image_digest="${BUILT_IMAGE_DIGEST:-}"
built_image_metadata="${BUILT_IMAGE_METADATA:-}"
release_commit="${RELEASE_COMMIT:-}"
run_id="${RELEASE_RUN_ID:-}"
run_attempt="${RELEASE_RUN_ATTEMPT:-}"
workflow_package_ref="${WORKFLOW_PACKAGE_REF:-}"
workflow_package_commit="${WORKFLOW_PACKAGE_COMMIT:-}"
workflow_package_source="${WORKFLOW_PACKAGE_SOURCE:-}"
required_platforms="${RELEASE_IMAGE_REQUIRED_PLATFORMS:-linux/amd64 linux/arm64}"
tmp_root="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"
tmp_dir="$(mktemp -d "${tmp_root}/release-exact-images.XXXXXX")"
verified_refs=""
image_digest=""
expected_image_digest=""
registry_image_digest=""
verify_published_metadata=false
verification_only="${RELEASE_IMAGE_VERIFICATION_ONLY:-false}"

trap 'rm -rf "$tmp_dir"' EXIT HUP INT TERM

write_output() {
    if [ -n "${GITHUB_OUTPUT:-}" ]; then
        printf '%s=%s\n' "$1" "$2" >> "$GITHUB_OUTPUT"
    fi
}

write_multiline_output() {
    if [ -n "${GITHUB_OUTPUT:-}" ]; then
        {
            printf '%s<<__release_exact_%s__\n' "$1" "$1"
            printf '%s\n' "$2"
            printf '__release_exact_%s__\n' "$1"
        } >> "$GITHUB_OUTPUT"
    fi
}

append_line() {
    if [ -n "$1" ]; then
        printf '%s\n%s' "$1" "$2"
    else
        printf '%s' "$2"
    fi
}

fail() {
    title="$1"
    message="$2"
    reason="$3"

    write_output "exact_publish_outcome" "failure"
    write_output "exact_publish_reason" "$reason"

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

extract_sha256_digest() {
    printf '%s\n' "$1" | sed -n 's/.*\(sha256:[0-9A-Fa-f]\{64\}\).*/\1/p' | head -n 1 | tr 'A-F' 'a-f'
}

extract_metadata_image_digest() {
    printf '%s' "$1" | tr '\n' ' ' | sed -n 's/.*"containerimage\.digest"[[:space:]]*:[[:space:]]*"\(sha256:[0-9A-Fa-f]\{64\}\)".*/\1/p' | head -n 1 | tr 'A-F' 'a-f'
}

has_json_label() {
    path="$1"
    key="$2"
    value="$3"

    jq -e \
        --arg key "$key" \
        --arg value "$value" \
        '(.config.Labels // {})[$key] == $value' \
        "$path" >/dev/null 2>&1
}

verify_ref_metadata() {
    ref="$1"
    safe_ref="$2"

    for platform in $platform_list; do
        safe_platform="$(printf '%s' "$platform" | sed 's/[^A-Za-z0-9_.-]/_/g')"
        image_path="${tmp_dir}/${safe_ref}_${safe_platform}.image"
        error_path="${tmp_dir}/${safe_ref}_${safe_platform}.image.err"

        if ! "$docker_bin" buildx imagetools inspect --format "{{json (index .Image \"${platform}\")}}" "$ref" > "$image_path" 2> "$error_path"; then
            error_detail="$(cat "$error_path" 2>/dev/null || true)"
            fail "Exact release image metadata unavailable" "Cannot inspect image config metadata for published exact image ${ref} on ${platform}. ${error_detail}" "exact_manifest_metadata_missing"
        fi

        for label_pair in \
            "org.opencontainers.image.revision=${release_commit}" \
            "dev.durable-workflow.release.tag=${release_tag}" \
            "dev.durable-workflow.release.run-id=${run_id}" \
            "dev.durable-workflow.release.run-attempt=${run_attempt}" \
            "dev.durable-workflow.workflow.source=${workflow_package_source}" \
            "dev.durable-workflow.workflow.version=${workflow_package_ref}" \
            "dev.durable-workflow.workflow.commit=${workflow_package_commit}"
        do
            label_key="${label_pair%%=*}"
            label_value="${label_pair#*=}"
            [ -n "$label_value" ] || continue

            if ! has_json_label "$image_path" "$label_key" "$label_value"; then
                fail "Exact release image metadata mismatch" "Published exact image ${ref} for ${platform} does not carry expected release metadata ${label_key}=${label_value}." "exact_manifest_metadata_mismatch"
            fi
        done
    done
}

if [ -z "$release_tag" ]; then
    fail "Exact release image tag required" "Cannot verify exact image publication without RELEASE_TAG." "exact_release_tag_missing"
fi

case "$verification_only" in
    true|false) ;;
    *)
        fail "Invalid exact release verification mode" "RELEASE_IMAGE_VERIFICATION_ONLY must be true or false." "exact_verification_mode_invalid"
        ;;
esac

if ! printf '%s' "$release_tag" | grep -Eq '^[0-9A-Za-z._-]+$'; then
    fail "Invalid exact release image tag" "Release image tag '${release_tag}' contains characters that are not safe for Docker tags." "exact_release_tag_invalid"
fi

platform_list="$(printf '%s' "$required_platforms" | tr ',' ' ')"
if [ -z "$platform_list" ]; then
    fail "Exact release image platforms required" "Cannot verify exact image publication without at least one required platform." "exact_required_platforms_missing"
fi

release_identity_present=false
for identity_value in \
    "$release_commit" \
    "$run_id" \
    "$run_attempt" \
    "$workflow_package_source" \
    "$workflow_package_ref" \
    "$workflow_package_commit"
do
    if [ -n "$identity_value" ]; then
        release_identity_present=true
    fi
done

if [ "$verification_only" = "true" ]; then
    if ! printf '%s' "$release_commit" | grep -Eq '^[0-9a-f]{40}$'; then
        fail "Exact release source commit required" "Verification-only recovery requires RELEASE_COMMIT to be a full lowercase Git commit SHA." "exact_release_commit_missing"
    fi

    verify_published_metadata=true
elif [ "$release_identity_present" = "true" ]; then
    if [ -z "$release_commit" ] \
        || [ -z "$run_id" ] \
        || [ -z "$run_attempt" ] \
        || [ -z "$workflow_package_source" ] \
        || [ -z "$workflow_package_ref" ] \
        || [ -z "$workflow_package_commit" ]; then
        fail "Exact release image identity incomplete" "Release commit, run identity, and the complete locked Workflow package source, version, and commit are required together." "exact_release_identity_incomplete"
    fi

    verify_published_metadata=true
fi

direct_build_digest="$(extract_sha256_digest "$built_image_digest")"
metadata_build_digest="$(extract_metadata_image_digest "$built_image_metadata")"

if [ -n "$direct_build_digest" ] && [ -n "$metadata_build_digest" ] && [ "$direct_build_digest" != "$metadata_build_digest" ]; then
    fail "Exact release image build metadata mismatch" "The Docker build step reported digest ${direct_build_digest}, but its metadata reported ${metadata_build_digest}. Cannot verify public exact image tags against an inconsistent build result." "exact_build_metadata_digest_mismatch"
fi

if [ -n "$direct_build_digest" ]; then
    expected_image_digest="$direct_build_digest"
else
    expected_image_digest="$metadata_build_digest"
fi

if [ -z "$expected_image_digest" ]; then
    if [ "$verify_published_metadata" != "true" ]; then
        fail "Exact release image build digest required" "Cannot verify exact image publication because the Docker build step did not expose a digest or containerimage.digest metadata, and release run metadata is incomplete for public exact image tag verification." "exact_build_metadata_digest_missing"
    fi
fi

inspect_ref() {
    ref="$1"
    safe_ref="$(printf '%s' "$ref" | sed 's/[^A-Za-z0-9_.-]/_/g')"
    inspect_path="${tmp_dir}/${safe_ref}.inspect"
    error_path="${tmp_dir}/${safe_ref}.err"

    if ! "$docker_bin" buildx imagetools inspect "$ref" > "$inspect_path" 2> "$error_path"; then
        error_detail="$(cat "$error_path" 2>/dev/null || true)"
        fail "Exact release image manifest missing" "Cannot inspect published exact image manifest ${ref}. ${error_detail}" "exact_manifest_missing"
    fi

    missing_platforms=""
    for platform in $platform_list; do
        if ! grep -Eq "Platform:[[:space:]]*${platform}([[:space:]]|$)" "$inspect_path"; then
            missing_platforms="$(append_line "$missing_platforms" "$platform")"
        fi
    done

    if [ -n "$missing_platforms" ]; then
        fail "Exact release image platform missing" "Published exact image manifest ${ref} does not include required platform(s): $(printf '%s' "$missing_platforms" | tr '\n' ' ')." "exact_manifest_platform_missing"
    fi

    digest="$(sed -n 's/^[[:space:]]*Digest:[[:space:]]*//p' "$inspect_path" | head -n 1 | tr 'A-F' 'a-f')"
    case "$digest" in
        sha256:*) ;;
        *)
            fail "Exact release image digest missing" "Cannot read the top-level manifest digest for published exact image manifest ${ref}." "exact_manifest_digest_missing"
            ;;
    esac

    if [ -z "$registry_image_digest" ]; then
        registry_image_digest="$digest"
    elif [ "$registry_image_digest" != "$digest" ]; then
        fail "Exact release image digest mismatch" "Published exact image manifests are not identical across registries: ${ref} has ${digest}, but another configured registry has ${registry_image_digest}." "exact_manifest_digest_mismatch"
    fi

    if [ -n "$expected_image_digest" ] && [ "$expected_image_digest" != "$digest" ]; then
        fail "Exact release image build digest mismatch" "Published exact image manifest ${ref} has digest ${digest}, but this release build produced ${expected_image_digest}." "exact_manifest_build_digest_mismatch"
    fi

    if [ "$verify_published_metadata" = "true" ]; then
        verify_ref_metadata "$ref" "$safe_ref"
    fi

    image_digest="$digest"

    verified_refs="$(append_line "$verified_refs" "$ref")"
}

inspect_ref "${dockerhub_image}:${release_tag}"
inspect_ref "${ghcr_image}:${release_tag}"

identity_detail="match this release build digest"
if [ "$verification_only" = "true" ]; then
    identity_detail="carry the requested release tag and source commit and use the same manifest digest"
elif [ "$verify_published_metadata" = "true" ]; then
    identity_detail="carry this release run metadata and use the same manifest digest"
fi

reason=""
case "$build_outcome" in
    ""|success) ;;
    *)
        reason="exact_manifests_verified_after_build_step_${build_outcome}"
        if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
            {
                printf '## Exact image publication verified\n\n'
                printf 'The Docker build step reported `%s`, but exact image manifests in all configured registries %s and include the required platforms. Treating exact publication as successful.\n' "$build_outcome" "$identity_detail"
            } >> "$GITHUB_STEP_SUMMARY"
        fi
        printf 'Docker build step reported %s, but exact image manifests %s in all configured registries.\n' "$build_outcome" "$identity_detail"
        ;;
esac

write_output "exact_publish_outcome" "success"
write_output "exact_publish_reason" "$reason"
write_output "image_digest" "$image_digest"
write_multiline_output "verified_refs" "$verified_refs"

printf 'Verified exact image manifests for %s in %s and %s with required platforms: %s.\n' \
    "$release_tag" "$dockerhub_image" "$ghcr_image" "$platform_list"
