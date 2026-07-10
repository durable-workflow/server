#!/usr/bin/env bash
set -euo pipefail

manifest_path="${RUST_SDK_MANIFEST_PATH:-sdk-rust/Cargo.toml}"
evidence_path="${RUST_SDK_RELEASE_EVIDENCE_PATH:-rust-sdk-release-evidence.json}"
release_tag="${RELEASE_TAG:-}"
release_commit="${RELEASE_COMMIT:-}"
release_run_id="${RELEASE_RUN_ID:-}"
release_run_attempt="${RELEASE_RUN_ATTEMPT:-}"

for command in cargo curl diff jq tar; do
    if ! command -v "$command" >/dev/null 2>&1; then
        printf 'required command not found: %s\n' "$command" >&2
        exit 1
    fi
done

if [[ ! -f "$manifest_path" ]]; then
    printf 'Rust SDK manifest not found: %s\n' "$manifest_path" >&2
    exit 1
fi

if command -v git >/dev/null 2>&1 && [[ -n "$(git status --porcelain --untracked-files=all)" ]]; then
    printf 'Rust SDK publication requires a clean release checkout\n' >&2
    exit 1
fi

metadata="$(cargo metadata --manifest-path "$manifest_path" --no-deps --format-version 1)"
package_name="$(jq -er '.packages[0].name' <<<"$metadata")"
package_version="$(jq -er '.packages[0].version' <<<"$metadata")"
package_repository="$(jq -er '.packages[0].repository' <<<"$metadata")"
server_compatibility="$(jq -er '.packages[0].metadata["durable-workflow"]["supported-server-versions"]' <<<"$metadata")"
worker_protocol="$(jq -er '.packages[0].metadata["durable-workflow"]["worker-protocol-version"]' <<<"$metadata")"
control_plane="$(jq -er '.packages[0].metadata["durable-workflow"]["control-plane-version"]' <<<"$metadata")"

if [[ "$package_name" != "durable-workflow" ]]; then
    printf 'unexpected Rust SDK package name: %s\n' "$package_name" >&2
    exit 1
fi
if [[ ! "$package_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$ ]]; then
    printf 'Rust SDK package version must be exact: %s\n' "$package_version" >&2
    exit 1
fi
if [[ "$package_repository" != "https://github.com/durable-workflow/server" ]]; then
    printf 'unexpected Rust SDK repository metadata: %s\n' "$package_repository" >&2
    exit 1
fi
if [[ ! "$release_tag" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$ ]]; then
    printf 'RELEASE_TAG must identify an exact server release: %s\n' "${release_tag:-<empty>}" >&2
    exit 1
fi

registry_api="https://crates.io/api/v1/crates/${package_name}"
version_api="${registry_api}/${package_version}"
download_url="${version_api}/download"
user_agent="durable-workflow-release/${package_version} (support@durable-workflow.com)"
tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT
version_response="$tmp_dir/version.json"
crate_response="$tmp_dir/crate.json"

write_evidence() {
    local outcome="$1"
    local reason="$2"
    local checksum="${3:-}"
    local published_at="${4:-}"
    local registry_verified="${5:-false}"

    jq -n \
        --arg schema "durable-workflow.rust-sdk.release-evidence" \
        --arg generated_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        --arg package "$package_name" \
        --arg version "$package_version" \
        --arg source "crates.io://${package_name}@${package_version}" \
        --arg download_url "$download_url" \
        --arg checksum "$checksum" \
        --arg published_at "$published_at" \
        --arg repository "$package_repository" \
        --arg server_compatibility "$server_compatibility" \
        --arg worker_protocol "$worker_protocol" \
        --arg control_plane "$control_plane" \
        --arg release_tag "$release_tag" \
        --arg release_commit "$release_commit" \
        --arg release_run_id "$release_run_id" \
        --arg release_run_attempt "$release_run_attempt" \
        --arg outcome "$outcome" \
        --arg reason "$reason" \
        --arg registry_verified "$registry_verified" \
        '{
            schema: $schema,
            version: 1,
            generated_at: $generated_at,
            package: $package,
            package_version: $version,
            package_source: $source,
            package_download_url: $download_url,
            package_checksum_sha256: $checksum,
            published_at: $published_at,
            repository: $repository,
            supported_server_versions: $server_compatibility,
            protocol_compatibility: {
                worker_protocol: $worker_protocol,
                control_plane: $control_plane
            },
            release: {
                server_tag: $release_tag,
                commit: $release_commit,
                run_id: $release_run_id,
                run_attempt: $release_run_attempt
            },
            outcome: $outcome,
            reason: $reason,
            registry_verified: ($registry_verified == "true"),
            source_archive_matches_release_checkout: ($registry_verified == "true")
        }' > "$evidence_path"
}

write_evidence "pending" "checking_public_registry"

if ! http_status="$(curl --silent --show-error --location \
    --header "User-Agent: ${user_agent}" \
    --output "$version_response" \
    --write-out '%{http_code}' \
    "$version_api")"; then
    write_evidence "failed" "registry_version_lookup_transport_failure"
    printf 'could not reach crates.io version API\n' >&2
    exit 1
fi

case "$http_status" in
    200)
        publish_outcome="already_published"
        publish_reason="exact_public_version_already_exists"
        ;;
    404)
        if [[ -z "${CARGO_REGISTRY_TOKEN:-}" ]]; then
            write_evidence "failed" "registry_token_missing"
            printf 'CARGO_REGISTRY_TOKEN is required to publish %s %s\n' "$package_name" "$package_version" >&2
            exit 1
        fi
        # cargo metadata may generate a library lockfile in the clean tag checkout.
        # The preflight above guarantees that this is the only allowed dirtiness.
        if ! cargo publish --manifest-path "$manifest_path" --registry crates-io --allow-dirty; then
            write_evidence "failed" "cargo_publish_failed"
            exit 1
        fi
        publish_outcome="published"
        publish_reason="exact_public_version_published"
        for _attempt in $(seq 1 24); do
            http_status="$(curl --silent --show-error --location \
                --header "User-Agent: ${user_agent}" \
                --output "$version_response" \
                --write-out '%{http_code}' \
                "$version_api" || true)"
            [[ "$http_status" == "200" ]] && break
            sleep 5
        done
        if [[ "$http_status" != "200" ]]; then
            write_evidence "failed" "published_version_not_visible_after_registry_deadline"
            printf 'published Rust SDK version did not become visible at %s\n' "$version_api" >&2
            exit 1
        fi
        ;;
    *)
        write_evidence "failed" "registry_version_lookup_http_${http_status}"
        printf 'crates.io version lookup failed with HTTP %s\n' "$http_status" >&2
        exit 1
        ;;
