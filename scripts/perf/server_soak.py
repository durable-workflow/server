#!/usr/bin/env python3
"""Run bounded-growth worker-poll and public-health stress profiles."""

from __future__ import annotations

import argparse
import fnmatch
import hashlib
import json
import math
import os
import random
import re
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
from collections import Counter
from concurrent.futures import ThreadPoolExecutor, wait
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any


CONTROL_PLANE_VERSION = os.environ.get("DW_PERF_CONTROL_PLANE_VERSION", "2")
WORKER_PROTOCOL_VERSION = os.environ.get("DW_PERF_WORKER_PROTOCOL_VERSION", "1.2")
ERROR_WRITE_LOCK = threading.Lock()
SERVER_CACHE_KEY_PATTERNS = {
    "polling_cache_availability_probe": "*server:polling-cache:*",
    "long_poll_signals": "*server:long-poll-signal:*",
    "workflow_task_poll_requests": "*server:workflow-task-poll-request:*",
    "activity_task_poll_requests": "*server:activity-task-poll-request:*",
    "query_task_poll_requests": "*server:query-task-poll-request:*",
    "long_poll_wait_slots": "*server:long-poll-wait-slot:*",
    "sqlite_worker_poll_claim_gate": "*server:sqlite-worker-poll-claim:*",
    "workflow_query_tasks": "*server:workflow-query-task:*",
    "task_queue_admission_locks": "*server:task-queue-admission:*",
    "task_queue_dispatch_counters": "*server:task-queue-dispatch:*",
    "namespace_request_admission": "*server:namespace-request-admission:*",
    "shared_service_boundary_admission": "*server:service-boundary:*",
    "namespace_durable_state_quota_rejections": "*server:namespace-durable-state:*",
    "runtime_external_payload_quota_rejections": "*server:external-payload-quota:*",
    "workflow_task_expired_lease_recovery": "*server:workflow-task-expired-lease-recovery:*",
    "history_retention_inline": "*server:history-retention-inline:*",
    "worker_compatibility_heartbeat": "*server:worker-compatibility-heartbeat:*",
    "readiness_probe": "*server:readiness:*",
}


def env_bool(name: str, default: bool = False) -> bool:
    value = os.environ.get(name)
    if value is None:
        return default

    return value.strip().lower() in ("1", "true", "yes", "on")


def redis_cache_database() -> int:
    raw = os.environ.get("DW_PERF_REDIS_CACHE_DB", "1").strip()
    if not re.fullmatch(r"\d+", raw):
        raise ValueError("DW_PERF_REDIS_CACHE_DB must be an integer from 0 through 15")

    database = int(raw)
    if database > 15:
        raise ValueError("DW_PERF_REDIS_CACHE_DB must be an integer from 0 through 15")

    return database


def runner_environment() -> str:
    return os.environ.get("DW_PERF_RUNNER_ENVIRONMENT") or os.environ.get("RUNNER_ENVIRONMENT", "")


class Metrics:
    def __init__(self) -> None:
        self.lock = threading.Lock()
        self.requests = Counter()
        self.errors = 0
        self.completed = 0
        self.latency_sum = 0.0
        self.latest = {
            "server_memory_bytes": 0,
            "redis_memory_bytes": 0,
            "redis_db_keys": 0,
            "redis_polling_keys": 0,
            "redis_server_keys": 0,
            "redis_server_keys_by_policy": {policy_id: 0 for policy_id in SERVER_CACHE_KEY_PATTERNS},
            "assertion_failed": 0,
        }

    def record_request(self, status: int, latency: float) -> None:
        with self.lock:
            self.requests[str(status)] += 1
            self.completed += 1
            self.latency_sum += latency

    def record_error(self) -> None:
        with self.lock:
            self.errors += 1

    def update_sample(self, sample: dict[str, Any]) -> None:
        with self.lock:
            self.latest["server_memory_bytes"] = int(sample.get("server_memory_bytes") or 0)
            self.latest["redis_memory_bytes"] = int(sample.get("redis_used_memory_bytes") or 0)
            self.latest["redis_db_keys"] = int(sample.get("redis_db_keys") or 0)
            self.latest["redis_polling_keys"] = int(sample.get("redis_polling_keys") or 0)
            self.latest["redis_server_keys"] = int(sample.get("redis_server_keys") or 0)
            by_policy = sample.get("redis_server_keys_by_policy")
            if isinstance(by_policy, dict):
                self.latest["redis_server_keys_by_policy"] = {
                    policy_id: int(by_policy.get(policy_id) or 0)
                    for policy_id in SERVER_CACHE_KEY_PATTERNS
                }

    def mark_assertion_failed(self) -> None:
        with self.lock:
            self.latest["assertion_failed"] = 1

    def prometheus(self) -> str:
        with self.lock:
            lines = [
                "# HELP dw_perf_requests_total Worker poll requests by HTTP status.",
                "# TYPE dw_perf_requests_total counter",
            ]
            for status, count in sorted(self.requests.items()):
                lines.append(f'dw_perf_requests_total{{status="{status}"}} {count}')

            average_latency = self.latency_sum / self.completed if self.completed else 0.0
            lines.extend(
                [
                    "# HELP dw_perf_errors_total Load generator exceptions.",
                    "# TYPE dw_perf_errors_total counter",
                    f"dw_perf_errors_total {self.errors}",
                    "# HELP dw_perf_latency_seconds_average Average request latency.",
                    "# TYPE dw_perf_latency_seconds_average gauge",
                    f"dw_perf_latency_seconds_average {average_latency:.6f}",
                    "# HELP dw_perf_server_memory_bytes Sampled server container memory.",
                    "# TYPE dw_perf_server_memory_bytes gauge",
                    f"dw_perf_server_memory_bytes {self.latest['server_memory_bytes']}",
                    "# HELP dw_perf_redis_memory_bytes Redis used_memory from INFO memory.",
                    "# TYPE dw_perf_redis_memory_bytes gauge",
                    f"dw_perf_redis_memory_bytes {self.latest['redis_memory_bytes']}",
                    "# HELP dw_perf_redis_polling_keys Redis keys matching the polling cache pattern.",
                    "# TYPE dw_perf_redis_polling_keys gauge",
                    f"dw_perf_redis_polling_keys {self.latest['redis_polling_keys']}",
                    "# HELP dw_perf_redis_server_keys Redis keys owned by the Durable Workflow server cache namespace.",
                    "# TYPE dw_perf_redis_server_keys gauge",
                    f"dw_perf_redis_server_keys {self.latest['redis_server_keys']}",
                    "# HELP dw_perf_redis_server_keys_by_policy Redis keys owned by the Durable Workflow server cache namespace by bounded-growth policy.",
                    "# TYPE dw_perf_redis_server_keys_by_policy gauge",
                    *[
                        f'dw_perf_redis_server_keys_by_policy{{policy="{policy_id}"}} {count}'
                        for policy_id, count in sorted(self.latest["redis_server_keys_by_policy"].items())
                    ],
                    "# HELP dw_perf_redis_db_keys Redis cache database DBSIZE count.",
                    "# TYPE dw_perf_redis_db_keys gauge",
                    f"dw_perf_redis_db_keys {self.latest['redis_db_keys']}",
                    "# HELP dw_perf_assertion_failed Whether the harness failed an assertion.",
                    "# TYPE dw_perf_assertion_failed gauge",
                    f"dw_perf_assertion_failed {self.latest['assertion_failed']}",
                    "",
                ]
            )

        return "\n".join(lines)


