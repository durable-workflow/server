#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
  cat <<'USAGE'
Usage: signals-queries-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Writes a source-free signals/queries conformance split-out result.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  signals-queries-result.json
  signals-queries-record.json
  signals-queries-findings.json

Environment overrides:
  DW_SERVER_VERSION                         Published server version under test.
  DW_SERVER_IMAGE                           Optional server image for compose. Only exact
                                             durableworkflow/server tags or digest-pinned
                                             references matching DW_SERVER_VERSION prove
                                             published install evidence.
  DW_CLI_VERSION                            Published CLI version under test.
  DW_PYTHON_SDK_VERSION                     Published Python SDK version under test.
  DW_WORKFLOW_PHP_VERSION                   Published PHP workflow version under test.
  DW_WATERLINE_VERSION                      Published Waterline version under test.
  DW_SIGNALS_QUERIES_RESULT_DIR             Result directory when --result-dir is omitted.
  DW_SIGNALS_QUERIES_EVIDENCE               Optional JSON evidence from a real matrix run.
  DW_SIGNALS_QUERIES_SMOKE_EVIDENCE         Deprecated alias for DW_SIGNALS_QUERIES_EVIDENCE.
  DW_SIGNALS_QUERIES_RUN_BASELINE_PROBE     Set to 0 to skip the live order/dedup/unknown shard.
  DW_SIGNALS_QUERIES_RUN_PHP_BASELINE_PROBE Set to 0 to skip the live PHP worker mirror shard.
  DW_SIGNALS_QUERIES_RUN_ADVERSARIAL_PROBE  Set to 0 to skip the live malformed/unknown error shard.
  DW_SIGNALS_QUERIES_RUN_REPLAY_TERMINAL_PROBE
                                             Set to 0 to skip the live replay/terminal shard.
  DW_SIGNALS_QUERIES_RUN_WATERLINE_OBSERVER_PROBE
                                             Set to 0 to skip the published Waterline observer shard.
  DW_SIGNALS_QUERIES_SERVER_URL             Reuse an already-running published server for the adversarial shard.
  DW_SIGNALS_QUERIES_SERVER_CONNECT_HOST    Preferred host/address to probe for a self-started published server.
  DW_SIGNALS_QUERIES_SERVER_READY_TIMEOUT_SECONDS
                                             Host /api/ready timeout for published-server probes.
  DW_SIGNALS_QUERIES_AUTH_TOKEN             Bearer token for the adversarial shard. Defaults to dev-token.
  DW_SIGNALS_QUERIES_NAMESPACE              Namespace for the adversarial shard. Defaults to default.
  DW_SIGNALS_QUERIES_CLI_BIN                Optional configured dw binary path; does not prove published install.
  DW_SIGNALS_QUERIES_PYTHON                 Optional configured Python executable; does not prove published install.
  DW_SIGNALS_QUERIES_PHP_DOCKER_IMAGE       Docker image used to install and run the published PHP package.
                                             Defaults to composer:2.
  DW_SIGNALS_QUERIES_KEEP_RUN_ROOT          Set to 1 to keep the adversarial shard scratch directory.
USAGE
}

result_dir="${DW_SIGNALS_QUERIES_RESULT_DIR:-}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --result-dir)
      result_dir="${2:?--result-dir requires a value}"
      shift 2
      ;;
    --result-dir=*)
      result_dir="${1#--result-dir=}"
      if [[ -z "$result_dir" ]]; then
        printf '%s\n' '--result-dir requires a value' >&2
        usage >&2
        exit 2
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

if [[ -z "$result_dir" ]]; then
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-signals-queries.XXXXXX")"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

started_at="$(timestamp)"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"

RESULT_DIR="$result_dir" \
STARTED_AT="$started_at" \
REPO_ROOT="$repo_root" \
DW_SERVER_VERSION="${DW_SERVER_VERSION:-unresolved}" \
DW_CLI_VERSION="${DW_CLI_VERSION:-unresolved}" \
DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-unresolved}" \
DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-unresolved}" \
DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-unresolved}" \
DW_SIGNALS_QUERIES_EVIDENCE="${DW_SIGNALS_QUERIES_EVIDENCE:-${DW_SIGNALS_QUERIES_SMOKE_EVIDENCE:-}}" \
DW_SIGNALS_QUERIES_SMOKE_EVIDENCE="${DW_SIGNALS_QUERIES_SMOKE_EVIDENCE:-}" \
python3 - <<'PY'
from __future__ import annotations

import base64
import hashlib
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="microseconds").replace("+00:00", "Z")


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def env_text(name: str) -> str | None:
    value = os.environ.get(name)
    if value is None:
        return None
    value = value.strip()
    return value or None


def env_flag(name: str, default: bool) -> bool:
    value = env_text(name)
    if value is None:
        return default
    return value.lower() not in {"0", "false", "no", "off"}


def log_line(log_file: Path, message: str) -> None:
    with log_file.open("a", encoding="utf-8") as handle:
        handle.write(f"{now()} {message}\n")


def free_port() -> int:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def url_join(base_url: str, path: str) -> str:
    return base_url.rstrip("/") + "/" + path.lstrip("/")


def api_path(*parts: str) -> str:
    return "/api/" + "/".join(urllib.parse.quote(part, safe="._:-") for part in parts)


def http_json(
    base_url: str,
    path: str,
    *,
    method: str = "GET",
    body: Any = None,
    token: str,
    namespace: str,
    worker: bool = False,
    timeout: float = 30.0,
) -> dict[str, Any]:
    data = None
    headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "Authorization": f"Bearer {token}",
        "X-Namespace": namespace,
    }
    if worker:
        headers["X-Durable-Workflow-Protocol-Version"] = "1.12"
    else:
        headers["X-Durable-Workflow-Control-Plane-Version"] = "2"

    if body is not None:
        data = json.dumps(body).encode("utf-8")

    request = urllib.request.Request(
        url_join(base_url, path),
        data=data,
        headers=headers,
        method=method,
    )

    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8")
            return {
                "status_code": response.status,
                "body": json.loads(raw) if raw.strip() else {},
            }
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        try:
            body_value = json.loads(raw) if raw.strip() else {}
        except json.JSONDecodeError:
            body_value = {"raw": raw}
        return {
            "status_code": exc.code,
            "body": body_value,
        }


def response_sample(response: dict[str, Any]) -> dict[str, Any]:
    body = response.get("body")
    if not isinstance(body, dict):
        body = {}

    sample: dict[str, Any] = {
        "status_code": response.get("status_code"),
        "reason": body.get("reason"),
    }

    for key in (
        "message",
        "rejection_reason",
        "outcome",
        "command_status",
        "validation_errors",
        "errors",
        "workflow_id",
        "run_id",
        "signal_name",
        "query_name",
        "command_contract_source",
        "command_contract_backfill_needed",
        "command_contract_backfill_available",
        "declared_signals",
        "signal_admission",
    ):
        if key in body:
            sample[key] = body[key]

    return sample


def command_contract() -> dict[str, Any]:
    int_parameter = {
        "name": "amount",
        "position": 0,
        "required": True,
        "variadic": False,
        "default_available": False,
        "default": None,
        "type": "int",
        "allows_null": False,
    }
    minimum_parameter = dict(int_parameter)
    minimum_parameter["name"] = "minimum"

    return {
        "queries": ["count-at-least", "current", "state"],
        "query_contracts": [
            {"name": "current", "parameters": []},
            {"name": "state", "parameters": []},
            {"name": "count-at-least", "parameters": [minimum_parameter]},
        ],
        "signals": ["increment"],
        "signal_contracts": [
            {"name": "increment", "parameters": [int_parameter]},
        ],
        "updates": [],
        "update_contracts": [],
    }


def command_available(command_name: str) -> bool:
    return shutil.which(command_name) is not None


def run_command(
    command: list[str],
    *,
    log_file: Path,
    env: dict[str, str] | None = None,
    cwd: Path | None = None,
    timeout: float = 120.0,
) -> subprocess.CompletedProcess[str]:
    log_line(log_file, "run " + " ".join(command))
    completed = subprocess.run(
        command,
        cwd=str(cwd) if cwd is not None else None,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        timeout=timeout,
        check=False,
    )
    if completed.stdout:
        log_line(log_file, "stdout " + completed.stdout.strip())
    if completed.stderr:
        log_line(log_file, "stderr " + completed.stderr.strip())
    return completed


class ServerReadinessTopologyError(RuntimeError):
    def __init__(self, message: str, details: dict[str, Any]):
        super().__init__(message)
        self.details = details


def env_float(name: str, default: float) -> float:
    value = env_text(name)
    if value is None:
        return default
    try:
        parsed = float(value)
    except ValueError:
        return default
    if parsed <= 0:
        return default
    return parsed


def diagnostic_path(value: str) -> str:
    repo_root = os.environ.get("REPO_ROOT", "").rstrip(os.sep)
    if repo_root and value.startswith(repo_root + os.sep):
        return value[len(repo_root) + 1:]
    return value


def diagnostic_command(command: list[str]) -> list[str]:
    return [diagnostic_path(part) for part in command]


def command_summary(command: list[str], completed: subprocess.CompletedProcess[str]) -> dict[str, Any]:
    return {
        "command": diagnostic_command(command),
        "exit_code": completed.returncode,
        "stdout": completed.stdout.strip(),
        "stderr": completed.stderr.strip(),
    }


def capture_command_summary(
    command: list[str],
    *,
    log_file: Path,
    env: dict[str, str] | None = None,
    timeout: float = 30.0,
) -> dict[str, Any]:
    try:
        return command_summary(
            command,
            run_command(command, log_file=log_file, env=env, timeout=timeout),
        )
    except Exception as exc:  # noqa: BLE001 - diagnostic capture must not hide the readiness failure.
        log_line(log_file, f"diagnostic command failed: {' '.join(command)}: {type(exc).__name__}: {exc}")
        return {
            "command": diagnostic_command(command),
            "error": f"{type(exc).__name__}: {exc}",
        }


def server_ready_timeout_seconds(default: float) -> float:
    return env_float("DW_SIGNALS_QUERIES_SERVER_READY_TIMEOUT_SECONDS", default)


def ordered_unique(values: list[str]) -> list[str]:
    seen: list[str] = []
    for value in values:
        normalized = value.strip().rstrip("/")
        if normalized and normalized not in seen:
            seen.append(normalized)
    return seen


def is_wildcard_host(host: str | None) -> bool:
    if host is None:
        return True
    normalized = host.strip().strip("[]")
    return normalized in {"", "0.0.0.0", "::", "*"}


def host_port_url(host: str, port: int) -> str | None:
    host = host.strip().strip("[]")
    if not host or port <= 0:
        return None
    if ":" in host:
        return f"http://[{host}]:{port}"
    return f"http://{host}:{port}"


def docker_host_from_env() -> str | None:
    value = env_text("DOCKER_HOST")
    if value is None:
        return None
    if value.startswith(("tcp://", "http://", "https://")):
        value = value.split("://", 1)[1].split("/", 1)[0]
        if value.startswith("[") and "]" in value:
            value = value[1:].split("]", 1)[0]
        else:
            value = value.rsplit(":", 1)[0]
        if value not in {"127.0.0.1", "localhost"}:
            return value
    return None


def default_route_gateway() -> str | None:
    try:
        with Path("/proc/net/route").open("r", encoding="utf-8") as route_file:
            next(route_file, None)
            for line in route_file:
                fields = line.strip().split()
                if len(fields) < 3 or fields[1] != "00000000" or fields[2] == "00000000":
                    continue
                return socket.inet_ntoa(bytes.fromhex(fields[2])[::-1])
    except OSError:
        return None
    return None


def docker_bridge_gateway(log_file: Path, env: dict[str, str] | None = None) -> str | None:
    try:
        completed = run_command(
            ["docker", "network", "inspect", "bridge", "--format", "{{(index .IPAM.Config 0).Gateway}}"],
            log_file=log_file,
            env=env,
            timeout=30,
        )
    except Exception as exc:  # noqa: BLE001 - best-effort topology diagnostics.
        log_line(log_file, f"docker bridge gateway discovery failed: {type(exc).__name__}: {exc}")
        return None

    value = completed.stdout.strip()
    if completed.returncode == 0 and value and value != "<no value>":
        return value
    return None


def server_url_candidates_for_port(
    port: int,
    *,
    bind_host: str | None = None,
    log_file: Path | None = None,
    env: dict[str, str] | None = None,
) -> list[str]:
    candidates: list[str] = []
    preferred_host = env_text("DW_SIGNALS_QUERIES_SERVER_CONNECT_HOST") or "127.0.0.1"

    for host in (preferred_host, "127.0.0.1", "localhost"):
        candidate = host_port_url(host, port)
        if candidate is not None:
            candidates.append(candidate)

    if not is_wildcard_host(bind_host) and bind_host not in {"127.0.0.1", "localhost"}:
        candidate = host_port_url(str(bind_host), port)
        if candidate is not None:
            candidates.append(candidate)

    for host in (
        env_text("DW_SIGNALS_QUERIES_DOCKER_HOST_GATEWAY"),
        env_text("DOCKER_HOST_GATEWAY"),
        env_text("HOST_DOCKER_INTERNAL"),
        docker_host_from_env(),
        default_route_gateway(),
    ):
        if host:
            candidate = host_port_url(host, port)
            if candidate is not None:
                candidates.append(candidate)

    if log_file is not None:
        gateway = docker_bridge_gateway(log_file, env)
        if gateway:
            candidate = host_port_url(gateway, port)
            if candidate is not None:
                candidates.append(candidate)

    for host in ("host.docker.internal", "gateway.docker.internal"):
        candidate = host_port_url(host, port)
        if candidate is not None:
            candidates.append(candidate)

    return ordered_unique(candidates)


def parse_host_port_binding(line: str) -> tuple[str, int] | None:
    binding = line.strip().split(maxsplit=1)[0] if line.strip() else ""
    if not binding:
        return None

    if binding.startswith("[") and "]:" in binding:
        host, port_text = binding[1:].split("]:", 1)
    else:
        host, separator, port_text = binding.rpartition(":")
        if not separator:
            return None

    if not port_text.isdigit():
        return None

    return host.strip("[]"), int(port_text)


def server_url_candidates_from_published_port(
    published_port_output: str,
    *,
    fallback_port: int,
    log_file: Path,
    env: dict[str, str],
) -> list[str]:
    candidates: list[str] = []
    for line in published_port_output.splitlines():
        parsed = parse_host_port_binding(line)
        if parsed is None:
            continue
        bind_host, mapped_port = parsed
        candidates.extend(
            server_url_candidates_for_port(
                mapped_port,
                bind_host=bind_host,
                log_file=log_file,
                env=env,
            )
        )

    if not candidates:
        candidates.extend(server_url_candidates_for_port(fallback_port, log_file=log_file, env=env))

    return ordered_unique(candidates)


def readiness_error_summary(errors: dict[str, str], candidates: list[str]) -> str:
    if not errors:
        return "no response before timeout"
    return " | ".join(
        f"{candidate}: {errors.get(candidate, 'no response before timeout')}"
        for candidate in candidates
    )


def wait_for_ready(
    base_url: str | list[str],
    log_file: Path,
    timeout_seconds: float = 90.0,
    diagnostics: dict[str, Any] | None = None,
) -> dict[str, Any]:
    candidates = ordered_unique([base_url] if isinstance(base_url, str) else base_url)
    if not candidates:
        candidates = ["http://127.0.0.1:8080"]

    deadline = time.time() + timeout_seconds
    details: dict[str, Any] = {
        "kind": "server_readiness_topology",
        "effective_host_endpoint": candidates[0],
        "ready_url": url_join(candidates[0], "/api/ready"),
        "ready_urls": [url_join(candidate, "/api/ready") for candidate in candidates],
        "server_url_candidates": candidates,
        "timeout_seconds": timeout_seconds,
        "readiness_attempts": 0,
        "last_readiness_error": None,
        "candidate_readiness_errors": {},
    }
    if diagnostics:
        details.update(diagnostics)
        details["kind"] = "server_readiness_topology"
        details["effective_host_endpoint"] = candidates[0]
        details["ready_url"] = url_join(candidates[0], "/api/ready")
        details["ready_urls"] = [url_join(candidate, "/api/ready") for candidate in candidates]
        details["server_url_candidates"] = candidates
        details.setdefault("candidate_readiness_errors", {})

    candidate_errors: dict[str, str] = {}
    candidate_status_codes: dict[str, int] = {}

    while time.time() < deadline:
        for candidate in candidates:
            if time.time() >= deadline:
                break
            ready_url = url_join(candidate, "/api/ready")
            details["readiness_attempts"] = int(details["readiness_attempts"]) + 1
            details["effective_host_endpoint"] = candidate
            details["ready_url"] = ready_url
            try:
                request_timeout = min(2, max(0.2, deadline - time.time()))
                with urllib.request.urlopen(ready_url, timeout=request_timeout) as response:
                    details["last_readiness_status_code"] = response.status
                    candidate_status_codes[candidate] = response.status
                    if 200 <= response.status < 300:
                        details["ready_at"] = now()
                        details["candidate_readiness_errors"] = dict(candidate_errors)
                        details["candidate_readiness_status_codes"] = dict(candidate_status_codes)
                        log_line(log_file, f"published server ready at {ready_url}")
                        return details
                    candidate_errors[candidate] = f"HTTPStatus: {response.status}"
            except urllib.error.HTTPError as exc:
                details["last_readiness_status_code"] = exc.code
                candidate_status_codes[candidate] = exc.code
                body = exc.read().decode("utf-8", errors="replace")
                candidate_errors[candidate] = f"HTTPError: {exc.code} {body[:500]}"
            except Exception as exc:  # noqa: BLE001 - diagnostic best effort for conformance logs.
                candidate_errors[candidate] = f"{type(exc).__name__}: {exc}"

            details["candidate_readiness_errors"] = dict(candidate_errors)
            details["candidate_readiness_status_codes"] = dict(candidate_status_codes)
            details["last_readiness_error"] = readiness_error_summary(candidate_errors, candidates)
            log_line(log_file, f"readiness probe failed at {ready_url}: {candidate_errors[candidate]}")
        time.sleep(min(1, max(0, deadline - time.time())))

    if not details.get("last_readiness_error"):
        details["last_readiness_error"] = "readiness probe did not run before timeout"
    else:
        details["last_readiness_error"] = readiness_error_summary(candidate_errors, candidates)
    details["candidate_readiness_errors"] = dict(candidate_errors)
    details["candidate_readiness_status_codes"] = dict(candidate_status_codes)

    raise ServerReadinessTopologyError(
        "published server did not become ready from host endpoints "
        f"{', '.join(candidates)}: {details['last_readiness_error']}",
        details,
    )


SERVER_PATCH_TAG_RE = re.compile(r"^\d+\.\d+\.\d+$")
PUBLISHED_SERVER_IMAGE_REPOSITORIES = {
    "durableworkflow/server",
    "docker.io/durableworkflow/server",
    "index.docker.io/durableworkflow/server",
    "registry-1.docker.io/durableworkflow/server",
}


def normalize_docker_image_reference(image: str) -> str:
    return image.strip().removeprefix("docker://")


def server_repository_from_image(image: str) -> str:
    image = normalize_docker_image_reference(image)
    without_digest = image.split("@", 1)[0]
    tail = without_digest.rsplit("/", 1)[-1]
    if ":" in tail:
        without_digest = without_digest.rsplit(":", 1)[0]
    return without_digest


def server_tag_from_image(image: str) -> str | None:
    image = normalize_docker_image_reference(image)
    without_digest = image.split("@", 1)[0]
    tail = without_digest.rsplit("/", 1)[-1]
    if ":" not in tail:
        return None
    return tail.rsplit(":", 1)[1]


def is_exact_server_patch_tag(version: str) -> bool:
    return SERVER_PATCH_TAG_RE.match(version.strip()) is not None


def is_digest_pinned_server_image(image: str) -> bool:
    image = normalize_docker_image_reference(image)
    if "@" not in image:
        return False
    digest = image.rsplit("@", 1)[1]
    return re.match(r"^sha256:[0-9a-fA-F]{64}$", digest) is not None


def published_server_image_install_proven(image: str, version: str) -> bool:
    image = normalize_docker_image_reference(image)
    version = version.strip()
    if not is_exact_server_patch_tag(version):
        return False
    if server_repository_from_image(image) not in PUBLISHED_SERVER_IMAGE_REPOSITORIES:
        return False

    tag = server_tag_from_image(image)
    if is_digest_pinned_server_image(image):
        return tag is None or not is_exact_server_patch_tag(tag) or tag == version

    if tag is not None:
        if not is_exact_server_patch_tag(tag) or tag != version:
            return False
        return True

    return False


def server_image_not_proved_reason(image: str, version: str) -> str:
    image = normalize_docker_image_reference(image)
    version = version.strip()
    if not is_exact_server_patch_tag(version):
        return "DW_SERVER_VERSION must be an exact patch semver Docker tag"
    if server_repository_from_image(image) not in PUBLISHED_SERVER_IMAGE_REPOSITORIES:
        return "DW_SERVER_IMAGE is not a durableworkflow/server published image reference"

    tag = server_tag_from_image(image)
    if "@" in image and not is_digest_pinned_server_image(image):
        return "DW_SERVER_IMAGE digest must be a sha256 digest-pinned reference"

    if tag is None:
        return "DW_SERVER_IMAGE must use an exact patch semver tag or an image digest"

    if tag is not None:
        if not is_exact_server_patch_tag(tag) and not is_digest_pinned_server_image(image):
            return "DW_SERVER_IMAGE must use an exact patch semver tag or an image digest"
        if tag != version:
            return f"DW_SERVER_VERSION {version!r} does not match DW_SERVER_IMAGE tag {tag!r}"

    return (
        "DW_SERVER_IMAGE must be an exact durableworkflow/server tag or digest-pinned reference "
        "matching DW_SERVER_VERSION to prove published server install evidence"
    )


def server_image_for_compose(server_version: str) -> str:
    explicit = env_text("DW_SERVER_IMAGE")
    if explicit:
        return normalize_docker_image_reference(explicit)
    return f"durableworkflow/server:{server_version}"


def compose_published_server_diagnostics(
    *,
    project: str,
    compose: Path,
    env: dict[str, str],
    base_url: str,
    port: int,
    image: str,
    cleanup_command: list[str],
    log_file: Path,
) -> dict[str, Any]:
    compose_prefix = ["docker", "compose", "-p", project, "-f", str(compose)]
    compose_published_port = capture_command_summary(
        [*compose_prefix, "port", "server", "8080"],
        log_file=log_file,
        env=env,
        timeout=30,
    )
    mapped_port = str(compose_published_port.get("stdout") or "").strip()
    port_state = "reported" if mapped_port else "not_reported"
    if compose_published_port.get("exit_code") not in (0, None):
        port_state = "command_failed"
    if compose_published_port.get("error"):
        port_state = "command_error"
    server_url_candidates = server_url_candidates_from_published_port(
        mapped_port,
        fallback_port=port,
        log_file=log_file,
        env=env,
    )

    return {
        "kind": "server_readiness_topology",
        "effective_host_endpoint": base_url,
        "compose_project": project,
        "compose_file": diagnostic_path(str(compose)),
        "compose_server_port": port,
        "mapped_server_port": mapped_port or None,
        "published_port_state": port_state,
        "server_url_candidates": server_url_candidates,
        "server_image": image,
        "cleanup_commands": [cleanup_command],
        "compose_published_port": compose_published_port,
        "compose_ps": capture_command_summary(
            [*compose_prefix, "ps"],
            log_file=log_file,
            env=env,
            timeout=30,
        ),
        "docker_containers": capture_command_summary(
            ["docker", "container", "ls", "-a", "--filter", f"label=com.docker.compose.project={project}"],
            log_file=log_file,
            env=env,
            timeout=30,
        ),
    }


def configured_server_diagnostics(base_url: str) -> dict[str, Any]:
    return {
        "kind": "server_readiness_topology",
        "effective_host_endpoint": base_url.rstrip("/"),
        "endpoint_source": "configured_server_url",
    }


def cleanup_commands_from_blocker(details: dict[str, Any]) -> list[list[str]]:
    commands = details.get("cleanup_commands")
    if not isinstance(commands, list):
        return []

    normalized: list[list[str]] = []
    for command in commands:
        if not isinstance(command, list):
            continue
        normalized_command = [str(part) for part in command if isinstance(part, str)]
        if normalized_command:
            normalized.append(normalized_command)

    return normalized


def start_published_server(run_root: Path, log_file: Path) -> tuple[str, list[list[str]], dict[str, Any]]:
    if not command_available("docker"):
        raise RuntimeError("docker is required to start the published server")

    compose = Path(os.environ.get("REPO_ROOT", os.getcwd())) / "docker-compose.published.yml"
    if not compose.is_file():
        raise RuntimeError(f"published compose file not found: {compose}")

    server_version = artifact_version_value(artifact_versions, "server")
    if is_placeholder_version(server_version):
        raise RuntimeError("DW_SERVER_VERSION must be a concrete published server version")

    port = int(env_text("DW_SIGNALS_QUERIES_SERVER_PORT") or free_port())
    token = env_text("DW_SIGNALS_QUERIES_AUTH_TOKEN") or env_text("DURABLE_WORKFLOW_AUTH_TOKEN") or "dev-token"
    project = "dw-signals-queries-" + run_root.name.lower().replace(".", "-").replace("_", "-")
    env = os.environ.copy()
    image = server_image_for_compose(server_version)
    env.update(
        {
            "SERVER_PORT": str(port),
            "DW_SERVER_TAG": server_version,
            "DW_SERVER_IMAGE": image,
            "DW_AUTH_TOKEN": token,
            "DW_AUTH_BACKWARD_COMPATIBLE": "true",
        }
    )

    commands = [
        ["docker", "compose", "-p", project, "-f", str(compose), "down", "-v"],
        ["docker", "compose", "-p", project, "-f", str(compose), "up", "-d", "--wait", "server"],
    ]
    cleanup_command = commands[0]

    run_command(commands[0], log_file=log_file, env=env, timeout=120)
    up = run_command(commands[1], log_file=log_file, env=env, timeout=240)
    if up.returncode != 0:
        raise RuntimeError("docker compose failed to start the published server")

    base_url = f"http://127.0.0.1:{port}"
    compose_diagnostics = compose_published_server_diagnostics(
        project=project,
        compose=compose,
        env=env,
        base_url=base_url,
        port=port,
        image=image,
        cleanup_command=cleanup_command,
        log_file=log_file,
    )
    try:
        server_url_candidates = [
            str(candidate)
            for candidate in compose_diagnostics.get("server_url_candidates", [])
            if isinstance(candidate, str)
        ] or [base_url]
        readiness = wait_for_ready(
            server_url_candidates,
            log_file,
            timeout_seconds=server_ready_timeout_seconds(90),
            diagnostics=compose_diagnostics,
        )
        base_url = str(readiness.get("effective_host_endpoint") or base_url).rstrip("/")
    except ServerReadinessTopologyError as exc:
        details = dict(compose_diagnostics)
        details.update(exc.details)
        raise ServerReadinessTopologyError(str(exc), details) from exc

    return base_url, [cleanup_command], readiness


def artifact_install_evidence_entry(
    *,
    artifact: str,
    version: str,
    source: str,
    status: str,
    install_method: str,
    installed_from_public_artifact: bool,
) -> dict[str, Any]:
    return {
        "artifact": artifact,
        "status": status,
        "version": version,
        "source": source,
        "install_method": install_method,
        "installed_from_public_artifact": installed_from_public_artifact,
        "local_product_source_checkouts_used": False,
    }


def configured_artifact_entry(artifact: str, version: str, source: str, install_method: str) -> dict[str, Any]:
    return artifact_install_evidence_entry(
        artifact=artifact,
        version=version,
        source=source,
        status="not_proved",
        install_method=install_method,
        installed_from_public_artifact=False,
    )


def installed_public_artifact_entry(artifact: str, version: str, source: str, install_method: str) -> dict[str, Any]:
    return artifact_install_evidence_entry(
        artifact=artifact,
        version=version,
        source=source,
        status="pass",
        install_method=install_method,
        installed_from_public_artifact=True,
    )


def server_install_entry(cleanup_commands: list[list[str]]) -> dict[str, Any]:
    version = artifact_version_value(artifact_versions, "server")
    if cleanup_commands:
        image = server_image_for_compose(version)
        if published_server_image_install_proven(image, version):
            entry = installed_public_artifact_entry(
                "server",
                version,
                EXPECTED_ARTIFACT_SOURCES["server"],
                "docker_compose_published_image",
            )
            entry["image"] = image
            entry["image_provenance"] = "durableworkflow_server_exact_tag_or_digest"
            return entry

        entry = configured_artifact_entry(
            "server",
            version,
            "configured_server_image",
            "docker_compose_configured_image_override",
        )
        entry["image"] = image
        entry["not_proved_reason"] = server_image_not_proved_reason(image, version)
        return entry

    return configured_artifact_entry(
        "server",
        version,
        "configured_server_endpoint",
        "configured_server_url",
    )


def install_cli(run_root: Path, log_file: Path) -> tuple[str, dict[str, Any]]:
    cli_version = artifact_version_value(artifact_versions, "cli")
    explicit = env_text("DW_SIGNALS_QUERIES_CLI_BIN") or env_text("DW_CLI_BIN")
    if explicit:
        if Path(explicit).is_file() and os.access(explicit, os.X_OK):
            return explicit, configured_artifact_entry(
                "cli",
                cli_version,
                "configured_cli_binary",
                "configured_cli_binary_override",
            )
        raise RuntimeError(f"configured CLI binary is not executable: {explicit}")

    if is_placeholder_version(cli_version):
        raise RuntimeError("DW_CLI_VERSION must be concrete to install the public CLI")

    cli_root = run_root / "cli"
    bin_dir = cli_root / "bin"
    cli_root.mkdir(parents=True, exist_ok=True)
    bin_dir.mkdir(parents=True, exist_ok=True)

    tags = [cli_version]
    if not cli_version.startswith("v"):
        tags.append(f"v{cli_version}")

    installer = cli_root / "install.sh"
    errors: list[str] = []
    for tag in tags:
        url = f"https://github.com/durable-workflow/cli/releases/download/{tag}/install.sh"
        try:
            with urllib.request.urlopen(url, timeout=30) as response:
                installer.write_bytes(response.read())
            break
        except Exception as exc:  # noqa: BLE001 - try both public tag spellings.
            errors.append(f"{url}: {type(exc).__name__}: {exc}")
    else:
        raise RuntimeError("official CLI installer is not downloadable: " + "; ".join(errors))

    installer.chmod(0o755)
    env = os.environ.copy()
    env.update(
        {
            "VERSION": cli_version,
            "DURABLE_WORKFLOW_INSTALL_DIR": str(bin_dir),
            "DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS": "0",
        }
    )
    install = run_command(["sh", str(installer)], log_file=log_file, env=env, timeout=180)
    if install.returncode != 0:
        raise RuntimeError("official CLI installer failed")

    binary = bin_dir / "dw"
    if not binary.is_file() or not os.access(binary, os.X_OK):
        raise RuntimeError("official CLI installer did not create an executable dw binary")

    return str(binary), installed_public_artifact_entry(
        "cli",
        cli_version,
        EXPECTED_ARTIFACT_SOURCES["cli"],
        "github_release_installer",
    )


def cli_json_sample(
    cli_bin: str,
    base_url: str,
    token: str,
    namespace: str,
    command: list[str],
    log_file: Path,
) -> dict[str, Any]:
    env = os.environ.copy()
    env.update(
        {
            "DURABLE_WORKFLOW_SERVER_URL": base_url,
            "DURABLE_WORKFLOW_AUTH_TOKEN": token,
            "DURABLE_WORKFLOW_NAMESPACE": namespace,
            "DURABLE_WORKFLOW_TLS_VERIFY": "false",
        }
    )
    completed = run_command([cli_bin, *command], log_file=log_file, env=env, timeout=60)
    output = completed.stdout.strip()
    decoded: dict[str, Any] = {}
    if output:
        try:
            decoded = json.loads(output)
        except json.JSONDecodeError:
            decoded = {"raw_stdout": output}

    return {
        "command": "dw " + " ".join(command),
        "exit_code": completed.returncode,
        "status_code": decoded.get("status_code"),
        "reason": decoded.get("reason"),
        "validation_errors": decoded.get("validation_errors"),
        "server_response": decoded.get("server_response"),
        "output": decoded,
    }


def ensure_python_sdk(run_root: Path, log_file: Path) -> tuple[str, dict[str, Any]]:
    sdk_version = artifact_version_value(artifact_versions, "sdk-python")
    explicit = env_text("DW_SIGNALS_QUERIES_PYTHON")
    if explicit:
        return explicit, configured_artifact_entry(
            "sdk-python",
            sdk_version,
            "configured_python_environment",
            "configured_python_executable_override",
        )

    if is_placeholder_version(sdk_version):
        raise RuntimeError("DW_PYTHON_SDK_VERSION must be concrete to install the public Python SDK")

    venv_dir = run_root / "python-sdk"
    create = run_command([sys.executable, "-m", "venv", str(venv_dir)], log_file=log_file, timeout=120)
    if create.returncode != 0:
        raise RuntimeError("could not create Python SDK virtual environment")

    python_bin = venv_dir / "bin" / "python"
    pip = run_command([str(python_bin), "-m", "pip", "install", "--upgrade", "pip"], log_file=log_file, timeout=180)
    if pip.returncode != 0:
        raise RuntimeError("could not upgrade pip in Python SDK virtual environment")

    install = run_command(
        [str(python_bin), "-m", "pip", "install", f"durable-workflow=={sdk_version}"],
        log_file=log_file,
        timeout=240,
    )
    if install.returncode != 0:
        raise RuntimeError("could not install the public Python SDK artifact")

    return str(python_bin), installed_public_artifact_entry(
        "sdk-python",
        sdk_version,
        EXPECTED_ARTIFACT_SOURCES["sdk-python"],
        "pypi_package_install",
    )


def python_sdk_distribution_version(python_bin: str, log_file: Path) -> str:
    code = r'''
from importlib.metadata import PackageNotFoundError
from importlib.metadata import version

try:
    print(version("durable-workflow"))
except PackageNotFoundError:
    print("")
'''
    completed = run_command([python_bin, "-c", code], log_file=log_file, timeout=30)
    return completed.stdout.strip()


def workflow_php_docker_image() -> str:
    return env_text("DW_SIGNALS_QUERIES_PHP_DOCKER_IMAGE") or "composer:2"


def docker_volume_spec(path: Path, container_path: str = "/app") -> str:
    return f"{path}:{container_path}"


def docker_host_base_url(base_url: str) -> str:
    parsed = urllib.parse.urlparse(base_url)
    host = parsed.hostname
    if host not in {"127.0.0.1", "localhost"}:
        return base_url.rstrip("/")

    port = f":{parsed.port}" if parsed.port is not None else ""
    netloc = f"host.docker.internal{port}"
    if parsed.username or parsed.password:
        auth = parsed.username or ""
        if parsed.password:
            auth += f":{parsed.password}"
        netloc = f"{auth}@{netloc}"

    return urllib.parse.urlunparse(
        (
            parsed.scheme or "http",
            netloc,
            parsed.path.rstrip("/"),
            "",
            parsed.query,
            parsed.fragment,
        )
    ).rstrip("/")


def php_docker_command(
    project_dir: Path,
    args: list[str],
    *,
    name: str | None = None,
    detach: bool = False,
    env: dict[str, str] | None = None,
) -> list[str]:
    command = ["docker", "run"]
    if detach:
        if not name:
            raise RuntimeError("detached PHP docker command requires a container name")
        command.extend(["-d", "--name", name])
    else:
        command.append("--rm")

    command.extend(
        [
            "--add-host",
            "host.docker.internal:host-gateway",
            "-v",
            docker_volume_spec(project_dir),
            "-w",
            "/app",
        ]
    )
    for key, value in sorted((env or {}).items()):
        command.extend(["-e", f"{key}={value}"])
    command.append(workflow_php_docker_image())
    command.extend(args)
    return command


