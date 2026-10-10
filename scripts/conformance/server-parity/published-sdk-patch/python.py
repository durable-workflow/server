"""A real, separately started published SDK worker for an original PHP run."""
from __future__ import annotations

import asyncio
import importlib.metadata
import json
import os
import sys

from durable_workflow import Client, Worker, activity, workflow

DECISIONS: list[list[bool]] = []
CHANGE_ID = ""


@activity.defn(name="parity.v1.echo_activity")
def echo(value):
    return value


@workflow.defn(name="parity.v1.one_activity")
class Replacement:
    def run(self, ctx, value):
        selected = [(yield ctx.patched(CHANGE_ID)), (yield ctx.patched(CHANGE_ID))]
        DECISIONS.append(selected)
        if selected != [False, False]:
            raise RuntimeError("Legacy patch decisions changed")
        return (yield ctx.schedule_activity("parity.v1.echo_activity", [value]))


async def main():
    global CHANGE_ID
    url, workflow_id, _run_id, CHANGE_ID = sys.argv[1:]
    async with Client(url, token=os.environ.get("DW_PARITY_TOKEN"), namespace="default") as client:
        worker = Worker(client, task_queue="server-parity-v1", workflows=[Replacement], activities=[echo],
                        worker_id=workflow_id + ":replacement", poll_timeout=1.0)
        execution = await worker.run_until(workflow_id=workflow_id, timeout=25.0, poll_interval=0.05)
        if execution.status != "completed":
            raise RuntimeError(f"Original run closed as {execution.status}")
    print(json.dumps({"pid": os.getpid(), "language": "python",
                      "sdk_version": importlib.metadata.version("durable-workflow"),
                      "decisions": DECISIONS, "worker_finished": True}))


if __name__ == "__main__":
    asyncio.run(main())
