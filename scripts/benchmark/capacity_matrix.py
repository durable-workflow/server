#!/usr/bin/env python3
"""Execute the versioned capacity cell-by-binding matrix and emit evidence."""

from __future__ import annotations

import argparse
from concurrent.futures import (
    Future,
    ThreadPoolExecutor,
    TimeoutError as FutureTimeoutError,
    wait,
)
from dataclasses import dataclass
from datetime import datetime, timezone
import hashlib
import json
import math
import os
from pathlib import Path
import random
from select import select
import signal
import subprocess
import sys
import threading
import time
from typing import Any, Callable
from urllib import error as urlerror
from urllib import parse as urlparse
from urllib import request as urlrequest

import capacity_suite


ROOT = capacity_suite.ROOT
SUITE_ROOT = capacity_suite.SUITE_ROOT
DEFAULT_COLLECTOR = SUITE_ROOT / "collectors/local-docker/collector.json"
DEFAULT_SMOKE = SUITE_ROOT / "topologies/local-docker/smoke.json"


class MatrixError(RuntimeError):
    """The executable benchmark matrix failed closed."""


def _required_environment(name: str, fallback: str | None = None) -> str:
    value = os.environ.get(name, fallback or "").strip()
    if not value:
        raise MatrixError(f"set {name}")
    return value


def _iso_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def _parse_time(value: Any) -> datetime | None:
    if not isinstance(value, str) or not value.strip():
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None
    return parsed if parsed.tzinfo is not None else None


@dataclass(frozen=True)
class MatrixCell:
    suite: dict[str, Any]
    cell: dict[str, Any]
    binding: str
    adapter: dict[str, Any]
    adapter_root: Path

    @property
    def execution(self) -> dict[str, Any]:
        return self.cell["execution"]

    def plan(self) -> dict[str, Any]:
        return {
            "cell_id": self.cell["id"],
            "binding": self.binding,
            "entrypoint": self.adapter["entrypoint"],
            "worker_concurrency": self.adapter["worker_concurrency"],
            "execution": self.execution,
        }


def build_matrix(
    suite: dict[str, Any],
    suite_path: Path,
    *,
    cell_ids: set[str] | None = None,
    bindings: set[str] | None = None,
) -> list[MatrixCell]:
    requested_cells = cell_ids or {str(cell["id"]) for cell in suite["cells"]}
    requested_bindings = bindings or set(capacity_suite.REQUIRED_BINDINGS)
    unknown_cells = requested_cells - {str(cell["id"]) for cell in suite["cells"]}
    unknown_bindings = requested_bindings - capacity_suite.REQUIRED_BINDINGS
    if unknown_cells:
        raise MatrixError(f"unknown capacity cells: {sorted(unknown_cells)}")
    if unknown_bindings:
        raise MatrixError(f"unknown capacity bindings: {sorted(unknown_bindings)}")
    matrix: list[MatrixCell] = []
    for cell in suite["cells"]:
        if cell["id"] not in requested_cells:
            continue
        for binding in suite["driver_contract"]["required_bindings"]:
            if binding not in requested_bindings:
                continue
            adapter_root = suite_path.parent / "bindings" / binding
            matrix.append(
                MatrixCell(
                    suite=suite,
                    cell=cell,
                    binding=binding,
                    adapter=capacity_suite.load_json(adapter_root / "adapter.json"),
                    adapter_root=adapter_root,
                )
            )
    return matrix


class JsonLineProcess:
    def __init__(
        self,
        command: list[str],
        *,
        cwd: Path,
        environment: dict[str, str],
        label: str,
    ) -> None:
        self.label = label
        try:
            self.process = subprocess.Popen(
                command,
                cwd=cwd,
                env=environment,
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                bufsize=1,
                start_new_session=True,
            )
        except OSError as exc:
            raise MatrixError(f"cannot start {label}: {exc}") from exc
        self.lock = threading.Lock()

    def request(
        self,
        command: dict[str, Any],
        *,
        timeout_seconds: float = 60,
        on_send: Callable[[float], None] | None = None,
    ) -> dict[str, Any]:
        with self.lock:
            if self.process.poll() is not None:
                self._raise_exited()
            assert self.process.stdin is not None
            assert self.process.stdout is not None
            try:
                if on_send is not None:
                    on_send(time.monotonic())
                self.process.stdin.write(
                    json.dumps(command, separators=(",", ":")) + "\n"
                )
                self.process.stdin.flush()
                readable, _, _ = select(
                    [self.process.stdout], [], [], max(0.001, timeout_seconds)
                )
                if not readable:
                    raise MatrixError(
                        f"{self.label} did not answer within {timeout_seconds:g} seconds"
                    )
                line = self.process.stdout.readline()
            except OSError as exc:
                raise MatrixError(f"{self.label} protocol write failed: {exc}") from exc
            if not line:
                self._raise_exited()
            try:
                response = json.loads(line)
            except json.JSONDecodeError as exc:
                raise MatrixError(
                    f"{self.label} emitted invalid JSON: {line!r}"
                ) from exc
            if not isinstance(response, dict):
                raise MatrixError(f"{self.label} emitted a non-object response")
            return response

    def _raise_exited(self) -> None:
        stderr = ""
        if self.process.stderr is not None:
            try:
                stderr = self.process.stderr.read().strip()
            except OSError:
                pass
        raise MatrixError(
            f"{self.label} exited with status {self.process.poll()}"
            + (f": {stderr[-1000:]}" if stderr else "")
        )

    def close(self) -> None:
        if self.process.poll() is not None:
            return
        try:
            os.killpg(self.process.pid, signal.SIGTERM)
            self.process.wait(timeout=10)
        except (OSError, subprocess.TimeoutExpired):
            try:
                os.killpg(self.process.pid, signal.SIGKILL)
                self.process.wait(timeout=5)
            except (OSError, subprocess.TimeoutExpired):
                pass


class WorkerProcess:
    def __init__(self, process: subprocess.Popen[str], label: str):
        self.process = process
        self.label = label

    def assert_running(self) -> None:
        status = self.process.poll()
        if status is None:
            return
        stderr = self.process.stderr.read().strip() if self.process.stderr else ""
        raise MatrixError(
            f"{self.label} exited with status {status}"
            + (f": {stderr[-1000:]}" if stderr else "")
        )

    def close(self) -> None:
        if self.process.poll() is not None:
            return
        try:
            os.killpg(self.process.pid, signal.SIGTERM)
            self.process.wait(timeout=15)
        except (OSError, subprocess.TimeoutExpired):
            try:
                os.killpg(self.process.pid, signal.SIGKILL)
                self.process.wait(timeout=5)
            except (OSError, subprocess.TimeoutExpired):
                pass