class EndpointMetrics:
    def __init__(self) -> None:
        self.lock = threading.Lock()
        self.endpoints: dict[str, dict[str, Any]] = {}

    def record(self, endpoint: str, status: int, latency: float, *, backpressured: bool = False) -> None:
        with self.lock:
            entry = self.endpoints.setdefault(
                endpoint,
                {"requests": 0, "errors": 0, "statuses": Counter(), "latencies": [], "backpressured": 0},
            )
            entry["requests"] += 1
            entry["statuses"][str(status)] += 1
            entry["latencies"].append(latency)
            if backpressured:
                entry["backpressured"] = int(entry.get("backpressured", 0)) + 1

    def record_error(self, endpoint: str, latency: float) -> None:
        with self.lock:
            entry = self.endpoints.setdefault(
                endpoint,
                {"requests": 0, "errors": 0, "statuses": Counter(), "latencies": []},
            )
            entry["requests"] += 1
            entry["errors"] += 1
            entry["latencies"].append(latency)

    def snapshot(self) -> dict[str, Any]:
        with self.lock:
            snapshot: dict[str, Any] = {}
            for endpoint, entry in self.endpoints.items():
                latencies = sorted(float(value) for value in entry["latencies"])
                successful = sum(
                    count
                    for status, count in entry["statuses"].items()
                    if 200 <= int(status) < 300
                )
                http_backpressured = (
                    int(entry["statuses"].get("429", 0))
                    if endpoint == "worker_poll"
                    else 0
                )
                backpressured = int(entry.get("backpressured", 0)) + http_backpressured
                available = successful + http_backpressured
                requests = int(entry["requests"])
                snapshot[endpoint] = {
                    "requests": requests,
                    "successful": successful,
                    "backpressured": backpressured,
                    "available": available,
                    "errors": int(entry["errors"]),
                    "availability": 0.0 if requests == 0 else round(available / requests, 6),
                    "statuses": dict(entry["statuses"]),
                    "latency_seconds": {
                        "average": 0.0 if not latencies else round(sum(latencies) / len(latencies), 6),
                        "p95": 0.0
                        if not latencies
                        else round(
                            latencies[min(len(latencies) - 1, math.ceil(len(latencies) * 0.95) - 1)],
                            6,
                        ),
                        "max": 0.0 if not latencies else round(latencies[-1], 6),
                    },
                }

            return snapshot


class MetricsHandler(BaseHTTPRequestHandler):
    metrics: Metrics

    def do_GET(self) -> None:  # noqa: N802
        if self.path != "/metrics":
            self.send_response(404)
            self.end_headers()
            return

        body = self.metrics.prometheus().encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "text/plain; version=0.0.4")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, _format: str, *args: Any) -> None:
        return


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default=os.environ.get("DW_PERF_BASE_URL", "http://127.0.0.1:18080"))
    parser.add_argument("--token", default=os.environ.get("DW_PERF_AUTH_TOKEN", "perf-token"))
    parser.add_argument("--duration-seconds", type=int, default=int(os.environ.get("DW_PERF_DURATION_SECONDS", "120")))
    parser.add_argument("--concurrency", type=int, default=int(os.environ.get("DW_PERF_CONCURRENCY", "8")))
    parser.add_argument("--namespaces", type=int, default=int(os.environ.get("DW_PERF_NAMESPACES", "4")))
    parser.add_argument("--task-queues", type=int, default=int(os.environ.get("DW_PERF_TASK_QUEUES", "8")))
    parser.add_argument("--sample-interval-seconds", type=float, default=float(os.environ.get("DW_PERF_SAMPLE_INTERVAL_SECONDS", "5")))
    parser.add_argument("--poll-timeout-seconds", type=int, default=int(os.environ.get("DW_PERF_POLL_TIMEOUT", "1")))
    parser.add_argument("--workflow-runs", type=int, default=int(os.environ.get("DW_PERF_WORKFLOW_RUNS", "0")))
    parser.add_argument("--start-concurrency", type=int, default=int(os.environ.get("DW_PERF_START_CONCURRENCY", "8")))
    parser.add_argument(
        "--min-workflow-completion-ratio",
        type=float,
        default=float(os.environ.get("DW_PERF_MIN_WORKFLOW_COMPLETION_RATIO", "0.98")),
        help=(
            "Minimum successful workflow starts as a fraction of --workflow-runs. "
            "This keeps cache-growth coverage independent of shared-runner throughput; "
            "request errors and availability loss still fail the run."
        ),
    )
    parser.add_argument(
        "--health-interval-seconds",
        type=float,
        default=float(os.environ.get("DW_PERF_HEALTH_INTERVAL_SECONDS", "0.5")),
    )
    parser.add_argument(
        "--max-health-latency-seconds",
        type=float,
        default=float(os.environ.get("DW_PERF_MAX_HEALTH_LATENCY_SECONDS", "3")),
    )
    parser.add_argument(
        "--control-plane-interval-seconds",
        type=float,
        default=float(os.environ.get("DW_PERF_CONTROL_PLANE_INTERVAL_SECONDS", "5")),
    )
    parser.add_argument(
        "--max-control-plane-latency-seconds",
        type=float,
        default=float(os.environ.get("DW_PERF_MAX_CONTROL_PLANE_LATENCY_SECONDS", "5")),
    )
    parser.add_argument("--drain-seconds", type=int, default=int(os.environ.get("DW_PERF_DRAIN_SECONDS", "12")))
    parser.add_argument("--artifact-dir", default=os.environ.get("DW_PERF_ARTIFACT_DIR", "build/perf"))
    parser.add_argument("--compose-project", default=os.environ.get("DW_PERF_COMPOSE_PROJECT", ""))
    parser.add_argument("--metrics-port", type=int, default=int(os.environ.get("DW_PERF_METRICS_PORT", "19090")))
    parser.add_argument("--max-server-memory-mb", type=float, default=float(os.environ.get("DW_PERF_MAX_SERVER_MEMORY_MB", "768")))
    parser.add_argument("--max-polling-keys", type=int, default=int(os.environ.get("DW_PERF_MAX_POLLING_KEYS", "512")))
    parser.add_argument("--max-final-polling-keys", type=int, default=int(os.environ.get("DW_PERF_MAX_FINAL_POLLING_KEYS", "0")))
    parser.add_argument(
        "--max-server-cache-keys",
        type=int,
        default=int(os.environ.get("DW_PERF_MAX_SERVER_CACHE_KEYS", "1024")),
        help="Maximum server:* cache keys observed during the run.",
    )
    parser.add_argument(
        "--max-final-server-cache-keys",
        type=int,
        default=int(os.environ.get("DW_PERF_MAX_FINAL_SERVER_CACHE_KEYS", "0")),
        help="Maximum server:* cache keys allowed after the drain window.",
    )
    parser.add_argument(
        "--max-server-cache-keys-by-policy",
        default=os.environ.get("DW_PERF_MAX_SERVER_CACHE_KEYS_BY_POLICY", ""),
        help="JSON object of per-policy max cache key thresholds, keyed by cache policy ID.",
    )
    parser.add_argument(
        "--max-final-server-cache-keys-by-policy",
        default=os.environ.get("DW_PERF_MAX_FINAL_SERVER_CACHE_KEYS_BY_POLICY", ""),
        help="JSON object of per-policy post-drain cache key thresholds, keyed by cache policy ID.",
    )
    parser.add_argument(
        "--min-sample-coverage",
        type=float,
        default=float(os.environ.get("DW_PERF_MIN_SAMPLE_COVERAGE", "0.8")),
        help="Minimum fraction of expected periodic samples required before the run is trusted.",
    )
    parser.add_argument(
        "--max-server-memory-slope-mb-hour",
        type=float,
        default=float(os.environ.get("DW_PERF_MAX_SERVER_MEMORY_SLOPE_MB_HOUR", "0")),
        help="If positive and duration is at least 10 minutes, fail when post-warmup server memory slope exceeds this value.",
    )
    parser.add_argument(
        "--require-trusted-evidence",
        action="store_true",
        default=env_bool("DW_PERF_REQUIRE_TRUSTED_EVIDENCE"),
        help="Fail if the generated summary is not eligible for trusted_long_soak_v1 evidence.",
    )
    args = parser.parse_args()
    policy_ids = set(SERVER_CACHE_KEY_PATTERNS)
    args.max_server_cache_keys_by_policy = parse_policy_limit_map(
        args.max_server_cache_keys_by_policy,
        policy_ids,
        "DW_PERF_MAX_SERVER_CACHE_KEYS_BY_POLICY",
        parser,
    )
    args.max_final_server_cache_keys_by_policy = parse_policy_limit_map(
        args.max_final_server_cache_keys_by_policy,
        policy_ids,
        "DW_PERF_MAX_FINAL_SERVER_CACHE_KEYS_BY_POLICY",
        parser,
    )
    if not 0 < args.min_workflow_completion_ratio <= 1:
        parser.error("DW_PERF_MIN_WORKFLOW_COMPLETION_RATIO must be greater than 0 and at most 1.")

    return args


