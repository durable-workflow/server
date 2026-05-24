#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: sagas-published-artifacts.sh [--result-dir DIR] [--keep-run-root]

Runs the public saga runtime contract against published artifacts only.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  sagas-result.json
  sagas-record.json

Environment overrides:
  DW_SAGAS_RUN_ROOT             Scratch directory. Defaults to mktemp.
  DW_SAGAS_RESULT_DIR           Result directory. Defaults to run root.
  DW_SAGAS_KEEP_RUN_ROOT=1      Keep scratch directory after success.
  DW_SERVER_IMAGE               Exact server image/tag/digest to test.
  DW_SERVER_VERSION             Exact patch server Docker tag; required for digest-only DW_SERVER_IMAGE.
  DW_WORKFLOW_PHP_VERSION       Composer version for durable-workflow/workflow.
  DW_PYTHON_SDK_VERSION         PyPI version for durable-workflow.
  DW_CLI_VERSION                GitHub release tag for the official CLI installer.
  DW_WATERLINE_VERSION          Composer version for durable-workflow/waterline.
  DW_SAGAS_SKIP_DOCKER_PULL=1   Reuse local image instead of pulling.
USAGE
}

keep_run_root="${DW_SAGAS_KEEP_RUN_ROOT:-0}"
result_dir="${DW_SAGAS_RESULT_DIR:-}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --result-dir)
      result_dir="${2:?--result-dir requires a value}"
      shift 2
      ;;
    --keep-run-root)
      keep_run_root=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      printf 'unknown argument: %s\n' "$1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

require_command() {
  local name="$1"

  if ! command -v "$name" >/dev/null 2>&1; then
    printf 'required command not found: %s\n' "$name" >&2
    return 1
  fi
}

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

run_root="${DW_SAGAS_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-sagas.XXXXXX")"
fi
mkdir -p "$run_root"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"

cleanup() {
  local code=$?

  if [[ -n "${python_worker_pid:-}" ]]; then
    kill "$python_worker_pid" >/dev/null 2>&1 || true
  fi
  docker rm -f dw-sagas-php-worker >/dev/null 2>&1 || true
  if [[ -f "$run_root/compose.yml" ]]; then
    docker compose -f "$run_root/compose.yml" down -v >/dev/null 2>&1 || true
  fi
  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" ]]; then
    rm -rf "$run_root"
  fi
}
trap cleanup EXIT

saga_required_scenario_ids=(
  "published_artifact_install_only"
  "forward_success_path"
  "failure_at_d_reverse_compensation"
  "failure_at_c_reverse_compensation"
  "failure_at_a_no_compensation"
  "compensation_retry_idempotence"
  "compensation_failure_visibility"
  "mid_compensation_worker_restart"
  "php_workflow_python_compensation"
  "python_workflow_php_compensation"
  "typed_compensation_error_round_trip"
  "operator_visible_mid_compensation_status"
)

scenario_required_fields() {
  local scenario_id="$1"

  case "$scenario_id" in
    published_artifact_install_only)
      printf '%s\n' \
        "resolved_artifact_versions" \
        "artifact_sources" \
        "local_product_source_checkouts_used"
      ;;
    forward_success_path)
      printf '%s\n' \
        "forward_rows" \
        "compensation_rows" \
        "workflow_status" \
        "history_dumps"
      ;;
    failure_at_d_reverse_compensation)
      printf '%s\n' \
        "forward_rows" \
        "compensation_rows" \
        "compensation_order" \
        "workflow_status" \
        "history_dumps"
      ;;
    failure_at_c_reverse_compensation)
      printf '%s\n' \
        "forward_rows" \
        "compensation_rows" \
        "compensation_order" \
        "send_confirmation_invocations" \
        "workflow_status"
      ;;
    failure_at_a_no_compensation)
      printf '%s\n' \
        "forward_rows" \
        "compensation_rows" \
        "workflow_status"
      ;;
    compensation_retry_idempotence)
      printf '%s\n' \
        "retry_attempts" \
        "business_effect_count" \
        "workflow_status"
      ;;
    compensation_failure_visibility)
      printf '%s\n' \
        "failed_compensation_step" \
        "terminal_failure_shape" \
        "operator_visible_reason" \
        "workflow_status"
      ;;
    mid_compensation_worker_restart)
      printf '%s\n' \
        "restart_timing" \
        "resumed_compensation_step" \
        "duplicate_compensation_counts" \
        "history_dumps"
      ;;
    php_workflow_python_compensation|python_workflow_php_compensation)
      printf '%s\n' \
        "workflow_runtime" \
        "compensation_runtime" \
        "compensation_order" \
        "typed_result_shapes"
      ;;
    typed_compensation_error_round_trip)
      printf '%s\n' \
        "raised_error_type" \
        "observed_error_type" \
        "observed_error_message" \
        "terminal_failure_shape"
      ;;
    operator_visible_mid_compensation_status)
      printf '%s\n' \
        "completed_forward_steps" \
        "running_compensation_step" \
        "completed_compensations" \
        "pending_compensations" \
        "failed_compensations" \
        "operator_visibility_snapshots"
      ;;
  esac
}

json_string() {
  local value="$1"

  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//$'\n'/\\n}"
  value="${value//$'\r'/\\r}"
  value="${value//$'\t'/\\t}"
  printf '"%s"' "$value"
}

emit_required_null_fields() {
  local scenario_id="$1"
  local field

  while IFS= read -r field; do
    [[ -n "$field" ]] || continue
    printf ',\n      "%s": null' "$field"
  done < <(scenario_required_fields "$scenario_id")
}

emit_findings_array() {
  local finding="$1"

  if [[ -z "$finding" ]]; then
    printf '[]'
    return
  fi

  printf '[\n        '
  json_string "$finding"
  printf '\n      ]'
}

emit_blocked_install_scenario_result() {
  local status="$1"
  local finding="$2"
  local artifact_versions_json="$3"
  local artifact_sources_json="$4"

  cat <<JSON
    {
      "scenario_id": "published_artifact_install_only",
      "status": "$status",
      "resolved_artifact_versions": $artifact_versions_json,
      "artifact_sources": $artifact_sources_json,
      "local_product_source_checkouts_used": false,
      "findings": $(emit_findings_array "$finding")
    }
JSON
}

emit_blocked_scenario_result() {
  local scenario_id="$1"
  local finding="$2"

  printf '    {\n'
  printf '      "scenario_id": '
  json_string "$scenario_id"
  printf ',\n      "status": "runner_blocked"'
  emit_required_null_fields "$scenario_id"
  printf ',\n      "findings": '
  emit_findings_array "$finding"
  printf '\n    }'
}

emit_blocked_scenario_results() {
  local reason="$1"
  local artifact_versions_json="$2"
  local artifact_sources_json="$3"
  local install_status="runner_blocked"
  local install_finding="$reason"
  local scenario_id
  local first=1

  if [[ -f "$result_dir/run-metadata.json" ]]; then
    install_status="pass"
    install_finding=""
  fi

  for scenario_id in "${saga_required_scenario_ids[@]}"; do
    if [[ "$first" -eq 0 ]]; then
      printf ',\n'
    fi
    first=0

    if [[ "$scenario_id" == "published_artifact_install_only" ]]; then
      emit_blocked_install_scenario_result "$install_status" "$install_finding" "$artifact_versions_json" "$artifact_sources_json"
    else
      emit_blocked_scenario_result "$scenario_id" "scenario did not execute because the saga conformance runner was blocked: $reason"
    fi
  done
}

blocked_result() {
  local reason="$1"
  local started="$2"
  local finished
  local artifact_versions_json="{}"
  local artifact_sources_json="{}"
  finished="$(timestamp)"

  if command -v python3 >/dev/null 2>&1 && [[ -f "$result_dir/run-metadata.json" ]]; then
    artifact_versions_json="$(python3 -c 'import json,sys; print(json.dumps(json.load(open(sys.argv[1])).get("published_artifact_versions", {}), sort_keys=True))' "$result_dir/run-metadata.json" 2>/dev/null || printf '{}')"
    artifact_sources_json="$(python3 -c 'import json,sys; print(json.dumps(json.load(open(sys.argv[1])).get("artifact_sources", {}), sort_keys=True))' "$result_dir/run-metadata.json" 2>/dev/null || printf '{}')"
  elif command -v python3 >/dev/null 2>&1 && [[ -f "$result_dir/pins.json" ]]; then
    artifact_versions_json="$(python3 -c 'import json,sys; pins=json.load(open(sys.argv[1])); print(json.dumps({k:pins[k] for k in ("server","cli","sdk-python","workflow","waterline") if k in pins}, sort_keys=True))' "$result_dir/pins.json" 2>/dev/null || printf '{}')"
    artifact_sources_json="$(python3 -c 'import json,sys; print(json.dumps(json.load(open(sys.argv[1])).get("artifact_sources", {}), sort_keys=True))' "$result_dir/pins.json" 2>/dev/null || printf '{}')"
  fi

  {
    cat <<JSON
{
  "schema": "durable-workflow.v2.saga-runtime-conformance.result",
  "schema_version": 1,
  "suite_schema": "durable-workflow.v2.platform-conformance.suite",
  "suite_version": 12,
  "category": "saga_runtime_contract",
  "outcome": "error",
  "runner_blocked": true,
  "started_at": "$started",
  "finished_at": "$finished",
  "generated_at": "$finished",
  "published_artifact_versions": $artifact_versions_json,
  "resolved_artifact_versions": $artifact_versions_json,
  "artifact_sources": $artifact_sources_json,
  "scenario_results": [
JSON
    emit_blocked_scenario_results "$reason" "$artifact_versions_json" "$artifact_sources_json"
    cat <<JSON
  ],
  "findings": [
    {
      "id": "runner-prerequisite-missing",
      "severity": "P0",
      "surface": "conformance-runner",
      "summary": $(json_string "$reason")
    }
  ]
}
JSON
  } > "$result_dir/sagas-result.json"

  {
    cat <<JSON
{
  "experiment": "sagas",
  "outcome": "error",
  "runnerBlocked": true,
  "artifactVersions": $artifact_versions_json,
  "findings": [
    $(json_string "$reason")
  ],
  "resultPath": $(json_string "$result_dir/sagas-result.json")
}
JSON
  } > "$result_dir/sagas-record.json"
}

started_at="$(timestamp)"

on_error() {
  local code=$?

  if [[ "$code" -ne 0 && ! -f "$result_dir/sagas-result.json" ]]; then
    blocked_result "saga conformance runner exited before producing sagas-result.json (exit $code)" "$started_at"
  fi

  exit "$code"
}
trap on_error ERR

missing=()
for command_name in docker python3 curl; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    missing+=("$command_name")
  fi
done

if [[ "${#missing[@]}" -gt 0 ]]; then
  blocked_result "saga conformance runner requires missing command(s): ${missing[*]}" "$started_at"
  exit 1
fi

cat > "$run_root/resolve-pins.py" <<'PY'
from __future__ import annotations

import json
import os
import re
import sys
import urllib.error
import urllib.request
from typing import Any


SERVER_PATCH_TAG_RE = re.compile(r"^\d+\.\d+\.\d+(?:-[0-9A-Za-z][0-9A-Za-z.-]*)?$")
SEMVER_TAG_RE = re.compile(r"^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.]+)?$")


def read_json(url: str) -> Any:
    request = urllib.request.Request(url, headers={"User-Agent": "durable-workflow-sagas-conformance"})
    with urllib.request.urlopen(request, timeout=45) as response:
        return json.loads(response.read().decode("utf-8"))


def env(name: str) -> str | None:
    value = os.environ.get(name)
    if value is None:
        return None
    value = value.strip()
    return value or None