def write_workflow_php_project(project_dir: Path) -> None:
    workflow_version = artifact_version_value(artifact_versions, "workflow-php")
    write_json(
        project_dir / "composer.json",
        {
            "require": {
                "durable-workflow/workflow": workflow_version,
            },
            "minimum-stability": "dev",
            "prefer-stable": True,
            "config": {
                "preferred-install": "dist",
                "sort-packages": True,
                "allow-plugins": {
                    "php-http/discovery": True,
                },
            },
        },
    )

    (project_dir / "php-counter-worker.php").write_text(
        r'''<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Composer\InstalledVersions;
use Illuminate\Http\Client\Factory as HttpFactory;
use ReflectionMethod;
use Throwable;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Worker\StandaloneWorkflowWorker;
use Workflow\V2\Worker\WorkerProtocolClient;
use Workflow\V2\Worker\WorkflowFiberRunner;
use Workflow\V2\Worker\WorkflowQueryTaskExecutor;
use Workflow\V2\Workflow;
use function Workflow\V2\signal;

#[Type('conformance.counter.php')]
#[Signal('increment', [[
    'name' => 'amount',
    'type' => 'int',
]])]
final class ConformanceCounterWorkflow extends Workflow
{
    private int $count = 0;

    public function handle(): mixed
    {
        while (true) {
            $amount = signal('increment');
            $this->count += (int) $amount;
        }
    }

    #[QueryMethod]
    public function state(): int
    {
        return $this->count;
    }

    #[QueryMethod]
    public function current(): int
    {
        return $this->count;
    }
}

function sq_json_log(array $record): void
{
    fwrite(STDOUT, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
}

function sq_task_string(array $task, array $keys): string
{
    foreach ($keys as $key) {
        $value = $task[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return '';
}

function sq_workflow_arguments(array $task): array
{
    foreach (['workflow_arguments', 'arguments'] as $key) {
        $value = $task[$key] ?? null;
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }
    }

    return [];
}

function sq_history_events(WorkerProtocolClient $client, array $task): array
{
    $events = $task['history_events'] ?? null;
    if (is_array($events)) {
        return $events;
    }

    $taskId = sq_task_string($task, ['task_id', 'workflow_task_id']);
    if ($taskId === '') {
        return [];
    }

    $history = $client->workflowTaskHistory($taskId);
    $events = is_array($history) ? ($history['history_events'] ?? null) : null;

    return is_array($events) ? $events : [];
}

function sq_workflow_type(): string
{
    return TypeRegistry::for(ConformanceCounterWorkflow::class);
}

function sq_workflow_command_contracts(): array
{
    return [
        sq_workflow_type() => WorkflowDefinition::commandContract(ConformanceCounterWorkflow::class),
    ];
}

function sq_workflow_fingerprints(): array
{
    $fingerprint = WorkflowDefinition::fingerprint(ConformanceCounterWorkflow::class);

    return is_string($fingerprint) && $fingerprint !== ''
        ? [sq_workflow_type() => $fingerprint]
        : [];
}

function sq_register_worker(
    WorkerProtocolClient $client,
    string $workerId,
    string $taskQueue,
    string $sdkVersion
): array {
    $arguments = [
        'workerId' => $workerId,
        'taskQueue' => $taskQueue,
        'supportedWorkflowTypes' => [sq_workflow_type()],
        'sdkVersion' => $sdkVersion,
        'workflowDefinitionFingerprints' => sq_workflow_fingerprints(),
        'capabilities' => [WorkflowQueryTaskExecutor::CAPABILITY],
    ];

    $parameters = array_map(
        static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod($client, 'registerWorker'))->getParameters(),
    );
    if (in_array('workflowCommandContracts', $parameters, true)) {
        $arguments['workflowCommandContracts'] = sq_workflow_command_contracts();
    }

    return $client->registerWorker(...$arguments) ?? [];
}

function sq_complete_workflow_task(
    WorkerProtocolClient $client,
    array $task,
    string $namespace
): void {
    $taskId = sq_task_string($task, ['task_id', 'workflow_task_id']);
    $workflowId = sq_task_string($task, ['workflow_id', 'workflow_instance_id', 'instance_id']);
    $runId = sq_task_string($task, ['run_id', 'workflow_run_id']);
    if ($taskId === '' || $workflowId === '' || $runId === '') {
        throw new RuntimeException('workflow task is missing task, workflow, or run identity');
    }

    $runner = WorkflowFiberRunner::forClass(
        ConformanceCounterWorkflow::class,
        $workflowId,
        $runId,
        sq_workflow_arguments($task),
        'avro',
        sq_history_events($client, $task),
        $namespace,
    );
    $step = $runner->step();
    $complete = $client->completeWorkflowTask($taskId, $step->commands);
    sq_json_log([
        'event' => 'workflow_task_completed',
        'task_id' => $taskId,
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'command_types' => array_values(array_map(
            static fn (array $command): ?string => is_string($command['type'] ?? null) ? $command['type'] : null,
            $step->commands,
        )),
        'complete' => $complete,
    ]);
}

function sq_complete_query_task(
    WorkerProtocolClient $client,
    WorkflowQueryTaskExecutor $executor,
    array $task,
    string $workerId,
    string $taskQueue,
    string $evidencePath
): void {
    $outcome = $executor->execute($task);
    $taskId = is_string($outcome['query_task_id'] ?? null)
        ? $outcome['query_task_id']
        : sq_task_string($task, ['query_task_id']);
    $status = $outcome['outcome'] ?? null;

    if ($status === 'completed') {
        $client->completeQueryTask(
            $taskId,
            $outcome['result'] ?? null,
            is_array($outcome['result_envelope'] ?? null) ? $outcome['result_envelope'] : null,
        );
    } else {
        $failure = is_array($outcome['failure'] ?? null) ? $outcome['failure'] : [];
        $client->failQueryTask(
            $taskId,
            is_string($failure['message'] ?? null) ? $failure['message'] : 'Workflow query failed.',
            is_string($failure['reason'] ?? null) ? $failure['reason'] : 'query_rejected',
            is_string($failure['type'] ?? null) ? $failure['type'] : null,
            is_string($failure['stack_trace'] ?? null) ? $failure['stack_trace'] : null,
            validationErrors: is_array($failure['validation_errors'] ?? null) ? $failure['validation_errors'] : null,
        );
    }

    $record = [
        'schema' => 'durable-workflow.v2.signal-query-runtime.php-routed-query-task',
        'status' => $status === 'completed' ? 'pass' : 'fail',
        'worker_runtime' => 'workflow-php',
        'worker_id' => $workerId,
        'task_queue' => $taskQueue,
        'query_task_id' => $taskId,
        'query_task_attempt' => $outcome['query_task_attempt'] ?? ($task['query_task_attempt'] ?? null),
        'workflow_id' => $task['workflow_id'] ?? null,
        'run_id' => $task['run_id'] ?? ($task['workflow_run_id'] ?? null),
        'workflow_type' => $task['workflow_type'] ?? null,
        'query_name' => $task['query_name'] ?? null,
        'lease_owner' => $task['lease_owner'] ?? null,
        'server_route' => 'worker_query_task_poll',
        'completion_route' => $status === 'completed' ? 'worker_query_task_complete' : 'worker_query_task_fail',
        'observed_via' => 'workflow-php worker query task executor',
        'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    file_put_contents($evidencePath, json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
    sq_json_log(['event' => 'query_task_completed', 'record' => $record]);
}

function sq_record_standalone_query_task(
    array $result,
    string $workerId,
    string $taskQueue,
    string $evidencePath
): void {
    if (($result['kind'] ?? null) !== 'query_task' || ($result['processed'] ?? false) !== true) {
        return;
    }

    $task = is_array($result['query_task'] ?? null) ? $result['query_task'] : [];
    $status = $result['outcome'] ?? null;
    $record = [
        'schema' => 'durable-workflow.v2.signal-query-runtime.php-routed-query-task',
        'status' => $status === 'completed' ? 'pass' : 'fail',
        'worker_runtime' => 'workflow-php',
        'worker_id' => $workerId,
        'task_queue' => is_string($task['task_queue'] ?? null) ? $task['task_queue'] : $taskQueue,
        'query_task_id' => is_string($result['query_task_id'] ?? null)
            ? $result['query_task_id']
            : ($task['query_task_id'] ?? null),
        'query_task_attempt' => $task['query_task_attempt'] ?? null,
        'workflow_id' => $task['workflow_id'] ?? null,
        'run_id' => $task['run_id'] ?? null,
        'workflow_type' => $task['workflow_type'] ?? null,
        'query_name' => $task['query_name'] ?? null,
        'lease_owner' => $task['lease_owner'] ?? null,
        'server_route' => 'worker_query_task_poll',
        'completion_route' => $status === 'completed' ? 'worker_query_task_complete' : 'worker_query_task_fail',
        'observed_via' => 'workflow-php standalone worker query task driver',
        'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    file_put_contents($evidencePath, json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
    sq_json_log(['event' => 'query_task_completed', 'record' => $record]);
}

if ($argc < 7) {
    fwrite(STDERR, "usage: php-counter-worker.php <base-url> <token> <namespace> <task-queue> <worker-id> <evidence-path> [seconds]\n");
    exit(2);
}

[$script, $baseUrl, $token, $namespace, $taskQueue, $workerId, $evidencePath] = $argv;
$deadline = time() + (int) ($argv[7] ?? 600);
$sdkVersion = 'durable-workflow/workflow@'.(InstalledVersions::getPrettyVersion('durable-workflow/workflow') ?? 'unknown');
$client = new WorkerProtocolClient(new HttpFactory(), $baseUrl, $token, $namespace, defaultRequestTimeoutSeconds: 10);
$registration = sq_register_worker($client, $workerId, $taskQueue, $sdkVersion);
sq_json_log([
    'event' => 'worker_registered',
    'worker_id' => $workerId,
    'task_queue' => $taskQueue,
    'workflow_type' => sq_workflow_type(),
    'registration' => $registration,
]);

$executor = new WorkflowQueryTaskExecutor([
    sq_workflow_type() => ConformanceCounterWorkflow::class,
]);
$standaloneWorker = new StandaloneWorkflowWorker($client, [
    sq_workflow_type() => ConformanceCounterWorkflow::class,
], $executor);

while (time() < $deadline) {
    try {
        $client->heartbeatWorker($workerId, ['workflow_available' => 2, 'activity_available' => 0], heartbeatIntervalSeconds: 10);
    } catch (Throwable $throwable) {
        sq_json_log(['event' => 'heartbeat_failed', 'message' => $throwable->getMessage()]);
    }

    try {
        $queryResult = $standaloneWorker->processOneQueryTask($taskQueue, $workerId, 1);
        if (($queryResult['processed'] ?? false) === true) {
            sq_record_standalone_query_task($queryResult, $workerId, $taskQueue, $evidencePath);
            continue;
        }
    } catch (Throwable $throwable) {
        sq_json_log(['event' => 'query_poll_failed', 'message' => $throwable->getMessage()]);
    }

    try {
        $workflowResult = $standaloneWorker->processOneWorkflowTask($taskQueue, $workerId, 1);
        if (($workflowResult['processed'] ?? false) === true) {
            sq_json_log(['event' => 'workflow_task_processed', 'result' => $workflowResult]);
        }
    } catch (Throwable $throwable) {
        sq_json_log(['event' => 'workflow_poll_failed', 'message' => $throwable->getMessage()]);
    }
}
''',
        encoding="utf-8",
    )

    (project_dir / "php-workflow-client.php").write_text(
        r'''<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Composer\InstalledVersions;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;
use Workflow\V2\Client\WorkflowClient;
use Workflow\V2\Client\WorkflowClientException;

if ($argc < 9) {
    fwrite(STDERR, "usage: php-workflow-client.php <operation> <base-url> <token> <namespace> <workflow-type> <workflow-id> <task-queue> <name> [args-json]\n");
    exit(2);
}

[$script, $operation, $baseUrl, $token, $namespace, $workflowType, $workflowId, $taskQueue, $name] = $argv;
$args = [];
if (($argv[9] ?? '') !== '') {
    $decoded = json_decode($argv[9], true);
    $args = is_array($decoded) && array_is_list($decoded) ? $decoded : [];
}

$client = new WorkflowClient(new HttpFactory(), $baseUrl, $token, $namespace, defaultRequestTimeoutSeconds: 30);
$sample = [
    'client' => 'workflow-php',
    'operation' => $operation,
    'operation_name' => $name,
    'workflow_id' => $workflowId,
    'workflow_type' => $workflowType,
    'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/workflow') ?? null,
];

try {
    if ($operation === 'start') {
        $sample['result'] = $client->startWorkflow(
            $workflowType,
            $workflowId,
            [],
            ['task_queue' => $taskQueue],
        );
    } elseif ($operation === 'signal') {
        $sample['result'] = $client->signalWorkflow($workflowId, $name, $args);
    } elseif ($operation === 'query') {
        $sample['result'] = $client->queryWorkflow($workflowId, $name, $args);
    } else {
        throw new InvalidArgumentException(sprintf('unsupported operation [%s]', $operation));
    }

    $sample['ok'] = true;
    echo json_encode($sample, JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
} catch (WorkflowClientException $exception) {
    $sample['ok'] = false;
    $sample['exception'] = $exception::class;
    $sample['status_code'] = $exception->statusCode();
    $sample['body'] = $exception->body();
    $sample['reason'] = is_string($exception->body()['reason'] ?? null)
        ? $exception->body()['reason']
        : null;
    echo json_encode($sample, JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
} catch (Throwable $throwable) {
    $sample['ok'] = false;
    $sample['exception'] = $throwable::class;
    $sample['message'] = $throwable->getMessage();
    echo json_encode($sample, JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}
''',
        encoding="utf-8",
    )


def ensure_workflow_php_sdk(run_root: Path, log_file: Path) -> tuple[Path, dict[str, Any]]:
    workflow_version = artifact_version_value(artifact_versions, "workflow-php")
    if is_placeholder_version(workflow_version):
        raise RuntimeError("DW_WORKFLOW_PHP_VERSION must be concrete to install the public PHP workflow package")
    if not command_available("docker"):
        raise RuntimeError("docker is required to install and run the published PHP workflow package")

    project_dir = run_root / "workflow-php"
    project_dir.mkdir(parents=True, exist_ok=True)
    write_workflow_php_project(project_dir)

    install = run_command(
        php_docker_command(
            project_dir,
            ["install", "--no-interaction", "--no-progress", "--prefer-dist"],
        ),
        log_file=log_file,
        timeout=600,
    )
    if install.returncode != 0:
        raise RuntimeError("could not install the public PHP workflow package")

    check = run_command(
        php_docker_command(
            project_dir,
            [
                "php",
                "-r",
                "require 'vendor/autoload.php'; echo Composer\\InstalledVersions::getPrettyVersion('durable-workflow/workflow') ?: '';",
            ],
        ),
        log_file=log_file,
        timeout=60,
    )
    installed_version = check.stdout.strip() or workflow_version

    entry = installed_public_artifact_entry(
        "workflow-php",
        workflow_version,
        EXPECTED_ARTIFACT_SOURCES["workflow-php"],
        "composer_package_install",
    )
    entry["installed_version"] = installed_version
    entry["runtime_image"] = workflow_php_docker_image()

    return project_dir, entry


def php_workflow_client_sample(
    project_dir: Path,
    base_url: str,
    token: str,
    namespace: str,
    operation: str,
    workflow_type: str,
    workflow_id: str,
    task_queue: str,
    name: str,
    log_file: Path,
    args: list[Any] | None = None,
) -> dict[str, Any]:
    command = php_docker_command(
        project_dir,
        [
            "php",
            "php-workflow-client.php",
            operation,
            docker_host_base_url(base_url),
            token,
            namespace,
            workflow_type,
            workflow_id,
            task_queue,
            name,
            json.dumps(args or []),
        ],
    )
    completed = run_command(command, log_file=log_file, timeout=120)
    output = completed.stdout.strip()
    sample = json_sample_from_stdout(output)
    sample.setdefault("client", "workflow-php")
    sample.setdefault("operation", operation)
    sample.setdefault("operation_name", name)
    sample.setdefault("exit_code", completed.returncode)
    sample.setdefault("ok", completed.returncode == 0)
    sample.setdefault("api_sample", {
        "client": "workflow-php",
        "operation": operation,
        "workflow_type": workflow_type,
        "workflow_id": workflow_id,
        "task_queue": task_queue,
        "operation_name": name,
        "arguments": args or [],
        "wire_input_shape": "durable-workflow.v2.payload-envelope",
        "result_shape": "decoded-result-or-exact-error-body",
    })
    return sample


def docker_container_running(container_name: str, log_file: Path) -> bool:
    inspect = run_command(
        ["docker", "inspect", "-f", "{{.State.Running}}", container_name],
        log_file=log_file,
        timeout=30,
    )
    return inspect.returncode == 0 and inspect.stdout.strip() == "true"


def wait_for_docker_worker_registered(
    *,
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    container_name: str,
    log_file: Path,
    timeout_seconds: float = 75.0,
) -> dict[str, Any]:
    deadline = time.time() + timeout_seconds
    last_response: dict[str, Any] | None = None
    while time.time() < deadline:
        if not docker_container_running(container_name, log_file):
            logs = capture_command_summary(["docker", "logs", container_name], log_file=log_file, timeout=30)
            raise RuntimeError(f"PHP worker container exited before registration: {logs}")

        response = http_json(
            base_url,
            api_path("workers", worker_id),
            token=token,
            namespace=namespace,
            timeout=5,
        )
        last_response = response
        if int(response.get("status_code") or 0) == 200 and isinstance(response.get("body"), dict):
            return response["body"]
        time.sleep(0.5)

    log_line(log_file, f"last PHP worker registration probe response: {last_response}")
    raise RuntimeError(f"PHP worker {worker_id} did not register within {timeout_seconds}s")


def wait_for_php_query_route_evidence(
    evidence_path: Path,
    *,
    workflow_id: str,
    worker_id: str,
    query_name: str = "current",
    timeout_seconds: float = 45.0,
) -> dict[str, Any]:
    deadline = time.time() + timeout_seconds
    records: list[dict[str, Any]] = []
    while time.time() < deadline:
        records = load_query_route_records(evidence_path)
        for record in reversed(records):
            if record.get("workflow_id") != workflow_id:
                continue
            if record.get("worker_id") != worker_id:
                continue
            if record.get("query_name") != query_name:
                continue
            if str(record.get("status") or "").strip().lower() not in {"pass", "completed"}:
                continue
            return record
        time.sleep(0.5)

    raise RuntimeError(f"PHP worker did not record routed {query_name} query evidence: {records[-3:]}")


def sdk_error_sample(
    python_bin: str,
    base_url: str,
    token: str,
    namespace: str,
    workflow_id: str,
    operation: str,
    name: str,
    log_file: Path,
    args: list[Any] | None = None,
) -> dict[str, Any]:
    code = r'''
import asyncio
import json
import sys

from durable_workflow import Client, DurableWorkflowError, WorkflowNotFound

base_url, token, namespace, workflow_id, operation, name = sys.argv[1:7]
args = json.loads(sys.argv[7]) if len(sys.argv) > 7 and sys.argv[7] else None

def exception_reason(exc):
    reason = getattr(exc, "reason", None)
    if callable(reason):
        reason = reason()
    body = getattr(exc, "body", None)
    if not isinstance(reason, str) and isinstance(body, dict):
        candidate = body.get("reason")
        if isinstance(candidate, str):
            reason = candidate
    if not isinstance(reason, str) and isinstance(exc, WorkflowNotFound):
        reason = "instance_not_found"
    return reason if isinstance(reason, str) else None

async def main():
    async with Client(base_url, token=token, namespace=namespace, timeout=15.0) as client:
        try:
            if operation == "signal":
                await client.signal_workflow(workflow_id, name, args=args)
            else:
                await client.query_workflow(workflow_id, name, args=args)
        except DurableWorkflowError as exc:
            print(json.dumps({
                "client": "sdk-python",
                "exception": type(exc).__name__,
                "status_code": getattr(exc, "status", None),
                "reason": exception_reason(exc),
                "validation_errors": getattr(exc, "validation_errors", None),
                "body": getattr(exc, "body", None),
            }, sort_keys=True))
            return 0

    print(json.dumps({
        "client": "sdk-python",
        "exception": None,
        "reason": "no_exception",
    }, sort_keys=True))
    return 1

raise SystemExit(asyncio.run(main()))
'''
    command = [python_bin, "-c", code, base_url, token, namespace, workflow_id, operation, name]
    if args is not None:
        command.append(json.dumps(args))
    completed = run_command(
        command,
        log_file=log_file,
        timeout=60,
    )
    output = completed.stdout.strip()
    try:
        sample = json.loads(output) if output else {}
    except json.JSONDecodeError:
        sample = {"raw_stdout": output}
    sample.setdefault("client", "sdk-python")
    sample.setdefault("exit_code", completed.returncode)
    return sample


def sdk_success_sample(
    python_bin: str,
    base_url: str,
    token: str,
    namespace: str,
    workflow_id: str,
    operation: str,
    name: str,
    log_file: Path,
    args: list[Any] | None = None,
) -> dict[str, Any]:
    code = r'''
import asyncio
import json
import sys

from durable_workflow import Client, DurableWorkflowError

base_url, token, namespace, workflow_id, operation, name = sys.argv[1:7]
args = json.loads(sys.argv[7]) if len(sys.argv) > 7 and sys.argv[7] else None

def exception_reason(exc):
    reason = getattr(exc, "reason", None)
    if callable(reason):
        reason = reason()
    body = getattr(exc, "body", None)
    if not isinstance(reason, str) and isinstance(body, dict):
        candidate = body.get("reason")
        if isinstance(candidate, str):
            reason = candidate
    return reason if isinstance(reason, str) else None

async def main():
    async with Client(base_url, token=token, namespace=namespace, timeout=30.0) as client:
        try:
            if operation == "signal":
                result = await client.signal_workflow(workflow_id, name, args=args)
            else:
                result = await client.query_workflow(workflow_id, name, args=args)
        except DurableWorkflowError as exc:
            print(json.dumps({
                "client": "sdk-python",
                "operation": operation,
                "operation_name": name,
                "ok": False,
                "exception": type(exc).__name__,
                "status_code": getattr(exc, "status", None),
                "reason": exception_reason(exc),
                "validation_errors": getattr(exc, "validation_errors", None),
                "body": getattr(exc, "body", None),
            }, sort_keys=True))
            return 1

    print(json.dumps({
        "client": "sdk-python",
        "operation": operation,
        "operation_name": name,
        "ok": True,
        "result": result,
    }, sort_keys=True))
    return 0

raise SystemExit(asyncio.run(main()))
'''
    command = [python_bin, "-c", code, base_url, token, namespace, workflow_id, operation, name]
    if args is not None:
        command.append(json.dumps(args))
    completed = run_command(command, log_file=log_file, timeout=90)
    output = completed.stdout.strip()
    try:
        sample = json.loads(output) if output else {}
    except json.JSONDecodeError:
        sample = {"raw_stdout": output}
    sample.setdefault("client", "sdk-python")
    sample.setdefault("operation", operation)
    sample.setdefault("operation_name", name)
    sample.setdefault("exit_code", completed.returncode)
    sample.setdefault("ok", completed.returncode == 0)
    return sample


def sdk_start_workflow_sample(
    python_bin: str,
    base_url: str,
    token: str,
    namespace: str,
    workflow_id: str,
    workflow_type: str,
    task_queue: str,
    log_file: Path,
) -> dict[str, Any]:
    code = r'''
import asyncio
import json
import sys

from durable_workflow import Client, DurableWorkflowError

base_url, token, namespace, workflow_id, workflow_type, task_queue = sys.argv[1:7]

def exception_reason(exc):
    reason = getattr(exc, "reason", None)
    if callable(reason):
        reason = reason()
    body = getattr(exc, "body", None)
    if not isinstance(reason, str) and isinstance(body, dict):
        candidate = body.get("reason")
        if isinstance(candidate, str):
            reason = candidate
    return reason if isinstance(reason, str) else None

async def main():
    async with Client(base_url, token=token, namespace=namespace, timeout=30.0) as client:
        try:
            handle = await client.start_workflow(
                workflow_type=workflow_type,
                workflow_id=workflow_id,
                task_queue=task_queue,
                input=[],
            )
        except DurableWorkflowError as exc:
            print(json.dumps({
                "client": "sdk-python",
                "operation": "start",
                "operation_name": "start",
                "workflow_id": workflow_id,
                "workflow_type": workflow_type,
                "task_queue": task_queue,
                "ok": False,
                "exception": type(exc).__name__,
                "status_code": getattr(exc, "status", None),
                "reason": exception_reason(exc),
                "validation_errors": getattr(exc, "validation_errors", None),
                "body": getattr(exc, "body", None),
            }, sort_keys=True))
            return 1

    print(json.dumps({
        "client": "sdk-python",
        "operation": "start",
        "operation_name": "start",
        "workflow_id": handle.workflow_id,
        "run_id": handle.run_id,
        "workflow_type": handle.workflow_type,
        "task_queue": task_queue,
        "ok": True,
        "api_sample": {
            "client": "sdk-python",
            "operation": "start",
            "workflow_type": workflow_type,
            "workflow_id": workflow_id,
            "task_queue": task_queue,
            "wire_input_shape": "durable-workflow.v2.payload-envelope",
            "result_shape": "workflow-handle",
        },
    }, sort_keys=True))
    return 0

raise SystemExit(asyncio.run(main()))
'''
    completed = run_command(
        [python_bin, "-c", code, base_url, token, namespace, workflow_id, workflow_type, task_queue],
        log_file=log_file,
        timeout=90,
    )
    output = completed.stdout.strip()
    try:
        sample = json.loads(output) if output else {}
    except json.JSONDecodeError:
        sample = {"raw_stdout": output}
    sample.setdefault("client", "sdk-python")
    sample.setdefault("operation", "start")
    sample.setdefault("operation_name", "start")
    sample.setdefault("workflow_id", workflow_id)
    sample.setdefault("workflow_type", workflow_type)
    sample.setdefault("task_queue", task_queue)
    sample.setdefault("exit_code", completed.returncode)
    sample.setdefault("ok", completed.returncode == 0)
    return sample


def json_sample_from_stdout(output: str) -> dict[str, Any]:
    raw = output.strip()
    if raw == "":
        return {}

    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError:
        decoded = None

    if isinstance(decoded, dict):
        return decoded

    decoder = json.JSONDecoder()
    for index, character in enumerate(raw):
        if character != "{":
            continue

        try:
            decoded, end = decoder.raw_decode(raw, index)
        except json.JSONDecodeError:
            continue

        if not isinstance(decoded, dict):
            continue

        sample = dict(decoded)
        if raw[:index].strip() or raw[end:].strip():
            sample.setdefault("raw_stdout", raw)
        return sample

    return {"raw_stdout": raw}


_NO_SAMPLE_RESULT = object()


def envelope_result_value(candidate: dict[str, Any]) -> Any:
    for envelope_key in ("result_envelope", "resultEnvelope"):
        envelope = candidate.get(envelope_key)
        if not isinstance(envelope, dict):
            continue

        blob = envelope.get("blob")
        if not isinstance(blob, str):
            continue

        decoded = decode_json_blob(blob)
        if decoded is not None:
            return decoded

    return _NO_SAMPLE_RESULT


def looks_like_control_plane_result(value: Any) -> bool:
    return (
        isinstance(value, dict)
        and any(
            key in value
            for key in (
                "success",
                "workflow_id",
                "run_id",
                "query_name",
                "operation",
                "operation_name",
                "target_scope",
                "result_envelope",
                "resultEnvelope",
            )
        )
    )


def candidate_result_value(candidate: dict[str, Any]) -> Any:
    if "result" in candidate:
        result = candidate["result"]
        if looks_like_control_plane_result(result):
            nested = candidate_result_value(result)
            if nested is not _NO_SAMPLE_RESULT:
                return nested

        if result is not None:
            return result

        envelope = envelope_result_value(candidate)
        if envelope is not _NO_SAMPLE_RESULT:
            return envelope

        return None

    envelope = envelope_result_value(candidate)
    if envelope is not _NO_SAMPLE_RESULT:
        return envelope

    for nested_key in ("body", "data", "output", "server_response"):
        nested = candidate.get(nested_key)
        if not isinstance(nested, dict):
            continue

        value = candidate_result_value(nested)
        if value is not _NO_SAMPLE_RESULT:
            return value

    return _NO_SAMPLE_RESULT


def sample_result_value(sample: dict[str, Any]) -> Any:
    for candidate in (
        sample,
        sample.get("output"),
        sample.get("server_response"),
    ):
        if not isinstance(candidate, dict):
            continue

        value = candidate_result_value(candidate)
        if value is not _NO_SAMPLE_RESULT:
            return value

    return None


def workflow_start_run_id(sample: dict[str, Any]) -> str:
    candidates: list[Any] = [sample, sample.get("result"), sample.get("output"), sample.get("server_response")]
    seen: set[int] = set()
    nested_keys = (
        "result",
        "start",
        "start_result",
        "startResult",
        "workflow_start",
        "workflowStart",
        "body",
        "data",
        "output",
        "server_response",
        "serverResponse",
        "response",
        "workflow",
        "workflow_execution",
        "workflowExecution",
        "execution",
        "handle",
    )

    while candidates:
        candidate = candidates.pop(0)
        if not isinstance(candidate, dict):
            continue

        marker = id(candidate)
        if marker in seen:
            continue
        seen.add(marker)

        for key in ("run_id", "runId", "workflow_run_id", "workflowRunId"):
            value = candidate.get(key)
            if isinstance(value, str) and value.strip() != "":
                return value.strip()

        for key in nested_keys:
            nested = candidate.get(key)
            if isinstance(nested, dict):
                candidates.append(nested)

    return ""


def public_sample_ok(sample: dict[str, Any]) -> bool:
    if sample.get("ok") is True:
        return True
    exit_code = sample.get("exit_code")
    return isinstance(exit_code, int) and exit_code == 0


def load_query_route_records(path: Path) -> list[dict[str, Any]]:
    if not path.is_file():
        return []

    records: list[dict[str, Any]] = []
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line == "":
            continue
        try:
            parsed = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(parsed, dict):
            records.append(parsed)

    return records


def routed_current_query_task_satisfied(value: Any) -> bool:
    if not isinstance(value, dict):
        return False

    if value.get("query_name") != "current":
        return False

    if str(value.get("public_query_surface") or "").strip() != "cli":
        return False

    status = str(value.get("status") or "").strip().lower()
    if status not in {"pass", "completed"}:
        return False

    for field in (
        "query_task_id",
        "workflow_id",
        "run_id",
        "workflow_type",
        "task_queue",
        "worker_id",
        "worker_runtime",
        "lease_owner",
        "server_route",
        "completion_route",
    ):
        if str(value.get(field) or "").strip() == "":
            return False

    try:
        attempt = int(value.get("query_task_attempt"))
    except (TypeError, ValueError):
        return False

    return attempt >= 1 and str(value.get("worker_runtime")) == "sdk-python"


def routed_current_query_task_matches(
    record: dict[str, Any],
    *,
    workflow_id: str,
    run_id: str,
    workflow_type: str,
    task_queue: str,
    worker_id: str,
) -> bool:
    if record.get("query_name") != "current":
        return False
    if record.get("workflow_id") != workflow_id:
        return False
    if record.get("run_id") != run_id:
        return False
    if record.get("workflow_type") != workflow_type:
        return False
    if record.get("task_queue") != task_queue:
        return False
    if record.get("worker_id") != worker_id:
        return False
    status = str(record.get("status") or "").strip().lower()
    if status not in {"pass", "completed"}:
        return False
    return str(record.get("query_task_id") or "").strip() != ""


def wait_for_routed_current_query_task(
    *,
    evidence_path: Path,
    workflow_id: str,
    run_id: str,
    workflow_type: str,
    task_queue: str,
    worker_id: str,
    public_query_surface: str,
    log_file: Path,
    timeout_seconds: float = 15.0,
) -> dict[str, Any]:
    deadline = time.time() + timeout_seconds
    records: list[dict[str, Any]] = []
    while time.time() < deadline:
        records = load_query_route_records(evidence_path)
        for record in records:
            if not routed_current_query_task_matches(
                record,
                workflow_id=workflow_id,
                run_id=run_id,
                workflow_type=workflow_type,
                task_queue=task_queue,
                worker_id=worker_id,
            ):
                continue

            routed = dict(record)
            routed["public_query_surface"] = public_query_surface
            if routed_current_query_task_satisfied(routed):
                return routed

        time.sleep(0.25)

    log_line(log_file, f"routed current query task records: {records}")
    raise RuntimeError("Python SDK baseline did not record routed current query task evidence")


def decode_json_blob(blob: Any) -> Any:
    if not isinstance(blob, str) or blob.strip() == "":
        return None
    try:
        return json.loads(blob)
    except json.JSONDecodeError:
        pass
    try:
        decoded_bytes = base64.b64decode(blob, validate=True)
    except Exception:
        return None

    try:
        return json.loads(decoded_bytes.decode("utf-8"))
    except Exception:
        return decode_avro_generic_wrapper(decoded_bytes)


def decode_avro_long(data: bytes, offset: int) -> tuple[int, int] | None:
    raw = 0
    shift = 0
    while offset < len(data) and shift <= 63:
        byte = data[offset]
        offset += 1
        raw |= (byte & 0x7F) << shift
        if (byte & 0x80) == 0:
            return ((raw >> 1) ^ -(raw & 1), offset)
        shift += 7

    return None


def decode_avro_generic_wrapper(data: bytes) -> Any:
    if len(data) < 2 or data[0] != 0:
        return None

    length_result = decode_avro_long(data, 1)
    if length_result is None:
        return None

    length, offset = length_result
    if length < 0 or offset + length > len(data):
        return None

    try:
        return json.loads(data[offset:offset + length].decode("utf-8"))
    except Exception:
        return None


def decode_signal_arguments(envelope: Any) -> Any:
    if not isinstance(envelope, dict):
        return None
    for key in ("decoded", "value", "payload", "arguments"):
        value = envelope.get(key)
        if isinstance(value, (list, dict, int, float, str, bool)):
            return value
    decoded = decode_json_blob(envelope.get("blob"))
    if decoded is not None:
        return decoded
    return None


def amount_from_arguments(arguments: Any) -> int | None:
    if isinstance(arguments, list) and arguments:
        value = arguments[0]
    elif isinstance(arguments, dict):
        value = arguments.get("amount")
        if value is None:
            value = arguments.get("n")
    else:
        value = arguments

    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, str) and value.strip().lstrip("-").isdigit():
        return int(value.strip())
    return None


def signal_amount_from_task(task: dict[str, Any]) -> int | None:
    amount = amount_from_arguments(decode_signal_arguments(task.get("signal_arguments")))
    if amount is not None:
        return amount

    for event in reversed(task.get("history_events", [])):
        if not isinstance(event, dict) or event.get("event_type") != "SignalReceived":
            continue
        payload = event.get("payload")
        if not isinstance(payload, dict):
            continue
        amount = amount_from_arguments(decode_signal_arguments(payload.get("arguments")))
        if amount is not None:
            return amount

    return None


def workflow_task_history_events(
    base_url: str,
    token: str,
    namespace: str,
    task: dict[str, Any],
) -> list[dict[str, Any]]:
    events = [
        event
        for event in task.get("history_events", [])
        if isinstance(event, dict)
    ]
    next_token = task.get("next_history_page_token")
    seen_tokens: set[str] = set()

    while isinstance(next_token, str) and next_token.strip() != "":
        if next_token in seen_tokens:
            raise RuntimeError(f"workflow task history pagination repeated token {next_token!r}")
        seen_tokens.add(next_token)

        response = http_json(
            base_url,
            api_path("worker", "workflow-tasks", str(task["task_id"]), "history"),
            method="POST",
            body={
                "lease_owner": task["lease_owner"],
                "workflow_task_attempt": task["workflow_task_attempt"],
                "next_history_page_token": next_token,
                "history_page_size": 1000,
            },
            token=token,
            namespace=namespace,
            worker=True,
            timeout=30,
        )
        if int(response["status_code"]) >= 400:
            raise RuntimeError(f"workflow task history page failed: {response}")

        body = response.get("body")
        if not isinstance(body, dict):
            break
        page_events = body.get("history_events")
        if isinstance(page_events, list):
            events.extend(event for event in page_events if isinstance(event, dict))
        next_token = body.get("next_history_page_token")

    return events


def signal_observations_from_events(events: list[dict[str, Any]], signal_name: str) -> list[dict[str, Any]]:
    observations: list[dict[str, Any]] = []
    for index, event in enumerate(events):
        if event.get("event_type") != "SignalReceived":
            continue
        payload = event.get("payload")
        if not isinstance(payload, dict) or payload.get("signal_name") != signal_name:
            continue
        amount = amount_from_arguments(decode_signal_arguments(payload.get("arguments")))
        if amount is None:
            continue

        observation = {
            "signal_name": signal_name,
            "signal_amount": amount,
            "history_event_index": index,
        }
        signal_id = payload.get("signal_id")
        if isinstance(signal_id, str) and signal_id:
            observation["signal_id"] = signal_id
        sequence = payload.get("workflow_sequence")
        if isinstance(sequence, int):
            observation["workflow_sequence"] = sequence
        observations.append(observation)

    return observations


def increment_signal_amounts_from_history_events(events: Any) -> list[int]:
    if not isinstance(events, list):
        return []

    return [
        observation["signal_amount"]
        for observation in signal_observations_from_events(
            [event for event in events if isinstance(event, dict)],
            "increment",
        )
        if isinstance(observation.get("signal_amount"), int)
    ]


def signal_observation_key(observation: dict[str, Any]) -> str:
    signal_id = observation.get("signal_id")
    if isinstance(signal_id, str) and signal_id:
        return f"signal:{signal_id}"
    sequence = observation.get("workflow_sequence")
    if isinstance(sequence, int):
        return f"sequence:{sequence}"
    return f"history-index:{observation.get('history_event_index')}"


