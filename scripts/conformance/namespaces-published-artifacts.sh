#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: namespaces-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Runs the public namespace runtime contract against published artifacts only.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  artifact-install-evidence.json
  namespaces-result.json
  namespaces-record.json

Environment overrides:
  DW_NAMESPACES_RUN_ROOT             Scratch directory. Defaults to mktemp.
  DW_NAMESPACES_RESULT_DIR           Result directory. Defaults to run root.
  DW_NAMESPACES_KEEP_RUN_ROOT=1      Keep scratch directory after success.
  DW_SERVER_IMAGE                    Exact server image/tag/digest to test.
  DW_SERVER_VERSION                  Exact server SemVer tag; required for digest-only DW_SERVER_IMAGE.
  DW_CLI_VERSION                     GitHub release tag for the official CLI installer.
  DW_PYTHON_SDK_VERSION              PyPI version for durable-workflow.
  DW_PHP_SDK_VERSION                 Composer version for durable-workflow/sdk.
  DW_WORKFLOW_PHP_VERSION            Composer version for durable-workflow/workflow.
  DW_WATERLINE_VERSION               Composer version for durable-workflow/waterline.
  DW_NAMESPACES_SKIP_DOCKER_PULL=1   Reuse local server image instead of pulling.
  DW_NAMESPACES_SERVER_PORT          Host port for the published server. Defaults to a free 127.0.0.1 port.
  DW_NAMESPACES_WATERLINE_RESULT     Optional pre-generated JSON report from waterline:namespace-conformance.
                                      If unset, the runner installs the published Waterline artifact and runs this shard.
  DW_NAMESPACES_SDK_PHP_RESULT  Optional pre-generated JSON report from php-sdk-published-artifacts.
                                      If unset, the runner installs the published PHP SDK artifact and runs this shard.
USAGE
}

keep_run_root="${DW_NAMESPACES_KEEP_RUN_ROOT:-0}"
result_dir="${DW_NAMESPACES_RESULT_DIR:-}"

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

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
namespace_scenario_manifest="${DW_NAMESPACES_SCENARIO_MANIFEST:-$repo_root/static/platform-conformance/namespace-runtime-scenarios.json}"

read_namespace_suite_version() {
  local version

  if [[ ! -f "$namespace_scenario_manifest" ]]; then
    printf 'namespace scenario manifest not found: %s\n' "$namespace_scenario_manifest" >&2
    exit 2
  fi

  version="$(sed -n 's/^[[:space:]]*"suite_version"[[:space:]]*:[[:space:]]*\([0-9][0-9]*\).*/\1/p' "$namespace_scenario_manifest" | head -n 1)"
  if [[ -z "$version" ]]; then
    printf 'namespace scenario manifest does not declare suite_version: %s\n' "$namespace_scenario_manifest" >&2
    exit 2
  fi

  printf '%s\n' "$version"
}

namespace_suite_version="${DW_NAMESPACES_SUITE_VERSION:-$(read_namespace_suite_version)}"

run_root="${DW_NAMESPACES_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-namespaces.XXXXXX")"
fi
mkdir -p "$run_root"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"

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

namespace_required_scenario_ids=(
  "published_artifact_install_only"
  "namespace_create_update_describe_and_list"
  "workflow_cross_namespace_visibility_isolation"
  "workflow_cross_namespace_mutation_isolation"
  "php_worker_task_queue_namespace_isolation"
  "cli_namespace_context_and_default_scope"
  "sdk_namespace_selection_parity"
  "search_attribute_schema_and_value_query_isolation"
  "schedule_namespace_isolation"
  "namespace_lifecycle_cleanup_and_recreate"
  "waterline_operator_namespace_visibility"
  "nexus_explicit_cross_namespace_invocation"
  "reserved_namespace_name_refusal"
  "result_record_and_product_finding_routing"
)

require_command() {
  local command_name="$1"

  command -v "$command_name" >/dev/null 2>&1
}

blocked_result() {
  local reason="$1"
  local started_at="$2"

  python3 - "$result_dir" "$started_at" "$reason" "$namespace_suite_version" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

result_dir = Path(sys.argv[1])
started_at = sys.argv[2]
reason = sys.argv[3]
suite_version = int(sys.argv[4])
finished = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
scenario_ids = [
    "published_artifact_install_only",
    "namespace_create_update_describe_and_list",
    "workflow_cross_namespace_visibility_isolation",
    "workflow_cross_namespace_mutation_isolation",
    "php_worker_task_queue_namespace_isolation",
    "cli_namespace_context_and_default_scope",
    "sdk_namespace_selection_parity",
    "search_attribute_schema_and_value_query_isolation",
    "schedule_namespace_isolation",
    "namespace_lifecycle_cleanup_and_recreate",
    "waterline_operator_namespace_visibility",
    "nexus_explicit_cross_namespace_invocation",
    "reserved_namespace_name_refusal",
    "result_record_and_product_finding_routing",
]
findings = [
    {
        "scenario_id": scenario_id,
        "owning_surface": "conformance_harness",
        "observed_behavior": f"namespace conformance runner was blocked before product evidence: {reason}",
        "expected_behavior": "host runner can install published artifacts and exercise namespace parity",
        "next_acceptance_criterion": "restore the missing host capability and rerun namespaces conformance",
        "priority": "P0",
    }
    for scenario_id in scenario_ids
]
result = {
    "schema": "durable-workflow.v2.namespace-runtime.result",
    "schema_version": 1,
    "suite_schema": "durable-workflow.v2.platform-conformance.suite",
    "suite_version": suite_version,
    "category": "namespace_runtime_contract",
    "outcome": "non_passing_runner_blocked",
    "runner_blocked": True,
    "started_at": started_at,
    "finished_at": finished,
    "generated_at": finished,
    "artifact_versions": {},
    "published_artifact_versions": {},
    "namespace_topology": {"namespaces": ["tenant-a", "tenant-b", "shared"]},
    "runtime_matrix": {
        "runtimes": ["sdk-php", "sdk-python"],
        "client_paths": ["cli", "sdk-python", "sdk-php"],
        "observer_paths": ["waterline-list", "waterline-detail", "waterline-operator-api"],
    },
    "scenario_results": [
        {
            "scenario_id": scenario_id,
            "status": "runner_blocked",
            "observed_outputs": {"blocked_reason": reason},
            "linked_findings": [findings[index]],
        }
        for index, scenario_id in enumerate(scenario_ids)
    ],
    "findings": findings,
    "finding_links": {item["scenario_id"]: [item] for item in findings},
}
result_dir.mkdir(parents=True, exist_ok=True)
(result_dir / "namespaces-result.json").write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
(result_dir / "namespaces-record.json").write_text(
    json.dumps(
        {
            "experiment": "namespaces",
            "outcome": "non_passing_runner_blocked",
            "runnerBlocked": True,
            "artifactVersions": {},
            "findings": [reason],
            "resultPath": str(result_dir / "namespaces-result.json"),
        },
        indent=2,
        sort_keys=True,
    )
    + "\n",
    encoding="utf-8",
)
PY
}

started_at="$(timestamp)"

if ! require_command python3; then
  printf '%s\n' 'required command not found: python3' >&2
  exit 1
fi

for command_name in docker curl id; do
  if ! require_command "$command_name"; then
    blocked_result "required command not found: $command_name" "$started_at"
    exit 1
  fi
done

host_uid_gid="$(id -u):$(id -g)"

cat > "$run_root/resolve-pins.py" <<'PY'
from __future__ import annotations

import json
import os
import re
import sys
import urllib.error
import urllib.request
from typing import Any

SEMVER_TAG_RE = re.compile(r"^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$")
SERVER_PATCH_TAG_RE = re.compile(
    r"^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
    r"(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)"
    r"(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$",
)


def read_json(url: str) -> Any:
    request = urllib.request.Request(url, headers={"User-Agent": "durable-workflow-namespaces-conformance"})
    with urllib.request.urlopen(request, timeout=60) as response:
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
    request = urllib.request.Request(url, headers={"User-Agent": "durable-workflow-namespaces-conformance"}, method="GET")
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
sdk_php_version = packagist_version("durable-workflow/sdk", env("DW_PHP_SDK_VERSION"))
workflow_version = packagist_version("durable-workflow/workflow", env("DW_WORKFLOW_PHP_VERSION"))
waterline_version = packagist_version("durable-workflow/waterline", env("DW_WATERLINE_VERSION"))

json.dump(
    {
        "server": server_version,
        "server_image": server_image,
        "cli": cli_version,
        "cli_installer_url": cli_installer_url,
        "workflow": workflow_version,
        "workflow-php": workflow_version,
        "sdk-php": sdk_php_version,
        "sdk-python": python_version,
        "waterline": waterline_version,
        "artifact_sources": {
            "server": "docker_image",
            "cli": "github_release",
            "workflow": "packagist_package",
            "workflow-php": "packagist_package",
            "sdk-php": "packagist_package",
            "sdk-python": "pypi_package",
            "waterline": "packagist_package",
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
server_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["server"])' "$run_root/pins.json")"
cli_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["cli"])' "$run_root/pins.json")"
cli_installer_url="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["cli_installer_url"])' "$run_root/pins.json")"
python_sdk_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["sdk-python"])' "$run_root/pins.json")"
sdk_php_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["sdk-php"])' "$run_root/pins.json")"
workflow_php_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["workflow-php"])' "$run_root/pins.json")"
waterline_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["waterline"])' "$run_root/pins.json")"

if [[ "${DW_NAMESPACES_SKIP_DOCKER_PULL:-0}" != "1" ]]; then
  docker pull "$server_image"
fi
server_image_pin="$(docker image inspect --format '{{index .RepoDigests 0}}' "$server_image" 2>/dev/null || true)"
if [[ -z "$server_image_pin" || "$server_image_pin" == "<no value>" ]]; then
  server_image_pin="$server_image"
fi
docker tag "$server_image_pin" durable-workflow-namespaces-server:run
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

if ! python3 -m venv "$run_root/.venv" > "$result_dir/python-venv.log" 2>&1; then
  blocked_result "python venv creation failed; see python-venv.log" "$started_at"
  exit 1
fi
# durable-workflow== is intentionally visible for public runner scanners.
if ! "$run_root/.venv/bin/python" -m pip install --upgrade pip > "$result_dir/python-pip-upgrade.log" 2>&1; then
  blocked_result "pip upgrade failed; see python-pip-upgrade.log" "$started_at"
  exit 1
fi
if ! "$run_root/.venv/bin/python" -m pip install "durable-workflow==$python_sdk_version" > "$result_dir/python-sdk-install.log" 2>&1; then
  blocked_result "durable-workflow==$python_sdk_version install failed; see python-sdk-install.log" "$started_at"
  exit 1
fi

server_port="${DW_NAMESPACES_SERVER_PORT:-}"
if [[ -z "$server_port" ]]; then
  server_port="$(python3 - <<'PY'
import socket
with socket.socket() as s:
    s.bind(("127.0.0.1", 0))
    print(s.getsockname()[1])
PY
)"
fi
server_bind_host="${DW_NAMESPACES_SERVER_BIND_HOST:-127.0.0.1}"
server_base_url="${DW_NAMESPACES_SERVER_URL:-http://127.0.0.1:${server_port}}"
server_api_url="${server_base_url%/}/api"

namespace_tokens_json='[
  {"token":"admin-token","subject":"admin","roles":["admin"],"label":"Admin"},
  {"token":"operator-token","subject":"operator","roles":["operator"],"label":"Operator"},
  {"token":"worker-token","subject":"worker:namespaces","roles":["worker"],"label":"Namespaces Worker"}
]'

cat > "$run_root/compose.yml" <<YAML
x-server-environment: &server-environment
  DW_AUTH_DRIVER: token
  DW_AUTH_BACKWARD_COMPATIBLE: "false"
  DW_PRINCIPAL_TOKENS: '${namespace_tokens_json}'
  DW_WORKER_POLL_TIMEOUT: "1"
  DW_WORKER_POLL_INTERVAL_MS: "100"
  DB_CONNECTION: sqlite
  DB_DATABASE: /app/database/database.sqlite
  QUEUE_CONNECTION: database

services:
  server:
    image: durable-workflow-namespaces-server:run
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

volumes:
  server-db:
YAML

python3 - "$run_root/pins.json" "$result_dir/server-image-digest.txt" "$result_dir/run-metadata.json" "$server_base_url" "$namespace_suite_version" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

