import asyncio
from pathlib import Path

from durable_workflow import Client, Worker, activity, workflow


@activity.defn(name="storage.python.echo")
async def echo() -> str:
    value = "python:" + "x" * 262144
    with Path("/observation/python.effects").open("a") as output:
        output.write("executed\n")
    async with asyncio.timeout(120):
        while not Path("/observation/release-activities").is_file():
            await asyncio.sleep(0.1)
    Path("/observation/python.returned").write_text("ready")
    return value


@workflow.defn(name="storage.python")
class StorageWorkflow:
    def run(self, context):
        import hashlib

        value = yield context.schedule_activity(
            "storage.python.echo", [], start_to_close_timeout=180
        )
        return {"runtime": "python", "bytes": len(value), "sha256": hashlib.sha256(value.encode()).hexdigest()}


async def main():
    async with Client("http://server:8080", token="storage-fixture") as client:
        await Worker(
            client, task_queue="storage-python", worker_id="storage-python",
            workflows=[StorageWorkflow], activities=[echo],
            poll_timeout=1, heartbeat_interval=5,
        ).run()


asyncio.run(main())
