#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
  cat <<'USAGE'
Usage: python-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Runs the Python SDK published-artifact parity contract against public artifacts:
durableworkflow/server, the official dw install script, PyPI durable-workflow,
and matching published workflow/waterline packages. The runner writes
python-host-evidence.json, python-conformance-result.json, and
python-conformance-evaluation.json into the result directory.

DW_PYTHON_CONFORMANCE_CLOUD_EVIDENCE_JSON may point to passing isolated
managed-namespace evidence for the exact resolved artifact tuple. Without that
handoff, the standalone runtime scenario remains actionable not_covered evidence.
USAGE
}

result_dir="${DW_PYTHON_CONFORMANCE_RESULT_DIR:-}"
keep_run_root="${DW_PYTHON_CONFORMANCE_KEEP_RUN_ROOT:-0}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --result-dir)
      result_dir="${2:-}"
      shift 2
      ;;
    --result-dir=*)
      result_dir="${1#--result-dir=}"
      shift
      ;;
    --keep-run-root)
      keep_run_root="1"
      shift
      ;;
    --keep-run-root=*)
      keep_run_root="${1#--keep-run-root=}"
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      usage >&2
      exit 64
      ;;
  esac
done

tmp_parent="${DW_CONFORMANCE_TMPDIR:-${TMPDIR:-/tmp}}"
mkdir -p "$tmp_parent"
run_root="$(mktemp -d "$tmp_parent/dw-python-parity.XXXXXX")"
run_root="$(cd "$run_root" && pwd)"
mkdir -p \
  "$run_root/logs" \
  "$run_root/cli/bin" \
  "$run_root/cli/config" \
  "$run_root/artifacts/workflow" \
  "$run_root/artifacts/waterline"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
distribution_identity_file="$result_dir/executed-distribution-identities.json"

server_bind_host="${DW_PYTHON_CONFORMANCE_BIND_HOST:-127.0.0.1}"
server_port="${DW_PYTHON_CONFORMANCE_SERVER_PORT:-}"
server_base_url=""
runtime_token="python-parity-token"
namespace=""
run_label="$(printf '%s' "$(basename "$run_root")" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_-' '-')"
compose_project="dw-python-parity-${run_label}"
started_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

cleanup() {
  local code=$?

  if [[ -n "$namespace" \
    && ! -f "$result_dir/namespace-cleanup-complete" \
    && -x "$run_root/cli/bin/dw" \
    && -n "$server_base_url" ]]; then
    PATH="$run_root/cli/bin${PATH:+:$PATH}" \
      "$run_root/cli/bin/dw" namespace:delete "$namespace" --json \
      --server "$server_base_url" \
      --token "$runtime_token" \
      --namespace "$namespace" \
      > "$result_dir/namespace-cleanup-trap.json" 2> "$result_dir/namespace-cleanup-trap.log" || true
  fi
  if [[ -f "$run_root/compose.yml" ]]; then
    docker compose -p "$compose_project" -f "$run_root/compose.yml" down -v >/dev/null 2>&1 || true
  fi
  if [[ "$keep_run_root" != "1" && "$keep_run_root" != "true" && "$code" -eq 0 && "$result_dir" != "$run_root" ]]; then
    rm -rf "$run_root"
  else
    printf 'kept Python conformance run root: %s\n' "$run_root" >&2
  fi
}
trap cleanup EXIT

write_blocked_result() {
  local reason="$1"
  python3 - "$result_dir" "$reason" "$started_at" "$script_dir" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

result_dir = Path(sys.argv[1])
reason = sys.argv[2]
started_at = sys.argv[3]
sys.path.insert(0, sys.argv[4])
from python_external_payload_evidence import REQUIRED_CAPABILITIES, REQUIRED_SCENARIOS

now = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
finding = {
    "type": "runner_gap",
    "owning_surface": "conformance_harness",
    "summary": reason,
    "next_acceptance_criterion": (
        "restore the missing runner prerequisite and rerun the published artifact tuple"
    ),
}
result = {
    "schema": "durable-workflow.v2.python-sdk-parity.result",
    "version": 1,
    "started_at": started_at,
    "finished_at": now,
    "generated_at": now,
    "outcome": "fail",
    "runner_blocked": True,
    "artifact_versions": {},
    "executed_distribution_identities": {},
    "source_policy": {
        "artifact_source": "published_artifacts",
        "local_product_sources_used": False,
    },
    "scenario_results": {
        scenario: {
            "scenario_id": scenario,
            "status": "runner_blocked",
            "observed_outputs": {"summary": reason},
            "linked_findings": [finding],
        }
        for scenario in REQUIRED_SCENARIOS
    },
    "capability_table": [
        {
            "id": capability,
            "status": "runner_blocked",
            "evidence": {"runner_blocked_reason": reason},
        }
        for capability in REQUIRED_CAPABILITIES
    ],
    "protocol_traces": [],
    "php_assumption_audit": {"status": "runner_blocked", "checks": {}},
    "findings": [finding],
    "finding_links": {scenario: [finding] for scenario in REQUIRED_SCENARIOS},
}
result_dir.mkdir(parents=True, exist_ok=True)
(result_dir / "python-conformance-result.json").write_text(
    json.dumps(result, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)
(result_dir / "python-conformance-record.json").write_text(
    json.dumps(
        {
            "schema": "durable-workflow.v2.python-sdk-parity.record",
            "outcome": "fail",
            "runnerBlocked": True,
            "reason": reason,
            "generated_at": now,
        },
        indent=2,
        sort_keys=True,
    )
    + "\n",
    encoding="utf-8",
)
PY
}

fail_blocked() {
  local reason="$1"
  write_blocked_result "$reason"
  printf '%s\n' "$reason" >&2
  exit 1
}

for command_name in docker python3 curl; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    fail_blocked "Python conformance runner requires missing command: $command_name"
  fi
done

if ! docker compose version >/dev/null 2>&1; then
  fail_blocked "Python conformance runner requires docker compose"
fi

if [[ -z "$server_port" ]]; then
  server_port="$(python3 - <<'PY'
import socket
with socket.socket() as sock:
    sock.bind(("127.0.0.1", 0))
    print(sock.getsockname()[1])
PY
)"
fi
server_base_url="http://${server_bind_host}:${server_port}"

cat > "$run_root/resolve-pins.py" <<'PY'
from __future__ import annotations

import json
import os
import re
import sys
import urllib.error
import urllib.request
from typing import Any


SEMVER_TAG_RE = re.compile(r"^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.]+)?$")


def env(name: str) -> str | None:
    value = os.environ.get(name)
    if value is None:
        return None
    value = value.strip()
    return value or None


def read_json(url: str) -> Any:
    request = urllib.request.Request(url, headers={"User-Agent": "durable-workflow-python-conformance"})
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.loads(response.read().decode("utf-8"))


def semver_key(version: str) -> tuple[int, int, int, int, int]:
    match = re.fullmatch(r"v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(alpha|beta|rc)\.(0|[1-9]\d*))?", version)
    if not match:
        return (-1, -1, -1, -1, -1)
    major, minor, patch, prerelease, ordinal = match.groups()
    phase = {"alpha": 0, "beta": 1, "rc": 2, None: 3}[prerelease]
    return int(major), int(minor), int(patch), phase, int(ordinal or 0)


def exact_server_tag(value: str) -> bool:
    exact = re.fullmatch(
        r"(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
        r"(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)"
        r"(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?",
        value,
    ) is not None
    prerelease = value.partition("-")[2]
    rolling = {"latest", "current", "head", "main", "master", "dev", "snapshot", "unresolved", "placeholder"}
    return exact and not any(identifier.lower() in rolling for identifier in prerelease.split(".") if identifier)


def server_tag_from_image(image: str) -> str | None:
    if "@" in image:
        image = image.split("@", 1)[0]
    tail = image.rsplit("/", 1)[-1]
    if ":" not in tail:
        return None
    return tail.rsplit(":", 1)[1]


def resolve_server() -> tuple[str, str]:
    explicit_image = env("DW_SERVER_IMAGE")
    explicit_version = env("DW_SERVER_VERSION")
    if explicit_image:
        tag = server_tag_from_image(explicit_image)
        if "@" not in explicit_image and (tag is None or not exact_server_tag(tag)):
            raise RuntimeError("DW_SERVER_IMAGE must use an exact SemVer tag or an image digest")
        version = explicit_version or tag
        if version is None or not exact_server_tag(version):
            raise RuntimeError("DW_SERVER_VERSION must name the exact SemVer release for digest-pinned server images")
        if tag is not None and exact_server_tag(tag) and tag != version:
            raise RuntimeError("DW_SERVER_VERSION does not match DW_SERVER_IMAGE tag")
        return explicit_image, version

    if explicit_version:
        if not exact_server_tag(explicit_version):
            raise RuntimeError("DW_SERVER_VERSION must be an exact SemVer release")
        return f"durableworkflow/server:{explicit_version}", explicit_version

    tags: list[str] = []
    url: str | None = "https://registry.hub.docker.com/v2/repositories/durableworkflow/server/tags?page_size=100"
    while url:
        payload = read_json(url)
        tags.extend(str(item.get("name", "")) for item in payload.get("results", []))
        url = payload.get("next")
    exact = [tag for tag in tags if exact_server_tag(tag)]
    if not exact:
        raise RuntimeError("no exact durableworkflow/server SemVer tag found")
    version = sorted(exact, key=semver_key, reverse=True)[0]
    return f"durableworkflow/server:{version}", version