def increment_signal_observations_from_task(
    base_url: str,
    token: str,
    namespace: str,
    task: dict[str, Any],
) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    events = workflow_task_history_events(base_url, token, namespace, task)
    amount = amount_from_arguments(decode_signal_arguments(task.get("signal_arguments")))
    if task.get("signal_name") == "increment" and amount is not None:
        return [
            {
                "signal_name": "increment",
                "signal_amount": amount,
                "signal_id": task.get("workflow_signal_id"),
                "workflow_sequence": task.get("workflow_sequence"),
                "history_event_index": f"task:{task.get('task_id')}:signal_arguments",
            }
        ], events

    observations = signal_observations_from_events(events, "increment")
    if observations:
        return observations, events

    return [], events


def count_signal_received(events_response: dict[str, Any], signal_name: str) -> int:
    body = events_response.get("body")
    if not isinstance(body, dict):
        return 0
    events = body.get("events")
    if not isinstance(events, list):
        return 0

    count = 0
    for event in events:
        if not isinstance(event, dict) or event.get("event_type") != "SignalReceived":
            continue
        payload = event.get("payload")
        if isinstance(payload, dict) and payload.get("signal_name") == signal_name:
            count += 1
    return count


def answer_next_query_task(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    task_queue: str,
    result: Any,
    log_file: Path,
    holder: dict[str, Any],
    poll_timeout: float = 45.0,
) -> None:
    try:
        deadline = time.time() + poll_timeout
        poll_attempt = 0
        empty_polls: list[dict[str, Any]] = []
        task: dict[str, Any] | None = None

        while time.time() < deadline:
            poll_attempt += 1
            remaining = max(0.2, deadline - time.time())
            if "query_poll_started_at" not in holder:
                holder["query_poll_started_at"] = now()
            poll = http_json(
                base_url,
                api_path("worker", "query-tasks", "poll"),
                method="POST",
                body={
                    "worker_id": worker_id,
                    "task_queue": task_queue,
                    "poll_request_id": f"query-{int(time.time() * 1000)}-{poll_attempt}",
                },
                token=token,
                namespace=namespace,
                worker=True,
                timeout=remaining + 5.0,
            )
            holder["poll"] = poll
            task_candidate = poll.get("body", {}).get("task") if isinstance(poll.get("body"), dict) else None
            if isinstance(task_candidate, dict):
                task = task_candidate
                break

            empty_polls.append(response_sample(poll))
            holder["empty_polls"] = empty_polls

            if int(poll.get("status_code") or 0) >= 400:
                holder["error"] = f"query task poll failed: {poll}"
                return

            time.sleep(min(0.1, max(0.0, deadline - time.time())))

        if task is None:
            holder["error"] = "query task poll returned no task before timeout"
            return

        holder["query_handler_invoked_at"] = now()
        holder["query_task"] = task
        task_history_signal_order = increment_signal_amounts_from_history_events(task.get("history_events"))
        if task_history_signal_order:
            holder["history_signal_order"] = task_history_signal_order
        resolved_result = result(task, holder) if callable(result) else result
        holder["result"] = resolved_result
        complete = http_json(
            base_url,
            api_path("worker", "query-tasks", str(task["query_task_id"]), "complete"),
            method="POST",
            body={
                "lease_owner": task.get("lease_owner") or worker_id,
                "query_task_attempt": task["query_task_attempt"],
                "result": resolved_result,
            },
            token=token,
            namespace=namespace,
            worker=True,
            timeout=15,
        )
        holder["complete"] = complete
        holder["query_completed_at"] = now()
    except Exception as exc:  # noqa: BLE001 - captured into conformance evidence.
        holder["error"] = f"{type(exc).__name__}: {exc}"


def poll_workflow_task(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    task_queue: str,
    timeout: float = 45.0,
) -> dict[str, Any]:
    return http_json(
        base_url,
        api_path("worker", "workflow-tasks", "poll"),
        method="POST",
        body={
            "worker_id": worker_id,
            "task_queue": task_queue,
            "poll_request_id": f"workflow-{int(time.time() * 1000)}",
        },
        token=token,
        namespace=namespace,
        worker=True,
        timeout=timeout,
    )


def complete_workflow_task(
    base_url: str,
    token: str,
    namespace: str,
    task: dict[str, Any],
    commands: list[dict[str, Any]],
    timeout: float = 30.0,
) -> dict[str, Any]:
    return http_json(
        base_url,
        api_path("worker", "workflow-tasks", str(task["task_id"]), "complete"),
        method="POST",
        body={
            "lease_owner": task["lease_owner"],
            "workflow_task_attempt": task["workflow_task_attempt"],
            "commands": commands,
        },
        token=token,
        namespace=namespace,
        worker=True,
        timeout=timeout,
    )


def complete_open_wait(
    base_url: str,
    token: str,
    namespace: str,
    task: dict[str, Any],
    condition_key: str,
) -> dict[str, Any]:
    return complete_workflow_task(
        base_url,
        token,
        namespace,
        task,
        [
            {
                "type": "open_condition_wait",
                "condition_key": condition_key,
                "timeout_seconds": 300,
            },
        ],
    )


def append_new_increment_observations(
    observations: list[dict[str, Any]],
    seen_signals: set[str],
    observed_amounts: list[int],
) -> list[int]:
    new_amounts: list[int] = []
    for observation in observations:
        key = signal_observation_key(observation)
        if key in seen_signals:
            continue
        seen_signals.add(key)
        amount = observation.get("signal_amount")
        if isinstance(amount, int):
            new_amounts.append(amount)
            observed_amounts.append(amount)
    return new_amounts


def signal_task_observation_summary(
    task: dict[str, Any],
    observations: list[dict[str, Any]],
    history_events: list[dict[str, Any]],
    new_amounts: list[int],
    poll_index: int,
) -> dict[str, Any]:
    return {
        "poll_index": poll_index,
        "task_id": task.get("task_id"),
        "signal_name": signal_name_from_task(task),
        "signal_amounts": new_amounts,
        "history_signal_amounts": [
            observation.get("signal_amount")
            for observation in observations
            if isinstance(observation.get("signal_amount"), int)
        ],
        "history_event_types": [
            event.get("event_type")
            for event in history_events
            if isinstance(event.get("event_type"), str)
        ],
    }


def collect_increment_signal_observations(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    task_queue: str,
    seen_signals: set[str],
    observed_amounts: list[int],
    signal_tasks: list[dict[str, Any]],
    condition_key_prefix: str,
    label: str,
    expected_count: int,
    log_file: Path,
    poll_timeout: float = 45.0,
    allow_exhausted_after_observation: bool = False,
) -> None:
    poll_index = 0
    while len(observed_amounts) < expected_count and poll_index < expected_count:
        poll_index += 1
        try:
            poll = poll_workflow_task(
                base_url,
                token,
                namespace,
                worker_id,
                task_queue,
                timeout=poll_timeout,
            )
        except Exception as exc:  # noqa: BLE001 - dedup evidence may be complete after one delivered task.
            if allow_exhausted_after_observation and observed_amounts:
                log_line(log_file, f"{label} signal poll stopped: {type(exc).__name__}: {exc}")
                break
            raise

        task = poll.get("body", {}).get("task") if isinstance(poll.get("body"), dict) else None
        if not isinstance(task, dict):
            if allow_exhausted_after_observation and observed_amounts:
                log_line(log_file, f"{label} signal poll stopped: no further workflow task in {poll}")
                break
            raise RuntimeError(f"{label} signal poll {poll_index} returned no task: {poll}")

        observations, history_events = increment_signal_observations_from_task(
            base_url,
            token,
            namespace,
            task,
        )
        new_amounts = append_new_increment_observations(
            observations,
            seen_signals,
            observed_amounts,
        )
        if not new_amounts:
            if allow_exhausted_after_observation and observed_amounts:
                log_line(log_file, f"{label} signal poll stopped: no new increment signals in {task}")
                break
            raise RuntimeError(f"{label} signal poll {poll_index} did not expose new increment signals: {task}")

        complete = complete_open_wait(
            base_url,
            token,
            namespace,
            task,
            f"{condition_key_prefix}-{len(observed_amounts)}",
        )
        if int(complete["status_code"]) >= 400:
            raise RuntimeError(f"{label} signal poll {poll_index} task completion failed: {complete}")

        signal_tasks.append(
            signal_task_observation_summary(
                task,
                observations,
                history_events,
                new_amounts,
                poll_index,
            )
        )


def start_waiting_workflow(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    task_queue: str,
    workflow_id: str,
    workflow_type: str,
    condition_key: str,
) -> str:
    start = http_json(
        base_url,
        api_path("workflows"),
        method="POST",
        body={
            "workflow_id": workflow_id,
            "workflow_type": workflow_type,
            "task_queue": task_queue,
        },
        token=token,
        namespace=namespace,
        timeout=30,
    )
    if int(start["status_code"]) >= 400:
        raise RuntimeError(f"workflow start failed: {start}")

    run_id = str(start["body"]["run_id"])
    initial_poll = poll_workflow_task(base_url, token, namespace, worker_id, task_queue)
    initial_task = task_from_poll(initial_poll, f"{workflow_id} initial")
    initial_complete = complete_open_wait(
        base_url,
        token,
        namespace,
        initial_task,
        condition_key,
    )
    if int(initial_complete["status_code"]) >= 400:
        raise RuntimeError(f"initial workflow task completion failed: {initial_complete}")
    return run_id


def complete_next_increment_task(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    task_queue: str,
    condition_key: str,
    label: str,
    timeout: float = 45.0,
) -> tuple[int, dict[str, Any]]:
    poll = poll_workflow_task(base_url, token, namespace, worker_id, task_queue, timeout=timeout)
    task = task_from_poll(poll, label)
    signal_name = signal_name_from_task(task)
    if signal_name != "increment":
        raise RuntimeError(f"{label} task did not carry increment signal: {task}")
    amount = signal_amount_from_task(task)
    if amount is None:
        raise RuntimeError(f"{label} task did not expose decoded signal arguments: {task}")
    complete = complete_open_wait(base_url, token, namespace, task, condition_key)
    if int(complete["status_code"]) >= 400:
        raise RuntimeError(f"{label} task completion failed: {complete}")
    return amount, task


def workflow_query_call(
    base_url: str,
    token: str,
    namespace: str,
    workflow_id: str,
    query_name: str,
    holder: dict[str, Any],
) -> None:
    holder["query_sent_at"] = now()
    holder["response"] = http_json(
        base_url,
        api_path("workflows", workflow_id, "query", query_name),
        method="POST",
        body={},
        token=token,
        namespace=namespace,
        timeout=60,
    )
    holder["query_completed_at"] = now()


def query_with_worker_result(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    task_queue: str,
    workflow_id: str,
    query_name: str,
    result: Any,
    log_file: Path,
    call: Any,
) -> dict[str, Any]:
    holder: dict[str, Any] = {}
    responder = threading.Thread(
        target=answer_next_query_task,
        args=(base_url, token, namespace, worker_id, task_queue, result, log_file, holder),
        daemon=True,
    )
    responder.start()
    sample = call()
    responder.join(timeout=20)
    if responder.is_alive() or holder.get("error"):
        raise RuntimeError(f"{workflow_id} {query_name} query responder failed: {holder.get('error', 'timeout')}")
    sample["query_task"] = {
        "poll_status_code": holder.get("poll", {}).get("status_code")
        if isinstance(holder.get("poll"), dict)
        else None,
        "complete_status_code": holder.get("complete", {}).get("status_code")
        if isinstance(holder.get("complete"), dict)
        else None,
        "query_handler_invoked_at": holder.get("query_handler_invoked_at"),
        "query_completed_at": holder.get("query_completed_at"),
    }
    return sample


def task_from_poll(poll: dict[str, Any], label: str) -> dict[str, Any]:
    task = poll.get("body", {}).get("task") if isinstance(poll.get("body"), dict) else None
    if not isinstance(task, dict):
        raise RuntimeError(f"{label} workflow task poll returned no task: {poll}")
    return task


def history_event_types_from_task(task: dict[str, Any]) -> list[str]:
    events = task.get("history_events")
    if not isinstance(events, list):
        return []

    event_types: list[str] = []
    for event in events:
        if not isinstance(event, dict):
            continue
        event_type = event.get("event_type")
        if isinstance(event_type, str):
            event_types.append(event_type)
    return event_types


def signal_name_from_task(task: dict[str, Any]) -> str | None:
    signal_name = task.get("signal_name")
    if isinstance(signal_name, str) and signal_name:
        return signal_name

    for event in task.get("history_events", []):
        if not isinstance(event, dict) or event.get("event_type") != "SignalReceived":
            continue
        payload = event.get("payload")
        if not isinstance(payload, dict):
            continue
        candidate = payload.get("signal_name")
        if isinstance(candidate, str) and candidate:
            return candidate

    return None


def run_status(base_url: str, token: str, namespace: str, workflow_id: str) -> str | None:
    response = http_json(
        base_url,
        api_path("workflows", workflow_id),
        method="GET",
        token=token,
        namespace=namespace,
        timeout=30,
    )
    body = response.get("body")
    if not isinstance(body, dict):
        return None
    status = body.get("status")
    return status if isinstance(status, str) else None


def run_replay_terminal_probe(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    task_queue: str,
    workflow_type: str,
    versions: dict[str, str],
    sources: dict[str, str],
    log_file: Path,
) -> tuple[dict[str, Any] | None, dict[str, Any] | None]:
    if not env_flag("DW_SIGNALS_QUERIES_RUN_REPLAY_TERMINAL_PROBE", True):
        return None, {"skipped": "disabled_by_env"}

    try:
        suffix = hashlib.sha1(f"{time.time()}-replay-terminal".encode("utf-8")).hexdigest()[:10]
        replay_workflow_id = f"wf-sq-replay-{suffix}"
        terminal_workflow_id = f"wf-sq-terminal-{suffix}"
        probe_task_queue = f"{task_queue}-replay-terminal-{suffix}"
        probe_worker_id = f"{worker_id}-replay-terminal-{suffix}"

        register = http_json(
            base_url,
            api_path("worker", "register"),
            method="POST",
            body={
                "worker_id": probe_worker_id,
                "task_queue": probe_task_queue,
                "runtime": "external",
                "sdk_version": "signals-queries-replay-terminal-probe",
                "supported_workflow_types": [workflow_type],
                "capabilities": ["query_tasks"],
                "workflow_command_contracts": {
                    workflow_type: command_contract(),
                },
            },
            token=token,
            namespace=namespace,
            worker=True,
            timeout=30,
        )
        if int(register["status_code"]) >= 400:
            raise RuntimeError(f"replay/terminal worker registration failed: {register}")

        replay_start = http_json(
            base_url,
            api_path("workflows"),
            method="POST",
            body={
                "workflow_id": replay_workflow_id,
                "workflow_type": workflow_type,
                "task_queue": probe_task_queue,
            },
            token=token,
            namespace=namespace,
            timeout=30,
        )
        if int(replay_start["status_code"]) >= 400:
            raise RuntimeError(f"replay timing workflow start failed: {replay_start}")

        replay_run_id = str(replay_start["body"]["run_id"])
        worker_restart_at = now()
        replay_poll = poll_workflow_task(base_url, token, namespace, probe_worker_id, probe_task_queue)
        replay_task = task_from_poll(replay_poll, "replay timing")

        query_holder: dict[str, Any] = {}
        query_thread = threading.Thread(
            target=workflow_query_call,
            args=(base_url, token, namespace, replay_workflow_id, "state", query_holder),
            daemon=True,
        )
        query_thread.start()
        query_sent_deadline = time.time() + 2
        while "query_sent_at" not in query_holder and time.time() < query_sent_deadline:
            time.sleep(0.01)
        if "query_sent_at" not in query_holder:
            raise RuntimeError("query during replay thread did not start before replay completion")

        query_task_holder: dict[str, Any] = {}
        query_responder = threading.Thread(
            target=answer_next_query_task,
            args=(base_url, token, namespace, probe_worker_id, probe_task_queue, 0, log_file, query_task_holder),
            daemon=True,
        )
        query_responder.start()
        query_poll_deadline = time.time() + 2
        while "query_poll_started_at" not in query_task_holder and time.time() < query_poll_deadline:
            time.sleep(0.01)
        if "query_poll_started_at" not in query_task_holder:
            raise RuntimeError("query during replay responder did not start polling before replay completion")

        signal_sent_at = now()
        signal_response = http_json(
            base_url,
            api_path("workflows", replay_workflow_id, "signal", "increment"),
            method="POST",
            body={"input": {"amount": 5}},
            token=token,
            namespace=namespace,
            timeout=30,
        )
        if int(signal_response["status_code"]) >= 400:
            raise RuntimeError(f"signal during replay failed: {signal_response}")

        time.sleep(0.3)
        replay_complete = complete_workflow_task(
            base_url,
            token,
            namespace,
            replay_task,
            [
                {
                    "type": "open_condition_wait",
                    "condition_key": "signals-queries-replay-barrier",
                    "timeout_seconds": 60,
                },
            ],
        )
        if int(replay_complete["status_code"]) >= 400:
            raise RuntimeError(f"replay timing workflow task completion failed: {replay_complete}")
        replay_completed_at = now()

        query_responder.join(timeout=20)
        query_thread.join(timeout=20)
        if query_responder.is_alive() or query_task_holder.get("error"):
            raise RuntimeError(f"query during replay responder failed: {query_task_holder.get('error', 'timeout')}")
        if query_thread.is_alive():
            raise RuntimeError("query during replay API call timed out")

        signal_apply_poll = poll_workflow_task(base_url, token, namespace, probe_worker_id, probe_task_queue)
        signal_apply_task = task_from_poll(signal_apply_poll, "signal application")
        if signal_name_from_task(signal_apply_task) != "increment":
            raise RuntimeError(f"signal application task did not carry increment signal: {signal_apply_task}")

        signal_apply_complete = complete_workflow_task(
            base_url,
            token,
            namespace,
            signal_apply_task,
            [
                {
                    "type": "open_condition_wait",
                    "condition_key": "signals-queries-after-signal",
                    "timeout_seconds": 60,
                },
            ],
        )
        if int(signal_apply_complete["status_code"]) >= 400:
            raise RuntimeError(f"signal application workflow task completion failed: {signal_apply_complete}")
        signal_applied_at = now()

        query_response = query_holder.get("response") if isinstance(query_holder.get("response"), dict) else {}
        query_body = query_response.get("body") if isinstance(query_response, dict) else {}
        query_answer = query_body.get("result") if isinstance(query_body, dict) else None

        terminal_start = http_json(
            base_url,
            api_path("workflows"),
            method="POST",
            body={
                "workflow_id": terminal_workflow_id,
                "workflow_type": workflow_type,
                "task_queue": probe_task_queue,
            },
            token=token,
            namespace=namespace,
            timeout=30,
        )
        if int(terminal_start["status_code"]) >= 400:
            raise RuntimeError(f"terminal workflow start failed: {terminal_start}")

        terminal_run_id = str(terminal_start["body"]["run_id"])
        terminal_poll = poll_workflow_task(base_url, token, namespace, probe_worker_id, probe_task_queue)
        terminal_task = task_from_poll(terminal_poll, "terminal")
        terminal_complete = complete_workflow_task(
            base_url,
            token,
            namespace,
            terminal_task,
            [
                {
                    "type": "complete_workflow",
                    "payload_codec": "json",
                    "result": json.dumps({"counter": 0, "status": "completed"}, sort_keys=True),
                },
            ],
        )
        if int(terminal_complete["status_code"]) >= 400:
            raise RuntimeError(f"terminal workflow completion failed: {terminal_complete}")
        completed_at = now()

        terminal_signal = http_json(
            base_url,
            api_path("workflows", terminal_workflow_id, "signal", "increment"),
            method="POST",
            body={"input": {"amount": 1}},
            token=token,
            namespace=namespace,
            timeout=30,
        )

        terminal_query_holder: dict[str, Any] = {}
        terminal_query_thread = threading.Thread(
            target=workflow_query_call,
            args=(base_url, token, namespace, terminal_workflow_id, "state", terminal_query_holder),
            daemon=True,
        )
        terminal_query_thread.start()

        terminal_query_task_holder: dict[str, Any] = {}
        terminal_query_responder: threading.Thread | None = None
        terminal_query_thread.join(timeout=0.5)
        if terminal_query_thread.is_alive():
            terminal_query_responder = threading.Thread(
                target=answer_next_query_task,
                args=(
                    base_url,
                    token,
                    namespace,
                    probe_worker_id,
                    probe_task_queue,
                    {"counter": 0, "status": "completed"},
                    log_file,
                    terminal_query_task_holder,
                    5.0,
                ),
                daemon=True,
            )
            terminal_query_responder.start()
            terminal_query_responder.join(timeout=8)
        terminal_query_thread.join(timeout=20)
        if terminal_query_thread.is_alive():
            raise RuntimeError("completed-run query API call timed out")

        terminal_query = (
            terminal_query_holder.get("response")
            if isinstance(terminal_query_holder.get("response"), dict)
            else {}
        )
        terminal_query_body = terminal_query.get("body") if isinstance(terminal_query, dict) else {}
        terminal_query_status = int(terminal_query.get("status_code") or 0)
        if terminal_query_responder is not None and (
            terminal_query_responder.is_alive() or terminal_query_task_holder.get("error")
        ):
            if terminal_query_status < 400 or terminal_query_status > 499:
                raise RuntimeError(
                    f"completed-run query responder failed: {terminal_query_task_holder.get('error', 'timeout')}"
                )

        signal_outputs = {
            "signal_api_sample": {
                "method": "POST",
                "path": api_path("workflows", replay_workflow_id, "signal", "increment"),
                "body": {"input": {"amount": 5}},
                "response": response_sample(signal_response),
            },
            "signal_status_code": signal_response.get("status_code"),
            "worker_restart_at": worker_restart_at,
            "signal_sent_at": signal_sent_at,
            "replay_completed_at": replay_completed_at,
            "signal_applied_at": signal_applied_at,
            "workflow_id": replay_workflow_id,
            "run_id": replay_run_id,
            "leased_replay_task_id": replay_task.get("task_id"),
            "signal_application_task_id": signal_apply_task.get("task_id"),
            "signal_application_history_event_types": history_event_types_from_task(signal_apply_task),
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }
        query_outputs = {
            "query_api_sample": {
                "method": "POST",
                "path": api_path("workflows", replay_workflow_id, "query", "state"),
                "body": {},
                "response": response_sample(query_response),
            },
            "query_status_code": query_response.get("status_code"),
            "worker_restart_at": worker_restart_at,
            "query_sent_at": query_holder.get("query_sent_at"),
            "query_poll_started_at": query_task_holder.get("query_poll_started_at"),
            "replay_completed_at": replay_completed_at,
            "query_handler_invoked_at": query_task_holder.get("query_handler_invoked_at"),
            "query_completed_at": query_holder.get("query_completed_at") or query_task_holder.get("query_completed_at"),
            "query_answer": query_answer,
            "expected_answer": 0,
            "query_task_id": (
                query_task_holder.get("query_task", {}).get("query_task_id")
                if isinstance(query_task_holder.get("query_task"), dict)
                else None
            ),
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }
        terminal_outputs = {
            "completed_run_id": terminal_run_id,
            "completed_at": completed_at,
            "signal_api_sample": {
                "method": "POST",
                "path": api_path("workflows", terminal_workflow_id, "signal", "increment"),
                "body": {"input": {"amount": 1}},
                "response": response_sample(terminal_signal),
            },
            "signal_error": response_sample(terminal_signal),
            "query_api_sample": {
                "method": "POST",
                "path": api_path("workflows", terminal_workflow_id, "query", "state"),
                "body": {},
                "response": response_sample(terminal_query),
            },
            "query_result_or_error": {
                "status_code": terminal_query.get("status_code"),
                "reason": terminal_query_body.get("reason") if isinstance(terminal_query_body, dict) else None,
                "outcome": "completed_query_replayed_final_state"
                if int(terminal_query.get("status_code") or 0) < 400
                else "completed_query_typed_error",
                "result": terminal_query_body.get("result") if isinstance(terminal_query_body, dict) else None,
            },
            "public_query_surfaces": [
                "control-plane-api",
                "worker-query-task-protocol",
            ],
            "run_status_after_operations": run_status(base_url, token, namespace, terminal_workflow_id),
            "workflow_id": terminal_workflow_id,
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }

        evidence = {
            "artifact_versions": versions,
            "scenario_results": {
                "signal_during_replay": {
                    "status": "pass",
                    "observed_outputs": signal_outputs,
                },
                "query_during_replay": {
                    "status": "pass",
                    "observed_outputs": query_outputs,
                },
                "completed_run_signal_and_query": {
                    "status": "pass",
                    "observed_outputs": terminal_outputs,
                },
            },
        }
        descriptor = {
            "workflow_id": replay_workflow_id,
            "run_id": replay_run_id,
            "worker_id": probe_worker_id,
            "task_queue": probe_task_queue,
            "completed_workflow_id": terminal_workflow_id,
            "completed_run_id": terminal_run_id,
            "server_base_url": base_url,
            "generated_scenarios": [
                "signal_during_replay",
                "query_during_replay",
                "completed_run_signal_and_query",
            ],
        }
        return evidence, descriptor
    except Exception as exc:  # noqa: BLE001 - failed probe becomes uncovered evidence.
        log_line(log_file, f"replay/terminal probe failed: {type(exc).__name__}: {exc}")
        return None, {
            "file": log_file.name,
            "error": f"{type(exc).__name__}: {exc}",
        }


def probe_artifact_versions() -> dict[str, str]:
    return {
        "server": artifact_version_value(artifact_versions, "server"),
        "cli": artifact_version_value(artifact_versions, "cli"),
        "sdk-python": artifact_version_value(artifact_versions, "sdk-python"),
        "workflow-php": artifact_version_value(artifact_versions, "workflow-php"),
        "waterline": artifact_version_value(artifact_versions, "waterline"),
    }


def probe_artifact_sources(
    cleanup_commands: list[list[str]],
    install_entries: dict[str, dict[str, Any]] | None = None,
) -> dict[str, str]:
    sources = dict(EXPECTED_ARTIFACT_SOURCES)
    if not cleanup_commands:
        sources["server"] = "configured_server_endpoint"
    for artifact, entry in (install_entries or {}).items():
        source = str(entry.get("source") or "").strip()
        if source:
            sources[artifact] = source
    return sources


def write_python_sdk_counter_worker(run_root: Path) -> Path:
    worker_script = run_root / "python-sdk-counter-worker.py"
    worker_script.write_text(
        r'''
from __future__ import annotations

import asyncio
import json
import logging
from datetime import datetime, timezone
from pathlib import Path
import signal
import sys

from durable_workflow import Client, PassthroughWorkerInterceptor, Worker, workflow


@workflow.defn(name="conformance.counter")
class CounterWorkflow:
    def __init__(self) -> None:
        self.count = 0

    @workflow.signal("increment")
    def increment(self, amount: int) -> None:
        self.count += amount

    @workflow.query("state")
    def state(self) -> int:
        return self.count

    @workflow.query("current")
    def current(self) -> int:
        return self.count

    @workflow.query("count-at-least")
    def count_at_least(self, minimum: int) -> bool:
        return self.count >= minimum

    def run(self, ctx):  # type: ignore[no-untyped-def]
        yield ctx.wait_condition(lambda: False, key="signals-queries-baseline-open", timeout=3600)


class QueryRouteEvidenceInterceptor(PassthroughWorkerInterceptor):
    def __init__(self, evidence_path: Path) -> None:
        self.evidence_path = evidence_path

    async def execute_query_task(self, context, next):  # type: ignore[no-untyped-def]
        task = context.task
        outcome = await next(context)
        record = {
            "schema": "durable-workflow.v2.signal-query-runtime.routed-current-query-task",
            "status": "pass" if outcome == "completed" else outcome,
            "worker_runtime": "sdk-python",
            "worker_id": context.worker_id,
            "task_queue": context.task_queue,
            "query_task_id": task.get("query_task_id"),
            "query_task_attempt": task.get("query_task_attempt"),
            "workflow_id": task.get("workflow_id"),
            "run_id": task.get("run_id"),
            "workflow_type": task.get("workflow_type"),
            "query_name": task.get("query_name"),
            "lease_owner": task.get("lease_owner"),
            "run_status": task.get("run_status"),
            "last_history_sequence": task.get("last_history_sequence"),
            "server_route": "worker_query_task_poll",
            "completion_route": "worker_query_task_complete",
            "observed_via": "sdk-python worker query task interceptor",
            "observed_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        }
        with self.evidence_path.open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(record, sort_keys=True) + "\n")
        return outcome


async def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(name)s %(levelname)s %(message)s")
    base_url, token, namespace, task_queue, worker_id, evidence_path = sys.argv[1:7]

    async with Client(base_url, token=token, namespace=namespace, timeout=30.0) as client:
        worker = Worker(
            client,
            task_queue=task_queue,
            workflows=[CounterWorkflow],
            worker_id=worker_id,
            interceptors=[QueryRouteEvidenceInterceptor(Path(evidence_path))],
            poll_timeout=5.0,
            max_concurrent_workflow_tasks=2,
            max_concurrent_activity_tasks=1,
            heartbeat_interval=5.0,
        )

        stop_task = asyncio.create_task(worker.run())

        def request_stop(_signum, _frame):  # type: ignore[no-untyped-def]
            worker._stop.set()  # noqa: SLF001 - conformance worker process owns this lifecycle.

        signal.signal(signal.SIGTERM, request_stop)
        signal.signal(signal.SIGINT, request_stop)

        await stop_task

    return 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
'''.lstrip(),
        encoding="utf-8",
    )
    return worker_script


def start_python_sdk_counter_worker(
    *,
    python_bin: str,
    base_url: str,
    token: str,
    namespace: str,
    task_queue: str,
    worker_id: str,
    query_route_evidence_path: Path,
    run_root: Path,
    log_file: Path,
) -> subprocess.Popen[str]:
    script = write_python_sdk_counter_worker(run_root)
    env = os.environ.copy()
    env.update(
        {
            "PYTHONUNBUFFERED": "1",
            "DURABLE_WORKFLOW_SERVER_URL": base_url,
            "DURABLE_WORKFLOW_AUTH_TOKEN": token,
            "DURABLE_WORKFLOW_NAMESPACE": namespace,
        }
    )
    log_line(log_file, f"starting Python SDK worker {worker_id} on {task_queue}")
    worker_output = log_file.open("a", encoding="utf-8")
    try:
        worker_output.write(f"{now()} python-sdk-worker-output-begin\n")
        worker_output.flush()
        return subprocess.Popen(
            [
                python_bin,
                str(script),
                base_url,
                token,
                namespace,
                task_queue,
                worker_id,
                str(query_route_evidence_path),
            ],
            cwd=str(run_root),
            env=env,
            text=True,
            stdout=worker_output,
            stderr=worker_output,
        )
    finally:
        worker_output.close()


def stop_python_sdk_counter_worker(process: subprocess.Popen[str], log_file: Path) -> None:
    if process.poll() is None:
        process.terminate()
        try:
            process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=10)


def wait_for_worker_registered(
    *,
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    process: subprocess.Popen[str],
    log_file: Path,
    timeout_seconds: float = 45.0,
) -> dict[str, Any]:
    deadline = time.time() + timeout_seconds
    last_response: dict[str, Any] | None = None
    while time.time() < deadline:
        return_code = process.poll()
        if return_code is not None:
            raise RuntimeError(
                f"Python SDK worker exited before registration with code {return_code}; "
                f"see {log_file.name}"
            )

        response = http_json(
            base_url,
            api_path("workers", worker_id),
            token=token,
            namespace=namespace,
            timeout=5,
        )
        last_response = response
        if int(response.get("status_code") or 0) == 200 and isinstance(response.get("body"), dict):
            return response["body"]
        time.sleep(0.5)

    log_line(log_file, f"last worker registration probe response: {last_response}")
    raise RuntimeError(f"Python SDK worker {worker_id} did not register within {timeout_seconds}s")


def wait_for_query_result(
    *,
    sample_factory: Any,
    expected: Any,
    label: str,
    log_file: Path,
    timeout_seconds: float = 60.0,
    last_sample_holder: dict[str, Any] | None = None,
    last_sample_key: str | None = None,
) -> dict[str, Any]:
    deadline = time.time() + timeout_seconds
    last_sample: dict[str, Any] | None = None
    while time.time() < deadline:
        sample = sample_factory()
        last_sample = sample
        if last_sample_holder is not None and last_sample_key is not None:
            last_sample_holder[last_sample_key] = sample
        if public_sample_ok(sample) and sample_result_value(sample) == expected:
            return sample
        time.sleep(0.5)

    log_line(log_file, f"{label} last sample: {last_sample}")
    raise RuntimeError(f"{label} did not return {expected!r} within {timeout_seconds}s")


def install_evidence_for_artifacts(
    versions: dict[str, str],
    sources: dict[str, str],
    artifacts: tuple[str, ...],
    install_entries: dict[str, dict[str, Any]] | None = None,
) -> dict[str, Any]:
    entries = install_entries or {}
    return {
        "local_product_source_checkouts_used": False,
        "artifacts": [
            dict(entries[artifact])
            if artifact in entries
            else {
                "artifact": artifact,
                "status": "pass",
                "version": artifact_version_value(versions, artifact),
                "source": artifact_source_value(sources, artifact),
                "local_product_source_checkouts_used": False,
            }
            for artifact in artifacts
        ],
    }


def query_result_is(sample: dict[str, Any], expected: Any) -> bool:
    return public_sample_ok(sample) and sample_result_value(sample) == expected


def run_python_worker_php_facing_and_cli_clients(
    *,
    base_url: str,
    token: str,
    namespace: str,
    cli_bin: str,
    workflow_php_project: Path,
    task_queue: str,
    worker_id: str,
    workflow_type: str,
    versions: dict[str, str],
    sources: dict[str, str],
    log_file: Path,
) -> dict[str, Any]:
    suffix = hashlib.sha1(f"{time.time()}-python-worker-php-clients".encode("utf-8")).hexdigest()[:10]
    workflow_id = f"wf-sq-python-php-clients-{suffix}"
    outputs: dict[str, Any] = {
        "worker_runtime": "sdk-python",
        "worker_id": worker_id,
        "task_queue": task_queue,
        "workflow_id": workflow_id,
        "workflow_type": workflow_type,
        "public_clients": ["workflow-php-sdk", "cli"],
        "published_artifact_versions": versions,
        "artifact_sources": sources,
    }

    try:
        start_sample = php_workflow_client_sample(
            workflow_php_project,
            base_url,
            token,
            namespace,
            "start",
            workflow_type,
            workflow_id,
            task_queue,
            "start",
            log_file,
        )
        outputs["workflow_php_start_sample"] = start_sample
        if not public_sample_ok(start_sample):
            raise RuntimeError(f"PHP client start against Python worker failed: {start_sample}")

        run_id = workflow_start_run_id(start_sample)
        if not run_id:
            raise RuntimeError(f"PHP client start against Python worker did not return a run_id: {start_sample}")
        outputs["run_id"] = run_id

        initial_php_query = wait_for_query_result(
            label="Python worker PHP client initial query",
            expected=0,
            log_file=log_file,
            sample_factory=lambda: php_workflow_client_sample(
                workflow_php_project,
                base_url,
                token,
                namespace,
                "query",
                workflow_type,
                workflow_id,
                task_queue,
                "current",
                log_file,
            ),
        )
        outputs["workflow_php_initial_query_sample"] = initial_php_query

        php_signal = php_workflow_client_sample(
            workflow_php_project,
            base_url,
            token,
            namespace,
            "signal",
            workflow_type,
            workflow_id,
            task_queue,
            "increment",
            log_file,
            args=[4],
        )
        outputs["workflow_php_signal_sample"] = php_signal
        if not public_sample_ok(php_signal):
            raise RuntimeError(f"PHP client signal against Python worker failed: {php_signal}")

        php_query = wait_for_query_result(
            label="Python worker PHP client query after PHP signal",
            expected=4,
            log_file=log_file,
            sample_factory=lambda: php_workflow_client_sample(
                workflow_php_project,
                base_url,
                token,
                namespace,
                "query",
                workflow_type,
                workflow_id,
                task_queue,
                "current",
                log_file,
            ),
        )
        outputs["workflow_php_query_sample"] = php_query

        cli_signal = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:signal",
                workflow_id,
                "increment",
                "--input",
                "[6]",
                "--output=json",
            ],
            log_file,
        )
        outputs["cli_signal_sample"] = cli_signal
        if not public_sample_ok(cli_signal):
            raise RuntimeError(f"CLI signal against Python worker failed: {cli_signal}")

        cli_query = wait_for_query_result(
            label="Python worker CLI query after PHP and CLI signals",
            expected=10,
            log_file=log_file,
            sample_factory=lambda: cli_json_sample(
                cli_bin,
                base_url,
                token,
                namespace,
                [
                    "workflow:query",
                    workflow_id,
                    "current",
                    "--output=json",
                ],
                log_file,
            ),
        )
        outputs["cli_query_sample"] = cli_query

        repeat_php_query = wait_for_query_result(
            label="Python worker PHP client repeat query after CLI signal",
            expected=10,
            log_file=log_file,
            sample_factory=lambda: php_workflow_client_sample(
                workflow_php_project,
                base_url,
                token,
                namespace,
                "query",
                workflow_type,
                workflow_id,
                task_queue,
                "current",
                log_file,
            ),
        )
        outputs["workflow_php_repeat_query_sample"] = repeat_php_query

        outputs["php_client_signal_and_query"] = (
            query_result_is(initial_php_query, 0)
            and public_sample_ok(php_signal)
            and query_result_is(php_query, 4)
        )
        outputs["cli_signal_and_query"] = public_sample_ok(cli_signal) and query_result_is(cli_query, 10)
        outputs["cross_language_query_consistency"] = (
            sample_result_value(cli_query) == sample_result_value(repeat_php_query) == 10
        )
        outputs["wire_envelope_compatibility"] = (
            public_sample_ok(start_sample)
            and public_sample_ok(php_signal)
            and public_sample_ok(php_query)
            and public_sample_ok(cli_signal)
            and public_sample_ok(cli_query)
        )
        outputs["observed_values"] = {
            "initial_php_query": sample_result_value(initial_php_query),
            "after_php_signal_php_query": sample_result_value(php_query),
            "after_cli_signal_cli_query": sample_result_value(cli_query),
            "after_cli_signal_php_repeat_query": sample_result_value(repeat_php_query),
        }
        outputs["commands_and_api_samples"] = {
            "workflow_php_start": start_sample.get("api_sample"),
            "workflow_php_signal": php_signal.get("api_sample"),
            "workflow_php_query": php_query.get("api_sample"),
            "cli_signal": cli_signal.get("command"),
            "cli_query": cli_query.get("command"),
        }
    except Exception as exc:  # noqa: BLE001 - partial public samples are the failure evidence.
        outputs["probe_error"] = probe_error_payload(exc)
        log_line(
            log_file,
            "Python worker PHP-facing/CLI client matrix probe failed: "
            f"{type(exc).__name__}: {exc}",
        )

    return outputs


