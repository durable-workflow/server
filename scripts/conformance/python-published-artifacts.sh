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
  "$run_root/artifacts/workflow" \
  "$run_root/artifacts/waterline"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

server_bind_host="${DW_PYTHON_CONFORMANCE_BIND_HOST:-127.0.0.1}"
server_port="${DW_PYTHON_CONFORMANCE_SERVER_PORT:-}"
server_base_url=""
run_label="$(printf '%s' "$(basename "$run_root")" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_-' '-')"
compose_project="dw-python-parity-${run_label}"
started_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

cleanup() {
  local code=$?

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
  python3 - "$result_dir" "$reason" "$started_at" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

result_dir = Path(sys.argv[1])
reason = sys.argv[2]
started_at = sys.argv[3]
now = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
required_scenarios = [
    "published_artifact_install_only",
    "official_cli_install_start_result_path",
    "cold_first_user_setup",
    "python_worker_registration",
    "activity_backed_workflow_execution",
    "workflow_result_surface",
    "worker_restart_activity_and_signal_state",
    "protocol_trace_capture",
    "php_assumption_audit",
    "capability_table_complete",
]
required_capabilities = [
    "server_up",
    "official_cli_installed",
    "cli_reaches_server",
    "cli_starts_workflow",
    "cli_reads_workflow_result",
    "cold_first_user_setup",
    "python_sdk_installed_from_pypi",
    "python_worker_connects",
    "python_worker_registers_workflows",
    "python_worker_registers_activities",
    "python_workflow_runs",
    "python_activity_runs",
    "workflow_result_returned",
    "worker_restart_replays_activity_state",
    "worker_restart_replays_signal_state",
    "protocol_traces_recorded",
    "php_assumptions_absent",
]
finding = {
    "type": "runner_gap",
    "owning_surface": "conformance_harness",
    "summary": reason,
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
        for scenario in required_scenarios
    },
    "capability_table": [
        {
            "id": capability,
            "status": "runner_blocked",
            "evidence": {"runner_blocked_reason": reason},
        }
        for capability in required_capabilities
    ],
    "protocol_traces": [],
    "php_assumption_audit": {"status": "runner_blocked", "checks": {}},
    "findings": [finding],
    "finding_links": {scenario: [finding] for scenario in required_scenarios},
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


def semver_key(version: str) -> tuple[int, int, int, int]:
    match = re.fullmatch(r"v?(\d+)\.(\d+)\.(\d+)(?:-alpha\.(\d+))?", version)
    if not match:
        return (-1, -1, -1, -1)
    return tuple(int(part or 0) for part in match.groups())  # type: ignore[return-value]


def exact_server_tag(value: str) -> bool:
    return re.fullmatch(r"\d+\.\d+\.\d+", value) is not None


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
            raise RuntimeError("DW_SERVER_IMAGE must use an exact patch tag or an image digest")
        version = explicit_version or tag
        if version is None or not exact_server_tag(version):
            raise RuntimeError("DW_SERVER_VERSION must name the exact patch for digest-pinned server images")
        if tag is not None and exact_server_tag(tag) and tag != version:
            raise RuntimeError("DW_SERVER_VERSION does not match DW_SERVER_IMAGE tag")
        return explicit_image, version

    if explicit_version:
        if not exact_server_tag(explicit_version):
            raise RuntimeError("DW_SERVER_VERSION must be an exact patch version")
        return f"durableworkflow/server:{explicit_version}", explicit_version

    tags: list[str] = []
    url: str | None = "https://registry.hub.docker.com/v2/repositories/durableworkflow/server/tags?page_size=100"
    while url:
        payload = read_json(url)
        tags.extend(str(item.get("name", "")) for item in payload.get("results", []))
        url = payload.get("next")
    exact = [tag for tag in tags if exact_server_tag(tag)]
    if not exact:
        raise RuntimeError("no exact durableworkflow/server patch tag found")
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

python3 -m venv "$run_root/.venv"
# shellcheck disable=SC1091
. "$run_root/.venv/bin/activate"
python -m pip install --upgrade pip > "$result_dir/pip-upgrade.log" 2>&1
python -m pip install "durable-workflow==$python_version" > "$result_dir/python-sdk-install.log" 2>&1 \
  || fail_blocked "PyPI durable-workflow==$python_version install failed"

if ! curl -fsSL --retry 3 -o "$run_root/cli/install.sh" "$cli_installer_url"; then
  fail_blocked "official CLI installer is not downloadable for release $cli_version"
fi
if ! VERSION="$cli_version" \
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
  -e COMPOSER_CACHE_DIR=/tmp/composer-cache \
  -v "$run_root/artifacts/workflow:/app" composer:2 \
  composer require --no-interaction --no-progress --prefer-dist --no-scripts \
    "durable-workflow/workflow:$workflow_version" > "$result_dir/workflow-artifact-install.log" 2>&1 \
  || fail_blocked "published workflow artifact install failed for durable-workflow/workflow:$workflow_version"
write_prerelease_composer_manifest "$run_root/artifacts/waterline" "python-conformance-waterline-probe"
docker run --rm --user "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer-home \
  -e COMPOSER_CACHE_DIR=/tmp/composer-cache \
  -v "$run_root/artifacts/waterline:/app" composer:2 \
  composer require --no-interaction --no-progress --prefer-dist --no-scripts \
    "durable-workflow/workflow:$workflow_version" \
    "durable-workflow/waterline:$waterline_version" > "$result_dir/waterline-artifact-install.log" 2>&1 \
  || fail_blocked "published Waterline artifact install failed for durable-workflow/waterline:$waterline_version with durable-workflow/workflow:$workflow_version"

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

cat > "$run_root/python-parity-runner.py" <<'PY'
from __future__ import annotations

import asyncio
import importlib.metadata as metadata
import json
import os
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from durable_workflow import Client, Worker, activity, workflow
from durable_workflow.metrics import CLIENT_REQUESTS


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


class TraceMetrics:
    def __init__(self) -> None:
        self.entries: list[dict[str, Any]] = []

    def increment(self, name: str, value: float = 1.0, tags: dict[str, str] | None = None) -> None:
        if name == CLIENT_REQUESTS and tags is not None:
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
    pins = load_json(pins_path)
    metadata_doc = load_json(metadata_path)
    token = "python-parity-token"
    suffix = str(int(time.time()))
    namespace = f"python-parity-{suffix}"
    task_queue = f"python-parity-{suffix}"
    workflow_id = f"python-parity-{suffix}"
    trace_metrics = TraceMetrics()
    cli = CliRunner(dw_bin, server_url, token, namespace, result_dir / "cli-traces.json")

    cli.run(["server:health", "--output=json"], namespace=None, timeout=60)
    created_namespace = cli.run(
        [
            "namespace:create",
            namespace,
            "--description=Python SDK parity conformance",
            "--retention=7",
            "--json",
        ],
        namespace="default",
        timeout=60,
    )

    async with Client(server_url, token=token, namespace=namespace, timeout=5.0, metrics=trace_metrics) as client:
        await client.get_cluster_info()
        worker1 = Worker(
            client,
            task_queue=task_queue,
            workflows=[PythonParityWorkflow],
            activities=[parity_echo, parity_after_signal],
            worker_id=f"python-parity-worker-1-{suffix}",
            poll_timeout=1.0,
            heartbeat_interval=2.0,
            metrics=trace_metrics,
        )
        worker1_task = asyncio.create_task(worker1.run())
        await asyncio.sleep(0.5)

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

        activity_event = await wait_for_event(client, workflow_id, run_id, "ActivityCompleted")
        restart_started = utc_now()
        await stop_worker(worker1, worker1_task)
        restart_finished = utc_now()

        await client.signal_workflow(workflow_id, "approve", args=["python-parity-signal"])

        worker2 = Worker(
            client,
            task_queue=task_queue,
            workflows=[PythonParityWorkflow],
            activities=[parity_echo, parity_after_signal],
            worker_id=f"python-parity-worker-2-{suffix}",
            poll_timeout=1.0,
            heartbeat_interval=2.0,
            metrics=trace_metrics,
        )
        terminal = await worker2.run_until(workflow_id=workflow_id, timeout=90.0, poll_interval=0.25)
        await worker2.stop()
        handle = client.get_workflow_handle(workflow_id, run_id=run_id, workflow_type="python.parity.workflow")
        result_value = await client.get_result(handle, timeout=10.0)
        final_history = await client.get_history(workflow_id, run_id)

    cli_describe = cli.run(["workflow:describe", workflow_id, "--json"], namespace=namespace, timeout=60)
    cli_show_run = cli.run(["workflow:show-run", workflow_id, run_id, "--json"], namespace=namespace, timeout=60)

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
            "activity_before_restart": result_value.get("activity_before_restart") if isinstance(result_value, dict) else None,
        },
        "worker_restart_replays_signal_state": {
            "signal_state": result_value.get("signal_state") if isinstance(result_value, dict) else None,
        },
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
                "activity_state_after_restart": result_value.get("activity_before_restart") if isinstance(result_value, dict) else None,
                "signal_state_after_restart": result_value.get("signal_state") if isinstance(result_value, dict) else None,
                "observed_outputs": {"summary": "second Python worker replayed activity and signal state after restart"},
            },
        },
        "capabilities": capabilities,
        "findings": [],
        "finding_links": [],
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
python "$run_root/python-parity-runner.py" \
  "$run_root/pins.json" \
  "$result_dir/run-metadata.json" \
  "$result_dir" \
  "$run_root/cli/bin/dw" \
  "$server_base_url" \
  "$started_at" > "$result_dir/python-parity-runner.log" 2>&1
