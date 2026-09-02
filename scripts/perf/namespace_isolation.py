#!/usr/bin/env python3
"""Exercise two mutually untrusted namespaces on one constrained Server."""

from __future__ import annotations

import argparse
import asyncio
import json
import logging
import math
import subprocess
import time
import uuid
from collections import Counter
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Awaitable, Callable

from durable_workflow import Client, ScheduleAction, ScheduleSpec, Worker, activity, workflow
from durable_workflow.errors import ServerError


WORKFLOW_TYPE = "isolation.mixed"
CHILD_WORKFLOW_TYPE = "isolation.child"
ACTIVITY_TYPE = "isolation.echo"
NAMESPACES = ("noisy", "control")
logging.getLogger("durable_workflow.worker").setLevel(logging.ERROR)


@activity.defn(name=ACTIVITY_TYPE)
async def echo(value: Any) -> Any:
    await asyncio.sleep(0.01)
    return value


@workflow.defn(name=CHILD_WORKFLOW_TYPE)
class ChildWorkflow:
    def run(self, _context: Any, value: Any) -> dict[str, Any]:
        return {"child": value}


@workflow.defn(name=WORKFLOW_TYPE)
class MixedWorkflow:
    def __init__(self) -> None:
        self.count = 0
        self.finished = False

    @workflow.signal("finish")
    def finish(self) -> None:
        self.finished = True

    @workflow.signal("increment")
    def increment_signal(self, amount: int) -> None:
        self.count += amount

    @workflow.update("increment")
    def increment_update(self, amount: int) -> dict[str, int]:
        self.count += amount
        return {"count": self.count}

    @workflow.query("state")
    def state(self) -> dict[str, Any]:
        return {"count": self.count, "finished": self.finished}

    def run(self, context: Any, request: dict[str, Any]):  # type: ignore[no-untyped-def]
        mode = str(request.get("mode", "activity"))
        value = request.get("value")

        if mode == "interactive":
            yield context.wait_condition(lambda: self.finished, key="finish", timeout=20)
            return {"mode": mode, "count": self.count, "value": value}
        elif mode == "timer":
            yield context.sleep(1)
        elif mode == "child":
            yield context.start_child_workflow(CHILD_WORKFLOW_TYPE, [value])

        steps = int(request.get("history_steps", 1))
        for index in range(max(1, steps)):
            value = yield context.schedule_activity(ACTIVITY_TYPE, [{"index": index, "value": value}])

        return {"mode": mode, "count": self.count, "value": value}


@dataclass
class OperationStats:
    attempts: Counter[str] = field(default_factory=Counter)
    successes: Counter[str] = field(default_factory=Counter)
    rejections: Counter[str] = field(default_factory=Counter)
    errors: Counter[str] = field(default_factory=Counter)
    control_latencies: list[float] = field(default_factory=list)

    def record_error(self, operation: str, error: BaseException) -> None:
        if isinstance(error, ServerError):
            body = error.body if isinstance(error.body, dict) else {}
            reason = str(body.get("reason") or f"http_{error.status}")
            if error.status in (429, 503):
                self.rejections[reason] += 1
                return
        self.errors[f"{operation}:{type(error).__name__}"] += 1

    def as_dict(self) -> dict[str, Any]:
        return {
            "attempts": dict(self.attempts),
            "successes": dict(self.successes),
            "rejections": dict(self.rejections),
            "errors": dict(self.errors),
            "control_latency_seconds": summarize(self.control_latencies),
        }


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--token", required=True)
    parser.add_argument("--compose-project", required=True)
    parser.add_argument("--duration-seconds", type=int, default=60)
    parser.add_argument("--server-image", required=True)
    parser.add_argument("--sdk-version", required=True)
    parser.add_argument("--server-cpus", required=True)
    parser.add_argument("--server-memory", required=True)
    parser.add_argument("--noisy-producers", type=int, required=True)
    parser.add_argument("--noisy-requests-per-minute", type=int, required=True)
    parser.add_argument("--noisy-concurrent-requests", type=int, required=True)
    parser.add_argument("--control-latency-limit-seconds", type=float, required=True)
    parser.add_argument("--artifact", required=True)
    return parser.parse_args()


