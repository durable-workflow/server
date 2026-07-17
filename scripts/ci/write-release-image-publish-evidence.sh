#!/usr/bin/env sh

set -eu

evidence_path="${RELEASE_IMAGE_EVIDENCE_PATH:-release-image-publish-evidence.json}"
release_tag="${RELEASE_TAG:-}"
dockerhub_image="${DOCKERHUB_IMAGE:-durableworkflow/server}"
ghcr_image="${GHCR_IMAGE:-ghcr.io/durable-workflow/server}"
validation_outcome="${VALIDATION_OUTCOME:-success}"
exact_publish_outcome="${EXACT_PUBLISH_OUTCOME:-skipped}"
exact_publish_reason="${EXACT_PUBLISH_REASON:-}"
exact_verify_outcome="${EXACT_VERIFY_OUTCOME:-skipped}"
docker_build_outcome="${DOCKER_BUILD_OUTCOME:-skipped}"
build_cache_identity="${BUILD_CACHE_IDENTITY:-}"
build_cache_ref="${BUILD_CACHE_REF:-}"
build_duration_seconds="${BUILD_DURATION_SECONDS:-}"
warm_cache_target_seconds="${WARM_CACHE_TARGET_SECONDS:-600}"
protocol_catalog_conformance_outcome="${PROTOCOL_CATALOG_CONFORMANCE_OUTCOME:-skipped}"
rolling_guard_outcome="${ROLLING_GUARD_OUTCOME:-skipped}"
rolling_promote_outcome="${ROLLING_PROMOTE_OUTCOME:-skipped}"
rolling_status="${ROLLING_ARTIFACT_STATUS:-}"
rolling_should_promote="${ROLLING_SHOULD_PROMOTE:-false}"
superseded_by="${ROLLING_SUPERSEDED_BY:-}"
image_digest="${IMAGE_DIGEST:-}"
release_commit="${RELEASE_COMMIT:-}"
run_id="${RELEASE_RUN_ID:-}"
run_attempt="${RELEASE_RUN_ATTEMPT:-}"
workflow_package_name="${WORKFLOW_PACKAGE_NAME:-durable-workflow/workflow}"
workflow_package_source="${WORKFLOW_PACKAGE_SOURCE:-https://github.com/durable-workflow/workflow.git}"
workflow_package_ref="${WORKFLOW_PACKAGE_REF:-}"
workflow_package_commit="${WORKFLOW_PACKAGE_COMMIT:-}"
required_platforms="${RELEASE_IMAGE_REQUIRED_PLATFORMS:-linux/amd64 linux/arm64}"
reason=""
rolling_reason=""

json_escape() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

json_string() {
    printf '"%s"' "$(json_escape "$1")"
}

json_string_or_null() {
    if [ -n "$1" ]; then
        json_string "$1"
    else
        printf 'null'
    fi
}

json_unsigned_integer_or_null() {
    value="$1"
    if [ -z "$value" ]; then
        printf 'null'
        return
    fi

    case "$value" in
        *[!0-9]*)
            printf 'Expected an unsigned integer, got %s.\n' "$value" >&2
            exit 1
            ;;
    esac

    printf '%s' "$value"
}

write_warm_cache_target_met() {
    if [ -z "$build_duration_seconds" ]; then
        printf 'null'
    elif [ "$build_duration_seconds" -le "$warm_cache_target_seconds" ]; then
        printf 'true'
    else
        printf 'false'
    fi
}

is_stable_semver_tag() {
    printf '%s' "$1" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'
}

write_string_array() {
    refs="$1"
    first="true"

    printf '['
    if [ -n "$refs" ]; then
        printf '%s\n' "$refs" | while IFS= read -r ref; do
            [ -n "$ref" ] || continue
            if [ "$first" = "true" ]; then
                first="false"
            else
                printf ', '
            fi
            json_string "$ref"
        done
    fi
    printf ']'
}

