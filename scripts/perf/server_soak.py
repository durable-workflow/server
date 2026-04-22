#!/usr/bin/env python3
"""Run a bounded-growth stress pass against the server worker poll path."""

from __future__ import annotations

import argparse
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


CONTROL_PLANE_VERSION = "2"
WORKER_PROTOCOL_VERSION = "1.0"
ERROR_WRITE_LOCK = threading.Lock()
SERVER_CACHE_KEY_PATTERNS = {
    "long_poll_signals": "*server:long-poll-signal:*",
    "workflow_task_poll_requests": "*server:workflow-task-poll-request:*",
    "workflow_query_tasks": "*server:workflow-query-task:*",
    "task_queue_admission_locks": "*server:task-queue-admission:*",
    "task_queue_dispatch_counters": "*server:task-queue-dispatch:*",
    "workflow_task_expired_lease_recovery": "*server:workflow-task-expired-lease-recovery:*",
    "history_retention_inline": "*server:history-retention-inline:*",
    "readiness_probe": "*server:readiness:*",
}


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
                    "# HELP dw_perf_redis_db_keys Redis DBSIZE count.",
                    "# TYPE dw_perf_redis_db_keys gauge",
                    f"dw_perf_redis_db_keys {self.latest['redis_db_keys']}",
                    "# HELP dw_perf_assertion_failed Whether the harness failed an assertion.",
                    "# TYPE dw_perf_assertion_failed gauge",
                    f"dw_perf_assertion_failed {self.latest['assertion_failed']}",
                    "",
                ]
            )

        return "\n".join(lines)


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

    return limits


def http_json(method: str, url: str, headers: dict[str, str], payload: dict[str, Any] | None = None) -> tuple[int, Any]:
    data = None if payload is None else json.dumps(payload).encode("utf-8")
    request = urllib.request.Request(url, data=data, method=method)
    for key, value in headers.items():
        request.add_header(key, value)
    if payload is not None:
        request.add_header("Content-Type", "application/json")
    request.add_header("Accept", "application/json")

    try:
        with urllib.request.urlopen(request, timeout=20) as response:
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
                    "runtime": "php",
                    "sdk_version": "perf-harness",
                    "max_concurrent_workflow_tasks": 100,
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

    return stats


def redis_info(project: str) -> dict[str, int]:
    used_memory = 0
    db_keys = 0
    server_keys = 0
    server_keys_by_policy = {policy_id: 0 for policy_id in SERVER_CACHE_KEY_PATTERNS}

    info = run_command(compose_command(project, "exec", "-T", "redis", "redis-cli", "INFO", "memory"))
    redis_ok = info.returncode == 0
    for line in info.stdout.splitlines():
        if line.startswith("used_memory:"):
            used_memory = int(line.split(":", 1)[1].strip())
            break

    dbsize = run_command(compose_command(project, "exec", "-T", "redis", "redis-cli", "DBSIZE"))
    redis_ok = redis_ok and dbsize.returncode == 0
    try:
        db_keys = int(dbsize.stdout.strip() or "0")
    except ValueError:
        db_keys = 0
        redis_ok = False

    for policy_id, pattern in SERVER_CACHE_KEY_PATTERNS.items():
        count, ok = redis_scan_count(project, pattern)
        server_keys_by_policy[policy_id] = count
        redis_ok = redis_ok and ok

    server_keys, ok = redis_scan_count(project, "*server:*")
    redis_ok = redis_ok and ok

    return {
        "redis_sample_ok": 1 if redis_ok else 0,
        "redis_used_memory_bytes": used_memory,
        "redis_db_keys": db_keys,
        "redis_polling_keys": server_keys_by_policy["workflow_task_poll_requests"],
        "redis_server_keys": server_keys,
        "redis_server_keys_by_policy": server_keys_by_policy,
    }


def redis_scan_count(project: str, pattern: str) -> tuple[int, bool]:
    count = run_command(
        compose_command(
            project,
            "exec",
            "-T",
            "redis",
            "sh",
            "-lc",
            f"redis-cli --scan --pattern {json.dumps(pattern)} | wc -l",
        )
    )
    try:
        return int(count.stdout.strip() or "0"), count.returncode == 0
    except ValueError:
        return 0, False


