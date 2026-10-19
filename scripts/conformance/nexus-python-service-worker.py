"""Run an installed Python SDK workflow service for the PHP caller probe."""

from __future__ import annotations

import asyncio
import json
import os
import sys
from importlib.metadata import version

from durable_workflow import Client, Worker, WorkflowCancelled, workflow


class NexusFixtureFailure(RuntimeError):
    pass


@workflow.defn(name="nexus.fixture.python.greeter")
class PythonGreeter:
    def run(self, context, name):
        if name.startswith("nexus-external:"):
            yield context.sleep(2)
            return {"language": "python", "name": name, "greeting": f"hello, {name}",
                    "bigint": 9223372036854775807, "nullable": None, "unicode": "雪☃"}
        if name == "fail":
            raise NexusFixtureFailure("fixture service failure")
        if name == "recover":
            yield context.sleep(8)
        if name == "cancel":
            try:
                yield context.sleep(300)
            except WorkflowCancelled as cancelled:
                print(json.dumps({"scenario": "service_cancellation_observed",
                                  "request_id": cancelled.request_id,
                                  "type": type(cancelled).__name__}), flush=True)
                raise
        return {"language": "python", "name": name, "greeting": f"hello, {name}"}


async def main():
    os.environ["DURABLE_WORKFLOW_WORKER_PROTOCOL_VERSION"] = "1.20"
    with open(sys.argv[1], encoding="utf-8") as source:
        input_data = json.load(source)
    print(json.dumps({
        "process_id": os.getpid(), "installed_sdk_version": version("durable-workflow"),
        "namespace": "shared", "workflow_type": "nexus.fixture.python.greeter",
        "worker_protocol": "1.20", "cooperative_cancellation": True,
    }), flush=True)
    async with Client("http://server:8080", namespace="shared",
                      worker_token=input_data["token"]) as client:
        worker = Worker(client, task_queue="nexus-python-service",
                        worker_id=f"nexus-python-service-{os.getpid()}",
                        workflows=[PythonGreeter], activities=[], capabilities=["cooperative_cancellation"])
        await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
