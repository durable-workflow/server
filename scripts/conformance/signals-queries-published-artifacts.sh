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
  DW_CLI_VERSION                            Published CLI version under test.
  DW_PYTHON_SDK_VERSION                     Published Python SDK version under test.
  DW_WORKFLOW_PHP_VERSION                   Published PHP workflow version under test.
  DW_WATERLINE_VERSION                      Published Waterline version under test.
  DW_SIGNALS_QUERIES_RESULT_DIR             Result directory when --result-dir is omitted.
  DW_SIGNALS_QUERIES_EVIDENCE               Optional JSON evidence from a real matrix run.
  DW_SIGNALS_QUERIES_SMOKE_EVIDENCE         Deprecated alias for DW_SIGNALS_QUERIES_EVIDENCE.
  DW_SIGNALS_QUERIES_RUN_ADVERSARIAL_PROBE  Set to 0 to skip the live malformed/unknown error shard.
  DW_SIGNALS_QUERIES_RUN_REPLAY_TERMINAL_PROBE
                                             Set to 0 to skip the live replay/terminal shard.
  DW_SIGNALS_QUERIES_SERVER_URL             Reuse an already-running published server for the adversarial shard.
  DW_SIGNALS_QUERIES_AUTH_TOKEN             Bearer token for the adversarial shard. Defaults to dev-token.
  DW_SIGNALS_QUERIES_NAMESPACE              Namespace for the adversarial shard. Defaults to default.
  DW_SIGNALS_QUERIES_CLI_BIN                Optional explicit published dw binary path.
  DW_SIGNALS_QUERIES_PYTHON                 Optional Python executable with the published SDK installed.
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