def percentile(values: list[float], percentile_value: float) -> float | None:
    if not values:
        return None
    ordered = sorted(values)
    index = min(len(ordered) - 1, math.ceil(len(ordered) * percentile_value) - 1)
    return round(ordered[index], 4)


def summarize(values: list[float]) -> dict[str, float | int | None]:
    return {
        "count": len(values),
        "p50": percentile(values, 0.50),
        "p95": percentile(values, 0.95),
        "max": round(max(values), 4) if values else None,
    }


def docker(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["docker", *args],
        check=check,
        text=True,
        capture_output=True,
        timeout=120,
    )


def container_name(project: str, service: str) -> str:
    result = docker(
        "ps",
        "-a",
        "--filter",
        f"label=com.docker.compose.project={project}",
        "--filter",
        f"label=com.docker.compose.service={service}",
        "--format",
        "{{.Names}}",
    )
    name = result.stdout.strip().splitlines()
    if len(name) != 1:
        raise RuntimeError(f"expected one {service} container for {project}, found {name}")
    return name[0]


def resource_sample(project: str) -> dict[str, Any]:
    names = [container_name(project, service) for service in ("server", "mysql", "redis", "scheduler")]
    stats = docker("stats", "--no-stream", "--format", "{{json .}}", *names)
    return {
        "at": time.time(),
        "containers": [json.loads(line) for line in stats.stdout.splitlines() if line.strip()],
    }


async def queue_sample(
    clients: dict[str, Client],
    queues: dict[str, tuple[str, str]],
    phase: str,
) -> dict[str, Any]:
    sample: dict[str, Any] = {"at": time.time(), "phase": phase, "namespaces": {}}
    for namespace, queue_names in queues.items():
        namespace_sample: dict[str, Any] = {}
        for queue in queue_names:
            try:
                description = await clients[namespace].describe_task_queue(queue)
                namespace_sample[queue] = {
                    "observed": True,
                    "stats": description.stats,
                    "admission": description.admission.raw if description.admission else None,
                }
            except Exception as error:
                body = error.body if isinstance(error, ServerError) and isinstance(error.body, dict) else {}
                namespace_sample[queue] = {
                    "observed": False,
                    "error": type(error).__name__,
                    "status": error.status if isinstance(error, ServerError) else None,
                    "reason": body.get("reason"),
                }
        sample["namespaces"][namespace] = namespace_sample
    return sample


async def operator_metrics(clients: dict[str, Client]) -> dict[str, Any]:
    snapshots: dict[str, Any] = {}
    for namespace, client in clients.items():
        try:
            snapshots[namespace] = await client._request("GET", "/system/metrics")
        except Exception as error:
            body = error.body if isinstance(error, ServerError) and isinstance(error.body, dict) else {}
            snapshots[namespace] = {
                "error": type(error).__name__,
                "status": error.status if isinstance(error, ServerError) else None,
                "reason": body.get("reason"),
            }
    return snapshots


async def eventually_ready(client: Client, timeout: float = 60.0) -> None:
    deadline = time.monotonic() + timeout
    while True:
        try:
            health = await client.health()
            if health.get("status") in {"serving", "up"}:
                return
        except Exception:
            pass
        if time.monotonic() >= deadline:
            raise TimeoutError("Server did not recover before the readiness deadline")
        await asyncio.sleep(0.5)


async def setup(base_url: str, token: str) -> dict[str, Any]:
    async with Client(base_url, token=token, namespace="default") as client:
        for namespace in NAMESPACES:
            try:
                await client.create_namespace(namespace, description="Namespace isolation experiment")
            except ServerError as error:
                if error.status != 409:
                    raise
            await client.set_namespace_external_storage(
                namespace,
                driver="local",
                threshold_bytes=1024,
            )
        return await client.get_cluster_info()