def parse_policy_limit_map(
    raw: str,
    policy_ids: set[str],
    source_name: str,
    parser: argparse.ArgumentParser,
) -> dict[str, int]:
    if raw.strip() == "":
        return {}

    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError as exc:
        parser.error(f"{source_name} must be a JSON object: {exc.msg}")

    if not isinstance(decoded, dict):
        parser.error(f"{source_name} must be a JSON object keyed by bounded-growth cache policy ID.")

    limits: dict[str, int] = {}
    for policy_id, limit in decoded.items():
        if policy_id not in policy_ids:
            allowed = ", ".join(sorted(policy_ids))
            parser.error(f"{source_name} contains unknown cache policy {policy_id!r}; allowed: {allowed}")

        if isinstance(limit, bool) or not isinstance(limit, int):
            parser.error(f"{source_name}.{policy_id} must be a non-negative integer.")

        if limit < 0:
            parser.error(f"{source_name}.{policy_id} must be a non-negative integer.")

        limits[policy_id] = limit

    missing_policy_ids = sorted(policy_ids - set(limits))
    if missing_policy_ids:
        missing = ", ".join(missing_policy_ids)
        parser.error(f"{source_name} is missing cache policy thresholds for: {missing}")

    return limits


def emit_progress(message: str) -> None:
    timestamp = datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")
    print(f"[server-soak] {timestamp} {message}", flush=True)


def http_json(
    method: str,
    url: str,
    headers: dict[str, str],
    payload: dict[str, Any] | None = None,
    timeout_seconds: float = 20,
) -> tuple[int, Any]:
    data = None if payload is None else json.dumps(payload).encode("utf-8")
    request = urllib.request.Request(url, data=data, method=method)
    for key, value in headers.items():
        request.add_header(key, value)
    if payload is not None:
        request.add_header("Content-Type", "application/json")
    request.add_header("Accept", "application/json")

    try:
        with urllib.request.urlopen(request, timeout=timeout_seconds) as response:
            body = response.read().decode("utf-8")
            return response.status, json.loads(body) if body else {}
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        try:
            decoded = json.loads(body) if body else {}
        except json.JSONDecodeError:
            decoded = {"raw": body}
        return exc.code, decoded


def auth_headers(token: str, namespace: str, worker: bool = False) -> dict[str, str]:
    headers = {
        "Authorization": f"Bearer {token}",
        "X-Namespace": namespace,
    }

    if worker:
        headers["X-Durable-Workflow-Protocol-Version"] = WORKER_PROTOCOL_VERSION
    else:
        headers["X-Durable-Workflow-Control-Plane-Version"] = CONTROL_PLANE_VERSION

    return headers


def wait_for_health(base_url: str, timeout_seconds: int = 120) -> None:
    deadline = time.monotonic() + timeout_seconds
    last_error = ""

    while time.monotonic() < deadline:
        try:
            status, body = http_json("GET", f"{base_url}/api/health", {})
            if status == 200 and body.get("status") == "serving":
                return
            last_error = f"HTTP {status}: {body}"
        except Exception as exc:  # noqa: BLE001
            last_error = repr(exc)
        time.sleep(2)

    raise RuntimeError(f"server did not become healthy within {timeout_seconds}s: {last_error}")


def create_namespaces(base_url: str, token: str, namespaces: list[str]) -> None:
    for namespace in namespaces:
        status, body = http_json(
            "POST",
            f"{base_url}/api/namespaces",
            auth_headers(token, "default"),
            {
                "name": namespace,
                "description": "CI perf namespace",
                "retention_days": 1,
            },
        )
        if status not in (201, 409):
            raise RuntimeError(f"failed to create namespace {namespace}: HTTP {status}: {body}")


PERF_WORKFLOW_TYPE = "perf.harness.workflow"


def register_workers(base_url: str, token: str, namespaces: list[str], queues: list[str]) -> list[tuple[str, str, str]]:
    workers: list[tuple[str, str, str]] = []
    for namespace in namespaces:
        for queue in queues:
            worker_id = f"perf-worker-{namespace}-{queue}"
            status, body = http_json(
                "POST",
                f"{base_url}/api/worker/register",
                auth_headers(token, namespace, worker=True),
                {
                    "worker_id": worker_id,
                    "task_queue": queue,
                    # Model a published remote SDK that requires successful
                    # empty poll responses under capacity backpressure.
                    "runtime": "python",
                    "sdk_version": "perf-harness",
                    "max_concurrent_workflow_tasks": 100,
                    # Workers must advertise at least one workflow type so
                    # the server treats them as workflow-task-eligible. A
                    # registration with no types short-circuits every poll
                    # at no_workflow_capability and the polling cache surface
                    # never runs — leaving the bounded-growth smoke without
                    # any observation of the path it asserts on.
                    "supported_workflow_types": [PERF_WORKFLOW_TYPE],
                },
            )
            if status not in (200, 201):
                raise RuntimeError(f"failed to register {worker_id}: HTTP {status}: {body}")
            workers.append((namespace, queue, worker_id))

    return workers


def run_command(command: list[str], timeout: int = 30) -> subprocess.CompletedProcess[str]:
    return subprocess.run(command, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout, check=False)


def command_output(command: list[str], timeout: int = 5) -> str:
    try:
        result = run_command(command, timeout=timeout)
    except Exception:  # noqa: BLE001
        return ""

    return result.stdout.strip() if result.returncode == 0 else ""


def tracked_working_tree_changes() -> list[str]:
    result = run_command(["git", "status", "--porcelain", "--untracked-files=no"], timeout=10)
    if result.returncode != 0:
        return ["git status failed"]

    return [line for line in result.stdout.splitlines() if line.strip()]