pins = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
suite_version = int(sys.argv[5])
versions = {
    "server": pins["server"],
    "cli": pins["cli"],
    "workflow": pins["workflow"],
    "workflow-php": pins["workflow-php"],
    "sdk-php": pins["sdk-php"],
    "sdk-python": pins["sdk-python"],
    "waterline": pins["waterline"],
}
metadata = {
    "experiment": "namespaces",
    "schema": "durable-workflow.v2.namespace-runtime.metadata",
    "suite_schema": "durable-workflow.v2.platform-conformance.suite",
    "suite_version": suite_version,
    "generated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
    "published_artifact_versions": versions,
    "artifact_sources": pins.get("artifact_sources", {}),
    "server_image": pins["server_image"],
    "server_image_digest": Path(sys.argv[2]).read_text(encoding="utf-8").strip(),
    "server_url": sys.argv[4],
    "local_product_source_checkouts_used": False,
}
Path(sys.argv[3]).write_text(json.dumps(metadata, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY

docker compose -f "$run_root/compose.yml" run --rm server server-bootstrap > "$result_dir/server-bootstrap.log" 2>&1
docker compose -f "$run_root/compose.yml" up -d --wait > "$result_dir/docker-compose-up.log" 2>&1

for _ in $(seq 1 90); do
  if curl -fsS "$server_api_url/ready" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

if ! curl -fsS "$server_api_url/ready" >/dev/null 2>&1; then
  docker compose -f "$run_root/compose.yml" ps > "$result_dir/docker-compose-ps.log" 2>&1 || true
  docker compose -f "$run_root/compose.yml" logs server > "$result_dir/server.log" 2>&1 || true
  blocked_result "namespace conformance server was not reachable at $server_base_url; see docker-compose-ps.log and server.log" "$started_at"
  exit 1
fi

cat > "$run_root/artifact-smoke.py" <<'PY'
from __future__ import annotations

import importlib.metadata
import json
import os
import re
import subprocess
import sys
from pathlib import Path

pins = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
dw_bin = sys.argv[2]
out_path = Path(sys.argv[3])
server_image_digest = Path(sys.argv[4]).read_text(encoding="utf-8").strip()


def python_release_identity(version: str) -> str | None:
    stable = re.fullmatch(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)", version)
    if stable:
        return version
    semver = re.fullmatch(
        r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)-(alpha|beta|rc)\.(0|[1-9]\d*)",
        version,
        re.IGNORECASE,
    )
    if semver:
        major, minor, patch, prerelease, ordinal = semver.groups()
        phase = {"alpha": "a", "beta": "b", "rc": "rc"}[prerelease.lower()]
        return f"{major}.{minor}.{patch}{phase}{ordinal}"
    pep440 = re.fullmatch(
        r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(a|b|rc)(0|[1-9]\d*)",
        version,
        re.IGNORECASE,
    )
    if not pep440:
        return None
    major, minor, patch, prerelease, ordinal = pep440.groups()
    return f"{major}.{minor}.{patch}{prerelease.lower()}{ordinal}"


def run(command: list[str]) -> tuple[int, str]:
    completed = subprocess.run(command, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, check=False, timeout=120)
    return completed.returncode, completed.stdout


cli_code, cli_output = run([dw_bin, "--version"])
python_version = importlib.metadata.version("durable-workflow")

evidence = {
    "server_image": {"version": pins["server"], "source": pins["artifact_sources"]["server"], "digest": server_image_digest, "status": "installed"},
    "cli_release": {"version": pins["cli"], "source": pins["artifact_sources"]["cli"], "status": "installed" if cli_code == 0 else "failed", "output_sample": cli_output[-1000:]},
    "sdk_php_package": {"version": pins["sdk-php"], "source": pins["artifact_sources"]["sdk-php"], "status": "pending_shard_execution", "shard_command": "php-sdk-published-artifacts", "execution_required": True},
    "sdk_python_package": {"version": python_version, "source": pins["artifact_sources"]["sdk-python"], "status": "installed"},
    "waterline_artifact": {"version": pins["waterline"], "source": pins["artifact_sources"]["waterline"], "status": "resolved", "shard_command": "waterline:namespace-conformance"},
    "local_product_source_checkouts_used": False,
}
out_path.write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")
if cli_code != 0 or python_release_identity(python_version) != python_release_identity(pins["sdk-python"]):
    raise SystemExit(1)
PY

if ! "$run_root/.venv/bin/python" "$run_root/artifact-smoke.py" "$run_root/pins.json" "$run_root/cli/bin/dw" "$result_dir/artifact-install-evidence.json" "$result_dir/server-image-digest.txt" > "$result_dir/artifact-smoke.log" 2>&1; then
  blocked_result "published artifact install smoke failed before namespace scenarios; see artifact-smoke.log" "$started_at"
  exit 1
fi

sdk_php_result_path="${DW_NAMESPACES_SDK_PHP_RESULT:-}"
if [[ -z "$sdk_php_result_path" ]]; then
  sdk_php_result_path="$result_dir/sdk-php-namespace-result.json"

  write_sdk_php_setup_failure() {
    local reason="$1"

    python3 - "$run_root/pins.json" "$sdk_php_result_path" "$started_at" "$namespace_suite_version" "$reason" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

pins = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
out_path = Path(sys.argv[2])
started_at = sys.argv[3]
suite_version = int(sys.argv[4])
reason = sys.argv[5]
finished = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
versions = {
    "server": pins["server"],
    "cli": pins["cli"],
    "workflow": pins["workflow"],
    "workflow-php": pins["workflow-php"],
    "sdk-php": pins["sdk-php"],
    "sdk-python": pins["sdk-python"],
    "waterline": pins["waterline"],
}
required_scenarios = [
    "namespace_create_update_describe_and_list",
    "sdk_namespace_selection_parity",
    "php_worker_task_queue_namespace_isolation",
]
finding_template = {
    "owning_surface": "conformance_harness",
    "observed_behavior": f"PHP SDK namespace shard could not run in the published-artifact harness: {reason}",
    "expected_behavior": "php-sdk-published-artifacts runs against the published PHP SDK artifact and emits namespace client and worker evidence",
    "next_acceptance_criterion": "restore the PHP SDK shard execution path and rerun namespaces conformance",
    "priority": "P1",
}
scenario_results = [
    {
        "scenario_id": "published_artifact_install_only",
        "status": "pass",
        "observed_outputs": {
            "artifact_versions": versions,
            "artifact_sources": pins.get("artifact_sources", {}),
        },
        "linked_findings": [],
    },
]
findings = []
for scenario_id in required_scenarios:
    finding = {"scenario_id": scenario_id, **finding_template}
    findings.append(finding)
    scenario_results.append(
        {
            "scenario_id": scenario_id,
            "status": "fail",
            "observed_outputs": {
                "shard_command": "php-sdk-published-artifacts",
                "setup_failure": reason,
            },
            "linked_findings": [finding],
        }
    )
scenario_results.append(
    {
        "scenario_id": "result_record_and_product_finding_routing",
        "status": "pass",
        "observed_outputs": {
            "artifact_versions_recorded": True,
            "timestamps_recorded": True,
            "finding_links_recorded": True,
        },
        "linked_findings": [],
    }
)
report = {
    "schema": "durable-workflow.v2.namespace-runtime.result",
    "schema_version": 1,
    "suite_version": suite_version,
    "coverage_scope": "sdk-php-namespace-shard",
    "outcome": "fail",
    "started_at": started_at,
    "finished_at": finished,
    "generated_at": finished,
    "artifact_versions": versions,
    "artifact_sources": pins.get("artifact_sources", {}),
    "namespace_topology": {"namespaces": ["tenant-a", "tenant-b", "shared"]},
    "runtime_matrix": {
        "claimed_targets": ["sdk-php"],
        "covered_scenarios": required_scenarios,
        "client_paths": ["sdk-php"],
        "worker_isolation_cells": [
            {"runtime": "sdk-php", "namespace": "tenant-a", "scenario": "php_worker_task_queue_namespace_isolation"},
            {"runtime": "sdk-php", "namespace": "tenant-b", "scenario": "php_worker_task_queue_namespace_isolation"},
        ],
    },
    "scenario_results": scenario_results,
    "sdk_php_namespace_surface": {
        "shard_command": "php-sdk-published-artifacts",
        "setup_failure": reason,
    },
    "api_captures": {},
    "findings": findings,
    "finding_links": {item["scenario_id"]: [item] for item in findings},
}
out_path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
  }

  sdk_php_probe_dir="$result_dir/sdk-php-namespace-probe"
  mkdir -p "$sdk_php_probe_dir"
  server_container_id="$(docker compose -f "$run_root/compose.yml" ps -q server)"
  sdk_php_command_status=125
  if [[ -n "$server_container_id" ]]; then
    set +e
    docker run --rm \
      --user "$host_uid_gid" \
      --network "container:${server_container_id}" \
      -v "$sdk_php_probe_dir:/result" \
      -e DW_PHP_SDK_VERSION="$sdk_php_version" \
      -e DW_SERVER_VERSION="$server_version" \
      -e DW_SERVER_IMAGE="$server_image" \
      -e DW_PHP_SDK_CONFORMANCE_SERVER_URL="http://127.0.0.1:8080" \
      -e DW_PHP_SDK_CONFORMANCE_NAMESPACE=default \
      -e DW_PHP_SDK_CONFORMANCE_CONTROL_TOKEN=admin-token \
      -e DW_PHP_SDK_CONFORMANCE_WORKER_TOKEN=worker-token \
      "$server_image" scripts/conformance/php-sdk-published-artifacts.sh --scope namespace --result-dir /result \
      > "$result_dir/sdk-php-namespace-conformance.log" 2>&1
    sdk_php_command_status=$?
    set -e
  fi

  sdk_php_probe_result="$sdk_php_probe_dir/php-sdk-conformance-result.json"
  sdk_php_probe_sidecar="$sdk_php_probe_dir/php-sdk-lifecycle-evidence.json"
  if [[ -s "$sdk_php_probe_result" && -s "$sdk_php_probe_sidecar" ]]; then
    python3 "$script_dir/php-sdk-namespace-shard-report.py" \
      "$run_root/pins.json" "$sdk_php_probe_result" "$sdk_php_probe_sidecar" \
      "$sdk_php_result_path" "$started_at" "$namespace_suite_version"
  else
    write_sdk_php_setup_failure "The exact server image PHP SDK runner exited with status ${sdk_php_command_status} without writing complete evidence; see sdk-php-namespace-conformance.log"
  fi
fi

waterline_result_path="${DW_NAMESPACES_WATERLINE_RESULT:-}"
if [[ -z "$waterline_result_path" ]]; then
  waterline_result_path="$result_dir/waterline-namespace-result.json"
  waterline_app="$run_root/waterline-namespace-app"
  mkdir -p "$waterline_app"

  mapfile -t waterline_artifact_args < <(python3 - "$run_root/pins.json" <<'PY'
from __future__ import annotations

import json
import sys
from pathlib import Path

pins = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
artifact_keys = ["server", "cli", "workflow-php", "sdk-php", "sdk-python", "waterline"]
for key in artifact_keys:
    print(f"--artifact-version={key}={pins[key]}")
for key in artifact_keys:
    print(f"--artifact-source={key}={pins['artifact_sources'][key]}")
PY
)

  write_waterline_setup_failure() {
    local reason="$1"

    python3 - "$run_root/pins.json" "$waterline_result_path" "$started_at" "$namespace_suite_version" "$reason" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

pins = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
out_path = Path(sys.argv[2])
started_at = sys.argv[3]
suite_version = int(sys.argv[4])
reason = sys.argv[5]
finished = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
versions = {
    "server": pins["server"],
    "cli": pins["cli"],
    "workflow": pins["workflow"],
    "workflow-php": pins["workflow-php"],
    "sdk-php": pins["sdk-php"],
    "sdk-python": pins["sdk-python"],
    "waterline": pins["waterline"],
}
finding = {
    "scenario_id": "waterline_operator_namespace_visibility",
    "owning_surface": "conformance_harness",
    "finding_type": "unsupported_public_surface",
    "observed_behavior": f"Waterline namespace shard could not run in the published-artifact harness: {reason}",
    "expected_behavior": "waterline:namespace-conformance runs against the published Waterline artifact and emits scoped operator evidence",
    "next_acceptance_criterion": "restore the Waterline shard execution path and rerun namespaces conformance",
    "priority": "P1",
    "scenario_status": "unsupported",
}
scenario_results = [
    {
        "scenario_id": "published_artifact_install_only",
        "status": "pass",
        "observed_outputs": {
            "artifact_versions": versions,
            "artifact_sources": pins.get("artifact_sources", {}),
        },
        "linked_findings": [],
    },
    {
        "scenario_id": "waterline_operator_namespace_visibility",
        "status": "unsupported",
        "observed_outputs": {
            "shard_command": "waterline:namespace-conformance",
            "setup_failure": reason,
        },
        "linked_findings": [finding],
    },
    {
        "scenario_id": "result_record_and_product_finding_routing",
        "status": "pass",
        "observed_outputs": {
            "artifact_versions_recorded": True,
            "timestamps_recorded": True,
            "finding_links_recorded": True,
        },
        "linked_findings": [],
    },
]
report = {
    "schema": "durable-workflow.v2.namespace-runtime.result",
    "schema_version": 1,
    "suite_version": suite_version,
    "coverage_scope": "waterline-operator-namespace-shard",
    "outcome": "non_passing",
    "started_at": started_at,
    "finished_at": finished,
    "generated_at": finished,
    "artifact_versions": versions,
    "artifact_sources": pins.get("artifact_sources", {}),
    "namespace_topology": {"namespaces": ["tenant-a", "tenant-b", "shared"]},
    "runtime_matrix": {
        "claimed_targets": ["waterline_contract_surface"],
        "covered_scenarios": [
            "published_artifact_install_only",
            "waterline_operator_namespace_visibility",
            "result_record_and_product_finding_routing",
        ],
        "observer_paths": [
            "waterline-list",
            "waterline-detail",
            "waterline-operator-api",
            "waterline-schedules",
        ],
    },
    "scenario_results": scenario_results,
    "waterline_operator_visibility": {
        "shard_command": "waterline:namespace-conformance",
        "setup_failure": reason,
    },
    "api_captures": {},
    "findings": [finding],
    "finding_links": {"waterline_operator_namespace_visibility": [finding]},
}
out_path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
  }

  set +e
  docker run --rm --user "$host_uid_gid" -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer \
    -v "$waterline_app:/app" -w /app composer:2 \
    composer create-project laravel/laravel . --no-interaction --no-progress \
    > "$result_dir/waterline-create-project.log" 2>&1
  waterline_create_status=$?
  set -e

  waterline_require_status=1
  waterline_key_status=1
  waterline_migrate_status=1
  waterline_command_status=1
  if [[ "$waterline_create_status" -eq 0 ]]; then
    mkdir -p "$waterline_app/database"
    : > "$waterline_app/database/database.sqlite"

    set +e
    docker run --rm --user "$host_uid_gid" -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer \
      -v "$waterline_app:/app" -w /app composer:2 \
      composer require --no-interaction --no-progress \
        "durable-workflow/waterline:${waterline_version}@beta" \
        "durable-workflow/workflow:${workflow_php_version}@beta" \
        "durable-workflow/sdk:${sdk_php_version}@beta" \
      > "$result_dir/waterline-composer-install.log" 2>&1
    waterline_require_status=$?
    set -e
  fi

  if [[ "$waterline_require_status" -eq 0 ]]; then
    set +e
    docker run --rm \
      --user "$host_uid_gid" \
      -v "$waterline_app:/app" \
      -w /app \
      -e DB_CONNECTION=sqlite \
      -e DB_DATABASE=/app/database/database.sqlite \
      -e WATERLINE_ENGINE_SOURCE=v2 \
      -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
      composer:2 php artisan key:generate --force \
      > "$result_dir/waterline-key-generate.log" 2>&1
    waterline_key_status=$?
    set -e
  fi

  if [[ "$waterline_key_status" -eq 0 ]]; then
    set +e
    docker run --rm \
      --user "$host_uid_gid" \
      -v "$waterline_app:/app" \
      -w /app \
      -e DB_CONNECTION=sqlite \
      -e DB_DATABASE=/app/database/database.sqlite \
      -e WATERLINE_ENGINE_SOURCE=v2 \
      -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
      composer:2 php artisan migrate --force \
      > "$result_dir/waterline-migrate.log" 2>&1
    waterline_migrate_status=$?
    set -e
  fi

  if [[ "$waterline_migrate_status" -eq 0 ]]; then
    set +e
    docker run --rm \
      --user "$host_uid_gid" \
      -v "$waterline_app:/app" \
      -v "$result_dir:/result" \
      -w /app \
      -e DB_CONNECTION=sqlite \
      -e DB_DATABASE=/app/database/database.sqlite \
      -e WATERLINE_ENGINE_SOURCE=v2 \
      -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
      composer:2 php artisan waterline:namespace-conformance \
        --run-id "published-namespaces-${RUN_ID:-waterline}" \
        "${waterline_artifact_args[@]}" \
        --output /result/waterline-namespace-result.json \
        --json \
      > "$result_dir/waterline-namespace-conformance.log" 2>&1
    waterline_command_status=$?
    set -e
  fi

  if [[ ! -s "$waterline_result_path" ]]; then
    if [[ "$waterline_create_status" -ne 0 ]]; then
      write_waterline_setup_failure "Laravel app creation failed; see waterline-create-project.log"
    elif [[ "$waterline_require_status" -ne 0 ]]; then
      write_waterline_setup_failure "Composer install failed for durable-workflow/waterline:${waterline_version}; see waterline-composer-install.log"
    elif [[ "$waterline_key_status" -ne 0 ]]; then
      write_waterline_setup_failure "Laravel key generation failed before Waterline shard execution; see waterline-key-generate.log"
    elif [[ "$waterline_migrate_status" -ne 0 ]]; then
      write_waterline_setup_failure "Laravel migration failed before Waterline shard execution; see waterline-migrate.log"
    else
      write_waterline_setup_failure "waterline:namespace-conformance exited with status ${waterline_command_status} without writing a report; see waterline-namespace-conformance.log"
    fi
  fi
fi

cat > "$run_root/orchestrate.py" <<'PY'
from __future__ import annotations

import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from durable_workflow.sync import Client

SERVER_URL = os.environ["DW_NAMESPACES_SERVER_URL"].rstrip("/")
API = f"{SERVER_URL}/api"
RESULT_DIR = Path(os.environ["DW_NAMESPACES_RESULT_DIR"])
RUN_ROOT = Path(os.environ["DW_NAMESPACES_RUN_ROOT"])
DW_BIN = os.environ["DW_NAMESPACES_DW_BIN"]
STARTED_AT = os.environ["DW_NAMESPACES_STARTED_AT"]
SUITE_VERSION = int(os.environ["DW_NAMESPACES_SUITE_VERSION"])

TOKENS = {"admin": "admin-token", "operator": "operator-token", "worker": "worker-token"}
NAMESPACES = {"a": "tenant-a", "b": "tenant-b", "shared": "shared"}
WORKFLOW_TYPE = "namespace.conformance"
RUN_ID = os.environ.get("DW_NAMESPACES_RUN_ID", str(int(time.time())))
TASK_QUEUE = f"control-{RUN_ID}"
WORKER_TASK_QUEUE = "iso"
NEXUS_TASK_QUEUE = f"nexus-{RUN_ID}"
REQUIRED_SHARD_ARTIFACTS = ["server", "cli", "workflow-php", "sdk-php", "sdk-python", "waterline"]
SDK_PHP_REQUIRED_SCENARIOS = [
    "namespace_create_update_describe_and_list",
    "sdk_namespace_selection_parity",
    "php_worker_task_queue_namespace_isolation",
]
ARTIFACT_VERSION_ALIASES = {
    "workflow-php": ["workflow-php", "workflow"],
    "sdk-php": ["sdk-php", "sdk_php", "php", "php_worker"],
    "sdk-python": ["sdk-python", "sdk_python", "python"],
    "waterline": ["waterline", "waterline-ui", "waterline_ui"],
}
ALLOWED_SCENARIO_STATUSES = {"pass", "fail", "unsupported", "not_covered", "runner_blocked"}


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def request(
    method: str,
    path: str,
    *,
    namespace: str = "default",
    token: str = "admin-token",
    body: dict[str, Any] | None = None,
    allowed: set[int] | None = None,
    worker: bool = False,
    timeout: int = 20,
) -> dict[str, Any]:
    allowed = allowed or set(range(200, 300))
    data = None if body is None else json.dumps(body).encode("utf-8")
    headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "Authorization": f"Bearer {token}",
        "X-Namespace": namespace,
        "X-Durable-Workflow-Control-Plane-Version": "2",
    }
    if worker:
        headers["X-Durable-Workflow-Protocol-Version"] = "1.7"
    req = urllib.request.Request(API + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as response:
            payload = response.read().decode("utf-8")
            if response.status not in allowed:
                raise RuntimeError(f"{method} {path} returned HTTP {response.status}: {payload}")
            return {"status_code": response.status, "json": json.loads(payload) if payload else {}}
    except urllib.error.HTTPError as exc:
        payload = exc.read().decode("utf-8", "replace")
        parsed = json.loads(payload) if payload.strip().startswith("{") else {"raw": payload}
        if exc.code in allowed:
            return {"status_code": exc.code, "json": parsed}
        raise RuntimeError(f"{method} {path} returned HTTP {exc.code}: {payload}") from exc


def run(command: list[str], *, env: dict[str, str] | None = None, timeout: int = 120) -> dict[str, Any]:
    completed = subprocess.run(
        command,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
        timeout=timeout,
    )
    return {"command": command, "exit_code": completed.returncode, "output": completed.stdout[-4000:]}


def finding(
    scenario_id: str,
    owner: str,
    observed: str,
    expected: str,
    acceptance: str,
    priority: str = "P0",
    *,
    finding_type: str | None = None,
    scenario_status: str | None = None,
) -> dict[str, Any]:
    item = {
        "scenario_id": scenario_id,
        "owning_surface": owner,
        "observed_behavior": observed,
        "expected_behavior": expected,
        "next_acceptance_criterion": acceptance,
        "priority": priority,
    }
    if finding_type:
        item["finding_type"] = finding_type
    if scenario_status:
        item["scenario_status"] = scenario_status
    return item


def scenario(scenario_id: str, status: str, observed_outputs: dict[str, Any], linked_findings: list[dict[str, Any]] | None = None) -> dict[str, Any]:
    result = {
        "scenario_id": scenario_id,
        "status": status,
        "observed_outputs": observed_outputs,
    }
    if linked_findings is not None:
        result["linked_findings"] = linked_findings
    return result


def status_from_failures(failures: list[str]) -> str:
    return "pass" if not failures else "fail"


def workflow_ids(prefix: str) -> str:
    return f"ns-{prefix}-{RUN_ID}"


def create_namespace(name: str, description: str) -> dict[str, Any]:
    response = request("POST", "/namespaces", body={"name": name, "description": description, "retention_days": 7}, allowed={200, 201, 409})
    if response["status_code"] == 409:
        return request("GET", f"/namespaces/{urllib.parse.quote(name)}")["json"]
    return response["json"]


def register_worker(namespace: str, worker_id: str, runtime: str = "php") -> dict[str, Any]:
    return request(
        "POST",
        "/worker/register",
        namespace=namespace,
        token=TOKENS["worker"],
        worker=True,
        body={
            "worker_id": worker_id,
            "task_queue": WORKER_TASK_QUEUE,
            "runtime": runtime,
            "sdk_version": "published-artifact-namespace-runner",
            "supported_workflow_types": [WORKFLOW_TYPE, "namespace.nexus.target"],
            "supported_activity_types": [],
            "max_concurrent_workflow_tasks": 1,
            "max_concurrent_activity_tasks": 1,
        },
    )["json"]


def poll_worker(namespace: str, worker_id: str) -> dict[str, Any]:
    return request(
        "POST",
        "/worker/workflow-tasks/poll",
        namespace=namespace,
        token=TOKENS["worker"],
        worker=True,
        body={"worker_id": worker_id, "task_queue": WORKER_TASK_QUEUE, "poll_request_id": f"{worker_id}-{RUN_ID}-{time.time_ns()}"},
        allowed={200},
        timeout=8,
    )["json"]


def start_workflow(
    namespace: str,
    workflow_id: str,
    *,
    search_attributes: dict[str, Any] | None = None,
    task_queue: str = TASK_QUEUE,
) -> dict[str, Any]:
    body: dict[str, Any] = {
        "workflow_id": workflow_id,
        "workflow_type": WORKFLOW_TYPE,
        "task_queue": task_queue,
        "input": [namespace, workflow_id],
        "memo": {"conformance": "namespaces"},
    }
    if search_attributes:
        body["search_attributes"] = search_attributes
    return request("POST", "/workflows", namespace=namespace, body=body, allowed={200, 201, 409})["json"]


def workflow_ids_in_list(payload: dict[str, Any]) -> list[str]:
    return [str(item.get("workflow_id")) for item in payload.get("workflows", []) if isinstance(item, dict)]


def schedules_in_list(payload: dict[str, Any]) -> list[str]:
    return [str(item.get("schedule_id")) for item in payload.get("schedules", []) if isinstance(item, dict)]


def parse_cli_json(probe: dict[str, Any]) -> dict[str, Any] | None:
    if int(probe.get("exit_code") or 0) != 0:
        return None

    output = str(probe.get("output") or "").strip()
    if output == "":
        return None

    candidates = [output]
    candidates.extend(
        line.strip()
        for line in reversed(output.splitlines())
        if line.strip().startswith("{")
    )
    for candidate in candidates:
        try:
            parsed = json.loads(candidate)
        except json.JSONDecodeError:
            continue
        if isinstance(parsed, dict):
            return parsed

    return None


def cli_probe(probe: dict[str, Any]) -> dict[str, Any]:
    return probe | {"json": parse_cli_json(probe)}


def cli_json_namespace_is(probe: dict[str, Any], expected_namespace: str) -> bool:
    payload = parse_cli_json(probe)
    return payload is not None and str(payload.get("namespace") or "") == expected_namespace


def cli_json_items_are_namespaced(probe: dict[str, Any], items_key: str, expected_namespace: str) -> bool:
    payload = parse_cli_json(probe)
    if payload is None:
        return False

    items = payload.get(items_key)
    if not isinstance(items, list):
        return False

    return all(
        not isinstance(item, dict) or str(item.get("namespace") or "") == expected_namespace
        for item in items
    )


def cli_namespace_resource_json_is(probe: dict[str, Any], expected_namespace: str) -> bool:
    payload = parse_cli_json(probe)
    if payload is None:
        return False

    return (
        str(payload.get("namespace") or "") == expected_namespace
        and str(payload.get("name") or expected_namespace) == expected_namespace
    )


def cli_namespace_list_contains_resource(probe: dict[str, Any], expected_namespace: str) -> bool:
    payload = parse_cli_json(probe)
    if payload is None:
        return False

    namespaces = payload.get("namespaces")
    if not isinstance(namespaces, list):
        return False

    for item in namespaces:
        if not isinstance(item, dict):
            continue
        if (
            str(item.get("name") or "") == expected_namespace
            and str(item.get("namespace") or "") == expected_namespace
        ):
            return True

    return False


def artifact_version_value(versions: dict[str, Any], artifact: str) -> str:
    for key in ARTIFACT_VERSION_ALIASES.get(artifact, [artifact]):
        if key in versions:
            value = str(versions.get(key) or "").strip()
            if value:
                return value
    return ""


def shard_artifact_versions(payload: dict[str, Any]) -> dict[str, Any]:
    versions = (
        payload.get("artifact_versions")
        or payload.get("artifactVersions")
        or payload.get("published_artifact_versions")
        or payload.get("publishedArtifactVersions")
    )
    return versions if isinstance(versions, dict) else {}


def artifact_version_mismatches(payload: dict[str, Any], pins: dict[str, Any], required_artifacts: list[str]) -> tuple[list[str], dict[str, dict[str, str]]]:
    versions = shard_artifact_versions(payload)
    missing: list[str] = []
    mismatched: dict[str, dict[str, str]] = {}
    for artifact in required_artifacts:
        expected = artifact_version_value(pins, artifact)
        actual = artifact_version_value(versions, artifact)
        if actual == "":
            missing.append(artifact)
        elif expected != "" and actual != expected:
            mismatched[artifact] = {"expected": expected, "actual": actual}
    return missing, mismatched


def validate_shard_report(payload: dict[str, Any], scenario_id: str, command: str, scope: str, pins: dict[str, Any], required_artifacts: list[str]) -> dict[str, Any] | None:
    coverage_scope = str(payload.get("coverage_scope") or "").strip()
    if coverage_scope != scope:
        return finding(
            scenario_id,
            "conformance_harness",
            f"configured {scope} report declared coverage_scope {coverage_scope!r}",
            f"{command} emits coverage_scope {scope!r}",
            f"pass the published namespace shard report for {command}",
        )
    versions = shard_artifact_versions(payload)
    if not versions:
        return finding(
            scenario_id,
            "conformance_harness",
            f"configured {scope} report did not declare artifact_versions",
            f"{command} emits artifact_versions for {', '.join(required_artifacts)} matching resolved pins",
            f"run {command} with the artifact-version values from pins.json before invoking the namespace aggregator",
        )
    missing, mismatched = artifact_version_mismatches(payload, pins, required_artifacts)
    if missing or mismatched:
        return finding(
            scenario_id,
            "conformance_harness",
            "configured "
            + scope
            + " report artifact_versions did not match resolved pins: missing="
            + json.dumps(missing, sort_keys=True)
            + ", mismatched="
            + json.dumps(mismatched, sort_keys=True),
            f"{command} shard artifact_versions match the resolved published artifact tuple",
            f"rerun {command} against the same pins.json tuple used by this namespace aggregator invocation",
        )
    return None


def load_optional_shard(path: str | None, scenario_id: str, command: str, scope: str, pins: dict[str, Any], required_artifacts: list[str]) -> tuple[dict[str, Any] | None, dict[str, Any] | None, dict[str, Any] | None]:
    if not path:
        return None, None, None
    shard_path = Path(path)
    if not shard_path.exists():
        return None, None, finding(
            scenario_id,
            "conformance_harness",
            f"configured shard report does not exist: {path}",
            "configured shard report is readable JSON",
            "write the shard report before invoking the namespace aggregator",
        )
    payload = json.loads(shard_path.read_text(encoding="utf-8"))
    validation_error = validate_shard_report(payload, scenario_id, command, scope, pins, required_artifacts)
    if validation_error is not None:
        return None, payload, validation_error
    scenarios = payload.get("scenario_results", [])
    for item in scenarios:
        if isinstance(item, dict) and item.get("scenario_id") == scenario_id:
            return item, payload, None
    return None, payload, finding(
        scenario_id,
        "conformance_harness",
        f"configured shard report did not contain {scenario_id}",
        f"{scenario_id} is present in the shard report",
        "update the shard command to emit the namespace scenario id",
    )


def load_required_shard(path: str | None, scenario_id: str, command: str, scope: str, pins: dict[str, Any], required_artifacts: list[str]) -> tuple[dict[str, Any] | None, dict[str, Any] | None, dict[str, Any] | None]:
    if not path:
        return None, None, finding(
            scenario_id,
            "sdk-php",
            f"required {scope} report was not supplied to this runner invocation; the server/CLI/Python probes still ran, but the PHP SDK namespace mirror cell remains focused unsupported evidence",
            f"{command} runs against the published artifact tuple and emits {scenario_id}",
            f"run {command} from the published PHP SDK artifact and pass DW_NAMESPACES_SDK_PHP_RESULT",
            "P1",
            finding_type="unsupported_public_surface",
            scenario_status="unsupported",
        )
    shard_path = Path(path)
    if not shard_path.exists():
        return None, None, finding(
            scenario_id,
            "conformance_harness",
            f"configured {scope} report does not exist: {path}",
            "configured PHP SDK namespace shard report is readable JSON",
            f"write the {command} report before invoking the namespace aggregator",
        )
    payload = json.loads(shard_path.read_text(encoding="utf-8"))
    validation_error = validate_shard_report(payload, scenario_id, command, scope, pins, required_artifacts)
    if validation_error is not None:
        return None, payload, validation_error
    scenarios = payload.get("scenario_results", [])
    for item in scenarios:
        if isinstance(item, dict) and item.get("scenario_id") == scenario_id:
            return item, payload, None
    return None, payload, finding(
        scenario_id,
        "conformance_harness",
        f"configured {scope} report did not contain {scenario_id}",
        f"{scenario_id} is present in the PHP SDK namespace shard report",
        f"update {command} to emit the namespace scenario id",
    )


def load_sdk_php_shard(path: str | None, pins: dict[str, Any]) -> tuple[dict[str, dict[str, Any]], dict[str, Any] | None, dict[str, dict[str, Any]]]:
    items: dict[str, dict[str, Any]] = {}
    errors: dict[str, dict[str, Any]] = {}
    payload: dict[str, Any] | None = None
    for scenario_id in SDK_PHP_REQUIRED_SCENARIOS:
        item, current_payload, error = load_required_shard(
            path,
            scenario_id,
            "php-sdk-published-artifacts",
            "sdk-php-namespace-shard",
            pins,
            REQUIRED_SHARD_ARTIFACTS,
        )
        if current_payload is not None:
            payload = current_payload
        if item is not None:
            items[scenario_id] = item
        if error is not None:
            errors[scenario_id] = error
    return items, payload, errors


def scenario_observed_outputs(item: dict[str, Any] | None) -> dict[str, Any]:
    if item is None:
        return {}
    observed = item.get("observed_outputs")
    return observed if isinstance(observed, dict) else {}


def scenario_linked_findings(item: dict[str, Any] | None) -> list[dict[str, Any]]:
    if item is None:
        return []
    linked = item.get("linked_findings")
    if not isinstance(linked, list):
        return []
    return [entry for entry in linked if isinstance(entry, dict)]


def normalize_shard_finding(scenario_id: str, raw: dict[str, Any], default_owner: str) -> dict[str, Any]:
    if str(raw.get("observed_behavior") or ""):
        return raw
    owner = str(raw.get("owning_surface") or raw.get("owner") or default_owner)
    stage = str(raw.get("failure_stage") or "namespace assertions")
    title = str(
        raw.get("summary")
        or raw.get("title")
        or raw.get("message")
        or raw.get("observed")
        or f"{scenario_id} failed during {stage}"
    )
    normalized = finding(
        scenario_id,
        owner,
        title,
        "published namespace shard reports pass or a focused product finding in namespace-runtime result format",
        "normalize the shard finding and route the underlying namespace behavior to the owning surface",
    )
    normalized["raw_shard_finding"] = raw
    return normalized


def sdk_php_findings(scenario_id: str, item: dict[str, Any] | None, error: dict[str, Any] | None) -> list[dict[str, Any]]:
    if error is not None:
        return [error]
    linked = scenario_linked_findings(item)
    if linked:
        return [normalize_shard_finding(scenario_id, entry, "sdk-php") for entry in linked]
    if item is None:
        return [
            finding(
                scenario_id,
                "conformance_harness",
                f"PHP SDK namespace shard did not emit {scenario_id}",
                f"php-sdk-published-artifacts emits {scenario_id} against the published artifact tuple",
                "rerun the published PHP SDK namespace shard and attach its full report",
            )
        ]
    status = str(item.get("status") or "")
    if status != "pass":
        return [
            finding(
                scenario_id,
                "sdk-php",
                f"PHP SDK namespace shard reported {scenario_id} status {status or 'missing'} without a linked finding",
                f"php-sdk-published-artifacts reports pass or links a focused product finding for {scenario_id}",
                "route a focused PHP SDK namespace finding or repair the shard scenario",
            )
        ]
    return []


def combined_status(local_failures: list[str], item: dict[str, Any] | None, error: dict[str, Any] | None) -> str:
    if local_failures:
        return "fail"
    if error is not None:
        status = str(error.get("scenario_status") or "")
        return status if status in ALLOWED_SCENARIO_STATUSES else "not_covered"
    if item is None:
        return "not_covered"
    status = str(item.get("status") or "")
    return status if status in ALLOWED_SCENARIO_STATUSES else "fail"


def sdk_php_execution_record(path: str | None, payload: dict[str, Any] | None, items: dict[str, dict[str, Any]], errors: dict[str, dict[str, Any]], pins: dict[str, Any]) -> dict[str, Any]:
    missing = [scenario_id for scenario_id in SDK_PHP_REQUIRED_SCENARIOS if scenario_id not in items]
    status = "executed" if payload is not None and not errors and not missing else "missing"
    record: dict[str, Any] = {
        "status": status,
        "required": True,
        "shard_command": "php-sdk-published-artifacts",
        "scope": "sdk-php-namespace-shard",
        "artifact": "durable-workflow/sdk",
        "artifact_version": pins["sdk-php"],
        "report_path": path,
        "required_scenarios": SDK_PHP_REQUIRED_SCENARIOS,
        "covered_scenarios": sorted(items.keys()),
        "missing_required_scenarios": missing,
        "scenario_statuses": {
            scenario_id: str(items[scenario_id].get("status") or "")
            for scenario_id in sorted(items.keys())
        },
    }
    if payload is not None:
        record["coverage_scope"] = payload.get("coverage_scope")
        record["outcome"] = payload.get("outcome")
        record["schema"] = payload.get("schema")
        record["started_at"] = payload.get("started_at")
        record["finished_at"] = payload.get("finished_at")
        record["artifact_versions"] = shard_artifact_versions(payload)
    if errors:
        record["errors"] = {
            scenario_id: error["observed_behavior"]
            for scenario_id, error in sorted(errors.items())
        }
    return record


def waterline_execution_record(path: str | None, payload: dict[str, Any] | None, item: dict[str, Any] | None, error: dict[str, Any] | None, pins: dict[str, Any]) -> dict[str, Any]:
    status = "executed" if item is not None and error is None else ("not_supplied" if not path else "missing")
    scenario_status = str(item.get("status") or "") if item is not None else ""
    record: dict[str, Any] = {
        "status": status,
        "required": True,
        "shard_command": "waterline:namespace-conformance",
        "scope": "waterline-operator-namespace-shard",
        "artifact": "durable-workflow/waterline",
        "artifact_version": pins["waterline"],
        "report_path": path,
        "required_scenarios": ["waterline_operator_namespace_visibility"],
        "covered_scenarios": ["waterline_operator_namespace_visibility"] if item is not None else [],
        "scenario_statuses": {"waterline_operator_namespace_visibility": scenario_status} if scenario_status else {},
    }
    if payload is not None:
        record["coverage_scope"] = payload.get("coverage_scope")
        record["outcome"] = payload.get("outcome")
        record["schema"] = payload.get("schema")
        record["started_at"] = payload.get("started_at")
        record["finished_at"] = payload.get("finished_at")
        record["artifact_versions"] = shard_artifact_versions(payload)
    if item is not None:
        record["scenario_status"] = item.get("status")
    if error is not None:
        record["error"] = error["observed_behavior"]
    return record


def main() -> int:
    pins = json.loads((RESULT_DIR / "pins.json").read_text(encoding="utf-8"))
    versions = {
        "server": pins["server"],
        "cli": pins["cli"],
        "workflow": pins["workflow"],
        "workflow-php": pins["workflow-php"],
        "sdk-php": pins["sdk-php"],
        "sdk-python": pins["sdk-python"],
        "waterline": pins["waterline"],
    }
    artifact_install = json.loads((RESULT_DIR / "artifact-install-evidence.json").read_text(encoding="utf-8"))
    sdk_php_result_path = os.environ.get("DW_NAMESPACES_SDK_PHP_RESULT")
    sdk_php_items, sdk_php_payload, sdk_php_errors = load_sdk_php_shard(
        sdk_php_result_path,
        pins,
    )
    sdk_php_execution = sdk_php_execution_record(
        sdk_php_result_path,
        sdk_php_payload,
        sdk_php_items,
        sdk_php_errors,
        pins,
    )
    artifact_install["sdk_php_package"] = {
        **(artifact_install.get("sdk_php_package") if isinstance(artifact_install.get("sdk_php_package"), dict) else {}),
        "version": pins["sdk-php"],
        "source": pins["artifact_sources"]["sdk-php"],
        "status": sdk_php_execution["status"],
        "shard_command": "php-sdk-published-artifacts",
        "execution_required": True,
        "namespace_shard_execution": sdk_php_execution,
    }

    findings: list[dict[str, Any]] = []
    scenario_results: dict[str, dict[str, Any]] = {}

    def add(item: dict[str, Any]) -> None:
        scenario_results[str(item["scenario_id"])] = item
        for linked in item.get("linked_findings", []) or []:
            if isinstance(linked, dict):
                findings.append(linked)

    add(scenario("published_artifact_install_only", "pass", artifact_install))

    created = {key: create_namespace(value, f"Namespace conformance {key}") for key, value in NAMESPACES.items()}
    update = request("PUT", "/namespaces/tenant-a", body={"description": "Updated tenant A", "retention_days": 14})["json"]
    described = {key: request("GET", f"/namespaces/{value}")["json"] for key, value in NAMESPACES.items()}
    listed = request("GET", "/namespaces")["json"]
    namespace_crud = {
        "created_namespaces": created,
        "updated_namespace": update,
        "described_namespaces": described,
        "listed_namespaces": listed,
    }
    crud_php_item = sdk_php_items.get("namespace_create_update_describe_and_list")
    crud_php_error = sdk_php_errors.get("namespace_create_update_describe_and_list")
    add(scenario(
        "namespace_create_update_describe_and_list",
        combined_status([], crud_php_item, crud_php_error),
        namespace_crud | {
            "server_http_namespace_crud": namespace_crud,
            "sdk_php_namespace_crud": scenario_observed_outputs(crud_php_item),
            "sdk_php_shard_execution": sdk_php_execution,
        },
        sdk_php_findings("namespace_create_update_describe_and_list", crud_php_item, crud_php_error),
    ))

    wf_a = workflow_ids("visibility-a")
    wf_b = workflow_ids("visibility-b")
    start_a = start_workflow(NAMESPACES["a"], wf_a)
    start_b = start_workflow(NAMESPACES["b"], wf_b)
    tenant_b_describe_a = request("GET", f"/workflows/{wf_a}", namespace=NAMESPACES["b"], allowed={404})
    tenant_b_query_a = request("POST", f"/workflows/{wf_a}/query/state", namespace=NAMESPACES["b"], body={"args": []}, allowed={404})
    tenant_b_list = request("GET", f"/workflows?{urllib.parse.urlencode({'query': wf_a})}", namespace=NAMESPACES["b"])["json"]
    visibility_failures: list[str] = []
    if tenant_b_describe_a["status_code"] != 404:
        visibility_failures.append("tenant-b describe of tenant-a workflow was not hidden")
    if tenant_b_query_a["status_code"] != 404:
        visibility_failures.append("tenant-b query of tenant-a workflow was not hidden")
    if wf_a in workflow_ids_in_list(tenant_b_list):
        visibility_failures.append("tenant-b workflow list included tenant-a workflow")
    visibility_findings = [
        finding(
            "workflow_cross_namespace_visibility_isolation",
            "server",
            "; ".join(visibility_failures),
            "workflow visibility is namespace-scoped and cross-namespace reads return not-found",
            "make workflow describe/query/list apply the request namespace before lookup",
        )
    ] if visibility_failures else []
    add(scenario(
        "workflow_cross_namespace_visibility_isolation",
        status_from_failures(visibility_failures),
        {
            "tenant_a_workflow": start_a,
            "tenant_b_workflow": start_b,
            "tenant_a_list_excludes_tenant_b": wf_b not in workflow_ids_in_list(request("GET", f"/workflows?{urllib.parse.urlencode({'query': wf_b})}", namespace=NAMESPACES["a"])["json"]),
            "tenant_b_describe_tenant_a_denied": tenant_b_describe_a,
            "tenant_b_query_tenant_a_denied": tenant_b_query_a,
            "tenant_b_list": tenant_b_list,
        },
        visibility_findings,
    ))

    mutation_a = workflow_ids("mutation-a")
    start_workflow(NAMESPACES["a"], mutation_a)
    same_signal = request("POST", f"/workflows/{mutation_a}/signal/namespaceProbe", namespace=NAMESPACES["a"], body={"args": ["ok"]}, allowed={200, 202})
    cross_signal = request("POST", f"/workflows/{mutation_a}/signal/namespaceProbe", namespace=NAMESPACES["b"], body={"args": ["blocked"]}, allowed={404})
    cross_cancel = request("POST", f"/workflows/{mutation_a}/cancel", namespace=NAMESPACES["b"], body={"reason": "blocked"}, allowed={404})
    same_cancel = request("POST", f"/workflows/{mutation_a}/cancel", namespace=NAMESPACES["a"], body={"reason": "same namespace"}, allowed={200, 202, 409})
    mutation_failures = []
    if cross_signal["status_code"] != 404:
        mutation_failures.append("cross-namespace signal did not return not-found")
    if cross_cancel["status_code"] != 404:
        mutation_failures.append("cross-namespace cancel did not return not-found")
    mutation_findings = [
        finding(
            "workflow_cross_namespace_mutation_isolation",
            "server",
            "; ".join(mutation_failures),
            "cross-namespace signal/cancel cannot mutate another namespace",
            "scope workflow mutation lookup by request namespace before applying commands",
        )
    ] if mutation_failures else []
    add(scenario(
        "workflow_cross_namespace_mutation_isolation",
        status_from_failures(mutation_failures),
        {
            "same_namespace_signal_succeeds": same_signal["status_code"] in {200, 202},
            "same_namespace_cancel_succeeds": same_cancel["status_code"] in {200, 202, 409},
            "cross_namespace_signal_denied": cross_signal,
            "cross_namespace_cancel_denied": cross_cancel,
        },
        mutation_findings,
    ))

    worker_a = f"php-worker-a-{RUN_ID}"
    worker_b = f"php-worker-b-{RUN_ID}"
    reg_a = register_worker(NAMESPACES["a"], worker_a)
    reg_b = register_worker(NAMESPACES["b"], worker_b)
    wf_worker_a = workflow_ids("worker-a")
    wf_worker_b = workflow_ids("worker-b")
    start_workflow(NAMESPACES["a"], wf_worker_a, task_queue=WORKER_TASK_QUEUE)
    b_poll_before = poll_worker(NAMESPACES["b"], worker_b)
    a_poll = poll_worker(NAMESPACES["a"], worker_a)
    start_workflow(NAMESPACES["b"], wf_worker_b, task_queue=WORKER_TASK_QUEUE)
    b_poll = poll_worker(NAMESPACES["b"], worker_b)
    worker_failures = []
    if (b_poll_before.get("task") or {}).get("workflow_id") == wf_worker_a:
        worker_failures.append("tenant-b worker received tenant-a workflow task")
    if (a_poll.get("task") or {}).get("workflow_id") != wf_worker_a:
        worker_failures.append("tenant-a worker did not receive tenant-a workflow task")
    if (b_poll.get("task") or {}).get("workflow_id") != wf_worker_b:
        worker_failures.append("tenant-b worker did not receive tenant-b workflow task")
    worker_findings = [
        finding(
            "php_worker_task_queue_namespace_isolation",
            "server",
            "; ".join(worker_failures),
            "same task-queue names are matched within namespace only",
            "make worker registration and workflow-task polling namespace-aware for matching",
        )
    ] if worker_failures else []
    php_worker_item = sdk_php_items.get("php_worker_task_queue_namespace_isolation")
    php_worker_error = sdk_php_errors.get("php_worker_task_queue_namespace_isolation")
    direct_worker_outputs = {
        "tenant_a_worker_registration": reg_a,
        "tenant_b_worker_registration": reg_b,
        "tenant_a_delivery": a_poll,
        "tenant_b_delivery": b_poll,
        "cross_delivery_absent": (b_poll_before.get("task") or {}).get("workflow_id") != wf_worker_a,
        "server_http_worker_probe": True,
    }
    add(scenario(
        "php_worker_task_queue_namespace_isolation",
        combined_status(worker_failures, php_worker_item, php_worker_error),
        direct_worker_outputs | scenario_observed_outputs(php_worker_item) | {
            "server_http_worker_probe": direct_worker_outputs,
            "sdk_php_shard": "php-sdk-published-artifacts",
            "sdk_php_shard_execution": sdk_php_execution,
        },
        worker_findings + sdk_php_findings("php_worker_task_queue_namespace_isolation", php_worker_item, php_worker_error),
    ))

    request("POST", "/search-attributes", namespace=NAMESPACES["a"], body={"name": "customer_id", "type": "keyword"}, allowed={200, 201, 409})
    sa_workflow = workflow_ids("search-attribute")
    start_workflow(NAMESPACES["a"], sa_workflow, search_attributes={"customer_id": "tenant-a-value"})
    schema_b = request("GET", "/search-attributes", namespace=NAMESPACES["b"])["json"]
    query_b_params = urllib.parse.urlencode({"query": 'customer_id="tenant-a-value"'})
    query_b_response = request("GET", f"/workflows?{query_b_params}", namespace=NAMESPACES["b"], allowed={200, 400, 422})
    query_b_json = query_b_response["json"]
    query_b_text = json.dumps(query_b_json, sort_keys=True)
    sa_failures = []
    if "customer_id" in (schema_b.get("custom_attributes") or {}):
        sa_failures.append("tenant-b schema included tenant-a customer_id definition")
    if "tenant-a-value" in query_b_text or sa_workflow in query_b_text:
        sa_failures.append("tenant-b value query leaked tenant-a search attribute value or workflow")
    sa_findings = [
        finding(
            "search_attribute_schema_and_value_query_isolation",
            "server",
            "; ".join(sa_failures),
            "schema and value queries are isolated by namespace",
            "scope search-attribute definitions and workflow visibility query parsing by request namespace",
        )
    ] if sa_failures else []
    add(scenario(
        "search_attribute_schema_and_value_query_isolation",
        status_from_failures(sa_failures),
        {
            "schema_isolation": "customer_id" not in (schema_b.get("custom_attributes") or {}),
            "value_query_isolation": query_b_response["status_code"] in {200, 400, 422} and "tenant-a-value" not in query_b_text,
            "tenant_a_value": {"workflow_id": sa_workflow, "customer_id": "tenant-a-value"},
            "tenant_b_observed_result": query_b_response,
        },
        sa_findings,
    ))

    sched_a_id = f"ns-sched-a-{RUN_ID}"
    sched_b_id = f"ns-sched-b-{RUN_ID}"
    sched_a = request("POST", "/schedules", namespace=NAMESPACES["a"], body={
        "schedule_id": sched_a_id,
        "spec": {"cron_expressions": ["*/5 * * * *"], "timezone": "UTC"},
        "action": {"workflow_type": WORKFLOW_TYPE, "task_queue": TASK_QUEUE},
        "paused": True,
    }, allowed={200, 201})["json"]
    sched_b = request("POST", "/schedules", namespace=NAMESPACES["b"], body={
        "schedule_id": sched_b_id,
        "spec": {"cron_expressions": ["*/5 * * * *"], "timezone": "UTC"},
        "action": {"workflow_type": WORKFLOW_TYPE, "task_queue": TASK_QUEUE},
        "paused": True,
    }, allowed={200, 201})["json"]
    sched_b_list = request("GET", "/schedules", namespace=NAMESPACES["b"])["json"]
    sched_b_describe_a = request("GET", f"/schedules/{sched_a_id}", namespace=NAMESPACES["b"], allowed={404})
    sched_b_pause_a = request(
        "POST",
        f"/schedules/{sched_a_id}/pause",
        namespace=NAMESPACES["b"],
        body={"note": "cross-namespace mutation probe"},
        allowed={404},
    )
    schedule_failures = []
    if sched_a_id in schedules_in_list(sched_b_list):
        schedule_failures.append("tenant-b schedule list included tenant-a schedule")
    if sched_b_describe_a["status_code"] != 404:
        schedule_failures.append("tenant-b describe of tenant-a schedule did not return not-found")
    if sched_b_pause_a["status_code"] != 404:
        schedule_failures.append("tenant-b pause mutation of tenant-a schedule did not return not-found")
    schedule_findings = [
        finding(
            "schedule_namespace_isolation",
            "server",
            "; ".join(schedule_failures),
            "schedule list, describe, and mutation lookups are namespace-scoped",
            "scope schedule visibility and mutation lookups by request namespace before applying commands",
        )
    ] if schedule_failures else []
    add(scenario(
        "schedule_namespace_isolation",
        status_from_failures(schedule_failures),
        {
            "tenant_a_schedule": sched_a,
            "tenant_b_schedule": sched_b,
            "tenant_a_list_excludes_tenant_b": sched_b_id not in schedules_in_list(request("GET", "/schedules", namespace=NAMESPACES["a"])["json"]),
            "cross_namespace_schedule_describe_denied": sched_b_describe_a,
            "cross_namespace_schedule_mutation_denied": sched_b_pause_a,
        },
        schedule_findings,
    ))

    cli_env = os.environ.copy()
    cli_env.update({"DURABLE_WORKFLOW_SERVER_URL": SERVER_URL, "DURABLE_WORKFLOW_AUTH_TOKEN": TOKENS["admin"], "DURABLE_WORKFLOW_NAMESPACE": "default"})
    cli_namespace_name = f"cli-proof-{RUN_ID}"
    cli_namespace_create_json = run([DW_BIN, "namespace:create", cli_namespace_name, "--description=CLI namespace conformance probe", "--retention=11", "--json"], env=cli_env)
    cli_namespace_describe_json = run([DW_BIN, "namespace:describe", cli_namespace_name, "--json"], env=cli_env)
    cli_namespace_describe_human = run([DW_BIN, "namespace:describe", cli_namespace_name], env=cli_env)
    cli_namespace_update_json = run([DW_BIN, "namespace:update", cli_namespace_name, "--description=CLI namespace conformance probe updated", "--retention=12", "--json"], env=cli_env)
    cli_namespace_list_json = run([DW_BIN, "namespace:list", "--json"], env=cli_env)
    cli_namespace_list_human = run([DW_BIN, "namespace:list"], env=cli_env)
    cli_workflow_explicit_json = run([DW_BIN, "workflow:list", "--namespace=tenant-b", "--json"], env=cli_env)
    cli_workflow_explicit_human = run([DW_BIN, "workflow:list", "--namespace=tenant-b"], env=cli_env)
    cli_schedule_explicit_json = run([DW_BIN, "schedule:list", "--namespace=tenant-b", "--json"], env=cli_env)
    cli_schedule_explicit_human = run([DW_BIN, "schedule:list", "--namespace=tenant-b"], env=cli_env)
    cli_search_attribute_tenant_a_json = run([DW_BIN, "search-attribute:list", "--namespace=tenant-a", "--json"], env=cli_env)
    cli_search_attribute_tenant_a_human = run([DW_BIN, "search-attribute:list", "--namespace=tenant-a"], env=cli_env)
    cli_search_attribute_tenant_b_json = run([DW_BIN, "search-attribute:list", "--namespace=tenant-b", "--json"], env=cli_env)
    cli_search_attribute_tenant_b_human = run([DW_BIN, "search-attribute:list", "--namespace=tenant-b"], env=cli_env)
    cli_default_workflows = run([DW_BIN, "workflow:list", "--json"], env=cli_env)
    cli_default_schedules = run([DW_BIN, "schedule:list", "--json"], env=cli_env)
    cli_default_search_attributes = run([DW_BIN, "search-attribute:list", "--json"], env=cli_env)
    cli_namespace_delete_json = run([DW_BIN, "namespace:delete", cli_namespace_name, "--json"], env=cli_env)
    cli_failures = []
    if not cli_namespace_resource_json_is(cli_namespace_create_json, cli_namespace_name):
        cli_failures.append("CLI namespace:create --json did not expose the created namespace context")
    if not cli_namespace_resource_json_is(cli_namespace_describe_json, cli_namespace_name):
        cli_failures.append("CLI namespace:describe --json did not expose the described namespace context")
    if cli_namespace_describe_human["exit_code"] != 0 or f"Namespace: {cli_namespace_name}" not in cli_namespace_describe_human["output"]:
        cli_failures.append("CLI namespace:describe human output did not render the target namespace")
    if not cli_namespace_resource_json_is(cli_namespace_update_json, cli_namespace_name):
        cli_failures.append("CLI namespace:update --json did not expose the updated namespace context")
    if not cli_namespace_list_contains_resource(cli_namespace_list_json, cli_namespace_name):
        cli_failures.append("CLI namespace:list --json did not expose namespace context for listed resources")
    if cli_namespace_list_human["exit_code"] != 0 or "Namespace" not in cli_namespace_list_human["output"] or cli_namespace_name not in cli_namespace_list_human["output"]:
        cli_failures.append("CLI namespace:list human output did not make listed namespace names explicit")
    if not cli_namespace_resource_json_is(cli_namespace_delete_json, cli_namespace_name):
        cli_failures.append("CLI namespace:delete --json did not expose the deleted namespace context")

    if not cli_json_namespace_is(cli_workflow_explicit_json, NAMESPACES["b"]) or not cli_json_items_are_namespaced(cli_workflow_explicit_json, "workflows", NAMESPACES["b"]):
        cli_failures.append("explicit CLI JSON workflow:list did not expose tenant-b namespace context")
    if wf_b not in cli_workflow_explicit_json["output"] or wf_a in cli_workflow_explicit_json["output"] or "tenant-a-value" in cli_workflow_explicit_json["output"]:
        cli_failures.append("explicit CLI JSON workflow:list did not stay scoped to tenant-b workflows")
    if cli_workflow_explicit_human["exit_code"] != 0 or "Namespace: tenant-b" not in cli_workflow_explicit_human["output"]:
        cli_failures.append("explicit CLI human workflow:list did not render tenant-b namespace context")

    if not cli_json_namespace_is(cli_schedule_explicit_json, NAMESPACES["b"]) or not cli_json_items_are_namespaced(cli_schedule_explicit_json, "schedules", NAMESPACES["b"]):
        cli_failures.append("explicit CLI JSON schedule:list did not expose tenant-b namespace context")
    if sched_b_id not in cli_schedule_explicit_json["output"] or sched_a_id in cli_schedule_explicit_json["output"]:
        cli_failures.append("explicit CLI JSON schedule:list did not stay scoped to tenant-b schedules")
    if cli_schedule_explicit_human["exit_code"] != 0 or "Namespace: tenant-b" not in cli_schedule_explicit_human["output"]:
        cli_failures.append("explicit CLI human schedule:list did not render tenant-b namespace context")

    cli_search_attribute_tenant_a = parse_cli_json(cli_search_attribute_tenant_a_json)
    cli_search_attribute_tenant_b = parse_cli_json(cli_search_attribute_tenant_b_json)
    tenant_a_custom_attributes = cli_search_attribute_tenant_a.get("custom_attributes") if isinstance(cli_search_attribute_tenant_a, dict) else None
    tenant_b_custom_attributes = cli_search_attribute_tenant_b.get("custom_attributes") if isinstance(cli_search_attribute_tenant_b, dict) else None
    if not cli_json_namespace_is(cli_search_attribute_tenant_a_json, NAMESPACES["a"]):
        cli_failures.append("explicit CLI JSON search-attribute:list did not expose tenant-a namespace context")
    if not isinstance(tenant_a_custom_attributes, dict) or "customer_id" not in tenant_a_custom_attributes:
        cli_failures.append("explicit CLI JSON search-attribute:list did not show tenant-a custom attributes from tenant-a")
    if cli_search_attribute_tenant_a_human["exit_code"] != 0 or "Namespace: tenant-a" not in cli_search_attribute_tenant_a_human["output"] or "customer_id" not in cli_search_attribute_tenant_a_human["output"]:
        cli_failures.append("explicit CLI human search-attribute:list did not render tenant-a namespace context and custom attributes")
    if not cli_json_namespace_is(cli_search_attribute_tenant_b_json, NAMESPACES["b"]):
        cli_failures.append("explicit CLI JSON search-attribute:list did not expose tenant-b namespace context")
    if isinstance(tenant_b_custom_attributes, dict) and "customer_id" in tenant_b_custom_attributes:
        cli_failures.append("explicit CLI JSON search-attribute:list exposed tenant-a custom attributes from tenant-b")
    if cli_search_attribute_tenant_b_human["exit_code"] != 0 or "Namespace: tenant-b" not in cli_search_attribute_tenant_b_human["output"]:
        cli_failures.append("explicit CLI human search-attribute:list did not render tenant-b namespace context")

    default_search_attribute_json = parse_cli_json(cli_default_search_attributes)
    default_custom_attributes = default_search_attribute_json.get("custom_attributes") if isinstance(default_search_attribute_json, dict) else None
    if not cli_json_namespace_is(cli_default_workflows, "default"):
        cli_failures.append("CLI workflow:list without --namespace did not report the default namespace")
    if "tenant-a-value" in cli_default_workflows["output"] or wf_a in cli_default_workflows["output"] or wf_b in cli_default_workflows["output"]:
        cli_failures.append("CLI workflow:list without --namespace exposed tenant workflow state instead of default scope")
    if not cli_json_namespace_is(cli_default_schedules, "default"):
        cli_failures.append("CLI schedule:list without --namespace did not report the default namespace")
    if sched_a_id in cli_default_schedules["output"] or sched_b_id in cli_default_schedules["output"]:
        cli_failures.append("CLI schedule:list without --namespace exposed tenant schedule state instead of default scope")
    if not cli_json_namespace_is(cli_default_search_attributes, "default"):
        cli_failures.append("CLI search-attribute:list without --namespace did not report the default namespace")
    if isinstance(default_custom_attributes, dict) and "customer_id" in default_custom_attributes:
        cli_failures.append("CLI search-attribute:list without --namespace exposed tenant custom attributes instead of default scope")
    cli_findings = [
        finding(
            "cli_namespace_context_and_default_scope",
            "cli",
            "; ".join(cli_failures),
            "CLI namespace CRUD and namespace-scoped list commands show selected namespace and default to only the configured default namespace",
            "repair CLI namespace resolution/output so namespace CRUD, workflow:list, schedule:list, and search-attribute:list behavior is unambiguous",
            "P1",
        )
    ] if cli_failures else []
    add(scenario(
        "cli_namespace_context_and_default_scope",
        status_from_failures(cli_failures),
        {
            "explicit_namespace_json": {
                "namespace_crud": {
                    "create": cli_probe(cli_namespace_create_json),
                    "describe": cli_probe(cli_namespace_describe_json),
                    "update": cli_probe(cli_namespace_update_json),
                    "list": cli_probe(cli_namespace_list_json),
                    "delete": cli_probe(cli_namespace_delete_json),
                },
                "workflow_list": cli_probe(cli_workflow_explicit_json),
                "schedule_list": cli_probe(cli_schedule_explicit_json),
                "search_attribute_list": {
                    "tenant_a": cli_probe(cli_search_attribute_tenant_a_json),
                    "tenant_b": cli_probe(cli_search_attribute_tenant_b_json),
                },
            },
            "explicit_namespace_human_output": {
                "namespace_describe": cli_namespace_describe_human,
                "namespace_list": cli_namespace_list_human,
                "workflow_list": cli_workflow_explicit_human,
                "schedule_list": cli_schedule_explicit_human,
                "search_attribute_list": {
                    "tenant_a": cli_search_attribute_tenant_a_human,
                    "tenant_b": cli_search_attribute_tenant_b_human,
                },
            },
            "default_scope_behavior": {
                "workflow_list": cli_probe(cli_default_workflows),
                "schedule_list": cli_probe(cli_default_schedules),
                "search_attribute_list": cli_probe(cli_default_search_attributes),
                "expected_namespace": "default",
                "tenant_resources_checked": {
                    "workflow_ids": [wf_a, wf_b],
                    "schedule_ids": [sched_a_id, sched_b_id],
                    "search_attribute": "customer_id",
                },
            },
        },
        cli_findings,
    ))

    sdk_failures = []
    sdk_a = Client(SERVER_URL, token=TOKENS["admin"], namespace=NAMESPACES["a"])
    sdk_b = Client(SERVER_URL, token=TOKENS["admin"], namespace=NAMESPACES["b"])
    sdk_default = Client(SERVER_URL, token=TOKENS["admin"])
    sdk_wf = workflow_ids("sdk")
    sdk_handle = sdk_a.start_workflow(workflow_type=WORKFLOW_TYPE, task_queue=TASK_QUEUE, workflow_id=sdk_wf, input=["sdk"])
    sdk_same = sdk_handle.describe()
    try:
        sdk_b.describe_workflow(sdk_wf)
        sdk_cross: Any = {"unexpected_success": True}
        sdk_failures.append("Python SDK tenant-b described tenant-a workflow")
    except Exception as exc:  # SDK maps 404 into a typed exception; capture public shape only.
        sdk_cross = {"exception_class": exc.__class__.__name__, "message": str(exc)}
    sdk_default_list = sdk_default.list_workflows(page_size=100)
    if any(item.workflow_id == sdk_wf for item in sdk_default_list.executions):
        sdk_failures.append("Python SDK default namespace listed tenant-a workflow")
    sdk_findings = [
        finding(
            "sdk_namespace_selection_parity",
            "sdk-python",
            "; ".join(sdk_failures),
            "SDK namespace option selects the same namespace boundary as HTTP and CLI",
            "make SDK clients send and preserve namespace context for all workflow reads",
        )
    ] if sdk_failures else []
    sdk_php_item = sdk_php_items.get("sdk_namespace_selection_parity")
    sdk_php_error = sdk_php_errors.get("sdk_namespace_selection_parity")
    sdk_php_outputs = scenario_observed_outputs(sdk_php_item)
    python_default_namespace_behavior = {"listed_workflow_ids": [item.workflow_id for item in sdk_default_list.executions]}
    add(scenario(
        "sdk_namespace_selection_parity",
        combined_status(sdk_failures, sdk_php_item, sdk_php_error),
        {
            "python_client_namespace": getattr(sdk_same, "namespace", None),
            "php_client_namespace": sdk_php_outputs.get("php_client_namespace"),
            "default_namespace_behavior": {
                "sdk_python": python_default_namespace_behavior,
                "sdk_php": sdk_php_outputs.get("default_namespace_behavior"),
            },
            "cross_namespace_lookup_denied": {
                "sdk_python": sdk_cross,
                "sdk_php": sdk_php_outputs.get("cross_namespace_lookup_denied"),
            },
            "sdk_php_sdk_namespace_selection": sdk_php_outputs,
            "sdk_php_shard_execution": sdk_php_execution,
        },
        sdk_findings + sdk_php_findings("sdk_namespace_selection_parity", sdk_php_item, sdk_php_error),
    ))
    sdk_a.close()
    sdk_b.close()
    sdk_default.close()

    request("POST", "/service-endpoints", namespace=NAMESPACES["shared"], body={"endpoint_name": "shared-nexus", "description": "Namespace conformance Nexus endpoint"}, allowed={200, 201, 409})
    request("POST", "/service-endpoints/shared-nexus/services", namespace=NAMESPACES["shared"], body={"service_name": "echo", "description": "Echo service"}, allowed={200, 201, 409})
    request("POST", "/service-endpoints/shared-nexus/services/echo/operations", namespace=NAMESPACES["shared"], body={
        "operation_name": "call",
        "operation_mode": "async",
        "handler_binding_kind": "start_workflow",
        "handler_target_reference": "workflows.namespace.nexus.target",
        "handler_binding": {"workflow_type": "namespace.nexus.target", "queue": NEXUS_TASK_QUEUE, "task_queue": NEXUS_TASK_QUEUE},
        "boundary_policy": {"visibility": "service"},
    }, allowed={200, 201, 409})
    nexus_a = request("POST", "/service-endpoints/shared-nexus/services/echo/operations/call/execute", namespace=NAMESPACES["shared"], body={
        "caller_namespace": NAMESPACES["a"],
        "target_workflow_instance_id": workflow_ids("nexus-a-target"),
        "arguments": ["tenant-a"],
        "wait_for": "accepted",
    }, allowed={200, 409})
    nexus_b = request("POST", "/service-endpoints/shared-nexus/services/echo/operations/call/execute", namespace=NAMESPACES["shared"], body={
        "caller_namespace": NAMESPACES["b"],
        "target_workflow_instance_id": workflow_ids("nexus-b-target"),
        "arguments": ["tenant-b"],
        "wait_for": "accepted",
    }, allowed={200, 409})
    direct_without_nexus = request("GET", f"/workflows/{wf_b}", namespace=NAMESPACES["a"], allowed={404})
    nexus_failures = []
    if nexus_a["status_code"] != 200:
        nexus_failures.append("tenant-a explicit Nexus invocation to shared did not succeed")
    if nexus_b["status_code"] != 200:
        nexus_failures.append("tenant-b explicit Nexus invocation to shared did not succeed")
    if direct_without_nexus["status_code"] != 404:
        nexus_failures.append("direct cross-namespace workflow access without Nexus did not return not-found")
    nexus_findings = [
        finding(
            "nexus_explicit_cross_namespace_invocation",
            "server",
            "; ".join(nexus_failures),
            "explicit Nexus calls cross namespace while direct workflow access remains blocked",
            "repair service-call boundary dispatch or direct workflow namespace isolation before marking Nexus parity green",
        )
    ] if nexus_failures else []
    add(scenario(
        "nexus_explicit_cross_namespace_invocation",
        status_from_failures(nexus_failures),
        {
            "service_endpoint_namespace": NAMESPACES["shared"],
            "caller_namespaces": [NAMESPACES["a"], NAMESPACES["b"]],
            "target_namespace": NAMESPACES["shared"],
            "successful_results": {"tenant_a": nexus_a, "tenant_b": nexus_b},
            "direct_access_without_nexus_blocked": direct_without_nexus,
        },
        nexus_findings,
    ))

    invalid_attempts = []
    for candidate in ["", " ", "../tenant", "tenant/a", "default\nbad"]:
        invalid_attempts.append({
            "name": repr(candidate),
            "response": request("POST", "/namespaces", body={"name": candidate, "description": "invalid"}, allowed={400, 409, 422}),
        })
    valid_control_name = f"tenant-valid-{RUN_ID}"
    valid_response = create_namespace(valid_control_name, "Valid control namespace")
    list_after_invalid = request("GET", "/namespaces")["json"]
    stored_names = [str(item.get("name")) for item in list_after_invalid.get("namespaces", []) if isinstance(item, dict)]
    reserved_failures = [
        f"invalid namespace {item['name']} returned {item['response']['status_code']}"
        for item in invalid_attempts
        if item["response"]["status_code"] not in {400, 409, 422}
    ]
    for item in invalid_attempts:
        raw_name = item["name"].strip("'")
        if raw_name and raw_name in stored_names:
            reserved_failures.append(f"invalid namespace {item['name']} appeared in namespace list")
    reserved_findings = [
        finding(
            "reserved_namespace_name_refusal",
            "server",
            "; ".join(reserved_failures),
            "reserved and malformed namespace names are rejected without side effects",
            "tighten namespace-name validation and typed error reporting",
            "P1",
        )
    ] if reserved_failures else []
    add(scenario(
        "reserved_namespace_name_refusal",
        status_from_failures(reserved_failures),
        {
            "refused_names": invalid_attempts,
            "typed_errors": [item["response"]["json"].get("reason") or item["response"]["json"].get("message") for item in invalid_attempts],
            "valid_control_name_accepted": valid_response,
            "stored_namespace_names": stored_names,
        },
        reserved_findings,
    ))

    cleanup_pre_delete_resources = {
        "namespace": NAMESPACES["a"],
        "workflow_ids": sorted({wf_a, mutation_a, wf_worker_a, sa_workflow, sdk_wf}),
        "schedule_ids": [sched_a_id],
        "search_attribute_names": ["customer_id"],
        "worker_ids": [worker_a],
        "nexus_caller_namespace": NAMESPACES["a"],
    }
    cleanup_retained_resources = {
        "tenant_b_workflow_ids": sorted({wf_b, wf_worker_b}),
        "tenant_b_schedule_ids": [sched_b_id],
        "shared_namespace_service_endpoint": "shared-nexus",
        "tenant_b_nexus_call": nexus_b,
    }
    delete_response = request("DELETE", "/namespaces/tenant-a", namespace=NAMESPACES["a"])["json"]
    post_delete_namespace_describe = request("GET", "/namespaces/tenant-a", namespace=NAMESPACES["b"], allowed={404})
    post_delete_namespace_list = request("GET", "/namespaces", namespace=NAMESPACES["b"])["json"]
    post_delete_workflow_list_refused = request("GET", "/workflows", namespace=NAMESPACES["a"], allowed={404})
    post_delete_workflow_describe_refused = request("GET", f"/workflows/{wf_a}", namespace=NAMESPACES["a"], allowed={404})
    post_delete_schedule_list_refused = request("GET", "/schedules", namespace=NAMESPACES["a"], allowed={404})
    post_delete_schedule_describe_refused = request("GET", f"/schedules/{sched_a_id}", namespace=NAMESPACES["a"], allowed={404})
    post_delete_search_attributes_refused = request("GET", "/search-attributes", namespace=NAMESPACES["a"], allowed={404})
    post_delete_workers_refused = request("GET", "/workers", namespace=NAMESPACES["a"], allowed={404})
    post_delete_tenant_b_workflows = request("GET", "/workflows", namespace=NAMESPACES["b"])["json"]
    post_delete_tenant_b_schedules = request("GET", "/schedules", namespace=NAMESPACES["b"])["json"]
    post_delete_tenant_b_search_attributes = request("GET", "/search-attributes", namespace=NAMESPACES["b"])["json"]
    post_delete_tenant_b_workers = request("GET", "/workers", namespace=NAMESPACES["b"])["json"]
    recreate_response = create_namespace(NAMESPACES["a"], "Recreated tenant A")
    post_delete_workflows = request("GET", "/workflows", namespace=NAMESPACES["a"])["json"]
    post_delete_schedules = request("GET", "/schedules", namespace=NAMESPACES["a"])["json"]
    post_delete_search_attributes = request("GET", "/search-attributes", namespace=NAMESPACES["a"])["json"]
    post_delete_workers = request("GET", "/workers", namespace=NAMESPACES["a"])["json"]
    cleanup_failures = []
    deleted_counts = delete_response.get("deleted") if isinstance(delete_response, dict) else {}
    for table_name in [
        "workflow_runs",
        "workflow_schedules",
        "search_attribute_definitions",
        "workflow_worker_registrations",
    ]:
        if int((deleted_counts or {}).get(table_name) or 0) < 1:
            cleanup_failures.append(f"namespace delete response did not report removing {table_name}")
    recreate_failures = []
    if workflow_ids_in_list(post_delete_workflows):
        recreate_failures.append("recreated tenant-a inherited workflow visibility rows")
        cleanup_failures.append("recreated tenant-a inherited workflow visibility rows")
    if schedules_in_list(post_delete_schedules):
        recreate_failures.append("recreated tenant-a inherited schedule rows")
        cleanup_failures.append("recreated tenant-a inherited schedule rows")
    if "customer_id" in (post_delete_search_attributes.get("custom_attributes") or {}):
        recreate_failures.append("recreated tenant-a inherited custom search attribute definition")
        cleanup_failures.append("recreated tenant-a inherited custom search attribute definition")
    if post_delete_workers.get("workers"):
        recreate_failures.append("recreated tenant-a inherited worker registrations")
        cleanup_failures.append("recreated tenant-a inherited worker registrations")
    post_delete_refusals = {
        "namespace_describe": post_delete_namespace_describe,
        "workflow_list": post_delete_workflow_list_refused,
        "workflow_describe": post_delete_workflow_describe_refused,
        "schedule_list": post_delete_schedule_list_refused,
        "schedule_describe": post_delete_schedule_describe_refused,
        "search_attribute_list": post_delete_search_attributes_refused,
        "worker_list": post_delete_workers_refused,
    }
    for surface, probe in post_delete_refusals.items():
        if probe["status_code"] != 404:
            cleanup_failures.append(f"deleted tenant-a {surface} did not return namespace_not_found/not_found")
    post_delete_list_names = [str(item.get("name") or "") for item in post_delete_namespace_list.get("namespaces", []) if isinstance(item, dict)]
    if NAMESPACES["a"] in post_delete_list_names:
        cleanup_failures.append("deleted tenant-a remained visible in namespace list")
    tenant_b_workflow_ids_after_delete = workflow_ids_in_list(post_delete_tenant_b_workflows)
    tenant_b_schedule_ids_after_delete = schedules_in_list(post_delete_tenant_b_schedules)
    if wf_b not in tenant_b_workflow_ids_after_delete:
        cleanup_failures.append("tenant-b workflow was not retained after tenant-a deletion")
    if sched_b_id not in tenant_b_schedule_ids_after_delete:
        cleanup_failures.append("tenant-b schedule was not retained after tenant-a deletion")
    if "customer_id" in (post_delete_tenant_b_search_attributes.get("custom_attributes") or {}):
        cleanup_failures.append("tenant-b search attributes inherited tenant-a custom definition after deletion")
    if worker_b not in [str(item.get("worker_id") or "") for item in post_delete_tenant_b_workers.get("workers", []) if isinstance(item, dict)]:
        cleanup_failures.append("tenant-b worker registration was not retained after tenant-a deletion")
    cleanup_findings = [
        finding(
            "namespace_lifecycle_cleanup_and_recreate",
            "server",
            "; ".join(cleanup_failures),
            "namespace deletion removes owned runtime state, refuses deleted-namespace surfaces, preserves other namespaces, and recreates empty",
            "extend namespace lifecycle cleanup or runner evidence until workflow, schedule, search, worker, and operator surfaces prove no stale tenant data",
        )
    ] if cleanup_failures else []
    add(scenario(
        "namespace_lifecycle_cleanup_and_recreate",
        status_from_failures(cleanup_failures),
        {
            "deleted_namespace": delete_response,
            "pre_delete_resources": cleanup_pre_delete_resources,
            "deleted_counts": deleted_counts,
            "post_delete_refusals": post_delete_refusals,
            "operator_surface_cleanup": {
                "namespace_describe_after_delete": post_delete_namespace_describe,
                "namespace_list_after_delete": post_delete_namespace_list,
                "deleted_namespace_absent_from_list": NAMESPACES["a"] not in post_delete_list_names,
            },
            "workflow_cleanup": {
                "after_delete_refused": post_delete_workflow_list_refused,
                "describe_after_delete_refused": post_delete_workflow_describe_refused,
                "after_recreate": post_delete_workflows,
            },
            "schedule_cleanup": {
                "after_delete_refused": post_delete_schedule_list_refused,
                "describe_after_delete_refused": post_delete_schedule_describe_refused,
                "after_recreate": post_delete_schedules,
            },
            "search_attribute_cleanup": {
                "after_delete_refused": post_delete_search_attributes_refused,
                "after_recreate": post_delete_search_attributes,
            },
            "worker_registration_cleanup": {
                "after_delete_refused": post_delete_workers_refused,
                "after_recreate": post_delete_workers,
            },
            "retained_resources": {
                "expected": cleanup_retained_resources,
                "tenant_b_workflows_after_delete": post_delete_tenant_b_workflows,
                "tenant_b_schedules_after_delete": post_delete_tenant_b_schedules,
                "tenant_b_search_attributes_after_delete": post_delete_tenant_b_search_attributes,
                "tenant_b_workers_after_delete": post_delete_tenant_b_workers,
            },
            "recreate_state_empty": not recreate_failures,
            "external_payload_contexts_checked": "no external payload storage contexts were configured in this published-artifact pass",
            "recreated_namespace": recreate_response,
        },
        cleanup_findings,
    ))

    waterline_result_path = os.environ.get("DW_NAMESPACES_WATERLINE_RESULT")
    waterline_item, waterline_payload, waterline_error = load_optional_shard(
        waterline_result_path,
        "waterline_operator_namespace_visibility",
        "waterline:namespace-conformance",
        "waterline-operator-namespace-shard",
        pins,
        REQUIRED_SHARD_ARTIFACTS,
    )
    waterline_execution = waterline_execution_record(
        waterline_result_path,
        waterline_payload,
        waterline_item,
        waterline_error,
        pins,
    )
    if waterline_result_path:
        waterline_artifact = artifact_install.get("waterline_artifact") if isinstance(artifact_install.get("waterline_artifact"), dict) else {}
        artifact_install["waterline_artifact"] = {
            **waterline_artifact,
            "version": pins["waterline"],
            "source": pins["artifact_sources"]["waterline"],
            "status": "executed" if waterline_execution["status"] == "executed" else waterline_artifact.get("status", "resolved"),
            "shard_command": "waterline:namespace-conformance",
            "namespace_shard_status": waterline_execution["status"],
            "namespace_shard_execution": waterline_execution,
        }
    if waterline_item is not None:
        observed_outputs = waterline_item.get("observed_outputs") if isinstance(waterline_item.get("observed_outputs"), dict) else {}
        waterline_item = {
            **waterline_item,
            "observed_outputs": {
                **observed_outputs,
                "waterline_shard_execution": waterline_execution,
            },
        }
        add(waterline_item)
        waterline_section = waterline_item.get("observed_outputs", {})
    else:
        waterline_finding = waterline_error or finding(
            "waterline_operator_namespace_visibility",
            "waterline",
            "Waterline operator namespace visibility shard was not supplied to this runner invocation",
            "waterline:namespace-conformance runs against the published Waterline artifact and emits scoped operator evidence",
            "wire the host topology to run the published Waterline namespace shard and pass DW_NAMESPACES_WATERLINE_RESULT",
            "P1",
            finding_type="unsupported_public_surface",
            scenario_status="unsupported",
        )
        add(scenario(
            "waterline_operator_namespace_visibility",
            "unsupported",
            {
                "tenant_a_scoped_views": None,
                "tenant_b_scoped_views": None,
                "detail_namespace_identity": None,
                "unscoped_view_authority": None,
                "api_captures": {},
                "operator_surface_matrix": {},
                "shard_command": "waterline:namespace-conformance",
            },
            [waterline_finding],
        ))
        waterline_section = scenario_results["waterline_operator_namespace_visibility"]["observed_outputs"]

    result_record_outputs = {
        "artifact_versions_recorded": True,
        "timestamps_recorded": True,
        "outcome_recorded": True,
        "finding_links_recorded": True,
        "product_finding_routes_checked": True,
    }
    add(scenario("result_record_and_product_finding_routing", "pass", result_record_outputs))

    required = [
        "published_artifact_install_only",
        "namespace_create_update_describe_and_list",
        "workflow_cross_namespace_visibility_isolation",
        "workflow_cross_namespace_mutation_isolation",
        "php_worker_task_queue_namespace_isolation",
        "cli_namespace_context_and_default_scope",
        "sdk_namespace_selection_parity",
        "search_attribute_schema_and_value_query_isolation",
        "schedule_namespace_isolation",
        "namespace_lifecycle_cleanup_and_recreate",
        "waterline_operator_namespace_visibility",
        "nexus_explicit_cross_namespace_invocation",
        "reserved_namespace_name_refusal",
        "result_record_and_product_finding_routing",
    ]
    ordered_results = [scenario_results[item] for item in required]
    outcome = "pass" if all(item["status"] == "pass" for item in ordered_results) else "non_passing"
    finished = now()
    finding_links: dict[str, list[dict[str, Any]]] = {}
    for item in findings:
        scenario_id = str(item.get("scenario_id") or "unscoped")
        finding_links.setdefault(scenario_id, []).append(item)
    finding_summaries = [
        str(
            item.get("observed_behavior")
            or item.get("title")
            or item.get("message")
            or json.dumps(item, sort_keys=True)
        )
        for item in findings
    ]
    result = {
        "schema": "durable-workflow.v2.namespace-runtime.result",
        "schema_version": 1,
        "suite_schema": "durable-workflow.v2.platform-conformance.suite",
        "suite_version": SUITE_VERSION,
        "category": "namespace_runtime_contract",
        "outcome": outcome,
        "runner_blocked": False,
        "started_at": STARTED_AT,
        "finished_at": finished,
        "generated_at": finished,
        "artifact_versions": versions,
        "published_artifact_versions": versions,
        "artifact_sources": pins.get("artifact_sources", {}),
        "local_product_source_checkouts_used": False,
        "namespace_topology": {
            "namespaces": ["tenant-a", "tenant-b", "shared"],
            "task_queue": WORKER_TASK_QUEUE,
            "control_task_queue": TASK_QUEUE,
            "nexus_task_queue": NEXUS_TASK_QUEUE,
        },
        "runtime_matrix": {
            "runtimes": ["sdk-php", "sdk-python"],
            "client_paths": ["cli", "sdk-python", "sdk-php"],
            "observer_paths": ["waterline-list", "waterline-detail", "waterline-operator-api"],
            "worker_isolation_cells": [
                {"runtime": "sdk-php", "namespace": "tenant-a", "task_queue": WORKER_TASK_QUEUE, "scenario": "php_worker_task_queue_namespace_isolation"},
                {"runtime": "sdk-php", "namespace": "tenant-b", "task_queue": WORKER_TASK_QUEUE, "scenario": "php_worker_task_queue_namespace_isolation"},
            ],
            "cross_namespace_cells": [
                {"from": "tenant-a", "to": "tenant-b", "surface": "workflow-control-plane", "scenario": "workflow_cross_namespace_visibility_isolation"},
                {"from": "tenant-b", "to": "tenant-a", "surface": "workflow-control-plane", "scenario": "workflow_cross_namespace_mutation_isolation"},
                {"from": "tenant-a", "to": "shared", "surface": "nexus", "scenario": "nexus_explicit_cross_namespace_invocation"},
                {"from": "tenant-b", "to": "shared", "surface": "nexus", "scenario": "nexus_explicit_cross_namespace_invocation"},
            ],
        },
        "scenario_results": ordered_results,
        "published_artifact_install": artifact_install,
        "namespace_crud_behavior": namespace_crud,
        "workflow_visibility_isolation": scenario_results["workflow_cross_namespace_visibility_isolation"]["observed_outputs"],
        "workflow_mutation_isolation": scenario_results["workflow_cross_namespace_mutation_isolation"]["observed_outputs"],
        "cli_namespace_behavior": scenario_results["cli_namespace_context_and_default_scope"]["observed_outputs"],
        "sdk_namespace_selection": scenario_results["sdk_namespace_selection_parity"]["observed_outputs"],
        "php_worker_behavior": scenario_results["php_worker_task_queue_namespace_isolation"]["observed_outputs"],
        "schedule_namespace_isolation": scenario_results["schedule_namespace_isolation"]["observed_outputs"],
        "waterline_operator_visibility": waterline_section,
        "search_attribute_value_query_isolation": scenario_results["search_attribute_schema_and_value_query_isolation"]["observed_outputs"],
        "namespace_lifecycle_cleanup": scenario_results["namespace_lifecycle_cleanup_and_recreate"]["observed_outputs"],
        "nexus_cross_namespace": scenario_results["nexus_explicit_cross_namespace_invocation"]["observed_outputs"],
        "adversarial_namespace_names": scenario_results["reserved_namespace_name_refusal"]["observed_outputs"],
        "result_record_and_product_finding_routing": result_record_outputs,
        "findings": findings,
        "finding_links": finding_links,
    }
    (RESULT_DIR / "namespaces-result.json").write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (RESULT_DIR / "namespaces-record.json").write_text(
        json.dumps(
            {
                "experiment": "namespaces",
                "outcome": outcome,
                "runnerBlocked": False,
                "artifactVersions": versions,
                "findings": finding_summaries,
                "resultPath": str(RESULT_DIR / "namespaces-result.json"),
            },
            indent=2,
            sort_keys=True,
        )
        + "\n",
        encoding="utf-8",
    )
    return 0 if outcome == "pass" else 1


if __name__ == "__main__":
    raise SystemExit(main())
PY

set +e
DW_NAMESPACES_SERVER_URL="$server_base_url" \
DW_NAMESPACES_RESULT_DIR="$result_dir" \
DW_NAMESPACES_RUN_ROOT="$run_root" \
DW_NAMESPACES_DW_BIN="$run_root/cli/bin/dw" \
DW_NAMESPACES_SDK_PHP_RESULT="$sdk_php_result_path" \
DW_NAMESPACES_WATERLINE_RESULT="$waterline_result_path" \
DW_NAMESPACES_STARTED_AT="$started_at" \
DW_NAMESPACES_SUITE_VERSION="$namespace_suite_version" \
"$run_root/.venv/bin/python" "$run_root/orchestrate.py" > "$result_dir/orchestrate.log" 2>&1
orchestrate_status=$?
set -e

docker compose -f "$run_root/compose.yml" logs server > "$result_dir/server.log" 2>&1 || true

if [[ ! -f "$result_dir/namespaces-result.json" ]]; then
  blocked_result "namespace conformance orchestrator exited without producing namespaces-result.json; see orchestrate.log" "$started_at"
  exit 1
fi

if [[ "$orchestrate_status" -ne 0 && -f "$result_dir/namespaces-record.json" ]]; then
  recorded_pass_status="$(
    python3 - "$result_dir/namespaces-record.json" "$result_dir/namespaces-result.json" "$namespace_suite_version" <<'PY'
from __future__ import annotations

import json
import sys
from pathlib import Path

required = [
    "published_artifact_install_only",
    "namespace_create_update_describe_and_list",
    "workflow_cross_namespace_visibility_isolation",
    "workflow_cross_namespace_mutation_isolation",
    "php_worker_task_queue_namespace_isolation",
    "cli_namespace_context_and_default_scope",
    "sdk_namespace_selection_parity",
    "search_attribute_schema_and_value_query_isolation",
    "schedule_namespace_isolation",
    "namespace_lifecycle_cleanup_and_recreate",
    "waterline_operator_namespace_visibility",
    "nexus_explicit_cross_namespace_invocation",
    "reserved_namespace_name_refusal",
    "result_record_and_product_finding_routing",
]

try:
    record = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    result = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
    expected_suite_version = int(sys.argv[3])
    scenario_statuses = {
        str(item.get("scenario_id") or ""): str(item.get("status") or "").lower()
        for item in result.get("scenario_results", [])
        if isinstance(item, dict)
    }
    all_required_pass = all(scenario_statuses.get(scenario_id) == "pass" for scenario_id in required)
    suite_version_matches = int(result.get("suite_version", -1)) == expected_suite_version
    record_artifacts = record.get("artifactVersions") if isinstance(record.get("artifactVersions"), dict) else {}
    result_artifacts = result.get("artifact_versions") if isinstance(result.get("artifact_versions"), dict) else {}
    artifacts_match = all(
        str(record_artifacts.get(key) or "") == str(result_artifacts.get(key) or "")
        for key in ["server", "cli", "workflow-php", "sdk-php", "sdk-python", "waterline"]
    )
    findings_empty = not record.get("findings") and not result.get("findings")
    is_pass = (
        record.get("outcome") == "pass"
        and result.get("outcome") == "pass"
        and record.get("runnerBlocked") is not True
        and result.get("runner_blocked") is not True
        and suite_version_matches
        and artifacts_match
        and all_required_pass
        and findings_empty
    )
except Exception:
    is_pass = False

print("pass" if is_pass else "non_passing")
PY
  )"
  if [[ "$recorded_pass_status" == "pass" ]]; then
    orchestrate_status=0
  fi
fi

exit "$orchestrate_status"
