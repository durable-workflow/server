#!/usr/bin/env python3
"""HA load for native behind MySQL Router: start workflows continuously,
wait for each result, and record outcomes and timing as JSON lines."""

import argparse
import asyncio
import json
import logging
import sys
import time
import os
import uuid

from durable_workflow import Client, Worker, activity, workflow

logging.basicConfig(level=os.environ.get("LOGLEVEL", "ERROR"))
logging.getLogger("durable_workflow.worker").setLevel(os.environ.get("LOGLEVEL", "ERROR"))
WF = "ha.timer_activity"
ACT = "ha.echo"


@activity.defn(name=ACT)
async def echo(value):
    await asyncio.sleep(0.05)
    return value


@workflow.defn(name=WF)
class TimerActivity:
    def run(self, context, request):
        yield context.sleep(1)
        first = yield context.schedule_activity(ACT, [{"step": 1, "n": request["n"]}])
        second = yield context.schedule_activity(ACT, [{"step": 2, "n": request["n"]}])
        return {"n": request["n"], "first": first, "second": second}


def emit(record):
    record["t"] = round(time.time(), 3)
    sys.stdout.write(json.dumps(record) + "\n")
    sys.stdout.flush()


async def one(client, n, queue):
    started = time.monotonic()
    wid = f"ha-{n}-{uuid.uuid4().hex[:8]}"
    try:
        handle = await client.start_workflow(workflow_type=WF, task_queue=queue, workflow_id=wid, input=[{"n": n}])
    except Exception as error:  # start refused or connection lost
        emit({"event": "start_error", "n": n, "wid": wid, "error": f"{type(error).__name__}: {str(error)[:200]}"})
        return
    emit({"event": "started", "n": n, "wid": wid})
    try:
        result = await handle.result(timeout=300, poll_interval=0.5)
        ok = result == {"n": n, "first": {"step": 1, "n": n}, "second": {"step": 2, "n": n}}
        emit({"event": "completed", "n": n, "wid": wid, "ok": ok, "seconds": round(time.monotonic() - started, 3)})
    except Exception as error:
        emit({"event": "result_error", "n": n, "wid": wid, "error": f"{type(error).__name__}: {str(error)[:200]}"})


async def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--count", type=int, default=120)
    parser.add_argument("--rate", type=float, default=2.0)
    args = parser.parse_args()
    queue = "ha-queue"
    client = Client(os.environ["NATIVE_URL"], token=os.environ["DW_AUTH_TOKEN"], namespace="default", timeout=10)
    try:
        worker = Worker(client, task_queue=queue, workflows=[TimerActivity], activities=[echo],
                        worker_id=f"ha-worker-{uuid.uuid4().hex[:6]}", poll_timeout=2,
                        max_concurrent_workflow_tasks=8, max_concurrent_activity_tasks=8, heartbeat_interval=5)
        worker_task = asyncio.create_task(worker.run())
        tasks = []
        for n in range(args.count):
            tasks.append(asyncio.create_task(one(client, n, queue)))
            await asyncio.sleep(1.0 / args.rate)
        await asyncio.gather(*tasks)
        emit({"event": "done"})
        worker_task.cancel()
    finally:
        await client.aclose()


asyncio.run(main())
