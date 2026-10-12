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


@workflow.defn(name="parity.v1.sdk_timer")
class Timer:
    def run(self, ctx, value):
        global WORKFLOW_INVOCATIONS
        WORKFLOW_INVOCATIONS += 1
        yield ctx.sleep(value["sleep_ms"] / 1000)
        return value


@workflow.defn(name="parity.v1.sdk_signals")
class Signals:
    def __init__(self):
        self.payloads = []

    @workflow.signal("payload")
    def receive(self, value):
        self.payloads.append(value)

    def run(self, ctx, value, target):
        global WORKFLOW_INVOCATIONS
        WORKFLOW_INVOCATIONS += 1
        for index in range(target):
            yield ctx.wait_condition(lambda index=index: len(self.payloads) > index,
                                     key="payload:" + str(index))
        return self.payloads[-1]


async def main():
    phase, url, workflow_id, run_id, fixture_path, remaining = sys.argv[1:]
    with open(fixture_path, encoding="utf-8") as source:
        fixture = json.load(source)
    if "sdk_update_direction" in fixture:
        from updates import main as update_main
        await update_main(sys.argv[1:], fixture)
        return
    if "sdk_query_direction" in fixture:
        from queries import main as query_main
        await query_main(sys.argv[1:], fixture)
        return
    child_direction = "sdk_child_direction" in fixture
    timer_author = "sdk_timer_author" in fixture
    signal_direction = "sdk_signal_direction" in fixture
    if phase not in (("original", "signal-1", "resumed", "signal-2", "replacement") if signal_direction else
                    (("original", "replacement") if timer_author else
                    (("original", "child", "replacement") if child_direction else
                     ("original", "activity", "replacement")))):
        raise ValueError("Unknown SDK direction phase")
    queue = "server-parity-sdk-signals" if signal_direction else ("server-parity-sdk-timer" if timer_author else (("server-parity-sdk-child" if phase == "child" else "server-parity-sdk-parent")
             if child_direction else
             ("server-parity-sdk-activity" if phase == "activity" else "server-parity-sdk-workflow")))
    worker_id = workflow_id + ":" + phase
    async with asyncio.timeout(float(remaining)):
        async with Client(url, token=os.environ.get("DW_PARITY_TOKEN"), namespace="default") as client:
            if signal_direction and phase in ("signal-1", "signal-2"):
                result = await client.signal_workflow(workflow_id, "payload", args=[fixture["signal_value"]])
                print(json.dumps({"pid": os.getpid(), "language": "python",
                    "sdk_version": importlib.metadata.version("durable-workflow"),
                    "phase": phase, "workflow_id": workflow_id, "run_id": run_id,
                    "signal_sent": True, "sender_finished": True, "client_result": result}))
                return
            if phase == "original" and not signal_direction:
                handle = await client.start_workflow(workflow_type=fixture["workflow_type"],
                    task_queue=queue, workflow_id=workflow_id, input=[fixture["input"]])
                run_id = handle.run_id
            worker = Worker(client, task_queue=queue, worker_id=worker_id, poll_timeout=1,
                workflows=[Signals] if signal_direction else ([Timer] if timer_author else (([Child] if phase == "child" else [Parent]) if child_direction
                          else ([] if phase == "activity" else [Author]))),
                activities=[echo] if phase == "activity" else [],
                sticky_cache_capacity=0, sticky_cache_ttl_seconds=1)
            running = asyncio.create_task(worker.run())
            wanted = ({"original": "ConditionWaitOpened", "resumed": "ConditionWaitOpened",
                       "replacement": "WorkflowCompleted"} if signal_direction else
                      ({"original": "TimerScheduled", "replacement": "WorkflowCompleted"} if timer_author else
                      {"original": "ChildWorkflowScheduled", "child": "WorkflowCompleted",
                       "replacement": "WorkflowCompleted"} if child_direction else
                      {"original": "ActivityScheduled", "activity": "ActivityCompleted",
                       "replacement": "WorkflowCompleted"}))[phase]
            try:
                while True:
                    if signal_direction and not run_id:
                        if WORKFLOW_INVOCATIONS == 0:
                            if running.done():
                                await running
                                raise RuntimeError("Published worker stopped before its first workflow task")
                            await asyncio.sleep(.025)
                            continue
                        run_id = (await client.describe_workflow(workflow_id)).run_id
                    history = await client.get_history(workflow_id, run_id, page_size=1000)
                    observed = sum(event["event_type"] == wanted for event in history["events"])
                    required = 2 if signal_direction and phase == "resumed" else 1
                    if observed >= required:
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