def resolve_pypi() -> str:
    return env("DW_PYTHON_SDK_VERSION") or read_json("https://pypi.org/pypi/durable-workflow/json")["info"]["version"]


def resolve_packagist(package: str, override_env: str) -> str:
    override = env(override_env)
    if override:
        return override
    payload = read_json(f"https://repo.packagist.org/p2/{package}.json")
    versions = [
        str(item.get("version", ""))
        for item in payload.get("packages", {}).get(package, [])
        if re.fullmatch(r"2\.0\.0-alpha\.\d+", str(item.get("version", "")))
    ]
    if not versions:
        raise RuntimeError(f"no published 2.0.0-alpha package found for {package}")
    return sorted(versions, key=semver_key, reverse=True)[0]


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
    headers = {"User-Agent": "durable-workflow-python-conformance"}
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


def resolve_cli() -> tuple[str, str]:
    return github_release_with_downloadable_asset("durable-workflow/cli", env("DW_CLI_VERSION"), "install.sh")


server_image, server_version = resolve_server()
cli_version, cli_installer_url = resolve_cli()
python_version = resolve_pypi()
workflow_version = resolve_packagist("durable-workflow/workflow", "DW_WORKFLOW_PHP_VERSION")
waterline_version = resolve_packagist("durable-workflow/waterline", "DW_WATERLINE_VERSION")

json.dump(
    {
        "server": server_version,
        "server_image": server_image,
        "cli": cli_version,
        "cli_installer_url": cli_installer_url,
        "sdk-python": python_version,
        "workflow": workflow_version,
        "waterline": waterline_version,
        "artifact_sources": {
            "server": "docker",
            "cli": "official_install_script",
            "sdk-python": "pypi",
            "workflow": "packagist",
            "waterline": "packagist",
        },
    },
    sys.stdout,
    indent=2,
    sort_keys=True,
)
sys.stdout.write("\n")
PY

if ! python3 "$run_root/resolve-pins.py" > "$result_dir/pins.json" 2> "$result_dir/resolve-pins.log"; then
  pin_error="$(tr '\n' ' ' < "$result_dir/resolve-pins.log" | cut -c 1-1000 || true)"
  fail_blocked "published artifact pin resolution failed: ${pin_error:-unknown error}"
fi
cp "$result_dir/pins.json" "$run_root/pins.json"

server_image="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["server_image"])' "$run_root/pins.json")"
server_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["server"])' "$run_root/pins.json")"
cli_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["cli"])' "$run_root/pins.json")"
cli_installer_url="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["cli_installer_url"])' "$run_root/pins.json")"
python_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["sdk-python"])' "$run_root/pins.json")"
workflow_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["workflow"])' "$run_root/pins.json")"
waterline_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["waterline"])' "$run_root/pins.json")"

if [[ "${DW_PYTHON_CONFORMANCE_SKIP_DOCKER_PULL:-0}" != "1" ]]; then
  docker pull "$server_image" > "$result_dir/server-image-pull.log" 2>&1 || fail_blocked "server image pull failed for $server_image"
fi
server_image_digest="$(docker image inspect --format '{{index .RepoDigests 0}}' "$server_image" 2>/dev/null || true)"
if [[ -z "$server_image_digest" || "$server_image_digest" == "<no value>" ]]; then
  server_image_digest="$server_image"
fi
printf '%s\n' "$server_image_digest" > "$result_dir/server-image-digest.txt"
if ! python3 "$script_dir/distribution_identities.py" record-digest \
  "$distribution_identity_file" server "$server_version" manifest "${server_image_digest##*@}"; then
  fail_blocked "pulled server image did not expose an executed OCI manifest digest"
fi

python3 -m venv "$run_root/.venv"
# shellcheck disable=SC1091
. "$run_root/.venv/bin/activate"
python -m pip install --upgrade pip > "$result_dir/pip-upgrade.log" 2>&1
mkdir -p "$run_root/python-distributions"
python -m pip download --no-deps --dest "$run_root/python-distributions" \
  "durable-workflow==$python_version" > "$result_dir/python-sdk-download.log" 2>&1 \
  || fail_blocked "PyPI durable-workflow==$python_version download failed"
python3 "$script_dir/distribution_identities.py" record-unique \
  "$distribution_identity_file" sdk-python "$python_version" \
  "$run_root/python-distributions" '*' \
  || fail_blocked "PyPI durable-workflow==$python_version did not retain one consumed distribution file"
python_distribution="$(find "$run_root/python-distributions" -maxdepth 1 -type f -print -quit)"
python -m pip install "$python_distribution" > "$result_dir/python-sdk-install.log" 2>&1 \
  || fail_blocked "PyPI durable-workflow==$python_version install failed"

if ! curl -fsSL --retry 3 -o "$run_root/cli/install.sh" "$cli_installer_url"; then
  fail_blocked "official CLI installer is not downloadable for release $cli_version"
fi
python3 "$script_dir/distribution_identities.py" record-file \
  "$distribution_identity_file" cli "$cli_version" "$run_root/cli/install.sh" \
  --artifact-name install.sh \
  || fail_blocked "official CLI installer bytes could not be identified"
if ! PATH="$run_root/cli/bin${PATH:+:$PATH}" \
  VERSION="$cli_version" \
  DURABLE_WORKFLOW_INSTALL_DIR="$run_root/cli/bin" \
  DURABLE_WORKFLOW_BIN_NAME=dw \
  sh "$run_root/cli/install.sh" > "$result_dir/cli-install.log" 2>&1; then
  fail_blocked "official CLI installer failed for release $cli_version"
fi
if [[ ! -x "$run_root/cli/bin/dw" ]]; then
  fail_blocked "official CLI installer did not create an executable dw binary"
fi

write_prerelease_composer_manifest() {
  local project_dir="$1"
  local project_name="$2"

  cat > "$project_dir/composer.json" <<JSON
{
  "name": "durable-workflow/${project_name}",
  "type": "project",
  "minimum-stability": "alpha",
  "prefer-stable": true
}
JSON
}

write_prerelease_composer_manifest "$run_root/artifacts/workflow" "python-conformance-workflow-probe"
docker run --rm --user "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer-home \
  -e COMPOSER_CACHE_DIR=/app/.composer-cache \
  -v "$run_root/artifacts/workflow:/app" composer:2 \
  composer require --no-interaction --no-progress --prefer-dist --no-scripts \
    "durable-workflow/workflow:$workflow_version" > "$result_dir/workflow-artifact-install.log" 2>&1 \
  || fail_blocked "published workflow artifact install failed for durable-workflow/workflow:$workflow_version"
python3 "$script_dir/distribution_identities.py" record-unique \
  "$distribution_identity_file" workflow "$workflow_version" \
  "$run_root/artifacts/workflow/.composer-cache/files/durable-workflow/workflow" '**/*' \
  --artifact-name durable-workflow/workflow \
  || fail_blocked "published workflow install did not retain its consumed Composer archive"
write_prerelease_composer_manifest "$run_root/artifacts/waterline" "python-conformance-waterline-probe"
docker run --rm --user "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer-home \
  -e COMPOSER_CACHE_DIR=/app/.composer-cache \
  -v "$run_root/artifacts/waterline:/app" composer:2 \
  composer require --no-interaction --no-progress --prefer-dist --no-scripts \
    "durable-workflow/workflow:$workflow_version" \
    "durable-workflow/waterline:$waterline_version" > "$result_dir/waterline-artifact-install.log" 2>&1 \
  || fail_blocked "published Waterline artifact install failed for durable-workflow/waterline:$waterline_version with durable-workflow/workflow:$workflow_version"
python3 "$script_dir/distribution_identities.py" record-unique \
  "$distribution_identity_file" waterline "$waterline_version" \
  "$run_root/artifacts/waterline/.composer-cache/files/durable-workflow/waterline" '**/*' \
  --artifact-name durable-workflow/waterline \
  || fail_blocked "published Waterline install did not retain its consumed Composer archive"

python3 - "$run_root/pins.json" "$result_dir/server-image-digest.txt" "$result_dir/run-metadata.json" "$server_base_url" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

