"""Installed timeout-cleanup worker on the Python SDK (Server #1201).

The Python counterpart of ../worker.php: the same workflow types, behaviors and
observations, on the task queue in TC_QUEUE. TC_COOPERATIVE=0 registers an
ordinary worker, which does not advertise timeout_cleanup. TC_DIE_ON_CLEANUP=1
kills this process with SIGKILL when a cleanup turn reaches workflow code, so a
replacement worker must finish it. vectors.php names the vectors.
"""

from __future__ import annotations

import asyncio
import fcntl
import json
import logging
import os
import signal
from datetime import datetime, timezone
from typing import Any

from durable_workflow import Client, Worker, activity, workflow
from durable_workflow.errors import WorkflowCancelled, WorkflowTimeoutCleanupRequested

DIE_ON_CLEANUP = os.environ.get("TC_DIE_ON_CLEANUP") == "1"


def observe(ctx: Any, vector: str, error: WorkflowCancelled) -> Any:
    """What workflow code saw when the interruption reached it, written by an
    activity so the driver reads it without decoding payloads."""
    if DIE_ON_CLEANUP:
        os.kill(os.getpid(), signal.SIGKILL)
    context = error.context
    with ctx.cancellation_shield():
        yield ctx.schedule_activity("tc.observe", [{
            "vector": vector,
            "class": f"{type(error).__module__}.{type(error).__qualname__}",
            "timeout_subtype": isinstance(error, WorkflowTimeoutCleanupRequested),
            "cause": getattr(context, "cause", None) if context is not None else None,
            "request_id": error.request_id,
        }])


def cleanup(ctx: Any, mode: str, vector: str) -> Any:
    """Work waits on a long timer, so only the engine's deadline interrupts it."""
    try:
        yield ctx.start_timer(3600)
        return "work finished"
    except WorkflowCancelled as error:
        yield from observe(ctx, vector, error)
        if mode == "return":
            return "compensated"
        if mode == "rethrow":
            raise error
        if mode == "fail":
            raise RuntimeError("compensation failed") from None
        # Cleanup that outlives every deadline.
        with ctx.cancellation_shield():
            yield ctx.start_timer(3600)
        return "lingered"


@workflow.defn(name="tc.return")
class Return:
    def run(self, ctx: Any, vector: str = "") -> Any:
        return (yield from cleanup(ctx, "return", vector))


@workflow.defn(name="tc.rethrow")
class Rethrow:
    def run(self, ctx: Any, vector: str = "") -> Any:
        return (yield from cleanup(ctx, "rethrow", vector))


@workflow.defn(name="tc.fail")
class Fail:
    def run(self, ctx: Any, vector: str = "") -> Any:
        return (yield from cleanup(ctx, "fail", vector))


@workflow.defn(name="tc.linger")
class Linger:
    def run(self, ctx: Any, vector: str = "") -> Any:
        return (yield from cleanup(ctx, "linger", vector))


@workflow.defn(name="tc.continue")
class Continue:
    """Continues as new once, then waits for cleanup and compensates."""

    def run(self, ctx: Any, vector: str = "", generation: int = 0) -> Any:
        if generation == 0:
            yield ctx.start_timer(2)
            return ctx.continue_as_new(vector, 1)
        try:
            yield ctx.start_timer(3600)
            return "work finished"
        except WorkflowCancelled as error:
            yield from observe(ctx, vector, error)
            return "compensated"


@workflow.defn(name="tc.plain")
class Plain:
    """No opt-in: workflow code must never run again after the hard deadline."""

    def run(self, ctx: Any, vector: str = "") -> Any:
        try:
            yield ctx.start_timer(3600)
        except WorkflowCancelled as error:
            yield from observe(ctx, vector, error)
        return "unexpected"


@activity.defn(name="tc.observe")
def observe_activity(seen: dict[str, Any]) -> bool:
    line = json.dumps({**seen, "at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")}) + "\n"
    with open(os.environ.get("TC_OBSERVATIONS", "/evidence/observations.jsonl"), "a", encoding="utf-8") as output:
        fcntl.flock(output, fcntl.LOCK_EX)
        try:
            output.write(line)
            output.flush()
        finally:
            fcntl.flock(output, fcntl.LOCK_UN)
    return True


async def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(name)s %(levelname)s %(message)s")
    os.environ.setdefault("DURABLE_WORKFLOW_WORKER_PROTOCOL_VERSION", "1.20")
    cooperative = os.environ.get("TC_COOPERATIVE") != "0"
    async with Client(
        os.environ.get("TC_SERVER_URL", "http://server:8080"),
        worker_token=os.environ.get("TC_TOKEN", "timeout-cleanup-token"),
        namespace="default",
    ) as client:
        worker = Worker(
            client,
            task_queue=os.environ.get("TC_QUEUE", "timeout-cleanup"),
            worker_id=os.environ.get("TC_WORKER_ID") or None,
            workflows=[Return, Rethrow, Fail, Linger, Continue, Plain],
            activities=[observe_activity],
            capabilities=["cooperative_cancellation"] if cooperative else [],
            poll_timeout=2.0,
        )
        await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