class ControlPlane:
    def __init__(self, runtime_url: str, namespace: str):
        self.runtime_url = runtime_url.rstrip("/")
        self.namespace = namespace
        self.token = (
            os.environ.get("DURABLE_WORKFLOW_TOKEN", "").strip()
            or os.environ.get("DURABLE_WORKFLOW_CLIENT_TOKEN", "").strip()
        )

    def get(self, path: str) -> dict[str, Any]:
        headers = {
            "Accept": "application/json",
            "X-Namespace": self.namespace,
            "X-Durable-Workflow-Control-Plane-Version": "2",
        }
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"
        try:
            with urlrequest.urlopen(
                urlrequest.Request(self.runtime_url + path, headers=headers), timeout=15
            ) as response:
                value = json.loads(response.read())
        except (OSError, urlerror.URLError, json.JSONDecodeError) as exc:
            raise MatrixError(f"control-plane read failed for {path}") from exc
        if not isinstance(value, dict):
            raise MatrixError(f"control-plane response for {path} is not an object")
        return value

    def wait_for_worker_capacity(
        self, task_queue: str, worker_concurrency: int, workers: list[WorkerProcess]
    ) -> None:
        path = "/api/task-queues/" + urlparse.quote(task_queue, safe="")
        deadline = time.monotonic() + 90
        last: dict[str, Any] = {}
        while time.monotonic() < deadline:
            for worker in workers:
                worker.assert_running()
            try:
                last = self.get(path)
            except MatrixError:
                time.sleep(0.25)
                continue
            stats = last.get("stats") if isinstance(last.get("stats"), dict) else {}
            admission = (
                last.get("admission") if isinstance(last.get("admission"), dict) else {}
            )
            workflow = (
                admission.get("workflow_tasks")
                if isinstance(admission.get("workflow_tasks"), dict)
                else {}
            )
            if (
                int(stats.get("pollers", {}).get("active_count", 0)) >= 1
                and int(workflow.get("configured_slot_count", 0)) == worker_concurrency
            ):
                return
            time.sleep(0.25)
        raise MatrixError(
            "worker readiness did not expose the declared concurrency "
            f"{worker_concurrency}: {last}"
        )

    def _history_bundle(self, workflow_id: str, run_id: str) -> dict[str, Any]:
        workflow = urlparse.quote(workflow_id, safe="")
        run = urlparse.quote(run_id, safe="")
        return self.get(f"/api/workflows/{workflow}/runs/{run}/history/export")

    def history_metrics(
        self,
        workflow_id: str,
        run_id: str,
        history_contract: dict[str, Any],
    ) -> dict[str, Any]:
        bundle = self._history_bundle(workflow_id, run_id)
        child_bundles: list[dict[str, Any]] = []
        if history_contract.get("count_scope") == "workflow_tree":
            links = bundle.get("links") if isinstance(bundle.get("links"), dict) else {}
            children = (
                links.get("children") if isinstance(links.get("children"), list) else []
            )
            for child in children:
                if not isinstance(child, dict):
                    continue
                child_workflow_id = child.get("child_workflow_instance_id")
                child_run_id = child.get("child_workflow_run_id")
                if not isinstance(child_workflow_id, str) or not isinstance(
                    child_run_id, str
                ):
                    raise MatrixError(
                        f"history export for {workflow_id} has an incomplete child link"
                    )
                child_bundles.append(
                    self._history_bundle(child_workflow_id, child_run_id)
                )
        try:
            contract_evidence = capacity_suite.validate_history_evidence(
                history_contract,
                bundle,
                child_bundles,
                path=f"history export for {workflow_id}",
            )
        except capacity_suite.ContractError as exc:
            raise MatrixError(str(exc)) from exc
        evidence_bundles = [bundle, *child_bundles]
        tasks = [
            task
            for evidence_bundle in evidence_bundles
            for task in (
                evidence_bundle.get("tasks")
                if isinstance(evidence_bundle.get("tasks"), list)
                else []
            )
        ]
        events = [
            event
            for evidence_bundle in evidence_bundles
            for event in (
                evidence_bundle.get("history_events")
                if isinstance(evidence_bundle.get("history_events"), list)
                else evidence_bundle.get("events")
                if isinstance(evidence_bundle.get("events"), list)
                else []
            )
        ]
        event_times: dict[str, list[datetime]] = {}
        for raw in events:
            if not isinstance(raw, dict):
                continue
            task_id = raw.get("workflow_task_id")
            recorded = _parse_time(raw.get("recorded_at") or raw.get("timestamp"))
            if isinstance(task_id, str) and recorded is not None:
                event_times.setdefault(task_id, []).append(recorded)
        schedule: list[float] = []
        replay: list[float] = []
        activity_dispatches = 0
        for raw in tasks:
            if not isinstance(raw, dict):
                continue
            dispatched = _parse_time(raw.get("last_dispatched_at"))
            available = _parse_time(raw.get("available_at")) or _parse_time(
                raw.get("created_at")
            )
            if (
                dispatched is not None
                and available is not None
                and dispatched >= available
            ):
                schedule.append(
                    round((dispatched - available).total_seconds() * 1000, 3)
                )
            task_type = str(raw.get("type") or "")
            if task_type == "activity":
                activity_dispatches += 1
            if task_type == "workflow" and dispatched is not None:
                completed = max(
                    event_times.get(str(raw.get("id") or ""), []), default=None
                )
                if completed is not None and completed >= dispatched:
                    replay.append(
                        round((completed - dispatched).total_seconds() * 1000, 3)
                    )
        if not schedule:
            raise MatrixError(
                f"history export for {workflow_id} has no schedule-to-start evidence"
            )
        return {
            "schedule_to_start": schedule,
            "replay": replay,
            "activity_dispatches": activity_dispatches,
            "workload_history": contract_evidence,
        }


