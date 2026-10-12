"""Ordinary published update clients and cold author workers."""
import asyncio
import importlib.metadata
import json
import os

from durable_workflow import Client, UpdateFailed, Worker, workflow

WORKFLOW_INVOCATIONS = 0
UPDATE_INVOCATIONS = 0


@workflow.defn(name="parity.v1.sdk_updates")
class Updates:
    def __init__(self):
        self.values = []
        self.finished = False

    @workflow.signal("payload")
    def finish(self, value):
        self.finished = value["finish"]

    @workflow.update("set_payload")
    def set_payload(self, value):
        global UPDATE_INVOCATIONS
        UPDATE_INVOCATIONS += 1
        if value.get("fail_handler") is True:
            raise ValueError("parity update handler failure λ")
        self.values.append(value)
        return {"value": value, "applied": len(self.values)}

    def run(self, ctx, value, target):
        global WORKFLOW_INVOCATIONS
        WORKFLOW_INVOCATIONS += 1
        yield ctx.wait_condition(lambda: self.finished, key="payload:0")
        return {"values": self.values}


async def main(arguments, fixture):
    phase, url, workflow_id, run_id, _, remaining = arguments
    if phase not in fixture["sdk_update_direction"]["phase_order"]:
        raise ValueError("Unknown SDK update phase")
    common = {"pid": os.getpid(), "language": "python",
              "sdk_version": importlib.metadata.version("durable-workflow"),
              "phase": phase, "workflow_id": workflow_id, "run_id": run_id}
    async with asyncio.timeout(float(remaining)):
        async with Client(url, token=os.environ.get("DW_PARITY_TOKEN"), namespace="default") as client:
            if phase.startswith(("update-", "duplicate-pending-", "duplicate-completed-")):
                index = int(phase[-1]) - 1
                value = (fixture["update_values"][index] if phase.startswith("update-")
                         else fixture["duplicate_update_value"])
                request_id = workflow_id + ":update:" + str(index)
                expects_failure = "sdk_update_failure" in fixture and index == 0
                try:
                    result = await client.update_workflow(workflow_id, "set_payload", args=[value],
                                                          wait_for="completed", request_id=request_id)
                    if expects_failure:
                        raise RuntimeError("Failed update unexpectedly returned a successful result")
                    outcome = {"client_result": result}
                except UpdateFailed as error:
                    if not expects_failure:
                        raise
                    outcome = {"client_failure": {"class": type(error).__module__ + "." + type(error).__qualname__,
                                                  "message": str(error), "status": error.status,
                                                  "body": error.body, "workflow_id": error.workflow_id,
                                                  "run_id": error.run_id, "update_id": error.update_id,
                                                  "failure_id": error.failure_id}}
                print(json.dumps({**common, "update_sent": True, "client_finished": True,
                                  "request_id": request_id, **outcome}))
                return
            if phase == "signal":
                result = await client.signal_workflow(workflow_id, "payload", args=[fixture["signal_value"]])
                print(json.dumps({**common, "signal_sent": True, "sender_finished": True,
                                  "client_result": result}))
                return
            receipt_file = os.environ["DW_PARITY_UPDATE_RECEIPT_FILE"]
            queue = fixture["sdk_update_direction"]["task_queue"]
            worker_id = workflow_id + ":" + phase
            worker = Worker(client, task_queue=queue, worker_id=worker_id, poll_timeout=1,
                            workflows=[Updates], activities=[],
                            sticky_cache_capacity=0, sticky_cache_ttl_seconds=1)
            running = asyncio.create_task(worker.run())
            try:
                # The observer writes this only after the matching successful
                # workflow-task completion. It never supplies a task or result.
                while not os.path.isfile(receipt_file):
                    if running.done():
                        await running
                        raise RuntimeError("Published update author stopped before its fenced receipt")
                    await asyncio.sleep(.025)
                run_id = (await client.describe_workflow(workflow_id)).run_id
            finally:
                await worker.stop()
                await running
    print(json.dumps({**common, "run_id": run_id, "worker_id": worker_id, "task_queue": queue,
                      "workflow_invocations": WORKFLOW_INVOCATIONS, "activity_invocations": 0,
                      "update_invocations": UPDATE_INVOCATIONS, "worker_finished": True}))
