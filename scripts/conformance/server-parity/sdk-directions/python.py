"""Installed published SDK author and separately routed activity worker."""
import asyncio
import importlib.metadata
import json
import os
import sys

from durable_workflow import Client, Worker, activity, workflow

WORKFLOW_INVOCATIONS = 0
ACTIVITY_INVOCATIONS = 0


@workflow.defn(name="parity.v1.one_activity")
class Author:
    def run(self, ctx, value):
        global WORKFLOW_INVOCATIONS
        WORKFLOW_INVOCATIONS += 1
        return (yield ctx.schedule_activity("parity.v1.echo_activity", [value],
                                           queue="server-parity-sdk-activity"))


@activity.defn(name="parity.v1.echo_activity")
def echo(value):
    global ACTIVITY_INVOCATIONS
    ACTIVITY_INVOCATIONS += 1
    return value


@workflow.defn(name="parity.v1.one_child")
class Parent:
    def run(self, ctx, value):
        global WORKFLOW_INVOCATIONS
        WORKFLOW_INVOCATIONS += 1
        results = []
        for payload in value["payloads"]:
            results.append((yield ctx.start_child_workflow("parity.v1.echo_child", [payload],
                                                          task_queue="server-parity-sdk-child")))
        return {"child_results": results}


@workflow.defn(name="parity.v1.echo_child")
class Child:
    def run(self, ctx, value):
        global WORKFLOW_INVOCATIONS
        WORKFLOW_INVOCATIONS += 1
        return value


async def main():
    phase, url, workflow_id, run_id, fixture_path, remaining = sys.argv[1:]
    with open(fixture_path, encoding="utf-8") as source:
        fixture = json.load(source)
    child_direction = "sdk_child_direction" in fixture
    if phase not in (("original", "child", "replacement") if child_direction else
                     ("original", "activity", "replacement")):
        raise ValueError("Unknown SDK direction phase")
    queue = (("server-parity-sdk-child" if phase == "child" else "server-parity-sdk-parent")
             if child_direction else
             ("server-parity-sdk-activity" if phase == "activity" else "server-parity-sdk-workflow"))
    worker_id = workflow_id + ":" + phase
    async with asyncio.timeout(float(remaining)):
        async with Client(url, token=os.environ.get("DW_PARITY_TOKEN"), namespace="default") as client:
            if phase == "original":
                handle = await client.start_workflow(workflow_type=fixture["workflow_type"],
                    task_queue=queue, workflow_id=workflow_id, input=[fixture["input"]])
                run_id = handle.run_id
            worker = Worker(client, task_queue=queue, worker_id=worker_id, poll_timeout=1,
                workflows=([Child] if phase == "child" else [Parent]) if child_direction
                          else ([] if phase == "activity" else [Author]),
                activities=[echo] if phase == "activity" else [],
                sticky_cache_capacity=0, sticky_cache_ttl_seconds=1)
            running = asyncio.create_task(worker.run())
            wanted = ({"original": "ChildWorkflowScheduled", "child": "WorkflowCompleted",
                       "replacement": "WorkflowCompleted"} if child_direction else
                      {"original": "ActivityScheduled", "activity": "ActivityCompleted",
                       "replacement": "WorkflowCompleted"})[phase]
            try:
                while True:
                    history = await client.get_history(workflow_id, run_id, page_size=1000)
                    if any(event["event_type"] == wanted for event in history["events"]):
                        break
                    if running.done():
                        await running
                        raise RuntimeError("Published worker stopped before its persisted checkpoint")
                    await asyncio.sleep(.025)
            finally:
                await worker.stop()
                await running
    print(json.dumps({"pid": os.getpid(), "language": "python",
        "sdk_version": importlib.metadata.version("durable-workflow"), "phase": phase,
        "run_id": run_id, "worker_id": worker_id, "task_queue": queue,
        "workflow_invocations": WORKFLOW_INVOCATIONS,
        "activity_invocations": ACTIVITY_INVOCATIONS, "worker_finished": True}))


if __name__ == "__main__":
    asyncio.run(main())
