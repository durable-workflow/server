#!/usr/bin/env python3
"""Exact-artifact single-region failover rehearsal.

This file is orchestration only. Product runtime behavior is supplied by the
immutable public server image selected by the shell handoff.
"""

from __future__ import annotations

import concurrent.futures
import datetime as dt
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
from typing import Any, Callable, Iterable
import urllib.error
import urllib.request


SCHEMA = "durable-workflow.v2.single-region-failover.result"
PLATFORM_CONFORMANCE_SUITE_SCHEMA = "durable-workflow.v2.platform-conformance.suite"
TOKEN = os.environ.get("DW_AUTH_TOKEN", "failover-rehearsal-token")
NAMESPACE = "default"
TASK_QUEUE = "single-region-failover"
WORKFLOW_TYPE = "single.region.failover.workflow"
COMPOSE_FILE = Path(os.environ["DW_FAILOVER_COMPOSE_FILE"])
RESULT_DIR = Path(os.environ["DW_FAILOVER_RESULT_DIR"])
RESULT_PATH = RESULT_DIR / "single-region-failover-result.json"
PROJECT = os.environ["DW_FAILOVER_PROJECT"]
KEEP_STACK = os.environ.get("DW_FAILOVER_KEEP_STACK") == "1"
MODE = os.environ.get("DW_FAILOVER_MODE", "full")

DEFAULT_PORTS = {
    "server_a": 18084,
    "server_b": 18085,
    "load_balancer": 18086,
}
PORT_ENVIRONMENT = {
    "server_a": "DW_FAILOVER_SERVER_A_PORT",
    "server_b": "DW_FAILOVER_SERVER_B_PORT",
    "load_balancer": "DW_FAILOVER_LB_PORT",
}
READINESS_OBSERVATION_LIMIT = 12
TOPOLOGY_START_FAILURE_READINESS_TIMEOUT = 5
DIAGNOSTIC_OUTPUT_LIMIT = 12000

# Loaded from the released image's public failover contract during topology
# startup so new lifecycle states inherit their published bucket semantics.
PUBLIC_RUN_STATUS_CONTRACT: dict[str, dict[str, Any]] = {}


def parse_connect_host(value: str) -> str:
    if not value or value != value.strip():
        raise ValueError("DW_FAILOVER_CONNECT_HOST must be a hostname or IP without surrounding whitespace")
    if "://" in value or any(character in value for character in "/?#@"):
        raise ValueError("DW_FAILOVER_CONNECT_HOST must not include a URL scheme, path, query, fragment, or user info")

    candidate = value
    if value.startswith("[") or value.endswith("]"):
        if not (value.startswith("[") and value.endswith("]")):
            raise ValueError("DW_FAILOVER_CONNECT_HOST contains unmatched IPv6 brackets")
        candidate = value[1:-1]

    if "%" in candidate:
        raise ValueError("DW_FAILOVER_CONNECT_HOST must not use an interface-scoped IPv6 literal")

    try:
        return str(ipaddress.ip_address(candidate))
    except ValueError:
        pass

    try:
        hostname = candidate.encode("idna").decode("ascii")
    except UnicodeError as error:
        raise ValueError("DW_FAILOVER_CONNECT_HOST is not a valid DNS hostname") from error

    labels = hostname[:-1].split(".") if hostname.endswith(".") else hostname.split(".")
    if (
        len(hostname.rstrip(".")) > 253
        or not labels
        or any(
            not label
            or len(label) > 63
            or re.fullmatch(r"[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?", label) is None
            for label in labels
        )
    ):
        raise ValueError("DW_FAILOVER_CONNECT_HOST is not a valid DNS hostname")
    return hostname


def parse_published_port(name: str, value: str) -> int:
    try:
        port = int(value)
    except ValueError as error:
        raise ValueError(f"{PORT_ENVIRONMENT[name]} must be an integer port") from error
    if not 1 <= port <= 65535:
        raise ValueError(f"{PORT_ENVIRONMENT[name]} must be between 1 and 65535")
    return port


def build_probe_endpoints(connect_host: str, ports: dict[str, int] | None = None) -> dict[str, str]:
    host = parse_connect_host(connect_host)
    try:
        address = ipaddress.ip_address(host)
    except ValueError:
        url_host = host
    else:
        url_host = f"[{host}]" if address.version == 6 else host

    selected_ports = ports or DEFAULT_PORTS
    return {
        name: f"http://{url_host}:{selected_ports[name]}"
        for name in DEFAULT_PORTS
    }


CONFIGURATION_ERROR: str | None = None
try:
    CONNECT_HOST = parse_connect_host(os.environ.get("DW_FAILOVER_CONNECT_HOST", "127.0.0.1"))
    PUBLISHED_PORTS = {
        name: parse_published_port(name, os.environ.get(environment, str(DEFAULT_PORTS[name])))
        for name, environment in PORT_ENVIRONMENT.items()
    }
    PROBE_ENDPOINTS = build_probe_endpoints(CONNECT_HOST, PUBLISHED_PORTS)
except ValueError as error:
    CONFIGURATION_ERROR = str(error)
    CONNECT_HOST = os.environ.get("DW_FAILOVER_CONNECT_HOST", "127.0.0.1")
    PUBLISHED_PORTS = DEFAULT_PORTS.copy()
    PROBE_ENDPOINTS = {name: "" for name in DEFAULT_PORTS}

LB = PROBE_ENDPOINTS["load_balancer"]
SERVER_A = PROBE_ENDPOINTS["server_a"]
SERVER_B = PROBE_ENDPOINTS["server_b"]

BOUNDS = {
    "api_node_useful_traffic_seconds": 15,
    "database_ready_after_return_seconds": 30,
    "redis_poll_discovery_seconds": 10,
    "redis_recovered_poll_discovery_seconds": 3,
    "redis_ready_after_return_seconds": 15,
    "workflow_task_lease_seconds": int(os.environ.get("DW_FAILOVER_WORKFLOW_TASK_LEASE_SECONDS", "8")),
    "worker_repair_after_lease_seconds": 10,
    "scheduler_fire_after_restart_seconds": 15,
}

PHASE_RECOVERY_BOUNDS = {
    "api_node_loss": ("api_node_useful_traffic_seconds",),
    "database_interruption": ("database_ready_after_return_seconds",),
    "redis_interruption": (
        "redis_poll_discovery_seconds",
        "redis_recovered_poll_discovery_seconds",
        "redis_ready_after_return_seconds",
    ),
    "worker_lease_loss": (
        "workflow_task_lease_seconds",
        "worker_repair_after_lease_seconds",
    ),
    "singleton_scheduler_restart": ("scheduler_fire_after_restart_seconds",),
}

REQUIRED_PHASES = {
    "published_artifact_provenance",
    "cross_node_workflow_completion",
    "api_node_loss",
    "database_interruption",
    "redis_interruption",
    "worker_lease_loss",
    "singleton_scheduler_restart",
}


def now() -> str:
    return dt.datetime.now(dt.timezone.utc).isoformat().replace("+00:00", "Z")


def monotonic_ms(started: float) -> int:
    return round((time.monotonic() - started) * 1000)


def command(args: list[str], *, check: bool = True, timeout: int = 240) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, check=check, text=True, capture_output=True, timeout=timeout)


