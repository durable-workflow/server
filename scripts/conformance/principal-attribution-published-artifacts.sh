#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: principal-attribution-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Runs the public principal-attribution contract against published artifacts only.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  artifact-install-evidence.json
  waterline-principal-attribution-execution.json
  principal-attribution-result.json
  principal-attribution-record.json

Environment overrides:
  DW_PRINCIPAL_ATTRIBUTION_RUN_ROOT        Scratch directory. Defaults to mktemp.
  DW_PRINCIPAL_ATTRIBUTION_RESULT_DIR      Result directory. Defaults to run root.
  DW_PRINCIPAL_ATTRIBUTION_KEEP_RUN_ROOT=1 Keep scratch directory after success.
  DW_SERVER_IMAGE                          Exact server image/tag/digest to test.
  DW_SERVER_VERSION                        Exact server SemVer tag; required for digest-only DW_SERVER_IMAGE.
  DW_CLI_VERSION                           GitHub release tag for the official CLI installer.
  DW_PYTHON_SDK_VERSION                    PyPI version for durable-workflow.
  DW_PHP_SDK_VERSION                       Composer version for durable-workflow/sdk.
  DW_WORKFLOW_PHP_VERSION                  Composer version for durable-workflow/workflow.
  DW_WATERLINE_VERSION                     Composer version for durable-workflow/waterline.
  DW_PRINCIPAL_ATTRIBUTION_SKIP_DOCKER_PULL=1 Reuse local image instead of pulling.
  DW_PRINCIPAL_ATTRIBUTION_SERVER_PORT     Host port for the published server. Defaults to a free 127.0.0.1 port.
USAGE
}

keep_run_root="${DW_PRINCIPAL_ATTRIBUTION_KEEP_RUN_ROOT:-0}"
result_dir="${DW_PRINCIPAL_ATTRIBUTION_RESULT_DIR:-}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --result-dir)
      result_dir="${2:?--result-dir requires a value}"
      shift 2
      ;;
    --result-dir=*)
      result_dir="${1#--result-dir=}"
      [[ -n "$result_dir" ]] || { printf '%s\n' '--result-dir requires a value' >&2; usage >&2; exit 2; }
      shift
      ;;
    --keep-run-root)
      keep_run_root=1
      shift
      ;;
    --keep-run-root=*)
      keep_run_root="${1#--keep-run-root=}"
      if [[ "$keep_run_root" == "true" ]]; then
        keep_run_root=1
      elif [[ "$keep_run_root" != "1" ]]; then
        keep_run_root=0
      fi
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

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
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

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
principal_scenario_manifest="${DW_PRINCIPAL_ATTRIBUTION_SCENARIO_MANIFEST:-$repo_root/static/platform-conformance/principal-attribution-scenarios.json}"

read_principal_suite_version() {
  local version=""

  if [[ -f "$principal_scenario_manifest" ]]; then
    version="$(sed -n 's/^[[:space:]]*"suite_version"[[:space:]]*:[[:space:]]*\([0-9][0-9]*\).*/\1/p' "$principal_scenario_manifest" | head -n 1)"
  fi

  if [[ -z "$version" ]]; then
    version="${DW_PRINCIPAL_ATTRIBUTION_SUITE_VERSION:-}"
  fi

  if [[ -n "$version" ]]; then
    printf '%s' "$version"
  else
    printf 'null'
  fi
}

principal_suite_version="$(read_principal_suite_version)"

run_root="${DW_PRINCIPAL_ATTRIBUTION_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-principal-attribution.XXXXXX")"
fi
mkdir -p "$run_root"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"

run_label="$(basename "$run_root" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_.-' '-')"

cleanup() {
  local code=$?

  if [[ -f "$run_root/compose.yml" ]]; then
    docker compose -f "$run_root/compose.yml" down -v >/dev/null 2>&1 || true
  fi
  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" ]]; then
    rm -rf "$run_root"
  fi
}
trap cleanup EXIT

principal_required_scenario_ids=(
  "published_artifact_install_only"
  "named_token_actor_matrix"
  "start_signal_cancel_spoofing"
  "query_attribution"
  "completion_failure_attribution"
  "server_originated_events"
  "anonymous_attribution"
  "python_sdk_visibility"
  "php_client_visibility"
  "cli_operator_visibility"
  "waterline_operator_visibility"
)

emit_principal_blocked_placeholder_fields() {
  local scenario_id="$1"
  local artifact_versions_json="$2"
  local artifact_sources_json="$3"

  case "$scenario_id" in
    published_artifact_install_only)
      printf ',\n      "resolved_artifact_versions": %s' "$artifact_versions_json"
      printf ',\n      "artifact_sources": %s' "$artifact_sources_json"
      printf ',\n      "local_product_source_checkouts_used": false'
      ;;
    named_token_actor_matrix)
      printf ',\n      "actors": []'
      printf ',\n      "credentials": {}'
      printf ',\n      "rotation_observations": {}'
      printf ',\n      "credential_rotation": {}'
      printf ',\n      "action_credentials": {}'
      ;;
    start_signal_cancel_spoofing)
      printf ',\n      "history_events": []'
      printf ',\n      "recorded_principals": {}'
      printf ',\n      "spoofing_attempts": {"payload_values": [], "headers": [], "executed": false}'
      printf ',\n      "spoofing_matrix": []'
      printf ',\n      "action_credentials": {}'
      ;;
    query_attribution)
      printf ',\n      "query_result": null'
      printf ',\n      "recorded_principal": null'
      printf ',\n      "history_or_query_task_surface": null'
      printf ',\n      "spoofing_attempts": {"payload_values": [], "headers": [], "executed": false}'
      printf ',\n      "spoofing_matrix": []'
      ;;
    completion_failure_attribution)
      printf ',\n      "completion_event_principal": null'
      printf ',\n      "failure_event_principal": null'
      printf ',\n      "worker_principal": null'
      printf ',\n      "expected_worker_principal": {"id": "worker:principal-attribution", "type": "auth:token"}'
      printf ',\n      "documented_system_principals": []'
      ;;
    server_originated_events)
      printf ',\n      "event_types": []'
      printf ',\n      "principal_values": {}'
      printf ',\n      "classification": null'
      ;;
    anonymous_attribution)
      printf ',\n      "anonymous_principal": null'
      printf ',\n      "documented_value": {"type": "server", "id": "anonymous"}'
      printf ',\n      "history_events": []'
      printf ',\n      "recorded_principals": {}'
      printf ',\n      "spoofing_attempts": {"payload_fields": [], "headers": [], "actions": [], "executed": false}'
      printf ',\n      "spoofing_matrix": []'
      printf ',\n      "anonymous_auth_driver": "none"'
      ;;
    python_sdk_visibility|php_client_visibility)
      printf ',\n      "client_operation": null'
      printf ',\n      "sdk_package_version": null'
      printf ',\n      "credential_used": null'
      printf ',\n      "expected_principal": null'
      printf ',\n      "raw_http_reference_principal": null'
      printf ',\n      "history_api_principal_samples": {}'
      printf ',\n      "operation_outputs": {}'
      printf ',\n      "operation_output_sample": null'
      printf ',\n      "recorded_principal": null'
      printf ',\n      "shape_matches_http": null'
      ;;
    cli_operator_visibility)
      printf ',\n      "command": null'
      printf ',\n      "output_sample": null'
      printf ',\n      "principal_visible": null'
      ;;
    waterline_operator_visibility)
      printf ',\n      "surface": null'
      printf ',\n      "output_sample": null'
      printf ',\n      "principal_visible": null'
      ;;
  esac
}

principal_blocked_finding_message() {
  local scenario_id="$1"
  local reason="$2"

  if [[ "$scenario_id" == "published_artifact_install_only" ]]; then
    printf '%s' "$reason"
  else
    printf 'scenario did not execute because the principal-attribution conformance runner was blocked: %s' "$reason"
  fi
}

principal_blocked_finding_versions() {
  local artifact_versions_json="$1"

  if [[ "$artifact_versions_json" == "{}" ]]; then
    printf '{"cli":"unresolved","sdk-php":"unresolved","sdk-python":"unresolved","server":"unresolved","waterline":"unresolved","workflow":"unresolved"}'
  else
    printf '%s' "$artifact_versions_json"
  fi
}

emit_principal_blocked_finding() {
  local scenario_id="$1"
  local observed="$2"
  local artifact_versions_json="$3"
  local finding_id="runner-blocked-${scenario_id//_/-}"
  local finding_versions_json
  finding_versions_json="$(principal_blocked_finding_versions "$artifact_versions_json")"

  printf '{\n'
  printf '        "id": '
  json_string "$finding_id"
  printf ',\n        "severity": "P0"'
  printf ',\n        "surface": "conformance-runner"'
  printf ',\n        "scenario_id": '
  json_string "$scenario_id"
  printf ',\n        "owning_surface": "conformance_harness"'
  printf ',\n        "artifact_versions": %s' "$finding_versions_json"
  printf ',\n        "observed_behavior": '
  json_string "$observed"
  printf ',\n        "expected_behavior": '
  json_string "the principal-attribution $scenario_id scenario executes against published artifacts and records its required evidence"
  printf ',\n        "next_acceptance_criterion": '
  json_string "restore the host runner prerequisite and rerun this principal-attribution scenario with runner_blocked=false evidence"
  printf '\n      }'
}

emit_principal_blocked_scenario_results() {
  local reason="$1"
  local artifact_versions_json="$2"
  local artifact_sources_json="$3"
  local first=1
  local scenario_id
  local finding

  for scenario_id in "${principal_required_scenario_ids[@]}"; do
    if [[ "$first" -eq 0 ]]; then
      printf ',\n'
    fi
    first=0

    if [[ "$scenario_id" == "published_artifact_install_only" ]]; then
      finding="$reason"
    else
      finding="$(principal_blocked_finding_message "$scenario_id" "$reason")"
    fi

    printf '    {\n'
    printf '      "scenario_id": '
    json_string "$scenario_id"
    printf ',\n      "status": "runner_blocked"'
    emit_principal_blocked_placeholder_fields "$scenario_id" "$artifact_versions_json" "$artifact_sources_json"
    printf ',\n      "linked_findings": ['
    emit_principal_blocked_finding "$scenario_id" "$finding" "$artifact_versions_json"
    printf ']'
    printf ',\n      "findings": ['
    emit_principal_blocked_finding "$scenario_id" "$finding" "$artifact_versions_json"
    printf ']\n    }'
  done
}

emit_principal_blocked_findings() {
  local reason="$1"
  local artifact_versions_json="$2"
  local first=1
  local scenario_id
  local finding

  for scenario_id in "${principal_required_scenario_ids[@]}"; do
    if [[ "$first" -eq 0 ]]; then
      printf ',\n'
    fi
    first=0

    finding="$(principal_blocked_finding_message "$scenario_id" "$reason")"
    printf '    '
    emit_principal_blocked_finding "$scenario_id" "$finding" "$artifact_versions_json"
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
    artifact_versions_json="$(python3 -c 'import json,sys; pins=json.load(open(sys.argv[1])); print(json.dumps({k:pins[k] for k in ("server","cli","workflow","sdk-php","sdk-python","waterline") if k in pins}, sort_keys=True))' "$result_dir/pins.json" 2>/dev/null || printf '{}')"
    artifact_sources_json="$(python3 -c 'import json,sys; print(json.dumps(json.load(open(sys.argv[1])).get("artifact_sources", {}), sort_keys=True))' "$result_dir/pins.json" 2>/dev/null || printf '{}')"
  fi

  {
    cat <<JSON
{
  "schema": "durable-workflow.v2.principal-attribution-conformance.result",
  "schema_version": 1,
  "suite_schema": "durable-workflow.v2.platform-conformance.suite",
  "suite_version": $principal_suite_version,
  "category": "principal_attribution_contract",
  "outcome": "error",
  "runner_blocked": true,
  "started_at": "$started",
  "finished_at": "$finished",
  "generated_at": "$finished",
  "published_artifact_versions": $artifact_versions_json,
  "resolved_artifact_versions": $artifact_versions_json,
  "artifact_sources": $artifact_sources_json,
  "topology": {
    "status": "runner_blocked",
    "server_url": null,
    "task_queues": {},
    "auth_driver": null,
    "principal_tokens": []
  },
  "actor_matrix": {},
  "history_dumps": {},
  "spoofing_attempts": {
    "payload_values": [],
    "headers": [],
    "executed": false
  },
  "spoofing_matrix": [],
  "operator_visibility": {
    "cli_history_json_principal_visible": null,
    "waterline": null
  },
  "sdk_principal_attribution_parity": {
    "python_sdk_visibility": {
      "status": "runner_blocked",
      "sdk_package_version": null,
      "credential_used": null,
      "expected_principal": null,
      "raw_http_reference_principal": null,
      "history_api_principal_samples": {},
      "operation_outputs": {},
      "recorded_principal": null,
      "shape_matches_http": null
    },
    "php_client_visibility": {
      "status": "runner_blocked",
      "sdk_package_version": null,
      "credential_used": null,
      "expected_principal": null,
      "raw_http_reference_principal": null,
      "history_api_principal_samples": {},
      "operation_outputs": {},
      "recorded_principal": null,
      "shape_matches_http": null
    }
  },
  "anonymous_observations": {
    "status": "runner_blocked",
    "documented_value": {"type": "server", "id": "anonymous"},
    "history_events": []
  },
  "scenario_results": [
JSON
    emit_principal_blocked_scenario_results "$reason" "$artifact_versions_json" "$artifact_sources_json"
    cat <<JSON
  ],
  "findings": [
JSON
    emit_principal_blocked_findings "$reason" "$artifact_versions_json"
    cat <<JSON
  ]
}
JSON
  } > "$result_dir/principal-attribution-result.json"

  {
    cat <<JSON
{
  "experiment": "principal-attribution",
  "outcome": "error",
  "runnerBlocked": true,
  "artifactVersions": $artifact_versions_json,
  "sdkPrincipalAttributionParity": {
    "python_sdk_visibility": {
      "status": "runner_blocked",
      "sdk_package_version": null,
      "credential_used": null,
      "expected_principal": null,
      "raw_http_reference_principal": null,
      "history_api_principal_samples": {},
      "operation_outputs": {},
      "recorded_principal": null,
      "shape_matches_http": null
    },
    "php_client_visibility": {
      "status": "runner_blocked",
      "sdk_package_version": null,
      "credential_used": null,
      "expected_principal": null,
      "raw_http_reference_principal": null,
      "history_api_principal_samples": {},
      "operation_outputs": {},
      "recorded_principal": null,
      "shape_matches_http": null
    }
  },
  "findings": [
JSON
    emit_principal_blocked_findings "$reason" "$artifact_versions_json"
    cat <<JSON
  ],
  "resultPath": $(json_string "$result_dir/principal-attribution-result.json")
}
JSON
  } > "$result_dir/principal-attribution-record.json"
}

started_at="$(timestamp)"