def first_semver_package(packages: list[dict[str, Any]]) -> str:
    for package in packages:
        version = str(package.get("version", ""))
        if re.match(r"^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.]+)?$", version):
            return version
    raise RuntimeError("no semver package version found")


def packagist_version(name: str, override: str | None = None) -> str:
    if override:
        return override
    payload = read_json(f"https://repo.packagist.org/p2/{name}.json")
    return first_semver_package(payload["packages"][name])


def normalize_semver_tag(tag: str) -> str:
    tag = tag.strip()
    if not SEMVER_TAG_RE.match(tag):
        raise RuntimeError(f"no semver GitHub release tag found: {tag!r}")
    return tag.lstrip("v")


def asset_download_url(release: dict[str, Any], required_asset_name: str) -> str | None:
    for asset in release.get("assets", []):
        if str(asset.get("name", "")) == required_asset_name:
            url = str(asset.get("browser_download_url", "")).strip()
            return url or None
    return None


def url_is_downloadable(url: str) -> bool:
    headers = {"User-Agent": "durable-workflow-sagas-conformance"}
    for method in ("HEAD", "GET"):
        request_headers = dict(headers)
        if method == "GET":
            request_headers["Range"] = "bytes=0-0"
        request = urllib.request.Request(url, headers=request_headers, method=method)
        try:
            with urllib.request.urlopen(request, timeout=45) as response:
                return 200 <= response.status < 400
        except urllib.error.HTTPError:
            if method == "HEAD":
                continue
            return False
        except urllib.error.URLError:
            return False
    return False


def github_release_by_tag(repo: str, tag: str) -> dict[str, Any]:
    return read_json(f"https://api.github.com/repos/{repo}/releases/tags/{tag}")


def github_releases(repo: str) -> list[dict[str, Any]]:
    releases: list[dict[str, Any]] = []
    page = 1
    while True:
        payload = read_json(f"https://api.github.com/repos/{repo}/releases?per_page=100&page={page}")
        if not payload:
            return releases
        releases.extend(payload)
        page += 1


def github_release_with_downloadable_asset(
    repo: str,
    override: str | None,
    required_asset_name: str,
) -> tuple[str, str]:
    if override and override != "latest":
        requested_tag = override.strip()
        candidates = list(dict.fromkeys([requested_tag, requested_tag.lstrip("v")]))
        release: dict[str, Any] | None = None
        for tag in candidates:
            try:
                release = github_release_by_tag(repo, tag)
                break
            except urllib.error.HTTPError as exc:
                if exc.code == 404:
                    continue
                raise
        if release is None:
            raise RuntimeError(f"GitHub release {override!r} was not found for {repo}")
        resolved_tag = normalize_semver_tag(str(release.get("tag_name", requested_tag)))
        asset_url = asset_download_url(release, required_asset_name)
        if not asset_url or not url_is_downloadable(asset_url):
            raise RuntimeError(
                f"GitHub release {resolved_tag} for {repo} does not have a downloadable {required_asset_name} asset"
            )
        return resolved_tag, asset_url

    for release in github_releases(repo):
        tag = str(release.get("tag_name", ""))
        if not SEMVER_TAG_RE.match(tag):
            continue
        asset_url = asset_download_url(release, required_asset_name)
        if asset_url and url_is_downloadable(asset_url):
            return normalize_semver_tag(tag), asset_url

    raise RuntimeError(f"no semver GitHub release for {repo} has a downloadable {required_asset_name} asset")


def is_exact_server_patch_tag(version: str) -> bool:
    return bool(SERVER_PATCH_TAG_RE.match(version))


def server_tag_from_image(image: str) -> str | None:
    last_path_part = image.rsplit("/", 1)[-1]
    if ":" not in last_path_part:
        return None
    tag = last_path_part.rsplit(":", 1)[-1]
    return tag


def validate_server_version(version: str, source: str) -> str:
    if not is_exact_server_patch_tag(version):
        raise RuntimeError(f"{source} must be an exact patch semver Docker tag, not {version!r}")
    return version


def docker_hub_server_tags() -> list[str]:
    tags: list[str] = []
    url: str | None = "https://hub.docker.com/v2/repositories/durableworkflow/server/tags?page_size=100"
    while url:
        payload = read_json(url)
        for tag in payload.get("results", []):
            tags.append(str(tag.get("name", "")))
        next_url = payload.get("next")
        url = str(next_url) if next_url else None
    return tags


def docker_server_image() -> tuple[str, str]:
    explicit = env("DW_SERVER_IMAGE")
    if explicit:
        version = env("DW_SERVER_VERSION")
        image_name = explicit.split("@", 1)[0]
        image_tag = server_tag_from_image(image_name)
        exact_image_tag = image_tag if image_tag and is_exact_server_patch_tag(image_tag) else None
        if "@" not in explicit and exact_image_tag is None:
            raise RuntimeError("DW_SERVER_IMAGE must use an exact patch semver tag or an image digest")
        if version is None and exact_image_tag is not None:
            version = exact_image_tag
        if version is None:
            raise RuntimeError(
                "DW_SERVER_IMAGE must include an exact patch semver tag, "
                "or DW_SERVER_VERSION must name the exact patch version for digest-pinned images"
            )
        version = validate_server_version(version, "DW_SERVER_VERSION")
        if exact_image_tag is not None and version != exact_image_tag:
            raise RuntimeError(
                f"DW_SERVER_VERSION {version!r} does not match DW_SERVER_IMAGE tag {exact_image_tag!r}"
            )
        return explicit, version
    version = env("DW_SERVER_VERSION")
    if version is not None:
        version = validate_server_version(version, "DW_SERVER_VERSION")
    else:
        for name in docker_hub_server_tags():
            if is_exact_server_patch_tag(name):
                version = name
                break
        else:
            raise RuntimeError("no durableworkflow/server exact patch semver tag found")
    return f"durableworkflow/server:{version}", version


server_image, server_version = docker_server_image()
python_version = env("DW_PYTHON_SDK_VERSION") or read_json("https://pypi.org/pypi/durable-workflow/json")["info"]["version"]
workflow_version = packagist_version("durable-workflow/workflow", env("DW_WORKFLOW_PHP_VERSION"))
cli_version, cli_installer_url = github_release_with_downloadable_asset(
    "durable-workflow/cli",
    env("DW_CLI_VERSION"),
    "install.sh",
)
waterline_version = packagist_version("durable-workflow/waterline", env("DW_WATERLINE_VERSION"))

pins = {
    "server": server_version,
    "server_image": server_image,
    "cli": cli_version,
    "cli_installer_url": cli_installer_url,
    "sdk-python": python_version,
    "workflow": workflow_version,
    "waterline": waterline_version,
    "artifact_sources": {
        "server": "docker",
        "cli": "github-release",
        "sdk-python": "pypi",
        "workflow": "packagist",
        "waterline": "packagist",
    },
}

json.dump(pins, sys.stdout, indent=2, sort_keys=True)
sys.stdout.write("\n")
PY

pin_resolution_log="$result_dir/resolve-pins.log"
if ! python3 "$run_root/resolve-pins.py" > "$result_dir/pins.json" 2> "$pin_resolution_log"; then
  pin_resolution_error="$(tr '\n' ' ' < "$pin_resolution_log" | cut -c 1-1000 || true)"
  if [[ -z "$pin_resolution_error" ]]; then
    pin_resolution_error="unknown error"
  fi
  blocked_result "published artifact pin resolution failed: $pin_resolution_error" "$started_at"
  exit 1
fi
cp "$result_dir/pins.json" "$run_root/pins.json"

server_image="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["server_image"])' "$run_root/pins.json")"
workflow_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["workflow"])' "$run_root/pins.json")"
python_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["sdk-python"])' "$run_root/pins.json")"
cli_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["cli"])' "$run_root/pins.json")"
cli_installer_url="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["cli_installer_url"])' "$run_root/pins.json")"
waterline_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["waterline"])' "$run_root/pins.json")"

if [[ "${DW_SAGAS_SKIP_DOCKER_PULL:-0}" != "1" ]]; then
  docker pull "$server_image"
fi
server_image_pin="$(docker image inspect --format '{{index .RepoDigests 0}}' "$server_image" 2>/dev/null || true)"
if [[ -z "$server_image_pin" || "$server_image_pin" == "<no value>" ]]; then
  server_image_pin="$server_image"
fi
docker tag "$server_image_pin" durable-workflow-sagas-server:run
printf '%s\n' "$server_image_pin" > "$result_dir/server-image-digest.txt"
cp "$result_dir/server-image-digest.txt" "$run_root/server-image-digest.txt"

python3 -m venv "$run_root/.venv"
# shellcheck disable=SC1091
. "$run_root/.venv/bin/activate"
python -m pip install --upgrade pip
python -m pip install "durable-workflow==$python_version" httpx

mkdir -p "$run_root/php-worker" "$run_root/cli/bin" "$run_root/waterline" "$run_root/logs"
docker run --rm -v "$run_root/php-worker:/app" composer:2 \
  composer require --no-interaction --no-progress "durable-workflow/workflow:$workflow_version"
if ! curl -fsSL --retry 3 -o "$run_root/cli/install.sh" "$cli_installer_url"; then
  blocked_result "official CLI installer is not downloadable for release $cli_version at $cli_installer_url" "$started_at"
  exit 1
fi
if ! VERSION="$cli_version" \
  DURABLE_WORKFLOW_INSTALL_DIR="$run_root/cli/bin" \
  DURABLE_WORKFLOW_BIN_NAME=dw \
  sh "$run_root/cli/install.sh" > "$result_dir/cli-install.log" 2>&1; then
  blocked_result "official CLI installer failed for release $cli_version; see cli-install.log" "$started_at"
  exit 1
fi
if [[ ! -x "$run_root/cli/bin/dw" ]]; then
  blocked_result "official CLI installer did not create an executable dw binary for release $cli_version" "$started_at"
  exit 1
fi
docker run --rm -v "$run_root/waterline:/app" composer:2 \
  composer require --no-interaction --no-progress "durable-workflow/waterline:$waterline_version"

python3 - "$run_root/pins.json" "$result_dir/server-image-digest.txt" "$result_dir/run-metadata.json" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

pins = json.loads(Path(sys.argv[1]).read_text())
metadata = {
    "experiment": "sagas",
    "schema": "durable-workflow.v2.saga-runtime-conformance.metadata",
    "suite_schema": "durable-workflow.v2.platform-conformance.suite",
    "suite_version": 12,
    "generated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
    "published_artifact_versions": {
        "server": pins["server"],
        "cli": pins["cli"],
        "sdk-python": pins["sdk-python"],
        "workflow": pins["workflow"],
        "waterline": pins["waterline"],
    },
    "artifact_sources": pins["artifact_sources"],
    "server_image": pins["server_image"],
    "server_image_digest": Path(sys.argv[2]).read_text().strip(),
    "local_product_source_checkouts_used": False,
}
Path(sys.argv[3]).write_text(json.dumps(metadata, indent=2, sort_keys=True) + "\n")
PY
cp "$result_dir/run-metadata.json" "$run_root/run-metadata.json"

cat > "$run_root/compose.yml" <<'YAML'
x-server-environment: &server-environment
  DW_AUTH_DRIVER: token
  DW_AUTH_TOKEN: sagas-token
  DW_WORKER_POLL_TIMEOUT: "1"
  DW_WORKER_POLL_INTERVAL_MS: "100"
  DB_CONNECTION: sqlite
  DB_DATABASE: /app/database/database.sqlite
  QUEUE_CONNECTION: database

