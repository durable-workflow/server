#!/usr/bin/env python3
"""Exact-artifact single-region failover rehearsal.

This file is orchestration only. Product runtime behavior is supplied by the
immutable public server image selected by the shell handoff.
"""

from __future__ import annotations

import concurrent.futures
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import time
from typing import Any, Callable, Iterable
import urllib.error
import urllib.request


SCHEMA = "durable-workflow.v2.single-region-failover.result"
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

LB = f"http://127.0.0.1:{os.environ.get('DW_FAILOVER_LB_PORT', '18086')}"
SERVER_A = f"http://127.0.0.1:{os.environ.get('DW_FAILOVER_SERVER_A_PORT', '18084')}"
SERVER_B = f"http://127.0.0.1:{os.environ.get('DW_FAILOVER_SERVER_B_PORT', '18085')}"

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


def ready(base: str, expected_status: int = 200) -> dict[str, Any] | None:
    status, body, elapsed = request(base, "/api/ready", authenticated=False, timeout=3)
    if status == expected_status:
        return {"http_status": status, "body": body, "request_ms": elapsed, "observed_at": now()}
    return None


def cache_ready(base: str) -> dict[str, Any] | None:
    observation = ready(base)
    if observation is None:
        return None
    if observation["body"].get("checks", {}).get("cache", {}).get("status") != "ok":
        return None
    return observation


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
    compose("up", "-d", "--wait", "--wait-timeout", "180", timeout=240)
    for base in (SERVER_A, SERVER_B, LB):
        wait_for(f"readiness at {base}", lambda base=base: ready(base), 30)
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
    RESULT["artifacts"]["server_reported_version"] = cluster.get("version")
    return {"running_services": sorted(running), "cluster_info_contract_version": contract.get("version")}


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
    loss_started = time.monotonic()
    compose("stop", "server-a", timeout=60)

    def survivor_traffic() -> dict[str, Any] | None:
        status, body, _ = describe(started["workflow_id"], started["run_id"], LB)
        return body if status == 200 and body.get("status") == "running" else None

    wait_for("useful shared-endpoint traffic after API node loss", survivor_traffic, BOUNDS["api_node_useful_traffic_seconds"])
    recovery_ms = monotonic_ms(loss_started)
    status, completed, _ = complete_task(task, SERVER_B)
    require(status in (200, 202) and completed.get("recorded") is True, f"survivor completion failed: {status} {completed}")
    show_status, shown, _ = describe(started["workflow_id"], started["run_id"], SERVER_B)
    require(show_status == 200 and shown.get("status") == "completed", f"acknowledged state was not preserved: {shown}")
    compose("start", "server-a")
    wait_for("server-a restart readiness", lambda: ready(SERVER_A), 30)
    RESULT["recovery_timings_ms"]["api_node_useful_traffic"] = recovery_ms
    RESULT["recovery_bounds"]["api_node_useful_traffic_seconds"]["passed"] = recovery_ms <= BOUNDS["api_node_useful_traffic_seconds"] * 1000
    RESULT["identities"]["api_node_loss"] = {**started, "task_id": task["task_id"], "lost_node": "server-a", "surviving_node": "server-b"}
    RESULT["loss_assertions"].append({"phase": "api_node_loss", "acknowledged_state_present": True, "passed": True})
    return {"recovery_ms": recovery_ms, "final_status": shown["status"], "acknowledged_state_present": True}


def database_interruption_phase() -> dict[str, Any]:
    worker = unique("database-worker")
    register_worker(worker)
    started = start_workflow(unique("database-interruption"))
    task = poll_task(worker, SERVER_A)
    compose("stop", "mysql", timeout=60)
    down_transitions = []
    for node, base in (("server-a", SERVER_A), ("server-b", SERVER_B)):
        observation = wait_for(f"database-down readiness on {node}", lambda base=base: ready(base, 503), 20)
        require(observation["body"].get("checks", {}).get("database", {}).get("status") == "unavailable", f"database readiness contract mismatch: {observation}")
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
    require(failed_status == 0 or failed_status >= 500, f"database-down write was unexpectedly acknowledged: {failed_status} {failed_body}")

    recovery_started = time.monotonic()
    compose("start", "mysql")
    recovered = []
    for node, base in (("server-a", SERVER_A), ("server-b", SERVER_B)):
        observation = wait_for(f"database recovery readiness on {node}", lambda base=base: ready(base), BOUNDS["database_ready_after_return_seconds"])
        transition = {"phase": "database_interruption", "node": node, "state": "ready", **observation}
        RESULT["readiness_transitions"].append(transition)
        recovered.append(transition)
    recovery_ms = monotonic_ms(recovery_started)
    show_status, shown, _ = describe(started["workflow_id"], started["run_id"], SERVER_B)
    require(show_status == 200 and shown.get("status") == "running", f"acknowledged database state was lost: {shown}")
    status, completed, _ = complete_task(task, SERVER_B)
    require(status in (200, 202) and completed.get("recorded") is True, f"post-database completion failed: {status} {completed}")
    duplicate_status, duplicate_body, _ = complete_task(task, SERVER_A)
    require(duplicate_status == 409, f"duplicate completion was not refused: {duplicate_status} {duplicate_body}")
    _, final, _ = describe(started["workflow_id"], started["run_id"], SERVER_A)
    require(final.get("status") == "completed", f"database workflow did not complete: {final}")
    RESULT["recovery_timings_ms"]["database_ready_after_return"] = recovery_ms
    RESULT["recovery_bounds"]["database_ready_after_return_seconds"]["passed"] = recovery_ms <= BOUNDS["database_ready_after_return_seconds"] * 1000
    RESULT["identities"]["database_interruption"] = {**started, "task_id": task["task_id"]}
    RESULT["duplicate_assertions"].append({"phase": "database_interruption", "duplicate_completion_http_status": duplicate_status, "passed": True})
    RESULT["loss_assertions"].append({"phase": "database_interruption", "acknowledged_state_present": True, "unacknowledged_write_present": False, "passed": True})
    return {"readiness_down": down_transitions, "readiness_recovered": recovered, "failed_write_status": failed_status, "recovery_ms": recovery_ms, "duplicate_completion_refused": True}


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
        RESULT["phase_outcomes"][active_phase] = {
            "status": "fail",
            "reason": str(error),
            "error_type": type(error).__name__,
        }
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