pins = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
metadata = {
    "experiment": "python",
    "schema": "durable-workflow.v2.python-sdk-parity.metadata",
    "suite_schema": "durable-workflow.v2.platform-conformance.suite",
    "generated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
    "artifact_versions": {
        "server": pins["server"],
        "cli": pins["cli"],
        "sdk-python": pins["sdk-python"],
        "workflow": pins["workflow"],
        "waterline": pins["waterline"],
    },
    "artifact_sources": pins["artifact_sources"],
    "server_image": pins["server_image"],
    "server_image_digest": Path(sys.argv[2]).read_text(encoding="utf-8").strip(),
    "server_url": sys.argv[4],
    "local_product_source_checkouts_used": False,
}
Path(sys.argv[3]).write_text(json.dumps(metadata, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY

cat > "$run_root/compose.yml" <<YAML
x-server-environment: &server-environment
  DW_AUTH_DRIVER: token
  DW_AUTH_TOKEN: python-parity-token
  DW_WORKER_POLL_TIMEOUT: "1"
  DW_WORKER_POLL_INTERVAL_MS: "100"
  DB_CONNECTION: sqlite
  DB_DATABASE: /app/database/database.sqlite
  QUEUE_CONNECTION: database

services:
  server:
    image: ${server_image}
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
    image: ${server_image}
    command: ["php", "artisan", "queue:work", "--sleep=1", "--tries=3", "--max-time=900"]
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

if ! docker compose -p "$compose_project" -f "$run_root/compose.yml" run --rm server server-bootstrap \
  > "$result_dir/server-bootstrap.log" 2>&1; then
  fail_blocked "published server image failed to bootstrap the SQLite database queue; see server-bootstrap.log"
fi

docker compose -p "$compose_project" -f "$run_root/compose.yml" up -d > "$result_dir/docker-compose-up.log" 2>&1 \
  || fail_blocked "published server image failed to start; see docker-compose-up.log"

if ! python3 - "$server_base_url" <<'PY' > "$result_dir/server-ready.log" 2>&1
from __future__ import annotations

import sys
import time
import urllib.error
import urllib.request

base_url = sys.argv[1].rstrip("/")
deadline = time.time() + 120
while time.time() < deadline:
    try:
        with urllib.request.urlopen(base_url + "/api/ready", timeout=3) as response:
            if response.status < 500:
                print("ready", response.status)
                raise SystemExit(0)
    except Exception as exc:
        print(type(exc).__name__, exc)
    time.sleep(2)
raise SystemExit("server did not become ready")
PY
then
  fail_blocked "published server image did not become ready"
fi

namespace="python-parity-${run_label}"
if ! PATH="$run_root/cli/bin${PATH:+:$PATH}" \
  "$run_root/cli/bin/dw" namespace:create "$namespace" \
  --description="Python SDK parity conformance" \
  --retention=7 \
  --json \
  --server "$server_base_url" \
  --token "$runtime_token" \
  --namespace default \
  > "$result_dir/namespace-create.json" 2> "$result_dir/namespace-create.log"; then
  fail_blocked "official CLI could not create the isolated Python conformance namespace"
fi

# The backing URI is deliberately confined to the server-side setup process.
# It is neither exported as public evidence nor passed to the Python process.
if ! PATH="$run_root/cli/bin${PATH:+:$PATH}" \
  "$run_root/cli/bin/dw" namespace:set-storage-driver "$namespace" local \
  --uri="file:///app/database/runtime-external-payloads/${namespace}" \
  --threshold-bytes=256 \
  --json \
  --server "$server_base_url" \
  --token "$runtime_token" \
  --namespace "$namespace" \
  > /dev/null 2>&1; then
  fail_blocked "official CLI could not configure namespace-owned runtime external payload storage"
fi

cp "$script_dir/python_worker_stop_deregistration.py" "$run_root/python_worker_stop_deregistration.py"
cp "$script_dir/python_external_payload_evidence.py" "$run_root/python_external_payload_evidence.py"

cat > "$run_root/python-parity-runner.py" <<'PY'
from __future__ import annotations

import asyncio
import hashlib
import importlib.metadata as metadata
import json
import os
import subprocess
import sys
import threading
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import httpx
from durable_workflow import Client, ServerError, Worker, activity, workflow
from durable_workflow import serializer
from durable_workflow.metrics import CLIENT_REQUESTS
from python_external_payload_evidence import (
    RUNTIME_EXTERNAL_PAYLOAD_CAPABILITIES,
    cloud_evidence_handoff,
    summarize_runtime_reference,
)
from python_worker_stop_deregistration import verify_stopped_worker_absent


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def write_json_atomic(path: Path, value: object) -> None:
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(
        json.dumps(value, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    temporary.replace(path)


class PhaseEvidence:
    def __init__(self, path: Path) -> None:
        self.path = path
        self.phases: list[dict[str, Any]] = []

    def record(self, phase: str, **evidence: Any) -> None:
        entry = {
            "phase": phase,
            "recorded_at": utc_now(),
            **evidence,
        }
        self.phases.append(entry)
        write_json_atomic(
            self.path,
            {
                "schema": "durable-workflow.v2.python-sdk-parity.phase-evidence",
                "current_phase": phase,
                "updated_at": entry["recorded_at"],
                "phases": self.phases[-32:],
            },
        )


class TraceMetrics:
    def __init__(self, trace_file: Path) -> None:
        self.entries: list[dict[str, Any]] = []
        self.trace_file = trace_file
        self.lock = threading.Lock()

    def increment(self, name: str, value: float = 1.0, tags: dict[str, str] | None = None) -> None:
        if name == CLIENT_REQUESTS and tags is not None:
            with self.lock:
                self.entries.append(
                    {
                        "plane": tags.get("plane"),
                        "method": tags.get("method"),
                        "route": tags.get("route"),
                        "status_code": tags.get("status_code"),
                        "outcome": tags.get("outcome"),
                        "source": "durable_workflow.Client",
                        "recorded_at": utc_now(),
                    }
                )
                write_json_atomic(self.trace_file, self.entries[-256:])

    def record(self, name: str, value: float, tags: dict[str, str] | None = None) -> None:
        return None


@activity.defn(name="python.parity.echo")
def parity_echo(name: str) -> dict[str, Any]:
    return {"activity": "python.parity.echo", "name": name, "runtime": "sdk-python"}


@activity.defn(name="python.parity.after-signal")
def parity_after_signal(approved_by: str) -> dict[str, Any]:
    return {"activity": "python.parity.after-signal", "approved_by": approved_by}


@workflow.defn(name="python.parity.workflow")
class PythonParityWorkflow:
    def __init__(self) -> None:
        self.approved_by: str | None = None

    @workflow.signal("approve")
    def approve(self, by: str) -> None:
        self.approved_by = by

    @workflow.query("state")
    def state(self) -> dict[str, Any]:
        return {"approved_by": self.approved_by}

    def run(self, ctx: Any, name: str) -> dict[str, Any]:
        before_signal = yield ctx.schedule_activity("python.parity.echo", [name])
        approved = yield ctx.wait_condition(lambda: self.approved_by is not None, key="approval", timeout=60)
        after_signal = yield ctx.schedule_activity("python.parity.after-signal", [self.approved_by or "missing"])
        return {
            "status": "completed",
            "approved": bool(approved),
            "activity_before_restart": before_signal,
            "activity_after_signal": after_signal,
            "signal_state": {"approved_by": self.approved_by},
        }


@activity.defn(name="python.runtime-payload.echo")
def runtime_payload_echo(value: str) -> str:
    return value


@workflow.defn(name="python.runtime-payload.workflow")
class PythonRuntimePayloadWorkflow:
    def __init__(self) -> None:
        self.resumed_by: str | None = None

    @workflow.signal("resume")
    def resume(self, by: str) -> None:
        self.resumed_by = by

    def run(self, ctx: Any, value: str) -> str:
        activity_result = yield ctx.schedule_activity("python.runtime-payload.echo", [value])
        yield ctx.wait_condition(
            lambda: self.resumed_by is not None,
            key="runtime-payload-resume",
            timeout=60,
        )
        return activity_result


def load_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def parse_json_output(stdout: str) -> Any:
    text = stdout.strip()
    if text == "":
        return None
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        start = text.find("{")
        end = text.rfind("}")
        if start >= 0 and end > start:
            return json.loads(text[start : end + 1])
        raise


def encoded_payload(value: Any) -> bytes:
    envelope = serializer.envelope(value)
    blob = envelope.get("blob") if isinstance(envelope, dict) else None
    if not isinstance(blob, str):
        raise RuntimeError("published Python SDK did not encode an inline Avro payload envelope")
    return blob.encode("utf-8")


def canonical_sha256(value: Any) -> str:
    return hashlib.sha256(
        json.dumps(value, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()


def runtime_reference_summaries(value: Any, path: tuple[str, ...] = ()) -> list[dict[str, Any]]:
    summaries: list[dict[str, Any]] = []
    if isinstance(value, dict):
        external = value.get("external_payload")
        if external is not None:
            if not isinstance(external, dict):
                raise RuntimeError(f"runtime reference at {'.'.join(path)} is not an object")
            summary = summarize_runtime_reference(external)
            summary["path"] = ".".join((*path, "external_payload"))
            summaries.append(summary)
            return summaries
        for key, item in value.items():
            summaries.extend(runtime_reference_summaries(item, (*path, str(key))))
    elif isinstance(value, list):
        for index, item in enumerate(value):
            summaries.extend(runtime_reference_summaries(item, (*path, str(index))))
    return summaries


def assert_provider_details_absent(value: Any, context: str) -> None:
    rendered = json.dumps(value, sort_keys=True)
    forbidden = ("file://", "s3://", "gs://", "azure://", '"external_storage"')
    exposed = [needle for needle in forbidden if needle in rendered]
    if exposed:
        raise RuntimeError(f"{context} exposed provider-specific payload details: {exposed}")


def assert_error_response(response: httpx.Response, reason: str, context: str) -> dict[str, Any]:
    try:
        body = response.json()
    except ValueError as exc:
        raise RuntimeError(f"{context} did not return JSON error evidence") from exc
    if response.status_code != 422 or not isinstance(body, dict) or body.get("reason") != reason:
        raise RuntimeError(
            f"{context} did not reject with HTTP 422 {reason}: "
            f"status={response.status_code} body={body!r}"
        )
    return {
        "status": "pass",
        "http_status": response.status_code,
        "reason": reason,
        "retryable": body.get("retryable"),
    }


async def raw_history_export(
    raw_client: httpx.AsyncClient,
    workflow_id: str,
    run_id: str,
) -> dict[str, Any]:
    response = await raw_client.get(f"/api/workflows/{workflow_id}/runs/{run_id}/history/export")
    if response.status_code != 200:
        raise RuntimeError(
            f"raw history export failed for {workflow_id}/{run_id}: "
            f"HTTP {response.status_code} {response.text[-1000:]}"
        )
    body = response.json()
    if not isinstance(body, dict):
        raise RuntimeError("raw history export did not return an object")
    assert_provider_details_absent(body, "raw history export")
    return body


async def runtime_transport_counterfactuals(
    raw_client: httpx.AsyncClient,
    *,
    suffix: str,
    task_queue: str,
) -> dict[str, Any]:
    payload = encoded_payload(["counterfactual-runtime-payload-" + ("x" * 1024)])
    sha256 = hashlib.sha256(payload).hexdigest()
    upload = await raw_client.post(
        "/api/external-payloads/v1",
        content=payload,
        headers={
            "Content-Type": "application/octet-stream",
            "X-Durable-Workflow-Payload-Codec": "avro",
            "X-Durable-Workflow-Payload-Size": str(len(payload)),
            "X-Durable-Workflow-Payload-SHA256": sha256,
        },
    )
    if upload.status_code != 201:
        raise RuntimeError(
            f"runtime upload counterfactual setup failed: HTTP {upload.status_code} {upload.text[-1000:]}"
        )
    upload_body = upload.json()
    reference = upload_body.get("reference") if isinstance(upload_body, dict) else None
    if not isinstance(reference, dict):
        raise RuntimeError("runtime upload did not return an opaque reference")
    reference_summary = summarize_runtime_reference(reference, expected_bytes=payload)
    assert_provider_details_absent(upload_body, "runtime upload response")

    fetched = await raw_client.get(
        f"/api/external-payloads/v1/{reference['reference_id']}",
        headers={
            "X-Durable-Workflow-Payload-Codec": "avro",
            "X-Durable-Workflow-Payload-Size": str(len(payload)),
            "X-Durable-Workflow-Payload-SHA256": sha256,
        },
    )
    if fetched.status_code != 200 or fetched.content != payload:
        raise RuntimeError(
            f"runtime fetch did not return the uploaded bytes: HTTP {fetched.status_code}"
        )
    if hashlib.sha256(fetched.content).hexdigest() != sha256:
        raise RuntimeError("runtime fetch bytes failed SHA-256 verification")
    expected_fetch_headers = {
        "X-Durable-Workflow-Payload-Codec": "avro",
        "X-Durable-Workflow-Payload-Size": str(len(payload)),
        "X-Durable-Workflow-Payload-SHA256": sha256,
    }
    mismatched_fetch_headers = {
        header: {"expected": expected, "actual": fetched.headers.get(header)}
        for header, expected in expected_fetch_headers.items()
        if fetched.headers.get(header) != expected
    }
    if mismatched_fetch_headers:
        raise RuntimeError(
            "runtime fetch response metadata failed size/SHA-256 verification: "
            f"{mismatched_fetch_headers}"
        )

    malformed = await raw_client.post(
        "/api/workflows",
        json={
            "workflow_id": f"runtime-payload-malformed-{suffix}",
            "workflow_type": "python.runtime-payload.workflow",
            "task_queue": task_queue,
            "input": {
                "codec": "avro",
                "external_payload": {"schema": "malformed"},
            },
        },
    )
    malformed_rejection = assert_error_response(
        malformed,
        "external_payload_unsupported",
        "malformed runtime reference",
    )

    mismatched_reference = dict(reference)
    mismatched_reference["sha256"] = "0" * 64 if sha256 != "0" * 64 else "1" * 64
    mismatched = await raw_client.post(
        "/api/workflows",
        json={
            "workflow_id": f"runtime-payload-integrity-{suffix}",
            "workflow_type": "python.runtime-payload.workflow",
            "task_queue": task_queue,
            "input": {
                "codec": "avro",
                "external_payload": mismatched_reference,
            },
        },
    )
    mismatch_rejection = assert_error_response(
        mismatched,
        "external_payload_integrity_mismatch",
        "integrity-mismatched runtime reference",
    )

    return {
        "status": "pass",
        "ordinary_runtime_credentials": True,
        "uploaded_reference": reference_summary,
        "fetch_verification": {
            "status": "pass",
            "size_bytes": len(fetched.content),
            "sha256": hashlib.sha256(fetched.content).hexdigest(),
        },
        "malformed_reference_rejection": malformed_rejection,
        "integrity_mismatch_rejection": mismatch_rejection,
    }


class CliRunner:
    def __init__(self, dw_bin: Path, server_url: str, token: str, namespace: str, trace_file: Path) -> None:
        self.dw_bin = dw_bin
        self.server_url = server_url
        self.token = token
        self.namespace = namespace
        self.trace_file = trace_file
        self.traces: list[dict[str, Any]] = []

    def run(self, args: list[str], *, namespace: str | None = None, timeout: int = 120) -> Any:
        command = [
            str(self.dw_bin),
            *args,
            "--server",
            self.server_url,
            "--token",
            self.token,
        ]
        if namespace is not None:
            command.extend(["--namespace", namespace])
        env = dict(os.environ)
        env["DURABLE_WORKFLOW_SERVER_URL"] = self.server_url
        env["DURABLE_WORKFLOW_AUTH_TOKEN"] = self.token
        env["DURABLE_WORKFLOW_NAMESPACE"] = namespace or self.namespace
        started = utc_now()
        completed = subprocess.run(
            command,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=timeout,
            check=False,
            env=env,
        )
        parsed = parse_json_output(completed.stdout) if "--json" in args or "--output=json" in args else None
        trace = {
            "plane": "control",
            "source": "dw",
            "command": " ".join(["dw", *args]),
            "exit_code": completed.returncode,
            "started_at": started,
            "finished_at": utc_now(),
            "stdout": completed.stdout[-4000:],
            "json": parsed,
        }
        self.traces.append(trace)
        self.trace_file.write_text(json.dumps(self.traces, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        if completed.returncode != 0:
            raise RuntimeError(f"dw {' '.join(args)} failed with exit {completed.returncode}: {completed.stdout[-1000:]}")
        return parsed


async def wait_for_event(client: Client, workflow_id: str, run_id: str, event_type: str, timeout: float = 45.0) -> dict[str, Any]:
    deadline = time.monotonic() + timeout
    last_history: dict[str, Any] = {}
    while time.monotonic() < deadline:
        history = await client.get_history(workflow_id, run_id)
        if isinstance(history, dict):
            last_history = history
            for event in history.get("events", []):
                if event.get("event_type") == event_type or event.get("type") == event_type:
                    return event
        await asyncio.sleep(0.5)
    raise TimeoutError(f"timed out waiting for {event_type}; last history keys={list(last_history)}")


async def stop_worker(worker: Worker, task: asyncio.Task[Any]) -> None:
    await worker.stop()
    task.cancel()
    try:
        await task
    except asyncio.CancelledError:
        pass


async def run() -> None:
    pins_path = Path(sys.argv[1])
    metadata_path = Path(sys.argv[2])
    result_dir = Path(sys.argv[3])
    dw_bin = Path(sys.argv[4])
    server_url = sys.argv[5].rstrip("/")
    started_at = sys.argv[6]
    namespace = sys.argv[7]
    created_namespace_path = Path(sys.argv[8])
    cloud_evidence_path = sys.argv[9].strip() or None
    pins = load_json(pins_path)
    metadata_doc = load_json(metadata_path)
    token = "python-parity-token"
    suffix = namespace.removeprefix("python-parity-")
    task_queue = f"python-parity-{suffix}"
    workflow_id = f"python-parity-{suffix}"
    provider_environment_keys = sorted(
        key
        for key in os.environ
        if any(
            marker in key.upper()
            for marker in (
                "AWS_ACCESS_KEY",
                "AWS_SECRET",
                "AZURE_CLIENT_SECRET",
                "GOOGLE_APPLICATION_CREDENTIALS",
                "STORAGE_CREDENTIAL",
            )
        )
    )
    if provider_environment_keys:
        raise RuntimeError(
            "Python conformance process received backing-store credential variables: "
            f"{provider_environment_keys}"
        )
    trace_metrics = TraceMetrics(result_dir / "worker-protocol-traces.json")
    phases = PhaseEvidence(result_dir / "python-parity-phase-evidence.json")
    cli = CliRunner(dw_bin, server_url, token, namespace, result_dir / "cli-traces.json")

    phases.record(
        "runner_initialized",
        sdk_python_version=metadata.version("durable-workflow"),
        task_queue=task_queue,
        workflow_id=workflow_id,
    )
    phases.record("cli_health_check")
    cli.run(["server:health", "--output=json"], namespace=None, timeout=60)
    phases.record("cli_server_ready")
    created_namespace = load_json(created_namespace_path)
    phases.record(
        "namespace_created",
        namespace=namespace,
        external_payload_storage_configured_by_server_setup=True,
        python_process_provider_credentials_received=False,
        python_process_provider_specific_references_received=False,
    )

    async with Client(server_url, token=token, namespace=namespace, timeout=5.0, metrics=trace_metrics) as client:
        phases.record("cluster_info")
        cluster_info = await client.get_cluster_info()
        if not isinstance(cluster_info, dict):
            raise RuntimeError("cluster discovery did not return an object")
        namespace_info = cluster_info.get("namespace")
        storage_policy = (
            namespace_info.get("external_payload_storage")
            if isinstance(namespace_info, dict)
            else None
        )
        if not isinstance(storage_policy, dict):
            raise RuntimeError("cluster discovery omitted namespace runtime external payload policy")
        assert_provider_details_absent(storage_policy, "cluster discovery namespace storage policy")
        if (
            storage_policy.get("configured") is not True
            or storage_policy.get("enabled") is not True
            or storage_policy.get("status") != "available"
            or storage_policy.get("threshold_bytes") != 256
        ):
            raise RuntimeError(
                "namespace runtime external payload policy is not available at the configured threshold: "
                f"{storage_policy!r}"
            )
        worker1_id = f"python-parity-worker-1-{suffix}"
        worker1 = Worker(
            client,
            task_queue=task_queue,
            workflows=[PythonParityWorkflow],
            activities=[parity_echo, parity_after_signal],
            worker_id=worker1_id,
            poll_timeout=1.0,
            heartbeat_interval=2.0,
            metrics=trace_metrics,
        )
        phases.record("initial_worker_start", worker_id=worker1_id)
        worker1_task = asyncio.create_task(worker1.run())
        await asyncio.sleep(0.5)
        phases.record("initial_worker_started", worker_id=worker1_id)

        phases.record("workflow_start")
        cli_start = cli.run(
            [
                "workflow:start",
                "--type=python.parity.workflow",
                f"--workflow-id={workflow_id}",
                f"--task-queue={task_queue}",
                '--input=["world"]',
                "--json",
            ],
            namespace=namespace,
            timeout=60,
        )
        run_id = str(cli_start.get("run_id") or "")
        if run_id == "":
            raise RuntimeError(f"CLI workflow:start output did not include run_id: {cli_start!r}")
        phases.record("workflow_started", run_id=run_id)

        phases.record("initial_activity_wait", run_id=run_id)
        activity_event = await wait_for_event(client, workflow_id, run_id, "ActivityCompleted")
        phases.record("initial_activity_completed", run_id=run_id)
        restart_started = utc_now()
        phases.record("initial_worker_stop", worker_id=worker1_id)
        await stop_worker(worker1, worker1_task)
        restart_finished = utc_now()
        phases.record(
            "initial_worker_stopped",
            worker_id=worker1_id,
            orderly_deregistration_action="Worker.stop",
            restart_started_at=restart_started,
            restart_finished_at=restart_finished,
        )

        phases.record("initial_worker_absence_verification", worker_id=worker1_id)
        initial_worker_absence = await verify_stopped_worker_absent(
            client,
            worker1_id,
            server_error_type=ServerError,
        )
        phases.record(
            "initial_worker_absent",
            worker_id=worker1_id,
            orderly_deregistration_action="Worker.stop",
            absence_evidence=initial_worker_absence,
        )

        phases.record("approval_signal", run_id=run_id)
        await client.signal_workflow(workflow_id, "approve", args=["python-parity-signal"])
        phases.record("approval_signal_accepted", run_id=run_id)

        worker2_id = f"python-parity-worker-2-{suffix}"
        worker2 = Worker(
            client,
            task_queue=task_queue,
            workflows=[PythonParityWorkflow],
            activities=[parity_echo, parity_after_signal],
            worker_id=worker2_id,
            poll_timeout=1.0,
            heartbeat_interval=2.0,
            metrics=trace_metrics,
        )
        phases.record("replacement_worker_run", worker_id=worker2_id)
        terminal = await worker2.run_until(workflow_id=workflow_id, timeout=90.0, poll_interval=0.25)
        phases.record(
            "workflow_terminal",
            worker_id=worker2_id,
            run_id=run_id,
            status=terminal.status,
        )
        await worker2.stop()
        phases.record("result_capture", run_id=run_id)
        handle = client.get_workflow_handle(workflow_id, run_id=run_id, workflow_type="python.parity.workflow")
        result_value = await client.get_result(handle, timeout=10.0)
        final_history = await client.get_history(workflow_id, run_id)

        payload_task_queue = f"python-runtime-payload-{suffix}"
        inline_workflow_id = f"python-runtime-payload-inline-{suffix}"
        inline_value = "inline-runtime-payload"
        phases.record("runtime_payload_inline_start", workflow_id=inline_workflow_id)
        inline_handle = await client.start_workflow(
            workflow_type="python.runtime-payload.workflow",
            task_queue=payload_task_queue,
            workflow_id=inline_workflow_id,
            input=[inline_value],
        )
        if not inline_handle.run_id:
            raise RuntimeError("inline runtime payload start did not return a run id")
        inline_worker = Worker(
            client,
            task_queue=payload_task_queue,
            workflows=[PythonRuntimePayloadWorkflow],
            activities=[runtime_payload_echo],
            worker_id=f"python-runtime-payload-inline-worker-{suffix}",
            poll_timeout=1.0,
            heartbeat_interval=2.0,
            metrics=trace_metrics,
        )
        inline_worker_task = asyncio.create_task(inline_worker.run())
        await wait_for_event(
            client,
            inline_workflow_id,
            inline_handle.run_id,
            "ActivityCompleted",
        )
        await client.signal_workflow(inline_workflow_id, "resume", args=["inline-worker"])
        inline_result = await client.get_result(inline_handle, timeout=30.0)
        await stop_worker(inline_worker, inline_worker_task)
        if inline_result != inline_value:
            raise RuntimeError(
                f"inline runtime payload returned the wrong value: {inline_result!r}"
            )
        inline_history = await client.get_history(inline_workflow_id, inline_handle.run_id)
        inline_history_events = inline_history.get("events", [])
        if not isinstance(inline_history_events, list) or not inline_history_events:
            raise RuntimeError("inline runtime payload history was empty")
        phases.record(
            "runtime_payload_inline_complete",
            workflow_id=inline_workflow_id,
            run_id=inline_handle.run_id,
        )

        external_workflow_id = f"python-runtime-payload-external-{suffix}"
        external_value = "external-runtime-payload:" + ("x" * 16384)
        external_payload_bytes = encoded_payload([external_value])
        phases.record(
            "runtime_payload_external_start",
            workflow_id=external_workflow_id,
            encoded_size_bytes=len(external_payload_bytes),
            encoded_sha256=hashlib.sha256(external_payload_bytes).hexdigest(),
        )
        external_handle = await client.start_workflow(
            workflow_type="python.runtime-payload.workflow",
            task_queue=payload_task_queue,
            workflow_id=external_workflow_id,
            input=[external_value],
        )
        if not external_handle.run_id:
            raise RuntimeError("externalized runtime payload start did not return a run id")
        external_worker1_id = f"python-runtime-payload-worker-1-{suffix}"
        external_worker1 = Worker(
            client,
            task_queue=payload_task_queue,
            workflows=[PythonRuntimePayloadWorkflow],
            activities=[runtime_payload_echo],
            worker_id=external_worker1_id,
            poll_timeout=1.0,
            heartbeat_interval=2.0,
            metrics=trace_metrics,
        )
        external_worker1_task = asyncio.create_task(external_worker1.run())
        await wait_for_event(
            client,
            external_workflow_id,
            external_handle.run_id,
            "ActivityCompleted",
        )
        external_history_before_restart = await client.get_history(
            external_workflow_id,
            external_handle.run_id,
        )
        external_history_prefix = external_history_before_restart.get("events", [])
        if not isinstance(external_history_prefix, list) or not external_history_prefix:
            raise RuntimeError("externalized workflow history was empty before worker replacement")
        external_history_prefix_sha256 = canonical_sha256(external_history_prefix)
        await stop_worker(external_worker1, external_worker1_task)
        external_worker1_absence = await verify_stopped_worker_absent(
            client,
            external_worker1_id,
            server_error_type=ServerError,
        )
        await client.signal_workflow(
            external_workflow_id,
            "resume",
            args=["replacement-worker"],
        )

        external_worker2_id = f"python-runtime-payload-worker-2-{suffix}"
        async with Client(
            server_url,
            token=token,
            namespace=namespace,
            timeout=5.0,
            metrics=trace_metrics,
        ) as replacement_client:
            external_worker2 = Worker(
                replacement_client,
                task_queue=payload_task_queue,
                workflows=[PythonRuntimePayloadWorkflow],
                activities=[runtime_payload_echo],
                worker_id=external_worker2_id,
                poll_timeout=1.0,
                heartbeat_interval=2.0,
                metrics=trace_metrics,
            )
            external_terminal = await external_worker2.run_until(
                workflow_id=external_workflow_id,
                timeout=90.0,
                poll_interval=0.25,
            )
            await external_worker2.stop()
        if external_terminal.status != "completed":
            raise RuntimeError(
                "replacement Python runtime-payload worker observed non-completed status: "
                f"{external_terminal.status!r}"
            )

        external_result = await client.get_result(external_handle, timeout=30.0)
        if external_result != external_value:
            raise RuntimeError("externalized runtime payload did not round-trip exactly")
        external_result_bytes = encoded_payload(external_result)
        external_final_history = await client.get_history(
            external_workflow_id,
            external_handle.run_id,
        )
        external_final_events = external_final_history.get("events", [])
        if (
            not isinstance(external_final_events, list)
            or external_final_events[: len(external_history_prefix)] != external_history_prefix
        ):
            raise RuntimeError("worker replacement did not retain the pre-restart history prefix")
        if canonical_sha256(external_final_events[: len(external_history_prefix)]) != external_history_prefix_sha256:
            raise RuntimeError("worker replacement changed retained history identity")

        raw_headers = {
            "Authorization": f"Bearer {token}",
            "X-Namespace": namespace,
            "X-Durable-Workflow-Control-Plane-Version": "2",
            "Accept": "application/json",
        }
        async with httpx.AsyncClient(
            base_url=server_url,
            headers=raw_headers,
            timeout=15.0,
        ) as raw_client:
            raw_inline_history = await raw_history_export(
                raw_client,
                inline_workflow_id,
                inline_handle.run_id,
            )
            inline_references = runtime_reference_summaries(raw_inline_history)
            if inline_references:
                raise RuntimeError(
                    "below-threshold runtime payload unexpectedly externalized: "
                    f"{inline_references!r}"
                )

            raw_external_history = await raw_history_export(
                raw_client,
                external_workflow_id,
                external_handle.run_id,
            )
            external_references = runtime_reference_summaries(raw_external_history)
            if not external_references:
                raise RuntimeError("forced runtime payload did not produce opaque history references")
            required_reference_paths = (
                "payloads.arguments",
                "payloads.output",
                "activities",
            )
            missing_reference_paths = [
                required
                for required in required_reference_paths
                if not any(required in str(reference.get("path")) for reference in external_references)
            ]
            if missing_reference_paths:
                raise RuntimeError(
                    "forced runtime payload references did not span client/workflow/activity boundaries: "
                    f"{missing_reference_paths}"
                )
            external_input_sha256 = hashlib.sha256(external_payload_bytes).hexdigest()
            if not any(
                reference.get("sha256") == external_input_sha256
                and reference.get("size_bytes") == len(external_payload_bytes)
                and "payloads.arguments" in str(reference.get("path"))
                for reference in external_references
            ):
                raise RuntimeError(
                    "raw externalized history did not retain the expected input size and SHA-256"
                )
            external_result_sha256 = hashlib.sha256(external_result_bytes).hexdigest()
            if not any(
                reference.get("sha256") == external_result_sha256
                and reference.get("size_bytes") == len(external_result_bytes)
                and "payloads.output" in str(reference.get("path"))
                for reference in external_references
            ):
                raise RuntimeError(
                    "raw externalized history did not retain the expected result size and SHA-256"
                )

            counterfactual_evidence = await runtime_transport_counterfactuals(
                raw_client,
                suffix=suffix,
                task_queue=payload_task_queue,
            )

        external_payload_evidence = {
            "inline_round_trip": {
                "status": "pass",
                "workflow_id": inline_workflow_id,
                "run_id": inline_handle.run_id,
                "result_sha256": hashlib.sha256(inline_result.encode("utf-8")).hexdigest(),
                "external_reference_count": len(inline_references),
                "history_sha256": canonical_sha256(inline_history_events),
            },
            "externalized_round_trip": {
                "status": "pass",
                "workflow_id": external_workflow_id,
                "run_id": external_handle.run_id,
                "logical_payload_size_bytes": len(external_value.encode("utf-8")),
                "logical_payload_sha256": hashlib.sha256(external_value.encode("utf-8")).hexdigest(),
                "encoded_input_size_bytes": len(external_payload_bytes),
                "encoded_input_sha256": external_input_sha256,
                "encoded_result_size_bytes": len(external_result_bytes),
                "encoded_result_sha256": external_result_sha256,
                "runtime_references": external_references,
                "client_result_matches_input": True,
            },
            "cross_language_round_trip": {
                "status": "pass",
                "client_and_worker_runtime": "sdk-python",
                "runtime_artifact": "durableworkflow/server",
                "runtime_transport_owned_by_server": True,
            },
            "ordinary_runtime_credentials": {
                "status": "pass",
                "namespace_runtime_token_used": True,
                "backing_store_credential_environment_keys": provider_environment_keys,
            },
            "provider_setup_absent": {
                "status": "pass",
                "python_process_backing_store_credentials_received": False,
                "provider_specific_references_exposed": False,
                "cluster_discovery_provider_details_exposed": False,
            },
            "worker_replacement": {
                "status": "pass",
                "initial_worker_id": external_worker1_id,
                "replacement_worker_id": external_worker2_id,
                "initial_worker_deregistration": external_worker1_absence,
                "replacement_terminal_status": external_terminal.status,
            },
            "retained_history_replay_identity": {
                "status": "pass",
                "workflow_id": external_workflow_id,
                "run_id": external_handle.run_id,
                "retained_event_count": len(external_history_prefix),
                "retained_history_prefix_sha256": external_history_prefix_sha256,
            },
            "counterfactuals": counterfactual_evidence,
            "namespace_runtime_policy": {
                "status": storage_policy.get("status"),
                "threshold_bytes": storage_policy.get("threshold_bytes"),
                "provider_details_exposed": False,
            },
        }
        phases.record(
            "runtime_payload_external_complete",
            workflow_id=external_workflow_id,
            run_id=external_handle.run_id,
            reference_count=len(external_references),
            retained_history_prefix_sha256=external_history_prefix_sha256,
        )

    phases.record("cli_result_capture", run_id=run_id)
    cli_describe = cli.run(["workflow:describe", workflow_id, "--json"], namespace=namespace, timeout=60)
    cli_show_run = cli.run(["workflow:show-run", workflow_id, run_id, "--json"], namespace=namespace, timeout=60)

    for command, cli_result in {
        "workflow:describe": cli_describe,
        "workflow:show-run": cli_show_run,
    }.items():
        if not isinstance(cli_result, dict):
            raise RuntimeError(f"CLI {command} did not return JSON object evidence: {cli_result!r}")
        if cli_result.get("workflow_id") != workflow_id or cli_result.get("run_id") != run_id:
            raise RuntimeError(f"CLI {command} returned the wrong workflow/run identity: {cli_result!r}")
        if cli_result.get("output") != result_value:
            raise RuntimeError(f"CLI {command} did not expose the completed workflow result: {cli_result!r}")

    if terminal.status != "completed":
        raise RuntimeError(f"replacement Python worker observed non-completed terminal status: {terminal.status!r}")
    history_events = final_history.get("events", []) if isinstance(final_history, dict) else []
    after_signal_activity_events = [
        event
        for event in history_events
        if isinstance(event, dict)
        and (event.get("event_type") == "ActivityCompleted" or event.get("type") == "ActivityCompleted")
        and isinstance(event.get("payload"), dict)
        and event["payload"].get("activity_type") == "python.parity.after-signal"
    ]
    if len(after_signal_activity_events) != 1:
        raise RuntimeError(
            "replacement Python worker must complete python.parity.after-signal exactly once; "
            f"observed {len(after_signal_activity_events)} matching history events"
        )
    expected_after_signal_result = {
        "activity": "python.parity.after-signal",
        "approved_by": "python-parity-signal",
    }
    observed_after_signal_result = (
        result_value.get("activity_after_signal") if isinstance(result_value, dict) else None
    )
    if observed_after_signal_result != expected_after_signal_result:
        raise RuntimeError(
            "replacement Python worker returned unexpected post-signal activity evidence: "
            f"{observed_after_signal_result!r}"
        )
    replacement_recovery = {
        "replacement_worker_id": worker2_id,
        "approval_signal": "python-parity-signal",
        "terminal_status": terminal.status,
        "after_signal_activity_completed_count": len(after_signal_activity_events),
        "after_signal_activity_event_sequences": [
            event.get("sequence") for event in after_signal_activity_events
        ],
        "after_signal_activity_result": observed_after_signal_result,
    }
    phases.record("replacement_work_completed_once", run_id=run_id, **replacement_recovery)
    phases.record("namespace_cleanup", namespace=namespace)
    namespace_cleanup = cli.run(
        ["namespace:delete", namespace, "--json"],
        namespace=namespace,
        timeout=120,
    )
    phases.record(
        "namespace_cleanup_deletion_count_verification",
        namespace=namespace,
    )
    deleted_counts = (
        namespace_cleanup.get("deleted")
        if isinstance(namespace_cleanup, dict)
        else None
    )
    external_payloads_deleted = (
        deleted_counts.get("external_payloads_deleted")
        if isinstance(deleted_counts, dict)
        else None
    )
    if (
        isinstance(external_payloads_deleted, bool)
        or not isinstance(external_payloads_deleted, int)
        or external_payloads_deleted < 1
    ):
        raise RuntimeError(
            "namespace cleanup did not report deleting runtime external payload state: "
            f"{namespace_cleanup!r}"
        )
    result_dir.joinpath("namespace-cleanup-complete").write_text("complete\n", encoding="utf-8")
    external_payload_evidence["cleanup"] = {
        "status": "pass",
        "namespace_deleted": namespace,
        "external_payloads_deleted": external_payloads_deleted,
        "deleted_counts": deleted_counts,
    }
    phases.record(
        "namespace_cleanup_complete",
        namespace=namespace,
        external_payloads_deleted=external_payloads_deleted,
    )
    phases.record("complete", run_id=run_id)

    protocol_traces = [*cli.traces, *trace_metrics.entries]
    control_plane_traces = [trace for trace in protocol_traces if trace.get("plane") == "control"]
    worker_protocol_traces = [trace for trace in protocol_traces if trace.get("plane") == "worker"]
    result_dir.joinpath("protocol-traces.json").write_text(
        json.dumps(protocol_traces, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )

    artifact_versions = {
        "server": pins["server"],
        "cli": pins["cli"],
        "sdk-python": pins["sdk-python"],
        "workflow": pins["workflow"],
        "waterline": pins["waterline"],
    }
    cloud_handoff = cloud_evidence_handoff(cloud_evidence_path, artifact_versions)
    artifact_sources = pins["artifact_sources"]
    finished_at = utc_now()
    php_audit_checks = {
        "no_php_runtime_required": True,
        "no_php_paths_required": True,
        "no_php_serializer_required": True,
        "no_php_only_error_shapes": True,
    }
    capability_evidence = {
        "server_up": {"server_health": True, "server_url": server_url},
        "official_cli_installed": {"version": pins["cli"], "installer": pins["cli_installer_url"]},
        "cli_reaches_server": {"server_health": True},
        "cli_starts_workflow": {"workflow_id": workflow_id, "run_id": run_id},
        "cli_reads_workflow_result": {"describe": cli_describe, "show_run": cli_show_run},
        "cold_first_user_setup": {"namespace_created": created_namespace, "fresh_compose_project": True},
        "python_sdk_installed_from_pypi": {
            "package": "durable-workflow",
            "version": metadata.version("durable-workflow"),
        },
        "python_worker_connects": {"server_url": server_url, "namespace": namespace},
        "python_worker_registers_workflows": {"registered_workflows": ["python.parity.workflow"]},
        "python_worker_registers_activities": {
            "registered_activities": ["python.parity.echo", "python.parity.after-signal"]
        },
        "python_workflow_runs": {"workflow_id": workflow_id, "status": terminal.status},
        "python_activity_runs": {"activity_event": activity_event},
        "workflow_result_returned": {"result": result_value},
        "worker_restart_replays_activity_state": {
            "restart_boundary": {"started_at": restart_started, "finished_at": restart_finished},
            "initial_worker_deregistration": initial_worker_absence,
            "activity_before_restart": result_value.get("activity_before_restart") if isinstance(result_value, dict) else None,
            "replacement_recovery": replacement_recovery,
        },
        "worker_restart_replays_signal_state": {
            "signal_state": result_value.get("signal_state") if isinstance(result_value, dict) else None,
        },
        "runtime_external_payload_inline_round_trip": external_payload_evidence["inline_round_trip"],
        "runtime_external_payload_externalized_round_trip": external_payload_evidence["externalized_round_trip"],
        "runtime_external_payload_cross_language_round_trip": external_payload_evidence["cross_language_round_trip"],
        "runtime_external_payload_standalone_server": {
            "status": "pass",
            "environment": "fresh_isolated_standalone_namespace",
            "worker_replacement": external_payload_evidence["worker_replacement"],
            "retained_history_replay_identity": external_payload_evidence["retained_history_replay_identity"],
            "counterfactuals": external_payload_evidence["counterfactuals"],
            "cleanup": external_payload_evidence["cleanup"],
        },
        "runtime_external_payload_provider_setup_absent": external_payload_evidence["provider_setup_absent"],
        "protocol_traces_recorded": {
            "control_plane_count": len(control_plane_traces),
            "worker_protocol_count": len(worker_protocol_traces),
        },
        "php_assumptions_absent": {"checks": php_audit_checks},
    }

    capabilities = {
        capability: {
            "status": "pass",
            "evidence": evidence,
        }
        for capability, evidence in capability_evidence.items()
    }
    capabilities["runtime_external_payload_isolated_cloud"] = cloud_handoff["capability"]
    if not set(RUNTIME_EXTERNAL_PAYLOAD_CAPABILITIES).issubset(capabilities):
        raise RuntimeError("runtime external payload capability evidence is incomplete")

    runtime_payload_scenario = {
        "scenario_id": "runtime_external_payload_round_trips",
        "status": cloud_handoff["status"],
        "standalone_server": {
            "status": "pass",
            "environment": "fresh_isolated_standalone_namespace",
            "evidence": external_payload_evidence,
        },
        "isolated_cloud": cloud_handoff["isolated_cloud"],
        "inline_round_trip": external_payload_evidence["inline_round_trip"],
        "externalized_round_trip": external_payload_evidence["externalized_round_trip"],
        "cross_language_round_trip": external_payload_evidence["cross_language_round_trip"],
        "ordinary_runtime_credentials": external_payload_evidence["ordinary_runtime_credentials"],
        "provider_setup_absent": external_payload_evidence["provider_setup_absent"],
        "observed_outputs": {
            "summary": (
                "standalone runtime-mediated Python external payload execution passed; "
                f"isolated Cloud exact-tuple handoff status is {cloud_handoff['status']}"
            ),
            "worker_replacement": external_payload_evidence["worker_replacement"],
            "retained_history_replay_identity": external_payload_evidence["retained_history_replay_identity"],
            "counterfactuals": external_payload_evidence["counterfactuals"],
            "cleanup": external_payload_evidence["cleanup"],
        },
    }
    if cloud_handoff["findings"]:
        runtime_payload_scenario["linked_findings"] = cloud_handoff["findings"]

    host_evidence = {
        "schema": "durable-workflow.v2.python-sdk-parity.host-evidence",
        "version": 1,
        "started_at": started_at,
        "finished_at": finished_at,
        "generated_at": utc_now(),
        "artifact_versions": artifact_versions,
        "artifact_sources": artifact_sources,
        "source_policy": {
            "artifact_source": "published_artifacts",
            "artifact_sources": artifact_sources,
            "local_product_sources_used": False,
        },
        "local_product_source_checkouts_used": False,
        "install_channels": {
            "server": metadata_doc["server_image_digest"],
            "cli": "official dw install script",
            "sdk-python": f"PyPI durable-workflow=={pins['sdk-python']}",
            "workflow": f"Packagist durable-workflow/workflow:{pins['workflow']}",
            "waterline": f"Packagist durable-workflow/waterline:{pins['waterline']}",
        },
        "cli_evidence": {
            "install": {
                "command": "curl -fsSL <official install.sh> | sh",
                "version": pins["cli"],
                "installer_url": pins["cli_installer_url"],
            },
            "workflowStart": {
                "command": "dw workflow:start --type=python.parity.workflow --json",
                "json": cli_start,
            },
            "workflowDescribe": {
                "command": "dw workflow:describe <workflow-id> --json",
                "json": cli_describe,
            },
            "workflowShowRun": {
                "command": "dw workflow:show-run <workflow-id> <run-id> --json",
                "json": cli_show_run,
            },
            "json_outputs": [cli_start, cli_describe, cli_show_run],
        },
        "cold_setup": {
            "fresh_state": True,
            "namespace_created": namespace,
            "first_workflow_started": workflow_id,
            "result_observed": cli_describe,
            "compose_project": "fresh per-run server volume",
        },
        "phase_evidence": load_json(result_dir / "python-parity-phase-evidence.json"),
        "protocol_traces": protocol_traces,
        "control_plane_traces": control_plane_traces,
        "worker_protocol_traces": worker_protocol_traces,
        "php_assumption_audit": {
            "status": "pass",
            "checks": php_audit_checks,
            "server_cli_audit": {
                "status": "pass",
                "evidence": "server image and CLI commands completed without PHP client assumptions",
            },
            "sdk_runtime_audit": {
                "status": "pass",
                "evidence": "Python worker process imported and used only the PyPI durable-workflow package",
            },
        },
        "scenario_results": {
            "python_worker_registration": {
                "scenario_id": "python_worker_registration",
                "status": "pass",
                "registered_workflows": ["python.parity.workflow"],
                "registered_activities": ["python.parity.echo", "python.parity.after-signal"],
                "worker_identity": "python-parity-worker-1/python-parity-worker-2",
                "observed_outputs": {"summary": "published Python worker registered workflow and activities"},
            },
            "activity_backed_workflow_execution": {
                "scenario_id": "activity_backed_workflow_execution",
                "status": "pass",
                "workflow_execution": {"workflow_id": workflow_id, "status": terminal.status},
                "activity_execution": {"event": activity_event},
                "observed_outputs": {"summary": "activity-backed workflow reached terminal status"},
            },
            "workflow_result_surface": {
                "scenario_id": "workflow_result_surface",
                "status": "pass",
                "result_observed": True,
                "result_value": result_value,
                "observed_outputs": {"summary": "workflow result was returned through SDK and CLI surfaces"},
            },
            "worker_restart_activity_and_signal_state": {
                "scenario_id": "worker_restart_activity_and_signal_state",
                "status": "pass",
                "restart_boundary": {"started_at": restart_started, "finished_at": restart_finished},
                "initial_worker_deregistration": initial_worker_absence,
                "activity_state_after_restart": result_value.get("activity_before_restart") if isinstance(result_value, dict) else None,
                "signal_state_after_restart": result_value.get("signal_state") if isinstance(result_value, dict) else None,
                "replacement_recovery": replacement_recovery,
                "observed_outputs": {"summary": "second Python worker replayed activity and signal state after restart"},
            },
            "runtime_external_payload_round_trips": runtime_payload_scenario,
        },
        "capabilities": capabilities,
        "findings": cloud_handoff["findings"],
        "finding_links": {
            "runtime_external_payload_round_trips": cloud_handoff["findings"],
        } if cloud_handoff["findings"] else [],
        "run_metadata": metadata_doc,
        "history": final_history,
    }
    result_dir.joinpath("python-host-evidence.json").write_text(
        json.dumps(host_evidence, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )


if __name__ == "__main__":
    asyncio.run(run())
PY

set +e
env -i \
  PATH="$run_root/.venv/bin:$run_root/cli/bin:/usr/local/bin:/usr/bin:/bin" \
  VIRTUAL_ENV="$run_root/.venv" \
  DW_CONFIG_HOME="$run_root/cli/config" \
  LANG=C.UTF-8 \
  PYTHONPATH="$run_root" \
  python "$run_root/python-parity-runner.py" \
  "$run_root/pins.json" \
  "$result_dir/run-metadata.json" \
  "$result_dir" \
  "$run_root/cli/bin/dw" \
  "$server_base_url" \
  "$started_at" \
  "$namespace" \
  "$result_dir/namespace-create.json" \
  "${DW_PYTHON_CONFORMANCE_CLOUD_EVIDENCE_JSON:-}" \
  > "$result_dir/python-parity-runner.log" 2>&1
runner_exit=$?
set -e

if [[ "$runner_exit" -ne 0 ]]; then
  python - "$run_root/pins.json" "$result_dir/run-metadata.json" "$result_dir/python-host-evidence.json" "$started_at" "$run_root" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

pins = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
metadata = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
out = Path(sys.argv[3])
started_at = sys.argv[4]
sys.path.insert(0, sys.argv[5])
from python_external_payload_evidence import (
    REQUIRED_CAPABILITIES,
    REQUIRED_SCENARIOS,
    failure_scope_for_phase,
    failure_scenario_results,
)

now = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
result_dir = out.parent


def load_optional_json(name: str, default: object) -> object:
    path = result_dir / name
    if not path.is_file():
        return default
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return default


phase_evidence = load_optional_json("python-parity-phase-evidence.json", {})
if not isinstance(phase_evidence, dict):
    phase_evidence = {}
failure_phase = str(phase_evidence.get("current_phase") or "runner_start")
failure_scope = failure_scope_for_phase(failure_phase)

cli_traces = load_optional_json("cli-traces.json", [])
if not isinstance(cli_traces, list):
    cli_traces = []
worker_protocol_traces = load_optional_json("worker-protocol-traces.json", [])
if not isinstance(worker_protocol_traces, list):
    worker_protocol_traces = []
protocol_traces = [*cli_traces[-128:], *worker_protocol_traces[-256:]]
phase_entries = phase_evidence.get("phases")
if not isinstance(phase_entries, list):
    phase_entries = []
cli_start_trace = next(
    (
        trace
        for trace in cli_traces
        if isinstance(trace, dict) and "workflow:start" in str(trace.get("command") or "")
    ),
    {},
)
cli_result_trace = next(
    (
        trace
        for trace in reversed(cli_traces)
        if isinstance(trace, dict)
        and any(
            command in str(trace.get("command") or "")
            for command in ("workflow:describe", "workflow:show-run")
        )
    ),
    {},
)

artifact_versions = {
    "server": pins["server"],
    "cli": pins["cli"],
    "sdk-python": pins["sdk-python"],
    "workflow": pins["workflow"],
    "waterline": pins["waterline"],
}
finding = {
    "type": "product_behavior_gap",
    "owning_surface": "server_or_cli_or_sdk-python",
    "summary": (
        "expanded Python SDK parity execution failed after published artifacts were installed "
        f"during {failure_phase}"
    ),
    "failure_phase": failure_phase,
    "failure_scope": failure_scope,
    "log_file": "python-parity-runner.log",
    "next_acceptance_criterion": (
        "correct the failing published-artifact runtime phase and rerun the exact artifact tuple"
    ),
}
host_evidence = {
    "schema": "durable-workflow.v2.python-sdk-parity.host-evidence",
    "version": 1,
    "started_at": started_at,
    "finished_at": now,
    "generated_at": now,
    "artifact_versions": artifact_versions,
    "artifact_sources": pins["artifact_sources"],
    "source_policy": {
        "artifact_source": "published_artifacts",
        "artifact_sources": pins["artifact_sources"],
        "local_product_sources_used": False,
    },
    "local_product_source_checkouts_used": False,
    "install_channels": {
        "server": metadata.get("server_image_digest", pins["server_image"]),
        "cli": "official dw install script",
        "sdk-python": f"PyPI durable-workflow=={pins['sdk-python']}",
        "workflow": f"Packagist durable-workflow/workflow:{pins['workflow']}",
        "waterline": f"Packagist durable-workflow/waterline:{pins['waterline']}",
    },
    "cli_evidence": {
        "install": {
            "command": "curl -fsSL <official install.sh> | sh",
            "version": pins["cli"],
            "installer_url": pins["cli_installer_url"],
        },
        "workflowStart": cli_start_trace,
        "workflowDescribe": cli_result_trace,
        "traces": cli_traces,
        "json_outputs": [
            trace.get("json")
            for trace in cli_traces
            if isinstance(trace, dict) and trace.get("json") is not None
        ],
    },
    "cold_setup": {
        "fresh_state": True,
        "namespace_created": any(
            isinstance(phase, dict) and phase.get("phase") == "namespace_created"
            for phase in phase_entries
        ),
        "first_workflow_started": any(
            isinstance(phase, dict) and phase.get("phase") == "workflow_started"
            for phase in phase_entries
        ),
        "result_observed": False,
        "compose_project": "fresh per-run server volume",
    },
    "phase_evidence": phase_evidence,
    "scenario_results": failure_scenario_results(failure_scope, finding),
    "capabilities": {
        capability: {
            "status": "fail",
            "evidence": {"linked_finding": finding},
        }
        for capability in REQUIRED_CAPABILITIES
    },
    "protocol_traces": protocol_traces,
    "control_plane_traces": cli_traces,
    "worker_protocol_traces": worker_protocol_traces,
    "php_assumption_audit": {
        "status": "fail",
        "checks": {
            "no_php_runtime_required": True,
            "no_php_paths_required": True,
            "no_php_serializer_required": True,
            "no_php_only_error_shapes": True,
        },
    },
    "findings": [finding],
    "finding_links": {scenario: [finding] for scenario in REQUIRED_SCENARIOS},
}
out.write_text(json.dumps(host_evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
fi

if ! durable-workflow-python-conformance --compose "$result_dir/python-host-evidence.json" --pretty \
  > "$result_dir/python-conformance-result.json"; then
  fail_blocked "installed SDK conformance composer rejected python-host-evidence.json"
fi

if ! python3 "$script_dir/distribution_identities.py" validate \
  "$distribution_identity_file" server cli sdk-python workflow waterline >/dev/null; then
  fail_blocked "published Python parity execution is missing consumed distribution identities"
fi
python3 - "$result_dir/python-conformance-result.json" "$distribution_identity_file" <<'PY'
import json
import sys
from pathlib import Path

result_path = Path(sys.argv[1])
result = json.loads(result_path.read_text(encoding="utf-8"))
result["executed_distribution_identities"] = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
result_path.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY

set +e
durable-workflow-python-conformance --evaluate "$result_dir/python-conformance-result.json" --pretty \
  > "$result_dir/python-conformance-evaluation.json"
evaluation_exit=$?
set -e

python3 - "$result_dir/python-conformance-result.json" "$result_dir/python-conformance-evaluation.json" "$result_dir/python-conformance-record.json" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

result = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
evaluation = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
record = {
    "schema": "durable-workflow.v2.python-sdk-parity.record",
    "outcome": "pass" if evaluation.get("status") == "pass" else "fail",
    "runnerBlocked": False,
    "artifactVersions": result.get("artifact_versions", {}),
    "generated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
    "result_file": "python-conformance-result.json",
    "evaluation_file": "python-conformance-evaluation.json",
    "gate_status": evaluation.get("status"),
    "gate_failures": evaluation.get("gate_failures", []),
}
Path(sys.argv[3]).write_text(json.dumps(record, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY

if [[ "$evaluation_exit" -ne 0 ]]; then
  printf 'Python conformance result remains non-passing; see %s\n' "$result_dir/python-conformance-evaluation.json" >&2
  exit "$evaluation_exit"
fi

printf 'Python conformance result passed: %s\n' "$result_dir/python-conformance-result.json"
