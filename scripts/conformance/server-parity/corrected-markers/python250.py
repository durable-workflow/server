"""Genuine public range author from the normally installed historical wheel."""
import asyncio
import importlib.metadata
import json
import os
import subprocess
import sys
import tempfile

from durable_workflow import ActivityRetryPolicy, Client, Worker, workflow
from admission_receipt import admission_acknowledged

URL, MODE, WORKFLOW_ID, RUN_ID, CASE, PROFILE, POLICY, INPUT = sys.argv[1:9]
VALUE = json.loads(INPUT)


@workflow.defn(name="parity.v1.corrected_markers")
class Original:
    def run(self, ctx, value):
        first = yield ctx.get_version("corrected-one", 0, 1)
        second = yield ctx.get_version("corrected-one", 1, 1)
        print(json.dumps({"decisions": [first, second]}), flush=True)
        typed = yield ctx.schedule_activity(
            "parity.v1.echo_activity", [value], queue=WORKFLOW_ID + "-activity",
            retry_policy=ActivityRetryPolicy(max_attempts=1, backoff_seconds=[]),
            start_to_close_timeout=120, schedule_to_close_timeout=600,
        )
        return {"projection": [first, second], "typed": typed}


async def author():
    async with Client(URL, token=os.environ.get("DW_PARITY_TOKEN"), namespace="default") as client:
        worker = Worker(client, task_queue=WORKFLOW_ID + "-workflow", workflows=[Original], activities=[],
                        worker_id=WORKFLOW_ID + ":workflow:original", poll_timeout=1,
                        sticky_cache_capacity=0, sticky_cache_ttl_seconds=1)
        await worker.run()


async def checkpoint():
    assert importlib.metadata.version("durable-workflow") == "2.5.0"
    assert (MODE, CASE, PROFILE, POLICY) == ("workflow", "genuine_one", "original", "empty")
    journal_path = os.environ["DW_PARITY_HTTP_JOURNAL"]
    # This fixture requires both durable admission and its observed response.
    # Killing on history alone can cancel the still-returning completion.
    with tempfile.TemporaryFile(mode="w+t") as trace:
        child = subprocess.Popen([sys.executable, __file__, *sys.argv[1:9], "author"], stdout=trace)
        try:
            async with asyncio.timeout(30):
                async with Client(URL, token=os.environ.get("DW_PARITY_TOKEN"), namespace="default") as client:
                    while True:
                        page = await client.get_history(WORKFLOW_ID, RUN_ID, page_size=1000)
                        events = page["events"]
                        scheduled = next((event for event in events
                                          if event["event_type"] == "ActivityScheduled"), None)
                        if scheduled is not None:
                            markers = [event for event in events if event["event_type"] == "VersionMarkerRecorded"]
                            assert [event["payload"]["version"] for event in markers] == [1, 1]
                            assert [(event["payload"]["min_supported"], event["payload"]["max_supported"]) for event in markers] == [(0, 1), (1, 1)]
                            with open(journal_path) as journal:
                                captured = journal.read(4 * 1024 * 1024 + 1)
                            if len(captured) > 4 * 1024 * 1024:
                                raise RuntimeError("Original admission journal exceeds the fixture limit")
                            if admission_acknowledged(captured, scheduled["payload"]["task"],
                                                      WORKFLOW_ID + ":workflow:original"):
                                break
                        if child.poll() is not None:
                            raise RuntimeError("Historical author exited before the committed checkpoint")
                        await asyncio.sleep(.025)
        finally:
            if child.poll() is None:
                child.kill()
            exit_code = await asyncio.to_thread(child.wait, 5)
        trace.seek(0)
        decisions = [json.loads(line)["decisions"] for line in trace if line.strip()]
        assert decisions == [[1, 1]]
        assert exit_code == -9
        print(json.dumps({"pid": child.pid, "observer_pid": os.getpid(), "sdk_version": "2.5.0",
                          "workflow_id": WORKFLOW_ID, "run_id": RUN_ID, "mode": MODE,
                          "case": CASE, "range_profile": PROFILE, "retry_policy": POLICY,
                          "decisions": decisions, "worker_finished": True, "author_exit_code": exit_code}), flush=True)


asyncio.run(author() if sys.argv[9:] == ["author"] else checkpoint())