services:
  server:
    image: durable-workflow-sagas-server:run
    environment:
      <<: *server-environment
      DW_SERVER_TOPOLOGY_SHAPE: standalone_server
      DW_SERVER_PROCESS_CLASS: server_http_node
    ports:
      - "8080:8080"
    volumes:
      - server-db:/app/database
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/api/ready"]
      interval: 5s
      timeout: 3s
      retries: 24

  server-queue-worker:
    image: durable-workflow-sagas-server:run
    command: ["php", "artisan", "queue:work", "--sleep=1", "--tries=3", "--max-time=3600"]
    environment:
      <<: *server-environment
      DW_SERVER_TOPOLOGY_SHAPE: standalone_server
      DW_SERVER_PROCESS_CLASS: worker_node
    volumes:
      - server-db:/app/database
    depends_on:
      server:
        condition: service_healthy

volumes:
  server-db:
YAML

cat > "$run_root/php-worker/worker.php" <<'PHP'
<?php
declare(strict_types=1);

use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\ChildWorkflowOptions;
use Workflow\V2\Support\TimerCall;
use Workflow\V2\Support\WorkflowExecution;
use Workflow\V2\Workflow;

require __DIR__.'/vendor/autoload.php';

const BASE_URL = 'http://localhost:8080/api';
const TOKEN = 'sagas-token';
const NAMESPACE_NAME = 'default';
const PROTOCOL_VERSION = '1.7';
const PHP_QUEUE = 'sagas-php';
const PYTHON_QUEUE = 'sagas-python';
const WORKER_ID = 'php-sagas-worker';

#[Type('php.book-trip.failure')]
final class PhpFailureWorkflow extends Workflow
{
    public function handle(array $payload): array
    {
        throw new \RuntimeException((string) ($payload['failure_message'] ?? 'planned saga failure'));
    }
}

#[Type('php.book-trip')]
final class PhpBookTripWorkflow extends Workflow
{
    public function handle(array $payload): array
    {
        $steps = steps();
        $completed = [];
        $compensations = [];
        $failStep = string_or_null($payload['fail_step'] ?? null);
        $failureMode = (string) ($payload['failure_mode'] ?? 'none');
        $compensationRuntime = (string) ($payload['compensation_runtime'] ?? 'workflow-php');
        $pauseAfterFirstCompensation = (bool) ($payload['pause_after_first_compensation'] ?? false);
        $pauseSeconds = max(1, min(120, (int) ($payload['pause_seconds'] ?? 5)));

        try {
            foreach ($steps as $step) {
                if ($failStep === $step['action'] && $failureMode === 'before_forward') {
                    Workflow::child('php.book-trip.failure', new ChildWorkflowOptions(queue: PHP_QUEUE), $payload);
                }

                Workflow::activity(
                    $step['action'],
                    new ActivityOptions(queue: runtime_queue((string) ($payload['forward_runtime'] ?? 'workflow-php'))),
                    $payload
                );
                $completed[] = $step['action'];

                $compensation = $step['compensation'];
                $this->addCompensation(function () use ($compensation, $payload, $compensationRuntime, $pauseAfterFirstCompensation, $pauseSeconds, &$completed): void {
                    $options = new ActivityOptions(
                        queue: runtime_queue($compensationRuntime),
                        maxAttempts: compensation_max_attempts($compensation, $payload),
                        backoff: [0]
                    );
                    Workflow::activity($compensation, $options, $payload);
                    $completed[] = $compensation;

                    if ($pauseAfterFirstCompensation && $compensation === 'refund_card') {
                        Workflow::activity('pause_after_refund', new ActivityOptions(queue: runtime_queue($compensationRuntime)), $payload);
                        $completed[] = 'pause_after_refund';
                        Workflow::timer($pauseSeconds);
                    }
                });
                $compensations[] = $compensation;

                if ($failStep === $step['action'] && $failureMode === 'after_forward') {
                    Workflow::child('php.book-trip.failure', new ChildWorkflowOptions(queue: PHP_QUEUE), $payload);
                }
            }

            return ['status' => 'completed', 'activity_log' => $completed, 'compensations' => $compensations];
        } catch (\Throwable $throwable) {
            if ($compensations === []) {
                throw $throwable;
            }

            try {
                $this->compensate();
            } catch (\Throwable $compensationFailure) {
                throw new \RuntimeException(
                    'compensation failed for '.failed_compensation_step($compensationFailure->getMessage()).': '.$compensationFailure->getMessage(),
                    previous: $compensationFailure
                );
            }

            return ['status' => 'compensated', 'activity_log' => $completed, 'compensations' => $compensations];
        }
    }
}

function steps(): array
{
    return [
        ['action' => 'reserve_flight', 'compensation' => 'cancel_flight'],
        ['action' => 'reserve_hotel', 'compensation' => 'cancel_hotel'],
        ['action' => 'charge_card', 'compensation' => 'refund_card'],
        ['action' => 'send_confirmation', 'compensation' => ''],
    ];
}

function string_or_null(mixed $value): ?string
{
    return is_string($value) && $value !== '' ? $value : null;
}

function runtime_queue(string $runtime): string
{
    return $runtime === 'sdk-python' ? PYTHON_QUEUE : PHP_QUEUE;
}

function compensation_max_attempts(string $activity, array $payload): ?int
{
    if ($activity === 'cancel_hotel' && ($payload['cancel_hotel_fail_once'] ?? false)) {
        return 2;
    }

    return null;
}

function failed_compensation_step(string $message): string
{
    foreach (['cancel_flight', 'cancel_hotel', 'refund_card'] as $step) {
        if (str_contains($message, $step)) {
            return $step;
        }
    }

    return 'unknown';
}

function request_json(string $method, string $path, ?array $body = null, int $timeout = 10, array $allowed = []): array
{
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer '.TOKEN,
        'X-Namespace: '.NAMESPACE_NAME,
        'X-Durable-Workflow-Protocol-Version: '.PROTOCOL_VERSION,
    ];
    $options = ['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => $timeout,
    ]];
    if ($body !== null) {
        $options['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
    }
    unset($http_response_header);
    $response = file_get_contents(BASE_URL.$path, false, stream_context_create($options));
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }
    if (($status >= 400 || $status === 0) && ! in_array($status, $allowed, true)) {
        throw new RuntimeException("$method $path failed with HTTP $status: ".($response ?: ''));
    }
    $decoded = $response === false || $response === '' ? [] : json_decode($response, true, flags: JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
}

function envelope(mixed $value, ?string $codec = null): array
{
    $codec = $codec ?: CodecRegistry::defaultCodec();
    return ['codec' => $codec, 'blob' => Serializer::serializeWithCodec($codec, $value)];
}

function decode_payload(mixed $value, ?string $codec = null): mixed
{
    if ($value === null) {
        return null;
    }
    if (is_array($value) && isset($value['codec'], $value['blob'])) {
        return Serializer::unserializeWithCodec((string) $value['codec'], (string) $value['blob']);
    }
    if (is_string($value)) {
        return Serializer::unserializeWithCodec($codec ?: CodecRegistry::defaultCodec(), $value);
    }
    return $value;
}

function task_codec(array $task): string
{
    $codec = $task['payload_codec'] ?? null;
    if (! is_string($codec) || $codec === '') {
        $codec = is_array($task['arguments'] ?? null) ? ($task['arguments']['codec'] ?? null) : null;
    }
    return is_string($codec) && $codec !== '' ? $codec : CodecRegistry::defaultCodec();
}

function history_events(array $task): array
{
    $events = $task['history_events'] ?? ($task['history']['events'] ?? []);
    return is_array($events) ? $events : [];
}

function event_sequence(array $event): ?int
{
    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    $sequence = $payload['sequence'] ?? $event['sequence'] ?? null;
    return is_int($sequence) ? $sequence : null;
}

function event_for_sequence(array $task, int $sequence, array $eventTypes): ?array
{
    foreach (history_events($task) as $event) {
        if (! is_array($event) || ! in_array($event['event_type'] ?? null, $eventTypes, true)) {
            continue;
        }
        if (event_sequence($event) === $sequence) {
            return $event;
        }
    }
    return null;
}

function event_payload(array $event): array
{
    $payload = $event['payload'] ?? [];
    return is_array($payload) ? $payload : [];
}

function decode_history_value(mixed $value, string $codec): mixed
{
    if ($value === null) {
        return null;
    }
    if (is_array($value) && isset($value['codec'], $value['blob'])) {
        return Serializer::unserializeWithCodec((string) $value['codec'], (string) $value['blob']);
    }
    if (is_string($value)) {
        return Serializer::unserializeWithCodec($codec, $value);
    }
    return $value;
}

function activity_result(array $event, string $codec): mixed
{
    $payload = event_payload($event);
    $payloadCodec = is_string($payload['payload_codec'] ?? null) && $payload['payload_codec'] !== ''
        ? $payload['payload_codec']
        : $codec;
    return decode_history_value($payload['result'] ?? null, $payloadCodec);
}

function child_result(array $event, string $codec): mixed
{
    $payload = event_payload($event);
    $payloadCodec = is_string($payload['payload_codec'] ?? null) && $payload['payload_codec'] !== ''
        ? $payload['payload_codec']
        : $codec;
    return decode_history_value($payload['output'] ?? null, $payloadCodec);
}

function failure_from_event(array $event, string $fallback): \RuntimeException
{
    $payload = event_payload($event);
    $message = $payload['message'] ?? null;
    if (! is_string($message) || $message === '') {
        $exception = $payload['exception'] ?? null;
        $message = is_array($exception) && is_string($exception['message'] ?? null) ? $exception['message'] : $fallback;
    }
    return new \RuntimeException($message);
}

function complete_workflow_task(array $task, array $commands): void
{
    request_json('POST', '/worker/workflow-tasks/'.$task['task_id'].'/complete', [
        'lease_owner' => $task['lease_owner'],
        'workflow_task_attempt' => $task['workflow_task_attempt'] ?? 1,
        'commands' => $commands,
    ], 10, [409]);
}

function complete_activity_task(array $task, mixed $result, string $codec): void
{
    request_json('POST', '/worker/activity-tasks/'.$task['task_id'].'/complete', [
        'activity_attempt_id' => $task['activity_attempt_id'] ?? $task['attempt_id'] ?? '',
        'lease_owner' => $task['lease_owner'],
        'result' => envelope($result, $codec),
    ], 10, [409]);
}

function fail_activity_task(array $task, string $message, string $type = 'SagaActivityError'): void
{
    request_json('POST', '/worker/activity-tasks/'.$task['task_id'].'/fail', [
        'activity_attempt_id' => $task['activity_attempt_id'] ?? $task['attempt_id'] ?? '',
        'lease_owner' => $task['lease_owner'],
        'failure' => [
            'message' => $message,
            'type' => $type,
            'kind' => 'application',
        ],
    ], 10, [409]);
}

function fail_workflow_task(array $task, \Throwable $throwable): void
{
    complete_workflow_task($task, [[
        'type' => 'fail_workflow',
        'message' => $throwable->getMessage(),
        'exception_type' => $throwable::class,
        'exception' => ['class' => $throwable::class, 'message' => $throwable->getMessage()],
    ]]);
}

function workflow_input(array $task, string $codec): array
{
    $input = decode_payload($task['arguments'] ?? null, $codec);
    $input = is_array($input) && array_is_list($input) ? ($input[0] ?? []) : $input;
    return is_array($input) ? $input : [];
}

