import asyncio
import hashlib
import json
import os
import sys
from pathlib import Path

import boto3
from botocore.config import Config
from durable_workflow import Client, Worker, activity, workflow


IDS = {
    "completed": "restore-drill-completed",
    "timer": "restore-drill-timer",
    "pending": "restore-drill-pending",
}
PAYLOADS = {
    "completed": "completed:" + "A" * 8192,
    "timer": "timer:" + "B" * 8192,
    "pending": "pending:" + "C" * 8192,
}


def digest(value):
    encoded = value.encode("utf-8")
    return {"bytes": len(encoded), "sha256": hashlib.sha256(encoded).hexdigest()}


@activity.defn(name="restore-drill-digest")
def digest_activity(value):
    return digest(value)


@workflow.defn(name="restore-drill-complete")
class CompleteWorkflow:
    def run(self, ctx, value):
        return (yield ctx.schedule_activity("restore-drill-digest", [value]))


@workflow.defn(name="restore-drill-timer")
class TimerWorkflow:
    def run(self, ctx, value):
        yield ctx.start_timer(3600)
        return digest(value)


def client():
    return Client(
        os.getenv("SERVER_URL", "http://server:8080"),
        control_token=os.environ["DRILL_CONTROL_TOKEN"],
        worker_token=os.environ["DRILL_WORKER_TOKEN"],
        namespace="default",
    )


async def inspect_client(runtime):
    rows = {}
    for label, workflow_id in IDS.items():
        execution = await runtime.describe_workflow(workflow_id)
        history = await runtime.get_history(workflow_id, execution.run_id)
        expected_input = [PAYLOADS[label]]
        input_matches = execution.input == expected_input
        if not input_matches:
            raise RuntimeError(f"{label} workflow input did not round-trip")
        serialized_history = json.dumps(history, sort_keys=True, default=str).encode()
        rows[label] = {
            "workflow_id": workflow_id,
            "run_id": execution.run_id,
            "status": execution.status,
            "input_matches": input_matches,
            "input_digest": digest(PAYLOADS[label]),
            "output": execution.output,
            "history_sha256": hashlib.sha256(serialized_history).hexdigest(),
            "history_bytes": len(serialized_history),
        }
    return rows


async def seed():
    async with client() as runtime:
        completed = await runtime.start_workflow(
            workflow_type="restore-drill-complete",
            task_queue="restore-drill-live",
            workflow_id=IDS["completed"],
            input=[PAYLOADS["completed"]],
            execution_timeout_seconds=7200,
            run_timeout_seconds=7200,
        )
        worker = Worker(
            runtime,
            task_queue="restore-drill-live",
            workflows=[CompleteWorkflow, TimerWorkflow],
            activities=[digest_activity],
        )
        await worker.run_until(workflow_id=IDS["completed"], timeout=90)
        completed_result = await completed.result(timeout=20)
        if completed_result != digest(PAYLOADS["completed"]):
            raise RuntimeError("completed workflow returned the wrong result")

        await runtime.start_workflow(
            workflow_type="restore-drill-timer",
            task_queue="restore-drill-live",
            workflow_id=IDS["timer"],
            input=[PAYLOADS["timer"]],
            execution_timeout_seconds=7200,
            run_timeout_seconds=7200,
        )
        timer_worker = Worker(
            runtime,
            task_queue="restore-drill-live",
            workflows=[CompleteWorkflow, TimerWorkflow],
            activities=[digest_activity],
        )
        task = asyncio.create_task(timer_worker.run())
        await asyncio.sleep(8)
        await timer_worker.stop()
        await task

        await runtime.start_workflow(
            workflow_type="restore-drill-complete",
            task_queue="restore-drill-pending",
            workflow_id=IDS["pending"],
            input=[PAYLOADS["pending"]],
            execution_timeout_seconds=7200,
            run_timeout_seconds=7200,
        )
        print(json.dumps({"phase": "seed", "workflows": await inspect_client(runtime)}, sort_keys=True))


async def inspect():
    async with client() as runtime:
        print(json.dumps({"phase": "inspect", "workflows": await inspect_client(runtime)}, sort_keys=True))


async def resume():
    async with client() as runtime:
        worker = Worker(
            runtime,
            task_queue="restore-drill-pending",
            workflows=[CompleteWorkflow],
            activities=[digest_activity],
        )
        await worker.run_until(workflow_id=IDS["pending"], timeout=90)
        handle = runtime.get_workflow_handle(IDS["pending"])
        result = await handle.result(timeout=20)
        if result != digest(PAYLOADS["pending"]):
            raise RuntimeError("restored pending workflow returned the wrong result")
        print(json.dumps({"phase": "resume", "result": result, "workflows": await inspect_client(runtime)}, sort_keys=True))


def s3_client():
    return boto3.client(
        "s3",
        endpoint_url=os.environ["DRILL_S3_ENDPOINT"],
        aws_access_key_id="drill-access",
        aws_secret_access_key="drill-secret",
        region_name="us-east-1",
        config=Config(s3={"addressing_style": "path"}),
    )


def backup_objects():
    directory = Path(os.environ["DRILL_OBJECT_DIR"])
    directory.mkdir(parents=True, exist_ok=True)
    s3 = s3_client()
    bucket = "restore-drill"
    objects = []
    for page in s3.get_paginator("list_objects_v2").paginate(Bucket=bucket):
        for item in page.get("Contents", []):
            key = item["Key"]
            data = s3.get_object(Bucket=bucket, Key=key)["Body"].read()
            filename = hashlib.sha256(key.encode()).hexdigest()
            (directory / filename).write_bytes(data)
            objects.append({"key": key, "file": filename, "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()})
    if not objects:
        raise RuntimeError("no external payload objects were found")
    (directory / "manifest.json").write_text(json.dumps(objects, sort_keys=True, indent=2))
    print(json.dumps({"phase": "backup_objects", "count": len(objects), "total_bytes": sum(row["bytes"] for row in objects)}, sort_keys=True))


def restore_objects():
    directory = Path(os.environ["DRILL_OBJECT_DIR"])
    objects = json.loads((directory / "manifest.json").read_text())
    s3 = s3_client()
    bucket = "restore-drill"
    for item in objects:
        data = (directory / item["file"]).read_bytes()
        if len(data) != item["bytes"] or hashlib.sha256(data).hexdigest() != item["sha256"]:
            raise RuntimeError(f"backup object checksum mismatch: {item['file']}")
        s3.put_object(Bucket=bucket, Key=item["key"], Body=data)
        restored = s3.get_object(Bucket=bucket, Key=item["key"])["Body"].read()
        if restored != data:
            raise RuntimeError(f"restored object differs: {item['file']}")
    print(json.dumps({"phase": "restore_objects", "count": len(objects), "total_bytes": sum(row["bytes"] for row in objects)}, sort_keys=True))


if __name__ == "__main__":
    mode = sys.argv[1]
    if mode == "seed":
        asyncio.run(seed())
    elif mode == "inspect":
        asyncio.run(inspect())
    elif mode == "resume":
        asyncio.run(resume())
    elif mode == "backup-objects":
        backup_objects()
    elif mode == "restore-objects":
        restore_objects()
    else:
        raise SystemExit(f"unknown mode: {mode}")