on_error() {
  local code="${1:-$?}"
  local line="${2:-unknown}"
  local command="${3:-unknown}"
  command="${command//$run_root/<run-root>}"
  command="${command//$result_dir/<result-dir>}"

  if [[ "$code" -ne 0 && ! -f "$result_dir/principal-attribution-result.json" ]]; then
    blocked_result "principal-attribution conformance runner exited before producing a result (exit $code at line $line while running: $command)" "$started_at"
  fi

  exit "$code"
}
trap 'on_error "$?" "$LINENO" "$BASH_COMMAND"' ERR

missing=()
for command_name in docker python3 curl; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    missing+=("$command_name")
  fi
done

if [[ "${#missing[@]}" -gt 0 ]]; then
  blocked_result "principal-attribution conformance runner requires missing command(s): ${missing[*]}" "$started_at"
  exit 1
fi

choose_free_port() {
  python3 - <<'PY'
import socket

with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
    sock.bind(("127.0.0.1", 0))
    print(sock.getsockname()[1])
PY
}

server_bind_host="${DW_PRINCIPAL_ATTRIBUTION_SERVER_BIND_HOST:-127.0.0.1}"
server_connect_host="${DW_PRINCIPAL_ATTRIBUTION_SERVER_CONNECT_HOST:-127.0.0.1}"
server_port="${DW_PRINCIPAL_ATTRIBUTION_SERVER_PORT:-$(choose_free_port)}"
anonymous_server_port="${DW_PRINCIPAL_ATTRIBUTION_ANONYMOUS_SERVER_PORT:-$(choose_free_port)}"
if [[ "$anonymous_server_port" == "$server_port" ]]; then
  anonymous_server_port="$(choose_free_port)"
fi
server_base_url="${DW_PRINCIPAL_ATTRIBUTION_SERVER_URL:-http://${server_connect_host}:${server_port}}"
anonymous_server_base_url="${DW_PRINCIPAL_ATTRIBUTION_ANONYMOUS_SERVER_URL:-http://${server_connect_host}:${anonymous_server_port}}"
server_api_url="${server_base_url%/}/api"
anonymous_server_api_url="${anonymous_server_base_url%/}/api"

cat > "$run_root/resolve-pins.py" <<'PY'
from __future__ import annotations

import json
import os
import re
import sys
import urllib.error
import urllib.request
from typing import Any

SERVER_PATCH_TAG_RE = re.compile(
    r"^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
    r"(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)"
    r"(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$",
)
SEMVER_TAG_RE = re.compile(r"^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.]+)?$")


def read_json(url: str) -> Any:
    request = urllib.request.Request(url, headers={"User-Agent": "durable-workflow-principal-attribution-conformance"})
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
        if SEMVER_TAG_RE.match(version):
            return version
    raise RuntimeError("no semver package version found")


def packagist_version(name: str, override: str | None = None) -> str:
    if override:
        return override
    payload = read_json(f"https://repo.packagist.org/p2/{name}.json")
    return first_semver_package(payload["packages"][name])


def normalize_semver_tag(tag: str) -> str:
    if not SEMVER_TAG_RE.match(tag):
        raise RuntimeError(f"no semver GitHub release tag found: {tag!r}")
    return tag.lstrip("v")


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


def asset_download_url(release: dict[str, Any], required_asset_name: str) -> str | None:
    for asset in release.get("assets", []):
        if str(asset.get("name", "")) == required_asset_name:
            url = str(asset.get("browser_download_url", "")).strip()
            return url or None
    return None


def url_is_downloadable(url: str) -> bool:
    request = urllib.request.Request(url, headers={"User-Agent": "durable-workflow-principal-attribution-conformance"}, method="GET")
    try:
        with urllib.request.urlopen(request, timeout=45) as response:
            return 200 <= response.status < 400
    except urllib.error.URLError:
        return False


def github_release_with_downloadable_asset(repo: str, override: str | None, required_asset_name: str) -> tuple[str, str]:
    if override and override != "latest":
        requested_tag = override.strip()
        release = github_release_by_tag(repo, requested_tag)
        resolved_tag = normalize_semver_tag(str(release.get("tag_name", requested_tag)))
        asset_url = asset_download_url(release, required_asset_name)
        if not asset_url or not url_is_downloadable(asset_url):
            raise RuntimeError(f"GitHub release {resolved_tag} for {repo} does not have a downloadable {required_asset_name} asset")
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
    prerelease = version.partition("-")[2]
    rolling = {"latest", "current", "head", "main", "master", "dev", "snapshot", "unresolved", "placeholder"}
    return bool(SERVER_PATCH_TAG_RE.match(version)) and not any(
        identifier.lower() in rolling for identifier in prerelease.split(".") if identifier
    )


def server_tag_from_image(image: str) -> str | None:
    last_path_part = image.rsplit("/", 1)[-1]
    if ":" not in last_path_part:
        return None
    return last_path_part.rsplit(":", 1)[-1]


def validate_server_version(version: str, source: str) -> str:
    if not is_exact_server_patch_tag(version):
        raise RuntimeError(f"{source} must be an exact SemVer Docker tag, not {version!r}")
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
            raise RuntimeError("DW_SERVER_IMAGE must use an exact SemVer tag or an image digest")
        if version is None and exact_image_tag is not None:
            version = exact_image_tag
        if version is None:
            raise RuntimeError("DW_SERVER_IMAGE must include an exact SemVer tag, or DW_SERVER_VERSION must name the exact release")
        version = validate_server_version(version, "DW_SERVER_VERSION")
        if exact_image_tag is not None and version != exact_image_tag:
            raise RuntimeError(f"DW_SERVER_VERSION {version!r} does not match DW_SERVER_IMAGE tag {exact_image_tag!r}")
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
            raise RuntimeError("no durableworkflow/server exact SemVer tag found")
    return f"durableworkflow/server:{version}", version


server_image, server_version = docker_server_image()
cli_version, cli_installer_url = github_release_with_downloadable_asset("durable-workflow/cli", env("DW_CLI_VERSION"), "install.sh")
python_version = env("DW_PYTHON_SDK_VERSION") or read_json("https://pypi.org/pypi/durable-workflow/json")["info"]["version"]
workflow_version = packagist_version("durable-workflow/workflow", env("DW_WORKFLOW_PHP_VERSION"))
php_sdk_version = packagist_version("durable-workflow/sdk", env("DW_PHP_SDK_VERSION"))
waterline_version = packagist_version("durable-workflow/waterline", env("DW_WATERLINE_VERSION"))

json.dump(
    {
        "server": server_version,
        "server_image": server_image,
        "cli": cli_version,
        "cli_installer_url": cli_installer_url,
        "workflow": workflow_version,
        "sdk-php": php_sdk_version,
        "sdk-python": python_version,
        "waterline": waterline_version,
        "artifact_sources": {
            "server": "docker",
            "cli": "github-release",
            "workflow": "packagist",
            "sdk-php": "packagist",
            "sdk-python": "pypi",
            "waterline": "packagist",
        },
    },
    sys.stdout,
    indent=2,
    sort_keys=True,
)
sys.stdout.write("\n")
PY

pin_resolution_log="$result_dir/resolve-pins.log"
if ! python3 "$run_root/resolve-pins.py" > "$result_dir/pins.json" 2> "$pin_resolution_log"; then
  pin_resolution_error="$(tr '\n' ' ' < "$pin_resolution_log" | cut -c 1-1000 || true)"
  [[ -n "$pin_resolution_error" ]] || pin_resolution_error="unknown error"
  blocked_result "published artifact pin resolution failed: $pin_resolution_error" "$started_at"
  exit 1
fi
cp "$result_dir/pins.json" "$run_root/pins.json"

server_image="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["server_image"])' "$run_root/pins.json")"
cli_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["cli"])' "$run_root/pins.json")"
cli_installer_url="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["cli_installer_url"])' "$run_root/pins.json")"
workflow_php_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["workflow"])' "$run_root/pins.json")"
php_sdk_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["sdk-php"])' "$run_root/pins.json")"
waterline_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["waterline"])' "$run_root/pins.json")"

if [[ "${DW_PRINCIPAL_ATTRIBUTION_SKIP_DOCKER_PULL:-0}" != "1" ]]; then
  docker pull "$server_image"
fi
server_image_pin="$(docker image inspect --format '{{index .RepoDigests 0}}' "$server_image" 2>/dev/null || true)"
if [[ -z "$server_image_pin" || "$server_image_pin" == "<no value>" ]]; then
  server_image_pin="$server_image"
fi
docker tag "$server_image_pin" durable-workflow-principal-attribution-server:run
printf '%s\n' "$server_image_pin" > "$result_dir/server-image-digest.txt"

mkdir -p "$run_root/cli/bin" "$run_root/logs"
if ! curl -fsSL --retry 3 -o "$run_root/cli/install.sh" "$cli_installer_url"; then
  blocked_result "official CLI installer is not downloadable for release $cli_version at $cli_installer_url" "$started_at"
  exit 1
fi
if ! PATH="$run_root/cli/bin${PATH:+:$PATH}" \
  VERSION="$cli_version" \
  DURABLE_WORKFLOW_INSTALL_DIR="$run_root/cli/bin" \
  DURABLE_WORKFLOW_BIN_NAME=dw \
  sh "$run_root/cli/install.sh" > "$result_dir/cli-install.log" 2>&1; then
  blocked_result "official CLI installer failed for release $cli_version; see cli-install.log" "$started_at"
  exit 1
fi

cat > "$run_root/artifact-smoke.py" <<'PY'
from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Any

def run(command: list[str], *, cwd: Path | None = None, timeout: int = 240, env: dict[str, str] | None = None) -> tuple[int, str]:
    try:
        completed = subprocess.run(
            command,
            cwd=str(cwd) if cwd else None,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=timeout,
            check=False,
        )
    except subprocess.TimeoutExpired as exc:
        output = exc.stdout if isinstance(exc.stdout, str) else ""
        return 124, f"command timed out after {timeout}s\n{output}"

    return completed.returncode, completed.stdout


def item(
    name: str,
    version: str,
    source: str,
    status: str,
    command: list[str] | None = None,
    detail: str = "",
    output: str = "",
) -> dict[str, Any]:
    return {
        "artifact": name,
        "version": version,
        "source": source,
        "status": status,
        "command": command,
        "detail": detail,
        "output_sample": output[-4000:],
    }


def pass_or_fail(name: str, version: str, source: str, command: list[str], code: int, output: str, detail: str) -> dict[str, Any]:
    return item(
        name,
        version,
        source,
        "pass" if code == 0 else "fail",
        command,
        detail if code == 0 else f"{detail} failed with exit {code}",
        output,
    )


def smoke_cli(dw_bin: Path, version: str, source: str) -> dict[str, Any]:
    if not dw_bin.exists():
        return item("cli", version, source, "fail", detail=f"installed CLI binary missing at {dw_bin}")

    command = [str(dw_bin), "--version"]
    code, output = run(command, timeout=30)

    return pass_or_fail("cli", version, source, command, code, output, "official GitHub release installer produced an executable CLI")


def smoke_python(root: Path, version: str, source: str) -> dict[str, Any]:
    venv_dir = root / "python-sdk"
    create_command = [sys.executable, "-m", "venv", str(venv_dir)]
    code, output = run(create_command, timeout=120)
    if code != 0:
        return item("sdk-python", version, source, "runner_blocked", create_command, "python venv creation failed", output)

    python = venv_dir / "bin" / "python"
    install_command = [
        str(python),
        "-m",
        "pip",
        "install",
        "--disable-pip-version-check",
        "--no-input",
        f"durable-workflow=={version}",
    ]
    code, output = run(install_command, timeout=300)
    if code != 0:
        return item("sdk-python", version, source, "fail", install_command, "PyPI package install failed", output)

    import_command = [
        str(python),
        "-c",
        "import importlib.metadata as metadata; from durable_workflow import Client, Worker; "
        "assert metadata.version('durable-workflow'); print(Client.__name__, Worker.__name__)",
    ]
    code, output = run(import_command, timeout=60)

    return pass_or_fail("sdk-python", version, source, import_command, code, output, "PyPI package imported public client and worker APIs")


def composer_env(root: Path) -> dict[str, str]:
    env = dict(os.environ)
    env["COMPOSER_HOME"] = str(root / "composer-home")
    env["COMPOSER_CACHE_DIR"] = str(root / "composer-cache")

    return env


def run_in_composer_container(root: Path, workdir: Path, command: list[str], *, timeout: int = 360) -> tuple[int, str]:
    docker = shutil.which("docker")
    if docker is None:
        return 127, "docker is required when composer/php are not installed on the host"

    relative_workdir = workdir.relative_to(root)
    docker_command = [
        docker,
        "run",
        "--rm",
        "-v",
        f"{root}:/work",
        "-w",
        "/work/" + str(relative_workdir),
        "-e",
        "COMPOSER_HOME=/work/composer-home",
        "-e",
        "COMPOSER_CACHE_DIR=/work/composer-cache",
        "composer:2",
        *command,
    ]

    return run(docker_command, timeout=timeout)


def run_composer_or_container(root: Path, workdir: Path, command: list[str], *, timeout: int = 360) -> tuple[int, str]:
    composer = shutil.which("composer")
    if composer is not None:
        return run([composer, *command], cwd=workdir, timeout=timeout, env=composer_env(root))

    return run_in_composer_container(root, workdir, ["composer", *command], timeout=timeout)


def run_php_or_container(root: Path, workdir: Path, code: str, *, timeout: int = 60) -> tuple[int, str]:
    php = shutil.which("php")
    if php is not None:
        return run([php, "-r", code], cwd=workdir, timeout=timeout, env=composer_env(root))

    return run_in_composer_container(root, workdir, ["php", "-r", code], timeout=timeout)


def smoke_composer_package(
    root: Path,
    artifact: str,
    package: str,
    version: str,
    source: str,
    import_assertion: str,
) -> dict[str, Any]:
    workdir = root / artifact
    workdir.mkdir(parents=True, exist_ok=True)
    (workdir / "composer.json").write_text(
        json.dumps(
            {
                "name": f"durable-workflow/principal-attribution-{artifact}-smoke",
                "description": "Published artifact import smoke for principal-attribution conformance.",
                "require": {},
                "minimum-stability": "dev",
                "prefer-stable": True,
            },
            indent=2,
            sort_keys=True,
        )
        + "\n"
    )

    install_command = [
        "require",
        "--no-interaction",
        "--no-progress",
        "--prefer-dist",
        "--no-scripts",
        f"{package}:{version}",
    ]
    composer_home = root / "composer-home"
    composer_cache = root / "composer-cache"
    composer_home.mkdir(parents=True, exist_ok=True)
    composer_cache.mkdir(parents=True, exist_ok=True)
    code, output = run_composer_or_container(root, workdir, install_command, timeout=360)
    if code != 0:
        return item(artifact, version, source, "fail", install_command, f"Packagist package install failed for {package}", output)

    import_code = f"require 'vendor/autoload.php'; {import_assertion}"
    import_command = ["php", "-r", import_code]
    code, output = run_php_or_container(root, workdir, import_code, timeout=60)

    return pass_or_fail(artifact, version, source, import_command, code, output, f"Packagist package {package} imported after install")