async def run_worker(client: Client, queue: str, worker_id: str) -> None:
    worker = Worker(
        client,
        task_queue=queue,
        workflows=[MixedWorkflow, ChildWorkflow],
        activities=[echo],
        worker_id=worker_id,
        poll_timeout=2,
        max_concurrent_workflow_tasks=4,
        max_concurrent_activity_tasks=4,
        heartbeat_interval=10,
    )
    await worker.run()


async def call(
    stats: OperationStats,
    operation: str,
    awaitable: Callable[[], Awaitable[Any]],
) -> Any | None:
    stats.attempts[operation] += 1
    try:
        result = await awaitable()
        stats.successes[operation] += 1
        return result
    except Exception as error:
        stats.record_error(operation, error)
        return None


async def control_loop(
    client: Client,
    queues: tuple[str, str],
    stop: asyncio.Event,
    stats: OperationStats,
    disruption_epoch: list[int],
) -> None:
    index = 0
    while not stop.is_set():
        started = time.monotonic()
        started_epoch = disruption_epoch[0]
        operation = "control_workflow"
        stats.attempts[operation] += 1
        try:
            handle = await client.start_workflow(
                workflow_type=WORKFLOW_TYPE,
                task_queue=queues[index % len(queues)],
                workflow_id=f"control-{uuid.uuid4().hex}",
                input=[{"mode": "activity", "value": index}],
            )
            await handle.result(timeout=20, poll_interval=0.1)
            if started_epoch == disruption_epoch[0]:
                stats.successes[operation] += 1
                stats.control_latencies.append(time.monotonic() - started)
        except Exception as error:
            stats.record_error(operation, error)
        index += 1
        await asyncio.sleep(0.15)


async def exercise_interactive(handle: Any, stats: OperationStats) -> None:
    await asyncio.sleep(0.15)
    await call(stats, "query", lambda: handle.query("state"))
    await call(stats, "update", lambda: handle.update("increment", [1], wait_for="completed", wait_timeout_seconds=5))
    await call(stats, "signal", lambda: handle.signal("increment", [1]))
    await call(stats, "signal", lambda: handle.signal("finish"))


async def noisy_loop(
    client: Client,
    queues: tuple[str, str],
    stop: asyncio.Event,
    stats: OperationStats,
    seed: int,
) -> None:
    index = seed
    modes = ("history", "timer", "child", "interactive", "external")
    background: set[asyncio.Task[Any]] = set()
    while not stop.is_set():
        mode = modes[index % len(modes)]
        payload: dict[str, Any] = {
            "mode": "activity" if mode == "external" else mode,
            "history_steps": 8 if mode == "history" else 1,
            "value": "x" * 4096 if mode == "external" else index,
        }
        handle = await call(
            stats,
            f"workflow_start_{mode}",
            lambda: client.start_workflow(
                workflow_type=WORKFLOW_TYPE,
                task_queue=queues[index % len(queues)],
                workflow_id=f"noisy-{uuid.uuid4().hex}",
                input=[payload],
            ),
        )
        if handle is not None and mode == "interactive":
            task = asyncio.create_task(exercise_interactive(handle, stats))
            background.add(task)
            task.add_done_callback(background.discard)

        if index % 4 == 0:
            await call(
                stats,
                "standalone_activity",
                lambda: client.start_activity(
                    activity_type=ACTIVITY_TYPE,
                    task_queue=queues[index % len(queues)],
                    activity_id=f"noisy-activity-{uuid.uuid4().hex}",
                    input=[index],
                ),
            )
        if index % 6 == 0:
            await call(
                stats,
                "schedule",
                lambda: client.create_schedule(
                    schedule_id=f"noisy-schedule-{uuid.uuid4().hex}",
                    spec=ScheduleSpec(cron_expressions=["0 0 1 1 *"]),
                    action=ScheduleAction(workflow_type=WORKFLOW_TYPE, task_queue=queues[0], input=[payload]),
                    paused=True,
                ),
            )
        if index % 8 == 0:
            await call(
                stats,
                "nexus",
                lambda: client.execute_nexus_operation(
                    endpoint_name="isolation",
                    service_name="work",
                    operation_name="start",
                    arguments=[payload],
                    idempotency_key=f"noisy-nexus-{uuid.uuid4().hex}",
                    mode="async",
                    wait_for="accepted",
                    caller_namespace="noisy",
                    queue=queues[0],
                    raise_on_failure=False,
                ),
            )
        index += 1
        await asyncio.sleep(0.01)
    if background:
        await asyncio.gather(*background, return_exceptions=True)