def run_php_worker_python_and_cli_clients(
    *,
    base_url: str,
    token: str,
    namespace: str,
    cli_bin: str,
    python_bin: str,
    task_queue: str,
    worker_id: str,
    workflow_type: str,
    versions: dict[str, str],
    sources: dict[str, str],
    log_file: Path,
) -> dict[str, Any]:
    suffix = hashlib.sha1(f"{time.time()}-php-worker-python-clients".encode("utf-8")).hexdigest()[:10]
    workflow_id = f"wf-sq-php-python-clients-{suffix}"
    outputs: dict[str, Any] = {
        "worker_runtime": "workflow-php",
        "worker_id": worker_id,
        "task_queue": task_queue,
        "workflow_id": workflow_id,
        "workflow_type": workflow_type,
        "public_clients": ["sdk-python", "cli"],
        "published_artifact_versions": versions,
        "artifact_sources": sources,
    }

    try:
        start_sample = sdk_start_workflow_sample(
            python_bin,
            base_url,
            token,
            namespace,
            workflow_id,
            workflow_type,
            task_queue,
            log_file,
        )
        outputs["sdk_python_start_sample"] = start_sample
        if not public_sample_ok(start_sample):
            raise RuntimeError(f"Python SDK start against PHP worker failed: {start_sample}")

        run_id = workflow_start_run_id(start_sample)
        if not run_id:
            raise RuntimeError(f"Python SDK start against PHP worker did not return a run_id: {start_sample}")
        outputs["run_id"] = run_id

        initial_cli_query = wait_for_query_result(
            label="PHP worker CLI initial query after Python SDK start",
            expected=0,
            log_file=log_file,
            sample_factory=lambda: cli_json_sample(
                cli_bin,
                base_url,
                token,
                namespace,
                [
                    "workflow:query",
                    workflow_id,
                    "current",
                    "--output=json",
                ],
                log_file,
            ),
        )
        outputs["cli_initial_query_sample"] = initial_cli_query

        sdk_signal = sdk_success_sample(
            python_bin,
            base_url,
            token,
            namespace,
            workflow_id,
            "signal",
            "increment",
            log_file,
            args=[4],
        )
        outputs["sdk_python_signal_sample"] = sdk_signal
        if not public_sample_ok(sdk_signal):
            raise RuntimeError(f"Python SDK signal against PHP worker failed: {sdk_signal}")

        sdk_query = wait_for_query_result(
            label="PHP worker Python SDK query after Python SDK signal",
            expected=4,
            log_file=log_file,
            sample_factory=lambda: sdk_success_sample(
                python_bin,
                base_url,
                token,
                namespace,
                workflow_id,
                "query",
                "current",
                log_file,
            ),
        )
        outputs["sdk_python_query_sample"] = sdk_query

        cli_signal = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:signal",
                workflow_id,
                "increment",
                "--input",
                "[6]",
                "--output=json",
            ],
            log_file,
        )
        outputs["cli_signal_sample"] = cli_signal
        if not public_sample_ok(cli_signal):
            raise RuntimeError(f"CLI signal against PHP worker failed: {cli_signal}")

        cli_query = wait_for_query_result(
            label="PHP worker CLI query after Python SDK and CLI signals",
            expected=10,
            log_file=log_file,
            sample_factory=lambda: cli_json_sample(
                cli_bin,
                base_url,
                token,
                namespace,
                [
                    "workflow:query",
                    workflow_id,
                    "current",
                    "--output=json",
                ],
                log_file,
            ),
        )
        outputs["cli_query_sample"] = cli_query

        repeat_sdk_query = wait_for_query_result(
            label="PHP worker Python SDK repeat query after CLI signal",
            expected=10,
            log_file=log_file,
            sample_factory=lambda: sdk_success_sample(
                python_bin,
                base_url,
                token,
                namespace,
                workflow_id,
                "query",
                "current",
                log_file,
            ),
        )
        outputs["sdk_python_repeat_query_sample"] = repeat_sdk_query

        outputs["sdk_python_signal_and_query"] = (
            query_result_is(initial_cli_query, 0)
            and public_sample_ok(sdk_signal)
            and query_result_is(sdk_query, 4)
        )
        outputs["cli_signal_and_query"] = public_sample_ok(cli_signal) and query_result_is(cli_query, 10)
        outputs["cross_language_query_consistency"] = (
            sample_result_value(cli_query) == sample_result_value(repeat_sdk_query) == 10
        )
        outputs["wire_envelope_compatibility"] = (
            public_sample_ok(start_sample)
            and public_sample_ok(sdk_signal)
            and public_sample_ok(sdk_query)
            and public_sample_ok(cli_signal)
            and public_sample_ok(cli_query)
        )
        outputs["observed_values"] = {
            "initial_cli_query": sample_result_value(initial_cli_query),
            "after_python_signal_python_query": sample_result_value(sdk_query),
            "after_cli_signal_cli_query": sample_result_value(cli_query),
            "after_cli_signal_python_repeat_query": sample_result_value(repeat_sdk_query),
        }
        outputs["commands_and_api_samples"] = {
            "sdk_python_start": start_sample.get("api_sample"),
            "sdk_python_signal": {
                "client": "sdk-python",
                "operation": "signal",
                "workflow_id": workflow_id,
                "operation_name": "increment",
                "arguments": [4],
                "wire_input_shape": "durable-workflow.v2.payload-envelope",
            },
            "sdk_python_query": {
                "client": "sdk-python",
                "operation": "query",
                "workflow_id": workflow_id,
                "operation_name": "current",
                "wire_input_shape": "durable-workflow.v2.payload-envelope",
            },
            "cli_signal": cli_signal.get("command"),
            "cli_query": cli_query.get("command"),
        }
    except Exception as exc:  # noqa: BLE001 - partial public samples are the failure evidence.
        outputs["probe_error"] = probe_error_payload(exc)
        log_line(
            log_file,
            "PHP worker Python/CLI client matrix probe failed: "
            f"{type(exc).__name__}: {exc}",
        )

    return outputs


def run_python_sdk_baseline(
    *,
    base_url: str,
    token: str,
    namespace: str,
    cli_bin: str,
    python_bin: str,
    versions: dict[str, str],
    sources: dict[str, str],
    run_root: Path,
    log_file: Path,
    workflow_php_project: Path | None = None,
) -> tuple[dict[str, Any], dict[str, Any]]:
    suffix = hashlib.sha1(f"{time.time()}-python-sdk-baseline".encode("utf-8")).hexdigest()[:10]
    task_queue = f"signals-queries-python-sdk-{suffix}"
    worker_id = f"signals-queries-python-sdk-worker-{suffix}"
    workflow_type = "conformance.counter"
    workflow_id = f"wf-sq-python-sdk-{suffix}"
    query_route_evidence_path = run_root / f"{worker_id}-query-route-evidence.jsonl"
    worker_process = start_python_sdk_counter_worker(
        python_bin=python_bin,
        base_url=base_url,
        token=token,
        namespace=namespace,
        task_queue=task_queue,
        worker_id=worker_id,
        query_route_evidence_path=query_route_evidence_path,
        run_root=run_root,
        log_file=log_file,
    )
    outputs: dict[str, Any] = {
        "worker_runtime": "sdk-python",
        "python_worker_artifact_source": sources["sdk-python"],
        "python_worker_sdk_version": versions["sdk-python"],
        "workflow_id": workflow_id,
        "task_queue": task_queue,
        "worker_id": worker_id,
        "published_artifact_versions": versions,
        "artifact_sources": sources,
    }
    descriptor: dict[str, Any] = {
        "worker_id": worker_id,
        "task_queue": task_queue,
        "workflow_id": workflow_id,
        "worker_runtime": "sdk-python",
        "worker_source": sources["sdk-python"],
        "worker_sdk_version": outputs["python_worker_sdk_version"],
        "log_file": log_file.name,
    }

    try:
        installed_sdk_version = python_sdk_distribution_version(python_bin, log_file)
        outputs["python_worker_sdk_version"] = installed_sdk_version or versions["sdk-python"]
        descriptor["worker_sdk_version"] = outputs["python_worker_sdk_version"]

        worker_registration = wait_for_worker_registered(
            base_url=base_url,
            token=token,
            namespace=namespace,
            worker_id=worker_id,
            process=worker_process,
            log_file=log_file,
        )
        outputs["worker_registration"] = worker_registration
        capabilities = worker_registration.get("capabilities") if isinstance(worker_registration, dict) else None
        if isinstance(capabilities, list) and "query_tasks" in capabilities:
            outputs["python_worker_query_task_routing"] = True

        start = http_json(
            base_url,
            api_path("workflows"),
            method="POST",
            body={
                "workflow_id": workflow_id,
                "workflow_type": workflow_type,
                "task_queue": task_queue,
            },
            token=token,
            namespace=namespace,
            timeout=30,
        )
        if int(start["status_code"]) >= 400:
            raise RuntimeError(f"Python SDK baseline workflow start failed: {start}")

        run_id = str(start["body"].get("run_id", ""))
        outputs["run_id"] = run_id
        descriptor["run_id"] = run_id
        initial_query = wait_for_query_result(
            label="initial Python SDK worker CLI query",
            expected=0,
            log_file=log_file,
            sample_factory=lambda: cli_json_sample(
                cli_bin,
                base_url,
                token,
                namespace,
                [
                    "workflow:query",
                    workflow_id,
                    "state",
                    "--output=json",
                ],
                log_file,
            ),
        )
        outputs["initial_query_sample"] = initial_query

        cli_signal = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:signal",
                workflow_id,
                "increment",
                "--input",
                "[3]",
                "--output=json",
            ],
            log_file,
        )
        if not public_sample_ok(cli_signal):
            raise RuntimeError(f"Python SDK baseline CLI signal failed: {cli_signal}")
        outputs["cli_signal_sample"] = cli_signal

        cli_query = wait_for_query_result(
            label="Python SDK worker CLI query after CLI signal",
            expected=3,
            log_file=log_file,
            sample_factory=lambda: cli_json_sample(
                cli_bin,
                base_url,
                token,
                namespace,
                [
                    "workflow:query",
                    workflow_id,
                    "current",
                    "--output=json",
                ],
                log_file,
            ),
        )
        outputs["cli_query_sample"] = cli_query
        outputs["cli_signal_and_query"] = (
            public_sample_ok(cli_signal)
            and public_sample_ok(cli_query)
            and sample_result_value(cli_query) == 3
        )
        routed_current_query_task: dict[str, Any] | None = None
        routed_current_query_task_error: dict[str, str] | None = None
        try:
            routed_current_query_task = wait_for_routed_current_query_task(
                evidence_path=query_route_evidence_path,
                workflow_id=workflow_id,
                run_id=run_id,
                workflow_type=workflow_type,
                task_queue=task_queue,
                worker_id=worker_id,
                public_query_surface="cli",
                log_file=log_file,
            )
        except Exception as exc:  # noqa: BLE001 - retain public client proof for focused missing-route evidence.
            routed_current_query_task_error = probe_error_payload(exc)
            outputs["routed_current_query_task_error"] = routed_current_query_task_error
            descriptor["routed_current_query_task_error"] = routed_current_query_task_error
            log_line(
                log_file,
                "Python SDK baseline routed current query task proof missing: "
                f"{type(exc).__name__}: {exc}",
            )
        if routed_current_query_task is not None:
            outputs["routed_current_query_task"] = routed_current_query_task

        try:
            sdk_signal = sdk_success_sample(
                python_bin,
                base_url,
                token,
                namespace,
                workflow_id,
                "signal",
                "increment",
                log_file,
                args=[5],
            )
        except Exception as exc:  # noqa: BLE001 - preserve SDK failure as product behavior evidence.
            outputs["sdk_python_signal_and_query"] = False
            outputs["sdk_python_signal_and_query_error"] = probe_error_payload(exc)
            raise
        outputs["sdk_python_signal_sample"] = sdk_signal
        if not public_sample_ok(sdk_signal):
            outputs["sdk_python_signal_and_query"] = False
            outputs["sdk_python_signal_and_query_error"] = probe_error_payload(
                RuntimeError(f"Python SDK baseline SDK signal failed: {sdk_signal}")
            )
            raise RuntimeError(f"Python SDK baseline SDK signal failed: {sdk_signal}")

        try:
            sdk_query = wait_for_query_result(
                label="Python SDK worker SDK query after SDK signal",
                expected=8,
                log_file=log_file,
                sample_factory=lambda: sdk_success_sample(
                    python_bin,
                    base_url,
                    token,
                    namespace,
                    workflow_id,
                    "query",
                    "current",
                    log_file,
                ),
                last_sample_holder=outputs,
                last_sample_key="sdk_python_query_sample",
            )
        except Exception as exc:  # noqa: BLE001 - preserve public SDK evidence for product-failure routing.
            outputs["sdk_python_signal_and_query"] = False
            outputs["sdk_python_signal_and_query_error"] = probe_error_payload(exc)
            raise
        outputs["sdk_python_query_sample"] = sdk_query
        outputs["sdk_python_signal_and_query"] = (
            public_sample_ok(sdk_signal)
            and public_sample_ok(sdk_query)
            and sample_result_value(sdk_query) == 8
        )

        try:
            repeat_query = wait_for_query_result(
                label="Python SDK worker repeat CLI query",
                expected=8,
                log_file=log_file,
                sample_factory=lambda: cli_json_sample(
                    cli_bin,
                    base_url,
                    token,
                    namespace,
                    [
                        "workflow:query",
                        workflow_id,
                        "current",
                        "--output=json",
                    ],
                    log_file,
                ),
                last_sample_holder=outputs,
                last_sample_key="repeat_query_sample",
            )
        except Exception as exc:  # noqa: BLE001 - preserve repeat-query evidence for product-failure routing.
            outputs["immediate_repeat_query_consistency"] = False
            outputs["immediate_repeat_query_consistency_error"] = probe_error_payload(exc)
            raise
        outputs["repeat_query_sample"] = repeat_query
        outputs["immediate_repeat_query_consistency"] = (
            sample_result_value(repeat_query) == sample_result_value(sdk_query)
        )

        if workflow_php_project is not None:
            cross_language_outputs = run_python_worker_php_facing_and_cli_clients(
                base_url=base_url,
                token=token,
                namespace=namespace,
                cli_bin=cli_bin,
                workflow_php_project=workflow_php_project,
                task_queue=task_queue,
                worker_id=worker_id,
                workflow_type=workflow_type,
                versions=versions,
                sources=sources,
                log_file=log_file,
            )
            descriptor["python_worker_php_facing_and_cli_clients"] = {
                "workflow_id": cross_language_outputs.get("workflow_id"),
                "run_id": cross_language_outputs.get("run_id"),
                "worker_id": worker_id,
                "task_queue": task_queue,
                "status": baseline_scenario_result(
                    "python_worker_php_facing_and_cli_clients",
                    cross_language_outputs,
                )["status"],
            }
            descriptor.setdefault("cross_language_scenario_results", {})[
                "python_worker_php_facing_and_cli_clients"
            ] = baseline_scenario_result(
                "python_worker_php_facing_and_cli_clients",
                cross_language_outputs,
            )

        return outputs, descriptor
    except Exception as exc:  # noqa: BLE001 - retain any public evidence collected before the probe stopped.
        error = probe_error_payload(exc)
        outputs["probe_error"] = error
        descriptor["error"] = f"{type(exc).__name__}: {exc}"
        log_line(log_file, f"Python SDK baseline probe stopped after partial evidence: {type(exc).__name__}: {exc}")
        return outputs, descriptor
    finally:
        stop_python_sdk_counter_worker(worker_process, log_file)


def baseline_scenario_result(scenario: str, observed: dict[str, Any]) -> dict[str, Any]:
    if current_behavior_failures_for(scenario, observed):
        status = "fail"
    elif has_required_evidence(scenario, observed):
        status = "pass"
    else:
        status = "not_covered"

    return {
        "status": status,
        "observed_outputs": observed,
    }


def run_workflow_php_baseline(
    *,
    base_url: str,
    token: str,
    namespace: str,
    cli_bin: str,
    python_bin: str | None = None,
    workflow_php_project: Path,
    versions: dict[str, str],
    sources: dict[str, str],
    install_entry: dict[str, Any],
    run_root: Path,
    log_file: Path,
) -> tuple[dict[str, Any], dict[str, Any]]:
    suffix = hashlib.sha1(f"{time.time()}-workflow-php-baseline".encode("utf-8")).hexdigest()[:10]
    task_queue = f"signals-queries-workflow-php-{suffix}"
    worker_id = f"signals-queries-workflow-php-worker-{suffix}"
    workflow_type = "conformance.counter.php"
    workflow_id = f"wf-sq-workflow-php-{suffix}"
    container_name = f"dw-sq-php-{suffix}"
    query_route_evidence_path = workflow_php_project / f"{worker_id}-query-route-evidence.jsonl"
    container_evidence_path = f"/app/{query_route_evidence_path.name}"

    outputs: dict[str, Any] = {
        "worker_runtime": "workflow-php",
        "workflow_php_artifact_source": sources["workflow-php"],
        "workflow_php_sdk_version": versions["workflow-php"],
        "workflow_id": workflow_id,
        "task_queue": task_queue,
        "worker_id": worker_id,
        "published_artifact_versions": versions,
        "artifact_sources": sources,
        "workflow_php_install_evidence": install_entry,
    }
    descriptor: dict[str, Any] = {
        "worker_id": worker_id,
        "task_queue": task_queue,
        "workflow_id": workflow_id,
        "worker_runtime": "workflow-php",
        "worker_source": sources["workflow-php"],
        "worker_sdk_version": outputs["workflow_php_sdk_version"],
        "runtime_image": workflow_php_docker_image(),
        "log_file": log_file.name,
    }

    start = run_command(
        php_docker_command(
            workflow_php_project,
            [
                "php",
                "php-counter-worker.php",
                docker_host_base_url(base_url),
                token,
                namespace,
                task_queue,
                worker_id,
                container_evidence_path,
                "600",
            ],
            name=container_name,
            detach=True,
        ),
        log_file=log_file,
        timeout=90,
    )
    if start.returncode != 0:
        raise RuntimeError("PHP worker container failed to start")
    descriptor["container_name"] = container_name
    descriptor["container_id"] = start.stdout.strip()

    try:
        worker_registration = wait_for_docker_worker_registered(
            base_url=base_url,
            token=token,
            namespace=namespace,
            worker_id=worker_id,
            container_name=container_name,
            log_file=log_file,
        )
        outputs["worker_registration"] = worker_registration
        capabilities = worker_registration.get("capabilities") if isinstance(worker_registration, dict) else None
        if isinstance(capabilities, list) and "query_tasks" in capabilities:
            outputs["registered_query_task_capability"] = True

        start_sample = php_workflow_client_sample(
            workflow_php_project,
            base_url,
            token,
            namespace,
            "start",
            workflow_type,
            workflow_id,
            task_queue,
            "start",
            log_file,
        )
        outputs["workflow_php_start_sample"] = start_sample
        if not public_sample_ok(start_sample):
            raise RuntimeError(f"PHP baseline workflow start failed: {start_sample}")

        run_id = workflow_start_run_id(start_sample)
        if not run_id:
            raise RuntimeError(f"PHP baseline workflow start did not return a run_id: {start_sample}")
        outputs["run_id"] = run_id
        descriptor["run_id"] = run_id

        initial_query = wait_for_query_result(
            label="PHP worker initial CLI query",
            expected=0,
            log_file=log_file,
            sample_factory=lambda: cli_json_sample(
                cli_bin,
                base_url,
                token,
                namespace,
                [
                    "workflow:query",
                    workflow_id,
                    "state",
                    "--output=json",
                ],
                log_file,
            ),
        )
        outputs["initial_query_sample"] = initial_query

        cli_signal = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:signal",
                workflow_id,
                "increment",
                "--input",
                "[3]",
                "--output=json",
            ],
            log_file,
        )
        if not public_sample_ok(cli_signal):
            raise RuntimeError(f"PHP worker CLI signal failed: {cli_signal}")
        outputs["cli_signal_sample"] = cli_signal

        cli_query = wait_for_query_result(
            label="PHP worker CLI query after CLI signal",
            expected=3,
            log_file=log_file,
            sample_factory=lambda: cli_json_sample(
                cli_bin,
                base_url,
                token,
                namespace,
                [
                    "workflow:query",
                    workflow_id,
                    "current",
                    "--output=json",
                ],
                log_file,
            ),
        )
        outputs["cli_query_sample"] = cli_query
        outputs["cli_signal_and_query"] = (
            public_sample_ok(cli_signal)
            and public_sample_ok(cli_query)
            and sample_result_value(cli_query) == 3
        )

        php_signal = php_workflow_client_sample(
            workflow_php_project,
            base_url,
            token,
            namespace,
            "signal",
            workflow_type,
            workflow_id,
            task_queue,
            "increment",
            log_file,
            args=[5],
        )
        if not public_sample_ok(php_signal):
            raise RuntimeError(f"PHP SDK signal failed: {php_signal}")
        outputs["workflow_php_signal_sample"] = php_signal

        php_query = wait_for_query_result(
            label="PHP worker PHP SDK query after PHP SDK signal",
            expected=8,
            log_file=log_file,
            sample_factory=lambda: php_workflow_client_sample(
                workflow_php_project,
                base_url,
                token,
                namespace,
                "query",
                workflow_type,
                workflow_id,
                task_queue,
                "current",
                log_file,
            ),
        )
        outputs["workflow_php_query_sample"] = php_query
        outputs["workflow_php_signal_and_query"] = (
            public_sample_ok(php_signal)
            and public_sample_ok(php_query)
            and sample_result_value(php_query) == 8
        )

        repeat_query = wait_for_query_result(
            label="PHP worker repeat PHP SDK query",
            expected=8,
            log_file=log_file,
            sample_factory=lambda: php_workflow_client_sample(
                workflow_php_project,
                base_url,
                token,
                namespace,
                "query",
                workflow_type,
                workflow_id,
                task_queue,
                "current",
                log_file,
            ),
        )
        outputs["repeat_query_sample"] = repeat_query
        outputs["immediate_repeat_query_consistency"] = (
            sample_result_value(repeat_query) == sample_result_value(php_query)
        )

        routed_query = wait_for_php_query_route_evidence(
            query_route_evidence_path,
            workflow_id=workflow_id,
            worker_id=worker_id,
        )
        outputs["php_routed_current_query_task"] = routed_query
        outputs["php_worker_query_task_routing"] = True

        if python_bin is not None:
            cross_language_outputs = run_php_worker_python_and_cli_clients(
                base_url=base_url,
                token=token,
                namespace=namespace,
                cli_bin=cli_bin,
                python_bin=python_bin,
                task_queue=task_queue,
                worker_id=worker_id,
                workflow_type=workflow_type,
                versions=versions,
                sources=sources,
                log_file=log_file,
            )
            descriptor["php_worker_python_and_cli_clients"] = {
                "workflow_id": cross_language_outputs.get("workflow_id"),
                "run_id": cross_language_outputs.get("run_id"),
                "worker_id": worker_id,
                "task_queue": task_queue,
                "status": baseline_scenario_result(
                    "php_worker_python_and_cli_clients",
                    cross_language_outputs,
                )["status"],
            }
            descriptor.setdefault("cross_language_scenario_results", {})[
                "php_worker_python_and_cli_clients"
            ] = baseline_scenario_result(
                "php_worker_python_and_cli_clients",
                cross_language_outputs,
            )

        return outputs, descriptor
    except Exception as exc:  # noqa: BLE001 - retain partial PHP evidence for a focused mirror finding.
        error = probe_error_payload(exc)
        outputs["probe_error"] = error
        descriptor["error"] = f"{type(exc).__name__}: {exc}"
        log_line(log_file, f"PHP worker mirror probe stopped after partial evidence: {type(exc).__name__}: {exc}")
        return outputs, descriptor
    finally:
        capture_command_summary(["docker", "logs", container_name], log_file=log_file, timeout=60)
        run_command(["docker", "rm", "-f", container_name], log_file=log_file, timeout=60)


def probe_error_payload(exc: Exception) -> dict[str, str]:
    return {
        "type": type(exc).__name__,
        "message": str(exc),
    }