def mysql_counts(project: str) -> dict[str, int]:
    query = (
        "SELECT "
        "(SELECT COUNT(*) FROM workflow_namespaces) AS namespaces, "
        "(SELECT COUNT(*) FROM workflow_worker_registrations) AS workers;"
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
    if len(parts) >= 2:
        return {
            "mysql_sample_ok": 1 if result.returncode == 0 else 0,
            "mysql_namespaces": int(parts[0]),
            "mysql_worker_registrations": int(parts[1]),
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
            if status != 200:
                metrics.record_error()
                write_jsonl(errors_path, {"status": status, "body": body, "namespace": namespace, "queue": queue})
        except Exception as exc:  # noqa: BLE001
            metrics.record_error()
            write_jsonl(errors_path, {"exception": repr(exc), "namespace": namespace, "queue": queue})


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

    return {
        "repository": os.environ.get("GITHUB_REPOSITORY") or command_output(["git", "config", "--get", "remote.origin.url"]),
        "ref": os.environ.get("GITHUB_REF") or command_output(["git", "rev-parse", "--abbrev-ref", "HEAD"]),
        "sha": os.environ.get("GITHUB_SHA") or command_output(["git", "rev-parse", "HEAD"]),
        "workflow": os.environ.get("GITHUB_WORKFLOW", ""),
        "run_id": os.environ.get("GITHUB_RUN_ID", ""),
        "run_attempt": os.environ.get("GITHUB_RUN_ATTEMPT", ""),
        "runner_name": os.environ.get("RUNNER_NAME", ""),
        "runner_os": os.environ.get("RUNNER_OS", ""),
        "runner_arch": os.environ.get("RUNNER_ARCH", ""),
        "compose_project": compose_project,
        "base_url": base_url,
        "bounded_growth_policy_sha256": file_sha256(policy_path),
    }


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
    metrics_server = start_metrics_server(metrics, args.metrics_port)
    base_url = args.base_url.rstrip("/")
    started_at = datetime.now(timezone.utc)
    started_monotonic = time.monotonic()

    try:
        wait_for_health(base_url)

        namespaces = [f"perf-ns-{index:03d}" for index in range(max(1, args.namespaces))]
        queues = [f"perf-queue-{index:03d}" for index in range(max(1, args.task_queues))]
        create_namespaces(base_url, args.token, namespaces)
        workers = register_workers(base_url, args.token, namespaces, queues)

        stop_at = time.monotonic() + max(1, args.duration_seconds)
        futures = []
        samples: list[dict[str, Any]] = []
        periodic_sample_count = 0

        with ThreadPoolExecutor(max_workers=max(1, args.concurrency)) as executor:
            for index in range(max(1, args.concurrency)):
                futures.append(
                    executor.submit(worker_loop, stop_at, base_url, args.token, workers, metrics, errors_path, index)
                )

            next_sample = time.monotonic()
            sample_interval = max(1, args.sample_interval_seconds)
            while time.monotonic() < stop_at:
                if time.monotonic() >= next_sample:
                    row = sample(args.compose_project)
                    samples.append(row)
                    periodic_sample_count += 1
                    metrics.update_sample(row)
                    write_jsonl(samples_path, row)
                    next_sample += sample_interval
                time.sleep(0.2)

            wait(futures)
            for future in futures:
                exception = future.exception()
                if exception is not None:
                    metrics.record_error()
                    write_jsonl(errors_path, {"worker_exception": repr(exception)})

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
        max_polling_keys = max(max_pattern_polling_keys, max_redis_db_keys)
        final_pattern_polling_keys = int(final_sample.get("redis_polling_keys") or 0)
        final_server_cache_keys = int(final_sample.get("redis_server_keys") or 0)
        final_redis_db_keys = int(final_sample.get("redis_db_keys") or 0)
        final_server_cache_keys_by_policy = {
            policy_id: int((final_sample.get("redis_server_keys_by_policy") or {}).get(policy_id) or 0)
            for policy_id in SERVER_CACHE_KEY_PATTERNS
        }
        final_polling_keys = max(final_pattern_polling_keys, final_redis_db_keys)
        slope = memory_slope_mb_hour(samples) if args.duration_seconds >= 600 else None
        finished_at = datetime.now(timezone.utc)
        elapsed_seconds = time.monotonic() - started_monotonic
        expected_samples = max(1, math.floor(args.duration_seconds / max(1, args.sample_interval_seconds)))
        sample_coverage = max(0.0, min(1.0, args.min_sample_coverage))
        min_samples = max(1, math.ceil(expected_samples * sample_coverage))
        sample_count = len(samples)
        observed_sample_coverage = periodic_sample_count / expected_samples
        sampling_health = sample_health(samples, args.compose_project)

        summary = {
            "duration_seconds": args.duration_seconds,
            "elapsed_seconds": round(elapsed_seconds, 2),
            "concurrency": args.concurrency,
            "namespaces": len(namespaces),
            "task_queues": len(queues),
            "sample_interval_seconds": args.sample_interval_seconds,
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
            "final_polling_keys": final_polling_keys,
            "final_polling_pattern_keys": final_pattern_polling_keys,
            "final_server_cache_keys": final_server_cache_keys,
            "final_server_cache_keys_by_policy": final_server_cache_keys_by_policy,
            "final_redis_db_keys": final_redis_db_keys,
            "server_memory_slope_mb_hour": None if slope is None else round(slope, 2),
            "sampling_health": sampling_health,
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
            },
            "evidence": {
                "started_at": started_at.isoformat().replace("+00:00", "Z"),
                "finished_at": finished_at.isoformat().replace("+00:00", "Z"),
                "provenance": evidence_provenance(base_url, args.compose_project),
            },
        }

        failures = []
        if metrics.errors > 0:
            failures.append(f"{metrics.errors} load-generator errors")
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

        metrics_path.write_text(metrics.prometheus(), encoding="utf-8")
        summary_path.write_text(json.dumps(summary, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        print(json.dumps(summary, indent=2, sort_keys=True))

        return 1 if failures else 0
    finally:
        metrics_server.shutdown()


if __name__ == "__main__":
    sys.exit(main())