class MetricBuffer:
    def __init__(self) -> None:
        self.lock = threading.Lock()
        self.measurement_deadline: float | None = None
        self.counters = {
            phase: self._empty_counters() for phase in ("measurement", "drain")
        }
        self.latencies = {
            phase: self._empty_latencies() for phase in ("measurement", "drain")
        }
        self.demand = {
            phase: self._empty_demand() for phase in ("measurement", "drain")
        }
        self.workflow_cohorts = {
            phase: self._empty_workflow_cohorts() for phase in ("measurement", "drain")
        }
        self.open_workflows = 0
        self.measurement_open_workflows = 0
        self.open_long_lived_query_workflows = 0
        self.measurement_open_long_lived_query_workflows = 0

    @staticmethod
    def _empty_counters() -> dict[str, int]:
        return {
            "workflow_starts": 0,
            "workflow_completions": 0,
            "activity_dispatches": 0,
            "errors": 0,
            "throttles": 0,
        }

    @staticmethod
    def _empty_latencies() -> dict[str, list[float]]:
        return {"schedule_to_start": [], "replay": [], "query": []}

    @staticmethod
    def _empty_demand() -> dict[str, dict[str, int]]:
        return {
            operation: {
                "attempted": 0,
                "accepted": 0,
                "completed": 0,
                "rejected": 0,
                "throttled": 0,
            }
            for operation in ("workflow_starts", "query_operations")
        }

    @staticmethod
    def _empty_workflow_cohorts() -> dict[str, dict[str, int]]:
        return {
            cohort: {"starts": 0, "completions": 0}
            for cohort in ("completion_required", "long_lived_query")
        }

    def reset(self) -> None:
        with self.lock:
            self.measurement_deadline = None
            self.counters = {
                phase: self._empty_counters() for phase in ("measurement", "drain")
            }
            self.latencies = {
                phase: self._empty_latencies() for phase in ("measurement", "drain")
            }
            self.demand = {
                phase: self._empty_demand() for phase in ("measurement", "drain")
            }
            self.workflow_cohorts = {
                phase: self._empty_workflow_cohorts()
                for phase in ("measurement", "drain")
            }
            self.open_workflows = 0
            self.measurement_open_workflows = 0
            self.open_long_lived_query_workflows = 0
            self.measurement_open_long_lived_query_workflows = 0

    def begin_measurement(self, deadline: float) -> None:
        with self.lock:
            if self.measurement_deadline is not None:
                raise MatrixError("measurement phase has already begun")
            if not math.isfinite(deadline) or deadline <= 0:
                raise MatrixError("measurement deadline must be a positive finite time")
            self.measurement_deadline = deadline

    def restart_measurement(self, deadline: float) -> None:
        with self.lock:
            if self.measurement_deadline is None:
                raise MatrixError("measurement phase has not begun")
            if not math.isfinite(deadline) or deadline <= 0:
                raise MatrixError("measurement deadline must be a positive finite time")
            self.measurement_deadline = deadline
            self.counters = {
                phase: self._empty_counters() for phase in ("measurement", "drain")
            }
            self.latencies = {
                phase: self._empty_latencies() for phase in ("measurement", "drain")
            }
            self.demand = {
                phase: self._empty_demand() for phase in ("measurement", "drain")
            }
            self.workflow_cohorts = {
                phase: self._empty_workflow_cohorts()
                for phase in ("measurement", "drain")
            }
            self.measurement_open_workflows = self.open_workflows
            self.measurement_open_long_lived_query_workflows = (
                self.open_long_lived_query_workflows
            )

    def _phase_at(self, recorded_at: float) -> str:
        return (
            "measurement"
            if self.measurement_deadline is not None
            and recorded_at < self.measurement_deadline
            else "drain"
        )

    @staticmethod
    def _callback_time(recorded_at: float | None) -> float:
        callback_time = time.monotonic() if recorded_at is None else recorded_at
        if not math.isfinite(callback_time):
            raise MatrixError("metric callback time must be finite")
        return callback_time

    def started(
        self,
        *,
        long_lived_query: bool = False,
        recorded_at: float | None = None,
    ) -> None:
        callback_time = self._callback_time(recorded_at)
        with self.lock:
            phase = self._phase_at(callback_time)
            self.demand[phase]["workflow_starts"]["accepted"] += 1
            self.counters[phase]["workflow_starts"] += 1
            cohort = "long_lived_query" if long_lived_query else "completion_required"
            self.workflow_cohorts[phase][cohort]["starts"] += 1
            self.open_workflows += 1
            if long_lived_query:
                self.open_long_lived_query_workflows += 1
            if phase == "measurement":
                self.measurement_open_workflows += 1
                if long_lived_query:
                    self.measurement_open_long_lived_query_workflows += 1

    def workflow_attempted(self, *, recorded_at: float | None = None) -> None:
        callback_time = self._callback_time(recorded_at)
        with self.lock:
            phase = self._phase_at(callback_time)
            self.demand[phase]["workflow_starts"]["attempted"] += 1

    def completed(
        self,
        evidence: dict[str, Any],
        include_replay: bool,
        *,
        long_lived_query: bool = False,
        recorded_at: float | None = None,
    ) -> None:
        callback_time = self._callback_time(recorded_at)
        with self.lock:
            phase = self._phase_at(callback_time)
            self.demand[phase]["workflow_starts"]["completed"] += 1
            self.counters[phase]["workflow_completions"] += 1
            cohort = "long_lived_query" if long_lived_query else "completion_required"
            self.workflow_cohorts[phase][cohort]["completions"] += 1
            self.counters[phase]["activity_dispatches"] += int(
                evidence["activity_dispatches"]
            )
            self.latencies[phase]["schedule_to_start"].extend(
                evidence["schedule_to_start"]
            )
            if include_replay:
                self.latencies[phase]["replay"].extend(evidence["replay"])
            self.open_workflows -= 1
            if long_lived_query:
                self.open_long_lived_query_workflows -= 1
            if phase == "measurement":
                self.measurement_open_workflows -= 1
                if long_lived_query:
                    self.measurement_open_long_lived_query_workflows -= 1

    @staticmethod
    def _throttled(response: dict[str, Any] | None) -> bool:
        text = json.dumps(response or {}).lower()
        return "throttl" in text or "429" in text

    def _rejected_demand(
        self,
        operation: str,
        response: dict[str, Any] | None,
        *,
        recorded_at: float | None = None,
    ) -> None:
        callback_time = self._callback_time(recorded_at)
        with self.lock:
            phase = self._phase_at(callback_time)
            field = "throttled" if self._throttled(response) else "rejected"
            self.demand[phase][operation][field] += 1
            aggregate = "throttles" if field == "throttled" else "errors"
            self.counters[phase][aggregate] += 1

    def workflow_rejected(
        self,
        response: dict[str, Any] | None,
        *,
        recorded_at: float | None = None,
    ) -> None:
        self._rejected_demand("workflow_starts", response, recorded_at=recorded_at)

    def query_rejected(
        self,
        response: dict[str, Any] | None,
        *,
        recorded_at: float | None = None,
    ) -> None:
        self._rejected_demand("query_operations", response, recorded_at=recorded_at)

    def failed(
        self,
        response: dict[str, Any] | None = None,
        *,
        was_open: bool = False,
        long_lived_query: bool = False,
        recorded_at: float | None = None,
    ) -> None:
        callback_time = self._callback_time(recorded_at)
        with self.lock:
            phase = self._phase_at(callback_time)
            if self._throttled(response):
                self.counters[phase]["throttles"] += 1
            else:
                self.counters[phase]["errors"] += 1
            if was_open:
                self.open_workflows -= 1
                if long_lived_query:
                    self.open_long_lived_query_workflows -= 1
                if phase == "measurement":
                    self.measurement_open_workflows -= 1
                    if long_lived_query:
                        self.measurement_open_long_lived_query_workflows -= 1

    def query_attempted(self, *, recorded_at: float | None = None) -> None:
        callback_time = self._callback_time(recorded_at)
        with self.lock:
            phase = self._phase_at(callback_time)
            self.demand[phase]["query_operations"]["attempted"] += 1

    def query_completed(
        self, elapsed_ms: float, *, recorded_at: float | None = None
    ) -> None:
        callback_time = self._callback_time(recorded_at)
        with self.lock:
            phase = self._phase_at(callback_time)
            self.demand[phase]["query_operations"]["accepted"] += 1
            self.demand[phase]["query_operations"]["completed"] += 1
            self.latencies[phase]["query"].append(elapsed_ms)

    def snapshot(
        self, phase: str
    ) -> tuple[
        dict[str, int],
        dict[str, list[float]],
        dict[str, dict[str, int]],
        dict[str, dict[str, int]],
        int,
        int,
    ]:
        if phase not in {"measurement", "drain"}:
            raise MatrixError(f"unsupported metric phase: {phase}")
        with self.lock:
            counters = self.counters[phase]
            latencies = self.latencies[phase]
            demand = self.demand[phase]
            workflow_cohorts = self.workflow_cohorts[phase]
            self.counters[phase] = self._empty_counters()
            self.latencies[phase] = self._empty_latencies()
            self.demand[phase] = self._empty_demand()
            self.workflow_cohorts[phase] = self._empty_workflow_cohorts()
            open_workflows = (
                self.measurement_open_workflows
                if phase == "measurement"
                else self.open_workflows
            )
            open_long_lived_queries = (
                self.measurement_open_long_lived_query_workflows
                if phase == "measurement"
                else self.open_long_lived_query_workflows
            )
            return (
                counters,
                latencies,
                demand,
                workflow_cohorts,
                open_workflows,
                open_long_lived_queries,
            )


