"""Explicit installed-package controls for Workflow #878, outside the unit suite."""

from __future__ import annotations

import argparse
import asyncio
import json
import logging
import os
import signal
import time
from importlib.metadata import version
from pathlib import Path
from typing import Any

from durable_workflow import Client, Worker, activity, workflow

QUEUE = "lease-qualification-python"
WORKFLOW_TYPE = "qualification.runtime-lease-python"
ACTIVITY_TYPE = "qualification.blocking-python"
EXPECTED = [9007199254740993, None, "λ"]


def state() -> Path:
    return Path(os.environ.get("STATE_DIR", "/state"))


@workflow.defn(name=WORKFLOW_TYPE)
class LeaseWorkflow:
    def run(self, ctx: Any, seconds: int, mode: str):
        return (yield ctx.schedule_activity(
            ACTIVITY_TYPE, [seconds, mode],
            retry_policy={"max_attempts": 2, "backoff_seconds": [0]},
        ))


@activity.defn(name=ACTIVITY_TYPE)
def blocking(seconds: int, mode: str) -> list[Any]:
    info = activity.context().info
    record = {
        "mode": mode, "pid": os.getpid(), "monotonic_ns": time.monotonic_ns(),
        "task_id": info.task_id, "activity_attempt_id": info.activity_attempt_id,
        "attempt": info.attempt_number,
    }
    with (state() / "invocations.jsonl").open("a", encoding="utf-8") as output:
        output.write(json.dumps(record) + "\n")
    if mode == "long" or info.attempt_number == 1:
        time.sleep(seconds)
    return EXPECTED


async def run_worker(client: Client) -> None:
    worker = Worker(
        client, task_queue=QUEUE, workflows=[LeaseWorkflow], activities=[blocking],
        worker_id=os.environ["WORKER_ID"], poll_timeout=1, shutdown_timeout=5,
    )
    (state() / "worker.pid").write_text(str(os.getpid()), encoding="utf-8")
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for signum in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(signum, stop.set)
    running = asyncio.create_task(worker.run())
    requested = asyncio.create_task(stop.wait())
    try:
        done, _ = await asyncio.wait((running, requested), return_when=asyncio.FIRST_COMPLETED)
        if running not in done:
            await worker.stop()
        await running
    finally:
        requested.cancel()
        await asyncio.gather(requested, return_exceptions=True)
        for signum in (signal.SIGTERM, signal.SIGINT):
            loop.remove_signal_handler(signum)


async def main(role: str) -> None:
    requirement = Path(__file__).with_name("requirements.txt").read_text(encoding="utf-8").strip()
    expected_version = requirement.removeprefix("durable-workflow==")
    if version("durable-workflow") != expected_version:
        raise RuntimeError("The normally installed SDK differs from requirements.txt")
    async with Client(
        os.environ["SERVER_URL"], token="synthetic", namespace=os.environ["NAMESPACE"],
    ) as client:
        discovery = await client.get_cluster_info()
        if discovery.get("worker_protocol", {}).get("server_capabilities", {}).get("activity_lease_renewal") is not True:
            raise RuntimeError("The runtime does not advertise activity lease renewal")
        if role == "worker":
            await run_worker(client)
            return
        started = time.monotonic()
        handle = await client.start_workflow(
            workflow_type=WORKFLOW_TYPE, task_queue=QUEUE,
            workflow_id=os.environ["WORKFLOW_ID"], input=[360, role],
            execution_timeout_seconds=420, run_timeout_seconds=420,
        )
        (state() / "started.json").write_text(json.dumps({
            "workflow_id": handle.workflow_id, "run_id": handle.run_id, "mode": role,
        }), encoding="utf-8")
        result = await handle.result(timeout=420 if role == "long" else 30)
        elapsed = time.monotonic() - started
        if result != EXPECTED or (role == "long" and elapsed < 360):
            raise RuntimeError("The original real-duration/typed-result control failed")
        print(json.dumps({"sdk": version("durable-workflow"), "mode": role,
                          "workflow_id": handle.workflow_id, "run_id": handle.run_id,
                          "elapsed_seconds": elapsed, "result": result}, ensure_ascii=False), flush=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("role", choices=("worker", "long", "loss"))
    arguments = parser.parse_args()
    logging.basicConfig(level=logging.INFO)
    asyncio.run(main(arguments.role))