esac

if ! curl --fail --silent --show-error --location \
    --header "User-Agent: ${user_agent}" \
    --output "$crate_response" \
    "$registry_api"; then
    write_evidence "failed" "registry_package_metadata_lookup_failed"
    exit 1
fi

published_repository="$(jq -er '.crate.repository' "$crate_response")"
published_version="$(jq -er '.version.num' "$version_response")"
published_checksum="$(jq -er '.version.checksum' "$version_response")"
published_at="$(jq -er '.version.created_at' "$version_response")"

if [[ "$published_repository" != "$package_repository" ]]; then
    write_evidence "failed" "published_repository_provenance_mismatch" "$published_checksum" "$published_at"
    printf 'published crate repository mismatch: expected %s, got %s\n' "$package_repository" "$published_repository" >&2
    exit 1
fi
if [[ "$published_version" != "$package_version" ]]; then
    write_evidence "failed" "published_version_mismatch" "$published_checksum" "$published_at"
    printf 'published crate version mismatch: expected %s, got %s\n' "$package_version" "$published_version" >&2
    exit 1
fi
if [[ ! "$published_checksum" =~ ^[0-9a-f]{64}$ ]]; then
    write_evidence "failed" "published_checksum_missing" "$published_checksum" "$published_at"
    printf 'published crate checksum is missing or invalid\n' >&2
    exit 1
fi

if ! cargo package --manifest-path "$manifest_path" --allow-dirty --no-verify; then
    write_evidence "failed" "local_package_archive_build_failed" "$published_checksum" "$published_at"
    exit 1
fi
manifest_dir="$(cd "$(dirname "$manifest_path")" && pwd)"
local_archive="${manifest_dir}/target/package/${package_name}-${package_version}.crate"
if [[ ! -f "$local_archive" ]]; then
    write_evidence "failed" "local_package_archive_missing" "$published_checksum" "$published_at"
    printf 'local Cargo package archive was not created: %s\n' "$local_archive" >&2
    exit 1
fi
if ! curl --fail --silent --show-error --location \
    --header "User-Agent: ${user_agent}" \
    --output "$tmp_dir/published.crate" \
    "$download_url"; then
    write_evidence "failed" "published_package_archive_download_failed" "$published_checksum" "$published_at"
    exit 1
fi
mkdir -p "$tmp_dir/local" "$tmp_dir/published"
tar -xzf "$local_archive" -C "$tmp_dir/local"
tar -xzf "$tmp_dir/published.crate" -C "$tmp_dir/published"
if ! diff -ru \
    --exclude='.cargo_vcs_info.json' \
    --exclude='Cargo.lock' \
    --exclude='Cargo.toml' \
    "$tmp_dir/local/${package_name}-${package_version}" \
    "$tmp_dir/published/${package_name}-${package_version}" \
    > "$tmp_dir/archive.diff"; then
    write_evidence "failed" "published_source_archive_mismatch" "$published_checksum" "$published_at"
    printf 'published Rust SDK source archive differs from the release checkout\n' >&2
    sed -n '1,120p' "$tmp_dir/archive.diff" >&2
    exit 1
fi

write_evidence "$publish_outcome" "$publish_reason" "$published_checksum" "$published_at" "true"
printf 'Rust SDK %s %s is available from crates.io (%s).\n' "$package_name" "$package_version" "$publish_outcome"
