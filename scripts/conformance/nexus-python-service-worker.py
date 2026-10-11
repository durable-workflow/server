"""Run an installed Python SDK workflow service for the PHP caller probe."""

from __future__ import annotations

import asyncio
import json
import os
import sys
from importlib.metadata import version

from durable_workflow import Client, Worker, workflow


class NexusFixtureFailure(RuntimeError):
    pass


@workflow.defn(name="nexus.fixture.python.greeter")
class PythonGreeter:
    def run(self, context, name):
        if name == "fail":
            raise NexusFixtureFailure("fixture service failure")
        return {"language": "python", "name": name, "greeting": f"hello, {name}"}


async def main():
    with open(sys.argv[1], encoding="utf-8") as source:
        input_data = json.load(source)
    print(json.dumps({
        "process_id": os.getpid(), "installed_sdk_version": version("durable-workflow"),
        "namespace": "shared", "workflow_type": "nexus.fixture.python.greeter",
    }), flush=True)
    async with Client("http://server:8080", namespace="shared",
                      worker_token=input_data["token"]) as client:
        worker = Worker(client, task_queue="nexus-python-service",
                        worker_id=f"nexus-python-service-{os.getpid()}",
                        workflows=[PythonGreeter], activities=[])
        await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