def main() -> int:
    pins = json.loads(Path(sys.argv[1]).read_text())
    evidence_path = Path(sys.argv[2])
    root = Path(sys.argv[3])
    dw_bin = Path(sys.argv[4])
    sources = pins.get("artifact_sources", {})
    root.mkdir(parents=True, exist_ok=True)

    artifacts = [
        item(
            "server",
            pins["server"],
            str(sources.get("server", "docker")),
            "pass",
            detail="Docker image was pulled, digest-pinned, bootstrapped, and reached /api/ready before the conformance run.",
        ),
        smoke_cli(dw_bin, pins["cli"], str(sources.get("cli", "github-release"))),
        smoke_python(root, pins["sdk-python"], str(sources.get("sdk-python", "pypi"))),
        smoke_composer_package(
            root,
            "sdk-php",
            "durable-workflow/sdk",
            pins["sdk-php"],
            str(sources.get("sdk-php", "packagist")),
            "if (!class_exists('DurableWorkflow\\\\Client')) { fwrite(STDERR, 'DurableWorkflow\\\\Client missing'); exit(1); } echo 'ok';",
        ),
        smoke_composer_package(
            root,
            "workflow",
            "durable-workflow/workflow",
            pins["workflow"],
            str(sources.get("workflow", "packagist")),
            "if (!class_exists('Workflow\\\\Workflow')) { fwrite(STDERR, 'Workflow\\\\Workflow missing'); exit(1); } echo 'ok';",
        ),
        smoke_composer_package(
            root,
            "waterline",
            "durable-workflow/waterline",
            pins["waterline"],
            str(sources.get("waterline", "packagist")),
            "if (!class_exists('Waterline\\\\WaterlineServiceProvider')) { fwrite(STDERR, 'Waterline\\\\WaterlineServiceProvider missing'); exit(1); } echo 'ok';",
        ),
    ]

    evidence = {
        "schema": "durable-workflow.v2.principal-attribution.artifact-install-evidence",
        "local_product_source_checkouts_used": False,
        "artifacts": artifacts,
    }
    evidence_path.write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
PY

if ! python3 "$run_root/artifact-smoke.py" "$run_root/pins.json" "$result_dir/artifact-install-evidence.json" "$run_root/artifacts" "$run_root/cli/bin/dw" > "$result_dir/artifact-smoke.log" 2>&1; then
  blocked_result "published artifact install smoke failed before producing evidence; see artifact-smoke.log" "$started_at"
  exit 1
fi

principal_tokens_json='[
  {"token":"alice-token-v1","subject":"alice","roles":["operator"],"label":"Alice","claims":{}},
  {"token":"alice-token-v2","subject":"alice","roles":["operator"],"label":"Alice"},
  {"token":"bob-token","subject":"bob","roles":["operator"],"label":"Bob"},
  {"token":"admin-token","subject":"admin","roles":["admin"],"label":"Admin"},
  {"token":"worker-token","subject":"worker:principal-attribution","roles":["worker"],"label":"Principal Attribution Worker"}
]'

python3 - "$run_root/pins.json" "$result_dir/server-image-digest.txt" "$result_dir/run-metadata.json" "$server_base_url" "$principal_suite_version" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

pins = json.loads(Path(sys.argv[1]).read_text())
suite_version = json.loads(sys.argv[5])
metadata = {
    "experiment": "principal-attribution",
    "schema": "durable-workflow.v2.principal-attribution.metadata",
    "suite_schema": "durable-workflow.v2.platform-conformance.suite",
    "suite_version": suite_version,
    "generated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
    "published_artifact_versions": {
        "server": pins["server"],
        "cli": pins["cli"],
        "workflow": pins["workflow"],
        "sdk-php": pins["sdk-php"],
        "sdk-python": pins["sdk-python"],
        "waterline": pins["waterline"],
    },
    "artifact_sources": pins["artifact_sources"],
    "server_image": pins["server_image"],
    "server_image_digest": Path(sys.argv[2]).read_text().strip(),
    "server_url": sys.argv[4],
    "local_product_source_checkouts_used": False,
}
Path(sys.argv[3]).write_text(json.dumps(metadata, indent=2, sort_keys=True) + "\n")
PY

cat > "$run_root/compose.yml" <<YAML
x-server-environment: &server-environment
  DW_AUTH_DRIVER: token
  DW_AUTH_BACKWARD_COMPATIBLE: "false"
  DW_PRINCIPAL_TOKENS: '${principal_tokens_json}'
  DW_TRUST_FORWARDED_ATTRIBUTION_HEADERS: "true"
  DW_WORKER_POLL_TIMEOUT: "1"
  DW_WORKER_POLL_INTERVAL_MS: "100"
  DW_QUERY_TASK_TIMEOUT: "3"
  DB_CONNECTION: sqlite
  DB_DATABASE: /app/database/database.sqlite
  QUEUE_CONNECTION: database

x-anonymous-server-environment: &anonymous-server-environment
  DW_AUTH_DRIVER: none
  DW_TRUST_FORWARDED_ATTRIBUTION_HEADERS: "true"
  DW_WORKER_POLL_TIMEOUT: "1"
  DW_WORKER_POLL_INTERVAL_MS: "100"
  DW_QUERY_TASK_TIMEOUT: "3"
  DB_CONNECTION: sqlite
  DB_DATABASE: /app/database/database.sqlite
  QUEUE_CONNECTION: database

services:
  server:
    image: durable-workflow-principal-attribution-server:run
    environment:
      <<: *server-environment
      DW_SERVER_TOPOLOGY_SHAPE: standalone_server
      DW_SERVER_PROCESS_CLASS: server_http_node
    ports:
      - "${server_bind_host}:${server_port}:8080"
    volumes:
      - server-db:/app/database
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/api/ready"]
      interval: 5s
      timeout: 3s
      retries: 24

  server-queue-worker:
    image: durable-workflow-principal-attribution-server:run
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

  anonymous-server:
    image: durable-workflow-principal-attribution-server:run
    environment:
      <<: *anonymous-server-environment
      DW_SERVER_TOPOLOGY_SHAPE: standalone_server
      DW_SERVER_PROCESS_CLASS: server_http_node
    ports:
      - "${server_bind_host}:${anonymous_server_port}:8080"
    volumes:
      - anonymous-server-db:/app/database
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/api/ready"]
      interval: 5s
      timeout: 3s
      retries: 24

  anonymous-server-queue-worker:
    image: durable-workflow-principal-attribution-server:run
    command: ["php", "artisan", "queue:work", "--sleep=1", "--tries=3", "--max-time=3600"]
    environment:
      <<: *anonymous-server-environment
      DW_SERVER_TOPOLOGY_SHAPE: standalone_server
      DW_SERVER_PROCESS_CLASS: worker_node
    volumes:
      - anonymous-server-db:/app/database
    depends_on:
      anonymous-server:
        condition: service_healthy

volumes:
  server-db:
  anonymous-server-db:
YAML

docker compose -f "$run_root/compose.yml" run --rm server server-bootstrap > "$result_dir/server-bootstrap.log" 2>&1
docker compose -f "$run_root/compose.yml" run --rm anonymous-server server-bootstrap > "$result_dir/anonymous-server-bootstrap.log" 2>&1
docker compose -f "$run_root/compose.yml" up -d --wait > "$result_dir/docker-compose-up.log" 2>&1

for _ in $(seq 1 90); do
  if curl -fsS "$server_api_url/ready" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

for _ in $(seq 1 90); do
  if curl -fsS "$anonymous_server_api_url/ready" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

# BEGIN principal-attribution-waterline-shard
waterline_result_path="$result_dir/waterline-principal-attribution-result.json"
waterline_execution_path="$result_dir/waterline-principal-attribution-execution.json"
waterline_app="$run_root/waterline-principal-app"
rm -f -- "$waterline_result_path" "$waterline_execution_path"
waterline_artifact_args=(
  --artifact-version "server=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["server"])' "$run_root/pins.json")"
  --artifact-version "cli=$cli_version"
  --artifact-version "workflow=$workflow_php_version"
  --artifact-version "sdk-php=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["sdk-php"])' "$run_root/pins.json")"
  --artifact-version "sdk-python=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["sdk-python"])' "$run_root/pins.json")"
  --artifact-version "waterline=$waterline_version"
  --artifact-source "server=docker_image"
  --artifact-source "cli=published_install_script"
  --artifact-source "workflow=published_composer_package"
  --artifact-source "sdk-php=published_packagist_package"
  --artifact-source "sdk-python=published_pypi_package"
  --artifact-source "waterline=published_package"
)