def run_baseline_probe(result_dir: Path) -> tuple[dict[str, Any] | None, dict[str, Any] | None]:
    if not env_flag("DW_SIGNALS_QUERIES_RUN_BASELINE_PROBE", True):
        return None, {"skipped": "disabled_by_env"}

    run_root = Path(
        env_text("DW_SIGNALS_QUERIES_BASELINE_RUN_ROOT")
        or tempfile.mkdtemp(prefix="dw-signals-queries-baseline.", dir=str(result_dir))
    )
    run_root.mkdir(parents=True, exist_ok=True)
    log_file = result_dir / "signals-queries-baseline-probe.log"
    cleanup_commands: list[list[str]] = []

    namespace = (
        env_text("DW_SIGNALS_QUERIES_NAMESPACE")
        or env_text("DURABLE_WORKFLOW_NAMESPACE")
        or "default"
    )
    token = (
        env_text("DW_SIGNALS_QUERIES_AUTH_TOKEN")
        or env_text("DURABLE_WORKFLOW_AUTH_TOKEN")
        or env_text("DW_AUTH_TOKEN")
        or "dev-token"
    )
    base_url = env_text("DW_SIGNALS_QUERIES_SERVER_URL") or env_text("DURABLE_WORKFLOW_SERVER_URL")
    readiness_probe: dict[str, Any] | None = None

    try:
        if not isinstance(base_url, str) or base_url.strip() == "":
            base_url, cleanup_commands, readiness_probe = start_published_server(run_root, log_file)
        else:
            base_url = base_url.rstrip("/")
            readiness_probe = wait_for_ready(
                base_url,
                log_file,
                timeout_seconds=server_ready_timeout_seconds(30),
                diagnostics=configured_server_diagnostics(base_url),
            )

        server_install = server_install_entry(cleanup_commands)
        versions = probe_artifact_versions()
        install_entries = {
            "server": server_install,
        }
        cli_bin: str | None = None
        python_bin: str | None = None
        workflow_php_project: Path | None = None
        workflow_php_install_error: dict[str, str] | None = None
        install_descriptors: dict[str, Any] = {}
        try:
            cli_bin, cli_install = install_cli(run_root, log_file)
            install_entries["cli"] = cli_install
        except Exception as exc:  # noqa: BLE001 - install proof is reported separately from server baseline cells.
            log_line(log_file, f"CLI install probe failed: {type(exc).__name__}: {exc}")
            cli_install = configured_artifact_entry(
                "cli",
                artifact_version_value(versions, "cli"),
                "published_cli_release",
                "github_release_installer",
            )
            cli_install["not_proved_reason"] = f"{type(exc).__name__}: {exc}"
            install_entries["cli"] = cli_install
            install_descriptors["cli"] = {
                "error": f"{type(exc).__name__}: {exc}",
                "log_file": log_file.name,
            }
        try:
            python_bin, python_install = ensure_python_sdk(run_root, log_file)
            install_entries["sdk-python"] = python_install
        except Exception as exc:  # noqa: BLE001 - install proof is reported separately from server baseline cells.
            log_line(log_file, f"Python SDK install probe failed: {type(exc).__name__}: {exc}")
            python_install = configured_artifact_entry(
                "sdk-python",
                artifact_version_value(versions, "sdk-python"),
                "published_pypi_package",
                "pypi_package_install",
            )
            python_install["not_proved_reason"] = f"{type(exc).__name__}: {exc}"
            install_entries["sdk-python"] = python_install
            install_descriptors["sdk-python"] = {
                "error": f"{type(exc).__name__}: {exc}",
                "log_file": log_file.name,
            }
        try:
            workflow_php_project, workflow_php_install = ensure_workflow_php_sdk(run_root, log_file)
            install_entries["workflow-php"] = workflow_php_install
        except Exception as exc:  # noqa: BLE001 - PHP mirror evidence is reported as its own baseline cell.
            workflow_php_install_error = probe_error_payload(exc)
            log_line(log_file, f"PHP workflow package install probe failed: {type(exc).__name__}: {exc}")
            workflow_php_install = configured_artifact_entry(
                "workflow-php",
                artifact_version_value(versions, "workflow-php"),
                "published_composer_package",
                "composer_package_install",
            )
            workflow_php_install["not_proved_reason"] = f"{type(exc).__name__}: {exc}"
            install_entries["workflow-php"] = workflow_php_install
            install_descriptors["workflow-php"] = {
                "error": f"{type(exc).__name__}: {exc}",
                "log_file": log_file.name,
            }
        sources = probe_artifact_sources(cleanup_commands, install_entries)
        install_outputs = {
            "published_artifact_versions": versions,
            "artifact_sources": sources,
            "artifact_install_evidence": install_evidence_for_artifacts(
                versions,
                sources,
                REQUIRED_INSTALL_PROOF_ARTIFACTS,
                install_entries,
            ),
            "local_product_source_checkouts_used": False,
        }
        install_status = "pass" if install_outputs_cover_required_artifacts(install_outputs) else "not_covered"
        python_sdk_outputs: dict[str, Any] | None = None
        python_sdk_descriptor: dict[str, Any] | None = None
        python_worker_php_cross_result: dict[str, Any] | None = None
        python_sdk_status = "not_covered"
        if cli_bin is not None and python_bin is not None:
            try:
                python_sdk_outputs, python_sdk_descriptor = run_python_sdk_baseline(
                    base_url=base_url,
                    token=token,
                    namespace=namespace,
                    cli_bin=cli_bin,
                    python_bin=python_bin,
                    versions=versions,
                    sources=sources,
                    run_root=run_root,
                    log_file=log_file,
                    workflow_php_project=workflow_php_project,
                )
                if isinstance(python_sdk_descriptor, dict):
                    cross_results = python_sdk_descriptor.get("cross_language_scenario_results")
                    if isinstance(cross_results, dict):
                        candidate = cross_results.get("python_worker_php_facing_and_cli_clients")
                        if isinstance(candidate, dict):
                            python_worker_php_cross_result = candidate
                if (
                    install_status == "pass"
                    and has_required_evidence("python_worker_cli_and_sdk_baseline", python_sdk_outputs)
                ):
                    python_sdk_status = "pass"
            except Exception as exc:  # noqa: BLE001 - keep the older shards routed when the SDK baseline is missing.
                log_line(log_file, f"Python SDK baseline probe failed: {type(exc).__name__}: {exc}")
                python_sdk_descriptor = {
                    "error": f"{type(exc).__name__}: {exc}",
                    "log_file": log_file.name,
                }
        else:
            python_sdk_descriptor = {
                "skipped": "cli_or_python_sdk_install_unavailable",
                "install_probes": install_descriptors,
                "log_file": log_file.name,
            }

        workflow_php_outputs: dict[str, Any] | None = None
        workflow_php_descriptor: dict[str, Any] | None = None
        php_worker_python_cross_result: dict[str, Any] | None = None
        workflow_php_status = "not_covered"
        if not env_flag("DW_SIGNALS_QUERIES_RUN_PHP_BASELINE_PROBE", True):
            workflow_php_descriptor = {
                "skipped": "disabled_by_env",
                "log_file": log_file.name,
            }
        elif cli_bin is not None and workflow_php_project is not None:
            try:
                workflow_php_outputs, workflow_php_descriptor = run_workflow_php_baseline(
                    base_url=base_url,
                    token=token,
                    namespace=namespace,
                    cli_bin=cli_bin,
                    python_bin=python_bin,
                    workflow_php_project=workflow_php_project,
                    versions=versions,
                    sources=sources,
                    install_entry=install_entries["workflow-php"],
                    run_root=run_root,
                    log_file=log_file,
                )
                if isinstance(workflow_php_descriptor, dict):
                    cross_results = workflow_php_descriptor.get("cross_language_scenario_results")
                    if isinstance(cross_results, dict):
                        candidate = cross_results.get("php_worker_python_and_cli_clients")
                        if isinstance(candidate, dict):
                            php_worker_python_cross_result = candidate
                if (
                    install_status == "pass"
                    and has_required_evidence("php_worker_cli_and_sdk_baseline", workflow_php_outputs)
                ):
                    workflow_php_status = "pass"
            except Exception as exc:  # noqa: BLE001 - leave a focused mirror finding and preserve sibling cells.
                log_line(log_file, f"PHP worker mirror probe failed: {type(exc).__name__}: {exc}")
                workflow_php_descriptor = {
                    "error": f"{type(exc).__name__}: {exc}",
                    "log_file": log_file.name,
                }
        elif workflow_php_install_error is not None:
            workflow_php_outputs = {
                "worker_runtime": "workflow-php",
                "workflow_php_artifact_source": sources["workflow-php"],
                "workflow_php_sdk_version": versions["workflow-php"],
                "published_artifact_versions": versions,
                "artifact_sources": sources,
                "workflow_php_install_evidence": install_entries["workflow-php"],
                "probe_error": workflow_php_install_error,
            }
            workflow_php_descriptor = {
                "error": f"{workflow_php_install_error['type']}: {workflow_php_install_error['message']}",
                "install_probes": install_descriptors,
                "log_file": log_file.name,
            }
        else:
            workflow_php_descriptor = {
                "skipped": "cli_or_php_workflow_install_unavailable",
                "install_probes": install_descriptors,
                "log_file": log_file.name,
            }

        suffix = hashlib.sha1(f"{time.time()}-baseline".encode("utf-8")).hexdigest()[:10]
        task_queue = f"signals-queries-baseline-{suffix}"
        worker_id = f"signals-queries-baseline-worker-{suffix}"
        workflow_type = "conformance.counter"

        register = http_json(
            base_url,
            api_path("worker", "register"),
            method="POST",
            body={
                "worker_id": worker_id,
                "task_queue": task_queue,
                "runtime": "external",
                "sdk_version": "signals-queries-baseline-probe",
                "supported_workflow_types": [workflow_type],
                "capabilities": ["query_tasks"],
                "workflow_command_contracts": {
                    workflow_type: command_contract(),
                },
            },
            token=token,
            namespace=namespace,
            worker=True,
            timeout=30,
        )
        if int(register["status_code"]) >= 400:
            raise RuntimeError(f"baseline worker registration failed: {register}")

        scenario_results: dict[str, dict[str, Any]] = {
            "published_artifact_install_only": {
                "status": install_status,
                "observed_outputs": install_outputs,
            },
        }
        generated_scenarios = ["published_artifact_install_only"]

        def optional_sample(field: str, callback: Any) -> Any:
            try:
                return callback()
            except Exception as exc:  # noqa: BLE001 - optional client samples must not erase server evidence.
                log_line(log_file, f"{field} optional public client sample failed: {type(exc).__name__}: {exc}")
                return MISSING

        baseline_workflow_id = f"wf-sq-baseline-{suffix}"
        baseline_run_id: str | None = None
        ordered_workflow_id = f"wf-sq-ordered-{suffix}"
        ordered_run_id: str | None = None
        dedup_workflow_id = f"wf-sq-dedup-{suffix}"
        dedup_run_id: str | None = None
        optional_unknown_outputs: dict[str, Any] = {}
        server_baseline_outputs: dict[str, Any] = {
            "worker_runtime": "external-http",
            "workflow_id": baseline_workflow_id,
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }

        try:
            baseline_run_id = start_waiting_workflow(
                base_url,
                token,
                namespace,
                worker_id,
                task_queue,
                baseline_workflow_id,
                workflow_type,
                f"{baseline_workflow_id}-initial",
            )
            counter = 0

            unknown_signal = http_json(
                base_url,
                api_path("workflows", baseline_workflow_id, "signal", "missing"),
                method="POST",
                body={},
                token=token,
                namespace=namespace,
                timeout=30,
            )
            query_not_found = http_json(
                base_url,
                api_path("workflows", baseline_workflow_id, "query", "missing"),
                method="POST",
                body={},
                token=token,
                namespace=namespace,
                timeout=30,
            )
            missing_workflow_signal = http_json(
                base_url,
                api_path("workflows", baseline_workflow_id + "-missing", "signal", "increment"),
                method="POST",
                body={},
                token=token,
                namespace=namespace,
                timeout=30,
            )
            missing_workflow_query = http_json(
                base_url,
                api_path("workflows", baseline_workflow_id + "-missing", "query", "state"),
                method="POST",
                body={},
                token=token,
                namespace=namespace,
                timeout=30,
            )
            if cli_bin is not None:
                optional_unknown_outputs.update(
                    {
                        "cli_unknown_signal_sample": optional_sample(
                            "cli_unknown_signal_sample",
                            lambda: cli_json_sample(
                                cli_bin,
                                base_url,
                                token,
                                namespace,
                                [
                                    "workflow:signal",
                                    baseline_workflow_id,
                                    "missing",
                                    "--output=json",
                                ],
                                log_file,
                            ),
                        ),
                        "cli_unknown_query_sample": optional_sample(
                            "cli_unknown_query_sample",
                            lambda: cli_json_sample(
                                cli_bin,
                                base_url,
                                token,
                                namespace,
                                [
                                    "workflow:query",
                                    baseline_workflow_id,
                                    "missing",
                                    "--output=json",
                                ],
                                log_file,
                            ),
                        ),
                        "cli_missing_workflow_signal_sample": optional_sample(
                            "cli_missing_workflow_signal_sample",
                            lambda: cli_json_sample(
                                cli_bin,
                                base_url,
                                token,
                                namespace,
                                [
                                    "workflow:signal",
                                    baseline_workflow_id + "-missing",
                                    "increment",
                                    "--output=json",
                                ],
                                log_file,
                            ),
                        ),
                        "cli_missing_workflow_query_sample": optional_sample(
                            "cli_missing_workflow_query_sample",
                            lambda: cli_json_sample(
                                cli_bin,
                                base_url,
                                token,
                                namespace,
                                [
                                    "workflow:query",
                                    baseline_workflow_id + "-missing",
                                    "state",
                                    "--output=json",
                                ],
                                log_file,
                            ),
                        ),
                    }
                )
            if python_bin is not None:
                optional_unknown_outputs.update(
                    {
                        "sdk_python_unknown_signal_sample": optional_sample(
                            "sdk_python_unknown_signal_sample",
                            lambda: sdk_error_sample(
                                python_bin,
                                base_url,
                                token,
                                namespace,
                                baseline_workflow_id,
                                "signal",
                                "missing",
                                log_file,
                            ),
                        ),
                        "sdk_python_unknown_query_sample": optional_sample(
                            "sdk_python_unknown_query_sample",
                            lambda: sdk_error_sample(
                                python_bin,
                                base_url,
                                token,
                                namespace,
                                baseline_workflow_id,
                                "query",
                                "missing",
                                log_file,
                            ),
                        ),
                        "sdk_python_missing_workflow_signal_sample": optional_sample(
                            "sdk_python_missing_workflow_signal_sample",
                            lambda: sdk_error_sample(
                                python_bin,
                                base_url,
                                token,
                                namespace,
                                baseline_workflow_id + "-missing",
                                "signal",
                                "increment",
                                log_file,
                            ),
                        ),
                        "sdk_python_missing_workflow_query_sample": optional_sample(
                            "sdk_python_missing_workflow_query_sample",
                            lambda: sdk_error_sample(
                                python_bin,
                                base_url,
                                token,
                                namespace,
                                baseline_workflow_id + "-missing",
                                "query",
                                "state",
                                log_file,
                            ),
                        ),
                    }
                )
            optional_unknown_outputs = {
                field: sample
                for field, sample in optional_unknown_outputs.items()
                if sample is not MISSING
            }
            known_query_after_unknown_errors = query_with_worker_result(
                base_url,
                token,
                namespace,
                worker_id,
                task_queue,
                baseline_workflow_id,
                "state",
                counter,
                log_file,
                lambda: http_json(
                    base_url,
                    api_path("workflows", baseline_workflow_id, "query", "state"),
                    method="POST",
                    body={},
                    token=token,
                    namespace=namespace,
                    timeout=60,
                ),
            )
            known_query_after_unknown_result = sample_result_value(known_query_after_unknown_errors)

            unknown_outputs = {
                "unknown_signal": response_sample(unknown_signal),
                "missing_workflow_signal": response_sample(missing_workflow_signal),
                "missing_workflow_query": response_sample(missing_workflow_query),
                "query_not_found": response_sample(query_not_found),
                "rejected_unknown_query": response_sample(query_not_found),
                "known_query_after_unknown_errors": known_query_after_unknown_errors,
                "known_query_after_unknown_result": known_query_after_unknown_result,
                "known_query_after_unknown_expected": counter,
                "workflow_id": baseline_workflow_id,
                "run_id": baseline_run_id,
                "published_artifact_versions": versions,
                "artifact_sources": sources,
            }
            unknown_outputs.update(optional_unknown_outputs)
            server_baseline_outputs = {
                "worker_runtime": "external-http",
                "workflow_id": baseline_workflow_id,
                "run_id": baseline_run_id,
                "known_query_after_unknown_errors": response_sample(known_query_after_unknown_errors),
                "known_query_after_unknown_result": known_query_after_unknown_result,
                "published_artifact_versions": versions,
                "artifact_sources": sources,
            }
        except Exception as exc:  # noqa: BLE001 - record the missing proof without dropping sibling cells.
            log_line(log_file, f"unknown-handler baseline probe failed: {type(exc).__name__}: {exc}")
            unknown_outputs = {
                "workflow_id": baseline_workflow_id,
                "run_id": baseline_run_id,
                "probe_error": probe_error_payload(exc),
                "published_artifact_versions": versions,
                "artifact_sources": sources,
            }
            server_baseline_outputs = {
                "worker_runtime": "external-http",
                "workflow_id": baseline_workflow_id,
                "run_id": baseline_run_id,
                "probe_error": probe_error_payload(exc),
                "published_artifact_versions": versions,
                "artifact_sources": sources,
            }
        scenario_results["unknown_signal_and_query_errors"] = baseline_scenario_result(
            "unknown_signal_and_query_errors",
            unknown_outputs,
        )
        generated_scenarios.append("unknown_signal_and_query_errors")

        ordered_outputs: dict[str, Any] = {
            "workflow_id": ordered_workflow_id,
            "server_base_url": base_url,
            "namespace": namespace,
            "worker_id": worker_id,
            "task_queue": task_queue,
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }
        try:
            ordered_run_id = start_waiting_workflow(
                base_url,
                token,
                namespace,
                worker_id,
                task_queue,
                ordered_workflow_id,
                workflow_type,
                f"{ordered_workflow_id}-initial",
            )
            ordered_outputs["run_id"] = ordered_run_id
            rapid_inputs = list(range(1, 11))
            ordered_outputs["rapid_increment_inputs"] = rapid_inputs
            ordered_signal_responses = []
            ordered_signal_failures = []
            accepted_signal_inputs: list[int] = []
            history_signal_order: list[int] = []
            ordered_outputs["history_signal_order"] = history_signal_order
            ordered_signal_tasks: list[dict[str, Any]] = []
            for amount in rapid_inputs:
                response = http_json(
                    base_url,
                    api_path("workflows", ordered_workflow_id, "signal", "increment"),
                    method="POST",
                    body={"input": [amount], "request_id": f"{ordered_workflow_id}-{amount}"},
                    token=token,
                    namespace=namespace,
                    timeout=30,
                )
                signal_sample = response_sample(response)
                signal_sample["amount"] = amount
                signal_sample["accepted"] = (
                    isinstance(signal_sample.get("status_code"), int)
                    and int(signal_sample["status_code"]) < 400
                )
                ordered_signal_responses.append(signal_sample)
                if signal_sample["accepted"]:
                    accepted_signal_inputs.append(amount)
                if int(response["status_code"]) >= 400:
                    ordered_signal_failures.append({
                        "amount": amount,
                        "response": signal_sample,
                    })

            ordered_outputs["signal_api_samples"] = ordered_signal_responses
            ordered_outputs["accepted_signal_inputs"] = accepted_signal_inputs
            ordered_outputs["accepted_signal_total"] = sum(accepted_signal_inputs)
            ordered_outputs["signal_status_codes"] = [
                sample.get("status_code")
                for sample in ordered_signal_responses
            ]
            if ordered_signal_failures:
                ordered_outputs["signal_api_failures"] = ordered_signal_failures

            accepted_signal_count = len(accepted_signal_inputs)
            if accepted_signal_count > 0:
                ordered_seen_signals: set[str] = set()
                try:
                    collect_increment_signal_observations(
                        base_url,
                        token,
                        namespace,
                        worker_id,
                        task_queue,
                        ordered_seen_signals,
                        history_signal_order,
                        ordered_signal_tasks,
                        f"{ordered_workflow_id}-after",
                        "ordered",
                        accepted_signal_count,
                        log_file,
                    )
                except Exception as exc:  # noqa: BLE001 - keep partial public ordered evidence.
                    log_line(log_file, f"ordered delivery history collection failed: {type(exc).__name__}: {exc}")
                    ordered_outputs["history_collection_error"] = probe_error_payload(exc)

            ordered_outputs["history_signal_order"] = history_signal_order
            ordered_outputs["signal_tasks"] = ordered_signal_tasks
            delivered_signal_total = sum(history_signal_order)
            ordered_outputs["delivered_signal_total"] = delivered_signal_total
            ordered_outputs["contract_expected_total"] = sum(rapid_inputs)
            ordered_outputs["expected_total"] = sum(accepted_signal_inputs)
            ordered_query_holder: dict[str, Any] = {}
            def ordered_query_result_from_task(task: dict[str, Any], holder: dict[str, Any]) -> int:
                query_history_order = increment_signal_amounts_from_history_events(task.get("history_events"))
                if query_history_order:
                    holder["history_signal_order"] = query_history_order
                    return sum(query_history_order)

                return delivered_signal_total

            ordered_responder = threading.Thread(
                target=answer_next_query_task,
                args=(
                    base_url,
                    token,
                    namespace,
                    worker_id,
                    task_queue,
                    ordered_query_result_from_task,
                    log_file,
                    ordered_query_holder,
                ),
                daemon=True,
            )
            ordered_responder.start()
            ordered_query: dict[str, Any] | None = None
            ordered_query_error: Exception | None = None
            try:
                ordered_query = http_json(
                    base_url,
                    api_path("workflows", ordered_workflow_id, "query", "state"),
                    method="POST",
                    body={},
                    token=token,
                    namespace=namespace,
                    timeout=60,
                )
            except Exception as exc:  # noqa: BLE001 - record the exact public query failure.
                ordered_query_error = exc
            ordered_responder.join(timeout=20)
            if ordered_responder.is_alive() or ordered_query_holder.get("error"):
                responder_error = ordered_query_holder.get("error", "timeout")
                log_line(log_file, f"ordered query responder failed: {responder_error}")
                ordered_outputs["query_responder_error"] = {"message": str(responder_error)}
            if ordered_query_error is not None:
                log_line(
                    log_file,
                    f"ordered query request failed: {type(ordered_query_error).__name__}: {ordered_query_error}",
                )
                ordered_outputs["query_error"] = probe_error_payload(ordered_query_error)
                ordered_query_result = None
            else:
                ordered_query_result = sample_result_value(ordered_query or {})
            query_task_history_order = ordered_query_holder.get("history_signal_order")
            if (
                isinstance(query_task_history_order, list)
                and all(isinstance(item, int) and not isinstance(item, bool) for item in query_task_history_order)
            ):
                ordered_outputs["workflow_task_history_signal_order"] = list(history_signal_order)
                ordered_outputs["query_task_history_signal_order"] = query_task_history_order
                history_signal_order[:] = query_task_history_order
                ordered_outputs["history_signal_order"] = history_signal_order
                delivered_signal_total = sum(history_signal_order)
                ordered_outputs["delivered_signal_total"] = delivered_signal_total
            ordered_outputs["queried_total"] = ordered_query_result
            ordered_outputs["ten_signal_ordered_delivery_total"] = ordered_query_result
            if ordered_query is not None:
                ordered_outputs["query_api_sample"] = response_sample(ordered_query)
            if ordered_signal_failures:
                raise RuntimeError(f"ordered signal API failures: {ordered_signal_failures}")
            if accepted_signal_inputs != rapid_inputs:
                raise RuntimeError(
                    f"ordered signal accepted inputs {accepted_signal_inputs}, expected {rapid_inputs}"
                )
            if history_signal_order != accepted_signal_inputs:
                raise RuntimeError(
                    f"ordered signal history order {history_signal_order}, expected {accepted_signal_inputs}"
                )
            if ordered_query_result != sum(accepted_signal_inputs):
                raise RuntimeError(
                    f"ordered query returned {ordered_query_result}, expected {sum(accepted_signal_inputs)}"
                )
        except Exception as exc:  # noqa: BLE001 - retain partial order proof for focused findings.
            log_line(log_file, f"ordered delivery baseline probe failed: {type(exc).__name__}: {exc}")
            ordered_outputs["run_id"] = ordered_run_id
            ordered_outputs["probe_error"] = probe_error_payload(exc)
        finally:
            try:
                ordered_outputs["final_run_status"] = run_status(base_url, token, namespace, ordered_workflow_id)
            except Exception as exc:  # noqa: BLE001 - retain ordered evidence even if status readout fails.
                ordered_outputs["final_run_status_error"] = probe_error_payload(exc)
        scenario_results["ordered_signal_delivery"] = baseline_scenario_result(
            "ordered_signal_delivery",
            ordered_outputs,
        )
        generated_scenarios.append("ordered_signal_delivery")

        dedup_outputs: dict[str, Any] = {
            "workflow_id": dedup_workflow_id,
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }
        try:
            dedup_run_id = start_waiting_workflow(
                base_url,
                token,
                namespace,
                worker_id,
                task_queue,
                dedup_workflow_id,
                workflow_type,
                f"{dedup_workflow_id}-initial",
            )
            dedup_outputs["run_id"] = dedup_run_id
            duplicate_request_id = f"{dedup_workflow_id}-duplicate-key"
            duplicate_signal_responses = []
            for index in range(2):
                response = http_json(
                    base_url,
                    api_path("workflows", dedup_workflow_id, "signal", "increment"),
                    method="POST",
                    body={"input": {"amount": 7}, "request_id": duplicate_request_id},
                    token=token,
                    namespace=namespace,
                    timeout=30,
                )
                duplicate_signal_responses.append(response_sample(response))
                if int(response["status_code"]) >= 400:
                    raise RuntimeError(f"duplicate signal {index + 1} failed: {response}")

            duplicate_observations: list[int] = []
            duplicate_tasks: list[dict[str, Any]] = []
            duplicate_seen_signals: set[str] = set()
            collect_increment_signal_observations(
                base_url,
                token,
                namespace,
                worker_id,
                task_queue,
                duplicate_seen_signals,
                duplicate_observations,
                duplicate_tasks,
                f"{dedup_workflow_id}-after",
                "duplicate",
                2,
                log_file,
                poll_timeout=5,
                allow_exhausted_after_observation=True,
            )

            handler_observation_count = len([amount for amount in duplicate_observations if amount == 7])
            client_side_key_support = handler_observation_count == 1
            documented_contract = (
                "the public control-plane signal request_id behaved as an idempotency key for duplicate signal calls"
                if client_side_key_support
                else (
                    "no signal deduplication key is documented on the public control-plane signal API; "
                    "duplicate accepted signal calls are delivered independently"
                    if handler_observation_count > 1
                    else "duplicate accepted signal calls were not observed by the external handler"
                )
            )
            dedup_outputs.update(
                {
                    "client_side_key_support": client_side_key_support,
                    "documented_contract": documented_contract,
                    "handler_observation_count": handler_observation_count,
                    "duplicate_request_id_used": duplicate_request_id,
                    "duplicate_signal_api_samples": duplicate_signal_responses,
                    "handler_observed_amounts": duplicate_observations,
                    "signal_tasks": duplicate_tasks,
                }
            )
            if handler_observation_count == 0:
                raise RuntimeError("duplicate signal probe did not observe any delivered increment signals")
        except Exception as exc:  # noqa: BLE001 - retain partial dedup proof for focused findings.
            log_line(log_file, f"dedup baseline probe failed: {type(exc).__name__}: {exc}")
            dedup_outputs["run_id"] = dedup_run_id
            dedup_outputs["probe_error"] = probe_error_payload(exc)
        scenario_results["dedup_contract_observation"] = baseline_scenario_result(
            "dedup_contract_observation",
            dedup_outputs,
        )
        generated_scenarios.append("dedup_contract_observation")

        evidence = {
            "artifact_versions": versions,
            "scenario_results": scenario_results,
        }
        if python_sdk_outputs is not None:
            evidence["scenario_results"]["python_worker_cli_and_sdk_baseline"] = {
                "status": python_sdk_status,
                "observed_outputs": python_sdk_outputs,
            }
            generated_scenarios.append("python_worker_cli_and_sdk_baseline")
        if python_worker_php_cross_result is not None:
            evidence["scenario_results"]["python_worker_php_facing_and_cli_clients"] = python_worker_php_cross_result
            generated_scenarios.append("python_worker_php_facing_and_cli_clients")
        if workflow_php_outputs is not None:
            evidence["scenario_results"]["php_worker_cli_and_sdk_baseline"] = {
                "status": workflow_php_status,
                "observed_outputs": workflow_php_outputs,
            }
            generated_scenarios.append("php_worker_cli_and_sdk_baseline")
        if php_worker_python_cross_result is not None:
            evidence["scenario_results"]["php_worker_python_and_cli_clients"] = php_worker_python_cross_result
            generated_scenarios.append("php_worker_python_and_cli_clients")
        not_claimed_as_pass = (
            ([] if install_status == "pass" else ["published_artifact_install_only"])
            + ([] if python_sdk_status == "pass" else ["python_worker_cli_and_sdk_baseline"])
            + ([] if workflow_php_status == "pass" else ["php_worker_cli_and_sdk_baseline"])
            + (
                []
                if (
                    isinstance(python_worker_php_cross_result, dict)
                    and python_worker_php_cross_result.get("status") == "pass"
                )
                else ["python_worker_php_facing_and_cli_clients"]
            )
            + (
                []
                if (
                    isinstance(php_worker_python_cross_result, dict)
                    and php_worker_python_cross_result.get("status") == "pass"
                )
                else ["php_worker_python_and_cli_clients"]
            )
            + [
                scenario
                for scenario in (
                    "ordered_signal_delivery",
                    "dedup_contract_observation",
                    "unknown_signal_and_query_errors",
                )
                if scenario_results.get(scenario, {}).get("status") != "pass"
            ]
        )
        descriptor = {
            "file": log_file.name,
            "server_base_url": base_url,
            "worker_id": worker_id,
            "task_queue": task_queue,
            "workflow_ids": {
                "baseline": baseline_workflow_id,
                "ordered": ordered_workflow_id,
                "dedup": dedup_workflow_id,
            },
            "partial_baseline_observations": {
                "external_worker_server_control_plane_observation": server_baseline_outputs,
                "optional_public_client_error_samples": sorted(optional_unknown_outputs),
                "python_worker_cli_and_sdk_baseline": python_sdk_descriptor,
                "python_worker_php_facing_and_cli_clients": (
                    python_worker_php_cross_result
                    if python_worker_php_cross_result is not None
                    else {"skipped": "python_worker_or_php_client_unavailable"}
                ),
                "php_worker_cli_and_sdk_baseline": workflow_php_descriptor,
                "php_worker_python_and_cli_clients": (
                    php_worker_python_cross_result
                    if php_worker_python_cross_result is not None
                    else {"skipped": "php_worker_or_python_client_unavailable"}
                ),
                "install_probes": install_descriptors,
                "server_readiness": readiness_probe,
                "published_artifact_sources_observed": sorted(sources),
                "not_claimed_as_pass": not_claimed_as_pass,
            },
            "generated_scenarios": generated_scenarios,
        }
        try:
            waterline_evidence, waterline_descriptor = run_waterline_observer_probe(
                result_dir,
                evidence,
                server_topology=readiness_probe,
            )
        except Exception as exc:  # noqa: BLE001 - keep baseline evidence if the Waterline shard crashes.
            waterline_evidence = waterline_observer_setup_result(
                status="fail",
                reason=f"Waterline observer shard failed before producing evidence: {type(exc).__name__}: {exc}",
                blocker_kind="waterline_observer_probe_exception",
            )
            waterline_descriptor = {
                "error": f"{type(exc).__name__}: {exc}",
                "generated_scenarios": ["waterline_operator_visibility"],
            }
        if waterline_evidence is not None:
            evidence = merge_probe_evidence(evidence, waterline_evidence)
        if waterline_descriptor is not None:
            descriptor["waterline_observer_probe"] = waterline_descriptor
        return evidence, descriptor
    except ServerReadinessTopologyError as exc:
        details = dict(exc.details)
        details.setdefault("kind", "server_readiness_topology")
        cleanup_commands = cleanup_commands_from_blocker(details)
        log_line(log_file, f"baseline readiness topology blocked: {type(exc).__name__}: {exc}")
        return None, {
            "file": log_file.name,
            "error": f"{type(exc).__name__}: {exc}",
            "runner_blocker": details,
        }
    except Exception as exc:  # noqa: BLE001 - failed probe becomes uncovered evidence.
        log_line(log_file, f"baseline probe failed: {type(exc).__name__}: {exc}")
        return None, {
            "file": log_file.name,
            "error": f"{type(exc).__name__}: {exc}",
        }
    finally:
        for command in cleanup_commands:
            run_command(command, log_file=log_file, timeout=120)
        if not env_flag("DW_SIGNALS_QUERIES_KEEP_RUN_ROOT", False):
            shutil.rmtree(run_root, ignore_errors=True)


def run_adversarial_probe(result_dir: Path, current_evidence: Any) -> tuple[dict[str, Any] | None, dict[str, Any] | None]:
    if not env_flag("DW_SIGNALS_QUERIES_RUN_ADVERSARIAL_PROBE", True):
        return None, {"skipped": "disabled_by_env"}

    run_root = Path(
        env_text("DW_SIGNALS_QUERIES_RUN_ROOT")
        or tempfile.mkdtemp(prefix="dw-signals-queries-adversarial.", dir=str(result_dir))
    )
    run_root.mkdir(parents=True, exist_ok=True)
    log_file = result_dir / "signals-queries-adversarial-probe.log"
    cleanup_commands: list[list[str]] = []

    namespace = (
        env_text("DW_SIGNALS_QUERIES_NAMESPACE")
        or env_text("DURABLE_WORKFLOW_NAMESPACE")
        or "default"
    )
    token = (
        env_text("DW_SIGNALS_QUERIES_AUTH_TOKEN")
        or env_text("DURABLE_WORKFLOW_AUTH_TOKEN")
        or env_text("DW_AUTH_TOKEN")
        or "dev-token"
    )
    base_url = env_text("DW_SIGNALS_QUERIES_SERVER_URL") or env_text("DURABLE_WORKFLOW_SERVER_URL")
    if (base_url is None or base_url.strip() == "") and current_evidence is not None:
        for evidence_key in ("server_base_url", "server_url", "base_url"):
            candidate = evidence_lookup(current_evidence, evidence_key)
            if isinstance(candidate, str) and candidate.strip() != "":
                base_url = candidate.strip()
                break
    readiness_probe: dict[str, Any] | None = None

    try:
        if not isinstance(base_url, str) or base_url.strip() == "":
            base_url, cleanup_commands, readiness_probe = start_published_server(run_root, log_file)
        else:
            base_url = base_url.rstrip("/")
            readiness_probe = wait_for_ready(
                base_url,
                log_file,
                timeout_seconds=server_ready_timeout_seconds(30),
                diagnostics=configured_server_diagnostics(base_url),
            )

        server_install = server_install_entry(cleanup_commands)
        cli_bin, cli_install = install_cli(run_root, log_file)
        python_bin, python_install = ensure_python_sdk(run_root, log_file)
        install_entries = {
            "server": server_install,
            "cli": cli_install,
            "sdk-python": python_install,
        }

        workflow_id = "wf-sq-adversarial-" + hashlib.sha1(str(time.time()).encode("utf-8")).hexdigest()[:10]
        task_queue = "signals-queries-adversarial"
        worker_id = "signals-queries-adversarial-worker"
        workflow_type = "conformance.counter"

        register = http_json(
            base_url,
            api_path("worker", "register"),
            method="POST",
            body={
                "worker_id": worker_id,
                "task_queue": task_queue,
                "runtime": "external",
                "sdk_version": "signals-queries-adversarial-probe",
                "supported_workflow_types": [workflow_type],
                "capabilities": ["query_tasks"],
                "workflow_command_contracts": {
                    workflow_type: command_contract(),
                },
            },
            token=token,
            namespace=namespace,
            worker=True,
            timeout=30,
        )
        if int(register["status_code"]) >= 400:
            raise RuntimeError(f"worker registration failed: {register}")

        start = http_json(
            base_url,
            api_path("workflows"),
            method="POST",
            body={
                "workflow_id": workflow_id,
                "workflow_type": workflow_type,
                "task_queue": task_queue,
            },
            token=token,
            namespace=namespace,
            timeout=30,
        )
        if int(start["status_code"]) >= 400:
            raise RuntimeError(f"workflow start failed: {start}")
        run_id = str(start["body"]["run_id"])

        invalid_signal = http_json(
            base_url,
            api_path("workflows", workflow_id, "signal", "increment"),
            method="POST",
            body={"input": {"amount": "bad"}},
            token=token,
            namespace=namespace,
            timeout=30,
        )
        invalid_query = http_json(
            base_url,
            api_path("workflows", workflow_id, "query", "count-at-least"),
            method="POST",
            body={"input": {"minimum": "bad"}},
            token=token,
            namespace=namespace,
            timeout=30,
        )
        unknown_signal = http_json(
            base_url,
            api_path("workflows", workflow_id, "signal", "missing"),
            method="POST",
            body={},
            token=token,
            namespace=namespace,
            timeout=30,
        )
        query_not_found = http_json(
            base_url,
            api_path("workflows", workflow_id, "query", "missing"),
            method="POST",
            body={},
            token=token,
            namespace=namespace,
            timeout=30,
        )
        missing_workflow_signal = http_json(
            base_url,
            api_path("workflows", workflow_id + "-missing", "signal", "increment"),
            method="POST",
            body={},
            token=token,
            namespace=namespace,
            timeout=30,
        )
        missing_workflow_query = http_json(
            base_url,
            api_path("workflows", workflow_id + "-missing", "query", "state"),
            method="POST",
            body={},
            token=token,
            namespace=namespace,
            timeout=30,
        )

        cli_invalid_signal = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:signal",
                workflow_id,
                "increment",
                "--input",
                '["bad"]',
                "--output=json",
            ],
            log_file,
        )
        cli_invalid_query = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:query",
                workflow_id,
                "count-at-least",
                "--input",
                '["bad"]',
                "--output=json",
            ],
            log_file,
        )
        cli_unknown_signal = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:signal",
                workflow_id,
                "missing",
                "--output=json",
            ],
            log_file,
        )
        cli_unknown_query = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:query",
                workflow_id,
                "missing",
                "--output=json",
            ],
            log_file,
        )
        cli_missing_workflow_signal = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:signal",
                workflow_id + "-missing",
                "increment",
                "--output=json",
            ],
            log_file,
        )
        cli_missing_workflow_query = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:query",
                workflow_id + "-missing",
                "state",
                "--output=json",
            ],
            log_file,
        )
        sdk_invalid_signal = sdk_error_sample(
            python_bin,
            base_url,
            token,
            namespace,
            workflow_id,
            "signal",
            "increment",
            log_file,
            args=["bad"],
        )
        sdk_invalid_query = sdk_error_sample(
            python_bin,
            base_url,
            token,
            namespace,
            workflow_id,
            "query",
            "count-at-least",
            log_file,
            args=["bad"],
        )
        sdk_unknown_signal = sdk_error_sample(
            python_bin,
            base_url,
            token,
            namespace,
            workflow_id,
            "signal",
            "missing",
            log_file,
        )
        sdk_unknown_query = sdk_error_sample(
            python_bin,
            base_url,
            token,
            namespace,
            workflow_id,
            "query",
            "missing",
            log_file,
        )
        sdk_missing_workflow_signal = sdk_error_sample(
            python_bin,
            base_url,
            token,
            namespace,
            workflow_id + "-missing",
            "signal",
            "increment",
            log_file,
        )
        sdk_missing_workflow_query = sdk_error_sample(
            python_bin,
            base_url,
            token,
            namespace,
            workflow_id + "-missing",
            "query",
            "state",
            log_file,
        )

        history = http_json(
            base_url,
            api_path("workflows", workflow_id, "runs", run_id, "history") + "?page_size=1000",
            method="GET",
            token=token,
            namespace=namespace,
            timeout=30,
        )
        signal_count = count_signal_received(history, "increment")

        holder: dict[str, Any] = {}
        responder = threading.Thread(
            target=answer_next_query_task,
            args=(base_url, token, namespace, worker_id, task_queue, 0, log_file, holder),
            daemon=True,
        )
        responder.start()
        time.sleep(0.2)
        post_error_query = http_json(
            base_url,
            api_path("workflows", workflow_id, "query", "state"),
            method="POST",
            body={},
            token=token,
            namespace=namespace,
            timeout=45,
        )
        responder.join(timeout=10)
        if responder.is_alive() or holder.get("error"):
            raise RuntimeError(f"query responder failed: {holder.get('error', 'timeout')}")

        post_error_result = (
            post_error_query.get("body", {}).get("result")
            if isinstance(post_error_query.get("body"), dict)
            else None
        )
        query_state_mutations = 0 if post_error_result == 0 else 1

        versions = {
            "server": artifact_version_value(artifact_versions, "server"),
            "cli": artifact_version_value(artifact_versions, "cli"),
            "sdk-python": artifact_version_value(artifact_versions, "sdk-python"),
            "workflow-php": artifact_version_value(artifact_versions, "workflow-php"),
            "waterline": artifact_version_value(artifact_versions, "waterline"),
        }
        sources = probe_artifact_sources(cleanup_commands, install_entries)
        replay_terminal_evidence, replay_terminal_descriptor = run_replay_terminal_probe(
            base_url,
            token,
            namespace,
            worker_id,
            task_queue,
            workflow_type,
            versions,
            sources,
            log_file,
        )

        malformed_outputs = {
            "invalid_signal_arguments": response_sample(invalid_signal),
            "invalid_query_arguments": response_sample(invalid_query),
            "invalid_signal_arguments_context": {
                "workflow_id": workflow_id,
                "run_id": run_id,
                "signal_name": "increment",
                "field": "amount",
                "artifact_versions": versions,
                "artifact_sources": sources,
            },
            "invalid_query_arguments_context": {
                "workflow_id": workflow_id,
                "run_id": run_id,
                "query_name": "count-at-least",
                "field": "minimum",
                "artifact_versions": versions,
                "artifact_sources": sources,
            },
            "signal_handler_invocation_count_after_invalid_payload": signal_count,
            "query_state_mutation_count_after_invalid_payload": query_state_mutations,
            "post_error_valid_query_result": post_error_result,
            "cli_invalid_signal_arguments_sample": cli_invalid_signal,
            "cli_invalid_query_arguments_sample": cli_invalid_query,
            "sdk_python_invalid_signal_arguments_sample": sdk_invalid_signal,
            "sdk_python_invalid_query_arguments_sample": sdk_invalid_query,
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }
        unknown_outputs = {
            "unknown_signal": response_sample(unknown_signal),
            "missing_workflow_signal": response_sample(missing_workflow_signal),
            "missing_workflow_query": response_sample(missing_workflow_query),
            "query_not_found": response_sample(query_not_found),
            "rejected_unknown_query": response_sample(query_not_found),
            "cli_unknown_signal_sample": cli_unknown_signal,
            "cli_unknown_query_sample": cli_unknown_query,
            "cli_missing_workflow_signal_sample": cli_missing_workflow_signal,
            "cli_missing_workflow_query_sample": cli_missing_workflow_query,
            "sdk_python_unknown_signal_sample": sdk_unknown_signal,
            "sdk_python_unknown_query_sample": sdk_unknown_query,
            "sdk_python_missing_workflow_signal_sample": sdk_missing_workflow_signal,
            "sdk_python_missing_workflow_query_sample": sdk_missing_workflow_query,
            "known_query_after_unknown_errors": post_error_query,
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }
        scenario_results = {
            "unknown_signal_and_query_errors": {
                "status": "pass",
                "observed_outputs": unknown_outputs,
            },
            "malformed_signal_and_query_payloads": {
                "status": "pass",
                "observed_outputs": malformed_outputs,
            },
        }
        generated_scenarios = [
            "unknown_signal_and_query_errors",
            "malformed_signal_and_query_payloads",
        ]
        if replay_terminal_evidence is not None:
            replay_results = replay_terminal_evidence.get("scenario_results")
            if isinstance(replay_results, dict):
                scenario_results.update(replay_results)
                if replay_terminal_descriptor is not None:
                    generated = replay_terminal_descriptor.get("generated_scenarios")
                    if isinstance(generated, list):
                        generated_scenarios.extend(
                            str(scenario) for scenario in generated if isinstance(scenario, str)
                        )

        evidence = {
            "artifact_versions": versions,
            "scenario_results": scenario_results,
        }
        descriptor = {
            "file": log_file.name,
            "workflow_id": workflow_id,
            "run_id": run_id,
            "server_base_url": base_url,
            "generated_scenarios": generated_scenarios,
            "server_readiness": readiness_probe,
            "replay_terminal_probe": replay_terminal_descriptor,
        }
        return evidence, descriptor
    except ServerReadinessTopologyError as exc:
        details = dict(exc.details)
        details.setdefault("kind", "server_readiness_topology")
        cleanup_commands = cleanup_commands_from_blocker(details)
        log_line(log_file, f"adversarial readiness topology blocked: {type(exc).__name__}: {exc}")
        return None, {
            "file": log_file.name,
            "error": f"{type(exc).__name__}: {exc}",
            "runner_blocker": details,
        }
    except Exception as exc:  # noqa: BLE001 - failed probe becomes uncovered evidence.
        log_line(log_file, f"adversarial probe failed: {type(exc).__name__}: {exc}")
        return None, {
            "file": log_file.name,
            "error": f"{type(exc).__name__}: {exc}",
        }
    finally:
        for command in cleanup_commands:
            run_command(command, log_file=log_file, timeout=120)
        if not env_flag("DW_SIGNALS_QUERIES_KEEP_RUN_ROOT", False):
            shutil.rmtree(run_root, ignore_errors=True)


def merge_probe_evidence(base: Any, probe: dict[str, Any]) -> dict[str, Any]:
    if not isinstance(base, dict):
        return dict(probe)

    merged = dict(base)
    for field in ("artifact_versions", "artifactVersions", "published_artifact_versions", "publishedArtifactVersions"):
        if field not in merged and field in probe:
            merged[field] = probe[field]

    probe_results = probe.get("scenario_results")
    if not isinstance(probe_results, dict):
        return merged

    existing = merged.get("scenario_results")
    if isinstance(existing, dict):
        existing = dict(existing)
        existing.update(probe_results)
        merged["scenario_results"] = existing
    elif isinstance(existing, list):
        existing = list(existing)
        for scenario_id, scenario_result in probe_results.items():
            item = dict(scenario_result)
            item.setdefault("scenario_id", scenario_id)
            replaced = False
            for index, existing_item in enumerate(existing):
                if not isinstance(existing_item, dict):
                    continue
                existing_scenario = (
                    existing_item.get("scenario_id")
                    or existing_item.get("scenario")
                    or existing_item.get("id")
                )
                if existing_scenario != scenario_id:
                    continue
                existing[index] = item
                replaced = True
                break
            if replaced:
                continue
            existing.append(item)
        merged["scenario_results"] = existing
    else:
        merged["scenario_results"] = probe_results

    return merged


def string_from_evidence(value: Any, keys: tuple[str, ...]) -> str | None:
    for key in keys:
        found = evidence_value(value, key)
        if isinstance(found, str) and found.strip():
            return found.strip()
    return None


def integer_from_evidence(value: Any, keys: tuple[str, ...]) -> int | None:
    for key in keys:
        found = evidence_value(value, key)
        if isinstance(found, bool):
            continue
        if isinstance(found, int):
            return found
        if isinstance(found, str) and found.strip().lstrip("-").isdigit():
            return int(found.strip())
    return None


def list_of_ints(value: Any) -> list[int]:
    if not isinstance(value, list):
        return []

    values: list[int] = []
    for item in value:
        if isinstance(item, bool) or not isinstance(item, int):
            return []
        values.append(item)
    return values


