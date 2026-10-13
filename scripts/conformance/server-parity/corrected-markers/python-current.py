"""Normal public Python worker clocks across two real activity/cold boundaries."""
import asyncio
import importlib.metadata
import hashlib
import json
import os
import sys
from pathlib import Path

import durable_workflow

from durable_workflow import ActivityRetryPolicy, Client, Worker, workflow

URL, MODE, WORKFLOW_ID, RUN_ID, CASE, PROFILE, POLICY, INPUT = sys.argv[1:9]
SNAPSHOTS = []


@workflow.defn(name="parity.v1.corrected_markers")
class ClockReader:
    def run(self, ctx, value):
        clocks, uuids = [], []

        def capture():
            clocks.append(ctx.now().isoformat(timespec="microseconds").replace("+00:00", "Z"))
            uuids.append(str(ctx.uuid7()))

        capture()
        if CASE == "zero":
            first = yield ctx.get_version("corrected-zero", -7, 0)
            capture()
            patch = yield ctx.patched("corrected-zero")
            capture()
            deprecated = yield ctx.deprecate_patch("corrected-zero")
            capture()
            wider = yield ctx.get_version("corrected-zero", 0, 10)
            capture()
            repeated = yield ctx.patched("corrected-zero")
            capture()
            projection = [first, patch, deprecated, wider, repeated]
        elif CASE == "genuine_one":
            first = yield ctx.deprecate_patch("corrected-one")
            capture()
            patch = yield ctx.patched("corrected-one")
            capture()
            wider = yield ctx.get_version("corrected-one", 0, 10)
            capture()
            repeated = yield ctx.deprecate_patch("corrected-one")
            capture()
            projection = [first, patch, wider, repeated]
        else:
            raise ValueError("Unknown original clock marker recipe")
        options = dict(queue=WORKFLOW_ID + "-activity",
                       retry_policy=ActivityRetryPolicy(max_attempts=1, backoff_seconds=[]),
                       start_to_close_timeout=120, schedule_to_close_timeout=600)
        first_result = yield ctx.schedule_activity("parity.v1.echo_activity", [value], **options)
        capture()
        snapshot = {"decisions": projection, "clocks": clocks, "uuids": uuids}
        SNAPSHOTS.append(snapshot)
        second_result = yield ctx.schedule_activity("parity.v1.echo_activity", [first_result], **options)
        capture()
        if second_result != value:
            raise ValueError("Original typed activity value changed")
        return {"projection": projection, "typed": second_result}


async def main():
    assert MODE == "workflow" and POLICY == "empty" and PROFILE in ("clock-wait", "wider")
    assert importlib.metadata.version("durable-workflow") == "2.5.13"
    async with asyncio.timeout(30):
        async with Client(URL, token=os.environ.get("DW_PARITY_TOKEN"), namespace="default") as client:
            worker = Worker(client, task_queue=WORKFLOW_ID + "-workflow", workflows=[ClockReader], activities=[],
                            worker_id=WORKFLOW_ID + ":workflow:" + PROFILE, poll_timeout=1,
                            sticky_cache_capacity=0, sticky_cache_ttl_seconds=1)
            running = asyncio.create_task(worker.run())
            try:
                while True:
                    page = await client.get_history(WORKFLOW_ID, RUN_ID, page_size=1000)
                    events = page["events"]
                    done = (sum(event["event_type"] == "ActivityScheduled" for event in events) == 2
                            if PROFILE == "clock-wait" else any(event["event_type"] == "WorkflowCompleted" for event in events))
                    if done:
                        break
                    if running.done():
                        await running
                        raise RuntimeError("Current clock worker stopped before its original boundary")
                    await asyncio.sleep(.025)
            finally:
                await worker.stop()
                await running
    if len(SNAPSHOTS) != 1:
        raise ValueError("Expected one real current-worker replay")
    print(json.dumps({"pid": os.getpid(), "sdk_version": importlib.metadata.version("durable-workflow"),
                      "workflow_id": WORKFLOW_ID, "run_id": RUN_ID, "mode": MODE, "case": CASE,
                      "range_profile": PROFILE, "retry_policy": POLICY,
                      "decisions": [SNAPSHOTS[0]["decisions"]], "clock_reads": SNAPSHOTS,
                      "loaded_sources": {name: hashlib.sha256((Path(durable_workflow.__file__).parent / name).read_bytes()).hexdigest()
                                         for name in ("workflow.py", "worker.py", "client.py")},
                      "worker_finished": True, "shutdown": "public_stop"}), flush=True)


asyncio.run(main())