def file_sha256(path: Path) -> str:
    try:
        digest = hashlib.sha256()
        with path.open("rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)
        return digest.hexdigest()
    except OSError:
        return ""


def compose_command(project: str, *args: str) -> list[str]:
    return ["docker", "compose", "-p", project, *args]


def parse_bytes(value: str) -> int:
    value = value.strip()
    match = re.match(r"^([0-9.]+)\s*([KMGT]?i?B|B)$", value)
    if not match:
        return 0

    amount = float(match.group(1))
    unit = match.group(2)
    scale = {
        "B": 1,
        "KB": 1000,
        "MB": 1000**2,
        "GB": 1000**3,
        "TB": 1000**4,
        "KiB": 1024,
        "MiB": 1024**2,
        "GiB": 1024**3,
        "TiB": 1024**4,
    }[unit]
    return int(amount * scale)


def docker_stats(project: str) -> dict[str, int]:
    ids_by_service: dict[str, str] = {}
    for service in ("server", "mysql", "redis"):
        result = run_command(compose_command(project, "ps", "-q", service))
        container_id = result.stdout.strip()
        if container_id:
            ids_by_service[service] = container_id

    if set(ids_by_service) != {"server", "mysql", "redis"}:
        return {"docker_stats_ok": 0}

    result = run_command(["docker", "stats", "--no-stream", "--format", "{{json .}}", *ids_by_service.values()])
    memory_by_id: dict[str, int] = {}
    for line in result.stdout.splitlines():
        try:
            row = json.loads(line)
        except json.JSONDecodeError:
            continue
        mem_usage = str(row.get("MemUsage", "")).split("/", 1)[0].strip()
        row_id = str(row.get("ID", ""))
        memory = parse_bytes(mem_usage)
        memory_by_id[row_id] = memory
        memory_by_id[row_id[:12]] = memory

    stats = {f"{service}_memory_bytes": memory_by_id.get(container_id[:12], 0) for service, container_id in ids_by_service.items()}
    stats["docker_stats_ok"] = 1 if result.returncode == 0 and all(stats.values()) else 0
    health = command_output(
        ["docker", "inspect", "--format", "{{.State.Health.Status}}", ids_by_service["server"]],
    )
    stats["server_container_healthy"] = 1 if health == "healthy" else 0

    return stats


def redis_info(project: str) -> dict[str, int]:
    cache_database = redis_cache_database()
    used_memory = 0
    db_keys = 0
    server_keys = 0
    server_keys_by_policy = {policy_id: 0 for policy_id in SERVER_CACHE_KEY_PATTERNS}

    result = run_command(
        compose_command(project, "exec", "-T", "redis", "sh", "-lc", redis_sampling_script(cache_database))
    )
    redis_ok = result.returncode == 0
    seen_used_memory = False
    seen_dbsize = False

    for line in result.stdout.splitlines():
        if line.startswith("__used_memory__="):
            seen_used_memory = True
            parsed, ok = parse_int_field(line.split("=", 1)[1])
            used_memory = parsed
            redis_ok = redis_ok and ok
            continue

        if line.startswith("__dbsize__="):
            seen_dbsize = True
            parsed, ok = parse_int_field(line.split("=", 1)[1])
            db_keys = parsed
            redis_ok = redis_ok and ok
            continue

        if not line.startswith("__server_key__="):
            continue

        key = line.split("=", 1)[1]
        server_keys += 1
        for policy_id, pattern in SERVER_CACHE_KEY_PATTERNS.items():
            if fnmatch.fnmatchcase(key, pattern):
                server_keys_by_policy[policy_id] += 1

    redis_ok = redis_ok and seen_used_memory and seen_dbsize

    return {
        "redis_sample_ok": 1 if redis_ok else 0,
        "redis_cache_database": cache_database,
        "redis_used_memory_bytes": used_memory,
        "redis_db_keys": db_keys,
        "redis_polling_keys": server_keys_by_policy["workflow_task_poll_requests"],
        "redis_server_keys": server_keys,
        "redis_server_keys_by_policy": server_keys_by_policy,
    }


def redis_sampling_script(cache_database: int) -> str:
    return "\n".join(
        [
            "set -e",
            "used_memory=$(redis-cli INFO memory | sed -n 's/^used_memory://p' | tr -d '\\r' | head -n 1)",
            f"cache_database={cache_database}",
            "dbsize=$(redis-cli -n \"$cache_database\" DBSIZE)",
            "printf '__used_memory__=%s\\n' \"$used_memory\"",
            "printf '__dbsize__=%s\\n' \"$dbsize\"",
            "redis-cli -n \"$cache_database\" --scan --pattern '*server:*' | sed 's/^/__server_key__=/'",
        ]
    )


def parse_int_field(value: str) -> tuple[int, bool]:
    try:
        return int(value.strip() or "0"), True
    except ValueError:
        return 0, False


def mysql_counts(project: str) -> dict[str, int]:
    query = (
        "SELECT "
        "(SELECT COUNT(*) FROM workflow_namespaces) AS namespaces, "
        "(SELECT COUNT(*) FROM workflow_worker_registrations) AS workers, "
        "(SELECT COUNT(*) FROM workflow_runs) AS workflow_runs, "
        "(SELECT COUNT(*) FROM workflow_tasks WHERE status = 'ready') AS ready_tasks;"
    )
    result = run_command(
        compose_command(
            project,
            "exec",
            "-T",
            "mysql",
            "mysql",
            "-uworkflow",
            "-pworkflow",
            "-N",
            "-e",
            query,
            "durable_workflow",
        )
    )
    parts = result.stdout.strip().split()
    if len(parts) >= 4:
        return {
            "mysql_sample_ok": 1 if result.returncode == 0 else 0,
            "mysql_namespaces": int(parts[0]),
            "mysql_worker_registrations": int(parts[1]),
            "mysql_workflow_runs": int(parts[2]),
            "mysql_ready_tasks": int(parts[3]),
        }
    return {"mysql_sample_ok": 0}


def sample(project: str) -> dict[str, Any]:
    row: dict[str, Any] = {"timestamp": time.time()}
    if project:
        row.update(docker_stats(project))
        row.update(redis_info(project))
        row.update(mysql_counts(project))
    return row


def sample_health(samples: list[dict[str, Any]], compose_project: str) -> dict[str, Any]:
    if not compose_project:
        return {
            "required": False,
            "unhealthy_samples": 0,
            "unhealthy_field_counts": {},
            "unhealthy_final_sample": False,
        }

    required_ok_fields = (
        "docker_stats_ok",
        "server_container_healthy",
        "redis_sample_ok",
        "mysql_sample_ok",
    )
    unhealthy_field_counts = {
        field: sum(1 for row in samples if int(row.get(field) or 0) != 1)
        for field in required_ok_fields
    }
    unhealthy_indexes = [
        index
        for index, row in enumerate(samples)
        if any(int(row.get(field) or 0) != 1 for field in required_ok_fields)
    ]

    return {
        "required": True,
        "required_ok_fields": list(required_ok_fields),
        "unhealthy_samples": len(unhealthy_indexes),
        "unhealthy_field_counts": unhealthy_field_counts,
        "unhealthy_sample_indexes": unhealthy_indexes[:20],
        "unhealthy_final_sample": bool(unhealthy_indexes and unhealthy_indexes[-1] == len(samples) - 1),
    }


def write_jsonl(path: Path, row: dict[str, Any]) -> None:
    with ERROR_WRITE_LOCK:
        with path.open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(row, sort_keys=True) + "\n")


def worker_loop(
    stop_at: float,
    base_url: str,
    token: str,
    workers: list[tuple[str, str, str]],
    metrics: Metrics,
    endpoint_metrics: EndpointMetrics,
    errors_path: Path,
    worker_index: int,
) -> None:
    rng = random.Random(worker_index)
    sequence = 0

    while time.monotonic() < stop_at:
        namespace, queue, worker_id = rng.choice(workers)
        sequence += 1
        poll_request_id = f"perf-{worker_index}-{sequence}-{time.time_ns()}"
        started = time.monotonic()

        try:
            status, body = http_json(
                "POST",
                f"{base_url}/api/worker/workflow-tasks/poll",
                auth_headers(token, namespace, worker=True),
                {
                    "worker_id": worker_id,
                    "task_queue": queue,
                    "poll_request_id": poll_request_id,
                },
            )
            latency = time.monotonic() - started
            metrics.record_request(status, latency)
            compatible_backpressure = (
                status == 200
                and isinstance(body, dict)
                and body.get("reason") == "long_poll_capacity_exhausted"
            )
            endpoint_metrics.record(
                "worker_poll",
                status,
                latency,
                backpressured=compatible_backpressure,
            )
            if status == 429:
                retry_after = body.get("retry_after_seconds", 1) if isinstance(body, dict) else 1
                time.sleep(max(0.05, min(5.0, float(retry_after))))
            elif status != 200:
                metrics.record_error()
                write_jsonl(errors_path, {"status": status, "body": body, "namespace": namespace, "queue": queue})
        except Exception as exc:  # noqa: BLE001
            metrics.record_error()
            endpoint_metrics.record_error("worker_poll", time.monotonic() - started)
            write_jsonl(errors_path, {"exception": repr(exc), "namespace": namespace, "queue": queue})