function workflow_run(array $task, string $codec): WorkflowRun
{
    $run = new WorkflowRun();
    $run->id = (string) ($task['run_id'] ?? $task['workflow_run_id'] ?? '');
    $run->workflow_instance_id = (string) ($task['workflow_id'] ?? $task['workflow_instance_id'] ?? '');
    $run->workflow_type = (string) ($task['workflow_type'] ?? '');
    $run->payload_codec = $codec;
    return $run;
}

function workflow_for_task(array $task, WorkflowRun $run): Workflow
{
    return match ($task['workflow_type'] ?? '') {
        'php.book-trip' => new PhpBookTripWorkflow($run),
        'php.book-trip.failure' => new PhpFailureWorkflow($run),
        default => throw new \RuntimeException('unknown PHP workflow type '.var_export($task['workflow_type'] ?? null, true)),
    };
}

function retry_policy_from_options(?ActivityOptions $options): ?array
{
    if (! $options instanceof ActivityOptions || ! $options->hasRetryOverrides()) {
        return null;
    }
    $policy = [];
    if ($options->maxAttempts !== null) {
        $policy['max_attempts'] = $options->maxAttempts;
    }
    if ($options->backoff !== null) {
        $policy['backoff_seconds'] = is_array($options->backoff) ? array_values($options->backoff) : [$options->backoff];
    }
    if ($options->nonRetryableErrorTypes !== []) {
        $policy['non_retryable_error_types'] = array_values($options->nonRetryableErrorTypes);
    }
    return $policy === [] ? null : $policy;
}

function complete_current_call(array $task, mixed $current, int $sequence, string $codec): bool
{
    if ($current instanceof ActivityCall) {
        if (event_for_sequence($task, $sequence, ['ActivityCompleted', 'ActivityFailed', 'ActivityCancelled', 'ActivityTimedOut'])) {
            return false;
        }
        $command = [
            'type' => 'schedule_activity',
            'activity_type' => $current->activity,
            'queue' => $current->options?->queue ?: PHP_QUEUE,
            'arguments' => envelope($current->arguments, $codec),
        ];
        $retryPolicy = retry_policy_from_options($current->options);
        if ($retryPolicy !== null) {
            $command['retry_policy'] = $retryPolicy;
        }
        foreach ([
            'start_to_close_timeout' => $current->options?->startToCloseTimeout,
            'schedule_to_start_timeout' => $current->options?->scheduleToStartTimeout,
            'schedule_to_close_timeout' => $current->options?->scheduleToCloseTimeout,
            'heartbeat_timeout' => $current->options?->heartbeatTimeout,
        ] as $field => $value) {
            if ($value !== null) {
                $command[$field] = $value;
            }
        }
        complete_workflow_task($task, [$command]);
        return true;
    }

    if ($current instanceof ChildWorkflowCall) {
        if (event_for_sequence($task, $sequence, ['ChildRunCompleted', 'ChildRunFailed', 'ChildRunCancelled', 'ChildRunTerminated'])) {
            return false;
        }
        $command = [
            'type' => 'start_child_workflow',
            'workflow_type' => $current->workflow,
            'queue' => $current->options?->queue ?: PHP_QUEUE,
            'arguments' => envelope($current->arguments, $codec),
        ];
        complete_workflow_task($task, [$command]);
        return true;
    }

    if ($current instanceof TimerCall) {
        if (event_for_sequence($task, $sequence, ['TimerFired'])) {
            return false;
        }
        complete_workflow_task($task, [['type' => 'start_timer', 'delay_seconds' => $current->seconds]]);
        return true;
    }

    throw new \RuntimeException('unsupported PHP workflow yield '.get_debug_type($current));
}

function replay_event(array $event, mixed $current, string $codec): mixed
{
    $eventType = $event['event_type'] ?? null;
    if ($current instanceof ActivityCall) {
        if ($eventType === 'ActivityCompleted') {
            return activity_result($event, $codec);
        }
        throw failure_from_event($event, 'activity failed');
    }
    if ($current instanceof ChildWorkflowCall) {
        if ($eventType === 'ChildRunCompleted') {
            return child_result($event, $codec);
        }
        throw failure_from_event($event, 'child workflow failed');
    }
    if ($current instanceof TimerCall) {
        return null;
    }
    throw new \RuntimeException('unsupported PHP workflow yield '.get_debug_type($current));
}

function resolution_event(array $task, mixed $current, int $sequence): ?array
{
    if ($current instanceof ActivityCall) {
        return event_for_sequence($task, $sequence, ['ActivityCompleted', 'ActivityFailed', 'ActivityCancelled', 'ActivityTimedOut']);
    }
    if ($current instanceof ChildWorkflowCall) {
        return event_for_sequence($task, $sequence, ['ChildRunCompleted', 'ChildRunFailed', 'ChildRunCancelled', 'ChildRunTerminated']);
    }
    if ($current instanceof TimerCall) {
        return event_for_sequence($task, $sequence, ['TimerFired']);
    }
    return null;
}

function handle_workflow_task(array $task): void
{
    $codec = task_codec($task);
    $run = workflow_run($task, $codec);
    $workflow = workflow_for_task($task, $run);
    $input = workflow_input($task, $codec);

    try {
        $execution = WorkflowExecution::start($workflow, [$input]);
        $sequence = 1;
        while ($execution->valid()) {
            $current = $execution->current();
            $event = resolution_event($task, $current, $sequence);
            if (is_array($event)) {
                try {
                    $value = replay_event($event, $current, $codec);
                    $execution->send($value);
                } catch (\Throwable $throwable) {
                    $execution->throw($throwable);
                }
                $sequence++;
                continue;
            }
            if (complete_current_call($task, $current, $sequence, $codec)) {
                return;
            }
        }
        complete_workflow_task($task, [['type' => 'complete_workflow', 'result' => envelope($execution->getReturn(), $codec)]]);
    } catch (\Throwable $throwable) {
        fail_workflow_task($task, $throwable);
    }
}

function activity_input(array $task, string $codec): array
{
    $arguments = decode_payload($task['arguments'] ?? null, $codec);
    $payload = is_array($arguments) && array_is_list($arguments) ? ($arguments[0] ?? []) : $arguments;
    return is_array($payload) ? $payload : [];
}

function side_store_path(): string
{
    return getenv('SAGA_SIDE_STORE') ?: __DIR__.'/side-store.jsonl';
}