def waterline_status_bucket(status: str | None) -> str:
    normalized = (status or "").strip().lower()
    if normalized in {"completed", "failed", "cancelled", "canceled", "terminated", "timed_out"}:
        return "terminal"
    return "running"


def waterline_observer_public_evidence(current_evidence: Any) -> dict[str, Any] | None:
    if not isinstance(current_evidence, dict):
        return None

    ordered_candidate = scenario_evidence_candidate_from(current_evidence, "ordered_signal_delivery")
    if ordered_candidate is None:
        return None

    ordered = scenario_observed_outputs(ordered_candidate)
    if not has_required_evidence("ordered_signal_delivery", ordered):
        return None

    workflow_id = string_from_evidence(ordered, ("workflow_id", "workflow_instance_id", "instance_id"))
    run_id = string_from_evidence(ordered, ("run_id", "workflow_run_id", "selected_run_id"))
    if workflow_id is None or run_id is None:
        return None

    counter = integer_from_evidence(ordered, ("queried_total", "ten_signal_ordered_delivery_total", "delivered_signal_total"))
    signal_inputs = list_of_ints(ordered.get("history_signal_order")) or list_of_ints(ordered.get("accepted_signal_inputs"))
    if counter is None and signal_inputs:
        counter = sum(signal_inputs)
    if counter is None:
        return None

    public_evidence = dict(current_evidence)
    public_evidence["workflow_instance_id"] = workflow_id
    public_evidence["workflow_run_id"] = run_id
    public_evidence["run_status"] = string_from_evidence(ordered, ("final_run_status", "run_status")) or "waiting"
    public_evidence["query_name"] = "state"
    public_evidence["current_counter"] = counter

    return public_evidence


def waterline_observer_setup_result(
    *,
    status: str,
    reason: str,
    blocker_kind: str,
) -> dict[str, Any]:
    finding = {
        "id": "signal_query_waterline_observer_probe_unavailable",
        "type": "signal_query_waterline_observer_probe_unavailable",
        "scenario_id": "waterline_operator_visibility",
        "owner": "conformance_harness" if status == "runner_blocked" else "waterline",
        "title": "Signals/queries Waterline observer probe could not produce comparison evidence",
        "current_evidence": {
            "published_artifact_evidence_present": True,
            "blocker_kind": blocker_kind,
            "reason": reason,
        },
        "acceptance": [
            "install the pinned published Waterline artifact",
            "run waterline:signals-queries-conformance against the selected signal/query run",
            "import a passing waterline_operator_visibility scenario result",
        ],
    }
    if status == "runner_blocked":
        finding["blocker_kind"] = blocker_kind

    return {
        "artifact_versions": dict(artifact_versions),
        "scenario_results": {
            "waterline_operator_visibility": {
                "scenario_id": "waterline_operator_visibility",
                "status": status,
                "observed_outputs": {
                    "artifact_versions": dict(artifact_versions),
                    "artifact_sources": dict(EXPECTED_ARTIFACT_SOURCES),
                    "setup_failure": {
                        "blocker_kind": blocker_kind,
                        "reason": reason,
                    },
                },
                "linked_findings": [finding],
            },
        },
    }


WATERLINE_WORKFLOW_STORAGE_ENV_KEYS = (
    "WATERLINE_WORKFLOW_STORAGE_CONNECTION",
    "WORKFLOW_STORAGE_CONNECTION",
    "DW_STORAGE_CONNECTION",
    "WATERLINE_WORKFLOW_DB_CONNECTION",
    "WATERLINE_WORKFLOW_DB_DRIVER",
    "WATERLINE_WORKFLOW_DB_HOST",
    "WATERLINE_WORKFLOW_DB_PORT",
    "WATERLINE_WORKFLOW_DB_DATABASE",
    "WATERLINE_WORKFLOW_DB_USERNAME",
    "WATERLINE_WORKFLOW_DB_PASSWORD",
    "WATERLINE_WORKFLOW_DB_SOCKET",
    "WORKFLOW_DB_CONNECTION",
    "WORKFLOW_DB_DRIVER",
    "WORKFLOW_DB_HOST",
    "WORKFLOW_DB_PORT",
    "WORKFLOW_DB_DATABASE",
    "WORKFLOW_DB_USERNAME",
    "WORKFLOW_DB_PASSWORD",
    "WORKFLOW_DB_SOCKET",
    "DW_WV_WATERLINE_DB_CONNECTION",
    "DW_WV_WATERLINE_DB_DRIVER",
    "DW_WV_WATERLINE_DB_HOST",
    "DW_WV_WATERLINE_DB_PORT",
    "DW_WV_WATERLINE_DB_DATABASE",
    "DW_WV_WATERLINE_DB_USERNAME",
    "DW_WV_WATERLINE_DB_PASSWORD",
    "DW_WV_WATERLINE_DB_SOCKET",
    "DW_WATERLINE_DB_CONNECTION",
    "DW_WATERLINE_DB_DRIVER",
    "DW_WATERLINE_DB_HOST",
    "DW_WATERLINE_DB_PORT",
    "DW_WATERLINE_DB_DATABASE",
    "DW_WATERLINE_DB_USERNAME",
    "DW_WATERLINE_DB_PASSWORD",
    "DW_WATERLINE_DB_SOCKET",
    "WORKFLOW_V2_TASK_DISPATCH_MODE",
    "DW_V2_TASK_DISPATCH_MODE",
)


def redacted_environment(env: dict[str, str]) -> dict[str, str]:
    redacted: dict[str, str] = {}
    for key, value in env.items():
        if "PASSWORD" in key or "TOKEN" in key or "SECRET" in key:
            redacted[key] = "<redacted>"
        else:
            redacted[key] = value
    return redacted


def waterline_storage_from_topology(server_topology: dict[str, Any] | None) -> dict[str, Any]:
    compose_project = None
    if isinstance(server_topology, dict):
        candidate = server_topology.get("compose_project")
        if isinstance(candidate, str) and candidate.strip():
            compose_project = candidate.strip()

    if compose_project:
        env = {
            "WATERLINE_WORKFLOW_STORAGE_CONNECTION": "waterline_workflow",
            "WATERLINE_WORKFLOW_DB_CONNECTION": "mysql",
            "WATERLINE_WORKFLOW_DB_DRIVER": "mysql",
            "WATERLINE_WORKFLOW_DB_HOST": "mysql",
            "WATERLINE_WORKFLOW_DB_PORT": "3306",
            "WATERLINE_WORKFLOW_DB_DATABASE": env_text("DB_DATABASE") or "durable_workflow",
            "WATERLINE_WORKFLOW_DB_USERNAME": env_text("DB_USERNAME") or "workflow",
            "WATERLINE_WORKFLOW_DB_PASSWORD": env_text("DB_PASSWORD") or "workflow",
            "WORKFLOW_V2_TASK_DISPATCH_MODE": env_text("WORKFLOW_V2_TASK_DISPATCH_MODE") or "database",
            "DW_V2_TASK_DISPATCH_MODE": env_text("DW_V2_TASK_DISPATCH_MODE") or "database",
        }
        return {
            "env": env,
            "docker_network": f"{compose_project}_default",
            "source": "published_server_compose_workflow_storage",
            "redacted_env": redacted_environment(env),
        }

    env = {
        key: value
        for key in WATERLINE_WORKFLOW_STORAGE_ENV_KEYS
        if (value := os.environ.get(key)) is not None and (value != "" or key.endswith("_PASSWORD"))
    }
    if env:
        return {
            "env": env,
            "docker_network": env_text("DW_SIGNALS_QUERIES_WATERLINE_DOCKER_NETWORK"),
            "source": "worker_environment_workflow_storage",
            "redacted_env": redacted_environment(env),
        }

    return {
        "env": {},
        "docker_network": None,
        "source": "unavailable",
        "redacted_env": {},
    }


def docker_run_for_project(
    project_dir: Path,
    command: list[str],
    *,
    extra_env: dict[str, str] | None = None,
    network: str | None = None,
) -> list[str]:
    docker_command = [
        "docker",
        "run",
        "--rm",
    ]
    if network:
        docker_command.extend(["--network", network])
    else:
        docker_command.extend(["--add-host", "host.docker.internal:host-gateway"])

    docker_command.extend([
        "-v",
        docker_volume_spec(project_dir),
        "-w",
        "/app",
        "-e",
        "APP_ENV=production",
        "-e",
        "APP_DEBUG=false",
        "-e",
        "DB_CONNECTION=sqlite",
        "-e",
        "DB_DATABASE=/app/database/database.sqlite",
        "-e",
        "QUEUE_CONNECTION=database",
        "-e",
        "CACHE_STORE=array",
        "-e",
        "SESSION_DRIVER=array",
        "-e",
        "WATERLINE_ENGINE_SOURCE=v2",
        "-e",
        "WATERLINE_ALLOW_UNAUTHENTICATED=true",
    ])
    for key, value in sorted((extra_env or {}).items()):
        docker_command.extend(["-e", f"{key}={value}"])

    docker_command.extend([
        workflow_php_docker_image(),
        *command,
    ])
    return docker_command


def waterline_observer_already_reported(current_evidence: Any) -> bool:
    candidate = scenario_evidence_candidate_from(current_evidence, "waterline_operator_visibility")
    if candidate is None:
        return False

    return isinstance(candidate.get("status"), str) and candidate["status"].strip() != ""


def waterline_query_responder_inputs(public_evidence: dict[str, Any]) -> dict[str, str] | None:
    ordered_candidate = scenario_evidence_candidate_from(public_evidence, "ordered_signal_delivery")
    if ordered_candidate is None:
        return None

    ordered = scenario_observed_outputs(ordered_candidate)
    base_url = (
        env_text("DW_SIGNALS_QUERIES_SERVER_URL")
        or env_text("DURABLE_WORKFLOW_SERVER_URL")
        or string_from_evidence(ordered, ("server_base_url", "server_url", "base_url"))
    )
    token = (
        env_text("DW_SIGNALS_QUERIES_AUTH_TOKEN")
        or env_text("DURABLE_WORKFLOW_AUTH_TOKEN")
        or env_text("DW_AUTH_TOKEN")
        or "dev-token"
    )
    namespace = (
        env_text("DW_SIGNALS_QUERIES_NAMESPACE")
        or env_text("DURABLE_WORKFLOW_NAMESPACE")
        or string_from_evidence(ordered, ("namespace",))
        or "default"
    )
    worker_id = string_from_evidence(ordered, ("worker_id",))
    task_queue = string_from_evidence(ordered, ("task_queue",))
    if base_url is None or worker_id is None or task_queue is None:
        return None

    return {
        "base_url": base_url.rstrip("/"),
        "token": token,
        "namespace": namespace,
        "worker_id": worker_id,
        "task_queue": task_queue,
    }


def run_waterline_observer_probe(
    result_dir: Path,
    current_evidence: Any,
    server_topology: dict[str, Any] | None = None,
) -> tuple[dict[str, Any] | None, dict[str, Any] | None]:
    if not env_flag("DW_SIGNALS_QUERIES_RUN_WATERLINE_OBSERVER_PROBE", True):
        return None, {"skipped": "disabled_by_env"}

    if waterline_observer_already_reported(current_evidence):
        return None, {"skipped": "waterline_operator_visibility_already_reported"}

    public_evidence = waterline_observer_public_evidence(current_evidence)
    if public_evidence is None:
        return waterline_observer_setup_result(
            status="fail",
            reason=(
                "Waterline observer comparison requires ordered_signal_delivery evidence "
                "identifying the workflow run and public counter state."
            ),
            blocker_kind="ordered_signal_delivery_evidence_unavailable",
        ), {"error": "ordered_signal_delivery_evidence_unavailable"}

    if not command_available("docker"):
        return waterline_observer_setup_result(
            status="runner_blocked",
            reason="Docker is required to install and run the published Waterline artifact.",
            blocker_kind="docker_unavailable",
        ), {"error": "docker_unavailable"}

    workflow_version = artifact_version_value(artifact_versions, "workflow-php")
    waterline_version = artifact_version_value(artifact_versions, "waterline")
    if is_placeholder_version(workflow_version) or is_placeholder_version(waterline_version):
        return waterline_observer_setup_result(
            status="runner_blocked",
            reason=(
                "Exact workflow-php and waterline versions are required before installing "
                "the published Waterline observer shard."
            ),
            blocker_kind="missing_exact_artifact_version",
        ), {"error": "missing_exact_artifact_version"}

    storage = waterline_storage_from_topology(server_topology)
    waterline_env = storage["env"] if isinstance(storage.get("env"), dict) else {}
    waterline_network = storage.get("docker_network") if isinstance(storage.get("docker_network"), str) else None
    if not waterline_env:
        return waterline_observer_setup_result(
            status="fail",
            reason=(
                "The runner did not expose workflow storage credentials for the published "
                "Waterline artifact, so the selected-run detail and query routes cannot be "
                "exercised against the observed signal/query workflow."
            ),
            blocker_kind="waterline_workflow_storage_unavailable",
        ), {"error": "waterline_workflow_storage_unavailable"}

    waterline_root = result_dir / "waterline-signals-queries-observer"
    waterline_root.mkdir(parents=True, exist_ok=True)
    log_file = result_dir / "waterline-signals-queries-observer.log"
    create = run_command(
        docker_run_for_project(
            waterline_root,
            ["composer", "create-project", "laravel/laravel", ".", "--no-interaction", "--no-progress", "--prefer-dist"],
            extra_env=waterline_env,
            network=waterline_network,
        ),
        log_file=log_file,
        timeout=300,
    )
    if create.returncode != 0:
        return waterline_observer_setup_result(
            status="runner_blocked",
            reason="Laravel app creation failed before Waterline observer shard execution.",
            blocker_kind="waterline_create_project",
        ), {"error": "waterline_create_project", "log_file": log_file.name}

    (waterline_root / "database").mkdir(parents=True, exist_ok=True)
    (waterline_root / "database" / "database.sqlite").touch()
    conformance_dir = waterline_root / "conformance"
    conformance_dir.mkdir(parents=True, exist_ok=True)

    public_evidence_path = conformance_dir / "public-evidence.json"
    waterline_report_path = conformance_dir / "waterline-signals-queries-result.json"
    write_json(public_evidence_path, public_evidence)

    require = run_command(
        docker_run_for_project(
            waterline_root,
            [
                "composer",
                "require",
                "--no-interaction",
                "--no-progress",
                "--prefer-dist",
                f"durable-workflow/workflow:{workflow_version}",
                f"durable-workflow/waterline:{waterline_version}",
            ],
            extra_env=waterline_env,
            network=waterline_network,
        ),
        log_file=log_file,
        timeout=420,
    )
    if require.returncode != 0:
        return waterline_observer_setup_result(
            status="fail",
            reason=(
                "Composer could not install pinned Packagist packages "
                f"durable-workflow/workflow:{workflow_version} and durable-workflow/waterline:{waterline_version}."
            ),
            blocker_kind="waterline_composer_require",
        ), {"error": "waterline_composer_require", "log_file": log_file.name}

    key = run_command(
        docker_run_for_project(
            waterline_root,
            ["php", "artisan", "key:generate", "--force"],
            extra_env=waterline_env,
            network=waterline_network,
        ),
        log_file=log_file,
        timeout=120,
    )
    artisan_list = run_command(
        docker_run_for_project(
            waterline_root,
            ["php", "artisan", "list", "--raw"],
            extra_env=waterline_env,
            network=waterline_network,
        ),
        log_file=log_file,
        timeout=120,
    )
    if key.returncode != 0 or artisan_list.returncode != 0:
        return waterline_observer_setup_result(
            status="fail",
            reason="The Composer-installed Waterline package could not boot its Laravel command surface.",
            blocker_kind="waterline_artisan_list",
        ), {"error": "waterline_artisan_list", "log_file": log_file.name}

    if "waterline:signals-queries-conformance" not in artisan_list.stdout:
        return waterline_observer_setup_result(
            status="fail",
            reason="The Composer-installed Waterline package does not expose waterline:signals-queries-conformance.",
            blocker_kind="waterline_command_missing",
        ), {"error": "waterline_command_missing", "log_file": log_file.name}

    workflow_id = str(public_evidence["workflow_instance_id"])
    run_id = str(public_evidence["workflow_run_id"])
    run_status_value = str(public_evidence["run_status"])
    query_name = str(public_evidence["query_name"])
    counter = integer_from_evidence(public_evidence, ("current_counter", "counter", "queried_total"))
    responder_inputs = waterline_query_responder_inputs(public_evidence)
    responder_holder: dict[str, Any] = {}
    responder: threading.Thread | None = None
    if responder_inputs is not None and counter is not None:
        responder = threading.Thread(
            target=answer_next_query_task,
            args=(
                responder_inputs["base_url"],
                responder_inputs["token"],
                responder_inputs["namespace"],
                responder_inputs["worker_id"],
                responder_inputs["task_queue"],
                counter,
                log_file,
                responder_holder,
                210.0,
            ),
            daemon=True,
        )
        responder.start()

    command = run_command(
        docker_run_for_project(
            waterline_root,
            [
                "php",
                "artisan",
                "waterline:signals-queries-conformance",
                "--input=/app/conformance/public-evidence.json",
                "--output=/app/conformance/waterline-signals-queries-result.json",
                f"--run-id=signals-queries-{run_id}",
                f"--instance-id={workflow_id}",
                f"--workflow-run-id={run_id}",
                f"--run-status={run_status_value}",
                f"--query={query_name}",
                f"--artifact-version=server={artifact_version_value(artifact_versions, 'server')}",
                f"--artifact-version=cli={artifact_version_value(artifact_versions, 'cli')}",
                f"--artifact-version=sdk-python={artifact_version_value(artifact_versions, 'sdk-python')}",
                f"--artifact-version=workflow-php={workflow_version}",
                f"--artifact-version=waterline={waterline_version}",
                "--artifact-source=server=docker_image",
                "--artifact-source=cli=official_install_script",
                "--artifact-source=sdk-python=pypi_package",
                "--artifact-source=workflow-php=packagist_package",
                "--artifact-source=waterline=packagist_package",
            ],
            extra_env=waterline_env,
            network=waterline_network,
        ),
        log_file=log_file,
        timeout=240,
    )
    if responder is not None:
        responder.join(timeout=5)
    if not waterline_report_path.is_file():
        return waterline_observer_setup_result(
            status="fail",
            reason=(
                "waterline:signals-queries-conformance exited without writing a report "
                f"(status {command.returncode})."
            ),
            blocker_kind="waterline_conformance_command",
        ), {"error": "waterline_conformance_command", "log_file": log_file.name}

    try:
        waterline_report = json.loads(waterline_report_path.read_text(encoding="utf-8"))
    except Exception as exc:  # noqa: BLE001 - malformed shard report becomes a focused finding.
        return waterline_observer_setup_result(
            status="fail",
            reason=f"Waterline observer shard report was not valid JSON: {type(exc).__name__}: {exc}",
            blocker_kind="waterline_report_decode",
        ), {"error": "waterline_report_decode", "log_file": log_file.name}

    if isinstance(waterline_report, dict):
        raw_scenarios = waterline_report.get("scenario_results")
        if isinstance(raw_scenarios, list):
            waterline_scenarios = {
                str(item["scenario_id"]): item
                for item in raw_scenarios
                if isinstance(item, dict) and isinstance(item.get("scenario_id"), str)
            }
        elif isinstance(raw_scenarios, dict):
            waterline_scenarios = raw_scenarios
        else:
            waterline_scenarios = {}

        waterline_visibility = waterline_scenarios.get("waterline_operator_visibility")
        if not isinstance(waterline_visibility, dict):
            return waterline_observer_setup_result(
                status="fail",
                reason="Waterline observer shard report did not include waterline_operator_visibility.",
                blocker_kind="waterline_visibility_scenario_missing",
            ), {"error": "waterline_visibility_scenario_missing", "log_file": log_file.name}

        waterline_report = {
            "artifact_versions": dict(artifact_versions),
            "scenario_results": {
                "waterline_operator_visibility": waterline_visibility,
            },
        }

    descriptor = {
        "file": waterline_report_path.name,
        "log_file": log_file.name,
        "workflow_id": workflow_id,
        "run_id": run_id,
        "query_name": query_name,
        "command_status": command.returncode,
        "generated_scenarios": ["waterline_operator_visibility"],
        "shard_command": "waterline:signals-queries-conformance",
        "real_capture_source": "published_waterline_artifact_http_kernel",
        "waterline_workflow_storage": {
            "source": storage.get("source"),
            "docker_network": waterline_network,
            "env": storage.get("redacted_env"),
        },
        "query_responder": {
            "started": responder is not None,
            "poll_status_code": responder_holder.get("poll", {}).get("status_code")
            if isinstance(responder_holder.get("poll"), dict)
            else None,
            "complete_status_code": responder_holder.get("complete", {}).get("status_code")
            if isinstance(responder_holder.get("complete"), dict)
            else None,
            "error": responder_holder.get("error"),
        },
    }
    return waterline_report if isinstance(waterline_report, dict) else None, descriptor


MISSING = object()
FORBIDDEN_ARTIFACT_SOURCES = (
    "local_product_source_checkout",
    "workspace_repo_as_artifact_under_test",
)
ARTIFACT_SOURCE_FIELDS = ("artifact_sources", "artifactSources")


def evidence_value(value: Any, key: str) -> Any:
    if isinstance(value, dict):
        if key in value:
            return value[key]
        for child in value.values():
            found = evidence_value(child, key)
            if found is not MISSING:
                return found
    if isinstance(value, list):
        for child in value:
            found = evidence_value(child, key)
            if found is not MISSING:
                return found
    return MISSING


def is_forbidden_artifact_source(source: Any) -> bool:
    if not isinstance(source, str):
        return False

    normalized = source.strip().lower()
    if normalized == "":
        return False

    return any(
        normalized == forbidden or forbidden in normalized
        for forbidden in FORBIDDEN_ARTIFACT_SOURCES
    )


def artifact_source_policy_violations(value: Any, path: str = "$") -> list[dict[str, str]]:
    violations: list[dict[str, str]] = []

    if isinstance(value, dict):
        for field in ARTIFACT_SOURCE_FIELDS:
            sources = value.get(field)
            if not isinstance(sources, dict):
                continue

            for artifact, source in sources.items():
                if not is_forbidden_artifact_source(source):
                    continue

                violations.append(
                    {
                        "path": f"{path}.{field}",
                        "field": field,
                        "artifact": str(artifact),
                        "source": str(source),
                    }
                )

        for key, child in value.items():
            if isinstance(child, (dict, list)):
                violations.extend(artifact_source_policy_violations(child, f"{path}.{key}"))

    if isinstance(value, list):
        for index, child in enumerate(value):
            if isinstance(child, (dict, list)):
                violations.extend(artifact_source_policy_violations(child, f"{path}[{index}]"))

    return violations


def evidence_source_policy_violations(*values: Any) -> list[dict[str, str]]:
    violations: list[dict[str, str]] = []
    for value in values:
        violations.extend(artifact_source_policy_violations(value))
    return violations


def flat_smoke_field(key: str) -> Any:
    if smoke_evidence is None:
        return MISSING
    if not isinstance(smoke_evidence, dict):
        return MISSING
    return smoke_evidence.get(key, MISSING)


def smoke_field(key: str, scenario: str | None = None) -> Any:
    value = flat_smoke_field(key)
    if value is not MISSING:
        return value

    if scenario is None:
        return MISSING

    candidate = scenario_evidence_candidate(scenario)
    if candidate is None:
        return MISSING

    observed = scenario_observed_outputs(candidate)
    found = evidence_lookup(observed, key)
    if found is not MISSING:
        return found

    if key == "ten_signal_ordered_delivery_total":
        return evidence_lookup(observed, "queried_total")

    return MISSING


def smoke_field_present(key: str, scenario: str | None = None) -> bool:
    value = smoke_field(key, scenario)
    return value is not MISSING and value not in (None, "", [], {})


def smoke_field_true(key: str, scenario: str | None = None) -> bool:
    value = smoke_field(key, scenario)
    if value is True:
        return True
    if isinstance(value, str):
        return value.strip().lower() in {"true", "pass", "passed", "ok", "yes"}
    return False


def is_placeholder_version(version: str) -> bool:
    normalized = version.strip().lower()
    if not normalized:
        return True
    placeholder_tokens = ("latest", "current", "head", "unresolved", "placeholder")
    return (
        normalized.startswith("<")
        or "${" in normalized
        or "{{" in normalized
        or any(token in normalized for token in placeholder_tokens)
    )


def artifact_versions_pinned() -> bool:
    return all(not is_placeholder_version(str(artifact_versions.get(artifact, ""))) for artifact in REQUIRED_INSTALL_ARTIFACTS)


REQUIRED_INSTALL_ARTIFACTS = ("server", "cli", "sdk-python", "workflow-php", "waterline")
REQUIRED_INSTALL_PROOF_ARTIFACTS = ("server", "cli", "sdk-python")
EXPECTED_ARTIFACT_SOURCES = {
    "server": "published_docker_image",
    "cli": "published_cli_release",
    "sdk-python": "published_pypi_package",
    "workflow-php": "published_composer_package",
    "waterline": "published_waterline_artifact",
}

ARTIFACT_VERSION_ALIASES: dict[str, list[str]] = {
    "workflow-php": ["workflow-php", "workflow_php", "workflow"],
    "sdk-python": ["sdk-python", "sdk_python", "python"],
    "waterline": ["waterline", "waterline-ui", "waterline_ui"],
}

ARTIFACT_VERSION_FIELDS = (
    "artifact_versions",
    "artifactVersions",
    "published_artifact_versions",
    "publishedArtifactVersions",
)


def artifact_version_value(versions: dict[str, Any], artifact: str) -> str:
    for key in ARTIFACT_VERSION_ALIASES.get(artifact, [artifact]):
        value = versions.get(key)
        if value is None:
            continue
        normalized = str(value).strip()
        if normalized:
            return normalized
    return ""


def artifact_source_value(sources: dict[str, Any], artifact: str) -> str:
    for key in ARTIFACT_VERSION_ALIASES.get(artifact, [artifact]):
        value = sources.get(key)
        if value is None:
            continue
        normalized = str(value).strip()
        if normalized:
            return normalized
    return ""


def published_source_matches_artifact(source: str, artifact: str) -> bool:
    return source.strip() == EXPECTED_ARTIFACT_SOURCES.get(artifact, "")


def declared_artifact_versions(value: Any) -> dict[str, Any]:
    if not isinstance(value, dict):
        return {}

    for field in ARTIFACT_VERSION_FIELDS:
        versions = value.get(field)
        if isinstance(versions, dict):
            return versions

    return {}


def declared_artifact_version_maps(value: Any) -> list[dict[str, Any]]:
    if isinstance(value, list):
        maps: list[dict[str, Any]] = []
        for child in value:
            maps.extend(declared_artifact_version_maps(child))
        return maps

    if not isinstance(value, dict):
        return []

    maps = []
    versions = declared_artifact_versions(value)
    if versions:
        maps.append(versions)

    for child in value.values():
        maps.extend(declared_artifact_version_maps(child))

    return maps


def artifact_version_mismatches(versions: dict[str, Any]) -> dict[str, dict[str, str]]:
    mismatched: dict[str, dict[str, str]] = {}
    for artifact in REQUIRED_INSTALL_ARTIFACTS:
        expected = artifact_version_value(artifact_versions, artifact)
        actual = artifact_version_value(versions, artifact)
        if expected and actual and expected != actual:
            mismatched[artifact] = {"expected": expected, "actual": actual}
    return mismatched


def evidence_artifact_version_mismatches(value: Any) -> dict[str, dict[str, str]]:
    mismatched: dict[str, dict[str, str]] = {}
    for versions in declared_artifact_version_maps(value):
        for artifact, mismatch in artifact_version_mismatches(versions).items():
            mismatched.setdefault(artifact, mismatch)
    return mismatched


def smoke_artifact_version_mismatches() -> dict[str, dict[str, str]]:
    return evidence_artifact_version_mismatches(smoke_evidence)


def evidence_matches_current_tuple(value: Any) -> bool:
    return evidence_artifact_version_mismatches(value) == {}


def smoke_evidence_matches_current_tuple() -> bool:
    return evidence_matches_current_tuple(smoke_evidence)


def candidate_artifact_versions(candidate: dict[str, Any], observed: dict[str, Any]) -> dict[str, Any]:
    for value in (candidate, observed):
        versions = declared_artifact_versions(value)
        if versions:
            return versions

    return {}


def candidate_matches_current_tuple(candidate: dict[str, Any], observed: dict[str, Any]) -> bool:
    if evidence_source_policy_violations(candidate, observed):
        return False

    versions = candidate_artifact_versions(candidate, observed)
    if versions:
        return artifact_version_mismatches(versions) == {}

    return smoke_evidence_matches_current_tuple()


def output_field(observed: dict[str, Any], *keys: str) -> Any:
    for key in keys:
        value = evidence_lookup(observed, key)
        if value is not MISSING:
            return value
    return MISSING


def is_python_worker_runtime(value: Any) -> bool:
    if not isinstance(value, str):
        return False
    return value.strip().lower() in {
        "sdk-python",
        "python",
        "sdk_python",
        "python_worker",
    }


def is_published_python_sdk_source(value: Any) -> bool:
    if not isinstance(value, str):
        return False
    return published_source_matches_artifact(value, "sdk-python")


def python_sdk_version_matches_current(value: Any) -> bool:
    if value is MISSING or value is None:
        return False
    actual = str(value).strip()
    expected = artifact_version_value(artifact_versions, "sdk-python")
    return actual != "" and not is_placeholder_version(actual) and (expected == "" or actual == expected)


def python_worker_claim_satisfied(observed: dict[str, Any]) -> bool:
    return (
        is_python_worker_runtime(output_field(observed, "worker_runtime", "workerRuntime", "python_worker_runtime"))
        and is_published_python_sdk_source(
            output_field(
                observed,
                "python_worker_artifact_source",
                "pythonWorkerArtifactSource",
                "worker_artifact_source",
                "workerArtifactSource",
            )
        )
        and python_sdk_version_matches_current(
            output_field(
                observed,
                "python_worker_sdk_version",
                "pythonWorkerSdkVersion",
                "worker_sdk_version",
                "workerSdkVersion",
            )
        )
    )


def is_workflow_php_worker_runtime(value: Any) -> bool:
    if not isinstance(value, str):
        return False
    return value.strip().lower() in {
        "workflow-php",
        "workflow_php",
        "php",
        "php_worker",
    }


def is_published_workflow_php_source(value: Any) -> bool:
    if not isinstance(value, str):
        return False
    return published_source_matches_artifact(value, "workflow-php")


def workflow_php_version_matches_current(value: Any) -> bool:
    if value is MISSING or value is None:
        return False
    actual = str(value).strip()
    expected = artifact_version_value(artifact_versions, "workflow-php")
    return actual != "" and not is_placeholder_version(actual) and (expected == "" or actual == expected)


def workflow_php_worker_claim_satisfied(observed: dict[str, Any]) -> bool:
    return (
        is_workflow_php_worker_runtime(output_field(observed, "worker_runtime", "workerRuntime", "php_worker_runtime"))
        and is_published_workflow_php_source(
            output_field(
                observed,
                "workflow_php_artifact_source",
                "workflowPhpArtifactSource",
                "worker_artifact_source",
                "workerArtifactSource",
            )
        )
        and workflow_php_version_matches_current(
            output_field(
                observed,
                "workflow_php_sdk_version",
                "workflowPhpSdkVersion",
                "worker_sdk_version",
                "workerSdkVersion",
            )
        )
    )


def exact_python_smoke_present() -> bool:
    candidate = scenario_evidence_candidate("python_worker_cli_and_sdk_baseline")
    observed = scenario_observed_outputs(candidate) if candidate is not None else smoke_evidence
    return (
        isinstance(observed, dict)
        and python_worker_claim_satisfied(observed)
        and routed_current_query_task_satisfied(
            evidence_lookup(observed, "routed_current_query_task")
        )
        and all(
            smoke_field_true(field, "python_worker_cli_and_sdk_baseline")
            for field in (
                "python_worker_query_task_routing",
                "cli_signal_and_query",
                "sdk_python_signal_and_query",
                "immediate_repeat_query_consistency",
            )
        )
    )


def exact_ordered_delivery_smoke_present() -> bool:
    observed = {
        "rapid_increment_inputs": smoke_field("rapid_increment_inputs", "ordered_signal_delivery"),
        "accepted_signal_inputs": smoke_field("accepted_signal_inputs", "ordered_signal_delivery"),
        "accepted_signal_total": smoke_field("accepted_signal_total", "ordered_signal_delivery"),
        "queried_total": smoke_field("queried_total", "ordered_signal_delivery"),
        "history_signal_order": smoke_field("history_signal_order", "ordered_signal_delivery"),
        "final_run_status": smoke_field("final_run_status", "ordered_signal_delivery"),
    }

    return ordered_delivery_observations_agree(observed)


ALLOWED_SCENARIO_STATUSES = {"pass", "fail", "unsupported", "not_covered", "runner_blocked"}

SCENARIO_REQUIRED_EVIDENCE: dict[str, list[str]] = {
    "published_artifact_install_only": [
        "published_artifact_versions",
        "artifact_sources",
        "artifact_install_evidence",
    ],
    "python_worker_cli_and_sdk_baseline": [
        "worker_runtime",
        "python_worker_artifact_source",
        "python_worker_sdk_version",
        "python_worker_query_task_routing",
        "routed_current_query_task",
        "cli_signal_and_query",
        "sdk_python_signal_and_query",
        "immediate_repeat_query_consistency",
    ],
    "php_worker_cli_and_sdk_baseline": [
        "worker_runtime",
        "workflow_php_artifact_source",
        "workflow_php_sdk_version",
        "php_worker_query_task_routing",
        "cli_signal_and_query",
        "workflow_php_signal_and_query",
        "immediate_repeat_query_consistency",
    ],
    "python_worker_php_facing_and_cli_clients": [
        "php_client_signal_and_query",
        "cli_signal_and_query",
        "cross_language_query_consistency",
        "wire_envelope_compatibility",
    ],
    "php_worker_python_and_cli_clients": [
        "sdk_python_signal_and_query",
        "cli_signal_and_query",
        "cross_language_query_consistency",
        "wire_envelope_compatibility",
    ],
    "ordered_signal_delivery": [
        "rapid_increment_inputs",
        "accepted_signal_inputs",
        "accepted_signal_total",
        "queried_total",
        "history_signal_order",
        "final_run_status",
    ],
    "dedup_contract_observation": [
        "client_side_key_support",
        "documented_contract",
        "handler_observation_count",
    ],
    "signal_during_replay": [
        "signal_api_sample",
        "signal_status_code",
        "worker_restart_at",
        "signal_sent_at",
        "replay_completed_at",
        "signal_applied_at",
    ],
    "query_during_replay": [
        "query_api_sample",
        "query_status_code",
        "worker_restart_at",
        "query_sent_at",
        "query_poll_started_at",
        "replay_completed_at",
        "query_handler_invoked_at",
        "query_completed_at",
        "query_answer",
        "expected_answer",
    ],
    "completed_run_signal_and_query": [
        "completed_run_id",
        "completed_at",
        "signal_api_sample",
        "signal_error.status_code",
        "signal_error.reason",
        "signal_error.rejection_reason",
        "query_api_sample",
        "query_result_or_error.status_code",
        "query_result_or_error.outcome",
        "signal_error",
        "query_result_or_error",
        "public_query_surfaces",
        "run_status_after_operations",
    ],
    "unknown_signal_and_query_errors": [
        "unknown_signal",
        "missing_workflow_signal",
        "missing_workflow_query",
        "query_not_found",
        "rejected_unknown_query",
        "known_query_after_unknown_errors",
    ],
    "malformed_signal_and_query_payloads": [
        "invalid_signal_arguments",
        "invalid_query_arguments",
        "invalid_signal_arguments.status_code",
        "invalid_signal_arguments.reason",
        "invalid_query_arguments.status_code",
        "invalid_query_arguments.reason",
        "invalid_signal_arguments_context",
        "invalid_query_arguments_context",
        "signal_handler_invocation_count_after_invalid_payload",
        "query_state_mutation_count_after_invalid_payload",
        "post_error_valid_query_result",
        "cli_invalid_signal_arguments_sample",
        "cli_invalid_query_arguments_sample",
        "sdk_python_invalid_signal_arguments_sample",
        "sdk_python_invalid_query_arguments_sample",
    ],
    "waterline_operator_visibility": [
        "artifact_versions",
        "artifact_sources",
        "captured_at",
        "observer_state.selected_run",
        "observer_state.signals",
        "observer_state.queries",
        "observer_state.paths.selected_run_query_template",
        "api_paths.selected_run_detail",
        "api_paths.selected_run_query_action",
        "dashboard_json_envelopes.selected_run_detail",
        "api_captures.selected_run_detail",
        "api_captures.selected_run_query_action",
        "comparison.run_status_matches_public_clients",
        "comparison.counter_state_matches_public_clients",
        "comparison.server_observation",
        "comparison.cli_observation",
        "comparison.sdk_observation",
    ],
}