def compose(*args: str, check: bool = True, timeout: int = 240) -> subprocess.CompletedProcess[str]:
    return command(
        ["docker", "compose", "-p", PROJECT, "-f", str(COMPOSE_FILE), *args],
        check=check,
        timeout=timeout,
    )


def request(
    base: str,
    path: str,
    *,
    method: str = "GET",
    payload: dict[str, Any] | None = None,
    worker: bool = False,
    authenticated: bool = True,
    timeout: float = 10,
) -> tuple[int, dict[str, Any], int]:
    headers = {"Accept": "application/json"}
    if authenticated:
        headers["Authorization"] = f"Bearer {TOKEN}"
        headers["X-Namespace"] = NAMESPACE
    if worker:
        headers["X-Durable-Workflow-Protocol-Version"] = "1.0"
    elif authenticated:
        headers["X-Durable-Workflow-Control-Plane-Version"] = "2"
    body = None
    if payload is not None:
        body = json.dumps(payload).encode()
        headers["Content-Type"] = "application/json"
    started = time.monotonic()
    req = urllib.request.Request(base + path, data=body, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as response:
            raw = response.read().decode("utf-8", errors="replace")
            status = response.status
    except urllib.error.HTTPError as error:
        raw = error.read().decode("utf-8", errors="replace")
        status = error.code
    except (urllib.error.URLError, TimeoutError, ConnectionError) as error:
        return 0, {"transport_error": str(error)}, monotonic_ms(started)
    try:
        decoded = json.loads(raw) if raw else {}
    except json.JSONDecodeError:
        decoded = {"raw": raw[:2000]}
    return status, decoded, monotonic_ms(started)


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def wait_for(label: str, callback: Callable[[], Any], timeout: float, interval: float = 0.5) -> Any:
    deadline = time.monotonic() + timeout
    last: Any = None
    while time.monotonic() < deadline:
        last = callback()
        if last:
            return last
        time.sleep(interval)
    raise AssertionError(f"timed out waiting for {label}; last observation: {last!r}")


class ProbeObservation(dict[str, Any]):
    """A rejected probe remains diagnostic evidence without satisfying wait_for."""

    def __bool__(self) -> bool:
        return bool(self.get("accepted"))


def redacted_run_summary(body: Any) -> dict[str, Any]:
    if not isinstance(body, dict):
        body = {}
    return {
        "workflow_id": body.get("workflow_id") if isinstance(body.get("workflow_id"), str) else None,
        "run_id": body.get("run_id") if isinstance(body.get("run_id"), str) else None,
        "raw_status": body.get("status") if isinstance(body.get("status"), str) else None,
        "status_bucket": body.get("status_bucket") if isinstance(body.get("status_bucket"), str) else None,
        "is_terminal": body.get("is_terminal") if isinstance(body.get("is_terminal"), bool) else None,
    }


def parse_public_run_status_contract(value: Any) -> dict[str, dict[str, Any]]:
    if not isinstance(value, dict) or not value:
        raise AssertionError("released image does not expose a public run-status contract")

    contract: dict[str, dict[str, Any]] = {}
    for raw_status, fields in value.items():
        require(isinstance(raw_status, str) and bool(raw_status), "run-status contract contains an invalid raw status")
        require(isinstance(fields, dict), f"run-status contract for {raw_status} is not an object")
        status_bucket = fields.get("status_bucket")
        is_terminal = fields.get("is_terminal")
        require(
            status_bucket in ("running", "completed", "failed"),
            f"run-status contract for {raw_status} has an invalid status bucket",
        )
        require(
            isinstance(is_terminal, bool),
            f"run-status contract for {raw_status} has an invalid terminal flag",
        )
        require(
            (status_bucket == "running") is (not is_terminal),
            f"run-status contract for {raw_status} has inconsistent bucket and terminal fields",
        )
        contract[raw_status] = {
            "status_bucket": status_bucket,
            "is_terminal": is_terminal,
        }
    return contract


def parse_public_suite_schema(contract: Any) -> str:
    require(isinstance(contract, dict), "released image does not expose the single-region failover contract")
    scenario_manifest = contract.get("scenario_manifest")
    require(isinstance(scenario_manifest, dict), "released image does not expose a failover scenario manifest contract")
    suite_schema = scenario_manifest.get("suite_schema")
    require(
        suite_schema == PLATFORM_CONFORMANCE_SUITE_SCHEMA,
        "released image exposes a non-canonical platform conformance suite schema",
    )
    return suite_schema


def nonterminal_run_observation(
    http_status: int,
    body: Any,
    expected_workflow_id: str,
    expected_run_id: str,
) -> dict[str, Any]:
    summary = redacted_run_summary(body)
    raw_status = summary["raw_status"]
    expected_status = PUBLIC_RUN_STATUS_CONTRACT.get(raw_status)
    rejection_reason: str | None = None

    if http_status != 200:
        rejection_reason = "http_status_not_ok"
    elif summary["workflow_id"] != expected_workflow_id:
        rejection_reason = "workflow_identity_mismatch"
    elif summary["run_id"] != expected_run_id:
        rejection_reason = "run_identity_mismatch"
    elif expected_status is None:
        rejection_reason = "missing_or_unknown_raw_status"
    elif summary["status_bucket"] != expected_status["status_bucket"]:
        rejection_reason = "status_bucket_contract_mismatch"
    elif summary["is_terminal"] is not expected_status["is_terminal"]:
        rejection_reason = "terminal_flag_contract_mismatch"
    elif expected_status["is_terminal"] or expected_status["status_bucket"] != "running":
        rejection_reason = "terminal_run"

    return {
        "http_status": http_status,
        "response_summary": summary,
        "accepted": rejection_reason is None,
        "rejection_reason": rejection_reason,
    }


def wait_for_survivor_traffic(
    workflow_id: str,
    run_id: str,
    timeout: float,
    evidence: dict[str, Any],
    interval: float = 0.5,
) -> dict[str, Any]:
    deadline = time.monotonic() + timeout
    last: dict[str, Any] | None = None
    while time.monotonic() < deadline:
        status, body, elapsed = describe(workflow_id, run_id, LB)
        last = {
            **nonterminal_run_observation(status, body, workflow_id, run_id),
            "request_ms": elapsed,
            "observed_at": now(),
        }
        evidence.clear()
        evidence.update(last)
        if last["accepted"]:
            return last
        time.sleep(interval)
    raise AssertionError(
        "timed out waiting for useful shared-endpoint traffic after API node loss; "
        f"last observation: {last!r}"
    )


def ready(base: str, expected_status: int = 200) -> ProbeObservation:
    status, body, elapsed = request(base, "/api/ready", authenticated=False, timeout=3)
    accepted = status == expected_status

    return ProbeObservation({
        "http_status": status,
        "body": body,
        "request_ms": elapsed,
        "observed_at": now(),
        "accepted": accepted,
        "rejection_reason": None if accepted else ("transport_error" if status == 0 else "unexpected_http_status"),
    })


def command_diagnostic(result: subprocess.CompletedProcess[str]) -> dict[str, Any]:
    return {
        "exit_code": result.returncode,
        "stdout": result.stdout[-DIAGNOSTIC_OUTPUT_LIMIT:],
        "stderr": result.stderr[-DIAGNOSTIC_OUTPUT_LIMIT:],
        "output_truncated": (
            len(result.stdout) > DIAGNOSTIC_OUTPUT_LIMIT
            or len(result.stderr) > DIAGNOSTIC_OUTPUT_LIMIT
        ),
    }


def exception_diagnostic(error: Exception) -> dict[str, Any]:
    diagnostic: dict[str, Any] = {
        "error_type": type(error).__name__,
        "reason": str(error),
    }
    if isinstance(error, subprocess.CalledProcessError):
        diagnostic.update({
            "exit_code": error.returncode,
            "stdout": (error.stdout or "")[-DIAGNOSTIC_OUTPUT_LIMIT:],
            "stderr": (error.stderr or "")[-DIAGNOSTIC_OUTPUT_LIMIT:],
            "output_truncated": (
                len(error.stdout or "") > DIAGNOSTIC_OUTPUT_LIMIT
                or len(error.stderr or "") > DIAGNOSTIC_OUTPUT_LIMIT
            ),
        })
    return diagnostic


def initial_topology_diagnostics() -> dict[str, Any]:
    endpoints = {
        name: {
            "base_url": base,
            "readiness_url": f"{base}/api/ready",
            "published_port": PUBLISHED_PORTS[name],
        }
        for name, base in PROBE_ENDPOINTS.items()
    }
    return {
        "connect_host": CONNECT_HOST,
        "resolved_probe_endpoints": endpoints,
        "compose_up": None,
        "compose_ps": None,
        "published_port_mappings": {},
        "readiness_observations": {
            name: {
                "endpoint": endpoint["readiness_url"],
                "attempt_count": 0,
                "observations_truncated": 0,
                "ready": False,
                "observations": [],
            }
            for name, endpoint in endpoints.items()
        },
    }


TOPOLOGY_DIAGNOSTICS = initial_topology_diagnostics()


def refresh_topology_diagnostics() -> None:
    try:
        TOPOLOGY_DIAGNOSTICS["compose_ps"] = command_diagnostic(
            compose("ps", "--all", "--format", "json", check=False, timeout=30)
        )
    except Exception as error:
        TOPOLOGY_DIAGNOSTICS["compose_ps"] = exception_diagnostic(error)

    mappings: dict[str, Any] = {}
    for name, service in (
        ("server_a", "server-a"),
        ("server_b", "server-b"),
        ("load_balancer", "load-balancer"),
    ):
        try:
            result = compose("port", service, "8080", check=False, timeout=30)
            mappings[name] = {
                "service": service,
                "container_port": "8080/tcp",
                "configured_host_port": PUBLISHED_PORTS[name],
                "published": result.stdout.splitlines(),
                **command_diagnostic(result),
            }
        except Exception as error:
            mappings[name] = {
                "service": service,
                "container_port": "8080/tcp",
                "configured_host_port": PUBLISHED_PORTS[name],
                **exception_diagnostic(error),
            }
    TOPOLOGY_DIAGNOSTICS["published_port_mappings"] = mappings


def api_node_loss_failure_diagnostics() -> dict[str, Any]:
    diagnostics: dict[str, Any] = {}
    for name, args in (
        ("compose_ps", ("ps", "--all", "--format", "json")),
        ("load_balancer_logs", ("logs", "--no-color", "--tail", "200", "load-balancer")),
        ("surviving_node_logs", ("logs", "--no-color", "--tail", "200", "server-b")),
    ):
        try:
            diagnostics[name] = command_diagnostic(
                compose(*args, check=False, timeout=30)
            )
        except Exception as error:
            diagnostics[name] = exception_diagnostic(error)
    return diagnostics


def observe_topology_readiness(name: str, base: str, timeout: float) -> bool:
    started = time.monotonic()
    try:
        status, body, elapsed = request(
            base,
            "/api/ready",
            authenticated=False,
            timeout=max(0.1, min(3, timeout)),
        )
    except Exception as error:
        status = 0
        body = exception_diagnostic(error)
        elapsed = monotonic_ms(started)
    observation = {
        "http_status": status,
        "body": body,
        "request_ms": elapsed,
        "observed_at": now(),
    }
    evidence = TOPOLOGY_DIAGNOSTICS["readiness_observations"][name]
    evidence["attempt_count"] += 1
    observations = evidence["observations"]
    if len(observations) == READINESS_OBSERVATION_LIMIT:
        observations.pop(0)
        evidence["observations_truncated"] += 1
    observations.append(observation)
    if status == 200:
        evidence["ready"] = True
        return True
    return False


def wait_for_topology_readiness(timeout: float = 30) -> None:
    deadline = time.monotonic() + timeout
    pending = set(PROBE_ENDPOINTS)
    while pending and time.monotonic() < deadline:
        remaining = max(0.1, deadline - time.monotonic())
        with concurrent.futures.ThreadPoolExecutor(max_workers=len(pending)) as executor:
            attempts = {
                name: executor.submit(observe_topology_readiness, name, PROBE_ENDPOINTS[name], remaining)
                for name in pending
            }
            for name, attempt in attempts.items():
                if attempt.result():
                    pending.remove(name)
        if pending:
            time.sleep(min(0.5, max(0, deadline - time.monotonic())))

    if pending:
        summary = {
            name: TOPOLOGY_DIAGNOSTICS["readiness_observations"][name]["observations"][-1]
            for name in sorted(pending)
        }
        raise AssertionError(
            f"timed out waiting for topology readiness at {', '.join(sorted(pending))}; "
            f"last observations: {summary!r}"
        )


def cache_ready(base: str) -> ProbeObservation:
    observation = ready(base)
    if not observation:
        return observation
    if observation["body"].get("checks", {}).get("cache", {}).get("status") != "ok":
        return ProbeObservation({
            **observation,
            "accepted": False,
            "rejection_reason": "cache_not_ready",
        })
    return ProbeObservation({
        **observation,
        "accepted": True,
        "rejection_reason": None,
    })


def worker_headers_payload(worker_id: str) -> dict[str, Any]:
    return {
        "worker_id": worker_id,
        "task_queue": TASK_QUEUE,
        "runtime": "python",
        "supported_workflow_types": [WORKFLOW_TYPE],
    }


def register_worker(worker_id: str, base: str = LB) -> dict[str, Any]:
    status, body, elapsed = request(
        base,
        "/api/worker/register",
        method="POST",
        payload=worker_headers_payload(worker_id),
        worker=True,
    )
    require(status in (200, 201), f"worker registration failed ({status}): {body}")
    require(body.get("registered") is True, f"worker was not registered: {body}")
    return {"worker_id": worker_id, "status": status, "elapsed_ms": elapsed}


def start_workflow(workflow_id: str, base: str = LB) -> dict[str, Any]:
    status, body, elapsed = request(
        base,
        "/api/workflows",
        method="POST",
        payload={
            "workflow_id": workflow_id,
            "workflow_type": WORKFLOW_TYPE,
            "task_queue": TASK_QUEUE,
            "input": ["failover-rehearsal"],
        },
    )
    require(status in (200, 201, 202), f"workflow start failed ({status}): {body}")
    require(body.get("workflow_id") == workflow_id and body.get("run_id"), f"invalid workflow start: {body}")
    return {"workflow_id": workflow_id, "run_id": body["run_id"], "status": status, "ack_ms": elapsed}


def poll_once(worker_id: str, base: str, poll_request_id: str | None = None) -> dict[str, Any] | None:
    payload = {"worker_id": worker_id, "task_queue": TASK_QUEUE}
    if poll_request_id:
        payload["poll_request_id"] = poll_request_id
    status, body, _ = request(
        base,
        "/api/worker/workflow-tasks/poll",
        method="POST",
        payload=payload,
        worker=True,
        timeout=5,
    )
    if status not in (200, 204):
        return None
    task = body.get("task")
    return task if isinstance(task, dict) and task.get("task_id") else None


def poll_task(
    worker_id: str,
    base: str,
    timeout: float = 15,
    poll_request_id: str | None = None,
) -> dict[str, Any]:
    task = wait_for(
        f"workflow task for {worker_id}",
        lambda: poll_once(worker_id, base, poll_request_id),
        timeout,
        interval=0.2,
    )
    require(isinstance(task, dict), "poll did not return a task")
    return task


def complete_task(task: dict[str, Any], base: str) -> tuple[int, dict[str, Any], int]:
    return request(
        base,
        f"/api/worker/workflow-tasks/{task['task_id']}/complete",
        method="POST",
        payload={
            "lease_owner": task["lease_owner"],
            "workflow_task_attempt": task["workflow_task_attempt"],
            "commands": [{"type": "complete_workflow", "result": None}],
        },
        worker=True,
    )


def describe(workflow_id: str, run_id: str, base: str = LB) -> tuple[int, dict[str, Any], int]:
    return request(base, f"/api/workflows/{workflow_id}/runs/{run_id}")


def unique(prefix: str) -> str:
    return f"{prefix}-{int(time.time() * 1000)}"


RESULT: dict[str, Any] = {
    "schema": SCHEMA,
    "version": 1,
    "runner_version": int(os.environ["DW_FAILOVER_RUNNER_VERSION"]),
    "mode": MODE,
    "outcome": "running",
    "runner_blocked": False,
    "started_at": now(),
    "finished_at": None,
    "topology": {
        "region_count": 1,
        "api_nodes": ["server-a", "server-b"],
        "shared_endpoint": "load-balancer",
        "connect_host": CONNECT_HOST,
        "published_ports": PUBLISHED_PORTS,
        "database": {"engine": "mysql", "instances": 1, "durability": "named_volume"},
        "redis": {"instances": 1, "role": "acceleration_layer"},
        "scheduler_maintenance_runners": ["scheduler"],
        "sticky_sessions": False,
    },
    "artifacts": {
        "server_image_requested": os.environ["DW_FAILOVER_SERVER_IMAGE_REQUESTED"],
        "server_image_digest": os.environ["DW_FAILOVER_SERVER_IMAGE"],
        "mysql_image_requested": os.environ["DW_FAILOVER_MYSQL_IMAGE_REQUESTED"],
        "mysql_image_digest": os.environ["DW_FAILOVER_MYSQL_IMAGE"],
        "redis_image_requested": os.environ["DW_FAILOVER_REDIS_IMAGE_REQUESTED"],
        "redis_image_digest": os.environ["DW_FAILOVER_REDIS_IMAGE"],
        "load_balancer_image_requested": os.environ["DW_FAILOVER_NGINX_IMAGE_REQUESTED"],
        "load_balancer_image_digest": os.environ["DW_FAILOVER_NGINX_IMAGE"],
    },
    "tools": {
        "docker_engine_version": os.environ["DW_FAILOVER_DOCKER_VERSION"],
        "docker_compose_version": os.environ["DW_FAILOVER_COMPOSE_VERSION"],
        "bash_version": os.environ["DW_FAILOVER_BASH_VERSION"],
        "python_version": sys.version.split()[0],
    },
    "local_product_source_checkouts_used": False,
    "phase_outcomes": {},
    "phase_evidence": {},
    "readiness_transitions": [],
    "recovery_timings_ms": {},
    "identities": {},
    "duplicate_assertions": [],
    "loss_assertions": [],
    "recovery_bounds": {key: {"seconds": value, "passed": None} for key, value in BOUNDS.items()},
}


def run_phase(name: str, callback: Callable[[], dict[str, Any]]) -> None:
    started = time.monotonic()
    details = callback()
    require_recovery_bounds(PHASE_RECOVERY_BOUNDS.get(name, ()))
    RESULT["phase_outcomes"][name] = {
        "status": "pass",
        "elapsed_ms": monotonic_ms(started),
        **details,
    }


def require_recovery_bounds(bound_names: Iterable[str]) -> None:
    failed = [
        name
        for name in bound_names
        if RESULT["recovery_bounds"].get(name, {}).get("passed") is not True
    ]
    require(not failed, f"required recovery bounds did not pass: {', '.join(failed)}")


def require_passing_result() -> None:
    require(REQUIRED_PHASES.issubset(RESULT["phase_outcomes"]), "required scenario result missing")
    require(
        all(RESULT["phase_outcomes"][name]["status"] == "pass" for name in REQUIRED_PHASES),
        "required scenario did not pass",
    )
    require_recovery_bounds(BOUNDS)


def provenance_phase() -> dict[str, Any]:
    require(CONFIGURATION_ERROR is None, CONFIGURATION_ERROR or "invalid runner configuration")
    raw = compose("config", "--format", "json").stdout
    config = json.loads(raw)
    services = config.get("services", {})
    require(set(services) == {"bootstrap", "server-a", "server-b", "scheduler", "load-balancer", "mysql", "redis"}, "unexpected Compose topology")
    require(all("build" not in service for service in services.values()), "Compose build sections are forbidden")
    for service_name, service in services.items():
        image = str(service.get("image", ""))
        require("@sha256:" in image, f"{service_name} is not repository-digest pinned: {image}")
        for volume in service.get("volumes", []) or []:
            volume_type = volume.get("type") if isinstance(volume, dict) else "volume"
            require(volume_type != "bind", f"bind mount is forbidden for {service_name}: {volume}")
    require(sum(1 for name in services if name == "scheduler") == 1, "exactly one scheduler service is required")
    package_script = r'''
$lock = json_decode(file_get_contents('/app/composer.lock'), true);
$package = null;
foreach (($lock['packages'] ?? []) as $candidate) {
    if (($candidate['name'] ?? null) === 'durable-workflow/workflow') {
        $package = [
            'name' => $candidate['name'],
            'version' => $candidate['version'] ?? null,
            'source_reference' => $candidate['source']['reference'] ?? null,
            'dist_reference' => $candidate['dist']['reference'] ?? null,
        ];
        break;
    }
}
if ($package === null) { fwrite(STDERR, 'workflow package missing'); exit(2); }
$provenance = is_file('/app/.package-provenance')
    ? array_values(array_filter(array_map('trim', file('/app/.package-provenance'))))
    : [];
$package['image_package_provenance'] = $provenance;
$package['php_version'] = PHP_VERSION;
echo json_encode($package, JSON_THROW_ON_ERROR);
'''
    package_result = command([
        "docker", "run", "--rm", "--entrypoint", "php",
        os.environ["DW_FAILOVER_SERVER_IMAGE"], "-r", package_script,
    ])
    workflow_package = json.loads(package_result.stdout)
    require(bool(workflow_package.get("version")), "embedded workflow package version was not discoverable")
    require(
        bool(workflow_package.get("source_reference") or workflow_package.get("dist_reference") or workflow_package.get("image_package_provenance")),
        "embedded workflow package has no exact source reference",
    )
    RESULT["artifacts"]["embedded_workflow_package"] = workflow_package
    digest = hashlib.sha256(raw.encode()).hexdigest()
    RESULT["artifacts"]["compose_config_sha256"] = digest
    return {
        "compose_services": sorted(services),
        "compose_config_sha256": digest,
        "all_runtime_images_repo_digest_pinned": True,
        "compose_build_sections_present": False,
        "product_source_bind_mounts_present": False,
        "embedded_workflow_package": workflow_package,
    }


def start_topology() -> dict[str, Any]:
    try:
        up_result = compose("up", "-d", "--wait", "--wait-timeout", "180", timeout=240)
    except Exception as error:
        TOPOLOGY_DIAGNOSTICS["compose_up"] = exception_diagnostic(error)
        refresh_topology_diagnostics()
        try:
            wait_for_topology_readiness(TOPOLOGY_START_FAILURE_READINESS_TIMEOUT)
        except Exception:
            # The original Compose failure remains the phase error. The bounded
            # probe's observations are retained in TOPOLOGY_DIAGNOSTICS.
            pass
        raise

    TOPOLOGY_DIAGNOSTICS["compose_up"] = command_diagnostic(up_result)
    refresh_topology_diagnostics()
    wait_for_topology_readiness(30)

    running = compose("ps", "--status", "running", "--services").stdout.splitlines()
    require(running.count("scheduler") == 1, f"expected one running scheduler, got: {running}")
    image_ids: dict[str, str] = {}
    for service in ("server-a", "server-b", "scheduler", "load-balancer", "mysql", "redis"):
        container_id = compose("ps", "-q", service).stdout.strip()
        require(bool(container_id), f"missing container for {service}")
        image_ids[service] = command(["docker", "inspect", "--format", "{{.Image}}", container_id]).stdout.strip()
    RESULT["artifacts"]["runtime_image_ids"] = image_ids
    status, cluster, _ = request(LB, "/api/cluster/info")
    require(status == 200, f"cluster info failed: {status} {cluster}")
    contract = cluster.get("single_region_failover_contract", {})
    require(contract.get("schema") == "durable-workflow.v2.single-region-failover.contract", "released image does not expose the single-region failover contract")
    require(contract.get("host_runner_contract", {}).get("runner_key") == "single-region-failover", "released image exposes an incompatible runner contract")
    suite_schema = parse_public_suite_schema(contract)
    run_status_contract = parse_public_run_status_contract(contract.get("run_status_contract"))
    PUBLIC_RUN_STATUS_CONTRACT.clear()
    PUBLIC_RUN_STATUS_CONTRACT.update(run_status_contract)
    RESULT["artifacts"]["server_reported_version"] = cluster.get("version")
    RESULT["artifacts"]["suite_schema"] = suite_schema
    RESULT["artifacts"]["run_status_contract"] = run_status_contract
    return {
        "running_services": sorted(running),
        "cluster_info_contract_version": contract.get("version"),
        "suite_schema": suite_schema,
        **TOPOLOGY_DIAGNOSTICS,
    }


def cross_node_phase() -> dict[str, Any]:
    worker = unique("cross-node-worker")
    registration = register_worker(worker)
    started = start_workflow(unique("cross-node"))
    task = poll_task(worker, SERVER_A)
    require(task.get("workflow_id") == started["workflow_id"], f"wrong task claimed: {task}")
    status, completed, elapsed = complete_task(task, SERVER_B)
    require(status in (200, 202) and completed.get("recorded") is True, f"cross-node completion failed: {status} {completed}")
    show_status, shown, _ = describe(started["workflow_id"], started["run_id"])
    require(show_status == 200 and shown.get("status") == "completed", f"cross-node workflow not complete: {shown}")
    identity = {
        **started,
        "task_id": task["task_id"],
        "claim_node": "server-a",
        "completion_node": "server-b",
        "lease_owner": task["lease_owner"],
    }
    RESULT["identities"]["cross_node_workflow_completion"] = identity
    RESULT["loss_assertions"].append({"phase": "cross_node_workflow_completion", "acknowledged_state_present": True, "passed": True})
    return {"registration": registration, "identity": identity, "completion_ms": elapsed, "final_status": shown["status"]}


def api_node_loss_phase() -> dict[str, Any]:
    worker = unique("api-loss-worker")
    register_worker(worker)
    started = start_workflow(unique("api-loss"))
    task = poll_task(worker, SERVER_A)
    require(
        task.get("workflow_id") == started["workflow_id"]
        and task.get("run_id") == started["run_id"],
        f"wrong task claimed before API node loss: {task}",
    )
    phase_evidence = RESULT["phase_evidence"].setdefault("api_node_loss", {})
    phase_evidence["acknowledged_task"] = {
        "workflow_id": task.get("workflow_id"),
        "run_id": task.get("run_id"),
        "task_id": task.get("task_id"),
        "claim_node": "server-a",
    }
    loss_started = time.monotonic()
    compose("stop", "server-a", timeout=60)
    running_result = compose("ps", "--status", "running", "--services", check=False, timeout=30)
    running_services = running_result.stdout.splitlines()
    phase_evidence["topology_after_stop"] = {
        "running_services": running_services,
        "server_a_stopped": "server-a" not in running_services,
        "server_b_running": "server-b" in running_services,
        "load_balancer_running": "load-balancer" in running_services,
        "compose_ps": command_diagnostic(running_result),
    }
    require("server-a" not in running_services, f"server-a remained running after stop: {running_services}")
    require("server-b" in running_services, f"server-b was not running after server-a stop: {running_services}")
    require("load-balancer" in running_services, f"shared endpoint was not running after server-a stop: {running_services}")

    surviving_readiness = ready(SERVER_B)
    phase_evidence["surviving_node_readiness"] = surviving_readiness
    require(bool(surviving_readiness), f"server-b was not ready after server-a stop: {surviving_readiness}")
    survivor_evidence: dict[str, Any] = {}
    phase_evidence["survivor_traffic"] = survivor_evidence
    survivor = wait_for_survivor_traffic(
        started["workflow_id"],
        started["run_id"],
        BOUNDS["api_node_useful_traffic_seconds"],
        survivor_evidence,
    )
    recovery_ms = monotonic_ms(loss_started)
    status, completed, _ = complete_task(task, SERVER_B)
    phase_evidence["survivor_completion"] = {
        "http_status": status,
        "recorded": completed.get("recorded") is True,
        "completion_node": "server-b",
    }
    require(status in (200, 202) and completed.get("recorded") is True, f"survivor completion failed: {status} {completed}")
    show_status, shown, _ = describe(started["workflow_id"], started["run_id"], SERVER_B)
    final_observation = nonterminal_run_observation(
        show_status,
        shown,
        started["workflow_id"],
        started["run_id"],
    )
    final_summary = final_observation["response_summary"]
    final_description = {
        "http_status": show_status,
        "response_summary": final_summary,
    }
    phase_evidence["final_description"] = final_description
    require(
        show_status == 200
        and final_summary["workflow_id"] == started["workflow_id"]
        and final_summary["run_id"] == started["run_id"]
        and final_summary["raw_status"] == "completed"
        and final_summary["status_bucket"] == "completed"
        and final_summary["is_terminal"] is True,
        f"acknowledged state was not preserved: {final_observation}",
    )
    compose("start", "server-a")
    wait_for("server-a restart readiness", lambda: ready(SERVER_A), 30)
    RESULT["recovery_timings_ms"]["api_node_useful_traffic"] = recovery_ms
    RESULT["recovery_bounds"]["api_node_useful_traffic_seconds"]["passed"] = recovery_ms <= BOUNDS["api_node_useful_traffic_seconds"] * 1000
    RESULT["identities"]["api_node_loss"] = {
        **started,
        "task_id": task["task_id"],
        "lost_node": "server-a",
        "surviving_node": "server-b",
        "completion_node": "server-b",
    }
    RESULT["loss_assertions"].append({"phase": "api_node_loss", "acknowledged_state_present": True, "passed": True})
    return {
        "recovery_ms": recovery_ms,
        "lost_node_stopped": True,
        "shared_endpoint_reached_surviving_node": True,
        "survivor_response": survivor,
        "completion_node": "server-b",
        "final_description": final_description,
        "final_status": final_summary["raw_status"],
        "acknowledged_state_present": True,
    }


def database_interruption_phase() -> dict[str, Any]:
    worker = unique("database-worker")
    register_worker(worker)
    started = start_workflow(unique("database-interruption"))
    task = poll_task(worker, SERVER_A)
    require(
        task.get("workflow_id") == started["workflow_id"]
        and task.get("run_id") == started["run_id"],
        f"wrong task claimed before database interruption: {task}",
    )
    phase_evidence = RESULT["phase_evidence"].setdefault("database_interruption", {})
    phase_evidence["acknowledged_task"] = {
        "workflow_id": task.get("workflow_id"),
        "run_id": task.get("run_id"),
        "task_id": task.get("task_id"),
        "claim_node": "server-a",
    }
    compose("stop", "mysql", timeout=60)
    down_transitions = []
    phase_evidence["readiness_down"] = down_transitions
    for node, base in (("server-a", SERVER_A), ("server-b", SERVER_B)):
        observation = wait_for(f"database-down readiness on {node}", lambda base=base: ready(base, 503), 20)
        require(
            observation["body"].get("status") == "not_ready"
            and observation["body"].get("checks", {}).get("database", {}).get("status") == "unavailable",
            f"database readiness contract mismatch: {observation}",
        )
        transition = {"phase": "database_interruption", "node": node, "state": "not_ready", **observation}
        RESULT["readiness_transitions"].append(transition)
        down_transitions.append(transition)

    failed_id = unique("database-unacknowledged")
    failed_status, failed_body, _ = request(
        SERVER_B,
        "/api/workflows",
        method="POST",
        payload={"workflow_id": failed_id, "workflow_type": WORKFLOW_TYPE, "task_queue": TASK_QUEUE, "input": []},
        timeout=5,
    )
    phase_evidence["database_down_write"] = {
        "http_status": failed_status,
        "acknowledged": 200 <= failed_status < 300,
    }
    require(failed_status == 0 or failed_status >= 500, f"database-down write was unexpectedly acknowledged: {failed_status} {failed_body}")

    recovery_started = time.monotonic()
    compose("start", "mysql")
    recovered = []
    phase_evidence["readiness_recovered"] = recovered
    for node, base in (("server-a", SERVER_A), ("server-b", SERVER_B)):
        observation = wait_for(f"database recovery readiness on {node}", lambda base=base: ready(base), BOUNDS["database_ready_after_return_seconds"])
        require(
            observation["body"].get("status") == "ready"
            and observation["body"].get("checks", {}).get("database", {}).get("status") == "ok",
            f"database recovery readiness contract mismatch: {observation}",
        )
        transition = {"phase": "database_interruption", "node": node, "state": "ready", **observation}
        RESULT["readiness_transitions"].append(transition)
        recovered.append(transition)
    recovery_ms = monotonic_ms(recovery_started)
    show_status, shown, _ = describe(started["workflow_id"], started["run_id"], SERVER_B)
    post_recovery_description = nonterminal_run_observation(
        show_status,
        shown,
        started["workflow_id"],
        started["run_id"],
    )
    phase_evidence["post_recovery_description"] = post_recovery_description
    require(
        post_recovery_description["accepted"],
        "post-recovery run description did not satisfy the public nonterminal contract: "
        f"{post_recovery_description}",
    )
    status, completed, _ = complete_task(task, SERVER_B)
    completion = {
        "http_status": status,
        "recorded": completed.get("recorded") is True,
        "completion_node": "server-b",
    }
    phase_evidence["completion"] = completion
    require(status in (200, 202) and completed.get("recorded") is True, f"post-database completion failed: {status} {completed}")
    duplicate_status, duplicate_body, _ = complete_task(task, SERVER_A)
    phase_evidence["duplicate_completion"] = {
        "http_status": duplicate_status,
        "rejected": duplicate_status == 409,
        "completion_node": "server-a",
    }
    require(duplicate_status == 409, f"duplicate completion was not refused: {duplicate_status} {duplicate_body}")
    final_status, final, _ = describe(started["workflow_id"], started["run_id"], SERVER_A)
    final_description = {
        "http_status": final_status,
        "response_summary": redacted_run_summary(final),
    }
    phase_evidence["final_description"] = final_description
    final_summary = final_description["response_summary"]
    require(
        final_status == 200
        and final_summary["workflow_id"] == started["workflow_id"]
        and final_summary["run_id"] == started["run_id"]
        and final_summary["raw_status"] == "completed"
        and final_summary["status_bucket"] == "completed"
        and final_summary["is_terminal"] is True,
        f"database workflow did not complete exactly once: {final_description}",
    )
    RESULT["recovery_timings_ms"]["database_ready_after_return"] = recovery_ms
    RESULT["recovery_bounds"]["database_ready_after_return_seconds"]["passed"] = recovery_ms <= BOUNDS["database_ready_after_return_seconds"] * 1000
    RESULT["identities"]["database_interruption"] = {**started, "task_id": task["task_id"]}
    RESULT["duplicate_assertions"].append({"phase": "database_interruption", "duplicate_completion_http_status": duplicate_status, "passed": True})
    RESULT["loss_assertions"].append({"phase": "database_interruption", "acknowledged_state_present": True, "unacknowledged_write_present": False, "passed": True})
    return {
        "readiness_down": down_transitions,
        "readiness_recovered": recovered,
        "failed_write_status": failed_status,
        "recovery_ms": recovery_ms,
        "workflow_id": started["workflow_id"],
        "run_id": started["run_id"],
        "task_id": task["task_id"],
        "post_recovery_description": post_recovery_description,
        "completion": completion,
        "duplicate_completion_refused": True,
        "final_description": final_description,
        "final_status": final_summary["raw_status"],
        "acknowledged_state_present": True,
    }


def timed_discovery(worker_id: str, workflow_prefix: str) -> tuple[dict[str, Any], dict[str, Any], int, str]:
    poll_request_id = unique(f"{workflow_prefix}-poll")
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
        future = executor.submit(poll_task, worker_id, SERVER_A, 15, poll_request_id)
        time.sleep(0.5)
        started = start_workflow(unique(workflow_prefix), SERVER_B)
        acknowledged_at = time.monotonic()
        task = future.result(timeout=16)
        discovery_ms = monotonic_ms(acknowledged_at)
    require(task.get("workflow_id") == started["workflow_id"], f"discovery claimed wrong workflow: {task}")
    return started, task, discovery_ms, poll_request_id


def redis_interruption_phase() -> dict[str, Any]:
    degraded_worker = unique("redis-degraded-worker")
    recovered_worker = unique("redis-recovered-worker")
    register_worker(degraded_worker)
    register_worker(recovered_worker)
    compose("stop", "redis", timeout=60)
    warnings = []
    for node, base in (("server-a", SERVER_A), ("server-b", SERVER_B)):
        observation = wait_for(f"Redis warning readiness on {node}", lambda base=base: ready(base), 15)
        cache = observation["body"].get("checks", {}).get("cache", {})
        require(cache.get("status") == "warning", f"Redis loss did not report a readiness warning: {observation}")
        require(cache.get("degraded_capability") == "long_poll_wake_acceleration", f"Redis warning did not name acceleration loss: {observation}")
        transition = {"phase": "redis_interruption", "node": node, "state": "ready_with_warning", **observation}
        RESULT["readiness_transitions"].append(transition)
        warnings.append(transition)

    degraded_started, degraded_task, degraded_ms, degraded_poll_request_id = timed_discovery(degraded_worker, "redis-degraded")
    duplicate_status, duplicate_body, _ = request(
        SERVER_B,
        "/api/worker/workflow-tasks/poll",
        method="POST",
        payload={
            "worker_id": degraded_worker,
            "task_queue": TASK_QUEUE,
            "poll_request_id": degraded_poll_request_id,
        },
        worker=True,
        timeout=5,
    )
    require(duplicate_status in (200, 204), f"Redis-degraded duplicate poll failed: {duplicate_status} {duplicate_body}")
    replayed_task = duplicate_body.get("task")
    require(isinstance(replayed_task, dict), f"Redis-degraded request ID did not replay its durable lease: {duplicate_body}")
    require(replayed_task.get("task_id") == degraded_task["task_id"], f"Redis-degraded request ID produced a duplicate lease: {duplicate_body}")
    require(replayed_task.get("workflow_task_attempt") == degraded_task.get("workflow_task_attempt"), f"Redis-degraded request ID changed the lease attempt: {duplicate_body}")
    status, completed, _ = complete_task(degraded_task, SERVER_B)
    require(status in (200, 202) and completed.get("recorded") is True, f"Redis-degraded durable completion failed: {status} {completed}")

    redis_returned = time.monotonic()
    compose("start", "redis")
    recovered_readiness = []
    for node, base in (("server-a", SERVER_A), ("server-b", SERVER_B)):
        observation = wait_for(
            f"Redis recovery readiness on {node}",
            lambda base=base: cache_ready(base),
            BOUNDS["redis_ready_after_return_seconds"],
        )
        transition = {"phase": "redis_interruption", "node": node, "state": "ready", **observation}
        RESULT["readiness_transitions"].append(transition)
        recovered_readiness.append(transition)
    redis_ready_ms = monotonic_ms(redis_returned)
    recovered_started, recovered_task, recovered_ms, _ = timed_discovery(recovered_worker, "redis-recovered")
    status, completed, _ = complete_task(recovered_task, SERVER_A)
    require(status in (200, 202) and completed.get("recorded") is True, f"Redis-recovered completion failed: {status} {completed}")
    require(degraded_ms <= BOUNDS["redis_poll_discovery_seconds"] * 1000, f"Redis-degraded poll discovery exceeded bound: {degraded_ms}ms")
    require(recovered_ms <= BOUNDS["redis_recovered_poll_discovery_seconds"] * 1000, f"Redis-recovered poll discovery exceeded bound: {recovered_ms}ms")
    require(redis_ready_ms <= BOUNDS["redis_ready_after_return_seconds"] * 1000, f"Redis readiness recovery exceeded bound: {redis_ready_ms}ms")
    RESULT["recovery_timings_ms"].update({"redis_degraded_poll_discovery": degraded_ms, "redis_ready_after_return": redis_ready_ms, "redis_recovered_poll_discovery": recovered_ms})
    RESULT["recovery_bounds"]["redis_poll_discovery_seconds"]["passed"] = True
    RESULT["recovery_bounds"]["redis_recovered_poll_discovery_seconds"]["passed"] = True
    RESULT["recovery_bounds"]["redis_ready_after_return_seconds"]["passed"] = True
    RESULT["identities"]["redis_interruption"] = {
        "degraded": {**degraded_started, "task_id": degraded_task["task_id"], "poll_request_id": degraded_poll_request_id},
        "recovered": {**recovered_started, "task_id": recovered_task["task_id"]},
    }
    RESULT["loss_assertions"].append({"phase": "redis_interruption", "durable_state_preserved": True, "passed": True})
    RESULT["duplicate_assertions"].append({"phase": "redis_interruption", "poll_request_id": degraded_poll_request_id, "replayed_task_id": replayed_task["task_id"], "duplicate_lease_observed": False, "passed": True})
    return {"readiness_warnings": warnings, "readiness_recovered": recovered_readiness, "degraded_poll_discovery_ms": degraded_ms, "redis_ready_after_return_ms": redis_ready_ms, "recovered_poll_discovery_ms": recovered_ms, "request_id_replayed_task_id": replayed_task["task_id"], "request_id_duplicate_lease_observed": False, "durable_state_preserved": True}


def worker_lease_loss_phase() -> dict[str, Any]:
    lost_worker = unique("lost-worker")
    recovery_worker = unique("recovery-worker")
    register_worker(lost_worker)
    register_worker(recovery_worker)
    started = start_workflow(unique("worker-loss"))
    task = poll_task(lost_worker, SERVER_A)
    lease_lost_at = time.monotonic()
    status, open_run, _ = describe(started["workflow_id"], started["run_id"], SERVER_B)
    require(status == 200 and open_run.get("status") == "running", f"leased run state changed after worker loss: {open_run}")
    recovered_task = poll_task(
        recovery_worker,
        SERVER_B,
        BOUNDS["workflow_task_lease_seconds"] + BOUNDS["worker_repair_after_lease_seconds"],
    )
    recovery_ms = monotonic_ms(lease_lost_at)
    require(recovered_task.get("workflow_id") == started["workflow_id"], f"wrong task recovered: {recovered_task}")
    require(recovered_task.get("lease_owner") != task.get("lease_owner"), "worker-loss task retained the lost lease owner")
    require(
        recovery_ms >= (BOUNDS["workflow_task_lease_seconds"] * 1000) - 500,
        f"worker-loss task was reclaimed before lease expiry: {recovery_ms}ms",
    )
    status, completed, _ = complete_task(recovered_task, SERVER_A)
    require(status in (200, 202) and completed.get("recorded") is True, f"recovered task completion failed: {status} {completed}")
    _, final, _ = describe(started["workflow_id"], started["run_id"], LB)
    require(final.get("status") == "completed", f"worker-loss run not completed: {final}")
    bound_ms = (BOUNDS["workflow_task_lease_seconds"] + BOUNDS["worker_repair_after_lease_seconds"]) * 1000
    require(recovery_ms <= bound_ms, f"worker recovery exceeded lease/repair bound: {recovery_ms}ms")
    RESULT["recovery_timings_ms"]["worker_after_lease"] = recovery_ms
    RESULT["recovery_bounds"]["workflow_task_lease_seconds"]["passed"] = True
    RESULT["recovery_bounds"]["worker_repair_after_lease_seconds"]["passed"] = True
    RESULT["identities"]["worker_lease_loss"] = {
        **started,
        "task_id": task["task_id"],
        "initial_lease_owner": task["lease_owner"],
        "recovery_task_id": recovered_task["task_id"],
        "recovery_lease_owner": recovered_task["lease_owner"],
        "lease_expires_at": task.get("lease_expires_at"),
    }
    RESULT["duplicate_assertions"].append({"phase": "worker_lease_loss", "logical_completion_count": 1, "passed": True})
    RESULT["loss_assertions"].append({"phase": "worker_lease_loss", "run_state_preserved_while_leased": True, "workflow_mutated_for_recovery": False, "passed": True})
    return {"recovery_ms": recovery_ms, "initial_task_id": task["task_id"], "recovery_task_id": recovered_task["task_id"], "final_status": final["status"]}


def schedule_history(schedule_id: str) -> list[dict[str, Any]] | None:
    status, body, _ = request(LB, f"/api/schedules/{schedule_id}/history")
    return body.get("events") if status == 200 and isinstance(body.get("events"), list) else None


def triggered_events(schedule_id: str) -> list[dict[str, Any]]:
    events = schedule_history(schedule_id) or []
    return [event for event in events if event.get("workflow_run_id")]


def scheduler_restart_phase() -> dict[str, Any]:
    compose("stop", "scheduler", timeout=60)
    running = compose("ps", "--status", "running", "--services").stdout.splitlines()
    require("scheduler" not in running, f"scheduler did not stop: {running}")
    schedule_id = unique("scheduler-restart")
    status, body, _ = request(
        LB,
        "/api/schedules",
        method="POST",
        payload={
            "schedule_id": schedule_id,
            "spec": {"intervals": [{"every": "PT5S", "offset": "PT5S"}]},
            "action": {"workflow_type": WORKFLOW_TYPE, "task_queue": TASK_QUEUE, "input": ["scheduler-restart"]},
            "overlap_policy": "skip",
            "max_runs": 1,
        },
    )
    require(status == 201 and body.get("outcome") == "created", f"schedule creation failed: {status} {body}")
    time.sleep(7)
    while_stopped = triggered_events(schedule_id)
    require(len(while_stopped) == 0, f"schedule fired with scheduler stopped: {while_stopped}")
    restarted_at = time.monotonic()
    compose("start", "scheduler")

    def one_fire() -> list[dict[str, Any]] | None:
        events = triggered_events(schedule_id)
        return events if len(events) >= 1 else None

    fired = wait_for("durable schedule fire after scheduler restart", one_fire, BOUNDS["scheduler_fire_after_restart_seconds"])
    recovery_ms = monotonic_ms(restarted_at)
    running = compose("ps", "--status", "running", "--services").stdout.splitlines()
    require(running.count("scheduler") == 1, f"scheduler restart did not preserve singleton topology: {running}")
    compose("stop", "scheduler", timeout=60)
    time.sleep(1)
    final_fires = triggered_events(schedule_id)
    require(len(final_fires) == 1, f"scheduler restart produced duplicate fires: {final_fires}")
    event = final_fires[0]
    require(event.get("workflow_instance_id") and event.get("workflow_run_id"), f"schedule fire omitted workflow identity: {event}")
    RESULT["recovery_timings_ms"]["scheduler_fire_after_restart"] = recovery_ms
    RESULT["recovery_bounds"]["scheduler_fire_after_restart_seconds"]["passed"] = recovery_ms <= BOUNDS["scheduler_fire_after_restart_seconds"] * 1000
    RESULT["identities"]["singleton_scheduler_restart"] = {
        "schedule_id": schedule_id,
        "workflow_id": event["workflow_instance_id"],
        "run_id": event["workflow_run_id"],
    }
    RESULT["duplicate_assertions"].append({"phase": "singleton_scheduler_restart", "fires_while_stopped": 0, "fires_after_restart": 1, "passed": True})
    return {"schedule_id": schedule_id, "scheduler_instances": 1, "fires_while_stopped": 0, "fires_after_restart": len(fired), "recovery_ms": recovery_ms, "workflow_id": event["workflow_instance_id"], "run_id": event["workflow_run_id"]}


def write_result() -> None:
    RESULT_DIR.mkdir(parents=True, exist_ok=True)
    RESULT_PATH.write_text(json.dumps(RESULT, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def main() -> int:
    active_phase = "published_artifact_provenance"
    stack_started = False
    try:
        run_phase(active_phase, provenance_phase)
        active_phase = "topology_start"
        stack_started = True
        run_phase(active_phase, start_topology)
        active_phase = "cross_node_workflow_completion"
        run_phase(active_phase, cross_node_phase)
        active_phase = "api_node_loss"
        run_phase(active_phase, api_node_loss_phase)
        active_phase = "database_interruption"
        run_phase(active_phase, database_interruption_phase)
        active_phase = "redis_interruption"
        run_phase(active_phase, redis_interruption_phase)
        active_phase = "worker_lease_loss"
        run_phase(active_phase, worker_lease_loss_phase)
        active_phase = "singleton_scheduler_restart"
        run_phase(active_phase, scheduler_restart_phase)
        require_passing_result()
        RESULT["outcome"] = "pass"
        return 0
    except Exception as error:  # evidence must survive every bounded failure
        failure = {
            "status": "fail",
            "reason": str(error),
            "error_type": type(error).__name__,
        }
        if active_phase == "topology_start":
            failure.update(TOPOLOGY_DIAGNOSTICS)
        elif active_phase == "api_node_loss":
            failure.update(api_node_loss_failure_diagnostics())
            failure["phase_evidence"] = RESULT["phase_evidence"].get("api_node_loss", {})
        elif active_phase == "database_interruption":
            failure["phase_evidence"] = RESULT["phase_evidence"].get("database_interruption", {})
        RESULT["phase_outcomes"][active_phase] = failure
        RESULT["outcome"] = "fail"
        return 1
    finally:
        if stack_started and not KEEP_STACK:
            compose("down", "-v", "--remove-orphans", check=False, timeout=180)
        RESULT["finished_at"] = now()
        write_result()
        print(f"Single-region failover outcome: {RESULT['outcome']}")
        print(f"Result: {RESULT_PATH}")


if __name__ == "__main__":
    raise SystemExit(main())