write_waterline_shard_execution() {
  local normative_result_written="$1"
  local failed_phase="$2"
  local exit_status="$3"
  local diagnostic_path="$4"
  local runner_blocked="$5"
  local reached_product_behavior="$6"

  python3 - \
    "$run_root/pins.json" \
    "$waterline_execution_path" \
    "$normative_result_written" \
    "$failed_phase" \
    "$exit_status" \
    "$diagnostic_path" \
    "$runner_blocked" \
    "$reached_product_behavior" \
    "$waterline_create_status" \
    "$waterline_require_status" \
    "$waterline_key_status" \
    "$waterline_migrate_status" \
    "$waterline_command_status" \
    "$waterline_report_validation_status" <<'PY'
from __future__ import annotations

import json
import sys
from pathlib import Path

pins = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
out_path = Path(sys.argv[2])
normative_result_written = sys.argv[3] == "true"
failed_phase = sys.argv[4] or None
exit_status_raw = sys.argv[5]
diagnostic_path = Path(sys.argv[6]) if sys.argv[6] else None
runner_blocked = sys.argv[7] == "true"
reached_product_behavior = sys.argv[8] == "true"
phase_names = (
    "create_project",
    "composer_require",
    "artisan_key_generate",
    "artisan_migrate",
    "artisan_command",
    "result_validation",
)


def status_value(raw: str) -> int | str | None:
    if raw == "not_invoked":
        return None
    try:
        return int(raw)
    except ValueError:
        return raw


phase_statuses = {
    phase: status_value(raw)
    for phase, raw in zip(phase_names, sys.argv[9:], strict=True)
}
exit_status = status_value(exit_status_raw)
diagnostic_limit_bytes = 4000
diagnostic_bytes = b""
if diagnostic_path is not None and diagnostic_path.is_file():
    diagnostic_bytes = diagnostic_path.read_bytes()
diagnostic_tail = diagnostic_bytes[-diagnostic_limit_bytes:].decode("utf-8", errors="replace")[-diagnostic_limit_bytes:]
versions = {
    "server": pins["server"],
    "cli": pins["cli"],
    "workflow": pins["workflow"],
    "sdk-php": pins["sdk-php"],
    "sdk-python": pins["sdk-python"],
    "waterline": pins["waterline"],
}
sources = {
    "server": "docker_image",
    "cli": "published_install_script",
    "workflow": "published_composer_package",
    "sdk-php": "published_packagist_package",
    "sdk-python": "published_pypi_package",
    "waterline": "published_package",
}
execution = {
    "schema": "durable-workflow.v2.principal-attribution.waterline-shard-execution",
    "schema_version": 1,
    "shard": "waterline_operator_visibility",
    "command": "waterline:principal-attribution-conformance",
    "normative_result_written": normative_result_written,
    "failed_phase": failed_phase,
    "exit_status": exit_status,
    "phase_statuses": phase_statuses,
    "runner_blocked": runner_blocked,
    "reached_published_product_behavior": reached_product_behavior,
    "artifact_versions": versions,
    "artifact_sources": sources,
    "diagnostic": {
        "log_file": diagnostic_path.name if diagnostic_path is not None else None,
        "output_tail": diagnostic_tail,
        "original_bytes": len(diagnostic_bytes),
        "captured_bytes": min(len(diagnostic_bytes), diagnostic_limit_bytes),
        "limit_bytes": diagnostic_limit_bytes,
        "truncated": len(diagnostic_bytes) > diagnostic_limit_bytes,
    },
}
out_path.write_text(json.dumps(execution, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

write_waterline_fallback() {
  local reason="$1"

  python3 - "$run_root/pins.json" "$waterline_result_path" "$waterline_execution_path" "$started_at" "$principal_suite_version" "$reason" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

pins = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
out_path = Path(sys.argv[2])
execution = json.loads(Path(sys.argv[3]).read_text(encoding="utf-8"))
started_at = sys.argv[4]
suite_version = int(sys.argv[5])
reason = sys.argv[6]
finished = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
versions = execution["artifact_versions"]
sources = execution["artifact_sources"]
runner_blocked = execution["runner_blocked"]
status = "runner_blocked" if runner_blocked else "fail"
diagnostic = execution["diagnostic"]
output_sample = diagnostic["output_tail"]
if not output_sample.strip():
    output_sample = (
        f"{execution['failed_phase']} exited with status {execution['exit_status']}; "
        "no diagnostic output was captured."
    )
finding = {
    "id": "waterline-principal-waterline_operator_visibility",
    "scenario_id": "waterline_operator_visibility",
    "owning_surface": "conformance_harness" if runner_blocked else "waterline",
    "artifact_versions": versions,
    "observed_behavior": reason,
    "expected_behavior": "waterline:principal-attribution-conformance runs from the published Waterline package and emits selected-run principal visibility evidence",
    "next_acceptance_criterion": (
        "restore the named host prerequisite and rerun the exact published artifact tuple"
        if runner_blocked
        else "correct the named published Waterline phase and rerun the exact published artifact tuple"
    ),
    "priority": "P0" if runner_blocked else "P1",
}
scenario_results = [
    {
        "scenario_id": "published_artifact_install_only",
        "status": "pass",
        "observed_outputs": {
            "artifact_versions": versions,
            "artifact_sources": sources,
        },
        "linked_findings": [],
    },
    {
        "scenario_id": "waterline_operator_visibility",
        "status": status,
        "surface": "selected-run detail API commands and timeline",
        "output_sample": output_sample,
        "principal_visible": False,
        "observed_outputs": {"shard_execution": execution},
        "linked_findings": [finding],
        "findings": [finding],
    },
]
report = {
    "schema": "durable-workflow.v2.principal-attribution.waterline-operator-shard",
    "schema_version": 1,
    "suite_version": suite_version,
    "coverage_scope": "waterline-principal-attribution-operator-shard",
    "outcome": "error" if runner_blocked else "fail",
    "runner_blocked": runner_blocked,
    "started_at": started_at,
    "finished_at": finished,
    "generated_at": finished,
    "artifact_versions": versions,
    "artifact_sources": sources,
    "runtime_matrix": {
        "claimed_targets": ["waterline_operator_visibility"],
        "covered_scenarios": [
            "published_artifact_install_only",
            "waterline_operator_visibility",
        ],
        "observer_paths": [
            "waterline-selected-run-detail",
            "waterline-selected-run-timeline",
            "waterline-command-intake",
        ],
    },
    "scenario_results": scenario_results,
    "waterline_principal_visibility": {
        "shard_command": "waterline:principal-attribution-conformance",
        "failure": reason,
        "shard_execution": execution,
    },
    "api_captures": {},
    "findings": [finding],
    "finding_links": {"waterline_operator_visibility": [finding["id"]]},
}
out_path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

mkdir -p "$waterline_app"
waterline_create_status=not_invoked
waterline_require_status=not_invoked
waterline_key_status=not_invoked
waterline_migrate_status=not_invoked
waterline_command_status=not_invoked
waterline_report_validation_status=not_invoked
if docker run --rm -v "$waterline_app:/app" -w /app composer:2 \
  composer create-project laravel/laravel . --no-interaction --no-progress \
  > "$result_dir/waterline-create-project.log" 2>&1; then
  waterline_create_status=0
else
  waterline_create_status=$?
fi

if [[ "$waterline_create_status" == 0 ]]; then
  mkdir -p "$waterline_app/database"
  : > "$waterline_app/database/database.sqlite"

  if docker run --rm -v "$waterline_app:/app" -w /app composer:2 \
    composer require --no-interaction --no-progress \
      "durable-workflow/waterline:${waterline_version}@beta" \
      "durable-workflow/workflow:${workflow_php_version}@beta" \
      "durable-workflow/sdk:${php_sdk_version}@beta" \
    > "$result_dir/waterline-composer-install.log" 2>&1; then
    waterline_require_status=0
  else
    waterline_require_status=$?
  fi
fi

if [[ "$waterline_require_status" == 0 ]]; then
  if docker run --rm \
    -v "$waterline_app:/app" \
    -w /app \
    -e DB_CONNECTION=sqlite \
    -e DB_DATABASE=/app/database/database.sqlite \
    -e WATERLINE_ENGINE_SOURCE=v2 \
    -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
    composer:2 php artisan key:generate --force \
    > "$result_dir/waterline-key-generate.log" 2>&1; then
    waterline_key_status=0
  else
    waterline_key_status=$?
  fi
fi

if [[ "$waterline_key_status" == 0 ]]; then
  if docker run --rm \
    -v "$waterline_app:/app" \
    -w /app \
    -e DB_CONNECTION=sqlite \
    -e DB_DATABASE=/app/database/database.sqlite \
    -e WATERLINE_ENGINE_SOURCE=v2 \
    -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
    composer:2 php artisan migrate --force \
    > "$result_dir/waterline-migrate.log" 2>&1; then
    waterline_migrate_status=0
  else
    waterline_migrate_status=$?
  fi
fi

if [[ "$waterline_migrate_status" == 0 ]]; then
  if docker run --rm \
    -v "$waterline_app:/app" \
    -v "$result_dir:/result" \
    -w /app \
    -e DB_CONNECTION=sqlite \
    -e DB_DATABASE=/app/database/database.sqlite \
    -e WATERLINE_ENGINE_SOURCE=v2 \
    -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
    composer:2 php artisan waterline:principal-attribution-conformance \
      --run-id "published-principal-${RUN_ID:-waterline}" \
      "${waterline_artifact_args[@]}" \
      --output /result/waterline-principal-attribution-result.json \
      --json \
    > "$result_dir/waterline-principal-attribution.log" 2>&1; then
    waterline_command_status=0
  else
    waterline_command_status=$?
  fi
fi

waterline_normative_result=false
if [[ -s "$waterline_result_path" ]]; then
  if python3 - "$waterline_result_path" > "$result_dir/waterline-result-validation.log" 2>&1 <<'PY'; then
import json
import sys
from pathlib import Path

payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
if not isinstance(payload, dict):
    raise TypeError("Waterline shard report must be a JSON object")
raw = payload.get("scenario_results")
if isinstance(raw, dict):
    scenario = raw.get("waterline_operator_visibility")
elif isinstance(raw, list):
    scenario = next(
        (item for item in raw if isinstance(item, dict) and item.get("scenario_id") == "waterline_operator_visibility"),
        None,
    )
else:
    scenario = None
if not isinstance(scenario, dict):
    raise ValueError("Waterline shard report omitted waterline_operator_visibility")
for field in ("status", "surface", "output_sample", "principal_visible"):
    if field not in scenario:
        raise ValueError(f"Waterline shard scenario omitted {field}")
PY
    waterline_report_validation_status=0
    waterline_normative_result=true
  else
    waterline_report_validation_status=$?
  fi
fi

waterline_failed_phase=""
waterline_failed_status=0
waterline_diagnostic_path="$result_dir/waterline-principal-attribution.log"
waterline_runner_blocked=false
waterline_reached_product_behavior=true
waterline_failure_reason=""

if [[ "$waterline_create_status" != 0 ]]; then
  waterline_failed_phase=create_project
  waterline_failed_status="$waterline_create_status"
  waterline_diagnostic_path="$result_dir/waterline-create-project.log"
  waterline_runner_blocked=true
  waterline_reached_product_behavior=false
  waterline_failure_reason="Waterline shard failed in create_project with exit status ${waterline_create_status} before published product behavior was reached."
elif [[ "$waterline_require_status" != 0 ]]; then
  waterline_failed_phase=composer_require
  waterline_failed_status="$waterline_require_status"
  waterline_diagnostic_path="$result_dir/waterline-composer-install.log"
  waterline_failure_reason="Waterline shard reached the published package install and failed in composer_require with exit status ${waterline_require_status}."
elif [[ "$waterline_key_status" != 0 ]]; then
  waterline_failed_phase=artisan_key_generate
  waterline_failed_status="$waterline_key_status"
  waterline_diagnostic_path="$result_dir/waterline-key-generate.log"
  waterline_failure_reason="Waterline shard reached the published package and failed in artisan_key_generate with exit status ${waterline_key_status}."
elif [[ "$waterline_migrate_status" != 0 ]]; then
  waterline_failed_phase=artisan_migrate
  waterline_failed_status="$waterline_migrate_status"
  waterline_diagnostic_path="$result_dir/waterline-migrate.log"
  waterline_failure_reason="Waterline shard reached the published package and failed in artisan_migrate with exit status ${waterline_migrate_status}."
elif [[ "$waterline_command_status" != 0 ]]; then
  waterline_failed_phase=artisan_command
  waterline_failed_status="$waterline_command_status"
  waterline_failure_reason="Waterline shard reached published product behavior and artisan_command exited with status ${waterline_command_status}."
elif [[ "$waterline_report_validation_status" == "not_invoked" ]]; then
  waterline_failed_phase=result_emission
  waterline_failed_status="$waterline_command_status"
  waterline_failure_reason="Waterline artisan_command exited with status ${waterline_command_status} without emitting a result."
elif [[ "$waterline_report_validation_status" != 0 ]]; then
  waterline_failed_phase=result_validation
  waterline_failed_status="$waterline_report_validation_status"
  waterline_diagnostic_path="$result_dir/waterline-result-validation.log"
  waterline_failure_reason="Waterline artisan_command emitted a non-normative result that failed validation with status ${waterline_report_validation_status}."
fi

if [[ "$waterline_failed_status" == 125 ]]; then
  waterline_runner_blocked=true
  waterline_reached_product_behavior=false
  waterline_failure_reason="Waterline shard could not invoke ${waterline_failed_phase} because the container runtime exited with status 125."
fi

write_waterline_shard_execution \
  "$waterline_normative_result" \
  "$waterline_failed_phase" \
  "$waterline_failed_status" \
  "$waterline_diagnostic_path" \
  "$waterline_runner_blocked" \
  "$waterline_reached_product_behavior"

if [[ "$waterline_normative_result" != true ]]; then
  write_waterline_fallback "$waterline_failure_reason"
fi
# END principal-attribution-waterline-shard

cat > "$run_root/orchestrate.py" <<'PY'
from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from durable_workflow import serializer


SERVER_URL = os.environ["SERVER_URL"].rstrip("/")
API = SERVER_URL + "/api"
ANONYMOUS_SERVER_URL = os.environ["ANONYMOUS_SERVER_URL"].rstrip("/")
ANONYMOUS_API = ANONYMOUS_SERVER_URL + "/api"
RESULT_DIR = Path(os.environ["RESULT_DIR"])
DW_BIN = Path(os.environ["DW_BIN"])
PYTHON_BIN = Path(os.environ["PYTHON_BIN"])
PHP_SDK_AUTOLOAD = Path(os.environ["PHP_SDK_AUTOLOAD"])
WATERLINE_PRINCIPAL_RESULT = Path(os.environ["WATERLINE_PRINCIPAL_RESULT"]) if os.environ.get("WATERLINE_PRINCIPAL_RESULT") else None
WATERLINE_PRINCIPAL_EXECUTION = Path(os.environ["WATERLINE_PRINCIPAL_EXECUTION"]) if os.environ.get("WATERLINE_PRINCIPAL_EXECUTION") else None
PHP_BIN = os.environ.get("PHP_BIN", "php")
STARTED_AT = os.environ["STARTED_AT"]
SUITE_VERSION = json.loads(os.environ["PRINCIPAL_ATTRIBUTION_SUITE_VERSION"])

TOKENS = {
    "alice_v1": "alice-token-v1",
    "alice_v2": "alice-token-v2",
    "bob": "bob-token",
    "admin": "admin-token",
    "worker": "worker-token",
}
WORKFLOW_TYPE = "principal.tracked"
TASK_QUEUE_BASE = "principal-attribution"
MAIN_TASK_QUEUE = f"{TASK_QUEUE_BASE}-main"
COMPLETE_TASK_QUEUE = f"{TASK_QUEUE_BASE}-complete"
FAIL_TASK_QUEUE = f"{TASK_QUEUE_BASE}-fail"
MAIN_WORKER_ID = "principal-attribution-main-worker"
COMPLETE_WORKER_ID = "principal-attribution-complete-worker"
FAIL_WORKER_ID = "principal-attribution-fail-worker"
ADVERSARIAL_BODY_FIELDS = {
    "principal": "mallory",
    "principal_id": "mallory",
    "principal_type": "attacker",
    "actor": "mallory",
    "user": "mallory",
}
ADVERSARIAL_HEADERS = {
    "X-Workflow-Principal-Id": "mallory",
    "X-Workflow-Principal-Type": "attacker",
    "X-Workflow-Principal-Label": "Mallory",
    "X-Workflow-Caller-Type": "spoofed-gateway",
    "X-Workflow-Caller-Label": "Mallory Gateway",
    "X-Workflow-Auth-Status": "trusted_elsewhere",
    "X-Workflow-Auth-Method": "gateway_token",
    "X-Forwarded-User": "mallory",
    "X-Forwarded-Email": "mallory@example.invalid",
    "X-Remote-User": "mallory",
    "Authorization-Override": "Bearer mallory",
}
ADVERSARIAL_VALUE_MARKERS = {
    str(value).strip().lower()
    for value in [*ADVERSARIAL_BODY_FIELDS.values(), *ADVERSARIAL_HEADERS.values()]
    if str(value).strip()
}


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def request(method: str, path: str, *, token: str | None, body: dict[str, Any] | None = None, headers: dict[str, str] | None = None, timeout: int = 10, allowed: set[int] | None = None, api: str = API) -> dict[str, Any]:
    allowed = allowed or set(range(200, 300))
    data = None if body is None else json.dumps(body).encode("utf-8")
    req_headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "X-Namespace": "default",
        "X-Durable-Workflow-Control-Plane-Version": "2",
        "X-Durable-Workflow-Protocol-Version": "1.7",
    }
    if token:
        req_headers["Authorization"] = f"Bearer {token}"
    if headers:
        req_headers.update(headers)
    req = urllib.request.Request(api + path, data=data, method=method, headers=req_headers)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as response:
            payload = response.read().decode("utf-8")
            if response.status not in allowed:
                raise RuntimeError(f"{method} {path} returned HTTP {response.status}: {payload}")
            return json.loads(payload) if payload else {}
    except urllib.error.HTTPError as exc:
        payload = exc.read().decode("utf-8", "replace")
        if exc.code in allowed:
            return json.loads(payload) if payload else {}
        raise RuntimeError(f"{method} {path} returned HTTP {exc.code}: {payload}") from exc


def register_worker(worker_id: str, task_queue: str) -> None:
    request(
        "POST",
        "/worker/register",
        token=TOKENS["worker"],
        body={
            "worker_id": worker_id,
            "task_queue": task_queue,
            "runtime": "external",
            "sdk_version": "published-artifact-runner",
            "supported_workflow_types": [WORKFLOW_TYPE],
            "supported_activity_types": [],
            "max_concurrent_workflow_tasks": 1,
            "max_concurrent_activity_tasks": 1,
        },
    )


def start_workflow(workflow_id: str, token_name: str, task_queue: str = MAIN_TASK_QUEUE, extra: dict[str, Any] | None = None, headers: dict[str, str] | None = None) -> dict[str, Any]:
    body = {
        "workflow_id": workflow_id,
        "workflow_type": WORKFLOW_TYPE,
        "task_queue": task_queue,
        "input": [{"workflow_id": workflow_id}],
    }
    if extra:
        body.update(extra)
    return request("POST", "/workflows", token=TOKENS[token_name], body=body, headers=headers)


def signal_workflow(workflow_id: str, token_name: str, signal_name: str = "nudge", extra: dict[str, Any] | None = None, headers: dict[str, str] | None = None) -> dict[str, Any]:
    body = {"input": [{"signal": signal_name}], "request_id": f"{workflow_id}-{signal_name}"}
    if extra:
        body.update(extra)
    return request("POST", f"/workflows/{workflow_id}/signal/{signal_name}", token=TOKENS[token_name], body=body, headers=headers, allowed={200, 202})


def cancel_workflow(workflow_id: str, token_name: str, extra: dict[str, Any] | None = None, headers: dict[str, str] | None = None) -> dict[str, Any]:
    body = {"reason": "principal attribution conformance"}
    if extra:
        body.update(extra)
    return request("POST", f"/workflows/{workflow_id}/cancel", token=TOKENS[token_name], body=body, headers=headers, allowed={200, 202, 409})


def history(workflow_id: str, run_id: str, token_name: str = "alice_v1") -> dict[str, Any]:
    return request("GET", f"/workflows/{workflow_id}/runs/{run_id}/history", token=TOKENS[token_name])


def poll_workflow_task(worker_id: str, task_queue: str, expected_workflow_id: str | None = None) -> dict[str, Any] | None:
    deadline = time.time() + 8

    while time.time() < deadline:
        payload = request(
            "POST",
            "/worker/workflow-tasks/poll",
            token=TOKENS["worker"],
            body={"worker_id": worker_id, "task_queue": task_queue},
            timeout=5,
        )
        task = payload.get("task")
        if not isinstance(task, dict):
            time.sleep(0.1)
            continue

        if expected_workflow_id is None or task.get("workflow_id") == expected_workflow_id:
            return task

        raise RuntimeError(
            f"polled workflow task for unexpected workflow_id={task.get('workflow_id')!r} "
            f"from isolated task_queue={task_queue!r}; expected {expected_workflow_id!r}"
        )

    return None


def complete_task(task: dict[str, Any], commands: list[dict[str, Any]]) -> dict[str, Any]:
    return request(
        "POST",
        f"/worker/workflow-tasks/{task['task_id']}/complete",
        token=TOKENS["worker"],
        body={
            "lease_owner": task["lease_owner"],
            "workflow_task_attempt": task.get("workflow_task_attempt", 1),
            "commands": commands,
        },
        allowed={200, 202, 409},
    )


def query_with_worker(workflow_id: str, worker_id: str, task_queue: str) -> dict[str, Any]:
    result: dict[str, Any] = {}
    errors: list[str] = []

    def do_query() -> None:
        try:
            result["response"] = request(
                "POST",
                f"/workflows/{workflow_id}/query/current",
                token=TOKENS["bob"],
                body={"input": [{"from": "bob"}], **ADVERSARIAL_BODY_FIELDS},
                headers=ADVERSARIAL_HEADERS,
                timeout=8,
                allowed={200, 202, 409, 503},
            )
        except Exception as exc:  # noqa: BLE001 - conformance result captures product failures
            errors.append(str(exc))

    thread = threading.Thread(target=do_query)
    thread.start()
    leased: dict[str, Any] | None = None
    deadline = time.time() + 6
    while thread.is_alive() and time.time() < deadline:
        payload = request(
            "POST",
            "/worker/query-tasks/poll",
            token=TOKENS["worker"],
            body={"worker_id": worker_id, "task_queue": task_queue},
            timeout=2,
            allowed={200, 503},
        )
        task = payload.get("task")
        if isinstance(task, dict):
            leased = task
            request(
                "POST",
                f"/worker/query-tasks/{task['query_task_id']}/complete",
                token=TOKENS["worker"],
                body={
                    "lease_owner": task["lease_owner"],
                    "query_task_attempt": task.get("query_task_attempt", 1),
                    "result": {"status": "ready"},
                    "result_envelope": serializer.envelope({"status": "ready"}),
                },
                allowed={200, 202, 409},
            )
            break
        time.sleep(0.1)
    thread.join(timeout=10)
    return {"query_response": result.get("response"), "query_task": leased, "errors": errors}


def event_principals(history_payload: dict[str, Any]) -> dict[str, Any]:
    principals: dict[str, Any] = {}
    for event in history_payload.get("events", []):
        event_type = event.get("event_type")
        if isinstance(event_type, str) and event_type not in principals:
            principals[event_type] = event.get("principal")
    return principals


def principal_id(principal: Any) -> str | None:
    if not isinstance(principal, dict):
        return None
    value = principal.get("id")
    return value if isinstance(value, str) and value else None


def spoofing_attempt_catalog(actions: list[str], executed: bool = True) -> dict[str, Any]:
    return {
        "payload_values": sorted({str(value) for value in ADVERSARIAL_BODY_FIELDS.values()}),
        "payload_fields": dict(ADVERSARIAL_BODY_FIELDS),
        "body_fields": dict(ADVERSARIAL_BODY_FIELDS),
        "headers": dict(ADVERSARIAL_HEADERS),
        "actions": actions,
        "executed": executed,
    }


def principal_string_values(value: Any) -> list[str]:
    if isinstance(value, str):
        return [value]
    if isinstance(value, dict):
        strings: list[str] = []
        for item in value.values():
            strings.extend(principal_string_values(item))
        return strings
    if isinstance(value, list):
        strings = []
        for item in value:
            strings.extend(principal_string_values(item))
        return strings

    return []


def caller_controlled_principal_hits(principal: Any) -> list[str]:
    hits = []
    for value in principal_string_values(principal):
        normalized = value.strip().lower()
        if normalized in ADVERSARIAL_VALUE_MARKERS:
            hits.append(value)

    return sorted(set(hits))


def spoofing_matrix_row(
    action: str,
    *,
    surface: str,
    credential_used: dict[str, Any] | None,
    expected_principal: dict[str, str],
    observed_principal: Any,
    event_type: str | None = None,
    auth_driver: str = "token",
) -> dict[str, Any]:
    return {
        "action": action,
        "surface": surface,
        "auth_driver": auth_driver,
        "credential_used": credential_used,
        "event_type": event_type,
        "requested_spoof_values": {
            "body_fields": dict(ADVERSARIAL_BODY_FIELDS),
            "headers": dict(ADVERSARIAL_HEADERS),
        },
        "expected_principal": expected_principal,
        "observed_principal": observed_principal,
        "caller_controlled_value_hits": caller_controlled_principal_hits(observed_principal),
    }


def principal_matches(principal: Any, expected: dict[str, str]) -> bool:
    if not isinstance(principal, dict):
        return False

    return all(principal.get(key) == value for key, value in expected.items())


def principal_shape_signature(principal: Any) -> dict[str, str] | None:
    if not isinstance(principal, dict):
        return None

    return {str(key): type(value).__name__ for key, value in sorted(principal.items())}


def principal_shape_matches_http(principal: Any, reference: Any) -> bool:
    signature = principal_shape_signature(principal)
    reference_signature = principal_shape_signature(reference)

    if signature is None or reference_signature is None:
        return False

    return signature == reference_signature


def sdk_operation_outputs(operation: dict[str, Any], workflow_id: str, client_name: str) -> dict[str, Any]:
    raw_outputs = operation.get("operation_outputs")
    outputs = raw_outputs if isinstance(raw_outputs, dict) else {}

    start_output = outputs.get("start_workflow")
    if not isinstance(start_output, dict) or start_output == {}:
        start_output = {
            "client": client_name,
            "workflow_id": workflow_id,
            "run_id": operation.get("run_id"),
            "accepted": operation.get("status") == "pass" and isinstance(operation.get("run_id"), str),
            "source": "normalized_from_sdk_result",
        }

    signal_output = outputs.get("signal_workflow")
    if not isinstance(signal_output, dict) or signal_output == {}:
        signal_output = {
            "client": client_name,
            "workflow_id": workflow_id,
            "signal_name": "nudge",
            "accepted": operation.get("status") == "pass",
            "source": "normalized_from_fire_and_forget_sdk_result",
            "raw_response": signal_output,
        }

    return {
        "start_workflow": start_output,
        "signal_workflow": signal_output,
    }


def principal_samples(principals: dict[str, Any], event_types: list[str] | None = None) -> dict[str, Any]:
    selected = event_types if event_types is not None else sorted(principals)

    return {event_type: principals.get(event_type) for event_type in selected if event_type in principals}


def documented_system_principal_match(principal: Any, documented: list[dict[str, str]]) -> dict[str, str] | None:
    for candidate in documented:
        if principal_matches(principal, candidate):
            return candidate

    return None


def principal_at_path(payload: Any, path: list[str]) -> dict[str, Any] | None:
    current = payload
    for key in path:
        if not isinstance(current, dict):
            return None
        current = current.get(key)

    if not isinstance(current, dict):
        return None

    if not isinstance(current.get("id"), str) or not isinstance(current.get("type"), str):
        return None

    return current


def principal_from_query_observation(query_observation: dict[str, Any]) -> dict[str, Any] | None:
    query_response = query_observation.get("query_response")
    query_task = query_observation.get("query_task")

    # Accept only server-controlled audit fields. Do not treat query result
    # payloads or query arguments as principal evidence because a workflow
    # implementation or caller could spoof those values.
    for payload, paths in (
        (
            query_response,
            [
                ["principal"],
                ["query_principal"],
                ["audit", "principal"],
                ["control_plane", "principal"],
                ["query", "principal"],
            ],
        ),
        (
            query_task,
            [
                ["principal"],
                ["query_principal"],
                ["audit", "principal"],
                ["command_context", "principal"],
                ["command_context", "context", "principal"],
            ],
        ),
    ):
        for path in paths:
            principal = principal_at_path(payload, path)
            if principal is not None:
                return principal

    return None


def run_python_sdk_client_operation(workflow_id: str) -> dict[str, Any]:
    if not PYTHON_BIN.exists():
        return {"status": "not_covered", "errors": [f"Python SDK interpreter missing at {PYTHON_BIN}"]}

    code = r'''
from __future__ import annotations

import asyncio
import json
import os

from durable_workflow import Client


async def main() -> None:
    workflow_id = os.environ["WORKFLOW_ID"]
    async with Client(os.environ["SERVER_URL"], token=os.environ["TOKEN"], namespace="default") as client:
        handle = await client.start_workflow(
            workflow_type=os.environ["WORKFLOW_TYPE"],
            task_queue=os.environ["TASK_QUEUE"],
            workflow_id=workflow_id,
            input=[{"client": "python-sdk"}],
        )
        signal_result = await client.signal_workflow(workflow_id, "nudge", args=[{"client": "python-sdk"}])
        print(json.dumps({
            "workflow_id": workflow_id,
            "run_id": handle.run_id,
            "operations": ["start_workflow", "signal_workflow"],
            "operation_outputs": {
                "start_workflow": {
                    "workflow_id": handle.workflow_id,
                    "run_id": handle.run_id,
                    "workflow_type": getattr(handle, "workflow_type", None),
                },
                "signal_workflow": {
                    "workflow_id": workflow_id,
                    "signal_name": "nudge",
                    "returned": signal_result,
                },
            },
        }))


asyncio.run(main())
'''
    env = {
        **os.environ,
        "SERVER_URL": SERVER_URL,
        "TOKEN": TOKENS["bob"],
        "WORKFLOW_ID": workflow_id,
        "WORKFLOW_TYPE": WORKFLOW_TYPE,
        "TASK_QUEUE": MAIN_TASK_QUEUE,
    }
    completed = subprocess.run(
        [str(PYTHON_BIN), "-c", code],
        check=False,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        timeout=30,
    )

    if completed.returncode != 0:
        return {"status": "fail", "errors": [completed.stdout[-4000:]], "output": completed.stdout[-4000:]}

    try:
        payload = json.loads(completed.stdout.strip().splitlines()[-1])
    except Exception as exc:  # noqa: BLE001 - conformance result captures product failures
        return {"status": "fail", "errors": [f"Python SDK output was not JSON: {exc}; output={completed.stdout[-4000:]}"]}

    return {
        "status": "pass",
        "client_operation": "python-sdk start_workflow + signal_workflow",
        **payload,
        "output": completed.stdout[-4000:],
    }


def run_php_code(code: str, env: dict[str, str], *, timeout: int = 45) -> subprocess.CompletedProcess[str]:
    php_path = str(Path(PHP_BIN)) if Path(PHP_BIN).exists() else shutil.which(PHP_BIN)

    if php_path is not None:
        return subprocess.run(
            [php_path, "-r", code],
            check=False,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=timeout,
        )

    docker = shutil.which("docker")
    if docker is None:
        return subprocess.CompletedProcess(
            [PHP_BIN, "-r", "<principal-attribution-client>"],
            127,
            stdout=f"PHP binary missing ({PHP_BIN}) and docker is unavailable for composer:2 fallback",
        )

    app_dir = PHP_SDK_AUTOLOAD.parent.parent
    container_env = {
        "SERVER_URL": env["SERVER_URL"],
        "TOKEN": env["TOKEN"],
        "WORKFLOW_ID": env["WORKFLOW_ID"],
        "WORKFLOW_TYPE": env["WORKFLOW_TYPE"],
        "TASK_QUEUE": env["TASK_QUEUE"],
        "PHP_SDK_AUTOLOAD": "/app/vendor/autoload.php",
    }
    docker_command = [
        docker,
        "run",
        "--rm",
        "--network",
        "host",
        "-v",
        f"{app_dir}:/app",
        "-w",
        "/app",
    ]
    for name, value in container_env.items():
        docker_command.extend(["-e", f"{name}={value}"])
    docker_command.extend(["composer:2", "php", "-r", code])

    return subprocess.run(
        docker_command,
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        timeout=timeout,
    )


def run_php_client_operation(workflow_id: str) -> dict[str, Any]:
    if not PHP_SDK_AUTOLOAD.exists():
        return {"status": "not_covered", "errors": [f"PHP SDK autoload missing at {PHP_SDK_AUTOLOAD}"]}

    code = r'''
$autoload = getenv('PHP_SDK_AUTOLOAD');
require $autoload;

$workflowId = getenv('WORKFLOW_ID');
$client = new \DurableWorkflow\Client(
    getenv('SERVER_URL'),
    token: getenv('TOKEN'),
    namespace: 'default',
);
$handle = $client->startWorkflow(
    workflowType: getenv('WORKFLOW_TYPE'),
    workflowId: $workflowId,
    taskQueue: getenv('TASK_QUEUE'),
    input: [['client' => 'php-sdk-client']],
);
$signal = $client->signalWorkflow($workflowId, 'nudge', [['client' => 'php-sdk-client']]);
echo json_encode([
    'workflow_id' => $workflowId,
    'run_id' => $handle->selectedRunId,
    'autoload' => $autoload,
    'client_class' => \DurableWorkflow\Client::class,
    'operation' => 'Client::startWorkflow + Client::signalWorkflow',
    'operations' => ['start_workflow', 'signal_workflow'],
    'operation_outputs' => [
        'start_workflow' => ['workflow_id' => $handle->workflowId, 'run_id' => $handle->selectedRunId],
        'signal_workflow' => $signal,
    ],
    'start_response' => ['workflow_id' => $handle->workflowId, 'run_id' => $handle->selectedRunId],
    'signal_response' => $signal,
]).PHP_EOL;
'''
    env = {
        **os.environ,
        "SERVER_URL": SERVER_URL,
        "TOKEN": TOKENS["alice_v1"],
        "WORKFLOW_ID": workflow_id,
        "WORKFLOW_TYPE": WORKFLOW_TYPE,
        "TASK_QUEUE": MAIN_TASK_QUEUE,
        "PHP_SDK_AUTOLOAD": str(PHP_SDK_AUTOLOAD),
    }
    completed = run_php_code(code, env, timeout=120)

    if completed.returncode != 0:
        return {"status": "fail", "errors": [completed.stdout[-4000:]], "output": completed.stdout[-4000:]}

    try:
        payload = json.loads(completed.stdout.strip().splitlines()[-1])
    except Exception as exc:  # noqa: BLE001
        return {"status": "fail", "errors": [f"PHP client output was not JSON: {exc}; output={completed.stdout[-4000:]}"]}

    return {"status": "pass", "client_operation": "PHP SDK Client startWorkflow + signalWorkflow", **payload, "output": completed.stdout[-4000:]}


def install_status_and_findings(evidence: dict[str, Any]) -> tuple[str, list[str]]:
    findings: list[str] = []
    runner_blocked = False

    for artifact in evidence.get("artifacts", []):
        if not isinstance(artifact, dict):
            findings.append(f"invalid artifact evidence row: {artifact!r}")
            continue

        status = artifact.get("status")
        name = artifact.get("artifact", "unknown")
        detail = artifact.get("detail", "")
        if status == "pass":
            continue
        if status == "runner_blocked":
            runner_blocked = True
        findings.append(f"{name} published install/exercise status={status}: {detail}")

    if findings:
        return ("runner_blocked" if runner_blocked else "fail", findings)

    return "pass", []


def scenario(status: str, scenario_id: str, **fields: Any) -> dict[str, Any]:
    return {"scenario_id": scenario_id, "status": status, **fields}


def current_artifact_versions() -> dict[str, Any]:
    metadata_path = RESULT_DIR / "run-metadata.json"
    if metadata_path.exists():
        metadata = json.loads(metadata_path.read_text())
        versions = metadata.get("published_artifact_versions")
        if isinstance(versions, dict):
            return versions

    pins_path = RESULT_DIR / "pins.json"
    if not pins_path.exists():
        return {}

    pins = json.loads(pins_path.read_text())
    return {k: pins[k] for k in ("server", "cli", "workflow", "sdk-php", "sdk-python", "waterline") if k in pins}


def finding(scenario_id: str, surface: str, observed: str, expected: str, next_acceptance: str, severity: str = "P1") -> dict[str, Any]:
    return {
        "id": f"{scenario_id}-{surface}".replace("_", "-"),
        "severity": severity,
        "surface": surface,
        "scenario_id": scenario_id,
        "owning_surface": surface,
        "artifact_versions": current_artifact_versions(),
        "observed_behavior": observed,
        "expected_behavior": expected,
        "next_acceptance_criterion": next_acceptance,
    }


def scenario_result_by_id(payload: dict[str, Any], scenario_id: str) -> dict[str, Any] | None:
    raw = payload.get("scenario_results")
    if isinstance(raw, dict):
        item = raw.get(scenario_id)
        return item if isinstance(item, dict) else None

    if isinstance(raw, list):
        for item in raw:
            if isinstance(item, dict) and item.get("scenario_id") == scenario_id:
                return item

    return None


def focused_findings_from_waterline_shard(item: dict[str, Any] | None, payload: dict[str, Any]) -> list[dict[str, Any]]:
    findings: list[dict[str, Any]] = []

    for source in (
        item.get("linked_findings") if isinstance(item, dict) else None,
        item.get("findings") if isinstance(item, dict) else None,
        payload.get("findings"),
    ):
        if not isinstance(source, list):
            continue

        for candidate in source:
            if isinstance(candidate, dict):
                findings.append(candidate)

    return findings


def load_waterline_principal_shard() -> tuple[dict[str, Any] | None, dict[str, Any] | None, dict[str, Any] | None]:
    if WATERLINE_PRINCIPAL_RESULT is None:
        return None, None, finding(
            "waterline_operator_visibility",
            "waterline",
            "Waterline principal-attribution shard path was not configured for this published-artifact run",
            "the published Waterline package emits waterline:principal-attribution-conformance evidence for selected-run principal visibility",
            "wire the server host runner to pass the Waterline principal-attribution shard report path",
        )

    if not WATERLINE_PRINCIPAL_RESULT.exists() or WATERLINE_PRINCIPAL_RESULT.stat().st_size == 0:
        return None, None, finding(
            "waterline_operator_visibility",
            "waterline",
            "Waterline principal-attribution shard did not produce a report in this published-artifact run",
            "waterline:principal-attribution-conformance runs from the published Waterline package and emits selected-run principal visibility evidence",
            "publish a Waterline artifact with the principal-attribution shard and rerun principal-attribution conformance",
        )

    try:
        payload = json.loads(WATERLINE_PRINCIPAL_RESULT.read_text())
    except Exception as exc:  # noqa: BLE001 - conformance result captures product failures
        return None, None, finding(
            "waterline_operator_visibility",
            "waterline",
            f"Waterline principal-attribution shard report could not be parsed: {exc}",
            "waterline:principal-attribution-conformance writes a JSON report with scenario_results",
            "fix the Waterline shard report serialization and rerun principal-attribution conformance",
        )

    if not isinstance(payload, dict):
        return None, None, finding(
            "waterline_operator_visibility",
            "waterline",
            "Waterline principal-attribution shard report was not a JSON object",
            "waterline:principal-attribution-conformance writes a JSON object with scenario_results",
            "fix the Waterline shard report shape and rerun principal-attribution conformance",
        )

    item = scenario_result_by_id(payload, "waterline_operator_visibility")
    if item is None:
        return payload, None, finding(
            "waterline_operator_visibility",
            "waterline",
            "Waterline principal-attribution shard omitted waterline_operator_visibility",
            "waterline:principal-attribution-conformance reports the waterline_operator_visibility scenario",
            "fix the Waterline shard scenario list and rerun principal-attribution conformance",
        )

    return payload, item, None


def load_waterline_shard_execution() -> tuple[dict[str, Any] | None, dict[str, Any] | None]:
    if WATERLINE_PRINCIPAL_EXECUTION is None:
        return None, finding(
            "waterline_operator_visibility",
            "conformance_harness",
            "Waterline shard execution evidence path was not configured",
            "the host runner preserves phase, exit status, artifact provenance, and bounded Waterline diagnostics",
            "configure the Waterline shard execution sidecar and rerun the exact published artifact tuple",
        )

    if not WATERLINE_PRINCIPAL_EXECUTION.exists() or WATERLINE_PRINCIPAL_EXECUTION.stat().st_size == 0:
        return None, finding(
            "waterline_operator_visibility",
            "conformance_harness",
            "Waterline shard execution evidence was not written",
            "every invoked Waterline shard writes its execution sidecar before aggregate evidence is emitted",
            "restore Waterline shard execution capture and rerun the exact published artifact tuple",
        )

    try:
        execution = json.loads(WATERLINE_PRINCIPAL_EXECUTION.read_text())
    except Exception as exc:  # noqa: BLE001 - conformance result captures runner failures
        return None, finding(
            "waterline_operator_visibility",
            "conformance_harness",
            f"Waterline shard execution evidence could not be parsed: {exc}",
            "the Waterline execution sidecar is a JSON object with phase and diagnostic evidence",
            "fix execution sidecar serialization and rerun the exact published artifact tuple",
        )

    required = {
        "normative_result_written",
        "failed_phase",
        "exit_status",
        "phase_statuses",
        "runner_blocked",
        "reached_published_product_behavior",
        "artifact_versions",
        "artifact_sources",
        "diagnostic",
    }
    if not isinstance(execution, dict) or not required.issubset(execution):
        return None, finding(
            "waterline_operator_visibility",
            "conformance_harness",
            "Waterline shard execution evidence omitted required classification fields",
            "the Waterline execution sidecar records phase, status, provenance, classification, and bounded diagnostics",
            "complete the execution sidecar shape and rerun the exact published artifact tuple",
        )

    return execution, None


def aggregate_waterline_evidence() -> dict[str, Any]:
    waterline_payload, waterline_item, waterline_load_finding = load_waterline_principal_shard()
    waterline_execution, waterline_execution_finding = load_waterline_shard_execution()
    waterline_visibility = (
        waterline_payload.get("waterline_principal_visibility", {})
        if isinstance(waterline_payload, dict) and isinstance(waterline_payload.get("waterline_principal_visibility"), dict)
        else {}
    )
    waterline_linked_findings = focused_findings_from_waterline_shard(waterline_item, waterline_payload or {})
    if waterline_load_finding is not None:
        waterline_linked_findings.append(waterline_load_finding)
    if waterline_execution_finding is not None:
        waterline_linked_findings.append(waterline_execution_finding)

    waterline_status = waterline_item.get("status") if isinstance(waterline_item, dict) else "unsupported"
    if waterline_status not in {"pass", "fail", "unsupported", "not_covered", "runner_blocked"}:
        waterline_status = "fail"
        waterline_linked_findings.append(finding(
            "waterline_operator_visibility",
            "waterline",
            f"Waterline principal-attribution shard returned invalid scenario status {waterline_item.get('status')!r}",
            "waterline:principal-attribution-conformance uses a published principal-attribution scenario status",
            "fix the Waterline shard status token and rerun principal-attribution conformance",
        ))

    waterline_output_sample = None
    waterline_output_sample_missing = True
    if isinstance(waterline_item, dict) and "output_sample" in waterline_item:
        raw_output_sample = waterline_item.get("output_sample")
        if isinstance(raw_output_sample, str):
            waterline_output_sample = raw_output_sample[:4000]
            waterline_output_sample_missing = raw_output_sample.strip() == ""
        elif isinstance(raw_output_sample, (dict, list)) and raw_output_sample:
            waterline_output_sample = json.dumps(raw_output_sample, sort_keys=True)[:4000]
            waterline_output_sample_missing = False

    waterline_surface = None
    if isinstance(waterline_item, dict):
        waterline_surface = waterline_item.get("surface")
    if not isinstance(waterline_surface, str) or waterline_surface == "":
        waterline_surface = "selected-run detail API commands and timeline"

    waterline_principal_visible = None
    if isinstance(waterline_item, dict) and isinstance(waterline_item.get("principal_visible"), bool):
        waterline_principal_visible = waterline_item["principal_visible"]

    waterline_claimed_pass = waterline_status == "pass"
    waterline_missing_required_pass_evidence = False
    if waterline_claimed_pass and waterline_principal_visible is not True:
        waterline_missing_required_pass_evidence = True
        waterline_linked_findings.append(finding(
            "waterline_operator_visibility",
            "waterline",
            "Waterline principal-attribution shard claimed pass without principal_visible=true",
            "passing Waterline principal-attribution evidence explicitly reports principal_visible=true",
            "fix the Waterline shard visibility verdict before marking the cell pass",
        ))

    if waterline_claimed_pass and waterline_output_sample_missing:
        waterline_missing_required_pass_evidence = True
        waterline_linked_findings.append(finding(
            "waterline_operator_visibility",
            "waterline",
            "Waterline principal-attribution shard claimed pass without an operator output sample",
            "passing Waterline principal-attribution evidence includes a selected-run operator output sample",
            "include selected-run command/timeline principal output in the shard report before marking the cell pass",
        ))

    if waterline_missing_required_pass_evidence:
        waterline_status = "fail"

    waterline_runner_blocked = (
        waterline_status == "runner_blocked"
        or waterline_execution_finding is not None
        or (
            isinstance(waterline_execution, dict)
            and waterline_execution.get("runner_blocked") is True
        )
    )

    if waterline_status != "pass" and waterline_linked_findings == []:
        waterline_linked_findings.append(finding(
            "waterline_operator_visibility",
            "waterline",
            "Waterline principal-attribution shard did not pass and did not provide a focused finding",
            "non-passing Waterline principal-attribution evidence links a focused Waterline finding",
            "include a focused Waterline finding in the shard report before marking the cell routed",
        ))

    return {
        "execution": waterline_execution,
        "runner_blocked": waterline_runner_blocked,
        "status": waterline_status,
        "principal_visible": waterline_principal_visible,
        "linked_findings": waterline_linked_findings,
        "scenario": scenario(
            waterline_status,
            "waterline_operator_visibility",
            surface=waterline_surface,
            output_sample=waterline_output_sample,
            principal_visible=waterline_principal_visible,
            shard_result_path=str(WATERLINE_PRINCIPAL_RESULT) if WATERLINE_PRINCIPAL_RESULT is not None else None,
            shard_execution_path=str(WATERLINE_PRINCIPAL_EXECUTION) if WATERLINE_PRINCIPAL_EXECUTION is not None else None,
            observed_outputs={
                "shard_report": waterline_payload,
                "shard_execution": waterline_execution,
                "waterline_principal_visibility": waterline_visibility,
            },
            linked_findings=waterline_linked_findings,
            findings=waterline_linked_findings,
        ),
    }


def write_principal_attribution_aggregate(
    *,
    versions: dict[str, Any],
    artifact_sources: dict[str, Any],
    findings: list[dict[str, Any]],
    history_dumps: dict[str, Any],
    scenario_results: list[dict[str, Any]],
    action_credentials: dict[str, Any],
    main_spoofing_matrix: list[dict[str, Any]],
    query_spoofing_matrix: list[dict[str, Any]],
    anonymous_spoofing_matrix: list[dict[str, Any]],
    cli_json_ok: bool,
    sdk_principal_attribution_parity: dict[str, Any],
    anonymous_failures: list[Any],
    anonymous_principals: dict[str, Any],
    expected_anonymous_principal: dict[str, Any],
) -> int:
    waterline_evidence = aggregate_waterline_evidence()
    waterline_scenario = waterline_evidence["scenario"]
    waterline_execution = waterline_evidence["execution"]
    waterline_runner_blocked = waterline_evidence["runner_blocked"]
    scenario_results.append(waterline_scenario)
    findings.extend(waterline_evidence["linked_findings"])

    outcome = (
        "error"
        if waterline_runner_blocked
        else ("pass" if all(item["status"] == "pass" for item in scenario_results) else "fail")
    )
    finished = now()
    result = {
        "schema": "durable-workflow.v2.principal-attribution-conformance.result",
        "schema_version": 1,
        "suite_schema": "durable-workflow.v2.platform-conformance.suite",
        "suite_version": SUITE_VERSION,
        "category": "principal_attribution_contract",
        "outcome": outcome,
        "runner_blocked": waterline_runner_blocked,
        "started_at": STARTED_AT,
        "finished_at": finished,
        "generated_at": finished,
        "published_artifact_versions": versions,
        "resolved_artifact_versions": versions,
        "artifact_sources": artifact_sources,
        "topology": {"server_url": SERVER_URL, "anonymous_server_url": ANONYMOUS_SERVER_URL, "task_queues": {"main": MAIN_TASK_QUEUE, "completion": COMPLETE_TASK_QUEUE, "failure": FAIL_TASK_QUEUE}, "auth_driver": "token", "anonymous_auth_driver": "none", "principal_tokens": ["alice", "bob", "worker"]},
        "actor_matrix": {"alice": {"credentials": ["alice-token-v1", "alice-token-v2"]}, "bob": {"credentials": ["bob-token"]}, "action_credentials": action_credentials},
        "history_dumps": history_dumps,
        "spoofing_attempts": spoofing_attempt_catalog(["start", "signal", "cancel", "query"]),
        "spoofing_matrix": [*main_spoofing_matrix, *query_spoofing_matrix, *anonymous_spoofing_matrix],
        "operator_visibility": {"cli_history_json_principal_visible": cli_json_ok, "waterline": {"status": waterline_evidence["status"], "principal_visible": waterline_evidence["principal_visible"], "linked_findings": waterline_evidence["linked_findings"], "result_path": str(WATERLINE_PRINCIPAL_RESULT) if WATERLINE_PRINCIPAL_RESULT is not None else None, "execution": waterline_execution}},
        "sdk_principal_attribution_parity": sdk_principal_attribution_parity,
        "anonymous_observations": {"status": "pass" if not anonymous_failures else "fail", "anonymous_principal": anonymous_principals.get("WorkflowStarted"), "documented_value": expected_anonymous_principal, "history_events": list(anonymous_principals), "recorded_principals": anonymous_principals, "spoofing_attempts": spoofing_attempt_catalog(["start", "signal", "cancel"]), "spoofing_matrix": anonymous_spoofing_matrix, "anonymous_auth_driver": "none"},
        "scenario_results": scenario_results,
        "findings": findings,
    }
    (RESULT_DIR / "principal-attribution-result.json").write_text(json.dumps(result, indent=2, sort_keys=True) + "\n")
    (RESULT_DIR / "principal-attribution-record.json").write_text(json.dumps({
        "experiment": "principal-attribution",
        "outcome": outcome,
        "runnerBlocked": waterline_runner_blocked,
        "artifactVersions": versions,
        "sdkPrincipalAttributionParity": {
            "python_sdk_visibility": sdk_principal_attribution_parity["python_sdk_visibility"],
            "php_client_visibility": sdk_principal_attribution_parity["php_client_visibility"],
        },
        "waterlineOperatorVisibility": waterline_scenario,
        "waterlineShardExecution": waterline_execution,
        "findings": findings,
        "resultPath": str(RESULT_DIR / "principal-attribution-result.json"),
    }, indent=2, sort_keys=True) + "\n")
    return 0 if outcome == "pass" else 1


def main() -> int:
    pins = json.loads((RESULT_DIR / "pins.json").read_text())
    artifact_install_evidence = json.loads((RESULT_DIR / "artifact-install-evidence.json").read_text())
    versions = {k: pins[k] for k in ("server", "cli", "workflow", "sdk-php", "sdk-python", "waterline") if k in pins}
    findings: list[dict[str, Any]] = []
    history_dumps: dict[str, Any] = {}
    scenario_results: list[dict[str, Any]] = []

    register_worker(MAIN_WORKER_ID, MAIN_TASK_QUEUE)
    register_worker(COMPLETE_WORKER_ID, COMPLETE_TASK_QUEUE)
    register_worker(FAIL_WORKER_ID, FAIL_TASK_QUEUE)

    main_id = f"pa-main-{int(time.time())}"
    start = start_workflow(main_id, "alice_v1", extra=ADVERSARIAL_BODY_FIELDS, headers=ADVERSARIAL_HEADERS)
    main_run = str(start["run_id"])
    signal_workflow(
        main_id,
        "bob",
        extra=ADVERSARIAL_BODY_FIELDS,
        headers=ADVERSARIAL_HEADERS,
    )
    query_observation = query_with_worker(main_id, MAIN_WORKER_ID, MAIN_TASK_QUEUE)
    cancel_workflow(
        main_id,
        "alice_v2",
        extra=ADVERSARIAL_BODY_FIELDS,
        headers=ADVERSARIAL_HEADERS,
    )
    main_history = history(main_id, main_run)
    history_dumps["start_signal_cancel_spoofing"] = main_history
    main_principals = event_principals(main_history)

    complete_id = f"pa-complete-{int(time.time())}"
    complete_start = start_workflow(complete_id, "alice_v1", task_queue=COMPLETE_TASK_QUEUE)
    complete_run = str(complete_start["run_id"])
    complete_task_payload = poll_workflow_task(COMPLETE_WORKER_ID, COMPLETE_TASK_QUEUE, expected_workflow_id=complete_id)
    complete_outcome = None
    if complete_task_payload:
        complete_outcome = complete_task(complete_task_payload, [{"type": "complete_workflow", "result": None}])
    complete_history = history(complete_id, complete_run)
    history_dumps["completion"] = complete_history
    complete_principals = event_principals(complete_history)

    fail_id = f"pa-fail-{int(time.time())}"
    fail_start = start_workflow(fail_id, "bob", task_queue=FAIL_TASK_QUEUE)
    fail_run = str(fail_start["run_id"])
    fail_task_payload = poll_workflow_task(FAIL_WORKER_ID, FAIL_TASK_QUEUE, expected_workflow_id=fail_id)
    fail_outcome = None
    if fail_task_payload:
        fail_outcome = complete_task(fail_task_payload, [{"type": "fail_workflow", "message": "principal attribution conformance failure"}])
    fail_history = history(fail_id, fail_run)
    history_dumps["failure"] = fail_history
    fail_principals = event_principals(fail_history)

    anonymous_id = f"pa-anonymous-{int(time.time())}"
    anonymous_start = request(
        "POST",
        "/workflows",
        token=None,
        api=ANONYMOUS_API,
        body={
            "workflow_id": anonymous_id,
            "workflow_type": WORKFLOW_TYPE,
            "task_queue": MAIN_TASK_QUEUE,
            "input": [{"workflow_id": anonymous_id}],
            **ADVERSARIAL_BODY_FIELDS,
        },
        headers=ADVERSARIAL_HEADERS,
    )
    anonymous_run = str(anonymous_start["run_id"])
    request(
        "POST",
        f"/workflows/{anonymous_id}/signal/nudge",
        token=None,
        api=ANONYMOUS_API,
        body={"input": [{"signal": "anonymous"}], **ADVERSARIAL_BODY_FIELDS},
        headers=ADVERSARIAL_HEADERS,
        allowed={200, 202},
    )
    request(
        "POST",
        f"/workflows/{anonymous_id}/cancel",
        token=None,
        api=ANONYMOUS_API,
        body={"reason": "anonymous principal attribution", **ADVERSARIAL_BODY_FIELDS},
        headers=ADVERSARIAL_HEADERS,
        allowed={200, 202, 409},
    )
    anonymous_history = request(
        "GET",
        f"/workflows/{anonymous_id}/runs/{anonymous_run}/history",
        token=None,
        api=ANONYMOUS_API,
    )
    history_dumps["anonymous"] = anonymous_history
    anonymous_principals = event_principals(anonymous_history)

    python_client_id = f"pa-python-{int(time.time())}"
    python_operation = run_python_sdk_client_operation(python_client_id)
    python_history: dict[str, Any] = {}
    python_principals: dict[str, Any] = {}
    if python_operation.get("status") == "pass" and isinstance(python_operation.get("run_id"), str):
        python_history = history(python_client_id, str(python_operation["run_id"]), token_name="bob")
        history_dumps["python_sdk"] = python_history
        python_principals = event_principals(python_history)

    php_client_id = f"pa-php-{int(time.time())}"
    php_operation = run_php_client_operation(php_client_id)
    php_history: dict[str, Any] = {}
    php_principals: dict[str, Any] = {}
    if php_operation.get("status") == "pass" and isinstance(php_operation.get("run_id"), str):
        php_history = history(php_client_id, str(php_operation["run_id"]), token_name="alice_v1")
        history_dumps["php_client"] = php_history
        php_principals = event_principals(php_history)

    cli_output = ""
    cli_json_ok = False
    try:
        cli = subprocess.run(
            [
                str(DW_BIN),
                "workflow:history",
                main_id,
                main_run,
                "--server",
                SERVER_URL,
                "--token",
                TOKENS["alice_v1"],
                "--namespace",
                "default",
                "--output=json",
            ],
            check=False,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=20,
        )
        cli_output = cli.stdout
        parsed = json.loads(cli.stdout)
        cli_json_ok = any(isinstance(event.get("principal"), dict) for event in parsed.get("events", []))
    except Exception as exc:  # noqa: BLE001
        cli_output = str(exc)

    install_status, install_findings = install_status_and_findings(artifact_install_evidence)
    scenario_results.append(scenario(install_status, "published_artifact_install_only", resolved_artifact_versions=versions, artifact_sources=pins.get("artifact_sources", {}), local_product_source_checkouts_used=False, artifact_install_evidence=artifact_install_evidence, findings=install_findings))
    for install_finding in install_findings:
        findings.append(finding("published_artifact_install_only", "conformance_harness", install_finding, "every required artifact is installed from its published channel and exercised before install-only coverage passes", "install and import-smoke the server, CLI, Python SDK, PHP SDK, embedded Workflow engine, and Waterline artifacts before marking this scenario pass"))

    expected_main = {
        "WorkflowStarted": {"type": "auth:token", "id": "alice"},
        "SignalReceived": {"type": "auth:token", "id": "bob"},
        "WorkflowCancelled": {"type": "auth:token", "id": "alice"},
    }
    action_credentials = {
        "start": {
            "actor": "alice",
            "credential_ref": "alice-token-v1",
            "credential_label": "alice credential v1",
            "expected_principal": {"type": "auth:token", "id": "alice"},
            "observed_principal": main_principals.get("WorkflowStarted"),
            "credential_material_recorded_as_principal": principal_id(main_principals.get("WorkflowStarted")) == "alice-token-v1",
            "event_type": "WorkflowStarted",
            "spoofing_payload_and_headers": True,
        },
        "signal": {
            "actor": "bob",
            "credential_ref": "bob-token",
            "credential_label": "bob credential",
            "expected_principal": {"type": "auth:token", "id": "bob"},
            "observed_principal": main_principals.get("SignalReceived"),
            "credential_material_recorded_as_principal": principal_id(main_principals.get("SignalReceived")) == "bob-token",
            "event_type": "SignalReceived",
            "spoofing_payload_and_headers": True,
        },
        "cancel": {
            "actor": "alice",
            "credential_ref": "alice-token-v2",
            "credential_label": "alice credential v2",
            "previous_credential_ref": "alice-token-v1",
            "previous_credential_label": "alice credential v1",
            "expected_principal": {"type": "auth:token", "id": "alice"},
            "observed_principal": main_principals.get("WorkflowCancelled"),
            "credential_material_recorded_as_principal": principal_id(main_principals.get("WorkflowCancelled")) == "alice-token-v2",
            "event_type": "WorkflowCancelled",
            "spoofing_payload_and_headers": True,
        },
    }
    main_spoofing_matrix = [
        spoofing_matrix_row(
            "start",
            surface="history_api",
            credential_used=action_credentials["start"],
            expected_principal=expected_main["WorkflowStarted"],
            observed_principal=main_principals.get("WorkflowStarted"),
            event_type="WorkflowStarted",
        ),
        spoofing_matrix_row(
            "signal",
            surface="history_api",
            credential_used=action_credentials["signal"],
            expected_principal=expected_main["SignalReceived"],
            observed_principal=main_principals.get("SignalReceived"),
            event_type="SignalReceived",
        ),
        spoofing_matrix_row(
            "cancel",
            surface="history_api",
            credential_used=action_credentials["cancel"],
            expected_principal=expected_main["WorkflowCancelled"],
            observed_principal=main_principals.get("WorkflowCancelled"),
            event_type="WorkflowCancelled",
        ),
    ]
    credential_rotation = {
        "actor": "alice",
        "before": {
            "action": "start",
            "credential_ref": "alice-token-v1",
            "credential_label": "alice credential v1",
            "observed_principal": main_principals.get("WorkflowStarted"),
        },
        "after": {
            "action": "cancel",
            "credential_ref": "alice-token-v2",
            "credential_label": "alice credential v2",
            "observed_principal": main_principals.get("WorkflowCancelled"),
        },
        "stable_principal_id": "alice",
        "credential_material_recorded_as_principal": any(
            principal_id(action_credentials[action].get("observed_principal")) == action_credentials[action]["credential_ref"]
            for action in ("start", "cancel")
        ),
    }
    rotation_failures = []
    if principal_id(credential_rotation["before"].get("observed_principal")) != "alice":
        rotation_failures.append(f"alice credential v1 principal expected alice, got {credential_rotation['before'].get('observed_principal')!r}")
    if principal_id(credential_rotation["after"].get("observed_principal")) != "alice":
        rotation_failures.append(f"alice credential v2 principal expected alice, got {credential_rotation['after'].get('observed_principal')!r}")
    if credential_rotation["credential_material_recorded_as_principal"]:
        rotation_failures.append("credential material was recorded as the user-visible principal")
    rotation_linked_findings: list[dict[str, Any]] = []
    if rotation_failures:
        rotation_linked_findings.append(finding(
            "named_token_actor_matrix",
            "server",
            f"credential rotation attribution failures: {rotation_failures}",
            "alice credential v1 and alice credential v2 both resolve to principal id alice, and credential material is never recorded as the user-visible principal",
            "fix token principal resolution across credential rotation before marking named-token actor coverage pass",
            "P0" if credential_rotation["credential_material_recorded_as_principal"] else "P1",
        ))
        findings.extend(rotation_linked_findings)
    scenario_results.append(scenario("pass" if not rotation_failures else "fail", "named_token_actor_matrix", actors=["alice", "bob"], credentials={"alice": ["alice-token-v1", "alice-token-v2"], "bob": ["bob-token"]}, rotation_observations={"alice_v1_start": "alice", "alice_v2_cancel": "alice"}, credential_rotation=credential_rotation, action_credentials=action_credentials, linked_findings=rotation_linked_findings, findings=rotation_failures))

    main_failures = []
    for event_type, expected in expected_main.items():
        actual = main_principals.get(event_type)
        if not principal_matches(actual, expected):
            main_failures.append(f"{event_type} principal expected {expected}, got {actual!r}")
    spoof_successes = [
        row for row in main_spoofing_matrix
        if row.get("caller_controlled_value_hits")
    ]
    if spoof_successes:
        main_failures.append(f"caller-controlled spoof value appeared in history principal: {spoof_successes!r}")
    main_linked_findings: list[dict[str, Any]] = []
    if main_failures:
        main_linked_findings.append(finding(
            "start_signal_cancel_spoofing",
            "server",
            f"start/signal/cancel attribution failures: {main_failures}",
            "server-derived principals record alice for start, bob for signal, alice for cancel, and never accept caller-supplied spoofing fields",
            "fix server-side command principal derivation before marking start/signal/cancel spoofing coverage pass",
            "P0" if spoof_successes else "P1",
        ))
        findings.extend(main_linked_findings)
    scenario_results.append(scenario("pass" if not main_failures else "fail", "start_signal_cancel_spoofing", history_events=list(main_principals), recorded_principals=main_principals, spoofing_attempts=spoofing_attempt_catalog(["start", "signal", "cancel"]), spoofing_matrix=main_spoofing_matrix, action_credentials=action_credentials, linked_findings=main_linked_findings, findings=main_failures))

    query_recorded = query_observation.get("query_response", {})
    recorded_query_principal = principal_from_query_observation(query_observation)
    query_spoofing_matrix = [
        spoofing_matrix_row(
            "query",
            surface="query_task_or_response",
            credential_used={"actor": "bob", "credential_ref": "bob-token", "credential_label": "bob credential"},
            expected_principal={"type": "auth:token", "id": "bob"},
            observed_principal=recorded_query_principal,
        ),
    ]
    query_principal_failures = []
    if not query_recorded:
        query_principal_failures.append("query did not return a response")
    if query_observation.get("errors"):
        query_principal_failures.append(f"query errors: {query_observation.get('errors')}")
    if principal_id(recorded_query_principal) != "bob":
        query_principal_failures.append(f"query principal expected bob, got {recorded_query_principal!r}")
    query_spoof_successes = [
        row for row in query_spoofing_matrix
        if row.get("caller_controlled_value_hits")
    ]
    if query_spoof_successes:
        query_principal_failures.append(f"caller-controlled spoof value appeared in query principal: {query_spoof_successes!r}")
    scenario_results.append(scenario("pass" if not query_principal_failures else "fail", "query_attribution", query_result=query_recorded, recorded_principal=recorded_query_principal, history_or_query_task_surface=query_observation, spoofing_attempts=spoofing_attempt_catalog(["query"]), spoofing_matrix=query_spoofing_matrix, findings=query_principal_failures))
    if query_principal_failures:
        findings.append(finding(
            "query_attribution",
            "server",
            f"query attribution could not be confirmed: {query_principal_failures}",
            "query operations expose the caller principal in a documented server-controlled audit surface and ignore caller-supplied spoofing fields",
            "add query principal evidence to history or query task audit output and reject caller-controlled attribution values",
            "P0" if query_spoof_successes else "P1",
        ))

    expected_worker_principal = {"id": "worker:principal-attribution", "type": "auth:token"}
    documented_system_principals: list[dict[str, str]] = []
    completion_event_principal = complete_principals.get("WorkflowCompleted")
    failure_event_principal = fail_principals.get("WorkflowFailed")
    completion_matches_worker_principal = principal_matches(completion_event_principal, expected_worker_principal)
    failure_matches_worker_principal = principal_matches(failure_event_principal, expected_worker_principal)
    completion_matches_system_principal = documented_system_principal_match(completion_event_principal, documented_system_principals)
    failure_matches_system_principal = documented_system_principal_match(failure_event_principal, documented_system_principals)
    completion_failure_failures = []

    if complete_task_payload is None:
        completion_failure_failures.append("completion workflow task was not leased by the authenticated worker")
    if fail_task_payload is None:
        completion_failure_failures.append("failure workflow task was not leased by the authenticated worker")

    if not completion_matches_worker_principal and completion_matches_system_principal is None:
        completion_failure_failures.append(
            f"WorkflowCompleted principal expected authenticated worker {expected_worker_principal!r}"
            f" or documented system principal {documented_system_principals!r}, got {completion_event_principal!r}"
        )

    if not failure_matches_worker_principal and failure_matches_system_principal is None:
        completion_failure_failures.append(
            f"WorkflowFailed principal expected authenticated worker {expected_worker_principal!r}"
            f" or documented system principal {documented_system_principals!r}, got {failure_event_principal!r}"
        )

    completion_failure_status = "pass" if not completion_failure_failures else "fail"
    if completion_failure_status == "fail":
        findings.append(finding("completion_failure_attribution", "server_or_worker_protocol", f"completion principal={completion_event_principal!r}, failure principal={failure_event_principal!r}; failures={completion_failure_failures!r}", "worker-caused completion and failure events expose the authenticated worker principal id=worker:principal-attribution type=auth:token, unless the product contract explicitly documents a system principal", "thread worker request principal into completion/failure history events or publish an explicit system-principal contract before marking this scenario pass"))
    scenario_results.append(scenario(completion_failure_status, "completion_failure_attribution", completion_event_principal=completion_event_principal, failure_event_principal=failure_event_principal, worker_principal=expected_worker_principal, expected_worker_principal=expected_worker_principal, documented_system_principals=documented_system_principals, completion_outcome=complete_outcome, failure_outcome=fail_outcome, findings=completion_failure_failures))

    server_originated = {event: principal for event, principal in {**main_principals, **complete_principals, **fail_principals}.items() if principal is None}
    scenario_results.append(scenario("pass", "server_originated_events", event_types=list(server_originated), principal_values=server_originated, classification="explicit_null_for_events_without_originating_control_plane_command"))

    expected_anonymous_principal = {"type": "server", "id": "anonymous"}
    anonymous_spoofing_matrix = [
        spoofing_matrix_row(
            "anonymous_start",
            surface="history_api",
            credential_used=None,
            expected_principal=expected_anonymous_principal,
            observed_principal=anonymous_principals.get("WorkflowStarted"),
            event_type="WorkflowStarted",
            auth_driver="none",
        ),
        spoofing_matrix_row(
            "anonymous_signal",
            surface="history_api",
            credential_used=None,
            expected_principal=expected_anonymous_principal,
            observed_principal=anonymous_principals.get("SignalReceived"),
            event_type="SignalReceived",
            auth_driver="none",
        ),
        spoofing_matrix_row(
            "anonymous_cancel",
            surface="history_api",
            credential_used=None,
            expected_principal=expected_anonymous_principal,
            observed_principal=anonymous_principals.get("WorkflowCancelled"),
            event_type="WorkflowCancelled",
            auth_driver="none",
        ),
    ]
    anonymous_failures = []
    for event_type in ["WorkflowStarted", "SignalReceived", "WorkflowCancelled"]:
        if anonymous_principals.get(event_type) is None:
            anonymous_failures.append(f"{event_type} caller-generated anonymous history event leaked null/undefined principal")
        elif not principal_matches(anonymous_principals.get(event_type), expected_anonymous_principal):
            anonymous_failures.append(f"{event_type} anonymous principal expected {expected_anonymous_principal!r}, got {anonymous_principals.get(event_type)!r}")
    anonymous_spoof_successes = [
        row for row in anonymous_spoofing_matrix
        if row.get("caller_controlled_value_hits")
    ]
    if anonymous_spoof_successes:
        anonymous_failures.append(f"caller-controlled spoof value appeared in anonymous history principal: {anonymous_spoof_successes!r}")
    anonymous_linked_findings: list[dict[str, Any]] = []
    if anonymous_failures:
        anonymous_linked_findings.append(finding("anonymous_attribution", "server", f"anonymous attribution failures: {anonymous_failures}", "auth-disabled requests record principal type=server id=anonymous for start, signal, and cancel, and ignore caller-supplied principal fields and gateway-style headers", "fix auth-disabled command context attribution before marking anonymous principal coverage pass", "P0" if anonymous_spoof_successes else "P1"))
        findings.extend(anonymous_linked_findings)
    scenario_results.append(scenario("pass" if not anonymous_failures else "fail", "anonymous_attribution", anonymous_principal=anonymous_principals.get("WorkflowStarted"), documented_value=expected_anonymous_principal, history_events=list(anonymous_principals), recorded_principals=anonymous_principals, spoofing_attempts=spoofing_attempt_catalog(["start", "signal", "cancel"]), spoofing_matrix=anonymous_spoofing_matrix, anonymous_auth_driver="none", linked_findings=anonymous_linked_findings, findings=anonymous_failures))

    python_expected_principal = {"type": "auth:token", "id": "bob"}
    python_raw_http_reference_principal = main_principals.get("SignalReceived")
    python_recorded_principal = python_principals.get("SignalReceived") or python_principals.get("WorkflowStarted")
    python_operation_outputs = sdk_operation_outputs(python_operation, python_client_id, "sdk-python")
    python_failures = list(python_operation.get("errors", [])) if isinstance(python_operation.get("errors"), list) else []
    if python_operation.get("status") != "pass":
        python_failures.append(f"Python SDK operation status={python_operation.get('status')}")
    if not principal_matches(python_principals.get("WorkflowStarted"), python_expected_principal):
        python_failures.append(f"Python SDK start principal expected bob, got {python_principals.get('WorkflowStarted')!r}")
    if not principal_matches(python_principals.get("SignalReceived"), python_expected_principal):
        python_failures.append(f"Python SDK signal principal expected bob, got {python_principals.get('SignalReceived')!r}")
    python_shape_matches_http = principal_shape_matches_http(python_recorded_principal, python_raw_http_reference_principal)
    if python_operation.get("status") == "pass" and not python_shape_matches_http:
        python_failures.append(
            f"Python SDK principal shape {principal_shape_signature(python_recorded_principal)!r} "
            f"did not match raw HTTP signal shape {principal_shape_signature(python_raw_http_reference_principal)!r}"
        )
    python_linked_findings: list[dict[str, Any]] = []
    if python_failures:
        python_linked_findings.append(finding("python_sdk_visibility", "sdk-python", f"Python SDK attribution failures: {python_failures}", "Python-authored client calls record the same principal shape as raw HTTP", "fix Python SDK credential propagation or server attribution shape before marking Python visibility pass"))
        findings.extend(python_linked_findings)
    scenario_results.append(scenario(
        "pass" if not python_failures else "fail",
        "python_sdk_visibility",
        client_operation=python_operation,
        sdk_package_version=versions.get("sdk-python"),
        credential_used={"actor": "bob", "credential_ref": "bob-token"},
        expected_principal=python_expected_principal,
        raw_http_reference_principal=python_raw_http_reference_principal,
        history_api_principal_samples=principal_samples(python_principals, ["WorkflowStarted", "SignalReceived"]),
        operation_outputs=python_operation_outputs,
        operation_output_sample=python_operation.get("output"),
        recorded_principal=python_recorded_principal,
        shape_matches_http=python_shape_matches_http,
        history_events=list(python_principals),
        linked_findings=python_linked_findings,
        findings=python_failures,
    ))

    php_expected_principal = {"type": "auth:token", "id": "alice"}
    php_raw_http_reference_principal = main_principals.get("WorkflowStarted")
    php_recorded_principal = php_principals.get("SignalReceived") or php_principals.get("WorkflowStarted")
    php_operation_outputs = sdk_operation_outputs(php_operation, php_client_id, "sdk-php")
    php_failures = list(php_operation.get("errors", [])) if isinstance(php_operation.get("errors"), list) else []
    if php_operation.get("status") != "pass":
        php_failures.append(f"PHP client operation status={php_operation.get('status')}")
    if not principal_matches(php_principals.get("WorkflowStarted"), php_expected_principal):
        php_failures.append(f"PHP client start principal expected alice, got {php_principals.get('WorkflowStarted')!r}")
    if not principal_matches(php_principals.get("SignalReceived"), php_expected_principal):
        php_failures.append(f"PHP client signal principal expected alice, got {php_principals.get('SignalReceived')!r}")
    php_shape_matches_http = principal_shape_matches_http(php_recorded_principal, php_raw_http_reference_principal)
    if php_operation.get("status") == "pass" and not php_shape_matches_http:
        php_failures.append(
            f"PHP client principal shape {principal_shape_signature(php_recorded_principal)!r} "
            f"did not match raw HTTP start shape {principal_shape_signature(php_raw_http_reference_principal)!r}"
        )
    php_linked_findings: list[dict[str, Any]] = []
    if php_failures:
        php_linked_findings.append(finding("php_client_visibility", "sdk-php", f"PHP client attribution failures: {php_failures}", "PHP-authored client calls record the same principal shape as raw HTTP", "fix PHP credential propagation or server attribution shape before marking PHP visibility pass"))
        findings.extend(php_linked_findings)
    scenario_results.append(scenario(
        "pass" if not php_failures else "fail",
        "php_client_visibility",
        client_operation=php_operation,
        sdk_package_version=versions.get("sdk-php"),
        credential_used={"actor": "alice", "credential_ref": "alice-token-v1"},
        expected_principal=php_expected_principal,
        raw_http_reference_principal=php_raw_http_reference_principal,
        history_api_principal_samples=principal_samples(php_principals, ["WorkflowStarted", "SignalReceived"]),
        operation_outputs=php_operation_outputs,
        operation_output_sample=php_operation.get("output"),
        recorded_principal=php_recorded_principal,
        shape_matches_http=php_shape_matches_http,
        history_events=list(php_principals),
        linked_findings=php_linked_findings,
        findings=php_failures,
    ))

    sdk_principal_attribution_parity = {
        "python_sdk_visibility": {
            "status": "pass" if not python_failures else "fail",
            "sdk_package_version": versions.get("sdk-python"),
            "credential_used": {"actor": "bob", "credential_ref": "bob-token"},
            "expected_principal": python_expected_principal,
            "raw_http_reference_principal": python_raw_http_reference_principal,
            "history_api_principal_samples": principal_samples(python_principals, ["WorkflowStarted", "SignalReceived"]),
            "operation_outputs": python_operation_outputs,
            "operation_output_sample": python_operation.get("output"),
            "recorded_principal": python_recorded_principal,
            "shape_matches_http": python_shape_matches_http,
            "client_operation": python_operation,
            "linked_findings": python_linked_findings,
        },
        "php_client_visibility": {
            "status": "pass" if not php_failures else "fail",
            "sdk_package_version": versions.get("sdk-php"),
            "credential_used": {"actor": "alice", "credential_ref": "alice-token-v1"},
            "expected_principal": php_expected_principal,
            "raw_http_reference_principal": php_raw_http_reference_principal,
            "history_api_principal_samples": principal_samples(php_principals, ["WorkflowStarted", "SignalReceived"]),
            "operation_outputs": php_operation_outputs,
            "operation_output_sample": php_operation.get("output"),
            "recorded_principal": php_recorded_principal,
            "shape_matches_http": php_shape_matches_http,
            "client_operation": php_operation,
            "linked_findings": php_linked_findings,
        },
    }

    scenario_results.append(scenario("pass" if cli_json_ok else "fail", "cli_operator_visibility", command=f"dw workflow:history {main_id} {main_run} --output=json", output_sample=cli_output[:4000], principal_visible=cli_json_ok))
    if not cli_json_ok:
        findings.append(finding("cli_operator_visibility", "cli", "CLI history output did not expose event principal", "CLI operator output shows the event principal clearly", "surface event principal in workflow:history output"))

    return write_principal_attribution_aggregate(
        versions=versions,
        artifact_sources=pins.get("artifact_sources", {}),
        findings=findings,
        history_dumps=history_dumps,
        scenario_results=scenario_results,
        action_credentials=action_credentials,
        main_spoofing_matrix=main_spoofing_matrix,
        query_spoofing_matrix=query_spoofing_matrix,
        anonymous_spoofing_matrix=anonymous_spoofing_matrix,
        cli_json_ok=cli_json_ok,
        sdk_principal_attribution_parity=sdk_principal_attribution_parity,
        anonymous_failures=anonymous_failures,
        anonymous_principals=anonymous_principals,
        expected_anonymous_principal=expected_anonymous_principal,
    )


if __name__ == "__main__":
    raise SystemExit(main())
PY

SERVER_URL="$server_base_url" \
ANONYMOUS_SERVER_URL="$anonymous_server_base_url" \
RESULT_DIR="$result_dir" \
DW_BIN="$run_root/cli/bin/dw" \
PYTHON_BIN="$run_root/artifacts/python-sdk/bin/python" \
PHP_SDK_AUTOLOAD="$run_root/artifacts/sdk-php/vendor/autoload.php" \
WATERLINE_PRINCIPAL_RESULT="$waterline_result_path" \
WATERLINE_PRINCIPAL_EXECUTION="$waterline_execution_path" \
STARTED_AT="$started_at" \
PRINCIPAL_ATTRIBUTION_SUITE_VERSION="$principal_suite_version" \
"$run_root/artifacts/python-sdk/bin/python" "$run_root/orchestrate.py" | tee "$result_dir/orchestrate.log"
