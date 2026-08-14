#!/usr/bin/env python3
"""Executable Python binding for every capacity.v1 workload."""

from __future__ import annotations

import asyncio
import hashlib
import json
import os
from pathlib import Path
import sys
import time
from typing import Any, Generator


ADAPTER_PATH = Path(__file__).with_name("adapter.json")
WORKFLOW_TYPES = {
    "simple-start-complete": "capacity.v1.simple",
    "one-activity": "capacity.v1.one_activity",
    "multiple-activities": "capacity.v1.multiple_activities",
    "timer": "capacity.v1.timer",
    "signal": "capacity.v1.signal",
    "child-workflow-fanout": "capacity.v1.child_parent",
    "replay-heavy-history": "capacity.v1.replay_heavy",
    "query-inspection": "capacity.v1.queryable_counter",
    "mixed": "capacity.v1.mixed_selector",
}


def describe() -> None:
    descriptor = json.loads(ADAPTER_PATH.read_text())
    print(json.dumps(descriptor, separators=(",", ":"), sort_keys=True), flush=True)


if len(sys.argv) > 1 and sys.argv[1] == "describe":
    describe()
    raise SystemExit(0)


def request(value: object) -> dict[str, Any]:
    return value if isinstance(value, dict) else {}


def blob(value: dict[str, Any]) -> str:
    candidate = value.get("blob")
    return candidate if isinstance(candidate, str) else ""


def result_blob(value: dict[str, Any]) -> str:
    candidate = value.get("result_blob")
    return candidate if isinstance(candidate, str) else blob(value)


def payload_contract(value: dict[str, Any]) -> dict[str, int]:
    candidate = value.get("payload_contract")
    if not isinstance(candidate, dict):
        raise ValueError("capacity workload omitted its payload contract")
    contract: dict[str, int] = {}
    for field in (
        "workflow_input_bytes",
        "workflow_result_bytes",
        "activity_input_bytes",
        "activity_result_bytes",
        "signal_bytes",
    ):
        size = candidate.get(field)
        if not isinstance(size, int) or isinstance(size, bool) or size < 0:
            raise ValueError(f"capacity payload contract has invalid {field}")
        contract[field] = size
    return contract


def require_utf8_size(value: object, expected: int, boundary: str) -> str:
    if not isinstance(value, str):
        raise ValueError(f"{boundary} must be a string")
    actual = len(value.encode("utf-8"))
    if actual != expected:
        raise ValueError(
            f"{boundary} must contain {expected} UTF-8 bytes; observed {actual}"
        )
    return value