import hashlib
import json
import os
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
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


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
        headers["X-Durable-Workflow-Protocol-Version"] = "1.10"
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
        "queries": ["count-at-least", "state"],
        "query_contracts": [
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


def wait_for_ready(base_url: str, log_file: Path, timeout_seconds: float = 90.0) -> None:
    deadline = time.time() + timeout_seconds
    last_error = ""
    while time.time() < deadline:
        try:
            with urllib.request.urlopen(url_join(base_url, "/api/ready"), timeout=5) as response:
                if 200 <= response.status < 300:
                    return
        except Exception as exc:  # noqa: BLE001 - diagnostic best effort for conformance logs.
            last_error = f"{type(exc).__name__}: {exc}"
        time.sleep(1)
    raise RuntimeError(f"published server did not become ready: {last_error}")


def start_published_server(run_root: Path, log_file: Path) -> tuple[str, list[list[str]]]:
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
    env.update(
        {
            "SERVER_PORT": str(port),
            "DW_SERVER_TAG": server_version,
            "DW_SERVER_IMAGE": env_text("DW_SERVER_IMAGE") or f"durableworkflow/server:{server_version}",
            "DW_AUTH_TOKEN": token,
            "DW_AUTH_BACKWARD_COMPATIBLE": "true",
        }
    )

    commands = [
        ["docker", "compose", "-p", project, "-f", str(compose), "down", "-v"],
        ["docker", "compose", "-p", project, "-f", str(compose), "up", "-d", "--wait", "server"],
    ]

    run_command(commands[0], log_file=log_file, env=env, timeout=120)
    up = run_command(commands[1], log_file=log_file, env=env, timeout=240)
    if up.returncode != 0:
        raise RuntimeError("docker compose failed to start the published server")

    base_url = f"http://127.0.0.1:{port}"
    wait_for_ready(base_url, log_file)
    return base_url, [["docker", "compose", "-p", project, "-f", str(compose), "down", "-v"]]


def install_cli(run_root: Path, log_file: Path) -> str:
    explicit = env_text("DW_SIGNALS_QUERIES_CLI_BIN") or env_text("DW_CLI_BIN")
    if explicit:
        if Path(explicit).is_file() and os.access(explicit, os.X_OK):
            return explicit
        raise RuntimeError(f"configured CLI binary is not executable: {explicit}")

    cli_version = artifact_version_value(artifact_versions, "cli")
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

    return str(binary)


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


def ensure_python_sdk(run_root: Path, log_file: Path) -> str:
    explicit = env_text("DW_SIGNALS_QUERIES_PYTHON")
    if explicit:
        return explicit

    sdk_version = artifact_version_value(artifact_versions, "sdk-python")
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

    return str(python_bin)


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
        poll = http_json(
            base_url,
            api_path("worker", "query-tasks", "poll"),
            method="POST",
            body={
                "worker_id": worker_id,
                "task_queue": task_queue,
                "poll_request_id": f"adversarial-{int(time.time() * 1000)}",
            },
            token=token,
            namespace=namespace,
            worker=True,
            timeout=poll_timeout,
        )
        holder["poll"] = poll
        task = poll.get("body", {}).get("task") if isinstance(poll.get("body"), dict) else None
        if not isinstance(task, dict):
            holder["error"] = "query task poll returned no task"
            return

        holder["query_handler_invoked_at"] = now()
        holder["query_task"] = task
        complete = http_json(
            base_url,
            api_path("worker", "query-tasks", str(task["query_task_id"]), "complete"),
            method="POST",
            body={
                "lease_owner": worker_id,
                "query_task_attempt": task["query_task_attempt"],
                "result": result,
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

    try:
        if not isinstance(base_url, str) or base_url.strip() == "":
            base_url, cleanup_commands = start_published_server(run_root, log_file)
        else:
            base_url = base_url.rstrip("/")
            wait_for_ready(base_url, log_file, timeout_seconds=30)

        cli_bin = install_cli(run_root, log_file)
        python_bin = ensure_python_sdk(run_root, log_file)

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
        sources = {
            "server": "published_docker_image" if cleanup_commands else "published_server_endpoint",
            "cli": "published_cli_release",
            "sdk-python": "published_pypi_package",
            "workflow-php": "published_composer_package",
            "waterline": "published_waterline_artifact",
        }
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
            "replay_terminal_probe": replay_terminal_descriptor,
        }
        return evidence, descriptor
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
    if not smoke_evidence_matches_current_tuple():
        return False

    if evidence_source_policy_violations(candidate, observed):
        return False

    versions = candidate_artifact_versions(candidate, observed)
    return not versions or artifact_version_mismatches(versions) == {}


def exact_python_smoke_present() -> bool:
    return all(
        smoke_field_true(field, "python_worker_cli_and_sdk_baseline")
        for field in (
            "python_worker_query_task_routing",
            "cli_signal_and_query",
            "sdk_python_signal_and_query",
            "immediate_repeat_query_consistency",
        )
    )


def exact_ordered_delivery_smoke_present() -> bool:
    rapid_inputs = smoke_field("rapid_increment_inputs", "ordered_signal_delivery")
    history_signal_order = smoke_field("history_signal_order", "ordered_signal_delivery")
    return (
        rapid_inputs == list(range(1, 11))
        and smoke_field("ten_signal_ordered_delivery_total", "ordered_signal_delivery") == 55
        and history_signal_order == list(range(1, 11))
    )


ALLOWED_SCENARIO_STATUSES = {"pass", "fail", "unsupported", "not_covered", "runner_blocked"}

SCENARIO_REQUIRED_EVIDENCE: dict[str, list[str]] = {
    "published_artifact_install_only": [
        "published_artifact_versions",
        "artifact_sources",
    ],
    "python_worker_cli_and_sdk_baseline": [
        "python_worker_query_task_routing",
        "cli_signal_and_query",
        "sdk_python_signal_and_query",
        "immediate_repeat_query_consistency",
    ],
    "php_worker_cli_and_sdk_baseline": [
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
        "queried_total",
        "history_signal_order",
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
        "cli_unknown_signal_sample",
        "cli_unknown_query_sample",
        "cli_missing_workflow_signal_sample",
        "cli_missing_workflow_query_sample",
        "sdk_python_unknown_signal_sample",
        "sdk_python_unknown_query_sample",
        "sdk_python_missing_workflow_signal_sample",
        "sdk_python_missing_workflow_query_sample",
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


def install_outputs_cover_required_artifacts(observed: dict[str, Any]) -> bool:
    versions = declared_artifact_versions(observed)
    sources = artifact_sources_from_outputs(observed)
    if not versions or not sources:
        return False
    if not any(
        isinstance(observed.get(field), dict) and observed.get(field)
        for field in ("published_artifact_versions", "publishedArtifactVersions")
    ):
        return False

    if evidence_source_policy_violations({"artifact_sources": sources}):
        return False

    for artifact in REQUIRED_INSTALL_ARTIFACTS:
        version = artifact_version_value(versions, artifact)
        source = artifact_source_value(sources, artifact)
        if version == "" or is_placeholder_version(version):
            return False
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

    if scenario == "ordered_signal_delivery":
        rapid_inputs = evidence_lookup(observed, "rapid_increment_inputs")
        queried_total = evidence_lookup(observed, "queried_total")
        if queried_total is MISSING:
            queried_total = evidence_lookup(observed, "ten_signal_ordered_delivery_total")
        history_signal_order = evidence_lookup(observed, "history_signal_order")

        return (
            rapid_inputs == list(range(1, 11))
            and queried_total == 55
            and history_signal_order == list(range(1, 11))
        )

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
                    ("query_sent_at", "<", "replay_completed_at"),
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
        return (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "unknown_signal.status_code", 404, 404)
            and status_code_in_range(observed, "missing_workflow_signal.status_code", 404, 404)
            and status_code_in_range(observed, "missing_workflow_query.status_code", 404, 404)
            and status_code_in_range(observed, "query_not_found.status_code", 404, 404)
            and reason_in(observed, "unknown_signal.reason", {"unknown_signal"})
            and reason_in(observed, "missing_workflow_signal.reason", {"instance_not_found"})
            and reason_in(observed, "missing_workflow_query.reason", {"instance_not_found"})
            and reason_in(observed, "query_not_found.reason", query_reasons)
            and reason_in(observed, "rejected_unknown_query.reason", query_reasons)
            and status_code_in_range(observed, "cli_unknown_signal_sample.status_code", 404, 404)
            and status_code_in_range(observed, "cli_unknown_query_sample.status_code", 404, 404)
            and status_code_in_range(observed, "cli_missing_workflow_signal_sample.status_code", 404, 404)
            and status_code_in_range(observed, "cli_missing_workflow_query_sample.status_code", 404, 404)
            and reason_in(observed, "cli_unknown_signal_sample.reason", {"unknown_signal"})
            and reason_in(observed, "cli_unknown_query_sample.reason", query_reasons)
            and reason_in(observed, "cli_missing_workflow_signal_sample.reason", {"instance_not_found"})
            and reason_in(observed, "cli_missing_workflow_query_sample.reason", {"instance_not_found"})
            and status_code_in_range(observed, "sdk_python_unknown_signal_sample.status_code", 404, 404)
            and status_code_in_range(observed, "sdk_python_unknown_query_sample.status_code", 404, 404)
            and reason_in(observed, "sdk_python_unknown_signal_sample.reason", {"unknown_signal"})
            and reason_in(observed, "sdk_python_unknown_query_sample.reason", query_reasons)
            and reason_in(observed, "sdk_python_missing_workflow_signal_sample.reason", {"instance_not_found"})
            and reason_in(observed, "sdk_python_missing_workflow_query_sample.reason", {"instance_not_found"})
            and evidence_lookup(observed, "sdk_python_unknown_signal_sample.exception") == "SignalFailed"
            and evidence_lookup(observed, "sdk_python_unknown_query_sample.exception") == "QueryFailed"
            and evidence_lookup(observed, "sdk_python_missing_workflow_signal_sample.exception") == "WorkflowNotFound"
            and evidence_lookup(observed, "sdk_python_missing_workflow_query_sample.exception") == "WorkflowNotFound"
        )

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
            outputs[field] = install_evidence

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
            "record immediate repeat-query consistency",
        ],
    },
    "ordered_signal_delivery": {
        "type": "signal_query_ordered_delivery_uncovered",
        "owner": "server",
        "title": "Signals/queries ordered delivery evidence remains unproved",
        "acceptance": [
            "send increment(1) through increment(10) rapidly",
            "query total 55",
            "record history signal order matching submission order",
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
            "python_worker_query_task_routing": smoke_field(
                "python_worker_query_task_routing",
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
            "queried_total": smoke_field("ten_signal_ordered_delivery_total", scenario),
            "history_signal_order": smoke_field("history_signal_order", scenario),
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
            result["linked_findings"] = [finding_id]
            findings.append(finding)
            finding_links[scenario] = [finding_id]

    scenario_results[scenario] = result

pins = {
    "artifact_versions": artifact_versions,
    "artifact_sources": dict(EXPECTED_ARTIFACT_SOURCES),
}
write_json(result_dir / "pins.json", pins)

run_metadata = {
    "schema": "durable-workflow.v2.signal-query-runtime.run-metadata",
    "started_at": started_at,
    "finished_at": finished_at,
    "runner": "scripts/conformance/signals-queries-published-artifacts.sh",
    "local_product_source_checkouts_used": False,
    "smoke_evidence": smoke_descriptor,
}
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


outcome = "pass" if not findings and all(item["status"] == "pass" for item in scenario_results.values()) else "non_passing"
result = {
    "schema": "durable-workflow.v2.signal-query-runtime.result",
    "started_at": started_at,
    "finished_at": finished_at,
    "outcome": outcome,
    "runner_blocked": False,
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
write_json(result_dir / "signals-queries-result.json", result)

record = {
    "experiment": "signals-queries",
    "outcome": "pass" if outcome == "pass" else "fail",
    "runnerBlocked": False,
    "artifactVersions": artifact_versions,
    "result_file": "signals-queries-result.json",
    "findings_file": "signals-queries-findings.json",
}
write_json(result_dir / "signals-queries-record.json", record)

print(json.dumps({"outcome": outcome, "result_dir": str(result_dir)}, sort_keys=True))
PY