@dataclass
class OpenQuery:
    client: JsonLineProcess
    workflow_id: str


class CellRunner:
    def __init__(
        self,
        matrix_cell: MatrixCell,
        profile: dict[str, Any],
        collector_descriptor: dict[str, Any],
        collector_root: Path,
        *,
        runtime_url: str,
        namespace: str,
        task_queue_prefix: str,
        sample_interval: float,
        minimum_delivery_ratio: float,
    ) -> None:
        self.matrix_cell = matrix_cell
        self.suite = matrix_cell.suite
        self.cell = matrix_cell.cell
        self.execution = matrix_cell.execution
        self.binding = matrix_cell.binding
        self.profile = profile
        self.collector_descriptor = collector_descriptor
        self.collector_root = collector_root
        self.runtime_url = runtime_url
        self.namespace = namespace
        self.task_queue_prefix = task_queue_prefix
        self.sample_interval = sample_interval
        self.minimum_delivery_ratio = minimum_delivery_ratio
        self.control_plane = ControlPlane(runtime_url, namespace)
        self.metrics = MetricBuffer()
        self.query_lock = threading.Lock()
        self.query_workflows: list[OpenQuery] = []
        self.clients: list[JsonLineProcess] = []
        self.workers: list[WorkerProcess] = []
        self.collector: JsonLineProcess | None = None
        self.sequence = 0
        self.random = random.Random(int(self.execution["deterministic_seed"]))

    def _environment(self, task_queue: str) -> dict[str, str]:
        environment = os.environ.copy()
        environment.update(
            {
                "DURABLE_WORKFLOW_RUNTIME_URL": self.runtime_url,
                "DURABLE_WORKFLOW_NAMESPACE": self.namespace,
                "DURABLE_WORKFLOW_TASK_QUEUE": task_queue,
                "DURABLE_WORKFLOW_CAPACITY_CELL": str(self.cell["id"]),
                "DURABLE_WORKFLOW_WORKER_CONCURRENCY": str(
                    int(self.execution["worker_concurrency"])
                ),
            }
        )
        return environment

    def _entrypoint(
        self,
        descriptor: dict[str, Any],
        mode: str,
        environment: dict[str, str] | None = None,
    ) -> list[str]:
        command = [str(value) for value in descriptor["entrypoint"]] + [mode]
        binding = descriptor.get("binding")
        if not isinstance(binding, str):
            return command
        suffix = binding.upper().replace("-", "_")
        container = os.environ.get(f"CAPACITY_ADAPTER_CONTAINER_{suffix}", "").strip()
        if not container:
            return command
        workdir = os.environ.get(
            f"CAPACITY_ADAPTER_WORKDIR_{suffix}", "/capacity"
        ).strip()
        if not workdir.startswith("/"):
            raise MatrixError(f"{binding} adapter container workdir must be absolute")
        remote = ["docker", "exec", "--interactive", "--workdir", workdir]
        forwarded = environment or os.environ
        for name, value in sorted(forwarded.items()):
            if name.startswith("DURABLE_WORKFLOW_"):
                remote.extend(["--env", f"{name}={value}"])
        remote.append(container)
        remote.extend(command)
        return remote

    def _start_processes(self, task_queue: str) -> None:
        environment = self._environment(task_queue)
        concurrency = self.matrix_cell.adapter["worker_concurrency"]
        process_count = (
            int(self.execution["worker_concurrency"])
            if concurrency["model"] == "processes"
            else 1
        )
        for index in range(process_count):
            worker_environment = environment.copy()
            worker_environment["DURABLE_WORKFLOW_WORKER_PROCESS_INDEX"] = str(index)
            try:
                process = subprocess.Popen(
                    self._entrypoint(
                        self.matrix_cell.adapter, "worker", worker_environment
                    ),
                    cwd=self.matrix_cell.adapter_root,
                    env=worker_environment,
                    stdin=subprocess.DEVNULL,
                    stdout=subprocess.DEVNULL,
                    stderr=subprocess.PIPE,
                    text=True,
                    start_new_session=True,
                )
            except OSError as exc:
                raise MatrixError(f"cannot start {self.binding} worker: {exc}") from exc
            self.workers.append(
                WorkerProcess(process, f"{self.binding} worker {index}")
            )
        for index in range(int(self.execution["client_concurrency"])):
            self.clients.append(
                JsonLineProcess(
                    self._entrypoint(self.matrix_cell.adapter, "client", environment),
                    cwd=self.matrix_cell.adapter_root,
                    environment=environment,
                    label=f"{self.binding} client {index}",
                )
            )
        self.collector = JsonLineProcess(
            self._entrypoint(self.collector_descriptor, "sample"),
            cwd=self.collector_root,
            environment=environment,
            label="capacity infrastructure collector",
        )
        initialized = self.collector.request(
            {
                "operation": "initialize",
                "profile": self.profile,
                "runtime_url": self.runtime_url,
                "namespace": self.namespace,
            },
            timeout_seconds=180,
        )
        self._require_ok(initialized, "collector initialize")
        self.control_plane.wait_for_worker_capacity(
            task_queue, int(self.execution["worker_concurrency"]), self.workers
        )

    @staticmethod
    def _require_ok(response: dict[str, Any], operation: str) -> dict[str, Any]:
        if response.get("ok") is not True:
            raise MatrixError(f"{operation} failed: {response}")
        return response

    def _sample_infrastructure(self, task_queue: str) -> dict[str, Any]:
        assert self.collector is not None
        response = self._require_ok(
            self.collector.request(
                {"operation": "sample", "task_queue": task_queue},
                timeout_seconds=120,
            ),
            "collector sample",
        )
        result = response.get("result")
        if not isinstance(result, dict):
            raise MatrixError("collector sample result must be an object")
        return result

    def _payload(self, shape: str, sequence: int) -> dict[str, Any]:
        workload = capacity_suite.workload_contract(self.suite, self.cell, shape)
        payload = workload["payload"]
        input_size = int(payload["workflow_input_bytes"])
        result_size = int(payload["workflow_result_bytes"])
        digest = hashlib.sha256(
            f"{self.execution['deterministic_seed']}:{sequence}:{shape}".encode()
        ).hexdigest()
        blob = (digest * ((input_size // len(digest)) + 1))[:input_size]
        result_blob = (digest * ((result_size // len(digest)) + 1))[:result_size]
        return {
            "blob": blob,
            "result_blob": result_blob,
            "shape": shape,
            "contract_cell_id": shape,
            "payload_contract": payload,
        }

    def _mixed_shape(self) -> str:
        weighted = {
            shape: weight
            for shape, weight in self.cell["workload"]["mix"].items()
            if shape != "query-inspection"
        }
        scale = sum(
            int(round(float(weight) * 1_000_000)) for weight in weighted.values()
        )
        marker = self.random.randrange(scale)
        cumulative = 0
        for shape, weight in weighted.items():
            cumulative += int(round(float(weight) * 1_000_000))
            if marker < cumulative:
                return str(shape)
        return str(next(reversed(weighted)))

    def _new_identity(self, load_step: int) -> tuple[str, int]:
        self.sequence += 1
        sequence = self.sequence
        cell = str(self.cell["id"]).replace("_", "-")
        workflow_id = (
            f"capacity-v1-{self.binding}-{cell}-{load_step}-"
            f"{self.execution['deterministic_seed']}-{sequence}"
        )
        return workflow_id, sequence

    def _workflow_lifecycle(
        self,
        client: JsonLineProcess,
        workflow_id: str,
        sequence: int,
        shape: str,
        task_queue: str,
        stop: threading.Event,
        measured: bool,
        open_slots: threading.BoundedSemaphore,
        ready: Callable[[], None] | None = None,
    ) -> None:
        was_open = False
        start_attempted = False
        start_resolved = False

        def record_start_attempt(recorded_at: float) -> None:
            nonlocal start_attempted
            start_attempted = True
            if measured:
                self.metrics.workflow_attempted(recorded_at=recorded_at)

        try:
            start = client.request(
                {
                    "operation": "start",
                    "workflow_id": workflow_id,
                    "cell_id": self.cell["id"],
                    "task_queue": task_queue,
                    "payload": self._payload(shape, sequence),
                },
                on_send=record_start_attempt,
            )
            start_recorded_at = time.monotonic()
            if start.get("ok") is not True:
                if measured:
                    self.metrics.workflow_rejected(start, recorded_at=start_recorded_at)
                start_resolved = True
                return
            run_id = start.get("run_id")
            if not isinstance(run_id, str) or not run_id:
                raise MatrixError("adapter start response omitted the selected run_id")
            was_open = True
            if measured:
                self.metrics.started(
                    long_lived_query=shape == "query-inspection",
                    recorded_at=start_recorded_at,
                )
            start_resolved = True
            if shape == "signal":
                signal_bytes = int(
                    capacity_suite.workload_contract(self.suite, self.cell, shape)[
                        "payload"
                    ]["signal_bytes"]
                )
                signal_payload = "s" * signal_bytes
                for signal_sequence in range(4):
                    response = client.request(
                        {
                            "operation": "signal",
                            "workflow_id": workflow_id,
                            "name": "capacity.v1.append",
                            "arguments": [signal_sequence, signal_payload],
                        },
                        timeout_seconds=30,
                    )
                    self._require_ok(response, "signal")
            if shape == "query-inspection":
                query = OpenQuery(client, workflow_id)
                with self.query_lock:
                    self.query_workflows.append(query)
                if ready is not None:
                    ready()
                stop.wait()
                with self.query_lock:
                    if query in self.query_workflows:
                        self.query_workflows.remove(query)
                self._require_ok(
                    client.request(
                        {
                            "operation": "signal",
                            "workflow_id": workflow_id,
                            "name": "capacity.v1.finish",
                            "arguments": [],
                        },
                        timeout_seconds=30,
                    ),
                    "query workflow finish",
                )
            response = client.request(
                {
                    "operation": "result",
                    "workflow_id": workflow_id,
                    "timeout_seconds": int(
                        self.execution["termination"]["drain_timeout_seconds"]
                    ),
                },
                timeout_seconds=int(
                    self.execution["termination"]["drain_timeout_seconds"]
                )
                + 30,
            )
            completed_at = time.monotonic()
            self._require_ok(response, "workflow result")
            workload = capacity_suite.workload_contract(self.suite, self.cell, shape)
            if workload["activities"]:
                result = response.get("result")
                expected_result_bytes = int(
                    workload["payload"]["workflow_result_bytes"]
                )
                if (
                    not isinstance(result, str)
                    or len(result.encode("utf-8")) != expected_result_bytes
                ):
                    actual = (
                        len(result.encode("utf-8"))
                        if isinstance(result, str)
                        else type(result).__name__
                    )
                    raise MatrixError(
                        f"workflow result payload drifted for {shape}: expected "
                        f"{expected_result_bytes} UTF-8 bytes, observed {actual}"
                    )
            evidence = self.control_plane.history_metrics(
                workflow_id, run_id, workload["history"]
            )
            if measured:
                self.metrics.completed(
                    evidence,
                    include_replay=shape == "replay-heavy-history",
                    long_lived_query=shape == "query-inspection",
                    recorded_at=completed_at,
                )
            was_open = False
        except Exception as exc:
            if measured:
                if start_attempted and not start_resolved:
                    self.metrics.workflow_rejected({"error": str(exc)})
                else:
                    self.metrics.failed(
                        {"error": str(exc)},
                        was_open=was_open,
                        long_lived_query=shape == "query-inspection",
                    )
                was_open = False
            raise
        finally:
            open_slots.release()

    def _query_loop(
        self, load_step: int, stop: threading.Event, measured: bool
    ) -> None:
        definitions = self.cell["workload"].get("queries", [])
        if not definitions:
            return
        rate = (
            sum(
                float(definition.get("rate_per_load_unit_per_second", 0))
                for definition in definitions
                if isinstance(definition, dict)
            )
            * load_step
        )
        if rate <= 0:
            return
        interval = 1.0 / rate
        next_query = time.monotonic()
        cursor = 0
        while not stop.is_set():
            with self.query_lock:
                available = list(self.query_workflows)
            if not available:
                stop.wait(min(0.01, interval))
                continue
            query = available[cursor % len(available)]
            cursor += 1
            attempted = False

            def record_query_attempt(recorded_at: float) -> None:
                nonlocal attempted
                attempted = True
                if measured:
                    self.metrics.query_attempted(recorded_at=recorded_at)

            try:
                response = query.client.request(
                    {
                        "operation": "query",
                        "workflow_id": query.workflow_id,
                        "name": "capacity.v1.inspect_counter",
                        "arguments": [],
                    },
                    timeout_seconds=10,
                    on_send=record_query_attempt,
                )
            except Exception as exc:
                if measured and attempted:
                    self.metrics.query_rejected({"error": str(exc)})
                raise
            recorded_at = time.monotonic()
            if response.get("ok") is True:
                if measured:
                    self.metrics.query_completed(
                        float(response.get("elapsed_ms") or 0),
                        recorded_at=recorded_at,
                    )
            elif measured:
                self.metrics.query_rejected(response, recorded_at=recorded_at)
            next_query += interval
            delay = next_query - time.monotonic()
            if delay > 0:
                stop.wait(delay)
            elif delay < -1:
                next_query = time.monotonic()

    def _query_rate(self, load_step: int) -> float:
        return round(
            sum(
                float(definition.get("rate_per_load_unit_per_second", 0))
                for definition in self.cell["workload"].get("queries", [])
                if isinstance(definition, dict)
            )
            * load_step,
            6,
        )

    def _control(self, load_step: int) -> dict[str, Any]:
        return {
            "suite_version": capacity_suite.SUITE_VERSION,
            "deterministic_seed": int(self.execution["deterministic_seed"]),
            "concurrent_open_workflows": int(
                self.execution["concurrent_open_workflows"]
            ),
            "client_concurrency": int(self.execution["client_concurrency"]),
            "worker_concurrency": int(self.execution["worker_concurrency"]),
            "warmup_seconds": int(self.execution["warmup_seconds"]),
            "duration_seconds": int(self.execution["duration_seconds"]),
            "offered_load": capacity_suite._offered_load_contract(
                self.cell,
                load_step,
                {"minimum_offered_load_delivery_ratio": self.minimum_delivery_ratio},
            ),
            "termination": self.execution["termination"],
        }

    def _observation(
        self,
        *,
        load_step: int,
        sample_index: int,
        phase: str,
        interval_seconds: float,
        task_queue: str,
    ) -> dict[str, Any]:
        (
            counters,
            latencies,
            demand,
            workflow_cohorts,
            open_workflows,
            open_long_lived_queries,
        ) = self.metrics.snapshot(phase)
        observation = {
            "schema": capacity_suite.OBSERVATION_SCHEMA,
            "cell_id": self.cell["id"],
            "binding": self.binding,
            "load_step": load_step,
            "sample_index": sample_index,
            "phase": phase,
            "interval_seconds": round(max(interval_seconds, 0.001), 6),
            "control": self._control(load_step),
            "counters": counters,
            "workflow_cohorts": workflow_cohorts,
            "demand": demand,
            "latencies_ms": latencies,
            "concurrent_open_workflows": open_workflows,
            "concurrent_long_lived_query_workflows": open_long_lived_queries,
            "infrastructure": self._sample_infrastructure(task_queue),
        }
        capacity_suite.validate_observation(observation, "controller.observation")
        return observation

    def _start_query_cohort(
        self,
        *,
        load_step: int,
        task_queue: str,
        stop: threading.Event,
        measured: bool,
        executor: ThreadPoolExecutor,
        open_slots: threading.BoundedSemaphore,
    ) -> list[Future[None]]:
        try:
            return self._start_query_cohort_inner(
                load_step=load_step,
                task_queue=task_queue,
                stop=stop,
                measured=measured,
                executor=executor,
                open_slots=open_slots,
            )
        except Exception:
            stop.set()
            raise

    def _start_query_cohort_inner(
        self,
        *,
        load_step: int,
        task_queue: str,
        stop: threading.Event,
        measured: bool,
        executor: ThreadPoolExecutor,
        open_slots: threading.BoundedSemaphore,
    ) -> list[Future[None]]:
        target = capacity_suite.long_lived_query_target(self.cell)
        if target == 0:
            return []

        setup_deadline = time.monotonic() + int(
            self.execution["termination"]["drain_timeout_seconds"]
        )
        entries: list[tuple[Future[None], threading.Event]] = []
        while sum(ready.is_set() for _, ready in entries) < target:
            retained: list[tuple[Future[None], threading.Event]] = []
            for future, ready in entries:
                if future.done() and not ready.is_set():
                    failure = future.exception()
                    if failure is not None:
                        raise MatrixError(
                            f"query cohort workflow failed during setup: {failure}"
                        ) from failure
                    continue
                retained.append((future, ready))
            entries = retained
            unresolved = sum(
                not future.done() and not ready.is_set() for future, ready in entries
            )
            ready_count = sum(ready.is_set() for _, ready in entries)
            for _ in range(target - ready_count - unresolved):
                if not open_slots.acquire(blocking=False):
                    break
                workflow_id, sequence = self._new_identity(load_step)
                ready = threading.Event()
                client = self.clients[(sequence - 1) % len(self.clients)]
                future = executor.submit(
                    self._workflow_lifecycle,
                    client,
                    workflow_id,
                    sequence,
                    "query-inspection",
                    task_queue,
                    stop,
                    measured,
                    open_slots,
                    ready.set,
                )
                entries.append((future, ready))
            if time.monotonic() >= setup_deadline:
                raise MatrixError(
                    f"long-lived query cohort did not reach {target} workflows before the setup timeout"
                )
            if sum(ready.is_set() for _, ready in entries) < target:
                time.sleep(0.01)
        return [future for future, _ in entries]

    def _run_phase(
        self,
        *,
        load_step: int,
        seconds: float,
        task_queue: str,
        measured: bool,
        executor: ThreadPoolExecutor,
    ) -> list[dict[str, Any]]:
        stop = threading.Event()
        try:
            return self._run_phase_inner(
                load_step=load_step,
                seconds=seconds,
                task_queue=task_queue,
                measured=measured,
                executor=executor,
                stop=stop,
            )
        finally:
            stop.set()

    def _run_phase_inner(
        self,
        *,
        load_step: int,
        seconds: float,
        task_queue: str,
        measured: bool,
        executor: ThreadPoolExecutor,
        stop: threading.Event,
    ) -> list[dict[str, Any]]:
        self.random.seed(int(self.execution["deterministic_seed"]))
        open_slots = threading.BoundedSemaphore(
            int(self.execution["concurrent_open_workflows"])
        )
        if measured:
            setup_deadline = time.monotonic() + int(
                self.execution["termination"]["drain_timeout_seconds"]
            )
            self.metrics.begin_measurement(setup_deadline)
        futures = self._start_query_cohort(
            load_step=load_step,
            task_queue=task_queue,
            stop=stop,
            measured=measured,
            executor=executor,
            open_slots=open_slots,
        )
        started = time.monotonic()
        deadline = started + seconds
        if measured:
            self.metrics.restart_measurement(deadline)
        query_future = executor.submit(self._query_loop, load_step, stop, measured)
        workflow_rate = float(
            capacity_suite._offered_load_contract(
                self.cell,
                load_step,
                {"minimum_offered_load_delivery_ratio": self.minimum_delivery_ratio},
            )["workflow_starts_per_second"]
        )
        next_admission = started if workflow_rate else math.inf
        next_sample = min(deadline, started + self.sample_interval)
        last_sample = started
        sample_index = 0
        observations: list[dict[str, Any]] = []
        while True:
            now = time.monotonic()
            if now >= deadline:
                break
            if now >= next_admission:
                if open_slots.acquire(blocking=False):
                    workflow_id, sequence = self._new_identity(load_step)
                    shape = (
                        self._mixed_shape()
                        if self.cell["id"] == "mixed"
                        else str(self.cell["id"])
                    )
                    client = self.clients[(sequence - 1) % len(self.clients)]
                    futures.append(
                        executor.submit(
                            self._workflow_lifecycle,
                            client,
                            workflow_id,
                            sequence,
                            shape,
                            task_queue,
                            stop,
                            measured,
                            open_slots,
                        )
                    )
                next_admission += 1.0 / workflow_rate
                if next_admission < now - 1:
                    next_admission = now
                continue
            if measured and now >= next_sample:
                observations.append(
                    self._observation(
                        load_step=load_step,
                        sample_index=sample_index,
                        phase="measurement",
                        interval_seconds=next_sample - last_sample,
                        task_queue=task_queue,
                    )
                )
                sample_index += 1
                last_sample = next_sample
                next_sample = min(deadline, next_sample + self.sample_interval)
                continue
            wake = min(deadline, next_admission, next_sample if measured else deadline)
            time.sleep(max(0.0005, min(0.01, wake - now)))
        if measured and last_sample < deadline:
            observations.append(
                self._observation(
                    load_step=load_step,
                    sample_index=sample_index,
                    phase="measurement",
                    interval_seconds=deadline - last_sample,
                    task_queue=task_queue,
                )
            )
            sample_index += 1
        stop.set()
        try:
            query_future.result(timeout=10)
        except FutureTimeoutError as exc:
            raise MatrixError("query driver did not stop within 10 seconds") from exc
        except Exception as exc:
            raise MatrixError(f"query driver failed: {exc}") from exc
        drain_started = time.monotonic()
        drain_timeout = int(self.execution["termination"]["drain_timeout_seconds"])
        done, pending = wait(futures, timeout=drain_timeout)
        for future in done:
            failure = future.exception()
            if failure is not None:
                raise MatrixError(f"workflow lifecycle failed: {failure}") from failure
        if pending:
            for client in self.clients:
                client.close()
            wait(pending, timeout=10)
            raise MatrixError(
                f"{len(pending)} workflows did not drain within {drain_timeout} seconds"
            )
        if measured:
            observations.append(
                self._observation(
                    load_step=load_step,
                    sample_index=sample_index,
                    phase="drain",
                    interval_seconds=time.monotonic() - drain_started,
                    task_queue=task_queue,
                )
            )
        return observations

    def run_load_step(self, load_step: int) -> list[dict[str, Any]]:
        task_queue = (
            f"{self.task_queue_prefix}-{self.binding}-{self.cell['id']}-{load_step}"
        )
        maximum_threads = (
            int(self.execution["concurrent_open_workflows"])
            + int(self.execution["client_concurrency"])
            + 4
        )
        try:
            self._start_processes(task_queue)
            with ThreadPoolExecutor(
                max_workers=maximum_threads, thread_name_prefix="capacity-workflow"
            ) as executor:
                self._run_phase(
                    load_step=load_step,
                    seconds=float(self.execution["warmup_seconds"]),
                    task_queue=task_queue,
                    measured=False,
                    executor=executor,
                )
                self.metrics.reset()
                return self._run_phase(
                    load_step=load_step,
                    seconds=float(self.execution["duration_seconds"]),
                    task_queue=task_queue,
                    measured=True,
                    executor=executor,
                )
        finally:
            if self.collector is not None:
                self.collector.close()
            for client in self.clients:
                client.close()
            for worker in self.workers:
                worker.close()


def _write_jsonl(path: Path, rows: list[dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        "".join(
            json.dumps(row, separators=(",", ":"), sort_keys=True) + "\n"
            for row in rows
        )
    )


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "command", nargs="?", choices=("run", "dry-run", "smoke"), default="run"
    )
    parser.add_argument("--suite", type=Path, default=capacity_suite.DEFAULT_SUITE)
    parser.add_argument("--profile", type=Path, default=capacity_suite.DEFAULT_PROFILE)
    parser.add_argument("--collector", type=Path, default=DEFAULT_COLLECTOR)
    parser.add_argument("--cell", action="append", dest="cells")
    parser.add_argument("--binding", action="append", dest="bindings")
    parser.add_argument("--output-dir", type=Path, default=ROOT / "build/capacity-v1")
    parser.add_argument("--task-queue-prefix", default="capacity-v1")
    parser.add_argument("--sample-interval-seconds", type=float, default=1.0)
    parser.add_argument("--source-revision")
    parser.add_argument("--run-timestamp")
    parser.add_argument("--architecture")
    parser.add_argument("--smoke", type=Path, default=DEFAULT_SMOKE)
    return parser.parse_args(argv)


def _run_smoke(
    args: argparse.Namespace,
    suite: dict[str, Any],
    profile: dict[str, Any],
    collector: dict[str, Any],
) -> dict[str, Any]:
    smoke = capacity_suite.load_json(args.smoke)
    expected_identity = {
        "schema": "durable-workflow.capacity-local-docker-smoke/v1",
        "suite_version": suite["suite_version"],
        "profile_id": profile["profile_id"],
        "evidence_class": "local_topology_smoke",
        "publishable": False,
        "teardown": "always",
    }
    for name, value in expected_identity.items():
        if smoke.get(name) != value:
            raise MatrixError(f"local smoke {name} differs from the suite")
    binding = str(smoke.get("binding") or "")
    cell_id = str(smoke.get("cell_id") or "")
    if binding not in capacity_suite.REQUIRED_BINDINGS:
        raise MatrixError("local smoke binding must be first-party")
    source_cell = next((cell for cell in suite["cells"] if cell["id"] == cell_id), None)
    if source_cell is None:
        raise MatrixError("local smoke cell is absent from the suite")
    execution = smoke.get("execution")
    if not isinstance(execution, dict):
        raise MatrixError("local smoke execution must be an object")
    cell = json.loads(json.dumps(source_cell))
    cell["execution"] = execution
    adapter_root = args.suite.parent / "bindings" / binding
    matrix_cell = MatrixCell(
        suite=suite,
        cell=cell,
        binding=binding,
        adapter=capacity_suite.load_json(adapter_root / "adapter.json"),
        adapter_root=adapter_root,
    )
    runtime_url = _required_environment("DURABLE_WORKFLOW_RUNTIME_URL")
    namespace = _required_environment("DURABLE_WORKFLOW_NAMESPACE", "capacity")
    runner = CellRunner(
        matrix_cell,
        profile,
        collector,
        args.collector.parent,
        runtime_url=runtime_url,
        namespace=namespace,
        task_queue_prefix=args.task_queue_prefix + "-smoke",
        sample_interval=float(smoke["sample_interval_seconds"]),
        minimum_delivery_ratio=float(
            suite["operating_point_rule"]["minimum_offered_load_delivery_ratio"]
        ),
    )
    rows = runner.run_load_step(int(smoke["load_step"]))
    observations_path = args.output_dir / "local-docker-smoke.observations.jsonl"
    result_path = args.output_dir / "local-docker-smoke.result.json"
    _write_jsonl(observations_path, rows)
    step = capacity_suite.reduce_step(
        rows,
        suite["operating_point_rule"],
        float(execution["duration_seconds"]),
        cell_id,
    )
    qualified = bool(
        step["operating_point_eligible"]
        and isinstance(step.get("drain"), dict)
        and step["drain"].get("converged") is True
    )
    source_revision = args.source_revision or capacity_suite._git_revision()
    run_timestamp = capacity_suite._timestamp(args.run_timestamp or _iso_now())
    architecture = capacity_suite.normalize_architecture(
        args.architecture or profile["architecture"]["machine"]
    )
    if architecture != capacity_suite.normalize_architecture(
        profile["architecture"]["machine"]
    ):
        raise MatrixError("local smoke architecture differs from the profile")
    result = {
        "schema": "durable-workflow.capacity-local-docker-smoke-result/v1",
        "identity": {
            "suite_version": suite["suite_version"],
            "suite_sha256": capacity_suite.sha256_file(args.suite),
            "source_revision": capacity_suite._source_revision(source_revision),
            "infrastructure_profile": profile["profile_id"],
            "infrastructure_profile_sha256": capacity_suite.sha256_file(args.profile),
            "architecture": architecture,
            "binding": binding,
            "run_timestamp": run_timestamp,
        },
        "evidence_class": "local_topology_smoke",
        "publishable": False,
        "qualified": qualified,
        "cell_id": cell_id,
        "execution": execution,
        "load_step": step,
    }
    capacity_suite._write_result(result, result_path)
    if not qualified:
        raise MatrixError(f"local Docker smoke did not qualify: {result_path}")
    return result


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    try:
        suite = capacity_suite.load_json(args.suite)
        capacity_suite.validate_suite(suite, args.suite)
        profile = capacity_suite.load_json(args.profile)
        capacity_suite.validate_profile(profile)
        collector = capacity_suite.load_json(args.collector)
        capacity_suite.validate_collector(collector, args.collector, suite, profile)
        matrix = build_matrix(
            suite,
            args.suite,
            cell_ids=set(args.cells) if args.cells else None,
            bindings=set(args.bindings) if args.bindings else None,
        )
        plan = {
            "schema": "durable-workflow.capacity-benchmark-matrix-plan/v1",
            "suite_version": suite["suite_version"],
            "profile_id": profile["profile_id"],
            "collector": collector["entrypoint"],
            "matrix": [cell.plan() for cell in matrix],
        }
        if args.command == "dry-run":
            print(json.dumps(plan, indent=2, sort_keys=True))
            return 0
        if args.command == "smoke":
            result = _run_smoke(args, suite, profile, collector)
            print(
                "completed local Docker smoke: "
                f"{args.output_dir / 'local-docker-smoke.result.json'} "
                f"(qualified={str(result['qualified']).lower()}, publishable=false)",
                flush=True,
            )
            return 0
        if args.sample_interval_seconds <= 0:
            raise MatrixError("sample interval must be positive")
        runtime_url = _required_environment("DURABLE_WORKFLOW_RUNTIME_URL")
        namespace = _required_environment("DURABLE_WORKFLOW_NAMESPACE", "default")
        source_revision = args.source_revision or capacity_suite._git_revision()
        run_timestamp = capacity_suite._timestamp(args.run_timestamp or _iso_now())
        architecture = args.architecture or profile["architecture"]["machine"]
        for matrix_cell in matrix:
            rows: list[dict[str, Any]] = []
            for load_step in matrix_cell.execution["load_steps"]:
                runner = CellRunner(
                    matrix_cell,
                    profile,
                    collector,
                    args.collector.parent,
                    runtime_url=runtime_url,
                    namespace=namespace,
                    task_queue_prefix=args.task_queue_prefix,
                    sample_interval=args.sample_interval_seconds,
                    minimum_delivery_ratio=float(
                        suite["operating_point_rule"][
                            "minimum_offered_load_delivery_ratio"
                        ]
                    ),
                )
                rows.extend(runner.run_load_step(int(load_step)))
            stem = f"{matrix_cell.cell['id']}--{matrix_cell.binding}"
            observations_path = args.output_dir / f"{stem}.observations.jsonl"
            result_path = args.output_dir / f"{stem}.result.json"
            _write_jsonl(observations_path, rows)
            result = capacity_suite.reduce_result(
                suite,
                args.suite,
                profile,
                args.profile,
                rows,
                source_revision=source_revision,
                run_timestamp=run_timestamp,
                architecture=architecture,
            )
            capacity_suite._write_result(result, result_path)
            print(f"completed {stem}: {result_path}", flush=True)
        return 0
    except (capacity_suite.ContractError, MatrixError) as exc:
        print(f"capacity matrix error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
