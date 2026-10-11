"""Execute installed Python Nexus authoring against an actual PHP SDK worker."""

from __future__ import annotations

import asyncio
import contextlib
import json
import os
import sys
from importlib.metadata import version

from durable_workflow import Client, Worker, serializer, workflow


@workflow.defn(name="nexus.fixture.python.caller")
class PythonNexusCaller:
    def run(self, context, endpoint, service, operation, name):
        result = yield context.call_nexus_service(
            endpoint, service, operation, [name], wait_timeout_seconds=5,
        )
        return result.to_recorded_payload()


async def execute(input_data, namespace):
    receipts = []

    async def observe(response):
        if "/service-endpoints/" not in response.request.url.path:
            return
        await response.aread()
        receipts.append({
            "method": response.request.method,
            "path": response.request.url.path,
            "request_namespace": response.request.headers.get("X-Namespace"),
            "request": json.loads(response.request.content) if response.request.content else None,
            "status": response.status_code,
            "response": response.json(),
        })

    async with Client("http://server:8080", namespace=namespace,
                      token=input_data["token"], worker_token=input_data["token"]) as client:
        client._http.event_hooks["response"] = [observe]
        worker = Worker(client, task_queue="nexus-python-workflow",
                        worker_id=f"nexus-python-caller-{namespace}-{os.getpid()}",
                        workflows=[PythonNexusCaller], activities=[])
        polling = asyncio.create_task(worker.run())
        try:
            handle = await client.start_workflow(
                workflow_type="nexus.fixture.python.caller",
                task_queue="nexus-python-workflow",
                workflow_id=f"nexus-python-caller-{namespace}-{os.getpid()}",
                input=[input_data["endpoint"], input_data["service"],
                       input_data["operation"], "world"],
            )
            observation = {
                "caller_namespace": namespace, "target_namespace": "shared",
                "workflow_id": handle.workflow_id, "run_id": handle.run_id,
                "process_id": os.getpid(), "installed_sdk_version": version("durable-workflow"),
                "public_method": "WorkflowContext.call_nexus_service",
            }
            try:
                observation["result"] = await handle.result(timeout=30)
            except Exception as error:
                observation["failure"] = {"type": type(error).__name__, "message": str(error)}
            observation["history"] = await handle.get_history()
            observation["receipts"] = receipts
            recorded = observation.get("result", {})
            service_result = recorded.get("result")
            if isinstance(service_result, str):
                service_result = serializer.decode(service_result, codec="avro")
            observation["decoded_service_result"] = service_result
            observation["completed"] = recorded.get("status") == "completed" and service_result == {
                "language": "php", "name": "world", "greeting": "hello, world",
            }
            return observation
        finally:
            await asyncio.wait_for(worker.stop(), timeout=10)
            polling.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await polling


async def main():
    with open(sys.argv[1], encoding="utf-8") as source:
        input_data = json.load(source)
    observations = []
    for namespace in ["shared", "tenant-b"]:
        observations.append(await execute(input_data, namespace))
    print(json.dumps({
        "scope": "python_caller_php_workflow_service",
        "outcome": "pass" if all(row["completed"] for row in observations) else "fail",
        "observations": observations,
        "recovery_qualified": False, "typed_failure_qualified": False,
        "cancellation_qualified": False,
    }))


if __name__ == "__main__":
    asyncio.run(main())
