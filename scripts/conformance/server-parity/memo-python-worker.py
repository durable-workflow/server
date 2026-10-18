"""Published Python author/cold reader for the unchanged memo recipe."""
import asyncio
import hashlib
import importlib.metadata
import json
import os
import sys
from pathlib import Path

import durable_workflow
from durable_workflow import ActivityRetryPolicy, Client, Worker, workflow

URL, PHASE, WORKFLOW_ID, RUN_ID, FIXTURE_PATH = sys.argv[1:6]
FIXTURE = json.loads(Path(FIXTURE_PATH).read_text())
SPEC = FIXTURE["memo_replay"]
if PHASE not in ("original", "replacement"):
    raise ValueError("Python memo actors author and cold-read workflows")


@workflow.defn(name=FIXTURE["workflow_type"])
class MemoWorkflow:
    def run(self, ctx, value):
        if SPEC["updates"]:
            yield ctx.upsert_memo(SPEC["updates"][0])
        result = yield ctx.schedule_activity(
            "parity.v1.memo_echo", [value], queue=WORKFLOW_ID + "-activity",
            retry_policy=ActivityRetryPolicy(**SPEC["activity_options"]["retry_policy"]),
            start_to_close_timeout=SPEC["activity_options"]["start_to_close_timeout"],
            schedule_to_close_timeout=SPEC["activity_options"]["schedule_to_close_timeout"],
        )
        if len(SPEC["updates"]) > 1:
            yield ctx.upsert_memo(SPEC["updates"][1])
        return result


async def main():
    async with asyncio.timeout(SPEC["phase_budget_seconds"]):
        async with Client(URL, token=os.environ.get("DW_PARITY_TOKEN"), namespace="default") as client:
            worker = Worker(
                client, task_queue=WORKFLOW_ID + "-workflow", workflows=[MemoWorkflow], activities=[],
                worker_id=WORKFLOW_ID + ":memo:python:" + PHASE, poll_timeout=1,
                sticky_cache_capacity=0, sticky_cache_ttl_seconds=1,
            )
            running = asyncio.create_task(worker.run())
            try:
                while True:
                    page = await client.get_history(WORKFLOW_ID, RUN_ID, page_size=1000)
                    events = page["events"]
                    expected = "ActivityScheduled" if PHASE == "original" else "WorkflowCompleted"
                    if any(event["event_type"] == expected for event in events):
                        break
                    if running.done():
                        await running
                        raise RuntimeError("Memo worker stopped before its original durable boundary")
                    await asyncio.sleep(.025)
            finally:
                await worker.stop()
                await running
    root = Path(durable_workflow.__file__).parent
    print(json.dumps({
        "phase": PHASE, "pid": os.getpid(), "workflow_id": WORKFLOW_ID, "run_id": RUN_ID,
        "language": "python", "sdk_version": importlib.metadata.version("durable-workflow"),
        "sdk_loaded_sources": {name: hashlib.sha256((root / name).read_bytes()).hexdigest()
                               for name in ("workflow.py", "worker.py", "client.py")},
        "definition_sha256": hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
        "activity_invocations": 0, "worker_finished": True, "shutdown": "public_stop",
    }), flush=True)


asyncio.run(main())
