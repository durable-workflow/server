#!/usr/bin/env sh

set -eu

release_tag="${RELEASE_TAG:-}"
server_image="${SERVER_IMAGE:-${DOCKERHUB_IMAGE:-durableworkflow/server}:${release_tag}}"
workflow_package_ref="${WORKFLOW_PACKAGE_REF:-}"
workflow_package_commit="${WORKFLOW_PACKAGE_COMMIT:-}"
workflow_package_source="${WORKFLOW_PACKAGE_SOURCE:-}"
public_catalog_url="${PUBLIC_CATALOG_URL:-https://durable-workflow.github.io/platform-protocol-specs.json}"
evidence_path="${PROTOCOL_CATALOG_CONFORMANCE_EVIDENCE:-release-protocol-catalog-conformance.json}"
docker_bin="${DOCKER:-docker}"
curl_bin="${CURL:-curl}"
node_bin="${NODE:-node}"
timeout_bin="${TIMEOUT:-timeout}"
port="${RELEASE_PROTOCOL_CATALOG_PORT:-18080}"
attempts="${RELEASE_PROTOCOL_CATALOG_ATTEMPTS:-30}"
retry_sleep="${RELEASE_PROTOCOL_CATALOG_RETRY_SLEEP:-2}"
bootstrap_timeout="${RELEASE_PROTOCOL_CATALOG_BOOTSTRAP_TIMEOUT:-180}"
bootstrap_log="${PROTOCOL_CATALOG_BOOTSTRAP_LOG:-release-protocol-catalog-bootstrap.log}"
server_log="${PROTOCOL_CATALOG_SERVER_LOG:-release-protocol-catalog-server.log}"
tmp_root="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"
tmp_dir="$(mktemp -d "${tmp_root}/release-protocol-catalog.XXXXXX")"
container_name="${RELEASE_PROTOCOL_CATALOG_SCOPE:-release-protocol-catalog-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-publish}-$$}"
bootstrap_container_name="${container_name}-bootstrap"
volume_name="${container_name}-database"
container_started=false
volume_created=false
failure_stage="setup"

capture_server_log() {
    : > "$server_log"
    if [ "$container_started" = "true" ]; then
        "$docker_bin" logs "$container_name" > "$server_log" 2>&1 || true
    fi
}

cleanup() {
    if [ "$container_started" = "true" ]; then
        capture_server_log
        "$docker_bin" rm -f "$container_name" >/dev/null 2>&1 || true
    fi
    if [ "$volume_created" = "true" ]; then
        "$docker_bin" volume rm -f "$volume_name" >/dev/null 2>&1 || true
    fi
    rm -rf "$tmp_dir"
}

trap cleanup EXIT HUP INT TERM

fail() {
    reason="$1"
    message="$2"

    if [ -n "${GITHUB_OUTPUT:-}" ]; then
        printf 'protocol_catalog_conformance_outcome=failure\n' >> "$GITHUB_OUTPUT"
    fi

    PROTOCOL_CATALOG_FAILURE_REASON="$reason" \
    PROTOCOL_CATALOG_FAILURE_MESSAGE="$message" \
    PROTOCOL_CATALOG_FAILURE_STAGE="$failure_stage" \
    PROTOCOL_CATALOG_CONFORMANCE_EVIDENCE="$evidence_path" \
    PROTOCOL_CATALOG_BOOTSTRAP_LOG="$bootstrap_log" \
    PROTOCOL_CATALOG_SERVER_LOG="$server_log" \
    RELEASE_TAG="$release_tag" \
    SERVER_IMAGE="$server_image" \
    PUBLIC_CATALOG_URL="$public_catalog_url" \
    WORKFLOW_PACKAGE_SOURCE="$workflow_package_source" \
    WORKFLOW_PACKAGE_REF="$workflow_package_ref" \
    WORKFLOW_PACKAGE_COMMIT="$workflow_package_commit" \
    "$node_bin" <<'NODE'
const fs = require('fs');

function tail(path, lines = 40) {
  if (!path || !fs.existsSync(path)) {
    return [];
  }

  const source = fs.readFileSync(path, 'utf8').trimEnd();
  return source === '' ? [] : source.split('\n').slice(-lines);
}

fs.writeFileSync(process.env.PROTOCOL_CATALOG_CONFORMANCE_EVIDENCE, `${JSON.stringify({
  schema: 'durable-workflow.server.release-protocol-catalog-conformance',
  schema_version: 1,
  checked_at: new Date().toISOString(),
  release_tag: process.env.RELEASE_TAG || null,
  server_image: process.env.SERVER_IMAGE || null,
  public_catalog_url: process.env.PUBLIC_CATALOG_URL || null,
  expected_workflow_package: {
    name: 'durable-workflow/workflow',
    source: process.env.WORKFLOW_PACKAGE_SOURCE || null,
    version: process.env.WORKFLOW_PACKAGE_REF || null,
    commit: process.env.WORKFLOW_PACKAGE_COMMIT || null,
  },
  outcome: 'fail',
  lifecycle: {
    failed_stage: process.env.PROTOCOL_CATALOG_FAILURE_STAGE || null,
  },
  diagnostics: {
    bootstrap_log: {
      artifact: process.env.PROTOCOL_CATALOG_BOOTSTRAP_LOG || null,
      tail: tail(process.env.PROTOCOL_CATALOG_BOOTSTRAP_LOG),
    },
    server_log: {
      artifact: process.env.PROTOCOL_CATALOG_SERVER_LOG || null,
      tail: tail(process.env.PROTOCOL_CATALOG_SERVER_LOG),
    },
  },
  findings: [{
    kind: process.env.PROTOCOL_CATALOG_FAILURE_REASON,
    message: process.env.PROTOCOL_CATALOG_FAILURE_MESSAGE,
  }],
}, null, 2)}\n`);
NODE

    printf '::error title=Published protocol catalog conformance failed::%s\n' "$message" >&2
    printf '%s\n' "$message" >&2
    exit 1
}

