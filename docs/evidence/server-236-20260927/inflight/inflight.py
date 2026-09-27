import asyncio
import hashlib
import json
import os
import sys
from collections import Counter

from durable_workflow import Worker, activity, workflow
from drill import client, digest


WORKFLOW_ID = "restore-drill-inflight"
VALUE = "inflight:" + "E" * 8192


@activity.defn(name="restore-drill-inflight-activity")
async def slow_digest(value):
    await asyncio.sleep(float(os.getenv("DRILL_ACTIVITY_SLEEP_SECONDS", "1")))
    return digest(value)


@workflow.defn(name="restore-drill-inflight-workflow")
class InflightWorkflow:
    def run(self, ctx, value):
        return (yield ctx.schedule_activity("restore-drill-inflight-activity", [value]))


async def inspect(runtime):
    execution = await runtime.describe_workflow(WORKFLOW_ID)
    history = await runtime.get_history(WORKFLOW_ID, execution.run_id)
    if history.get("next_page_token"):
        raise RuntimeError("unexpected history pagination")
    history_bytes = json.dumps(history, sort_keys=True, default=str).encode()
    if execution.input != [VALUE]:
        raise RuntimeError("inflight input bytes differ")
    return {
        "workflow_id": WORKFLOW_ID,
        "run_id": execution.run_id,
        "status": execution.status,
        "input_digest": digest(VALUE),
        "output": execution.output,
        "history_bytes": len(history_bytes),
        "history_sha256": hashlib.sha256(history_bytes).hexdigest(),
        "events": dict(Counter(event["event_type"] for event in history["events"])),
    }


async def seed():
    async with client() as runtime:
        worker = Worker(
            runtime,
            task_queue="restore-drill-inflight",
            workflows=[InflightWorkflow],
            activities=[slow_digest],
        )
        worker_task = asyncio.create_task(worker.run())
        await asyncio.sleep(1)
        await runtime.start_workflow(
            workflow_type="restore-drill-inflight-workflow",
            task_queue="restore-drill-inflight",
            workflow_id=WORKFLOW_ID,
            input=[VALUE],
            execution_timeout_seconds=7200,
            run_timeout_seconds=7200,
        )
        for _ in range(60):
            row = await inspect(runtime)
            if row["events"].get("ActivityStarted", 0) > 0:
                print(json.dumps({"phase": "seed", "workflow": row}, sort_keys=True), flush=True)
                await asyncio.sleep(240)
                await worker.stop()
                await worker_task
                return
            await asyncio.sleep(1)
        raise RuntimeError("activity did not start")


async def resume():
    async with client() as runtime:
        worker = Worker(
            runtime,
            task_queue="restore-drill-inflight",
            workflows=[InflightWorkflow],
            activities=[slow_digest],
        )
        await worker.run_until(workflow_id=WORKFLOW_ID, timeout=300)
        result = await runtime.get_workflow_handle(WORKFLOW_ID).result(timeout=20)
        if result != digest(VALUE):
            raise RuntimeError("inflight result bytes differ")
        print(json.dumps({"phase": "resume", "workflow": await inspect(runtime)}, sort_keys=True))


async def inspect_only():
    async with client() as runtime:
        print(json.dumps({"phase": "inspect", "workflow": await inspect(runtime)}, sort_keys=True))


if __name__ == "__main__":
    mode = sys.argv[1]
    if mode == "seed":
        asyncio.run(seed())
    elif mode == "resume":
        asyncio.run(resume())
    elif mode == "inspect":
        asyncio.run(inspect_only())
    else:
        raise SystemExit(f"unknown mode: {mode}")