async def create_nexus_catalog(client: Client, stats: OperationStats) -> None:
    operations = (
        ("endpoint", lambda: client._request("POST", "/service-endpoints", json={"endpoint_name": "isolation"})),
        ("service", lambda: client._request("POST", "/service-endpoints/isolation/services", json={"service_name": "work"})),
        (
            "operation",
            lambda: client._request(
                "POST",
                "/service-endpoints/isolation/services/work/operations",
                json={
                    "operation_name": "start",
                    "operation_mode": "async",
                    "handler_binding_kind": "start_workflow",
                    "handler_target_reference": WORKFLOW_TYPE,
                },
            ),
        ),
    )
    for operation, request in operations:
        try:
            await request()
        except ServerError as error:
            if error.status != 409:
                stats.record_error(f"nexus_catalog_{operation}", error)


async def disruption(
    project: str,
    service: str,
    pause_seconds: float,
    client: Client,
    disruption_epoch: list[int],
) -> dict[str, Any]:
    name = container_name(project, service)
    started = time.monotonic()
    disruption_epoch[0] += 1
    docker("stop", name)
    probe: dict[str, Any] = {"service": service, "during_outage": {}}
    await asyncio.sleep(pause_seconds)
    try:
        await client.list_workflows(page_size=1)
        probe["during_outage"] = {"accepted": True}
    except Exception as error:
        body = error.body if isinstance(error, ServerError) and isinstance(error.body, dict) else {}
        probe["during_outage"] = {
            "accepted": False,
            "error": type(error).__name__,
            "status": error.status if isinstance(error, ServerError) else None,
            "reason": body.get("reason"),
        }
    docker("start", name)
    await eventually_ready(client)
    disruption_epoch[0] += 1
    probe["recovery_seconds"] = round(time.monotonic() - started, 3)
    return probe


async def recovery_probe(client: Client, queue: str, namespace: str) -> dict[str, Any]:
    started = time.monotonic()
    deadline = started + 75
    attempts = 0
    handle = None
    while handle is None:
        attempts += 1
        try:
            handle = await client.start_workflow(
                workflow_type=WORKFLOW_TYPE,
                task_queue=queue,
                workflow_id=f"{namespace}-recovery-{uuid.uuid4().hex}",
                input=[{"mode": "activity", "value": "recovered"}],
            )
        except ServerError as error:
            if error.status in (429, 503) and time.monotonic() < deadline:
                await asyncio.sleep(2)
                continue
            body = error.body if isinstance(error.body, dict) else {}
            return {
                "completed": False,
                "attempts": attempts,
                "latency_seconds": round(time.monotonic() - started, 4),
                "error": type(error).__name__,
                "status": error.status,
                "reason": body.get("reason"),
            }
    try:
        while True:
            try:
                result = await handle.result(timeout=30, poll_interval=2.5)
                return {
                    "completed": True,
                    "attempts": attempts,
                    "latency_seconds": round(time.monotonic() - started, 4),
                    "result": result,
                }
            except (ServerError, TimeoutError) as error:
                retryable = isinstance(error, TimeoutError) or error.status in (429, 503)
                if retryable and time.monotonic() < deadline:
                    attempts += 1
                    await asyncio.sleep(5)
                    continue
                raise
    except Exception as error:
        body = error.body if isinstance(error, ServerError) and isinstance(error.body, dict) else {}
        return {
            "completed": False,
            "attempts": attempts,
            "latency_seconds": round(time.monotonic() - started, 4),
            "error": type(error).__name__,
            "status": error.status if isinstance(error, ServerError) else None,
            "reason": body.get("reason"),
        }