[ -n "$release_tag" ] || fail "release_tag_missing" "RELEASE_TAG is required for published protocol catalog conformance."
[ -n "$workflow_package_source" ] || fail "workflow_package_source_missing" "WORKFLOW_PACKAGE_SOURCE is required for published protocol catalog conformance."
[ -n "$workflow_package_ref" ] || fail "workflow_package_ref_missing" "WORKFLOW_PACKAGE_REF is required for published protocol catalog conformance."
[ -n "$workflow_package_commit" ] || fail "workflow_package_commit_missing" "WORKFLOW_PACKAGE_COMMIT is required for published protocol catalog conformance."

case "$attempts" in
    ''|*[!0-9]*) fail "invalid_attempt_count" "RELEASE_PROTOCOL_CATALOG_ATTEMPTS must be a positive integer." ;;
esac
case "$retry_sleep" in
    ''|*[!0-9]*) fail "invalid_retry_delay" "RELEASE_PROTOCOL_CATALOG_RETRY_SLEEP must be a non-negative integer." ;;
esac
case "$bootstrap_timeout" in
    ''|*[!0-9]*) fail "invalid_bootstrap_timeout" "RELEASE_PROTOCOL_CATALOG_BOOTSTRAP_TIMEOUT must be a positive integer." ;;
esac
[ "$attempts" -ge 1 ] || fail "invalid_attempt_count" "RELEASE_PROTOCOL_CATALOG_ATTEMPTS must be at least 1."
[ "$bootstrap_timeout" -ge 1 ] || fail "invalid_bootstrap_timeout" "RELEASE_PROTOCOL_CATALOG_BOOTSTRAP_TIMEOUT must be at least 1."

failure_stage="image_pull"
if ! "$docker_bin" pull "$server_image" >"${tmp_dir}/docker-pull.log" 2>&1; then
    detail="$(tail -n 20 "${tmp_dir}/docker-pull.log" 2>/dev/null || true)"
    fail "published_image_pull_failed" "Could not pull published server image ${server_image}. ${detail}"
fi

failure_stage="storage_create"
if ! "$docker_bin" volume create "$volume_name" >"${tmp_dir}/docker-volume-create.log" 2>&1; then
    detail="$(tail -n 20 "${tmp_dir}/docker-volume-create.log" 2>/dev/null || true)"
    fail "sqlite_storage_create_failed" "Could not create isolated SQLite storage for ${server_image}. ${detail}"
fi
volume_created=true

failure_stage="server_bootstrap"
bootstrap_exit=0
"$timeout_bin" "${bootstrap_timeout}s" \
    "$docker_bin" run --rm \
    --name "$bootstrap_container_name" \
    --volume "${volume_name}:/app/database" \
    --env DW_AUTH_DRIVER=none \
    "$server_image" server-bootstrap >"$bootstrap_log" 2>&1 || bootstrap_exit=$?
