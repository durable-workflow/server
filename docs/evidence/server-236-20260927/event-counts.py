import asyncio
import json
import os
from collections import Counter

from drill import client


IDS = (
    "restore-drill-completed",
    "restore-drill-pending",
    "restore-drill-edge",
    "restore-drill-timer",
)


async def main():
    rows = {}
    async with client() as runtime:
        for workflow_id in os.environ.get("DRILL_IDS", ",".join(IDS)).split(","):
            execution = await runtime.describe_workflow(workflow_id)
            history = await runtime.get_history(workflow_id, execution.run_id)
            if history.get("next_page_token"):
                raise RuntimeError(f"history pagination not consumed: {workflow_id}")
            rows[workflow_id] = {
                "run_id": execution.run_id,
                "status": execution.status,
                "events": dict(Counter(
                    event["event_type"] for event in history["events"]
                )),
            }
    print(json.dumps(rows, sort_keys=True))


asyncio.run(main())
