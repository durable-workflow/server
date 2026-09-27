import asyncio
import json

from durable_workflow import Worker
from drill import (
    CompleteWorkflow,
    IDS,
    PAYLOADS,
    TimerWorkflow,
    client,
    digest,
    digest_activity,
    inspect_client,
)


async def main():
    async with client() as runtime:
        worker = Worker(
            runtime,
            task_queue="restore-drill-live",
            workflows=[CompleteWorkflow, TimerWorkflow],
            activities=[digest_activity],
        )
        await worker.run_until(workflow_id=IDS["timer"], timeout=120)
        result = await runtime.get_workflow_handle(IDS["timer"]).result(timeout=20)
        if result != digest(PAYLOADS["timer"]):
            raise RuntimeError("restored timer returned the wrong payload digest")
        print(json.dumps({
            "phase": "timer-resume",
            "result": result,
            "workflows": await inspect_client(runtime),
        }, sort_keys=True))


asyncio.run(main())