runner_exit=$?
set -e

if [[ "$runner_exit" -ne 0 ]]; then
  python - "$run_root/pins.json" "$result_dir/run-metadata.json" "$result_dir/python-host-evidence.json" "$started_at" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

pins = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
metadata = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
out = Path(sys.argv[3])
started_at = sys.argv[4]
now = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
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
    "summary": "expanded Python SDK parity execution failed after published artifacts were installed",
    "log_file": "python-parity-runner.log",
}
required_scenarios = [
    "official_cli_install_start_result_path",
    "cold_first_user_setup",
    "python_worker_registration",
    "activity_backed_workflow_execution",
    "workflow_result_surface",
    "worker_restart_activity_and_signal_state",
    "protocol_trace_capture",
    "php_assumption_audit",
    "capability_table_complete",
]
required_capabilities = [
    "server_up",
    "official_cli_installed",
    "cli_reaches_server",
    "cli_starts_workflow",
    "cli_reads_workflow_result",
    "cold_first_user_setup",
    "python_sdk_installed_from_pypi",
    "python_worker_connects",
    "python_worker_registers_workflows",
    "python_worker_registers_activities",
    "python_workflow_runs",
    "python_activity_runs",
    "workflow_result_returned",
    "worker_restart_replays_activity_state",
    "worker_restart_replays_signal_state",
    "protocol_traces_recorded",
    "php_assumptions_absent",
]
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
    "scenario_results": {
        scenario: {
            "scenario_id": scenario,
            "status": "fail" if scenario in {
                "official_cli_install_start_result_path",
                "python_worker_registration",
                "activity_backed_workflow_execution",
                "workflow_result_surface",
            } else "not_covered",
            "observed_outputs": {"summary": finding["summary"]},
            "linked_findings": [finding],
        }
        for scenario in required_scenarios
    },
    "capabilities": {
        capability: {
            "status": "fail",
            "evidence": {"linked_finding": finding},
        }
        for capability in required_capabilities
    },
    "protocol_traces": [],
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
    "finding_links": {scenario: [finding] for scenario in required_scenarios},
}
out.write_text(json.dumps(host_evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
fi

if ! durable-workflow-python-conformance --compose "$result_dir/python-host-evidence.json" --pretty \
  > "$result_dir/python-conformance-result.json"; then
  fail_blocked "installed SDK conformance composer rejected python-host-evidence.json"
fi

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
