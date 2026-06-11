#!/usr/bin/env sh

set -eu

evidence_path="${RELEASE_IMAGE_EVIDENCE_PATH:-release-image-publish-evidence.json}"
release_tag="${RELEASE_TAG:-}"
dockerhub_image="${DOCKERHUB_IMAGE:-durableworkflow/server}"
ghcr_image="${GHCR_IMAGE:-ghcr.io/durable-workflow/server}"
validation_outcome="${VALIDATION_OUTCOME:-success}"
exact_publish_outcome="${EXACT_PUBLISH_OUTCOME:-skipped}"
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
reason=""

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
    printf '{"server": '
    json_string "${dockerhub_image}:${release_tag}"

    if [ -n "$workflow_package_ref" ]; then
        printf ', "workflow-php": '
        json_string "${workflow_package_name}:${workflow_package_ref}"
    fi

    printf '}'
}

status="$rolling_status"

if [ "$validation_outcome" != "success" ]; then
    status="failed"
    reason="release_publish_validation_${validation_outcome}"
elif [ "$exact_publish_outcome" != "success" ]; then
    status="failed"
    reason="exact_image_publish_${exact_publish_outcome}"
elif [ "$rolling_guard_outcome" = "failure" ] || [ "$rolling_guard_outcome" = "cancelled" ]; then
    status="failed"
    reason="rolling_alias_guard_${rolling_guard_outcome}"
elif [ "$rolling_should_promote" = "true" ] && [ "$rolling_promote_outcome" != "success" ]; then
    status="failed"
    reason="rolling_alias_promotion_${rolling_promote_outcome}"
elif [ -z "$status" ]; then
    status="current"
fi

exact_refs=""
if [ -n "$release_tag" ]; then
    exact_refs="$(printf '%s:%s\n%s:%s' "$dockerhub_image" "$release_tag" "$ghcr_image" "$release_tag")"
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
    printf '  "exact_refs": '; write_string_array "$exact_refs"; printf ',\n'
    printf '  "digest": '; json_string_or_null "$image_digest"; printf ',\n'
    printf '  "rolling": {\n'
    printf '    "eligible": %s,\n' "$(is_stable_semver_tag "$release_tag" && printf 'true' || printf 'false')"
    printf '    "should_promote": %s,\n' "$([ "$rolling_should_promote" = "true" ] && printf 'true' || printf 'false')"
    printf '    "promotion_outcome": '; json_string_or_null "$rolling_promote_outcome"; printf ',\n'
    printf '    "superseded_by": '; json_string_or_null "$superseded_by"; printf ',\n'
    printf '    "refs": '; if [ "$status" = "current" ]; then write_string_array "$rolling_refs"; else write_string_array ""; fi; printf ',\n'
    printf '    "skipped_refs": '; if [ "$status" = "superseded" ]; then write_string_array "$rolling_refs"; else write_string_array ""; fi; printf '\n'
    printf '  }\n'
    printf '}\n'
} > "$evidence_path"

printf 'Wrote release image publish evidence to %s with status=%s.\n' "$evidence_path" "$status"
