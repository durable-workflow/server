"""Unchanged published Python 2.5.0 genuinely authors retained marker aliases."""
import asyncio
import importlib.metadata
import json
import os
import sys

from durable_workflow import Client, Worker, activity, workflow

CHANGE_ID = ""
CALLS = 0
DECISIONS = []


@workflow.defn(name="parity.v1.one_activity")
class Original:
    def run(self, ctx, value):
        selected = []
        for _ in range(CALLS):
            selected.append((yield ctx.patched(CHANGE_ID)))
        DECISIONS.append(selected)
        if selected != [True] * CALLS:
            raise RuntimeError("Original marked decisions changed")
        return (yield ctx.schedule_activity("parity.v1.echo_activity", [value]))


@activity.defn(name="parity.v1.echo_activity")
def echo(value):
    return value


async def checkpoint_worker(worker, handle, event_type):
    running = asyncio.create_task(worker.run())
    try:
        while True:
            page = await handle.get_history(page_size=1000)
            if any(event["event_type"] == event_type for event in page["events"]):
                return
            if running.done():
                await running
                raise RuntimeError("Original worker stopped before its persisted checkpoint")
            await asyncio.sleep(.025)
    finally:
        await worker.stop()
        await running


async def main():
    global CHANGE_ID, CALLS
    url, workflow_id, CHANGE_ID, checkpoint, calls, value_json = sys.argv[1:]
    CALLS = int(calls)
    assert importlib.metadata.version("durable-workflow") == "2.5.0"
    value = json.loads(value_json)
    async with asyncio.timeout(25):
        async with Client(url, token=os.environ.get("DW_PARITY_TOKEN"), namespace="default") as client:
            handle = await client.start_workflow(workflow_type="parity.v1.one_activity", task_queue="server-parity-v1",
                workflow_id=workflow_id, input=[value])
            original = Worker(client, task_queue="server-parity-v1", workflows=[Original], activities=[],
                worker_id=workflow_id+":original", poll_timeout=1, sticky_cache_capacity=0, sticky_cache_ttl_seconds=1)
            await checkpoint_worker(original, handle, "ActivityScheduled")
            if checkpoint == "activity_completed":
                # This distinct activity-only registration cannot replay the
                # workflow to completion before the actual cold reader starts.
                processor = Worker(client, task_queue="server-parity-v1", workflows=[], activities=[echo],
                    worker_id=workflow_id+":original-activity", poll_timeout=1, sticky_cache_capacity=0, sticky_cache_ttl_seconds=1)
                await checkpoint_worker(processor, handle, "ActivityCompleted")
            elif checkpoint != "activity_pending":
                raise ValueError("Unknown checkpoint")
            page = await handle.get_history(page_size=1000)
            markers = [event for event in page["events"] if event["event_type"] == "VersionMarkerRecorded"]
            assert len(markers) == CALLS
            assert not any(event["event_type"] == "WorkflowCompleted" for event in page["events"])
            assert DECISIONS and all(selected == [True] * CALLS for selected in DECISIONS)
            print(json.dumps({"pid": os.getpid(), "language": "python", "sdk_version": "2.5.0",
                "run_id": handle.run_id, "decisions": DECISIONS, "worker_finished": True}))


asyncio.run(main())
