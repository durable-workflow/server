#!/usr/bin/env python3
"""Execute one child-workflow task with the installed public Python SDK.

This adapter intentionally accepts a leased server task, not conformance
evidence.  The surrounding published-image probe submits the commands returned
here to the server and captures the resulting durable history.
"""

from __future__ import annotations

import json
import sys
from typing import Any

from durable_workflow import serializer, workflow
from durable_workflow.errors import ChildWorkflowCancelled, ChildWorkflowFailed
from durable_workflow.workflow import WorkflowContext, commands_to_server_commands, replay


class ChildDomainError(RuntimeError):
    pass


@workflow.defn(name="conformance.python.child")
class PythonChild:
    def run(self, ctx: WorkflowContext, value: str) -> str:  # type: ignore[no-untyped-def]
        return f"{value}|python-child"


@workflow.defn(name="conformance.python.failure-child")
class PythonFailureChild:
    def run(self, ctx: WorkflowContext, message: str) -> None:  # type: ignore[no-untyped-def]
        raise ChildDomainError(message)


@workflow.defn(name="conformance.python.long-child")
class PythonLongChild:
    def run(self, ctx: WorkflowContext) -> str:  # type: ignore[no-untyped-def]
        yield ctx.start_timer(3600)
        return "long-child-completed"


@workflow.defn(name="conformance.python.parent")
class PythonParent:
    def run(self, ctx: WorkflowContext, child_type: str, value: str) -> dict[str, Any]:  # type: ignore[no-untyped-def]
        result = yield ctx.start_child_workflow(child_type, [value])
        return {"child_result": result, "parent_runtime": "sdk-python"}


@workflow.defn(name="conformance.python.failure-parent")
class PythonFailureParent:
    def run(self, ctx: WorkflowContext, child_type: str, message: str) -> dict[str, Any]:  # type: ignore[no-untyped-def]
        try:
            yield ctx.start_child_workflow(child_type, [message])
        except ChildWorkflowFailed as exc:
            return {
                "failure_kind": exc.failure_kind,
                "exception_class": exc.exception_class,
                "message": str(exc),
                "child_run_id": exc.child_workflow_run_id,
            }
        return {"unexpected_success": True}


@workflow.defn(name="conformance.python.cancel-parent")
class PythonCancelParent:
    def run(self, ctx: WorkflowContext, child_type: str) -> dict[str, Any]:  # type: ignore[no-untyped-def]
        try:
            yield ctx.start_child_workflow(child_type, [], parent_close_policy="request_cancel")
        except ChildWorkflowCancelled as exc:
            return {
                "failure_kind": exc.failure_kind,
                "exception_type": type(exc).__name__,
                "exception_class": f"{type(exc).__module__}.{type(exc).__qualname__}",
                "message": str(exc),
                "child_run_id": exc.child_workflow_run_id,
            }
        return {"unexpected_success": True}


@workflow.defn(name="conformance.python.fan-out-parent")
class PythonFanOutParent:
    def run(self, ctx: WorkflowContext, child_type: str, count: int) -> dict[str, Any]:  # type: ignore[no-untyped-def]
        values = yield [
            ctx.start_child_workflow(child_type, [str(index)])
            for index in range(count)
        ]
        return {"child_count": len(values), "values": values}


WORKFLOWS: dict[str, type] = {
    "conformance.python.child": PythonChild,
    "conformance.python.failure-child": PythonFailureChild,
    "conformance.python.long-child": PythonLongChild,
    "conformance.python.parent": PythonParent,
    "conformance.python.failure-parent": PythonFailureParent,
    "conformance.python.cancel-parent": PythonCancelParent,
    "conformance.python.fan-out-parent": PythonFanOutParent,
}


def main() -> int:
    task = json.load(sys.stdin)
    workflow_type = str(task.get("workflow_type") or "")
    workflow_class = WORKFLOWS.get(workflow_type)
    if workflow_class is None:
        raise RuntimeError(f"unregistered Python conformance workflow type: {workflow_type}")

    codec = task.get("payload_codec") or serializer.AVRO_CODEC
    decoded = serializer.decode_envelope(task.get("arguments"), codec=codec)
    arguments = decoded if isinstance(decoded, list) else ([] if decoded is None else [decoded])
    history = task.get("history_events") if isinstance(task.get("history_events"), list) else []

    try:
        outcome = replay(
            workflow_class,
            history,
            arguments,
            workflow_id=str(task.get("workflow_id") or ""),
            run_id=str(task.get("run_id") or ""),
            payload_codec=codec,
        )
        commands = commands_to_server_commands(
            list(outcome.commands),
            str(task.get("task_queue") or task.get("queue") or "cw-shared"),
            payload_codec=codec,
        )
        runtime_result = next(
            (getattr(command, "result", None) for command in outcome.commands if command.__class__.__name__ == "CompleteWorkflow"),
            None,
        )
    except BaseException as exc:  # the worker protocol turns workflow exceptions into terminal commands
        runtime_result = None
        commands = [{
            "type": "fail_workflow",
            "message": str(exc) or type(exc).__name__,
            "exception_type": type(exc).__name__,
            "exception_class": f"{type(exc).__module__}.{type(exc).__qualname__}",
            "exception": {
                "type": type(exc).__name__,
                "class": f"{type(exc).__module__}.{type(exc).__qualname__}",
                "message": str(exc) or type(exc).__name__,
            },
        }]

    json.dump({
        "runtime": "sdk-python",
        "sdk_execution": "durable_workflow.workflow.replay",
        "workflow_type": workflow_type,
        "workflow_id": task.get("workflow_id"),
        "run_id": task.get("run_id"),
        "task_id": task.get("task_id"),
        "task_queue": task.get("task_queue") or task.get("queue"),
        "lease_owner": task.get("lease_owner"),
        "commands": commands,
        "runtime_result": runtime_result,
    }, sys.stdout, sort_keys=True)
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