TRUTHY_REQUIRED_EVIDENCE = {
    "python_worker_query_task_routing",
    "cli_signal_and_query",
    "sdk_python_signal_and_query",
    "immediate_repeat_query_consistency",
    "php_worker_query_task_routing",
    "workflow_php_signal_and_query",
    "php_client_signal_and_query",
    "cross_language_query_consistency",
    "wire_envelope_compatibility",
    "comparison.run_status_matches_public_clients",
    "comparison.counter_state_matches_public_clients",
}


def path_value(value: Any, path: list[str]) -> Any:
    current = value
    for segment in path:
        if not isinstance(current, dict) or segment not in current:
            return MISSING
        current = current[segment]
    return current


def evidence_present(value: Any) -> bool:
    if value is MISSING or value is None:
        return False
    if isinstance(value, str):
        return value.strip() != ""
    if isinstance(value, (list, dict)):
        return bool(value)
    return True


def evidence_true(value: Any) -> bool:
    if value is True:
        return True
    if isinstance(value, str):
        return value.strip().lower() in {"true", "pass", "passed", "ok", "yes"}
    return False


def required_evidence_satisfied(evidence_key: str, value: Any) -> bool:
    if evidence_key in TRUTHY_REQUIRED_EVIDENCE:
        return evidence_true(value)

    return evidence_present(value)


def evidence_lookup(value: Any, key: str) -> Any:
    if "." in key and isinstance(value, dict):
        found = path_value(value, key.split("."))
        if found is not MISSING:
            return found

    return evidence_value(value, key)


def integer_value(value: Any) -> int | None:
    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, str) and value.strip().lstrip("-").isdigit():
        return int(value.strip())
    return None


def integer_sequence(value: Any) -> list[int] | None:
    if not isinstance(value, list):
        return None

    sequence: list[int] = []
    for item in value:
        if isinstance(item, bool) or not isinstance(item, int):
            return None
        sequence.append(item)
    return sequence


def expected_rapid_signal_inputs() -> list[int]:
    return list(range(1, 11))


def ordered_delivery_reference_inputs(observed: dict[str, Any]) -> list[int] | None:
    accepted_inputs = integer_sequence(evidence_lookup(observed, "accepted_signal_inputs"))
    if accepted_inputs is not None:
        return accepted_inputs

    return integer_sequence(evidence_lookup(observed, "rapid_increment_inputs"))


def ordered_delivery_observations_agree(observed: dict[str, Any]) -> bool:
    rapid_inputs = integer_sequence(evidence_lookup(observed, "rapid_increment_inputs"))
    accepted_inputs = integer_sequence(evidence_lookup(observed, "accepted_signal_inputs"))
    accepted_signal_total = integer_value(evidence_lookup(observed, "accepted_signal_total"))
    queried_total = integer_value(evidence_lookup(observed, "queried_total"))
    history_signal_order = integer_sequence(evidence_lookup(observed, "history_signal_order"))
    final_run_status = evidence_lookup(observed, "final_run_status")

    return (
        rapid_inputs == expected_rapid_signal_inputs()
        and accepted_inputs == rapid_inputs
        and accepted_signal_total == sum(accepted_inputs)
        and queried_total == sum(accepted_inputs)
        and history_signal_order == accepted_inputs
        and required_evidence_satisfied("final_run_status", final_run_status)
    )


def status_code_in_range(observed: dict[str, Any], key: str, minimum: int, maximum: int) -> bool:
    status = integer_value(evidence_lookup(observed, key))
    return status is not None and minimum <= status <= maximum


def reason_in(observed: dict[str, Any], key: str, allowed: set[str]) -> bool:
    value = evidence_lookup(observed, key)
    return isinstance(value, str) and value in allowed


def artifact_sources_from_outputs(observed: dict[str, Any]) -> dict[str, Any]:
    for field in ARTIFACT_SOURCE_FIELDS:
        sources = observed.get(field)
        if isinstance(sources, dict):
            return sources

    return {}


def artifact_install_evidence_from_outputs(observed: dict[str, Any]) -> dict[str, Any]:
    for field in (
        "artifact_install_evidence",
        "artifactInstallEvidence",
        "install_evidence",
        "installEvidence",
    ):
        evidence = observed.get(field)
        if isinstance(evidence, dict):
            return evidence

    return {}


def install_evidence_entry(install_evidence: dict[str, Any], artifact: str) -> dict[str, Any] | None:
    artifacts = install_evidence.get("artifacts")
    if isinstance(artifacts, list):
        for entry in artifacts:
            if not isinstance(entry, dict):
                continue
            entry_artifact = str(
                entry.get("artifact")
                or entry.get("name")
                or entry.get("id")
                or entry.get("package")
                or ""
            )
            if entry_artifact == artifact:
                return entry

    direct = install_evidence.get(artifact)
    if isinstance(direct, dict):
        return direct

    return None


def entry_text(entry: dict[str, Any], *keys: str) -> str:
    for key in keys:
        value = entry.get(key)
        if value is None:
            continue
        text = str(value).strip()
        if text:
            return text
    return ""


def entry_has_local_checkout(entry: dict[str, Any]) -> bool:
    for key in ("local_product_source_checkouts_used", "localProductSourceCheckoutsUsed"):
        value = entry.get(key)
        if value is True:
            return True
        if isinstance(value, str) and value.strip().lower() in {"1", "true", "yes"}:
            return True
    return False


def explicit_false_local_checkout(value: dict[str, Any]) -> bool:
    return any(
        value.get(key) is False
        for key in ("local_product_source_checkouts_used", "localProductSourceCheckoutsUsed")
    )


def install_outputs_cover_required_artifacts(observed: dict[str, Any]) -> bool:
    versions = declared_artifact_versions(observed)
    sources = artifact_sources_from_outputs(observed)
    install_evidence = artifact_install_evidence_from_outputs(observed)
    if not versions or not sources:
        return False
    if not install_evidence:
        return False
    if not any(
        isinstance(observed.get(field), dict) and observed.get(field)
        for field in ("published_artifact_versions", "publishedArtifactVersions")
    ):
        return False

    if evidence_source_policy_violations({"artifact_sources": sources}):
        return False

    if entry_has_local_checkout(observed):
        return False
    if not explicit_false_local_checkout(install_evidence):
        return False

    for artifact in REQUIRED_INSTALL_PROOF_ARTIFACTS:
        version = artifact_version_value(versions, artifact)
        source = artifact_source_value(sources, artifact)
        if version == "" or is_placeholder_version(version):
            return False
        if source == "" or is_forbidden_artifact_source(source):
            return False
        if not published_source_matches_artifact(source, artifact):
            return False
        entry = install_evidence_entry(install_evidence, artifact)
        if entry is None:
            return False
        status = entry_text(entry, "status", "result", "outcome").lower()
        if status != "pass":
            return False
        entry_version = entry_text(
            entry,
            "version",
            "resolved_version",
            "resolvedVersion",
            "artifact_version",
            "artifactVersion",
        )
        if entry_version == "" or is_placeholder_version(entry_version) or entry_version != version:
            return False
        entry_source = entry_text(
            entry,
            "source",
            "install_source",
            "installSource",
            "artifact_source",
            "artifactSource",
        )
        if entry_source == "" or not published_source_matches_artifact(entry_source, artifact):
            return False
        if entry_has_local_checkout(entry):
            return False

    for artifact in REQUIRED_INSTALL_ARTIFACTS:
        source = artifact_source_value(sources, artifact)
        if source == "" or is_forbidden_artifact_source(source):
            return False
        if not published_source_matches_artifact(source, artifact):
            return False

    return True


def timestamp_seconds(value: Any) -> float | None:
    if not isinstance(value, str) or value.strip() == "":
        return None
    normalized = value.strip()
    if normalized.endswith("Z"):
        normalized = f"{normalized[:-1]}+00:00"
    try:
        return datetime.fromisoformat(normalized).timestamp()
    except ValueError:
        return None


def timestamps_in_order(observed: dict[str, Any], orders: list[tuple[str, str, str]]) -> bool:
    for left_key, operator, right_key in orders:
        left = timestamp_seconds(evidence_lookup(observed, left_key))
        right = timestamp_seconds(evidence_lookup(observed, right_key))
        if left is None or right is None:
            return False
        if operator == "<" and not left < right:
            return False
        if operator == "<=" and not left <= right:
            return False
    return True


def has_required_evidence(scenario: str, observed: dict[str, Any]) -> bool:
    if scenario == "published_artifact_install_only":
        return artifact_versions_pinned() and install_outputs_cover_required_artifacts(observed)

    if scenario == "python_worker_cli_and_sdk_baseline":
        return (
            python_worker_claim_satisfied(observed)
            and routed_current_query_task_satisfied(
                evidence_lookup(observed, "routed_current_query_task")
            )
            and all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
        )

    if scenario == "php_worker_cli_and_sdk_baseline":
        return (
            workflow_php_worker_claim_satisfied(observed)
            and all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
        )

    if scenario == "ordered_signal_delivery":
        return ordered_delivery_observations_agree(observed)

    if scenario == "signal_during_replay":
        return (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "signal_status_code", 200, 299)
            and timestamps_in_order(
                observed,
                [
                    ("worker_restart_at", "<=", "signal_sent_at"),
                    ("signal_sent_at", "<", "replay_completed_at"),
                    ("replay_completed_at", "<=", "signal_applied_at"),
                ],
            )
        )

    if scenario == "query_during_replay":
        return (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "query_status_code", 200, 299)
            and evidence_lookup(observed, "query_answer") == evidence_lookup(observed, "expected_answer")
            and timestamps_in_order(
                observed,
                [
                    ("worker_restart_at", "<=", "query_sent_at"),
                    ("query_sent_at", "<=", "query_poll_started_at"),
                    ("query_poll_started_at", "<", "replay_completed_at"),
                    ("replay_completed_at", "<=", "query_handler_invoked_at"),
                    ("query_handler_invoked_at", "<=", "query_completed_at"),
                ],
            )
        )

    if scenario == "completed_run_signal_and_query":
        query_status = integer_value(evidence_lookup(observed, "query_result_or_error.status_code"))
        query_reason = evidence_lookup(observed, "query_result_or_error.reason")
        return (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "signal_error.status_code", 400, 499)
            and evidence_lookup(observed, "signal_error.reason") == "run_not_active"
            and evidence_lookup(observed, "signal_error.rejection_reason") == "run_not_active"
            and query_status is not None
            and 200 <= query_status <= 499
            and (query_status < 400 or required_evidence_satisfied("query_result_or_error.reason", query_reason))
        )

    if scenario == "unknown_signal_and_query_errors":
        query_reasons = {"query_not_found", "rejected_unknown_query"}
        required_passed = (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "unknown_signal.status_code", 404, 404)
            and status_code_in_range(observed, "missing_workflow_signal.status_code", 404, 404)
            and status_code_in_range(observed, "missing_workflow_query.status_code", 404, 404)
            and status_code_in_range(observed, "query_not_found.status_code", 404, 404)
            and status_code_in_range(observed, "known_query_after_unknown_errors.status_code", 200, 299)
            and reason_in(observed, "unknown_signal.reason", {"unknown_signal"})
            and reason_in(observed, "missing_workflow_signal.reason", {"instance_not_found"})
            and reason_in(observed, "missing_workflow_query.reason", {"instance_not_found"})
            and reason_in(observed, "query_not_found.reason", query_reasons)
            and reason_in(observed, "rejected_unknown_query.reason", query_reasons)
        )
        if not required_passed:
            return False

        optional_checks = {
            "cli_unknown_signal_sample": ({"unknown_signal"}, None, True),
            "cli_unknown_query_sample": (query_reasons, None, True),
            "cli_missing_workflow_signal_sample": ({"instance_not_found"}, None, True),
            "cli_missing_workflow_query_sample": ({"instance_not_found"}, None, True),
            "sdk_python_unknown_signal_sample": ({"unknown_signal"}, "SignalFailed", True),
            "sdk_python_unknown_query_sample": (query_reasons, "QueryFailed", True),
            "sdk_python_missing_workflow_signal_sample": ({"instance_not_found"}, "WorkflowNotFound", False),
            "sdk_python_missing_workflow_query_sample": ({"instance_not_found"}, "WorkflowNotFound", False),
        }
        for field, (reasons, exception, require_status_code) in optional_checks.items():
            sample = evidence_lookup(observed, field)
            if sample is MISSING:
                continue
            if not isinstance(sample, dict):
                return False
            if require_status_code and not status_code_in_range(observed, f"{field}.status_code", 404, 404):
                return False
            if not reason_in(observed, f"{field}.reason", reasons):
                return False
            if exception is not None and evidence_lookup(observed, f"{field}.exception") != exception:
                return False
        return True

    if scenario == "malformed_signal_and_query_payloads":
        return (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "invalid_signal_arguments.status_code", 422, 422)
            and status_code_in_range(observed, "invalid_query_arguments.status_code", 422, 422)
            and evidence_lookup(observed, "invalid_signal_arguments.reason") == "invalid_signal_arguments"
            and evidence_lookup(observed, "invalid_query_arguments.reason") == "invalid_query_arguments"
            and status_code_in_range(observed, "cli_invalid_signal_arguments_sample.status_code", 422, 422)
            and status_code_in_range(observed, "cli_invalid_query_arguments_sample.status_code", 422, 422)
            and evidence_lookup(
                observed,
                "cli_invalid_signal_arguments_sample.reason",
            ) == "invalid_signal_arguments"
            and evidence_lookup(
                observed,
                "cli_invalid_query_arguments_sample.reason",
            ) == "invalid_query_arguments"
            and status_code_in_range(observed, "sdk_python_invalid_signal_arguments_sample.status_code", 422, 422)
            and status_code_in_range(observed, "sdk_python_invalid_query_arguments_sample.status_code", 422, 422)
            and evidence_lookup(
                observed,
                "sdk_python_invalid_signal_arguments_sample.reason",
            ) == "invalid_signal_arguments"
            and evidence_lookup(
                observed,
                "sdk_python_invalid_query_arguments_sample.reason",
            ) == "invalid_query_arguments"
            and evidence_lookup(
                observed,
                "sdk_python_invalid_signal_arguments_sample.exception",
            ) == "SignalFailed"
            and evidence_lookup(
                observed,
                "sdk_python_invalid_query_arguments_sample.exception",
            ) == "QueryFailed"
            and integer_value(evidence_lookup(
                observed,
                "signal_handler_invocation_count_after_invalid_payload",
            )) == 0
            and integer_value(evidence_lookup(
                observed,
                "query_state_mutation_count_after_invalid_payload",
            )) == 0
        )

    return all(
        required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
        for evidence_key in SCENARIO_REQUIRED_EVIDENCE.get(scenario, [])
    )


def scenario_result_items(raw: Any) -> list[dict[str, Any]]:
    if isinstance(raw, dict):
        items = []
        for scenario_id, value in raw.items():
            if not isinstance(value, dict):
                continue
            item = dict(value)
            item.setdefault("scenario_id", scenario_id)
            items.append(item)
        return items

    if isinstance(raw, list):
        return [item for item in raw if isinstance(item, dict)]

    return []


def scenario_evidence_candidate_from(evidence: Any, scenario: str) -> dict[str, Any] | None:
    if not isinstance(evidence, dict):
        return None

    for field in ("scenario_results", "scenarioResults"):
        for item in scenario_result_items(evidence.get(field)):
            candidate_scenario = item.get("scenario_id") or item.get("scenario") or item.get("id")
            if candidate_scenario == scenario:
                return item

    direct = evidence.get(scenario)
    if isinstance(direct, dict):
        return direct

    for section in (
        "replay_timing",
        "terminal_run_behavior",
        "adversarial_errors",
        "waterline_observer_comparison",
    ):
        section_value = evidence.get(section)
        if not isinstance(section_value, dict):
            continue

        keyed = section_value.get(scenario)
        if isinstance(keyed, dict):
            return keyed

        for item in scenario_result_items(section_value):
            candidate_scenario = item.get("scenario_id") or item.get("scenario") or item.get("id")
            if candidate_scenario == scenario:
                return item

    return None


def scenario_evidence_candidate(scenario: str) -> dict[str, Any] | None:
    return scenario_evidence_candidate_from(smoke_evidence, scenario)


def scenario_status(candidate: dict[str, Any]) -> str:
    for field in ("status", "outcome", "verdict"):
        status = candidate.get(field)
        if isinstance(status, str) and status in ALLOWED_SCENARIO_STATUSES:
            return status

    return ""


def scenario_observed_outputs(candidate: dict[str, Any]) -> dict[str, Any]:
    for field in ("observed_outputs", "observedOutputs", "evidence", "outputs"):
        value = candidate.get(field)
        if isinstance(value, dict):
            return dict(value)

    metadata_fields = {
        "scenario_id",
        "scenario",
        "id",
        "status",
        "outcome",
        "verdict",
        "linked_findings",
        "linkedFindings",
        "finding_links",
        "findingLinks",
    }
    return {
        key: value
        for key, value in candidate.items()
        if key not in metadata_fields
    }


def explicit_install_observed_outputs(evidence: Any) -> dict[str, Any]:
    candidate = scenario_evidence_candidate_from(evidence, "published_artifact_install_only")
    if candidate is not None:
        return scenario_observed_outputs(candidate)

    if not isinstance(evidence, dict):
        return {}

    outputs: dict[str, Any] = {}
    for field in ARTIFACT_VERSION_FIELDS:
        versions = evidence.get(field)
        if isinstance(versions, dict):
            outputs["published_artifact_versions"] = versions
            break

    for field in ARTIFACT_SOURCE_FIELDS:
        sources = evidence.get(field)
        if isinstance(sources, dict):
            outputs["artifact_sources"] = sources
            break

    for field in (
        "artifact_install_evidence",
        "artifactInstallEvidence",
        "artifact_source_verification",
        "artifactSourceVerification",
    ):
        install_evidence = evidence.get(field)
        if isinstance(install_evidence, dict):
            outputs["artifact_install_evidence"] = install_evidence
            break

    return outputs


def scenario_linked_findings(candidate: dict[str, Any]) -> list[Any]:
    for field in ("linked_findings", "linkedFindings", "finding_links", "findingLinks"):
        value = candidate.get(field)
        if isinstance(value, list) and value:
            return value
    return []


def imported_scenario_result(scenario: str) -> dict[str, Any] | None:
    candidate = scenario_evidence_candidate(scenario)
    if candidate is None:
        return None

    observed = scenario_observed_outputs(candidate)
    if smoke_descriptor is not None:
        observed.setdefault("external_smoke_evidence", smoke_descriptor)

    if not candidate_matches_current_tuple(candidate, observed):
        return None

    status = scenario_status(candidate)
    if status == "" and has_required_evidence(scenario, observed):
        status = "pass"

    if status == "pass":
        if current_behavior_failures_for(scenario, observed):
            return {
                "scenario_id": scenario,
                "status": "fail",
                "observed_outputs": observed,
            }
        if not has_required_evidence(scenario, observed):
            return None
        return {
            "scenario_id": scenario,
            "status": "pass",
            "observed_outputs": observed,
        }

    if status in ALLOWED_SCENARIO_STATUSES:
        result: dict[str, Any] = {
            "scenario_id": scenario,
            "status": status,
        }
        if observed:
            result["observed_outputs"] = observed
        linked_findings = scenario_linked_findings(candidate)
        if linked_findings:
            result["linked_findings"] = linked_findings
        return result

    return None


SERVER_BASELINE_SCENARIOS = {
    "ordered_signal_delivery",
    "dedup_contract_observation",
    "unknown_signal_and_query_errors",
}

BASELINE_CURRENT_EVIDENCE_SCENARIOS = SERVER_BASELINE_SCENARIOS | {
    "python_worker_cli_and_sdk_baseline",
    "php_worker_cli_and_sdk_baseline",
    "python_worker_php_facing_and_cli_clients",
    "php_worker_python_and_cli_clients",
}

BASELINE_CURRENT_EVIDENCE_FIELDS = {
    "python_worker_cli_and_sdk_baseline": [
        "worker_runtime",
        "python_worker_artifact_source",
        "python_worker_sdk_version",
        "python_worker_query_task_routing",
        "routed_current_query_task",
        "cli_signal_and_query",
        "sdk_python_signal_and_query",
        "immediate_repeat_query_consistency",
    ],
    "php_worker_cli_and_sdk_baseline": [
        "worker_runtime",
        "workflow_php_artifact_source",
        "workflow_php_sdk_version",
        "php_worker_query_task_routing",
        "cli_signal_and_query",
        "workflow_php_signal_and_query",
        "immediate_repeat_query_consistency",
    ],
    "python_worker_php_facing_and_cli_clients": [
        "php_client_signal_and_query",
        "cli_signal_and_query",
        "cross_language_query_consistency",
        "wire_envelope_compatibility",
    ],
    "php_worker_python_and_cli_clients": [
        "sdk_python_signal_and_query",
        "cli_signal_and_query",
        "cross_language_query_consistency",
        "wire_envelope_compatibility",
    ],
    "ordered_signal_delivery": [
        "rapid_increment_inputs",
        "accepted_signal_inputs",
        "accepted_signal_total",
        "queried_total",
        "history_signal_order",
        "final_run_status",
    ],
    "dedup_contract_observation": [
        "client_side_key_support",
        "documented_contract",
        "handler_observation_count",
    ],
    "unknown_signal_and_query_errors": [
        "unknown_signal",
        "missing_workflow_signal",
        "missing_workflow_query",
        "query_not_found",
        "rejected_unknown_query",
        "known_query_after_unknown_errors",
    ],
}

BASELINE_PRODUCT_FAILURE_ROUTES = {
    "python_worker_cli_and_sdk_baseline": {
        "type": "signal_query_python_baseline_failed",
        "title": "Signals/queries Python worker CLI and SDK baseline behavior failed",
    },
    "php_worker_cli_and_sdk_baseline": {
        "type": "signal_query_php_worker_mirror_failed",
        "title": "Signals/queries PHP worker mirror behavior failed",
    },
    "python_worker_php_facing_and_cli_clients": {
        "type": "signal_query_python_worker_php_facing_clients_failed",
        "title": "Signals/queries Python worker PHP-facing client behavior failed",
    },
    "php_worker_python_and_cli_clients": {
        "type": "signal_query_php_worker_python_clients_failed",
        "title": "Signals/queries PHP worker Python and CLI client behavior failed",
    },
    "ordered_signal_delivery": {
        "type": "signal_query_ordered_delivery_failed",
        "title": "Signals/queries ordered delivery behavior failed",
    },
    "dedup_contract_observation": {
        "type": "signal_query_dedup_contract_failed",
        "title": "Signals/queries duplicate signal contract behavior failed",
    },
    "unknown_signal_and_query_errors": {
        "type": "signal_query_unknown_handler_errors_failed",
        "title": "Signals/queries unknown-handler error behavior failed",
    },
}

BASELINE_CURRENT_MISSING_ROUTES = {
    "python_worker_cli_and_sdk_baseline": {
        "type": "signal_query_python_routed_current_query_evidence_missing",
        "title": "Signals/queries Python baseline routed current-query evidence missing",
    },
    "php_worker_cli_and_sdk_baseline": {
        "type": "signal_query_php_worker_mirror_current_evidence_missing",
        "title": "Signals/queries PHP worker mirror current evidence missing",
    },
    "ordered_signal_delivery": {
        "type": "signal_query_ordered_delivery_current_evidence_missing",
        "title": "Signals/queries ordered delivery current evidence missing",
    },
    "dedup_contract_observation": {
        "type": "signal_query_dedup_contract_current_evidence_missing",
        "title": "Signals/queries duplicate signal contract current evidence missing",
    },
    "unknown_signal_and_query_errors": {
        "type": "signal_query_unknown_handler_errors_current_evidence_missing",
        "title": "Signals/queries unknown-handler current evidence missing",
    },
}


def unique_strings(values: list[str]) -> list[str]:
    seen: set[str] = set()
    unique = []
    for value in values:
        if value in seen:
            continue
        seen.add(value)
        unique.append(value)
    return unique


def required_current_evidence_for(scenario: str) -> list[str]:
    return list(BASELINE_CURRENT_EVIDENCE_FIELDS.get(
        scenario,
        SCENARIO_REQUIRED_EVIDENCE.get(scenario, []),
    ))


def ordered_delivery_flat_current_observed() -> dict[str, Any]:
    if not isinstance(smoke_evidence, dict):
        return {}

    observed: dict[str, Any] = {}
    for evidence_key in BASELINE_CURRENT_EVIDENCE_FIELDS["ordered_signal_delivery"]:
        value = flat_smoke_field(evidence_key)
        if value is not MISSING:
            observed[evidence_key] = value

    if "queried_total" not in observed:
        legacy_total = flat_smoke_field("ten_signal_ordered_delivery_total")
        if legacy_total is not MISSING:
            observed["queried_total"] = legacy_total

    if observed and smoke_descriptor is not None:
        observed.setdefault("external_smoke_evidence", smoke_descriptor)

    return observed


def flat_current_observed_for(scenario: str) -> dict[str, Any]:
    if scenario == "ordered_signal_delivery":
        return ordered_delivery_flat_current_observed()

    return {}


def current_candidate_and_observed(scenario: str) -> tuple[dict[str, Any] | None, dict[str, Any]]:
    candidate = scenario_evidence_candidate(scenario)
    if candidate is not None:
        observed = scenario_observed_outputs(candidate)
        if candidate_matches_current_tuple(candidate, observed):
            return candidate, observed

    observed = flat_current_observed_for(scenario)
    if not observed:
        return None, {}

    if evidence_source_policy_violations(smoke_evidence):
        return None, {}

    if not smoke_evidence_matches_current_tuple():
        return None, {}

    return None, observed


def current_evidence_candidate_status(scenario: str) -> str:
    candidate = scenario_evidence_candidate(scenario)
    if candidate is None:
        observed = flat_current_observed_for(scenario)
        if observed:
            if evidence_source_policy_violations(smoke_evidence):
                return "source_policy_violation"
            if not smoke_evidence_matches_current_tuple():
                return "not_current_tuple"
            return "current"
        return "missing"

    observed = scenario_observed_outputs(candidate)
    if evidence_source_policy_violations(candidate, observed):
        return "source_policy_violation"

    if not candidate_matches_current_tuple(candidate, observed):
        return "not_current_tuple"

    return "current"


def ordered_delivery_missing_current_evidence(observed: dict[str, Any]) -> list[str]:
    missing = []
    rapid_inputs = evidence_lookup(observed, "rapid_increment_inputs")
    accepted_inputs = evidence_lookup(observed, "accepted_signal_inputs")
    accepted_signal_total = evidence_lookup(observed, "accepted_signal_total")
    queried_total = evidence_lookup(observed, "queried_total")
    history_signal_order = evidence_lookup(observed, "history_signal_order")
    final_run_status = evidence_lookup(observed, "final_run_status")

    if rapid_inputs is MISSING:
        missing.append("rapid_increment_inputs")
    if accepted_inputs is MISSING:
        missing.append("accepted_signal_inputs")
    if accepted_signal_total is MISSING:
        missing.append("accepted_signal_total")
    if queried_total is MISSING:
        missing.append("queried_total")
    if history_signal_order is MISSING:
        missing.append("history_signal_order")
    if not required_evidence_satisfied("final_run_status", final_run_status):
        missing.append("final_run_status")

    return missing


def unknown_handler_missing_current_evidence(observed: dict[str, Any]) -> list[str]:
    missing = [
        evidence_key
        for evidence_key in SCENARIO_REQUIRED_EVIDENCE["unknown_signal_and_query_errors"]
        if not required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
    ]
    query_reasons = {"query_not_found", "rejected_unknown_query"}
    for evidence_key, minimum, maximum in (
        ("unknown_signal.status_code", 404, 404),
        ("missing_workflow_signal.status_code", 404, 404),
        ("missing_workflow_query.status_code", 404, 404),
        ("query_not_found.status_code", 404, 404),
        ("known_query_after_unknown_errors.status_code", 200, 299),
    ):
        if evidence_lookup(observed, evidence_key) is MISSING:
            missing.append(evidence_key)

    for evidence_key, reasons in (
        ("unknown_signal.reason", {"unknown_signal"}),
        ("missing_workflow_signal.reason", {"instance_not_found"}),
        ("missing_workflow_query.reason", {"instance_not_found"}),
        ("query_not_found.reason", query_reasons),
        ("rejected_unknown_query.reason", query_reasons),
    ):
        if evidence_lookup(observed, evidence_key) is MISSING:
            missing.append(evidence_key)

    return unique_strings(missing)


def missing_current_evidence_for(scenario: str, observed: dict[str, Any]) -> list[str]:
    if not observed:
        return required_current_evidence_for(scenario)

    if scenario == "python_worker_cli_and_sdk_baseline":
        missing = [
            evidence_key
            for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            if not required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
        ]
        if not is_python_worker_runtime(
            output_field(observed, "worker_runtime", "workerRuntime", "python_worker_runtime")
        ):
            missing.append("worker_runtime")
        if not is_published_python_sdk_source(
            output_field(
                observed,
                "python_worker_artifact_source",
                "pythonWorkerArtifactSource",
                "worker_artifact_source",
                "workerArtifactSource",
            )
        ):
            missing.append("python_worker_artifact_source")
        if not python_sdk_version_matches_current(
            output_field(
                observed,
                "python_worker_sdk_version",
                "pythonWorkerSdkVersion",
                "worker_sdk_version",
                "workerSdkVersion",
            )
        ):
            missing.append("python_worker_sdk_version")
        if not routed_current_query_task_satisfied(evidence_lookup(observed, "routed_current_query_task")):
            missing.append("routed_current_query_task")
        return unique_strings(missing)

    if scenario == "php_worker_cli_and_sdk_baseline":
        missing = [
            evidence_key
            for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            if not required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
        ]
        if not is_workflow_php_worker_runtime(
            output_field(observed, "worker_runtime", "workerRuntime", "php_worker_runtime")
        ):
            missing.append("worker_runtime")
        if not is_published_workflow_php_source(
            output_field(
                observed,
                "workflow_php_artifact_source",
                "workflowPhpArtifactSource",
                "worker_artifact_source",
                "workerArtifactSource",
            )
        ):
            missing.append("workflow_php_artifact_source")
        if not workflow_php_version_matches_current(
            output_field(
                observed,
                "workflow_php_sdk_version",
                "workflowPhpSdkVersion",
                "worker_sdk_version",
                "workerSdkVersion",
            )
        ):
            missing.append("workflow_php_sdk_version")
        return unique_strings(missing)

    if scenario == "ordered_signal_delivery":
        return ordered_delivery_missing_current_evidence(observed)

    if scenario == "unknown_signal_and_query_errors":
        return unknown_handler_missing_current_evidence(observed)

    return [
        evidence_key
        for evidence_key in SCENARIO_REQUIRED_EVIDENCE.get(scenario, [])
        if not required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
    ]


def python_worker_claim_observed_for_focused_route() -> dict[str, Any]:
    _, observed = current_candidate_and_observed("python_worker_cli_and_sdk_baseline")
    if observed:
        return observed

    if evidence_source_policy_violations(smoke_evidence):
        return {}

    if not smoke_evidence_matches_current_tuple():
        return {}

    observed = {}
    for evidence_key in (
        "worker_runtime",
        "python_worker_artifact_source",
        "python_worker_sdk_version",
    ):
        value = flat_smoke_field(evidence_key)
        if value is not MISSING:
            observed[evidence_key] = value

    return observed


def python_routed_current_missing_route_allowed(missing_current_evidence: list[str]) -> bool:
    return (
        "routed_current_query_task" in missing_current_evidence
        and python_worker_claim_satisfied(python_worker_claim_observed_for_focused_route())
    )


def current_evidence_gaps(scenario: str) -> list[str]:
    if scenario not in BASELINE_CURRENT_EVIDENCE_SCENARIOS:
        return []

    _, observed = current_candidate_and_observed(scenario)

    return missing_current_evidence_for(scenario, observed)


def behavior_failure(code: str, evidence_key: str, expected: Any, actual: Any) -> dict[str, Any]:
    return {
        "code": code,
        "evidence_key": evidence_key,
        "expected": expected,
        "actual": actual,
    }


def sample_readout(sample: Any) -> dict[str, Any] | None:
    if not isinstance(sample, dict):
        return None

    readout = {
        "ok": public_sample_ok(sample),
        "result": sample_result_value(sample),
        "status_code": sample.get("status_code"),
        "reason": sample.get("reason"),
        "exception": sample.get("exception"),
    }
    if isinstance(sample.get("raw_stdout"), str):
        readout["raw_stdout"] = sample["raw_stdout"]

    return readout


def python_baseline_behavior_failures(observed: dict[str, Any]) -> list[dict[str, Any]]:
    failures: list[dict[str, Any]] = []
    sdk_signal_and_query = evidence_lookup(observed, "sdk_python_signal_and_query")
    if sdk_signal_and_query is not MISSING and not evidence_true(sdk_signal_and_query):
        failures.append(behavior_failure(
            "python_sdk_signal_query_mismatch",
            "sdk_python_signal_and_query",
            "Python SDK signal increment(5) succeeds and Python SDK query current returns 8",
            {
                "sdk_signal": sample_readout(observed.get("sdk_python_signal_sample")),
                "sdk_query": sample_readout(observed.get("sdk_python_query_sample")),
                "error": observed.get("sdk_python_signal_and_query_error"),
            },
        ))

    repeat_consistency = evidence_lookup(observed, "immediate_repeat_query_consistency")
    if repeat_consistency is not MISSING and not evidence_true(repeat_consistency):
        failures.append(behavior_failure(
            "immediate_repeat_query_mismatch",
            "immediate_repeat_query_consistency",
            "immediate repeat query result equals the preceding Python SDK query result",
            {
                "sdk_query": sample_readout(observed.get("sdk_python_query_sample")),
                "repeat_query": sample_readout(observed.get("repeat_query_sample")),
                "error": observed.get("immediate_repeat_query_consistency_error"),
            },
        ))

    return failures


def php_baseline_behavior_failures(observed: dict[str, Any]) -> list[dict[str, Any]]:
    failures: list[dict[str, Any]] = []
    workflow_php_sdk_version = output_field(
        observed,
        "workflow_php_sdk_version",
        "workflowPhpSdkVersion",
        "worker_sdk_version",
        "workerSdkVersion",
    )
    actual_workflow_php_version = (
        str(workflow_php_sdk_version).strip()
        if workflow_php_sdk_version is not MISSING
        else ""
    )
    expected_workflow_php_version = artifact_version_value(artifact_versions, "workflow-php")
    if (
        actual_workflow_php_version != ""
        and not is_placeholder_version(actual_workflow_php_version)
        and expected_workflow_php_version != ""
        and not is_placeholder_version(expected_workflow_php_version)
        and actual_workflow_php_version != expected_workflow_php_version
    ):
        failures.append(behavior_failure(
            "workflow_php_sdk_version_mismatch",
            "workflow_php_sdk_version",
            expected_workflow_php_version,
            actual_workflow_php_version,
        ))

    cli_signal_and_query = evidence_lookup(observed, "cli_signal_and_query")
    if cli_signal_and_query is not MISSING and not evidence_true(cli_signal_and_query):
        failures.append(behavior_failure(
            "php_worker_cli_signal_query_mismatch",
            "cli_signal_and_query",
            "CLI signal increment(3) succeeds and CLI query current returns 3 against the PHP worker",
            {
                "cli_signal": sample_readout(observed.get("cli_signal_sample")),
                "cli_query": sample_readout(observed.get("cli_query_sample")),
                "error": observed.get("cli_signal_and_query_error"),
            },
        ))

    workflow_php_signal_and_query = evidence_lookup(observed, "workflow_php_signal_and_query")
    if workflow_php_signal_and_query is not MISSING and not evidence_true(workflow_php_signal_and_query):
        failures.append(behavior_failure(
            "php_sdk_signal_query_mismatch",
            "workflow_php_signal_and_query",
            "PHP SDK signal increment(5) succeeds and PHP SDK query current returns 8 against the PHP worker",
            {
                "workflow_php_signal": sample_readout(observed.get("workflow_php_signal_sample")),
                "workflow_php_query": sample_readout(observed.get("workflow_php_query_sample")),
                "error": observed.get("workflow_php_signal_and_query_error"),
            },
        ))

    repeat_consistency = evidence_lookup(observed, "immediate_repeat_query_consistency")
    if repeat_consistency is not MISSING and not evidence_true(repeat_consistency):
        failures.append(behavior_failure(
            "php_immediate_repeat_query_mismatch",
            "immediate_repeat_query_consistency",
            "immediate repeat query result equals the preceding PHP SDK query result",
            {
                "workflow_php_query": sample_readout(observed.get("workflow_php_query_sample")),
                "repeat_query": sample_readout(observed.get("repeat_query_sample")),
                "error": observed.get("immediate_repeat_query_consistency_error"),
            },
        ))

    probe_error = observed.get("probe_error")
    if isinstance(probe_error, dict) and probe_error:
        failures.append(behavior_failure(
            "php_worker_mirror_probe_failed",
            "probe_error",
            "published PHP worker completes start, signal, query, and routed current-query evidence",
            {
                "probe_error": probe_error,
                "worker_registration": observed.get("worker_registration"),
                "workflow_php_start": sample_readout(observed.get("workflow_php_start_sample")),
                "initial_query": sample_readout(observed.get("initial_query_sample")),
                "cli_signal": sample_readout(observed.get("cli_signal_sample")),
                "cli_query": sample_readout(observed.get("cli_query_sample")),
                "workflow_php_signal": sample_readout(observed.get("workflow_php_signal_sample")),
                "workflow_php_query": sample_readout(observed.get("workflow_php_query_sample")),
                "repeat_query": sample_readout(observed.get("repeat_query_sample")),
            },
        ))

    return failures