async def main() -> int:
    args = parse_args()
    stats = OperationStats()
    cluster = await setup(args.base_url, args.token)
    clients = {
        namespace: Client(args.base_url, token=args.token, namespace=namespace, timeout=10)
        for namespace in NAMESPACES
    }
    queues = {
        "noisy": ("isolation-shared", "isolation-noisy"),
        "control": ("isolation-shared", "isolation-control"),
    }
    recovery_queues = {
        "noisy": "isolation-noisy-recovery",
        "control": "isolation-control-recovery",
    }
    workers = [
        asyncio.create_task(run_worker(clients[namespace], queue, f"{namespace}-{queue}"))
        for namespace in NAMESPACES
        for queue in queues[namespace]
    ]
    recovery_workers: list[asyncio.Task[Any]] = []
    stop = asyncio.Event()
    samples: list[dict[str, Any]] = []
    queue_samples: list[dict[str, Any]] = []
    disruptions: list[dict[str, Any]] = []
    disruption_epoch = [0]

    try:
        await asyncio.sleep(3)
        queue_samples.append(await queue_sample(clients, queues, "before_pressure"))
        await create_nexus_catalog(clients["noisy"], stats)
        load = [
            asyncio.create_task(
                control_loop(clients["control"], queues["control"], stop, stats, disruption_epoch)
            )
        ]
        load.extend(
            asyncio.create_task(noisy_loop(clients["noisy"], queues["noisy"], stop, stats, seed))
            for seed in range(args.noisy_producers)
        )
        started = time.monotonic()
        redis_done = False
        server_done = False
        while True:
            elapsed = time.monotonic() - started
            samples.append(await asyncio.to_thread(resource_sample, args.compose_project))
            if not redis_done and elapsed >= args.duration_seconds / 3:
                disruptions.append(
                    await disruption(args.compose_project, "redis", 2, clients["control"], disruption_epoch)
                )
                queue_samples.append(await queue_sample(clients, queues, "after_redis_recovery"))
                redis_done = True
            if not server_done and elapsed >= args.duration_seconds * 2 / 3:
                disruptions.append(
                    await disruption(args.compose_project, "server", 1, clients["control"], disruption_epoch)
                )
                queue_samples.append(await queue_sample(clients, queues, "after_server_recovery"))
                server_done = True
            if elapsed >= args.duration_seconds and redis_done and server_done:
                break
            await asyncio.sleep(1)
        stop.set()
        await asyncio.gather(*load, return_exceptions=True)
        queue_samples.append(await queue_sample(clients, queues, "after_pressure"))
        metric_snapshots = await operator_metrics(clients)

        for worker in workers:
            worker.cancel()
        await asyncio.gather(*workers, return_exceptions=True)
        recovery_workers = [
            asyncio.create_task(run_worker(clients[namespace], recovery_queues[namespace], f"{namespace}-recovery"))
            for namespace in NAMESPACES
        ]
        await asyncio.sleep(3)

        recovery = {
            namespace: await recovery_probe(clients[namespace], recovery_queues[namespace], namespace)
            for namespace in NAMESPACES
        }
        control_latency = summarize(stats.control_latencies)
        failures: list[str] = []
        if stats.successes["control_workflow"] < 5:
            failures.append("control namespace completed fewer than five workflows")
        if (
            control_latency["p95"] is None
            or float(control_latency["p95"]) > args.control_latency_limit_seconds
        ):
            failures.append(
                "control namespace p95 latency exceeded the "
                f"{args.control_latency_limit_seconds:g} second envelope"
            )
        quota_rejections = sum(
            count for reason, count in stats.rejections.items() if not reason.endswith("_unavailable")
        )
        if quota_rejections == 0:
            failures.append("no deterministic noisy-namespace throttling was observed")
        for operation in ("workflow_start_history", "workflow_start_timer", "workflow_start_child", "workflow_start_external", "query", "update", "signal", "schedule", "standalone_activity", "nexus"):
            if stats.attempts[operation] == 0:
                failures.append(f"{operation} was not attempted")
        if any(not result.get("completed") for result in recovery.values()):
            failures.append("a namespace failed the post-pressure recovery probe")
        if len(disruptions) != 2:
            failures.append("both Redis and Server disruptions were not completed")
        queue_evidence = {
            namespace: sum(
                1
                for sample in queue_samples
                for detail in sample["namespaces"].get(namespace, {}).values()
                if detail.get("observed")
            )
            for namespace in NAMESPACES
        }
        if any(count == 0 for count in queue_evidence.values()):
            failures.append("queue-depth diagnostics were not captured for both namespaces")
        required_metrics = {
            "dw_namespace_request_admission_rejections",
            "dw_namespace_durable_state_usage",
            "dw_runtime_external_payload_namespace_usage",
        }
        for namespace in NAMESPACES:
            payload = metric_snapshots.get(namespace, {})
            if payload.get("namespace") != namespace or not required_metrics.issubset(
                payload.get("metrics", {}).keys()
            ):
                failures.append(f"operator namespace metrics were unavailable for {namespace}")
        noisy_metrics = metric_snapshots.get("noisy", {}).get("metrics", {})
        visible_noisy_rejections = sum(
            int(noisy_metrics.get(metric, {}).get("rejections_this_minute", 0))
            for metric in required_metrics
        )
        if visible_noisy_rejections == 0:
            failures.append("no noisy-namespace budget rejection was visible in operator metrics")

        report = {
            "schema": "durable-workflow.server.namespace-isolation.v1",
            "passed": not failures,
            "failures": failures,
            "server": {
                "image": args.server_image,
                "version": cluster.get("version"),
                "worker_protocol": cluster.get("worker_protocol", {}).get("version"),
                "control_plane": cluster.get("control_plane", {}).get("version"),
            },
            "configuration": {
                "duration_seconds": args.duration_seconds,
                "python_sdk_version": args.sdk_version,
                "server_cpus": args.server_cpus,
                "server_memory": args.server_memory,
                "noisy_producers": args.noisy_producers,
                "noisy_requests_per_minute": args.noisy_requests_per_minute,
                "noisy_concurrent_requests": args.noisy_concurrent_requests,
                "workflow_activity_long_poll_slots": {"global": 8, "per_namespace": 4},
                "query_long_poll_slots": {"global": 4, "per_namespace": 2},
                "scheduler_interval_seconds": 10,
                "namespaces": list(NAMESPACES),
                "same_queue": "isolation-shared",
                "different_queues": ["isolation-noisy", "isolation-control"],
                "control_p95_limit_seconds": args.control_latency_limit_seconds,
            },
            "operations": stats.as_dict(),
            "queue_samples": queue_samples,
            "operator_metrics": metric_snapshots,
            "disruptions": disruptions,
            "recovery": recovery,
            "resource_samples": samples,
        }
        Path(args.artifact).write_text(json.dumps(report, indent=2, sort_keys=True) + "\n")
        print(json.dumps({key: report[key] for key in ("passed", "failures", "server", "operations", "disruptions", "recovery")}, indent=2, sort_keys=True))
        return 0 if report["passed"] else 1
    finally:
        stop.set()
        for worker in workers:
            worker.cancel()
        for worker in recovery_workers:
            worker.cancel()
        await asyncio.gather(*workers, *recovery_workers, return_exceptions=True)
        await asyncio.gather(*(client.aclose() for client in clients.values()), return_exceptions=True)


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
