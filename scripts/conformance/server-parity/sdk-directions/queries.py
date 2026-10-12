"""Normal published query clients and cold author workers."""
import asyncio
import importlib.metadata
import json
import os

from durable_workflow import Client, Worker, workflow

WORKFLOW_INVOCATIONS = 0
QUERY_INVOCATIONS = 0


@workflow.defn(name="parity.v1.sdk_queries")
class Queries:
    def __init__(self):
        self.payloads = []

    @workflow.signal("payload")
    def receive(self, value):
        self.payloads.append(value)

    @workflow.query("state")
    def state(self):
        global QUERY_INVOCATIONS
        QUERY_INVOCATIONS += 1
        return {"received": len(self.payloads),
                "last": self.payloads[-1] if self.payloads else None}

    def run(self, ctx, value, target):
        global WORKFLOW_INVOCATIONS
        WORKFLOW_INVOCATIONS += 1
        for index in range(target):
            yield ctx.wait_condition(lambda index=index: len(self.payloads) > index,
                                     key="payload:" + str(index))
        return self.payloads[-1]


async def main(arguments, fixture):
    phase, url, workflow_id, run_id, _, remaining = arguments
    if phase not in fixture["sdk_query_direction"]["phase_order"]:
        raise ValueError("Unknown SDK query phase")
    common = {"pid": os.getpid(), "language": "python",
              "sdk_version": importlib.metadata.version("durable-workflow"),
              "phase": phase, "workflow_id": workflow_id, "run_id": run_id}
    async with asyncio.timeout(float(remaining)):
        async with Client(url, token=os.environ.get("DW_PARITY_TOKEN"), namespace="default") as client:
            if phase in ("signal-1", "signal-2"):
                result = await client.signal_workflow(workflow_id, "payload", args=[fixture["signal_value"]])
                print(json.dumps({**common, "signal_sent": True, "sender_finished": True,
                                  "client_result": result}))
                return
            if phase.startswith("query-"):
                result = await client.query_workflow(workflow_id, "state", args=[])
                print(json.dumps({**common, "query_sent": True, "client_finished": True,
                                  "client_result": result}))
                return
            receipt_file = os.environ["DW_PARITY_QUERY_RECEIPT_FILE"]
            queue = fixture["sdk_query_direction"]["task_queue"]
            worker_id = workflow_id + ":" + phase
            worker = Worker(client, task_queue=queue, worker_id=worker_id, poll_timeout=1,
                            workflows=[Queries], activities=[],
                            sticky_cache_capacity=0, sticky_cache_ttl_seconds=1)
            running = asyncio.create_task(worker.run())
            try:
                # The observer writes this only after the independent normal query
                # client returns its real successful receipt. It is a stop trigger,
                # never a replacement task, history or query result.
                while not os.path.isfile(receipt_file):
                    if running.done():
                        await running
                        raise RuntimeError("Published query author stopped before its query receipt")
                    await asyncio.sleep(.025)
                run_id = (await client.describe_workflow(workflow_id)).run_id
            finally:
                await worker.stop()
                await running
    print(json.dumps({**common, "run_id": run_id, "worker_id": worker_id, "task_queue": queue,
                      "workflow_invocations": WORKFLOW_INVOCATIONS, "activity_invocations": 0,
                      "query_invocations": QUERY_INVOCATIONS, "worker_finished": True}))