write_artifact_versions() {
    first="true"

    printf '{'

    if [ "$exact_publish_outcome" = "success" ] && [ -n "$release_tag" ]; then
        printf '"server": '
        json_string "${dockerhub_image}:${release_tag}"
        first="false"
    fi

    if [ "$exact_publish_outcome" = "success" ] && [ -n "$workflow_package_ref" ]; then
        if [ "$first" = "false" ]; then
            printf ', '
        fi
        printf '"workflow-php": '
        json_string "${workflow_package_name}:${workflow_package_ref}"
    fi

    printf '}'
}

write_platform_array() {
    first="true"

    printf '['
    for platform in $(printf '%s' "$required_platforms" | tr ',' ' '); do
        [ -n "$platform" ] || continue

        if [ "$first" = "true" ]; then
            first="false"
        else
            printf ', '
        fi

        json_string "$platform"
    done
    printf ']'
}

status="$rolling_status"

if [ "$validation_outcome" != "success" ]; then
    status="failed"
    reason="release_publish_validation_${validation_outcome}"
elif [ "$exact_publish_outcome" != "success" ]; then
    status="failed"
    if [ -n "$exact_publish_reason" ]; then
        reason="$exact_publish_reason"
    else
        reason="exact_image_publish_${exact_publish_outcome}"
    fi
elif [ "$protocol_catalog_conformance_outcome" = "failure" ] || [ "$protocol_catalog_conformance_outcome" = "cancelled" ]; then
    status="failed"
    reason="protocol_catalog_conformance_${protocol_catalog_conformance_outcome}"
elif [ "$rolling_guard_outcome" = "failure" ] || [ "$rolling_guard_outcome" = "cancelled" ]; then
    status="failed"
    reason="rolling_alias_guard_${rolling_guard_outcome}"
elif [ "$rolling_should_promote" = "true" ] && [ "$rolling_promote_outcome" != "success" ]; then
    status="failed"
    reason="rolling_alias_promotion_${rolling_promote_outcome}"
elif [ -z "$status" ]; then
    status="current"
fi

expected_exact_refs=""
if [ -n "$release_tag" ]; then
    expected_exact_refs="$(printf '%s:%s\n%s:%s' "$dockerhub_image" "$release_tag" "$ghcr_image" "$release_tag")"
fi

exact_refs=""
if [ "$exact_publish_outcome" = "success" ]; then
    exact_refs="$expected_exact_refs"
fi

rolling_refs=""
if is_stable_semver_tag "$release_tag"; then
    major="${release_tag%%.*}"
    minor_patch="${release_tag#*.}"
    minor="${minor_patch%%.*}"
    minor_alias="${major}.${minor}"
    major_alias="$major"

    rolling_refs="$(printf '%s:%s\n%s:%s\n%s:%s\n%s:%s\n%s:%s\n%s:%s' \
        "$dockerhub_image" "$minor_alias" \
        "$dockerhub_image" "$major_alias" \
        "$dockerhub_image" "latest" \
        "$ghcr_image" "$minor_alias" \
        "$ghcr_image" "$major_alias" \
        "$ghcr_image" "latest")"
fi

if ! is_stable_semver_tag "$release_tag"; then
    rolling_reason="not_stable_semver_tag"
elif [ "$exact_publish_outcome" != "success" ]; then
    rolling_reason="exact_image_publish_not_verified"
elif [ "$protocol_catalog_conformance_outcome" != "success" ] && [ "$protocol_catalog_conformance_outcome" != "skipped" ]; then
    rolling_reason="protocol_catalog_conformance_${protocol_catalog_conformance_outcome}"
elif [ "$rolling_guard_outcome" = "failure" ] || [ "$rolling_guard_outcome" = "cancelled" ]; then
    rolling_reason="rolling_alias_guard_${rolling_guard_outcome}"
elif [ "$rolling_should_promote" = "true" ] && [ "$rolling_promote_outcome" != "success" ]; then
    rolling_reason="rolling_alias_promotion_${rolling_promote_outcome}"
elif [ "$status" = "superseded" ]; then
    rolling_reason="superseded_by_newer_release"
elif [ "$rolling_should_promote" != "true" ]; then
    rolling_reason="rolling_alias_promotion_not_requested"
fi