function append_side_store(array $row): void
{
    $row['runtime'] = 'workflow-php';
    $row['recorded_at'] = gmdate('c');
    file_put_contents(side_store_path(), json_encode($row, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
}

function fail_once_state_path(string $scenario, string $activity): string
{
    return sys_get_temp_dir().'/dw-sagas-'.$scenario.'-'.$activity.'.failed-once';
}

function handle_activity_task(array $task): void
{
    $codec = task_codec($task);
    $activityType = (string) ($task['activity_type'] ?? '');
    $payload = activity_input($task, $codec);
    $scenario = (string) ($payload['scenario_id'] ?? 'unknown');

    if ($activityType === 'cancel_hotel' && ($payload['cancel_hotel_fail_once'] ?? false)) {
        $statePath = fail_once_state_path($scenario, $activityType);
        if (! is_file($statePath)) {
            file_put_contents($statePath, '1');
            append_side_store(['scenario_id' => $scenario, 'kind' => 'compensation_attempt', 'step' => $activityType]);
            fail_activity_task($task, 'cancel_hotel injected retryable failure', 'RetryableHotelCancelError');
            return;
        }
    }

    if ($activityType === 'cancel_flight' && ($payload['cancel_flight_fail'] ?? false)) {
        append_side_store(['scenario_id' => $scenario, 'kind' => 'compensation_attempt', 'step' => $activityType]);
        fail_activity_task($task, 'cancel_flight typed compensation failure', 'TypedCancelFlightError');
        return;
    }

    $kind = in_array($activityType, ['cancel_flight', 'cancel_hotel', 'refund_card'], true) ? 'compensation' : 'forward';
    if ($activityType === 'pause_after_refund') {
        $kind = 'marker';
    }
    append_side_store(['scenario_id' => $scenario, 'kind' => $kind, 'step' => $activityType]);
    complete_activity_task($task, ['activity' => $activityType, 'runtime' => 'workflow-php'], $codec);
}

request_json('POST', '/worker/register', [
    'worker_id' => WORKER_ID,
    'task_queue' => PHP_QUEUE,
    'runtime' => 'php',
    'sdk_version' => 'durable-workflow-php/published-artifact',
    'supported_workflow_types' => ['php.book-trip', 'php.book-trip.failure'],
    'supported_activity_types' => [
        'reserve_flight',
        'reserve_hotel',
        'charge_card',
        'send_confirmation',
        'cancel_flight',
        'cancel_hotel',
        'refund_card',
        'pause_after_refund',
    ],
    'max_concurrent_workflow_tasks' => 1,
    'max_concurrent_activity_tasks' => 1,
]);

while (true) {
    $workflowPoll = request_json('POST', '/worker/workflow-tasks/poll', [
        'worker_id' => WORKER_ID,
        'task_queue' => PHP_QUEUE,
    ], 6);
    if (is_array($workflowPoll['task'] ?? null)) {
        handle_workflow_task($workflowPoll['task']);
    }

    $activityPoll = request_json('POST', '/worker/activity-tasks/poll', [
        'worker_id' => WORKER_ID,
        'task_queue' => PHP_QUEUE,
    ], 6);
    if (is_array($activityPoll['task'] ?? null)) {
        handle_activity_task($activityPoll['task']);
    }
    usleep(100000);
}
PHP

cat > "$run_root/python-worker.py" <<'PY'
from __future__ import annotations

import asyncio
import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from durable_workflow import Client, Worker, activity, workflow
from durable_workflow.errors import ActivityFailed, ChildWorkflowFailed


PHP_QUEUE = "sagas-php"
PYTHON_QUEUE = "sagas-python"
SIDE_STORE = Path(os.environ["SAGA_SIDE_STORE"])


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def append_row(row: dict[str, Any]) -> None:
    row = {**row, "runtime": "sdk-python", "recorded_at": now()}
    with SIDE_STORE.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(row, sort_keys=True) + "\n")


def runtime_queue(runtime: str) -> str:
    return PYTHON_QUEUE if runtime == "sdk-python" else PHP_QUEUE


class TypedCancelFlightError(RuntimeError):
    pass


def steps() -> list[dict[str, str]]:
    return [
        {"action": "reserve_flight", "compensation": "cancel_flight"},
        {"action": "reserve_hotel", "compensation": "cancel_hotel"},
        {"action": "charge_card", "compensation": "refund_card"},
        {"action": "send_confirmation", "compensation": ""},
    ]


def activity_kind(name: str) -> str:
    if name in {"cancel_flight", "cancel_hotel", "refund_card"}:
        return "compensation"
    if name == "pause_after_refund":
        return "marker"
    return "forward"


def fail_once_path(scenario: str, activity_type: str) -> Path:
    return SIDE_STORE.parent / f"{scenario}-{activity_type}.failed-once"


async def activity_body(activity_type: str, payload: dict[str, Any]) -> dict[str, str]:
    scenario = str(payload.get("scenario_id", "unknown"))
    if activity_type == "cancel_hotel" and payload.get("cancel_hotel_fail_once"):
        path = fail_once_path(scenario, activity_type)
        if not path.exists():
            path.write_text("1", encoding="utf-8")
            append_row({"scenario_id": scenario, "kind": "compensation_attempt", "step": activity_type})
            raise RuntimeError("cancel_hotel injected retryable failure")

    if activity_type == "cancel_flight" and payload.get("cancel_flight_fail"):
        append_row({"scenario_id": scenario, "kind": "compensation_attempt", "step": activity_type})
        raise TypedCancelFlightError("cancel_flight typed compensation failure")

    append_row({"scenario_id": scenario, "kind": activity_kind(activity_type), "step": activity_type})
    return {"activity": activity_type, "runtime": "sdk-python"}


def define_activity(name: str):
    @activity.defn(name=name)
    async def _activity(payload: dict[str, Any]) -> dict[str, str]:
        return await activity_body(name, payload)

    return _activity


reserve_flight = define_activity("reserve_flight")
reserve_hotel = define_activity("reserve_hotel")
charge_card = define_activity("charge_card")
send_confirmation = define_activity("send_confirmation")
cancel_flight = define_activity("cancel_flight")
cancel_hotel = define_activity("cancel_hotel")
refund_card = define_activity("refund_card")
pause_after_refund = define_activity("pause_after_refund")


@workflow.defn(name="python.book-trip.failure")
class PythonFailureWorkflow:
    def run(self, ctx, payload: dict[str, Any]):
        raise RuntimeError(str(payload.get("failure_message") or "planned saga failure"))


@workflow.defn(name="python.book-trip")
class PythonBookTripWorkflow:
    def run(self, ctx, payload: dict[str, Any]):
        completed: list[str] = []
        compensations: list[str] = []
        fail_step = payload.get("fail_step")
        failure_mode = payload.get("failure_mode") or "none"
        compensation_runtime = str(payload.get("compensation_runtime") or "sdk-python")
        forward_runtime = str(payload.get("forward_runtime") or "sdk-python")
        pause = bool(payload.get("pause_after_first_compensation"))
        pause_seconds = max(1, min(120, int(payload.get("pause_seconds") or 5)))

        try:
            for step in steps():
                action = step["action"]
                compensation = step["compensation"]
                if fail_step == action and failure_mode == "before_forward":
                    yield ctx.start_child_workflow(
                        "python.book-trip.failure",
                        [payload],
                        task_queue=PYTHON_QUEUE,
                    )

                yield ctx.schedule_activity(action, [payload], queue=runtime_queue(forward_runtime))
                completed.append(action)

                if compensation:
                    compensations.append(compensation)

                if fail_step == action and failure_mode == "after_forward":
                    yield ctx.start_child_workflow(
                        "python.book-trip.failure",
                        [payload],
                        task_queue=PYTHON_QUEUE,
                    )

            return {"status": "completed", "activity_log": completed, "compensations": compensations}
        except ChildWorkflowFailed:
            if not compensations:
                raise

            for index, compensation in enumerate(reversed(compensations)):
                retry_policy = None
                if compensation == "cancel_hotel" and payload.get("cancel_hotel_fail_once"):
                    retry_policy = {"max_attempts": 2, "backoff_seconds": [0]}
                try:
                    yield ctx.schedule_activity(
                        compensation,
                        [payload],
                        queue=runtime_queue(compensation_runtime),
                        retry_policy=retry_policy,
                    )
                except ActivityFailed as exc:
                    failure_type = exc.exception_type or type(exc).__name__
                    raise RuntimeError(f"compensation failed for {compensation}: {failure_type}: {exc}") from exc
                completed.append(compensation)
                if pause and index == 0 and compensation == "refund_card":
                    yield ctx.schedule_activity(
                        "pause_after_refund",
                        [payload],
                        queue=runtime_queue(compensation_runtime),
                    )
                    completed.append("pause_after_refund")
                    yield ctx.sleep(pause_seconds)
            return {"status": "compensated", "activity_log": completed, "compensations": compensations}


async def main() -> None:
    client = Client("http://localhost:8080", token="sagas-token", namespace="default")
    worker = Worker(
        client,
        task_queue=PYTHON_QUEUE,
        workflows=[PythonBookTripWorkflow, PythonFailureWorkflow],
        activities=[
            reserve_flight,
            reserve_hotel,
            charge_card,
            send_confirmation,
            cancel_flight,
            cancel_hotel,
            refund_card,
            pause_after_refund,
        ],
        worker_id="python-sagas-worker",
        max_concurrent_workflow_tasks=1,
        max_concurrent_activity_tasks=1,
    )
    await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
PY

cat > "$run_root/orchestrate.py" <<'PY'
from __future__ import annotations

import asyncio
import atexit
import contextlib
import json
import os
import signal
import subprocess
import time
import urllib.error
import urllib.request
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from durable_workflow import Client, serializer


RUN_ROOT = Path(os.environ["RUN_ROOT"])
RESULT_DIR = Path(os.environ["RESULT_DIR"])
SIDE_STORE = Path(os.environ["SAGA_SIDE_STORE"])
PYTHON_WORKER_PID = int(os.environ["PYTHON_WORKER_PID"])
ACTIVE_PYTHON_WORKER_PID = PYTHON_WORKER_PID
RESTARTED_PYTHON_WORKERS: list[subprocess.Popen[Any]] = []
TERMINAL_STATUSES = {"completed", "failed", "terminated", "canceled", "cancelled"}
SCENARIO_REQUIRED_FIELDS = {
    "published_artifact_install_only": [
        "resolved_artifact_versions",
        "artifact_sources",
        "local_product_source_checkouts_used",
    ],
    "forward_success_path": [
        "forward_rows",
        "compensation_rows",
        "workflow_status",
        "history_dumps",
    ],
    "failure_at_d_reverse_compensation": [
        "forward_rows",
        "compensation_rows",
        "compensation_order",
        "workflow_status",
        "history_dumps",
    ],
    "failure_at_c_reverse_compensation": [
        "forward_rows",
        "compensation_rows",
        "compensation_order",
        "send_confirmation_invocations",
        "workflow_status",
    ],
    "failure_at_a_no_compensation": [
        "forward_rows",
        "compensation_rows",
        "workflow_status",
    ],
    "compensation_retry_idempotence": [
        "retry_attempts",
        "business_effect_count",
        "workflow_status",
    ],
    "compensation_failure_visibility": [
        "failed_compensation_step",
        "terminal_failure_shape",
        "operator_visible_reason",
        "workflow_status",
    ],
    "mid_compensation_worker_restart": [
        "restart_timing",
        "resumed_compensation_step",
        "duplicate_compensation_counts",
        "history_dumps",
    ],
    "php_workflow_python_compensation": [
        "workflow_runtime",
        "compensation_runtime",
        "compensation_order",
        "typed_result_shapes",
    ],
    "python_workflow_php_compensation": [
        "workflow_runtime",
        "compensation_runtime",
        "compensation_order",
        "typed_result_shapes",
    ],
    "typed_compensation_error_round_trip": [
        "raised_error_type",
        "observed_error_type",
        "observed_error_message",
        "terminal_failure_shape",
    ],
    "operator_visible_mid_compensation_status": [
        "completed_forward_steps",
        "running_compensation_step",
        "completed_compensations",
        "pending_compensations",
        "failed_compensations",
        "operator_visibility_snapshots",
    ],
}
EXPECTED = {
    "forward_success_path": {
        "forward": ["reserve_flight", "reserve_hotel", "charge_card", "send_confirmation"],
        "compensation": [],
        "output_status": "completed",
    },
    "failure_at_d_reverse_compensation": {
        "forward": ["reserve_flight", "reserve_hotel", "charge_card"],
        "compensation": ["refund_card", "cancel_hotel", "cancel_flight"],
        "output_status": "compensated",
    },
    "failure_at_c_reverse_compensation": {
        "forward": ["reserve_flight", "reserve_hotel"],
        "compensation": ["cancel_hotel", "cancel_flight"],
        "output_status": "compensated",
    },
    "failure_at_a_no_compensation": {
        "forward": [],
        "compensation": [],
        "output_status": "workflow_failed",
    },
}


def ts() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def read_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def side_rows(scenario_id: str) -> list[dict[str, Any]]:
    if not SIDE_STORE.exists():
        return []
    rows: list[dict[str, Any]] = []
    for line in SIDE_STORE.read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        row = json.loads(line)
        if row.get("scenario_id") == scenario_id:
            rows.append(row)
    return rows


def all_side_rows() -> list[dict[str, Any]]:
    if not SIDE_STORE.exists():
        return []
    return [
        json.loads(line)
        for line in SIDE_STORE.read_text(encoding="utf-8").splitlines()
        if line.strip()
    ]


def steps_for(rows: list[dict[str, Any]], kind: str) -> list[str]:
    return [str(row.get("step")) for row in rows if row.get("kind") == kind]


def counts(items: list[str]) -> dict[str, int]:
    return dict(sorted(Counter(items).items()))


def evidence_key(entry: dict[str, Any], index: int) -> str:
    language = entry.get("language")
    if isinstance(language, str) and language:
        return language
    workflow_runtime = entry.get("workflow_runtime")
    compensation_runtime = entry.get("compensation_runtime")
    if isinstance(workflow_runtime, str) and isinstance(compensation_runtime, str):
        return f"{workflow_runtime}->{compensation_runtime}"
    workflow_id = entry.get("workflow_id")
    if isinstance(workflow_id, str) and workflow_id:
        return workflow_id
    return f"entry_{index + 1}"


def side_store_field(entry: dict[str, Any], field: str) -> Any:
    deltas = entry.get("side_store_deltas")
    if not isinstance(deltas, dict):
        return None
    return deltas.get(field)


def required_field_value(entry: dict[str, Any], field: str) -> Any:
    if field in entry:
        return entry[field]
    if field == "resolved_artifact_versions":
        return entry.get("published_artifact_versions")
    if field == "forward_rows":
        return side_store_field(entry, "forward_rows")
    if field in {"compensation_rows", "compensation_order"}:
        return side_store_field(entry, "compensation_rows")
    if field == "workflow_status":
        return entry.get("terminal_state") or entry.get("control_plane_state")
    if field == "history_dumps":
        history_dump = entry.get("history_dump")
        if history_dump is not None:
            return {"workflow_history": history_dump}
    if field == "send_confirmation_invocations":
        forward_rows = required_field_value(entry, "forward_rows")
        if isinstance(forward_rows, list):
            return forward_rows.count("send_confirmation")
    return None


def collect_required_field(entries: list[dict[str, Any]], field: str) -> Any:
    values: dict[str, Any] = {}
    for index, entry in enumerate(entries):
        value = required_field_value(entry, field)
        if value is not None:
            values[evidence_key(entry, index)] = value
    if not values:
        return None
    if len(values) == 1:
        return next(iter(values.values()))
    return values


def apply_manifest_fields(scenario: dict[str, Any], entries: list[dict[str, Any]]) -> None:
    missing = []
    for field in SCENARIO_REQUIRED_FIELDS.get(str(scenario["scenario_id"]), []):
        value = collect_required_field(entries, field)
        scenario[field] = value
        if value is None:
            missing.append(field)
    if missing:
        scenario.setdefault("findings", []).append(
            "scenario evidence missing required field(s): " + ", ".join(missing)
        )
        if scenario.get("status") == "pass":
            scenario["status"] = "fail"


def report_history_dumps(results: list[dict[str, Any]]) -> dict[str, Any]:
    dumps: dict[str, Any] = {}
    for index, result in enumerate(results):
        value = required_field_value(result, "history_dumps")
        if value is None:
            continue
        scenario_id = str(result.get("scenario_id") or "unknown")
        dumps[f"{scenario_id}:{evidence_key(result, index)}"] = value
    return dumps


def report_operator_visibility_snapshots(results: list[dict[str, Any]]) -> dict[str, Any]:
    snapshots: dict[str, Any] = {}
    for index, result in enumerate(results):
        value = result.get("operator_visibility_snapshots")
        if value is None:
            continue
        scenario_id = str(result.get("scenario_id") or "unknown")
        snapshots[f"{scenario_id}:{evidence_key(result, index)}"] = value
    return snapshots


def report_typed_error_shapes(results: list[dict[str, Any]]) -> list[dict[str, Any]]:
    shapes: list[dict[str, Any]] = []
    for result in results:
        if result.get("scenario_id") != "typed_compensation_error_round_trip":
            continue
        shapes.append(
            {
                "raised_error_type": result.get("raised_error_type"),
                "observed_error_type": result.get("observed_error_type"),
                "observed_error_message": result.get("observed_error_message"),
                "terminal_failure_shape": result.get("terminal_failure_shape"),
            }
        )
    return shapes


def stop_restarted_python_workers() -> None:
    for process in RESTARTED_PYTHON_WORKERS:
        if process.poll() is None:
            process.terminate()
    for process in RESTARTED_PYTHON_WORKERS:
        with contextlib.suppress(subprocess.TimeoutExpired):
            process.wait(timeout=10)
        if process.poll() is None:
            process.kill()


atexit.register(stop_restarted_python_workers)


def compact_state(desc: dict[str, Any] | None) -> dict[str, Any]:
    if not isinstance(desc, dict):
        return {"status": None, "is_terminal": False}
    status = desc.get("status")
    return {
        "status": status,
        "is_terminal": bool(desc.get("is_terminal") or status in TERMINAL_STATUSES),
        "workflow_id": desc.get("workflow_id"),
        "run_id": desc.get("run_id"),
        "error": desc.get("error") or desc.get("failure") or desc.get("exception"),
    }


async def wait_result(client: Client, workflow_id: str, failures: list[str], timeout: float = 120.0) -> dict[str, Any]:
    deadline = time.monotonic() + timeout
    last_desc: dict[str, Any] | None = None
    while time.monotonic() < deadline:
        desc = await client._request("GET", f"/workflows/{workflow_id}")
        last_desc = desc
        status = desc.get("status")
        if desc.get("is_terminal") or status in TERMINAL_STATUSES:
            if status != "completed":
                return {
                    "status": "workflow_failed",
                    "terminal_state": compact_state(desc),
                    "error": desc.get("error") or desc.get("failure") or desc.get("exception"),
                }
            envelope = desc.get("output_envelope")
            if envelope is not None:
                return serializer.decode_envelope(envelope)
            output = desc.get("output")
            return output if isinstance(output, dict) else {"raw": output}
        await asyncio.sleep(0.5)
    failures.append(f"{workflow_id} timed out waiting for terminal state; last_state={compact_state(last_desc)}")
    return {}


async def terminal_state(client: Client, workflow_id: str) -> dict[str, Any]:
    try:
        return compact_state(await client._request("GET", f"/workflows/{workflow_id}"))
    except Exception as exc:
        return {"status": None, "is_terminal": False, "error": f"{type(exc).__name__}: {exc}"}


async def history(client: Client, workflow_id: str, run_id: str) -> dict[str, Any]:
    try:
        return await client.get_history(workflow_id, run_id)
    except Exception as exc:
        return {"error": f"{type(exc).__name__}: {exc}", "events": []}


def completed_activity_types(history_payload: dict[str, Any]) -> list[str]:
    events = history_payload.get("events")
    if not isinstance(events, list):
        events = ((history_payload.get("history") or {}).get("events") or [])
    activity_types: list[str] = []
    for event in events:
        if event.get("event_type") != "ActivityCompleted":
            continue
        payload = event.get("payload") or {}
        activity_type = payload.get("activity_type") or payload.get("activity_name")
        if isinstance(activity_type, str):
            activity_types.append(activity_type)
    return activity_types


def activity_failed_details(history_payload: dict[str, Any], activity_type: str) -> dict[str, Any]:
    events = history_payload.get("events")
    if not isinstance(events, list):
        events = ((history_payload.get("history") or {}).get("events") or [])
    for event in events:
        if not isinstance(event, dict) or event.get("event_type") != "ActivityFailed":
            continue
        payload = event.get("payload") or {}
        if not isinstance(payload, dict):
            continue
        observed_activity = payload.get("activity_type") or payload.get("activity_name")
        if observed_activity != activity_type:
            continue
        exception = payload.get("exception") if isinstance(payload.get("exception"), dict) else {}
        return {
            "activity_type": observed_activity,
            "exception_type": payload.get("exception_type") or exception.get("type"),
            "exception_class": payload.get("exception_class") or exception.get("class"),
            "message": payload.get("message") or exception.get("message"),
            "failure_category": payload.get("failure_category"),
            "non_retryable": payload.get("non_retryable"),
            "event": event,
        }
    return {}


async def start(client: Client, workflow_type: str, workflow_id: str, payload: dict[str, Any]):
    return await client.start_workflow(
        workflow_type=workflow_type,
        workflow_id=workflow_id,
        task_queue=PHP_QUEUE if workflow_type.startswith("php.") else PYTHON_QUEUE,
        input=[payload],
    )


PHP_QUEUE = "sagas-php"
PYTHON_QUEUE = "sagas-python"


def base_payload(scenario_id: str) -> dict[str, Any]:
    return {
        "scenario_id": scenario_id,
        "order_id": scenario_id,
    }


def scenario_status(failures: list[str]) -> str:
    return "pass" if not failures else "fail"


def finding(summary: str, surface: str = "runtime") -> dict[str, str]:
    return {"severity": "P0", "surface": surface, "summary": summary}


def parse_json_stdout(stdout: str) -> Any:
    text = stdout.strip()
    if not text:
        return None
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        decoder = json.JSONDecoder()
        for index, char in enumerate(text):
            if char not in "[{":
                continue
            try:
                value, _ = decoder.raw_decode(text[index:])
                return value
            except json.JSONDecodeError:
                continue
    return {"raw_stdout": text}


def cli_snapshot(label: str, args: list[str], timeout: float = 45.0) -> dict[str, Any]:
    command = [
        str(RUN_ROOT / "cli" / "bin" / "dw"),
        *args,
        "--server=http://localhost:8080",
        "--namespace=default",
        "--token=sagas-token",
    ]
    try:
        completed = subprocess.run(command, capture_output=True, text=True, timeout=timeout, check=False)
    except Exception as exc:
        return {
            "label": label,
            "ok": False,
            "error": f"{type(exc).__name__}: {exc}",
            "command": args,
        }
    return {
        "label": label,
        "ok": completed.returncode == 0,
        "exit_code": completed.returncode,
        "command": args,
        "stdout": parse_json_stdout(completed.stdout),
        "stderr": completed.stderr.strip(),
    }


async def control_plane_snapshot(client: Client, label: str, path: str) -> dict[str, Any]:
    try:
        return {"label": label, "ok": True, "path": f"/api{path}", "body": await client._request("GET", path)}
    except Exception as exc:
        return {"label": label, "ok": False, "path": f"/api{path}", "error": f"{type(exc).__name__}: {exc}"}


def http_snapshot(label: str, path: str, timeout: float = 15.0) -> dict[str, Any]:
    request = urllib.request.Request(
        f"http://localhost:8080{path}",
        headers={
            "Accept": "application/json",
            "Authorization": "Bearer sagas-token",
            "X-Namespace": "default",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            body = response.read().decode("utf-8")
            return {
                "label": label,
                "ok": 200 <= response.status < 300,
                "path": path,
                "http_status": response.status,
                "body": parse_json_stdout(body),
            }
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        return {
            "label": label,
            "ok": False,
            "path": path,
            "http_status": exc.code,
            "body": parse_json_stdout(body),
        }
    except Exception as exc:
        return {
            "label": label,
            "ok": False,
            "path": path,
            "error": f"{type(exc).__name__}: {exc}",
        }


def waterline_not_exercised_snapshot() -> dict[str, Any]:
    return {
        "label": "durable-workflow/waterline package",
        "ok": False,
        "status": "not_exercised",
        "surface": "waterline",
        "reason": (
            "The published-artifact sagas topology installs durable-workflow/waterline "
            "for artifact completeness but does not boot a Laravel Waterline app; "
            "no Waterline route is probed on the server-only image."
        ),
        "required_topology": "Boot Waterline against the saga run database to exercise Waterline operator visibility.",
    }


async def operator_snapshots(client: Client, workflow_id: str, run_id: str) -> dict[str, Any]:
    control_plane = {
        "workflow": await control_plane_snapshot(client, "GET /api/workflows/{workflowId}", f"/workflows/{workflow_id}"),
        "run": await control_plane_snapshot(client, "GET /api/workflows/{workflowId}/runs/{runId}", f"/workflows/{workflow_id}/runs/{run_id}"),
        "history": await control_plane_snapshot(client, "GET /api/workflows/{workflowId}/runs/{runId}/history", f"/workflows/{workflow_id}/runs/{run_id}/history"),
        "history_export": await control_plane_snapshot(client, "GET /api/workflows/{workflowId}/runs/{runId}/history/export", f"/workflows/{workflow_id}/runs/{run_id}/history/export"),
    }
    cli = {
        "describe": cli_snapshot(
            "dw workflow:describe <workflow-id>",
            ["workflow:describe", workflow_id, f"--run-id={run_id}", "--json"],
        ),
        "show_run": cli_snapshot(
            "dw workflow:show-run <workflow-id> <run-id>",
            ["workflow:show-run", workflow_id, run_id, "--json"],
        ),
        "history": cli_snapshot(
            "dw workflow:history <workflow-id> <run-id>",
            ["workflow:history", workflow_id, run_id, "--output=json"],
        ),
        "history_export": cli_snapshot(
            "dw workflow:history-export <workflow-id> <run-id>",
            ["workflow:history-export", workflow_id, run_id],
        ),
    }
    return {"control_plane": control_plane, "cli": cli, "waterline": waterline_not_exercised_snapshot()}


async def run_basic_scenario(
    client: Client,
    scenario_id: str,
    language: str,
    payload: dict[str, Any],
    row_scenario_id: str | None = None,
) -> dict[str, Any]:
    failures: list[str] = []
    workflow_type = f"{language}.book-trip"
    row_id = row_scenario_id or scenario_id
    workflow_id = f"sagas-{language}-{row_id}"
    handle = await start(client, workflow_type, workflow_id, payload)
    output = await wait_result(client, workflow_id, failures)
    state = await terminal_state(client, workflow_id)
    history_payload = await history(client, workflow_id, handle.run_id)
    rows = side_rows(row_id)
    actual_forward = steps_for(rows, "forward")
    actual_compensation = steps_for(rows, "compensation")

    expected = EXPECTED[scenario_id]
    if actual_forward != expected["forward"]:
        failures.append(f"{language} {scenario_id} forward rows expected {expected['forward']}, got {actual_forward}")
    if actual_compensation != expected["compensation"]:
        failures.append(f"{language} {scenario_id} compensation rows expected {expected['compensation']}, got {actual_compensation}")
    if output.get("status") != expected["output_status"]:
        failures.append(f"{language} {scenario_id} output.status expected {expected['output_status']}, got {output.get('status')}")
    if scenario_id == "failure_at_c_reverse_compensation" and "send_confirmation" in actual_forward:
        failures.append(f"{language} failure_at_c_reverse_compensation invoked send_confirmation")

    return {
        "scenario_id": scenario_id,
        "language": language,
        "status": scenario_status(failures),
        "failures": failures,
        "workflow_id": workflow_id,
        "run_id": handle.run_id,
        "observed_output": output,
        "terminal_state": state,
        "workflow_status": state,
        "forward_rows": actual_forward,
        "compensation_rows": actual_compensation,
        "compensation_order": actual_compensation,
        "send_confirmation_invocations": actual_forward.count("send_confirmation"),
        "side_store_deltas": {
            "forward_rows": actual_forward,
            "compensation_rows": actual_compensation,
            "counts": counts(actual_forward + actual_compensation),
        },
        "history_activity_completed": completed_activity_types(history_payload),
        "history_dump": history_payload,
        "history_dumps": {"workflow_history": history_payload},
    }


async def wait_for_activity(client: Client, workflow_id: str, run_id: str, activity_type: str) -> bool:
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        activity_types = completed_activity_types(await history(client, workflow_id, run_id))
        if activity_type in activity_types:
            return True
        await asyncio.sleep(0.5)
    return False


def restart_python_worker() -> subprocess.Popen[Any]:
    global ACTIVE_PYTHON_WORKER_PID

    with contextlib.suppress(ProcessLookupError):
        os.kill(ACTIVE_PYTHON_WORKER_PID, signal.SIGTERM)
    time.sleep(1)
    log = open(RUN_ROOT / "logs" / "python-worker-restart.log", "ab", buffering=0)
    process = subprocess.Popen(
        ["python", "-u", str(RUN_ROOT / "python-worker.py")],
        stdout=log,
        stderr=subprocess.STDOUT,
        env={**os.environ, "SAGA_SIDE_STORE": str(SIDE_STORE)},
    )
    ACTIVE_PYTHON_WORKER_PID = process.pid
    RESTARTED_PYTHON_WORKERS.append(process)
    return process


def restart_php_worker() -> None:
    subprocess.run(["docker", "rm", "-f", "dw-sagas-php-worker"], check=False)
    subprocess.run(
        [
            "docker",
            "run",
            "-d",
            "--name",
            "dw-sagas-php-worker",
            "--network",
            "host",
            "-e",
            f"SAGA_SIDE_STORE=/run-root/{SIDE_STORE.name}",
            "-v",
            f"{RUN_ROOT}:/run-root",
            "-v",
            f"{RUN_ROOT / 'php-worker'}:/work",
            "-w",
            "/work",
            "composer:2",
            "php",
            "worker.php",
        ],
        check=True,
    )


async def run_retry_idempotence(client: Client, language: str) -> dict[str, Any]:
    scenario_id = f"compensation_retry_idempotence_{language}"
    payload = {
        **base_payload(scenario_id),
        "fail_step": "charge_card",
        "failure_mode": "before_forward",
        "cancel_hotel_fail_once": True,
    }
    result = await run_basic_scenario(
        client,
        "failure_at_c_reverse_compensation",
        language,
        {**payload, "scenario_id": scenario_id},
        row_scenario_id=scenario_id,
    )
    rows = side_rows(scenario_id)
    attempts = [row for row in rows if row.get("kind") == "compensation_attempt" and row.get("step") == "cancel_hotel"]
    effects = [row for row in rows if row.get("kind") == "compensation" and row.get("step") == "cancel_hotel"]
    failures = []
    if len(attempts) != 1:
        failures.append(f"{language} cancel_hotel retry attempts expected 1 injected failed attempt, got {len(attempts)}")
    if len(effects) != 1:
        failures.append(f"{language} cancel_hotel business effect expected exactly once, got {len(effects)}")
    failures.extend(result["failures"])
    return {
        **result,
        "scenario_id": "compensation_retry_idempotence",
        "language": language,
        "status": scenario_status(failures),
        "failures": failures,
        "retry_attempts": len(attempts) + len(effects),
        "business_effect_count": len(effects),
    }


async def run_compensation_failure(client: Client, language: str) -> dict[str, Any]:
    scenario_id = f"compensation_failure_visibility_{language}"
    evidence_scenario_id = "compensation_failure_visibility"
    payload = {
        **base_payload(scenario_id),
        "fail_step": "charge_card",
        "failure_mode": "after_forward",
        "cancel_flight_fail": True,
    }
    workflow_type = f"{language}.book-trip"
    workflow_id = f"sagas-{language}-{scenario_id}"
    failures: list[str] = []
    handle = await start(client, workflow_type, workflow_id, payload)
    output = await wait_result(client, workflow_id, failures)
    state = await terminal_state(client, workflow_id)
    history_payload = await history(client, workflow_id, handle.run_id)
    visible = json.dumps(output, sort_keys=True) + json.dumps(state, sort_keys=True)
    if "cancel_flight" not in visible:
        failures.append(f"{language} terminal compensation failure did not name cancel_flight")
    if output.get("status") == "completed":
        failures.append(f"{language} terminal compensation failure reported success")
    return {
        "scenario_id": evidence_scenario_id,
        "language": language,
        "status": scenario_status(failures),
        "failures": failures,
        "workflow_id": workflow_id,
        "run_id": handle.run_id,
        "failed_compensation_step": "cancel_flight",
        "terminal_failure_shape": output,
        "operator_visible_reason": state,
        "workflow_status": state,
        "history_activity_completed": completed_activity_types(history_payload),
        "history_dump": history_payload,
        "history_dumps": {"workflow_history": history_payload},
    }


async def run_recovery(client: Client, language: str) -> dict[str, Any]:
    scenario_id = f"mid_compensation_worker_restart_{language}"
    payload = {
        **base_payload(scenario_id),
        "fail_step": "charge_card",
        "failure_mode": "after_forward",
        "pause_after_first_compensation": True,
    }
    workflow_type = f"{language}.book-trip"
    workflow_id = f"sagas-{language}-{scenario_id}"
    failures: list[str] = []
    handle = await start(client, workflow_type, workflow_id, payload)
    observed_pause = await wait_for_activity(client, workflow_id, handle.run_id, "pause_after_refund")
    restart_state = await terminal_state(client, workflow_id)
    if not observed_pause:
        failures.append(f"{language} recovery did not reach pause_after_refund before restart")
    elif restart_state.get("is_terminal"):
        failures.append(f"{language} recovery reached terminal state before worker restart point: {restart_state}")
    else:
        if language == "python":
            restart_python_worker()
        else:
            restart_php_worker()
    output = await wait_result(client, workflow_id, failures)
    rows = side_rows(scenario_id)
    compensation = steps_for(rows, "compensation")
    if compensation != EXPECTED["failure_at_c_reverse_compensation"]["compensation"]:
        failures.append(f"{language} recovery compensation expected {EXPECTED['failure_at_c_reverse_compensation']['compensation']}, got {compensation}")
    duplicates = {step: count for step, count in counts(compensation).items() if count > 1}
    if duplicates:
        failures.append(f"{language} recovery duplicate compensation counts: {duplicates}")
    history_payload = await history(client, workflow_id, handle.run_id)
    final_state = await terminal_state(client, workflow_id)
    return {
        "scenario_id": "mid_compensation_worker_restart",
        "language": language,
        "status": scenario_status(failures),
        "failures": failures,
        "workflow_id": workflow_id,
        "run_id": handle.run_id,
        "restart_timing": {"observed_pause_after_refund": observed_pause, "pre_restart_state": restart_state},
        "resumed_compensation_step": "cancel_flight",
        "duplicate_compensation_counts": duplicates,
        "observed_output": output,
        "workflow_status": final_state,
        "compensation_order": compensation,
        "side_store_deltas": {"compensation_rows": compensation},
        "history_activity_completed": completed_activity_types(history_payload),
        "history_dump": history_payload,
        "history_dumps": {"workflow_history": history_payload},
    }


async def run_cross_language(client: Client, scenario_id: str, workflow_language: str, compensation_runtime: str) -> dict[str, Any]:
    payload = {
        **base_payload(scenario_id),
        "fail_step": "charge_card",
        "failure_mode": "after_forward",
        "compensation_runtime": compensation_runtime,
    }
    result = await run_basic_scenario(
        client,
        "failure_at_c_reverse_compensation",
        workflow_language,
        {**payload, "scenario_id": scenario_id},
        row_scenario_id=scenario_id,
    )
    compensation_runtimes = [row.get("runtime") for row in side_rows(scenario_id) if row.get("kind") == "compensation"]
    expected_runtime = "sdk-python" if compensation_runtime == "sdk-python" else "workflow-php"
    failures = list(result["failures"])
    if any(runtime != expected_runtime for runtime in compensation_runtimes):
        failures.append(f"{scenario_id} expected compensation runtime {expected_runtime}, got {compensation_runtimes}")
    return {
        **result,
        "scenario_id": scenario_id,
        "workflow_runtime": "workflow-php" if workflow_language == "php" else "sdk-python",
        "compensation_runtime": expected_runtime,
        "compensation_order": result.get("compensation_order"),
        "typed_result_shapes": [row for row in side_rows(scenario_id) if row.get("kind") == "compensation"],
        "status": scenario_status(failures),
        "failures": failures,
    }


async def run_operator_visibility(client: Client) -> dict[str, Any]:
    scenario_id = "operator_visible_mid_compensation_status"
    payload = {
        **base_payload(scenario_id),
        "fail_step": "charge_card",
        "failure_mode": "after_forward",
        "pause_after_first_compensation": True,
        "pause_seconds": 90,
    }
    workflow_id = f"sagas-python-{scenario_id}"
    failures: list[str] = []
    handle = await start(client, "python.book-trip", workflow_id, payload)
    observed_pause = await wait_for_activity(client, workflow_id, handle.run_id, "pause_after_refund")
    control_plane_state = await terminal_state(client, workflow_id)
    history_payload = await history(client, workflow_id, handle.run_id)
    snapshots = await operator_snapshots(client, workflow_id, handle.run_id)
    rows = side_rows(scenario_id)
    completed_forward = steps_for(rows, "forward")
    completed_compensation = steps_for(rows, "compensation")
    visible = json.dumps(snapshots, sort_keys=True)
    for token in ["charge_card", "refund_card"]:
        if token not in visible:
            failures.append(f"operator visibility snapshot does not include {token}")
    if not observed_pause:
        failures.append("operator visibility scenario did not reach mid-compensation marker")
    for label, snapshot in snapshots["cli"].items():
        if not snapshot.get("ok"):
            failures.append(f"CLI {label} visibility snapshot failed: {snapshot}")
    unsupported_findings = [
        finding(
            (
                "Waterline operator visibility was not exercised because the "
                "published-artifact sagas topology does not boot a Waterline app "
                "against the run database"
            ),
            "waterline_operator_visibility",
        )
    ]
    status = "unsupported" if unsupported_findings and not failures else scenario_status(failures)
    return {
        "scenario_id": scenario_id,
        "status": status,
        "failures": failures,
        "findings": unsupported_findings,
        "completed_forward_steps": completed_forward,
        "running_compensation_step": "pause_after_refund" if observed_pause else None,
        "completed_compensations": completed_compensation,
        "pending_compensations": ["cancel_hotel", "cancel_flight"] if observed_pause else [],
        "failed_compensations": [],
        "operator_visibility_snapshots": snapshots,
        "unsupported_operator_surfaces": [
            {
                "surface": "waterline",
                "reason": snapshots["waterline"]["reason"],
                "required_topology": snapshots["waterline"]["required_topology"],
            }
        ],
        "control_plane_state": control_plane_state,
        "workflow_status": control_plane_state,
        "history_dump": history_payload,
        "history_dumps": {"workflow_history": history_payload},
    }


async def run_typed_error(client: Client) -> dict[str, Any]:
    scenario_id = "typed_compensation_error_round_trip_python"
    payload = {
        **base_payload(scenario_id),
        "fail_step": "charge_card",
        "failure_mode": "after_forward",
        "cancel_flight_fail": True,
    }
    failures: list[str] = []
    workflow_id = f"sagas-python-{scenario_id}"
    handle = await start(client, "python.book-trip", workflow_id, payload)
    output = await wait_result(client, workflow_id, failures)
    state = await terminal_state(client, workflow_id)
    history_payload = await history(client, workflow_id, handle.run_id)
    failure_details = activity_failed_details(history_payload, "cancel_flight")
    result = {
        "scenario_id": "typed_compensation_error_round_trip",
        "language": "python",
        "status": scenario_status(failures),
        "failures": failures,
        "workflow_id": workflow_id,
        "run_id": handle.run_id,
        "failed_compensation_step": "cancel_flight",
        "terminal_failure_shape": output,
        "operator_visible_reason": state,
        "workflow_status": state,
        "activity_failure_shape": failure_details,
        "history_activity_completed": completed_activity_types(history_payload),
        "history_dump": history_payload,
        "history_dumps": {"workflow_history": history_payload},
    }
    failures = list(result["failures"])
    observed_error_type = failure_details.get("exception_type")
    observed_error_message = failure_details.get("message")
    if observed_error_type != "TypedCancelFlightError":
        failures.append(f"typed compensation error expected ActivityFailed exception_type TypedCancelFlightError, got {observed_error_type!r}")
    terminal_shape = json.dumps({"output": output, "state": state}, sort_keys=True)
    if "TypedCancelFlightError" not in terminal_shape:
        failures.append("typed compensation error type did not survive to the terminal workflow failure shape")
    return {
        **result,
        "scenario_id": "typed_compensation_error_round_trip",
        "raised_error_type": "TypedCancelFlightError",
        "observed_error_type": observed_error_type,
        "observed_error_message": observed_error_message,
        "status": scenario_status(failures),
        "failures": failures,
    }


def fold_scenarios(results: list[dict[str, Any]]) -> list[dict[str, Any]]:
    grouped: dict[str, list[dict[str, Any]]] = {}
    for result in results:
        grouped.setdefault(str(result["scenario_id"]), []).append(result)
    folded: list[dict[str, Any]] = []
    for scenario_id, entries in grouped.items():
        failures: list[str] = []
        entry_findings: list[Any] = []
        for entry in entries:
            failures.extend(entry.get("failures") or [])
            entry_findings.extend(entry.get("findings") or [])
        statuses = {str(entry.get("status")) for entry in entries}
        if failures or "fail" in statuses:
            status = "fail"
        elif "runner_blocked" in statuses:
            status = "runner_blocked"
        elif "unsupported" in statuses:
            status = "unsupported"
        elif "not_covered" in statuses:
            status = "not_covered"
        elif statuses == {"pass"}:
            status = "pass"
        else:
            status = "fail"
        folded.append(
            {
                "scenario_id": scenario_id,
                "status": status,
                "started_at": entries[0].get("started_at"),
                "finished_at": entries[-1].get("finished_at"),
                "evidence": entries,
                "findings": failures + entry_findings,
            }
        )
        apply_manifest_fields(folded[-1], entries)
    return folded


async def main() -> None:
    started_at = os.environ["STARTED_AT"]
    metadata = read_json(RESULT_DIR / "run-metadata.json")
    client = Client("http://localhost:8080", token="sagas-token", namespace="default")
    results: list[dict[str, Any]] = []

    install_scenario = {
        "scenario_id": "published_artifact_install_only",
        "status": "pass",
        "started_at": started_at,
        "finished_at": ts(),
        "published_artifact_versions": metadata["published_artifact_versions"],
        "resolved_artifact_versions": metadata["published_artifact_versions"],
        "artifact_sources": metadata["artifact_sources"],
        "local_product_source_checkouts_used": False,
    }
    results.append(install_scenario)

    basic_payloads = {
        "forward_success_path": {},
        "failure_at_d_reverse_compensation": {"fail_step": "send_confirmation", "failure_mode": "before_forward"},
        "failure_at_c_reverse_compensation": {"fail_step": "charge_card", "failure_mode": "before_forward"},
        "failure_at_a_no_compensation": {"fail_step": "reserve_flight", "failure_mode": "before_forward"},
    }
    for scenario_id, overrides in basic_payloads.items():
        for language in ("php", "python"):
            row_id = f"{scenario_id}_{language}"
            result = await run_basic_scenario(
                client,
                scenario_id,
                language,
                {**base_payload(row_id), **overrides},
                row_scenario_id=row_id,
            )
            result["started_at"] = started_at
            result["finished_at"] = ts()
            results.append(result)

    for language in ("php", "python"):
        for runner in (run_retry_idempotence, run_compensation_failure, run_recovery):
            result = await runner(client, language)
            result["started_at"] = started_at
            result["finished_at"] = ts()
            results.append(result)

    for result in (
        await run_cross_language(client, "php_workflow_python_compensation", "php", "sdk-python"),
        await run_cross_language(client, "python_workflow_php_compensation", "python", "workflow-php"),
        await run_typed_error(client),
        await run_operator_visibility(client),
    ):
        result["started_at"] = started_at
        result["finished_at"] = ts()
        results.append(result)

    scenario_results = fold_scenarios(results)
    findings = []
    for scenario in scenario_results:
        for scenario_finding in scenario.get("findings") or []:
            if isinstance(scenario_finding, dict):
                item = dict(scenario_finding)
                item["summary"] = f"{scenario['scenario_id']}: {item.get('summary', 'scenario finding')}"
                findings.append(item)
            else:
                findings.append(finding(f"{scenario['scenario_id']}: {scenario_finding}"))

    required_ids = {
        "published_artifact_install_only",
        "forward_success_path",
        "failure_at_d_reverse_compensation",
        "failure_at_c_reverse_compensation",
        "failure_at_a_no_compensation",
        "compensation_retry_idempotence",
        "compensation_failure_visibility",
        "mid_compensation_worker_restart",
        "php_workflow_python_compensation",
        "python_workflow_php_compensation",
        "typed_compensation_error_round_trip",
        "operator_visible_mid_compensation_status",
    }
    covered_ids = {str(item["scenario_id"]) for item in scenario_results}
    for missing in sorted(required_ids - covered_ids):
        missing_scenario = {"scenario_id": missing, "status": "not_covered", "findings": ["scenario did not execute"]}
        apply_manifest_fields(missing_scenario, [])
        scenario_results.append(missing_scenario)
        for missing_finding in missing_scenario["findings"]:
            findings.append(finding(f"{missing}: {missing_finding}", "coverage"))

    outcome = "pass" if all(item.get("status") == "pass" for item in scenario_results) else "fail"
    finished_at = ts()
    report = {
        "schema": "durable-workflow.v2.saga-runtime-conformance.result",
        "schema_version": 1,
        "suite_schema": "durable-workflow.v2.platform-conformance.suite",
        "suite_version": 12,
        "category": "saga_runtime_contract",
        "outcome": outcome,
        "runner_blocked": False,
        "started_at": started_at,
        "finished_at": finished_at,
        "generated_at": finished_at,
        "published_artifact_versions": metadata["published_artifact_versions"],
        "resolved_artifact_versions": metadata["published_artifact_versions"],
        "artifact_sources": metadata["artifact_sources"],
        "implementation_identity": {
            "server_image": metadata["server_image"],
            "server_image_digest": metadata["server_image_digest"],
        },
        "runtime_matrix": {
            "workflow_runtimes": ["workflow-php", "sdk-python"],
            "activity_runtimes": ["workflow-php", "sdk-python"],
            "cross_language_cells": [
                "php_workflow_python_compensation",
                "python_workflow_php_compensation",
            ],
        },
        "topology": {
            "server": "published Docker image",
            "server_queue_worker": "same published Docker image running php artisan queue:work against the shared database queue",
            "php_worker": "composer:2 container with durable-workflow/workflow package",
            "python_worker": "venv with durable-workflow PyPI package",
            "cli": "official GitHub release installer and standalone dw binary",
            "waterline": "Composer package resolved and install-verified only; this server-only topology does not boot a Waterline app, so Waterline operator visibility is reported as unsupported rather than probed through server routes",
        },
        "book_trip_inputs": basic_payloads,
        "side_store_deltas": all_side_rows(),
        "history_dumps": report_history_dumps(results),
        "worker_restart_observations": [
            result
            for result in results
            if result.get("scenario_id") == "mid_compensation_worker_restart"
        ],
        "operator_visibility_snapshots": report_operator_visibility_snapshots(results),
        "cross_language_matrix": [
            result
            for result in results
            if result.get("scenario_id")
            in {"php_workflow_python_compensation", "python_workflow_php_compensation"}
        ],
        "typed_error_shapes": report_typed_error_shapes(results),
        "scenario_results": scenario_results,
        "observed_outputs": results,
        "linked_findings": findings,
        "findings": findings,
    }
    (RESULT_DIR / "sagas-result.json").write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (RESULT_DIR / "sagas-record.json").write_text(
        json.dumps(
            {
                "experiment": "sagas",
                "outcome": outcome,
                "runnerBlocked": False,
                "artifactVersions": metadata["published_artifact_versions"],
                "findings": [item["summary"] for item in findings],
                "resultPath": str(RESULT_DIR / "sagas-result.json"),
            },
            indent=2,
            sort_keys=True,
        )
        + "\n",
        encoding="utf-8",
    )
    print(json.dumps(report, indent=2, sort_keys=True))
    if outcome != "pass":
        raise SystemExit(1)


if __name__ == "__main__":
    asyncio.run(main())
PY

touch "$run_root/side-store.jsonl"
export SAGA_SIDE_STORE="$run_root/side-store.jsonl"
export RUN_ROOT="$run_root"
export RESULT_DIR="$result_dir"
export STARTED_AT="$started_at"

docker compose -f "$run_root/compose.yml" run --rm server server-bootstrap
docker compose -f "$run_root/compose.yml" up -d --wait

server_queue_worker_cid="$(docker compose -f "$run_root/compose.yml" ps -q server-queue-worker)"
server_queue_worker_running="$(docker inspect -f '{{.State.Running}}' "$server_queue_worker_cid" 2>/dev/null || true)"
if [[ -z "$server_queue_worker_cid" || "$server_queue_worker_running" != "true" ]]; then
  docker compose -f "$run_root/compose.yml" logs server-queue-worker > "$result_dir/server-queue-worker.log" 2>&1 || true
  blocked_result "saga conformance server queue worker failed to start; timer-backed recovery scenarios cannot execute without server-queue-worker.log evidence" "$started_at"
  exit 1
fi

docker rm -f dw-sagas-php-worker >/dev/null 2>&1 || true
docker run -d --name dw-sagas-php-worker --network host \
  -e "SAGA_SIDE_STORE=/run-root/side-store.jsonl" \
  -v "$run_root:/run-root" \
  -v "$run_root/php-worker:/work" \
  -w /work \
  composer:2 php worker.php

# shellcheck disable=SC1091
. "$run_root/.venv/bin/activate"
python -u "$run_root/python-worker.py" > "$run_root/logs/python-worker.log" 2>&1 &
python_worker_pid=$!
export PYTHON_WORKER_PID="$python_worker_pid"

set +e
python "$run_root/orchestrate.py" > "$run_root/logs/orchestrate.log" 2>&1
orchestrate_status=$?
set -e

cp "$run_root/logs/"* "$result_dir/" 2>/dev/null || true
docker logs dw-sagas-php-worker > "$result_dir/php-worker.log" 2>&1 || true
docker compose -f "$run_root/compose.yml" logs server > "$result_dir/server.log" 2>&1 || true
docker compose -f "$run_root/compose.yml" logs server-queue-worker > "$result_dir/server-queue-worker.log" 2>&1 || true

if [[ ! -f "$result_dir/sagas-result.json" ]]; then
  blocked_result "saga conformance orchestrator exited without producing sagas-result.json; see orchestrate.log" "$started_at"
  exit 1
fi

exit "$orchestrate_status"