def cross_language_client_behavior_failures(scenario: str, observed: dict[str, Any]) -> list[dict[str, Any]]:
    failures: list[dict[str, Any]] = []
    if scenario == "python_worker_php_facing_and_cli_clients":
        client_field = "php_client_signal_and_query"
        client_expected = (
            "PHP-facing client starts a Python-authored Counter workflow, sends increment(4), "
            "and queries current=4"
        )
        client_actual = {
            "start": sample_readout(observed.get("workflow_php_start_sample")),
            "initial_query": sample_readout(observed.get("workflow_php_initial_query_sample")),
            "signal": sample_readout(observed.get("workflow_php_signal_sample")),
            "query": sample_readout(observed.get("workflow_php_query_sample")),
            "repeat_query": sample_readout(observed.get("workflow_php_repeat_query_sample")),
            "observed_values": observed.get("observed_values"),
            "error": observed.get("probe_error"),
        }
        cli_expected = "CLI signal increment(6) succeeds and CLI query current returns 10 against the Python worker"
        cli_actual = {
            "cli_signal": sample_readout(observed.get("cli_signal_sample")),
            "cli_query": sample_readout(observed.get("cli_query_sample")),
            "observed_values": observed.get("observed_values"),
            "error": observed.get("probe_error"),
        }
    else:
        client_field = "sdk_python_signal_and_query"
        client_expected = (
            "Python SDK starts a PHP-authored Counter workflow, sends increment(4), "
            "and queries current=4"
        )
        client_actual = {
            "start": sample_readout(observed.get("sdk_python_start_sample")),
            "initial_query": sample_readout(observed.get("cli_initial_query_sample")),
            "signal": sample_readout(observed.get("sdk_python_signal_sample")),
            "query": sample_readout(observed.get("sdk_python_query_sample")),
            "repeat_query": sample_readout(observed.get("sdk_python_repeat_query_sample")),
            "observed_values": observed.get("observed_values"),
            "error": observed.get("probe_error"),
        }
        cli_expected = "CLI signal increment(6) succeeds and CLI query current returns 10 against the PHP worker"
        cli_actual = {
            "cli_signal": sample_readout(observed.get("cli_signal_sample")),
            "cli_query": sample_readout(observed.get("cli_query_sample")),
            "observed_values": observed.get("observed_values"),
            "error": observed.get("probe_error"),
        }

    client_signal_and_query = evidence_lookup(observed, client_field)
    if client_signal_and_query is not MISSING and not evidence_true(client_signal_and_query):
        failures.append(behavior_failure(
            "cross_language_public_client_signal_query_mismatch",
            client_field,
            client_expected,
            client_actual,
        ))

    cli_signal_and_query = evidence_lookup(observed, "cli_signal_and_query")
    if cli_signal_and_query is not MISSING and not evidence_true(cli_signal_and_query):
        failures.append(behavior_failure(
            "cross_language_cli_signal_query_mismatch",
            "cli_signal_and_query",
            cli_expected,
            cli_actual,
        ))

    query_consistency = evidence_lookup(observed, "cross_language_query_consistency")
    if query_consistency is not MISSING and not evidence_true(query_consistency):
        failures.append(behavior_failure(
            "cross_language_query_consistency_mismatch",
            "cross_language_query_consistency",
            "public clients return the same final current query value after all signals",
            {
                "observed_values": observed.get("observed_values"),
                "error": observed.get("probe_error"),
            },
        ))

    wire_compatibility = evidence_lookup(observed, "wire_envelope_compatibility")
    if wire_compatibility is not MISSING and not evidence_true(wire_compatibility):
        failures.append(behavior_failure(
            "cross_language_wire_envelope_incompatible",
            "wire_envelope_compatibility",
            "public clients exchange language-neutral payload envelopes with the worker runtime",
            {
                "commands_and_api_samples": observed.get("commands_and_api_samples"),
                "error": observed.get("probe_error"),
            },
        ))

    probe_error = observed.get("probe_error")
    if isinstance(probe_error, dict) and probe_error:
        failures.append(behavior_failure(
            "cross_language_client_matrix_probe_failed",
            "probe_error",
            "focused cross-language public client matrix completes against published artifacts",
            {
                "probe_error": probe_error,
                "commands_and_api_samples": observed.get("commands_and_api_samples"),
                "observed_values": observed.get("observed_values"),
            },
        ))

    return failures


def ordered_delivery_behavior_failures(observed: dict[str, Any]) -> list[dict[str, Any]]:
    failures: list[dict[str, Any]] = []
    expected_inputs = expected_rapid_signal_inputs()
    rapid_inputs = evidence_lookup(observed, "rapid_increment_inputs")
    accepted_inputs = evidence_lookup(observed, "accepted_signal_inputs")
    accepted_signal_total = evidence_lookup(observed, "accepted_signal_total")
    queried_total = evidence_lookup(observed, "queried_total")
    history_signal_order = evidence_lookup(observed, "history_signal_order")
    rapid_sequence = integer_sequence(rapid_inputs)
    accepted_sequence = integer_sequence(accepted_inputs)
    reference_sequence = ordered_delivery_reference_inputs(observed)

    if rapid_inputs is not MISSING and rapid_sequence != expected_inputs:
        failures.append(behavior_failure(
            "unexpected_ordered_signal_inputs",
            "rapid_increment_inputs",
            expected_inputs,
            rapid_inputs,
        ))
    if (
        accepted_inputs is not MISSING
        and rapid_sequence is not None
        and accepted_sequence != rapid_sequence
    ):
        failures.append(behavior_failure(
            "unexpected_ordered_signal_acceptance",
            "accepted_signal_inputs",
            rapid_sequence,
            accepted_inputs,
        ))
    if (
        accepted_signal_total is not MISSING
        and reference_sequence is not None
        and integer_value(accepted_signal_total) != sum(reference_sequence)
    ):
        failures.append(behavior_failure(
            "unexpected_ordered_signal_accepted_total",
            "accepted_signal_total",
            sum(reference_sequence),
            accepted_signal_total,
        ))
    if (
        queried_total is not MISSING
        and reference_sequence is not None
        and integer_value(queried_total) != sum(reference_sequence)
    ):
        failures.append(behavior_failure(
            "unexpected_ordered_signal_total",
            "queried_total",
            sum(reference_sequence),
            queried_total,
        ))
    if (
        history_signal_order is not MISSING
        and reference_sequence is not None
        and integer_sequence(history_signal_order) != reference_sequence
    ):
        failures.append(behavior_failure(
            "unexpected_ordered_signal_history_order",
            "history_signal_order",
            reference_sequence,
            history_signal_order,
        ))

    return failures


def dedup_contract_behavior_failures(observed: dict[str, Any]) -> list[dict[str, Any]]:
    failures: list[dict[str, Any]] = []
    handler_observation_count = evidence_lookup(observed, "handler_observation_count")
    count = integer_value(handler_observation_count)
    if handler_observation_count is not MISSING and (count is None or count < 1):
        failures.append(behavior_failure(
            "duplicate_signal_not_observed",
            "handler_observation_count",
            "at least one delivered duplicate/repeated signal observation",
            handler_observation_count,
        ))

    return failures


def unknown_handler_behavior_failures(observed: dict[str, Any]) -> list[dict[str, Any]]:
    failures: list[dict[str, Any]] = []
    query_reasons = {"query_not_found", "rejected_unknown_query"}
    for evidence_key, minimum, maximum in (
        ("unknown_signal.status_code", 404, 404),
        ("missing_workflow_signal.status_code", 404, 404),
        ("missing_workflow_query.status_code", 404, 404),
        ("query_not_found.status_code", 404, 404),
        ("known_query_after_unknown_errors.status_code", 200, 299),
    ):
        actual = evidence_lookup(observed, evidence_key)
        if actual is not MISSING and not status_code_in_range(observed, evidence_key, minimum, maximum):
            failures.append(behavior_failure(
                "unexpected_unknown_handler_status_code",
                evidence_key,
                f"{minimum}..{maximum}",
                actual,
            ))

    for evidence_key, reasons in (
        ("unknown_signal.reason", {"unknown_signal"}),
        ("missing_workflow_signal.reason", {"instance_not_found"}),
        ("missing_workflow_query.reason", {"instance_not_found"}),
        ("query_not_found.reason", query_reasons),
        ("rejected_unknown_query.reason", query_reasons),
    ):
        actual = evidence_lookup(observed, evidence_key)
        if actual is not MISSING and not reason_in(observed, evidence_key, reasons):
            failures.append(behavior_failure(
                "unexpected_unknown_handler_reason",
                evidence_key,
                sorted(reasons),
                actual,
            ))

    expected_known_result = evidence_lookup(observed, "known_query_after_unknown_expected")
    actual_known_result = evidence_lookup(observed, "known_query_after_unknown_result")
    if (
        expected_known_result is not MISSING
        and actual_known_result is not MISSING
        and actual_known_result != expected_known_result
    ):
        failures.append(behavior_failure(
            "unexpected_known_query_after_unknown_result",
            "known_query_after_unknown_result",
            expected_known_result,
            actual_known_result,
        ))

    return failures


def current_behavior_failures_for(scenario: str, observed: dict[str, Any]) -> list[dict[str, Any]]:
    if scenario == "python_worker_cli_and_sdk_baseline":
        return python_baseline_behavior_failures(observed)

    if scenario == "php_worker_cli_and_sdk_baseline":
        return php_baseline_behavior_failures(observed)

    if scenario in {
        "python_worker_php_facing_and_cli_clients",
        "php_worker_python_and_cli_clients",
    }:
        return cross_language_client_behavior_failures(scenario, observed)

    if scenario == "ordered_signal_delivery":
        return ordered_delivery_behavior_failures(observed)

    if scenario == "dedup_contract_observation":
        return dedup_contract_behavior_failures(observed)

    if scenario == "unknown_signal_and_query_errors":
        return unknown_handler_behavior_failures(observed)

    return []


def current_behavior_failures(scenario: str) -> list[dict[str, Any]]:
    if scenario not in BASELINE_CURRENT_EVIDENCE_SCENARIOS:
        return []

    _, observed = current_candidate_and_observed(scenario)
    if not observed:
        return []

    return current_behavior_failures_for(scenario, observed)


def runner_blocker_from_descriptor(descriptor: Any) -> dict[str, Any] | None:
    if not isinstance(descriptor, dict):
        return None

    blocker = descriptor.get("runner_blocker")
    if not isinstance(blocker, dict):
        return None

    if blocker.get("kind") != "server_readiness_topology":
        return None

    return blocker


result_dir = Path(os.environ["RESULT_DIR"])
started_at = os.environ["STARTED_AT"]
finished_at = now()
artifact_versions = {
    "server": os.environ["DW_SERVER_VERSION"],
    "cli": os.environ["DW_CLI_VERSION"],
    "sdk-python": os.environ["DW_PYTHON_SDK_VERSION"],
    "workflow": os.environ["DW_WORKFLOW_PHP_VERSION"],
    "workflow-php": os.environ["DW_WORKFLOW_PHP_VERSION"],
    "waterline": os.environ["DW_WATERLINE_VERSION"],
}

smoke_path = os.environ.get("DW_SIGNALS_QUERIES_EVIDENCE", "") or os.environ.get(
    "DW_SIGNALS_QUERIES_SMOKE_EVIDENCE",
    "",
)
smoke_evidence: Any = None
external_smoke_evidence: Any = None
smoke_descriptor: dict[str, Any] | None = None
if smoke_path:
    candidate = Path(smoke_path)
    if candidate.is_file():
        raw = candidate.read_bytes()
        smoke_descriptor = {
            "file": candidate.name,
            "sha256": hashlib.sha256(raw).hexdigest(),
        }
        try:
            smoke_evidence = json.loads(raw.decode("utf-8"))
            external_smoke_evidence = smoke_evidence
        except Exception as exc:
            smoke_descriptor["decode_error"] = f"{type(exc).__name__}: {exc}"

baseline_evidence, baseline_descriptor = run_baseline_probe(result_dir)
if baseline_evidence is not None:
    smoke_evidence = merge_probe_evidence(smoke_evidence, baseline_evidence)
    if smoke_descriptor is None:
        smoke_descriptor = {}
    smoke_descriptor["baseline_probe"] = baseline_descriptor
elif baseline_descriptor is not None:
    if smoke_descriptor is None:
        smoke_descriptor = {}
    smoke_descriptor["baseline_probe"] = baseline_descriptor

probe_evidence, probe_descriptor = run_adversarial_probe(result_dir, smoke_evidence)
if probe_evidence is not None:
    smoke_evidence = merge_probe_evidence(smoke_evidence, probe_evidence)
    if smoke_descriptor is None:
        smoke_descriptor = {}
    smoke_descriptor["adversarial_probe"] = probe_descriptor
elif probe_descriptor is not None:
    if smoke_descriptor is None:
        smoke_descriptor = {}
    smoke_descriptor["adversarial_probe"] = probe_descriptor

try:
    waterline_evidence, waterline_descriptor = run_waterline_observer_probe(result_dir, smoke_evidence)
except Exception as exc:  # noqa: BLE001 - the Waterline shard must not erase sibling evidence.
    waterline_evidence = waterline_observer_setup_result(
        status="fail",
        reason=f"Waterline observer shard failed before producing evidence: {type(exc).__name__}: {exc}",
        blocker_kind="waterline_observer_probe_exception",
    )
    waterline_descriptor = {
        "error": f"{type(exc).__name__}: {exc}",
        "generated_scenarios": ["waterline_operator_visibility"],
    }
if waterline_evidence is not None:
    smoke_evidence = merge_probe_evidence(smoke_evidence, waterline_evidence)
    if smoke_descriptor is None:
        smoke_descriptor = {}
    smoke_descriptor["waterline_observer_probe"] = waterline_descriptor
elif waterline_descriptor is not None:
    if smoke_descriptor is None:
        smoke_descriptor = {}
    smoke_descriptor["waterline_observer_probe"] = waterline_descriptor

baseline_readiness_blocker = runner_blocker_from_descriptor(baseline_descriptor)

required_scenarios = [
    "published_artifact_install_only",
    "python_worker_cli_and_sdk_baseline",
    "php_worker_cli_and_sdk_baseline",
    "python_worker_php_facing_and_cli_clients",
    "php_worker_python_and_cli_clients",
    "ordered_signal_delivery",
    "dedup_contract_observation",
    "signal_during_replay",
    "query_during_replay",
    "completed_run_signal_and_query",
    "unknown_signal_and_query_errors",
    "malformed_signal_and_query_payloads",
    "waterline_operator_visibility",
]

scenario_routes = {
    "published_artifact_install_only": {
        "type": "signal_query_published_artifact_install_uncovered",
        "owner": "conformance_harness",
        "title": "Signals/queries published-artifact install evidence remains unproved",
        "acceptance": [
            "resolve concrete server, CLI, Python SDK, PHP workflow, and Waterline versions",
            "prove every actor starts from a published package, image, or release asset",
        ],
    },
    "python_worker_cli_and_sdk_baseline": {
        "type": "signal_query_python_smoke_uncovered",
        "owner": "sdk-python, cli, server",
        "title": "Signals/queries Python worker CLI and SDK baseline remains unproved",
        "acceptance": [
            "start Counter on the Python worker",
            "verify CLI and Python SDK signals update query-visible state",
            "record a routed current query task from the public CLI through the server to the Python SDK worker",
            "record immediate repeat-query consistency",
        ],
    },
    "ordered_signal_delivery": {
        "type": "signal_query_ordered_delivery_uncovered",
        "owner": "server",
        "title": "Signals/queries ordered delivery evidence remains unproved",
        "acceptance": [
            "send increment(1) through increment(10) rapidly",
            "record the accepted signal sequence",
            "record the accepted signal total",
            "query total matching the accepted signal sequence",
            "record history signal order matching the accepted signal sequence",
        ],
    },
    "dedup_contract_observation": {
        "type": "signal_query_dedup_contract_uncovered",
        "owner": "server, sdk-python, workflow, cli, docs",
        "title": "Signals/queries dedup contract remains unproved",
        "acceptance": [
            "send duplicate signals with the documented idempotency or dedup key when supported",
            "record whether the handler observes one transition or two",
            "link any docs/runtime mismatch to the owning surface",
        ],
    },
    "php_worker_cli_and_sdk_baseline": {
        "type": "signal_query_php_worker_mirror_uncovered",
        "owner": "workflow",
        "title": "Signals/queries PHP worker mirror remains unproved",
        "acceptance": [
            "start Counter on the PHP worker",
            "verify CLI and PHP SDK signals update query-visible state",
            "record PHP handler and query evidence using published artifacts",
        ],
    },
    "python_worker_php_facing_and_cli_clients": {
        "type": "signal_query_cross_language_client_matrix_uncovered",
        "owner": "workflow, cli, server",
        "title": "Signals/queries Python worker with PHP-facing clients remains unproved",
        "acceptance": [
            "start Counter on the Python worker from a PHP-facing client",
            "send signals from PHP and CLI clients",
            "prove query results agree across clients",
        ],
    },
    "php_worker_python_and_cli_clients": {
        "type": "signal_query_cross_language_client_matrix_uncovered",
        "owner": "workflow, sdk-python, cli, server",
        "title": "Signals/queries PHP worker with Python and CLI clients remains unproved",
        "acceptance": [
            "start Counter on the PHP worker from the Python SDK",
            "send signals from Python and CLI clients",
            "prove query results agree across clients",
        ],
    },
    "signal_during_replay": {
        "type": "signal_query_replay_timing_uncovered",
        "owner": "workflow, sdk-python, server",
        "title": "Signals during replay timing remains unproved",
        "acceptance": [
            "restart a worker with non-empty history",
            "send a signal while replay is in progress",
            "prove the signal applies after replay reaches a consistent point",
        ],
    },
    "query_during_replay": {
        "type": "signal_query_replay_timing_uncovered",
        "owner": "workflow, sdk-python, server",
        "title": "Query during replay consistency remains unproved",
        "acceptance": [
            "restart a worker with non-empty history",
            "query while replay is in progress",
            "prove the answer matches the expected replay-consistent state",
        ],
    },
    "completed_run_signal_and_query": {
        "type": "signal_query_completed_run_handling_uncovered",
        "owner": "server, workflow, sdk-python, cli",
        "title": "Signals/queries completed-run handling remains unproved",
        "acceptance": [
            "complete Counter cleanly with a replayable query handler",
            "prove signal-to-completed-run returns a typed terminal outcome",
            "prove every claimed query surface returns final state or a documented handler-unavailable error",
        ],
    },
    "unknown_signal_and_query_errors": {
        "type": "signal_query_unknown_handler_errors_uncovered",
        "owner": "server, workflow, sdk-python, cli",
        "title": "Signals/queries unknown-handler errors remain unproved",
        "acceptance": [
            "send an unknown signal and unknown query",
            "capture stable typed error envelopes",
            "prove known queries still work after the errors",
        ],
    },
    "malformed_signal_and_query_payloads": {
        "type": "signal_query_adversarial_error_shapes_uncovered",
        "owner": "server, workflow, sdk-python, cli",
        "title": "Signals/queries malformed-payload errors remain unproved",
        "acceptance": [
            "send malformed signal and query payloads",
            "capture stable validation or decoding errors with argument context",
            "prove malformed attempts do not mutate workflow state",
            "record public CLI and Python SDK error samples for malformed signal and query calls",
        ],
    },
    "waterline_operator_visibility": {
        "type": "signal_query_waterline_observer_comparison_uncovered",
        "owner": "waterline",
        "title": "Signals/queries Waterline observer comparison remains unproved",
        "acceptance": [
            "compare Waterline selected-run detail against server, CLI, and SDK observations",
            "show applied, rejected, and terminal-run signal/query outcomes",
            "record any unsupported Waterline query-result materialization as an explicit finding",
        ],
    },
}

smoke_attached = smoke_evidence is not None
smoke_tuple_matches = smoke_evidence_matches_current_tuple()
smoke_tuple_mismatches = smoke_artifact_version_mismatches()
smoke_source_policy_violations = evidence_source_policy_violations(smoke_evidence)
smoke_source_policy_ok = smoke_source_policy_violations == []
external_smoke_attached = external_smoke_evidence is not None
external_smoke_tuple_matches = evidence_matches_current_tuple(external_smoke_evidence)
external_smoke_source_policy_violations = evidence_source_policy_violations(external_smoke_evidence)
external_smoke_source_policy_ok = external_smoke_source_policy_violations == []
install_evidence_outputs = explicit_install_observed_outputs(external_smoke_evidence)
if smoke_descriptor is not None and smoke_tuple_mismatches:
    smoke_descriptor["artifact_version_mismatches"] = smoke_tuple_mismatches
if smoke_descriptor is not None and smoke_source_policy_violations:
    smoke_descriptor["artifact_source_policy_violations"] = smoke_source_policy_violations
if smoke_descriptor is not None and external_smoke_source_policy_violations:
    smoke_descriptor["external_artifact_source_policy_violations"] = external_smoke_source_policy_violations
install_evidence_pass = (
    external_smoke_attached
    and external_smoke_tuple_matches
    and external_smoke_source_policy_ok
    and has_required_evidence("published_artifact_install_only", install_evidence_outputs)
)
python_smoke_pass = smoke_attached and smoke_tuple_matches and smoke_source_policy_ok and exact_python_smoke_present()
ordered_delivery_pass = smoke_attached and smoke_tuple_matches and smoke_source_policy_ok and exact_ordered_delivery_smoke_present()
scenario_results: dict[str, dict[str, Any]] = {}
findings: list[dict[str, Any]] = []
finding_links: dict[str, list[str]] = {}

for scenario in required_scenarios:
    observed: dict[str, Any] = {}
    status = "not_covered"
    imported_result = imported_scenario_result(scenario)

    if imported_result is not None:
        result = imported_result
        status = str(result["status"])
    elif install_evidence_pass and scenario == "published_artifact_install_only":
        status = "pass"
        observed = dict(install_evidence_outputs)
        observed.setdefault("published_artifact_versions", artifact_versions)
        observed.setdefault(
            "artifact_sources",
            dict(EXPECTED_ARTIFACT_SOURCES),
        )
        observed["external_smoke_evidence"] = smoke_descriptor
        result = {
            "scenario_id": scenario,
            "status": status,
            "observed_outputs": observed,
        }
    elif python_smoke_pass and scenario == "python_worker_cli_and_sdk_baseline":
        status = "pass"
        observed = {
            "worker_runtime": smoke_field("worker_runtime", scenario),
            "python_worker_artifact_source": smoke_field("python_worker_artifact_source", scenario),
            "python_worker_sdk_version": smoke_field("python_worker_sdk_version", scenario),
            "python_worker_query_task_routing": smoke_field(
                "python_worker_query_task_routing",
                scenario,
            ),
            "routed_current_query_task": smoke_field(
                "routed_current_query_task",
                scenario,
            ),
            "cli_signal_and_query": smoke_field("cli_signal_and_query", scenario),
            "sdk_python_signal_and_query": smoke_field("sdk_python_signal_and_query", scenario),
            "immediate_repeat_query_consistency": smoke_field(
                "immediate_repeat_query_consistency",
                scenario,
            ),
            "external_smoke_evidence": smoke_descriptor,
        }
        result = {
            "scenario_id": scenario,
            "status": status,
            "observed_outputs": observed,
        }
    elif ordered_delivery_pass and scenario == "ordered_signal_delivery":
        status = "pass"
        observed = {
            "rapid_increment_inputs": smoke_field("rapid_increment_inputs", scenario),
            "accepted_signal_inputs": smoke_field("accepted_signal_inputs", scenario),
            "accepted_signal_total": smoke_field("accepted_signal_total", scenario),
            "queried_total": smoke_field("queried_total", scenario),
            "history_signal_order": smoke_field("history_signal_order", scenario),
            "final_run_status": smoke_field("final_run_status", scenario),
            "external_smoke_evidence": smoke_descriptor,
        }
        result = {
            "scenario_id": scenario,
            "status": status,
            "observed_outputs": observed,
        }
    else:
        result = {
            "scenario_id": scenario,
            "status": status,
        }

    if status != "pass":
        linked_findings = result.get("linked_findings")
        if isinstance(linked_findings, list) and linked_findings:
            finding_links[scenario] = linked_findings
            findings.extend([item for item in linked_findings if isinstance(item, dict)])
        else:
            route = scenario_routes[scenario]
            behavior_failures: list[dict[str, Any]] = []
            missing_current_evidence: list[str] = []
            candidate_status = current_evidence_candidate_status(scenario)

            if baseline_readiness_blocker is not None and scenario in SERVER_BASELINE_SCENARIOS:
                status = "runner_blocked"
                result["status"] = status
                result["observed_outputs"] = {
                    "server_readiness_topology": baseline_readiness_blocker,
                }
                route = {
                    **route,
                    "type": f"signal_query_{scenario}_server_readiness_topology",
                    "owner": "conformance_harness",
                    "title": "Signals/queries published server readiness topology blocked baseline evidence",
                    "acceptance": [
                        "make the published server /api/ready endpoint reachable from the host runner",
                        "record the effective host endpoint and compose port/container diagnostics",
                        "rerun the baseline signals/queries scenarios after readiness is reachable",
                    ],
                }
                finding_id = route["type"]
            else:
                behavior_failures = current_behavior_failures(scenario)

            if behavior_failures:
                failure_route = BASELINE_PRODUCT_FAILURE_ROUTES.get(scenario)
                if failure_route is not None:
                    route = {
                        **route,
                        "type": failure_route["type"],
                        "title": failure_route["title"],
                    }
                status = "fail"
                result["status"] = status

            finding_id = route["type"]
            if status != "runner_blocked":
                missing_current_evidence = current_evidence_gaps(scenario)
                if scenario == "ordered_signal_delivery":
                    _, current_observed = current_candidate_and_observed(scenario)
                    if current_observed:
                        result.setdefault("observed_outputs", current_observed)
            if missing_current_evidence and not behavior_failures:
                current_missing_route = BASELINE_CURRENT_MISSING_ROUTES.get(scenario)
                if (
                    scenario == "python_worker_cli_and_sdk_baseline"
                    and not python_routed_current_missing_route_allowed(missing_current_evidence)
                ):
                    current_missing_route = None
                if current_missing_route is not None:
                    if scenario == "python_worker_cli_and_sdk_baseline":
                        missing_current_evidence = ["routed_current_query_task"]
                    route = {
                        **route,
                        "type": current_missing_route["type"],
                        "title": current_missing_route["title"],
                    }
                    finding_id = route["type"]
            finding = {
                "id": finding_id,
                "type": route["type"],
                "scenario_id": scenario,
                "owner": route["owner"],
                "title": route["title"],
                "current_evidence": {
                    "published_artifact_evidence_present": smoke_attached,
                    "evidence": smoke_descriptor,
                },
                "acceptance": route["acceptance"],
            }
            if behavior_failures:
                finding["current_evidence"]["current_evidence_candidate_status"] = candidate_status
                finding["current_evidence"]["current_behavior_failures"] = behavior_failures
                if scenario == "ordered_signal_delivery":
                    _, ordered_observed = current_candidate_and_observed(scenario)
                    ordered_readout = {
                        key: ordered_observed[key]
                        for key in BASELINE_CURRENT_EVIDENCE_FIELDS["ordered_signal_delivery"]
                        if key in ordered_observed
                    }
                    if ordered_readout:
                        finding["current_evidence"]["ordered_delivery_observed_outputs"] = ordered_readout
                finding["observed_behavior"] = "current published artifacts produced behavior outside the signals/queries contract"
            if status == "runner_blocked":
                finding["blocker_kind"] = "server_readiness_topology"
                finding["runner_blocker"] = baseline_readiness_blocker
                finding["current_evidence"]["server_readiness_topology"] = baseline_readiness_blocker
                finding["observed_behavior"] = (
                    "published server endpoint was not reachable from the host before baseline scenario generation"
                )
            if missing_current_evidence and not behavior_failures:
                finding["title"] = (
                    f"{route['title']}: missing current evidence "
                    f"{', '.join(missing_current_evidence)}"
                )
                finding["current_evidence"]["current_evidence_candidate_present"] = candidate_status == "current"
                finding["current_evidence"]["current_evidence_candidate_status"] = candidate_status
                finding["current_evidence"]["missing_current_evidence"] = missing_current_evidence
            result["linked_findings"] = [finding_id]
            findings.append(finding)
            finding_links[scenario] = [finding_id]

    scenario_results[scenario] = result

pins = {
    "artifact_versions": artifact_versions,
    "artifact_sources": dict(EXPECTED_ARTIFACT_SOURCES),
}
write_json(result_dir / "pins.json", pins)

runner_blocked = any(item["status"] == "runner_blocked" for item in scenario_results.values())

run_metadata = {
    "schema": "durable-workflow.v2.signal-query-runtime.run-metadata",
    "started_at": started_at,
    "finished_at": finished_at,
    "runner": "scripts/conformance/signals-queries-published-artifacts.sh",
    "local_product_source_checkouts_used": False,
    "smoke_evidence": smoke_descriptor,
}
if runner_blocked and baseline_readiness_blocker is not None:
    run_metadata["runner_blocker"] = baseline_readiness_blocker
write_json(result_dir / "run-metadata.json", run_metadata)
write_json(result_dir / "signals-queries-findings.json", findings)

def section_for(*scenario_ids: str) -> dict[str, dict[str, Any]]:
    return {
        scenario_id: {
            "status": scenario_results[scenario_id]["status"],
            "linked_findings": scenario_results[scenario_id].get("linked_findings", []),
            "observed_outputs": scenario_results[scenario_id].get("observed_outputs", {}),
        }
        for scenario_id in scenario_ids
    }


def retainable_php_worker_mirror_failures(behavior_failures: Any) -> list[dict[str, Any]]:
    if not isinstance(behavior_failures, list):
        return []

    retained_failures: list[dict[str, Any]] = []
    for failure in behavior_failures:
        if not isinstance(failure, dict):
            continue
        if failure.get("code") != "php_worker_mirror_probe_failed":
            continue
        if failure.get("actual") is None:
            continue

        retained_failures.append(
            {
                "code": failure.get("code"),
                "evidence_key": failure.get("evidence_key"),
                "expected": failure.get("expected"),
                "actual": failure.get("actual"),
            }
        )

    return retained_failures


def compact_finding_ref(
    scenario_result: dict[str, Any],
) -> tuple[Any | None, Any | None]:
    linked_findings = scenario_result.get("linked_findings")
    if not isinstance(linked_findings, list):
        return None, None

    for finding in linked_findings:
        if isinstance(finding, dict):
            finding_type = finding.get("type")
            finding_id = finding.get("id") or finding_type
            if finding_type == "signal_query_php_worker_mirror_failed":
                return finding_id, finding_type
        elif finding == "signal_query_php_worker_mirror_failed":
            return finding, finding

    return None, None


def php_worker_mirror_diagnostics_from_scenario_result(
    scenario_results: dict[str, dict[str, Any]],
) -> dict[str, Any] | None:
    scenario_result = scenario_results.get("php_worker_cli_and_sdk_baseline")
    if not isinstance(scenario_result, dict):
        return None
    if scenario_result.get("status") != "fail":
        return None

    observed = scenario_result.get("observed_outputs")
    if not isinstance(observed, dict):
        return None

    retained_failures = retainable_php_worker_mirror_failures(
        php_baseline_behavior_failures(observed)
    )
    if not retained_failures:
        return None

    finding_id, finding_type = compact_finding_ref(scenario_result)
    finding_type = finding_type or "signal_query_php_worker_mirror_failed"
    finding_id = finding_id or finding_type

    return {
        "finding_id": finding_id,
        "finding_type": finding_type,
        "current_evidence_candidate_status": current_evidence_candidate_status(
            "php_worker_cli_and_sdk_baseline"
        ),
        "current_behavior_failures": retained_failures,
    }


def retained_behavior_failure_diagnostics(
    findings: list[dict[str, Any]],
    scenario_results: dict[str, dict[str, Any]],
) -> dict[str, Any]:
    diagnostics: dict[str, Any] = {}

    for finding in findings:
        if finding.get("scenario_id") != "php_worker_cli_and_sdk_baseline":
            continue
        if finding.get("type") != "signal_query_php_worker_mirror_failed":
            continue

        current_evidence = finding.get("current_evidence")
        if not isinstance(current_evidence, dict):
            continue

        retained_failures = retainable_php_worker_mirror_failures(
            current_evidence.get("current_behavior_failures")
        )
        if not retained_failures:
            continue

        diagnostics["php_worker_cli_and_sdk_baseline"] = {
            "finding_id": finding.get("id"),
            "finding_type": finding.get("type"),
            "current_evidence_candidate_status": current_evidence.get("current_evidence_candidate_status"),
            "current_behavior_failures": retained_failures,
        }

    if "php_worker_cli_and_sdk_baseline" not in diagnostics:
        raw_diagnostics = php_worker_mirror_diagnostics_from_scenario_result(scenario_results)
        if raw_diagnostics is not None:
            diagnostics["php_worker_cli_and_sdk_baseline"] = raw_diagnostics

    return diagnostics


if runner_blocked:
    outcome = "non_passing_runner_blocked"
elif not findings and all(item["status"] == "pass" for item in scenario_results.values()):
    outcome = "pass"
else:
    outcome = "non_passing"

behavior_failure_diagnostics = retained_behavior_failure_diagnostics(findings, scenario_results)
result = {
    "schema": "durable-workflow.v2.signal-query-runtime.result",
    "started_at": started_at,
    "finished_at": finished_at,
    "outcome": outcome,
    "runner_blocked": runner_blocked,
    "artifactVersions": artifact_versions,
    "artifact_sources": pins["artifact_sources"],
    "runtime_matrix": {
        "runtimes": ["workflow-php", "sdk-python"],
        "same_language_cells": [
            {
                "scenario": "python_worker_cli_and_sdk_baseline",
                "worker": "sdk-python",
                "clients": ["cli", "sdk-python"],
            },
            {
                "scenario": "php_worker_cli_and_sdk_baseline",
                "worker": "workflow-php",
                "clients": ["cli", "workflow-php-sdk"],
            },
        ],
        "cross_language_cells": [
            {
                "scenario": "python_worker_php_facing_and_cli_clients",
                "worker": "sdk-python",
                "clients": ["workflow-php-sdk", "cli"],
            },
            {
                "scenario": "php_worker_python_and_cli_clients",
                "worker": "workflow-php",
                "clients": ["sdk-python", "cli"],
            },
        ],
    },
    "replay_timing": section_for("signal_during_replay", "query_during_replay"),
    "terminal_run_behavior": section_for("completed_run_signal_and_query"),
    "adversarial_errors": section_for(
        "unknown_signal_and_query_errors",
        "malformed_signal_and_query_payloads",
    ),
    "waterline_observer_comparison": section_for("waterline_operator_visibility"),
    "scenario_results": scenario_results,
    "findings": findings,
    "finding_links": finding_links,
}
if behavior_failure_diagnostics:
    result["behavior_failure_diagnostics"] = behavior_failure_diagnostics
if runner_blocked and baseline_readiness_blocker is not None:
    result["runner_blocker"] = baseline_readiness_blocker
write_json(result_dir / "signals-queries-result.json", result)

ordered_record_outputs = scenario_results.get("ordered_signal_delivery", {}).get("observed_outputs", {})
ordered_signal_delivery_evidence: dict[str, Any] = {}
if isinstance(ordered_record_outputs, dict):
    ordered_signal_delivery_evidence = {
        key: ordered_record_outputs[key]
        for key in BASELINE_CURRENT_EVIDENCE_FIELDS["ordered_signal_delivery"]
        if key in ordered_record_outputs
    }

record = {
    "experiment": "signals-queries",
    "outcome": outcome,
    "runnerBlocked": runner_blocked,
    "artifactVersions": artifact_versions,
    "result_file": "signals-queries-result.json",
    "findings_file": "signals-queries-findings.json",
}
if ordered_signal_delivery_evidence:
    record["ordered_signal_delivery_evidence"] = ordered_signal_delivery_evidence
if behavior_failure_diagnostics:
    record["behavior_failure_diagnostics"] = behavior_failure_diagnostics
if runner_blocked and baseline_readiness_blocker is not None:
    record["runner_blocker"] = baseline_readiness_blocker
write_json(result_dir / "signals-queries-record.json", record)

stdout_record = {"outcome": outcome, "result_dir": str(result_dir)}
if behavior_failure_diagnostics:
    stdout_record["behavior_failure_diagnostics"] = behavior_failure_diagnostics
print(json.dumps(stdout_record, sort_keys=True))
PY