def workflow_start_loop(
    stop_at: float,
    base_url: str,
    token: str,
    workers: list[tuple[str, str, str]],
    target_runs: int,
    start_concurrency: int,
    worker_index: int,
    endpoint_metrics: EndpointMetrics,
    errors_path: Path,
) -> None:
    for index in range(worker_index, target_runs, start_concurrency):
        if time.monotonic() >= stop_at:
            return

        namespace, queue, _worker_id = workers[index % len(workers)]
        started = time.monotonic()
        try:
            status, body = http_json(
                "POST",
                f"{base_url}/api/workflows",
                auth_headers(token, namespace),
                {
                    "workflow_id": f"perf-health-growth-{index:06d}",
                    "workflow_type": PERF_WORKFLOW_TYPE,
                    "task_queue": queue,
                    "input": [index],
                },
            )
            latency = time.monotonic() - started
            endpoint_metrics.record("workflow_start", status, latency)
            if status not in (200, 201):
                write_jsonl(
                    errors_path,
                    {"endpoint": "workflow_start", "status": status, "body": body, "run_index": index},
                )
        except Exception as exc:  # noqa: BLE001
            endpoint_metrics.record_error("workflow_start", time.monotonic() - started)
            write_jsonl(
                errors_path,
                {"endpoint": "workflow_start", "exception": repr(exc), "run_index": index},
            )


def evaluate_workflow_growth(
    target_runs: int,
    minimum_completion_ratio: float,
    start_results: dict[str, Any],
    final_workflow_runs: int,
    compose_backed: bool,
) -> tuple[dict[str, Any], list[str]]:
    if not 0 < minimum_completion_ratio <= 1:
        raise ValueError("minimum_completion_ratio must be greater than 0 and at most 1")

    target_runs = max(0, target_runs)
    attempted_starts = int(start_results.get("requests") or 0)
    successful_starts = int(start_results.get("successful") or 0)
    available_starts = int(start_results.get("available") or 0)
    request_errors = int(start_results.get("errors") or 0)
    minimum_successful_starts = math.ceil(target_runs * minimum_completion_ratio)
    completion_ratio = 1.0 if target_runs == 0 else min(1.0, successful_starts / target_runs)
    availability = 0.0 if attempted_starts == 0 else available_starts / attempted_starts

    result = {
        "target_runs": target_runs,
        "minimum_completion_ratio": minimum_completion_ratio,
        "minimum_successful_starts": minimum_successful_starts,
        "attempted_starts": attempted_starts,
        "successful_starts": successful_starts,
        "completion_ratio": round(completion_ratio, 6),
        "final_workflow_runs": final_workflow_runs,
    }
    failures: list[str] = []

    if target_runs == 0:
        return result, failures

    if successful_starts < minimum_successful_starts:
        failures.append(
            "workflow growth target incomplete: required at least "
            f"{minimum_successful_starts} of {target_runs} successful starts "
            f"({minimum_completion_ratio:.1%}) but observed {successful_starts}"
        )
    if compose_backed and final_workflow_runs < minimum_successful_starts:
        failures.append(
            "workflow run cardinality below completion floor: required at least "
            f"{minimum_successful_starts} of {target_runs} rows but sampled {final_workflow_runs}"
        )
    if request_errors > 0:
        failures.append(f"workflow_start recorded {request_errors} request errors")
    if attempted_starts == 0:
        failures.append("workflow_start availability was not sampled during workflow growth")
    elif available_starts < attempted_starts:
        failures.append(
            "workflow_start availability fell below 1.0 "
            f"(observed {availability:.6f})"
        )

    return result, failures


def workflow_list_loop(
    stop_at: float,
    base_url: str,
    token: str,
    namespaces: list[str],
    endpoint_metrics: EndpointMetrics,
    errors_path: Path,
) -> None:
    sequence = 0
    while time.monotonic() < stop_at:
        namespace = namespaces[sequence % len(namespaces)]
        sequence += 1
        started = time.monotonic()
        try:
            status, body = http_json(
                "GET",
                f"{base_url}/api/workflows?per_page=10",
                auth_headers(token, namespace),
            )
            endpoint_metrics.record("workflow_list", status, time.monotonic() - started)
            if status != 200:
                write_jsonl(
                    errors_path,
                    {"endpoint": "workflow_list", "status": status, "body": body, "namespace": namespace},
                )
        except Exception as exc:  # noqa: BLE001
            endpoint_metrics.record_error("workflow_list", time.monotonic() - started)
            write_jsonl(
                errors_path,
                {"endpoint": "workflow_list", "exception": repr(exc), "namespace": namespace},
            )

        time.sleep(0.2)


def health_probe_loop(
    stop_at: float,
    base_url: str,
    interval_seconds: float,
    timeout_seconds: float,
    endpoint_metrics: EndpointMetrics,
    errors_path: Path,
) -> None:
    while time.monotonic() < stop_at:
        for endpoint, expected_status in (("health", "serving"), ("ready", "ready")):
            started = time.monotonic()
            try:
                status, body = http_json(
                    "GET",
                    f"{base_url}/api/{endpoint}",
                    {},
                    timeout_seconds=timeout_seconds,
                )
                endpoint_metrics.record(endpoint, status, time.monotonic() - started)
                body_status = body.get("status") if isinstance(body, dict) else None
                if status != 200 or body_status != expected_status:
                    write_jsonl(
                        errors_path,
                        {"endpoint": endpoint, "status": status, "body": body},
                    )
            except Exception as exc:  # noqa: BLE001
                endpoint_metrics.record_error(endpoint, time.monotonic() - started)
                write_jsonl(errors_path, {"endpoint": endpoint, "exception": repr(exc)})

        time.sleep(max(0.05, interval_seconds))


def cluster_info_probe_loop(
    stop_at: float,
    base_url: str,
    token: str,
    namespace: str,
    interval_seconds: float,
    timeout_seconds: float,
    endpoint_metrics: EndpointMetrics,
    errors_path: Path,
) -> None:
    while time.monotonic() < stop_at:
        started = time.monotonic()
        try:
            status, body = http_json(
                "GET",
                f"{base_url}/api/cluster/info",
                auth_headers(token, namespace),
                timeout_seconds=timeout_seconds,
            )
            endpoint_metrics.record("cluster_info", status, time.monotonic() - started)
            if status != 200 or not isinstance(body, dict) or not body.get("version"):
                write_jsonl(
                    errors_path,
                    {"endpoint": "cluster_info", "status": status, "body": body},
                )
        except Exception as exc:  # noqa: BLE001
            endpoint_metrics.record_error("cluster_info", time.monotonic() - started)
            write_jsonl(errors_path, {"endpoint": "cluster_info", "exception": repr(exc)})

        time.sleep(max(0.05, interval_seconds))