def sized_ascii(seed: str, size: int) -> str:
    if not seed or not seed.isascii():
        raise ValueError("capacity payload expansion requires a non-empty ASCII seed")
    return (seed * ((size // len(seed)) + 1))[:size]


def initial_activity_input(value: dict[str, Any]) -> str:
    contract = payload_contract(value)
    workflow_input = require_utf8_size(
        blob(value), contract["workflow_input_bytes"], "workflow input"
    )
    return require_utf8_size(
        workflow_input, contract["activity_input_bytes"], "activity input"
    )


def checked_activity_result(value: object, contract: dict[str, int]) -> str:
    return require_utf8_size(
        value, contract["activity_result_bytes"], "activity result"
    )


def conformance_evidence(fixtures: dict[str, Any]) -> dict[str, Any]:
    cases: list[dict[str, Any]] = []
    for fixture in fixtures.get("payload_cases", []):
        if not isinstance(fixture, dict):
            raise ValueError("payload fixture must be an object")
        contract = fixture.get("payload")
        if not isinstance(contract, dict):
            raise ValueError("payload fixture omitted its contract")
        value = {
            "blob": sized_ascii("payload", int(contract["workflow_input_bytes"])),
            "payload_contract": contract,
        }
        current = initial_activity_input(value)
        input_sizes: list[int] = []
        result_sizes: list[int] = []
        activity_type = fixture.get("activity_type")
        for index in range(int(fixture.get("activity_count", 0))):
            input_sizes.append(len(current.encode("utf-8")))
            if activity_type == "capacity.v1.echo":
                result = current
            elif activity_type == "capacity.v1.hash":
                result = hashlib.sha256(current.encode()).hexdigest()
            else:
                raise ValueError(f"unsupported fixture activity type: {activity_type}")
            result = checked_activity_result(result, payload_contract(value))
            result_sizes.append(len(result.encode("utf-8")))
            if index + 1 < int(fixture["activity_count"]):
                current = sized_ascii(
                    result, payload_contract(value)["activity_input_bytes"]
                )
        cases.append(
            {
                "id": fixture.get("id"),
                "activity_input_bytes": input_sizes,
                "activity_result_bytes": result_sizes,
            }
        )
    return {
        "schema": "durable-workflow.capacity-workload-conformance-evidence/v1",
        "cases": cases,
    }


if len(sys.argv) > 1 and sys.argv[1] == "conformance":
    if len(sys.argv) != 3:
        raise SystemExit("conformance requires a fixture path")
    fixture_value = json.loads(Path(sys.argv[2]).read_text())
    if not isinstance(fixture_value, dict):
        raise SystemExit("conformance fixture must contain an object")
    print(
        json.dumps(
            conformance_evidence(fixture_value), separators=(",", ":"), sort_keys=True
        ),
        flush=True,
    )
    raise SystemExit(0)


from durable_workflow import Client, Worker, activity, workflow  # noqa: E402
from durable_workflow.workflow import WorkflowContext  # noqa: E402


@activity.defn(name="capacity.v1.echo")
def echo_activity(payload: str) -> str:
    return payload


@activity.defn(name="capacity.v1.hash")
def hash_activity(payload: str) -> str:
    return hashlib.sha256(payload.encode()).hexdigest()


@workflow.defn(name="capacity.v1.simple")
class SimpleWorkflow:
    def run(self, ctx: WorkflowContext, value: dict[str, Any]) -> str:
        return result_blob(request(value))


@workflow.defn(name="capacity.v1.one_activity")
class OneActivityWorkflow:
    def run(
        self, ctx: WorkflowContext, value: dict[str, Any]
    ) -> Generator[Any, Any, str]:
        value = request(value)
        contract = payload_contract(value)
        result = yield ctx.schedule_activity(
            "capacity.v1.echo", [initial_activity_input(value)]
        )
        return checked_activity_result(result, contract)


@workflow.defn(name="capacity.v1.multiple_activities")
class MultipleActivitiesWorkflow:
    def run(
        self, ctx: WorkflowContext, value: dict[str, Any]
    ) -> Generator[Any, Any, str]:
        value = request(value)
        contract = payload_contract(value)
        digest = initial_activity_input(value)
        for index in range(5):
            digest = checked_activity_result(
                (yield ctx.schedule_activity("capacity.v1.hash", [digest])), contract
            )
            if index < 4:
                digest = sized_ascii(digest, contract["activity_input_bytes"])
        return digest


@workflow.defn(name="capacity.v1.timer")
class TimerWorkflow:
    def run(self, ctx: WorkflowContext) -> Generator[Any, Any, str]:
        yield ctx.sleep(1)
        return "capacity.timer"


@workflow.defn(name="capacity.v1.signal")
class SignalWorkflow:
    def __init__(self) -> None:
        self.sequences: list[int] = []

    @workflow.signal("capacity.v1.append")
    def append(self, sequence: int, payload: str) -> None:
        del payload
        self.sequences.append(sequence)

    def run(self, ctx: WorkflowContext) -> Generator[Any, Any, int]:
        yield ctx.wait_condition(
            lambda: len(self.sequences) >= 4,
            key="capacity.v1.four-ordered-signals",
        )
        if self.sequences[:4] != [0, 1, 2, 3]:
            raise ValueError(
                "capacity.v1.append signals must retain sequence 0 through 3"
            )
        return len(self.sequences)


@workflow.defn(name="capacity.v1.child_leaf")
class ChildLeafWorkflow:
    def run(self, ctx: WorkflowContext, index: int) -> int:
        return index


@workflow.defn(name="capacity.v1.child_parent")
class ChildParentWorkflow:
    def run(
        self, ctx: WorkflowContext, value: dict[str, Any]
    ) -> Generator[Any, Any, int]:
        task_queue = request(value).get("task_queue")
        children = [
            ctx.start_child_workflow(
                "capacity.v1.child_leaf",
                [index],
                task_queue=task_queue if isinstance(task_queue, str) else None,
            )
            for index in range(10)
        ]
        results = yield children
        return sum(int(result) for result in results)


@workflow.defn(name="capacity.v1.replay_heavy")
class ReplayHeavyWorkflow:
    def run(self, ctx: WorkflowContext) -> Generator[Any, Any, int]:
        for index in range(500):
            yield ctx.side_effect(lambda value=index: value)
        return 500


@workflow.defn(name="capacity.v1.queryable_counter")
class QueryableCounterWorkflow:
    def __init__(self) -> None:
        self.finished = False
        self.counter = 0

    @workflow.signal("capacity.v1.finish")
    def finish(self) -> None:
        self.finished = True

    @workflow.query("capacity.v1.inspect_counter")
    def inspect_counter(self) -> int:
        return self.counter

    def run(self, ctx: WorkflowContext) -> Generator[Any, Any, int]:
        yield ctx.wait_condition(
            lambda: self.finished,
            key="capacity.v1.finish-query-workflow",
        )
        return self.counter


@workflow.defn(name="capacity.v1.mixed_selector")
class MixedSelectorWorkflow:
    def __init__(self) -> None:
        self.sequences: list[int] = []
        self.finished = False
        self.counter = 0

    @workflow.signal("capacity.v1.append")
    def append(self, sequence: int, payload: str) -> None:
        del payload
        self.sequences.append(sequence)

    @workflow.signal("capacity.v1.finish")
    def finish(self) -> None:
        self.finished = True

    @workflow.query("capacity.v1.inspect_counter")
    def inspect_counter(self) -> int:
        return self.counter

    def run(
        self, ctx: WorkflowContext, value: dict[str, Any]
    ) -> Generator[Any, Any, Any]:
        value = request(value)
        shape = value.get("shape")
        if shape == "simple-start-complete":
            return result_blob(value)
        if shape == "one-activity":
            contract = payload_contract(value)
            result = yield ctx.schedule_activity(
                "capacity.v1.echo", [initial_activity_input(value)]
            )
            return checked_activity_result(result, contract)
        if shape == "multiple-activities":
            contract = payload_contract(value)
            digest = initial_activity_input(value)
            for index in range(5):
                digest = checked_activity_result(
                    (yield ctx.schedule_activity("capacity.v1.hash", [digest])),
                    contract,
                )
                if index < 4:
                    digest = sized_ascii(digest, contract["activity_input_bytes"])
            return digest
        if shape == "timer":
            yield ctx.sleep(1)
            return "capacity.timer"
        if shape == "signal":
            yield ctx.wait_condition(
                lambda: len(self.sequences) >= 4,
                key="capacity.v1.mixed-four-ordered-signals",
            )
            if self.sequences[:4] != [0, 1, 2, 3]:
                raise ValueError(
                    "capacity.v1.append signals must retain sequence 0 through 3"
                )
            return len(self.sequences)
        if shape == "child-workflow-fanout":
            task_queue = value.get("task_queue")
            results = yield [
                ctx.start_child_workflow(
                    "capacity.v1.child_leaf",
                    [index],
                    task_queue=task_queue if isinstance(task_queue, str) else None,
                )
                for index in range(10)
            ]
            return sum(int(result) for result in results)
        if shape == "replay-heavy-history":
            for index in range(500):
                yield ctx.side_effect(lambda marker=index: marker)
            return 500
        if shape == "query-inspection":
            yield ctx.wait_condition(
                lambda: self.finished,
                key="capacity.v1.mixed-finish-query-workflow",
            )
            return self.counter
        raise ValueError(f"unsupported mixed workload shape: {shape!r}")


def required_environment(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"set {name}")
    return value


def new_client(*, worker: bool) -> Client:
    shared_token = os.environ.get("DURABLE_WORKFLOW_TOKEN") or None
    return Client(
        required_environment("DURABLE_WORKFLOW_RUNTIME_URL"),
        namespace=os.environ.get("DURABLE_WORKFLOW_NAMESPACE", "default"),
        token=shared_token,
        control_token=(os.environ.get("DURABLE_WORKFLOW_CLIENT_TOKEN") or None)
        if shared_token is None and not worker
        else None,
        worker_token=(os.environ.get("DURABLE_WORKFLOW_WORKER_TOKEN") or None)
        if shared_token is None and worker
        else None,
    )


def configured_worker(client: Client, task_queue: str) -> Worker:
    concurrency = max(
        1, int(os.environ.get("DURABLE_WORKFLOW_WORKER_CONCURRENCY", "32"))
    )
    return Worker(
        client,
        task_queue=task_queue,
        workflows=[
            SimpleWorkflow,
            OneActivityWorkflow,
            MultipleActivitiesWorkflow,
            TimerWorkflow,
            SignalWorkflow,
            ChildParentWorkflow,
            ChildLeafWorkflow,
            ReplayHeavyWorkflow,
            QueryableCounterWorkflow,
            MixedSelectorWorkflow,
        ],
        activities=[echo_activity, hash_activity],
        max_concurrent_workflow_tasks=concurrency,
        max_concurrent_activity_tasks=concurrency,
    )


async def run_worker() -> None:
    async with new_client(worker=True) as client:
        worker = configured_worker(
            client, required_environment("DURABLE_WORKFLOW_TASK_QUEUE")
        )
        await worker.run()


async def check_definitions() -> None:
    async with Client("http://127.0.0.1") as client:
        configured_worker(client, "capacity-check")
    print("capacity Python adapter definitions are valid")


async def execute_client_command(
    client: Client,
    handles: dict[str, Any],
    command: dict[str, Any],
) -> dict[str, Any]:
    operation = command.get("operation")
    workflow_id = command.get("workflow_id")
    if not isinstance(workflow_id, str) or not workflow_id:
        raise ValueError("every client operation requires workflow_id")
    started = time.perf_counter_ns()
    result: Any = None
    run_id: str | None = None

    if operation == "start":
        cell_id = command.get("cell_id")
        task_queue = command.get("task_queue")
        if (
            cell_id not in WORKFLOW_TYPES
            or not isinstance(task_queue, str)
            or not task_queue
        ):
            raise ValueError("start requires a declared cell_id and task_queue")
        payload = request(command.get("payload"))
        payload["task_queue"] = task_queue
        handle = await client.start_workflow(
            workflow_type=WORKFLOW_TYPES[cell_id],
            workflow_id=workflow_id,
            task_queue=task_queue,
            input=[payload],
        )
        handles[workflow_id] = handle
        run_id = handle.run_id
    elif operation == "signal":
        name = command.get("name")
        arguments = command.get("arguments", [])
        if not isinstance(name, str) or not isinstance(arguments, list):
            raise ValueError("signal requires name and an arguments array")
        await client.signal_workflow(workflow_id, name, args=arguments)
    elif operation == "query":
        name = command.get("name")
        arguments = command.get("arguments", [])
        if not isinstance(name, str) or not isinstance(arguments, list):
            raise ValueError("query requires name and an arguments array")
        result = await client.query_workflow(workflow_id, name, args=arguments)
    elif operation == "result":
        timeout = float(command.get("timeout_seconds", 300))
        handle = handles.get(workflow_id) or client.get_workflow_handle(workflow_id)
        result = await handle.result(timeout=timeout)
    else:
        raise ValueError(f"unsupported client operation: {operation!r}")

    return {
        "ok": True,
        "operation": operation,
        "workflow_id": workflow_id,
        "run_id": run_id,
        "elapsed_ms": round((time.perf_counter_ns() - started) / 1_000_000, 3),
        "result": result,
    }


async def run_client() -> None:
    async with new_client(worker=False) as client:
        handles: dict[str, Any] = {}
        while True:
            line = await asyncio.to_thread(sys.stdin.readline)
            if line == "":
                return
            try:
                command = json.loads(line)
                if not isinstance(command, dict):
                    raise ValueError("each client command must be a JSON object")
                response = await execute_client_command(client, handles, command)
            except Exception as error:  # protocol boundary: serialize typed failure
                response = {
                    "ok": False,
                    "error_type": type(error).__name__,
                    "error": str(error),
                }
            print(json.dumps(response, separators=(",", ":")), flush=True)


async def main() -> None:
    mode = sys.argv[1] if len(sys.argv) > 1 else ""
    if mode == "worker":
        await run_worker()
    elif mode == "client":
        await run_client()
    elif mode == "check":
        await check_definitions()
    else:
        raise SystemExit(
            "usage: capacity_adapter.py describe|conformance|check|worker|client"
        )


if __name__ == "__main__":
    asyncio.run(main())