"$docker_bin" rm -f "$bootstrap_container_name" >/dev/null 2>&1 || true

if [ "$bootstrap_exit" -ne 0 ]; then
    detail="$(tail -n 40 "$bootstrap_log" 2>/dev/null || true)"
    if [ "$bootstrap_exit" -eq 124 ]; then
        fail "server_bootstrap_timed_out" "Published server image ${server_image} did not complete server-bootstrap within ${bootstrap_timeout}s. ${detail}"
    fi
    fail "server_bootstrap_failed" "Published server image ${server_image} failed server-bootstrap with exit code ${bootstrap_exit}. ${detail}"
fi

failure_stage="server_start"
publish_argument="127.0.0.1:${port}:8080"
dynamic_port=false
if [ "$port" = "0" ]; then
    publish_argument="127.0.0.1::8080"
    dynamic_port=true
fi
if ! "$docker_bin" run --detach --rm \
    --name "$container_name" \
    --publish "$publish_argument" \
    --volume "${volume_name}:/app/database" \
    --env DW_AUTH_DRIVER=none \
    --env DW_EXPOSE_PACKAGE_PROVENANCE=1 \
    "$server_image" >"${tmp_dir}/container-id" 2>"${tmp_dir}/docker-run.log"; then
    cp "${tmp_dir}/docker-run.log" "$server_log"
    detail="$(cat "${tmp_dir}/docker-run.log" 2>/dev/null || true)"
    fail "published_image_start_failed" "Could not start published server image ${server_image}. ${detail}"
fi
container_started=true
if [ "$dynamic_port" = "true" ]; then
    port="$("$docker_bin" port "$container_name" 8080/tcp | head -n 1 | awk -F: '{print $NF}')"
    if [ -z "$port" ]; then
        fail "published_port_discovery_failed" "Could not discover the published server port for ${server_image}."
    fi
fi

failure_stage="server_discovery"
server_discovery_path="${tmp_dir}/server-discovery.json"
attempt=1
while [ "$attempt" -le "$attempts" ]; do
    if "$curl_bin" --fail --silent --show-error --max-time 10 \
        --output "$server_discovery_path" \
        "http://127.0.0.1:${port}/api/cluster/info"; then
        break
    fi

    if [ "$attempt" -eq "$attempts" ]; then
        capture_server_log
        logs="$(tail -n 40 "$server_log" 2>/dev/null || true)"
        fail "server_discovery_unavailable" "Published server image ${server_image} did not return /api/cluster/info after ${attempts} attempts. ${logs}"
    fi

    attempt=$((attempt + 1))
    sleep "$retry_sleep"
done

failure_stage="public_catalog_fetch"
public_catalog_path="${tmp_dir}/public-platform-protocol-specs.json"
if ! "$curl_bin" --fail --silent --show-error --location --retry 3 \
    --connect-timeout 10 --max-time 30 \
    --output "$public_catalog_path" "$public_catalog_url"; then
    fail "public_catalog_unavailable" "Could not fetch the public protocol catalog from ${public_catalog_url}."
fi

failure_stage="catalog_comparison"
SERVER_DISCOVERY_PATH="$server_discovery_path" \
PUBLIC_CATALOG_PATH="$public_catalog_path" \
PROTOCOL_CATALOG_CONFORMANCE_EVIDENCE="$evidence_path" \
PROTOCOL_CATALOG_BOOTSTRAP_LOG="$bootstrap_log" \
PROTOCOL_CATALOG_SERVER_LOG="$server_log" \
PROTOCOL_CATALOG_STORAGE_KIND="isolated_docker_volume" \
PROTOCOL_CATALOG_BOOTSTRAP_OUTCOME="pass" \
PROTOCOL_CATALOG_DISCOVERY_OUTCOME="pass" \
RELEASE_TAG="$release_tag" \
SERVER_IMAGE="$server_image" \
PUBLIC_CATALOG_URL="$public_catalog_url" \
WORKFLOW_PACKAGE_SOURCE="$workflow_package_source" \
WORKFLOW_PACKAGE_REF="$workflow_package_ref" \
WORKFLOW_PACKAGE_COMMIT="$workflow_package_commit" \
"$node_bin" scripts/ci/verify-release-protocol-catalog.mjs