def composer_package_version(package_name: str) -> str | None:
    lock_path = Path(__file__).resolve().parents[2] / "composer.lock"
    try:
        lock = json.loads(lock_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None

    for package in lock.get("packages", []):
        if isinstance(package, dict) and package.get("name") == package_name:
            version = package.get("version")
            return version if isinstance(version, str) and version else None

    return None


def artifact_versions(base_url: str, token: str, namespace: str) -> dict[str, str | None]:
    server_version = os.environ.get("DW_PERF_SERVER_VERSION")
    try:
        status, body = http_json(
            "GET",
            f"{base_url}/api/cluster/info",
            auth_headers(token, namespace),
        )
        if status == 200 and isinstance(body, dict) and isinstance(body.get("version"), str):
            server_version = body["version"]
    except Exception:  # noqa: BLE001
        pass

    return {
        "cli": os.environ.get("DW_PERF_CLI_VERSION"),
        "sdk-python": os.environ.get("DW_PERF_SDK_PYTHON_VERSION"),
        "server": server_version,
        "workflow": os.environ.get("DW_PERF_WORKFLOW_VERSION")
        or composer_package_version("durable-workflow/workflow"),
        "waterline": os.environ.get("DW_PERF_WATERLINE_VERSION"),
    }


def memory_slope_mb_hour(samples: list[dict[str, Any]]) -> float | None:
    points = [
        (float(row["timestamp"]), float(row.get("server_memory_bytes") or 0) / (1024 * 1024))
        for row in samples
        if row.get("server_memory_bytes")
    ]
    if len(points) < 4:
        return None

    warmup = max(1, math.floor(len(points) * 0.2))
    points = points[warmup:]
    if len(points) < 3:
        return None

    x0 = points[0][0]
    xs = [x - x0 for x, _ in points]
    ys = [y for _, y in points]
    x_mean = sum(xs) / len(xs)
    y_mean = sum(ys) / len(ys)
    denominator = sum((x - x_mean) ** 2 for x in xs)
    if denominator == 0:
        return None

    slope_mb_second = sum((x - x_mean) * (y - y_mean) for x, y in zip(xs, ys)) / denominator
    return slope_mb_second * 3600


def start_metrics_server(metrics: Metrics, port: int) -> ThreadingHTTPServer:
    MetricsHandler.metrics = metrics
    server = ThreadingHTTPServer(("0.0.0.0", port), MetricsHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return server


def evidence_provenance(base_url: str, compose_project: str) -> dict[str, Any]:
    repo_root = Path(__file__).resolve().parents[2]
    policy_path = repo_root / "config" / "dw-bounded-growth.php"
    tracked_changes = tracked_working_tree_changes()
    checked_out_sha = command_output(["git", "rev-parse", "HEAD"])
    github_sha = os.environ.get("GITHUB_SHA") or checked_out_sha

    return {
        "repository": os.environ.get("GITHUB_REPOSITORY") or command_output(["git", "config", "--get", "remote.origin.url"]),
        "ref": os.environ.get("GITHUB_REF") or command_output(["git", "rev-parse", "--abbrev-ref", "HEAD"]),
        "sha": github_sha,
        "checked_out_sha": checked_out_sha,
        "github_sha_matches_checked_out": github_sha == checked_out_sha,
        "workflow": os.environ.get("GITHUB_WORKFLOW", ""),
        "event_name": os.environ.get("GITHUB_EVENT_NAME", ""),
        "run_id": os.environ.get("GITHUB_RUN_ID", ""),
        "run_attempt": os.environ.get("GITHUB_RUN_ATTEMPT", ""),
        "job": os.environ.get("GITHUB_JOB", ""),
        "runner_name": os.environ.get("RUNNER_NAME", ""),
        "runner_os": os.environ.get("RUNNER_OS", ""),
        "runner_arch": os.environ.get("RUNNER_ARCH", ""),
        "runner_environment": runner_environment(),
        "compose_project": compose_project,
        "base_url": base_url,
        "bounded_growth_policy_sha256": file_sha256(policy_path),
        "tracked_working_tree_clean": len(tracked_changes) == 0,
        "tracked_working_tree_change_count": len(tracked_changes),
    }


def evidence_trust_profile(
    *,
    duration_seconds: int,
    compose_project: str,
    provenance: dict[str, Any],
    runner_environment: str,
    tracked_working_tree_clean: bool,
    periodic_sample_count: int,
    minimum_trusted_samples: int,
    sampling_health: dict[str, Any],
    max_server_cache_keys_by_policy: dict[str, int],
    max_final_server_cache_keys_by_policy: dict[str, int],
    polling_activity_observed: bool,
    failures: list[str],
) -> dict[str, Any]:
    minimum_duration_seconds = 3600
    reasons = []

    if duration_seconds < minimum_duration_seconds:
        reasons.append(f"duration below trusted long-soak minimum {minimum_duration_seconds}s")
    if not compose_project:
        reasons.append("compose-backed resource sampling was not configured")
    if not runner_environment:
        reasons.append("runner environment is unknown")
    elif runner_environment != "self-hosted":
        reasons.append(f"runner environment is {runner_environment}, not self-hosted")
    if not github_actions_provenance_present(provenance):
        reasons.append("GitHub Actions provenance is incomplete")
    if str(provenance.get("repository") or "").strip() != "durable-workflow/server":
        reasons.append("GitHub Actions repository is not durable-workflow/server")
    if str(provenance.get("ref") or "").strip() != "refs/heads/main":
        reasons.append("GitHub Actions ref is not refs/heads/main")
    if str(provenance.get("workflow") or "").strip() != "Server Perf Soak":
        reasons.append("GitHub Actions workflow is not Server Perf Soak")
    if str(provenance.get("event_name") or "").strip() not in ("schedule", "workflow_dispatch"):
        reasons.append("GitHub Actions event is not schedule or workflow_dispatch")
    if not bool(provenance.get("github_sha_matches_checked_out")):
        reasons.append("GitHub Actions SHA does not match checked-out source")
    if not tracked_working_tree_clean:
        reasons.append("tracked working tree has uncommitted changes")
    if periodic_sample_count < minimum_trusted_samples:
        reasons.append("periodic sample coverage below trusted minimum")
    if int(sampling_health.get("unhealthy_samples") or 0) > 0:
        reasons.append("compose-backed resource sampling has unhealthy samples")
    if not polling_activity_observed:
        # A long soak that never observed any polling cache activity cannot
        # produce trusted evidence about polling cache bounded growth — the
        # path was not exercised, so the assertions skip and the run says
        # nothing about the surface it is meant to certify.
        reasons.append("polling cache activity was not observed during the run")
    reasons.extend(
        per_policy_threshold_reasons(
            max_server_cache_keys_by_policy=max_server_cache_keys_by_policy,
            max_final_server_cache_keys_by_policy=max_final_server_cache_keys_by_policy,
        )
    )
    if failures:
        reasons.append("bounded-growth assertions failed")

    return {
        "profile": "trusted_long_soak_v1",
        "eligible": len(reasons) == 0,
        "minimum_duration_seconds": minimum_duration_seconds,
        "runner_environment": runner_environment,
        "requires_self_hosted_runner": True,
        "requires_github_actions_provenance": True,
        "requires_server_main_ref": True,
        "requires_server_perf_workflow": True,
        "requires_trusted_event": True,
        "requires_github_sha_match": True,
        "requires_compose_resource_sampling": True,
        "requires_clean_tracked_working_tree": True,
        "requires_per_policy_cache_thresholds": True,
        "requires_polling_cache_activity_observed": True,
        "reasons": reasons,
    }


def per_policy_threshold_reasons(
    *,
    max_server_cache_keys_by_policy: dict[str, int],
    max_final_server_cache_keys_by_policy: dict[str, int],
) -> list[str]:
    policy_ids = set(SERVER_CACHE_KEY_PATTERNS)
    reasons = []

    missing_max_policy_ids = sorted(policy_ids - set(max_server_cache_keys_by_policy))
    if missing_max_policy_ids:
        reasons.append(
            "per-policy max cache thresholds missing for: "
            + ", ".join(missing_max_policy_ids)
        )

    missing_final_policy_ids = sorted(
        policy_ids - set(max_final_server_cache_keys_by_policy)
    )
    if missing_final_policy_ids:
        reasons.append(
            "per-policy final cache thresholds missing for: "
            + ", ".join(missing_final_policy_ids)
        )

    return reasons


def github_actions_provenance_present(provenance: dict[str, Any]) -> bool:
    required_fields = (
        "repository",
        "ref",
        "sha",
        "workflow",
        "event_name",
        "run_id",
        "run_attempt",
    )

    return all(str(provenance.get(field) or "").strip() for field in required_fields)


def main() -> int:
    args = parse_args()

    artifact_dir = Path(args.artifact_dir)
    artifact_dir.mkdir(parents=True, exist_ok=True)
    samples_path = artifact_dir / "samples.jsonl"
    errors_path = artifact_dir / "errors.jsonl"
    summary_path = artifact_dir / "summary.json"
    metrics_path = artifact_dir / "metrics.prom"

    for path in (samples_path, errors_path, summary_path, metrics_path):
        if path.exists():
            path.unlink()

    metrics = Metrics()
    endpoint_metrics = EndpointMetrics()
    metrics_server = start_metrics_server(metrics, args.metrics_port)
    base_url = args.base_url.rstrip("/")
    started_at = datetime.now(timezone.utc)
    started_monotonic = time.monotonic()

    try:
        emit_progress(f"waiting for health at {base_url}")
        wait_for_health(base_url)
        emit_progress("server reported healthy")

        namespaces = [f"perf-ns-{index:03d}" for index in range(max(1, args.namespaces))]
        queues = [f"perf-queue-{index:03d}" for index in range(max(1, args.task_queues))]
        create_namespaces(base_url, args.token, namespaces)
        workers = register_workers(base_url, args.token, namespaces, queues)
        resolved_artifact_versions = artifact_versions(base_url, args.token, namespaces[0])
        emit_progress(
            f"registered {len(workers)} workers across {len(namespaces)} namespaces and {len(queues)} task queues"
        )

        stop_at = time.monotonic() + max(1, args.duration_seconds)
        futures = []
        samples: list[dict[str, Any]] = []
        periodic_sample_count = 0
        sample_interval = max(1, args.sample_interval_seconds)
        expected_periodic_samples = max(1, math.floor(args.duration_seconds / sample_interval))
        emit_progress(
            f"starting load for {args.duration_seconds}s with concurrency={args.concurrency} "
            f"workflow_runs={args.workflow_runs} and sample_interval={sample_interval}s"
        )

        growth_workers = max(1, args.start_concurrency) + 3 if args.workflow_runs > 0 else 0
        with ThreadPoolExecutor(max_workers=max(1, args.concurrency) + growth_workers) as executor:
            for index in range(max(1, args.concurrency)):
                futures.append(
                    executor.submit(
                        worker_loop,
                        stop_at,
                        base_url,
                        args.token,
                        workers,
                        metrics,
                        endpoint_metrics,
                        errors_path,
                        index,
                    )
                )

            if args.workflow_runs > 0:
                for index in range(max(1, args.start_concurrency)):
                    futures.append(
                        executor.submit(
                            workflow_start_loop,
                            stop_at,
                            base_url,
                            args.token,
                            workers,
                            args.workflow_runs,
                            max(1, args.start_concurrency),
                            index,
                            endpoint_metrics,
                            errors_path,
                        )
                    )
                futures.append(
                    executor.submit(
                        workflow_list_loop,
                        stop_at,
                        base_url,
                        args.token,
                        namespaces,
                        endpoint_metrics,
                        errors_path,
                    )
                )
                futures.append(
                    executor.submit(
                        health_probe_loop,
                        stop_at,
                        base_url,
                        args.health_interval_seconds,
                        args.max_health_latency_seconds,
                        endpoint_metrics,
                        errors_path,
                    )
                )
                futures.append(
                    executor.submit(
                        cluster_info_probe_loop,
                        stop_at,
                        base_url,
                        args.token,
                        namespaces[0],
                        args.control_plane_interval_seconds,
                        args.max_control_plane_latency_seconds,
                        endpoint_metrics,
                        errors_path,
                    )
                )

            next_sample = time.monotonic()
            while time.monotonic() < stop_at:
                if time.monotonic() >= next_sample:
                    row = sample(args.compose_project)
                    samples.append(row)
                    periodic_sample_count += 1
                    metrics.update_sample(row)
                    write_jsonl(samples_path, row)
                    emit_progress(
                        f"sample {periodic_sample_count}/{expected_periodic_samples}: "
                        f"requests={metrics.completed} errors={metrics.errors} "
                        f"server_keys={row.get('redis_server_keys', 0)} "
                        f"polling_keys={row.get('redis_polling_keys', 0)}"
                    )
                    next_sample += sample_interval
                time.sleep(0.2)

            emit_progress("load window complete; waiting for worker loops to finish")
            wait(futures)
            for future in futures:
                exception = future.exception()
                if exception is not None:
                    metrics.record_error()
                    write_jsonl(errors_path, {"worker_exception": repr(exception)})

        emit_progress(f"draining for {max(0, args.drain_seconds)}s before final sample")
        time.sleep(max(0, args.drain_seconds))
        final_sample = sample(args.compose_project)
        samples.append(final_sample)
        metrics.update_sample(final_sample)
        write_jsonl(samples_path, final_sample | {"phase": "final"})

        max_server_memory_bytes = max((int(row.get("server_memory_bytes") or 0) for row in samples), default=0)
        max_pattern_polling_keys = max((int(row.get("redis_polling_keys") or 0) for row in samples), default=0)
        max_server_cache_keys = max((int(row.get("redis_server_keys") or 0) for row in samples), default=0)
        max_redis_db_keys = max((int(row.get("redis_db_keys") or 0) for row in samples), default=0)
        max_server_cache_keys_by_policy = {
            policy_id: max(
                (
                    int((row.get("redis_server_keys_by_policy") or {}).get(policy_id) or 0)
                    for row in samples
                    if isinstance(row.get("redis_server_keys_by_policy"), dict)
                ),
                default=0,
            )
            for policy_id in SERVER_CACHE_KEY_PATTERNS
        }
        # Bounded-growth assertions on the polling cache must use the
        # polling-pattern observation alone. Redis DBSIZE includes unrelated
        # keys (queues, sessions, fairness counters, locks) that have nothing
        # to do with polling cache growth, so conflating them produces false
        # positives whenever the harness happens to leave non-polling Redis
        # state behind. DBSIZE is still surfaced as max_redis_db_keys for
        # diagnostic visibility.
        max_polling_keys = max_pattern_polling_keys
        final_pattern_polling_keys = int(final_sample.get("redis_polling_keys") or 0)
        final_server_cache_keys = int(final_sample.get("redis_server_keys") or 0)
        final_redis_db_keys = int(final_sample.get("redis_db_keys") or 0)
        final_workflow_runs = int(final_sample.get("mysql_workflow_runs") or 0)
        final_ready_tasks = int(final_sample.get("mysql_ready_tasks") or 0)
        final_server_cache_keys_by_policy = {
            policy_id: int((final_sample.get("redis_server_keys_by_policy") or {}).get(policy_id) or 0)
            for policy_id in SERVER_CACHE_KEY_PATTERNS
        }
        final_polling_keys = final_pattern_polling_keys
        slope = memory_slope_mb_hour(samples) if args.duration_seconds >= 600 else None
        finished_at = datetime.now(timezone.utc)
        elapsed_seconds = time.monotonic() - started_monotonic
        expected_samples = expected_periodic_samples
        sample_coverage = max(0.0, min(1.0, args.min_sample_coverage))
        min_samples = max(1, math.ceil(expected_samples * sample_coverage))
        sample_count = len(samples)
        observed_sample_coverage = periodic_sample_count / expected_samples
        sampling_health = sample_health(samples, args.compose_project)
        request_availability = endpoint_metrics.snapshot()
        workflow_growth, workflow_growth_failures = evaluate_workflow_growth(
            target_runs=args.workflow_runs,
            minimum_completion_ratio=args.min_workflow_completion_ratio,
            start_results=request_availability.get("workflow_start", {}),
            final_workflow_runs=final_workflow_runs,
            compose_backed=bool(args.compose_project),
        )

        provenance = evidence_provenance(base_url, args.compose_project)

        # When the harness fails to exercise the polling cache surface
        # at all (zero observations across the entire window) we cannot
        # make a meaningful bounded-growth claim about it. Surface that
        # explicitly so the polling-specific assertions can skip rather
        # than silently passing or asserting against conflated signals.
        polling_activity_observed = max_pattern_polling_keys > 0
        polling_observation_status = (
            "observed" if polling_activity_observed else "skipped_no_activity"
        )

        summary = {
            "duration_seconds": args.duration_seconds,
            "elapsed_seconds": round(elapsed_seconds, 2),
            "concurrency": args.concurrency,
            "workflow_runs_target": args.workflow_runs,
            "start_concurrency": args.start_concurrency,
            "namespaces": len(namespaces),
            "task_queues": len(queues),
            "sample_interval_seconds": args.sample_interval_seconds,
            "control_plane_interval_seconds": args.control_plane_interval_seconds,
            "sample_count": sample_count,
            "periodic_sample_count": periodic_sample_count,
            "expected_periodic_samples": expected_samples,
            "observed_sample_coverage": round(observed_sample_coverage, 4),
            "minimum_trusted_samples": min_samples,
            "requests": dict(metrics.requests),
            "errors": metrics.errors,
            "max_server_memory_mb": round(max_server_memory_bytes / (1024 * 1024), 2),
            "max_polling_keys": max_polling_keys,
            "max_polling_pattern_keys": max_pattern_polling_keys,
            "max_server_cache_keys": max_server_cache_keys,
            "max_server_cache_keys_by_policy": max_server_cache_keys_by_policy,
            "max_redis_db_keys": max_redis_db_keys,
            "redis_cache_database": int(final_sample.get("redis_cache_database") or 0),
            "final_polling_keys": final_polling_keys,
            "final_polling_pattern_keys": final_pattern_polling_keys,
            "final_server_cache_keys": final_server_cache_keys,
            "final_server_cache_keys_by_policy": final_server_cache_keys_by_policy,
            "final_redis_db_keys": final_redis_db_keys,
            "final_workflow_runs": final_workflow_runs,
            "final_ready_tasks": final_ready_tasks,
            "workflow_growth": workflow_growth,
            "polling_observation_status": polling_observation_status,
            "server_memory_slope_mb_hour": None if slope is None else round(slope, 2),
            "sampling_health": sampling_health,
            "request_availability": request_availability,
            "assertions": {
                "max_server_memory_mb": args.max_server_memory_mb,
                "max_polling_keys": args.max_polling_keys,
                "max_final_polling_keys": args.max_final_polling_keys,
                "max_server_cache_keys": args.max_server_cache_keys,
                "max_final_server_cache_keys": args.max_final_server_cache_keys,
                "max_server_cache_keys_by_policy": args.max_server_cache_keys_by_policy,
                "max_final_server_cache_keys_by_policy": args.max_final_server_cache_keys_by_policy,
                "max_server_memory_slope_mb_hour": args.max_server_memory_slope_mb_hour,
                "min_sample_coverage": args.min_sample_coverage,
                "max_health_latency_seconds": args.max_health_latency_seconds,
                "max_control_plane_latency_seconds": args.max_control_plane_latency_seconds,
                "min_workflow_completion_ratio": args.min_workflow_completion_ratio,
                "require_trusted_evidence": args.require_trusted_evidence,
            },
            "evidence": {
                "started_at": started_at.isoformat().replace("+00:00", "Z"),
                "finished_at": finished_at.isoformat().replace("+00:00", "Z"),
                "artifact_versions": resolved_artifact_versions,
                "provenance": provenance,
            },
        }

        failures = list(workflow_growth_failures)
        if metrics.errors > 0:
            failures.append(f"{metrics.errors} load-generator errors")
        if args.workflow_runs > 0:
            for endpoint in ("health", "ready", "cluster_info", "workflow_list", "worker_poll"):
                result = request_availability.get(endpoint, {})
                if int(result.get("requests") or 0) == 0:
                    failures.append(f"{endpoint} availability was not sampled during workflow growth")
                elif float(result.get("availability") or 0.0) < 1.0:
                    failures.append(
                        f"{endpoint} availability fell below 1.0 "
                        f"(observed {result.get('availability')})"
                    )

            for endpoint in ("health", "ready"):
                result = request_availability.get(endpoint, {})
                observed_latency = float((result.get("latency_seconds") or {}).get("max") or 0.0)
                if observed_latency > args.max_health_latency_seconds:
                    failures.append(
                        f"{endpoint} latency exceeded {args.max_health_latency_seconds}s "
                        f"(observed {observed_latency}s)"
                    )

            cluster_info = request_availability.get("cluster_info", {})
            cluster_info_latency = float((cluster_info.get("latency_seconds") or {}).get("max") or 0.0)
            if cluster_info_latency > args.max_control_plane_latency_seconds:
                failures.append(
                    f"cluster_info latency exceeded {args.max_control_plane_latency_seconds}s "
                    f"(observed {cluster_info_latency}s)"
                )
        if periodic_sample_count < min_samples:
            failures.append(
                f"sample coverage below trusted minimum {min_samples} "
                f"(observed {periodic_sample_count} periodic samples)"
            )
        if int(sampling_health.get("unhealthy_samples") or 0) > 0:
            failures.append(
                "resource sampling failed for "
                f"{sampling_health['unhealthy_samples']} compose-backed samples "
                f"(field failures: {sampling_health.get('unhealthy_field_counts')})"
            )
        if max_server_memory_bytes > args.max_server_memory_mb * 1024 * 1024:
            failures.append(
                f"server memory exceeded {args.max_server_memory_mb} MB "
                f"(observed {summary['max_server_memory_mb']} MB)"
            )
        # Skip the polling-cache-specific assertions when the harness did
        # not exercise the polling path at all. Asserting bounded growth
        # against zero observed activity blocks unrelated PRs without
        # exercising what the gate is meant to protect.
        if polling_activity_observed:
            if max_polling_keys > args.max_polling_keys:
                failures.append(f"polling cache keys exceeded {args.max_polling_keys} (observed {max_polling_keys})")
            if final_polling_keys > args.max_final_polling_keys:
                failures.append(
                    f"polling cache keys did not drain to {args.max_final_polling_keys} "
                    f"(observed {final_polling_keys})"
                )
        if max_server_cache_keys > args.max_server_cache_keys:
            failures.append(
                f"server cache keys exceeded {args.max_server_cache_keys} "
                f"(observed {max_server_cache_keys})"
            )
        if final_server_cache_keys > args.max_final_server_cache_keys:
            failures.append(
                f"server cache keys did not drain to {args.max_final_server_cache_keys} "
                f"(observed {final_server_cache_keys})"
            )
        for policy_id, limit in sorted(args.max_server_cache_keys_by_policy.items()):
            observed = max_server_cache_keys_by_policy.get(policy_id, 0)
            if observed > limit:
                failures.append(
                    f"{policy_id} cache keys exceeded {limit} "
                    f"(observed {observed})"
                )
        for policy_id, limit in sorted(args.max_final_server_cache_keys_by_policy.items()):
            observed = final_server_cache_keys_by_policy.get(policy_id, 0)
            if observed > limit:
                failures.append(
                    f"{policy_id} cache keys did not drain to {limit} "
                    f"(observed {observed})"
                )
        if (
            slope is not None
            and args.max_server_memory_slope_mb_hour > 0
            and slope > args.max_server_memory_slope_mb_hour
        ):
            failures.append(
                f"server memory slope exceeded {args.max_server_memory_slope_mb_hour} MB/hour "
                f"(observed {slope:.2f} MB/hour)"
            )

        if failures:
            metrics.mark_assertion_failed()
            summary["failures"] = failures

        summary["evidence"]["trust"] = evidence_trust_profile(
            duration_seconds=args.duration_seconds,
            compose_project=args.compose_project,
            provenance=provenance,
            runner_environment=str(provenance.get("runner_environment") or ""),
            tracked_working_tree_clean=bool(provenance.get("tracked_working_tree_clean")),
            periodic_sample_count=periodic_sample_count,
            minimum_trusted_samples=min_samples,
            sampling_health=sampling_health,
            max_server_cache_keys_by_policy=args.max_server_cache_keys_by_policy,
            max_final_server_cache_keys_by_policy=args.max_final_server_cache_keys_by_policy,
            polling_activity_observed=polling_activity_observed,
            failures=failures,
        )
        trust_reasons = summary["evidence"]["trust"].get("reasons") or []
        if args.require_trusted_evidence and not summary["evidence"]["trust"].get("eligible"):
            failures.append(
                "trusted evidence profile is ineligible"
                + (f": {trust_reasons}" if trust_reasons else "")
            )
            metrics.mark_assertion_failed()
            summary["failures"] = failures

        metrics_path.write_text(metrics.prometheus(), encoding="utf-8")
        summary_path.write_text(json.dumps(summary, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        print(json.dumps(summary, indent=2, sort_keys=True))

        return 1 if failures else 0
    finally:
        metrics_server.shutdown()


if __name__ == "__main__":
    sys.exit(main())