{
    printf '{\n'
    printf '  "schema": "durable-workflow.release-image-publish-evidence.v1",\n'
    printf '  "status": '; json_string "$status"; printf ',\n'
    printf '  "status_values": ["pending", "current", "superseded", "failed"],\n'
    printf '  "reason": '; json_string_or_null "$reason"; printf ',\n'
    printf '  "tag": '; json_string_or_null "$release_tag"; printf ',\n'
    printf '  "commit": '; json_string_or_null "$release_commit"; printf ',\n'
    printf '  "run_id": '; json_string_or_null "$run_id"; printf ',\n'
    printf '  "run_attempt": '; json_string_or_null "$run_attempt"; printf ',\n'
    printf '  "artifact_versions": '; write_artifact_versions; printf ',\n'
    printf '  "workflow_package": {\n'
    printf '    "name": '; json_string "$workflow_package_name"; printf ',\n'
    printf '    "source": '; json_string_or_null "$workflow_package_source"; printf ',\n'
    printf '    "version": '; json_string_or_null "$workflow_package_ref"; printf ',\n'
    printf '    "commit": '; json_string_or_null "$workflow_package_commit"; printf '\n'
    printf '  },\n'
    printf '  "expected_exact_refs": '; write_string_array "$expected_exact_refs"; printf ',\n'
    printf '  "exact_refs": '; write_string_array "$exact_refs"; printf ',\n'
    printf '  "digest": '; json_string_or_null "$image_digest"; printf ',\n'
    printf '  "exact_publish": {\n'
    printf '    "outcome": '; json_string "$exact_publish_outcome"; printf ',\n'
    printf '    "reason": '; json_string_or_null "$exact_publish_reason"; printf ',\n'
    printf '    "build_step_outcome": '; json_string "$docker_build_outcome"; printf ',\n'
    printf '    "verification_outcome": '; json_string "$exact_verify_outcome"; printf ',\n'
    printf '    "required_platforms": '; write_platform_array; printf ',\n'
    printf '    "cache": {\n'
    printf '      "identity": '; json_string_or_null "$build_cache_identity"; printf ',\n'
    printf '      "ref": '; json_string_or_null "$build_cache_ref"; printf '\n'
    printf '    },\n'
    printf '    "timing": {\n'
    printf '      "duration_seconds": '; json_unsigned_integer_or_null "$build_duration_seconds"; printf ',\n'
    printf '      "warm_cache_target_seconds": '; json_unsigned_integer_or_null "$warm_cache_target_seconds"; printf ',\n'
    printf '      "warm_cache_target_met": '; write_warm_cache_target_met; printf '\n'
    printf '    }\n'
    printf '  },\n'
    printf '  "protocol_catalog_conformance": {\n'
    printf '    "outcome": '; json_string "$protocol_catalog_conformance_outcome"; printf ',\n'
    printf '    "evidence": "release-protocol-catalog-conformance.json"\n'
    printf '  },\n'
    printf '  "rolling": {\n'
    printf '    "eligible": %s,\n' "$(is_stable_semver_tag "$release_tag" && printf 'true' || printf 'false')"
    printf '    "should_promote": %s,\n' "$([ "$rolling_should_promote" = "true" ] && printf 'true' || printf 'false')"
    printf '    "promotion_outcome": '; json_string_or_null "$rolling_promote_outcome"; printf ',\n'
    printf '    "reason": '; json_string_or_null "$rolling_reason"; printf ',\n'
    printf '    "superseded_by": '; json_string_or_null "$superseded_by"; printf ',\n'
    printf '    "refs": '; if [ "$status" = "current" ] && [ "$rolling_should_promote" = "true" ] && [ "$rolling_promote_outcome" = "success" ]; then write_string_array "$rolling_refs"; else write_string_array ""; fi; printf ',\n'
    printf '    "skipped_refs": '; if [ "$status" = "superseded" ]; then write_string_array "$rolling_refs"; else write_string_array ""; fi; printf '\n'
    printf '  }\n'
    printf '}\n'
} > "$evidence_path"

printf 'Wrote release image publish evidence to %s with status=%s.\n' "$evidence_path" "$status"
