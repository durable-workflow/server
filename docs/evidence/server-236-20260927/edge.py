import asyncio
import hashlib
import json
import sys

from durable_workflow import Worker
from drill import CompleteWorkflow, client, digest, digest_activity


WORKFLOW_ID = "restore-drill-edge"
VALUE = "edge:" + "D" * 8192


async def inspect(runtime):
    execution = await runtime.describe_workflow(WORKFLOW_ID)
    history = await runtime.get_history(WORKFLOW_ID, execution.run_id)
    history_bytes = json.dumps(history, sort_keys=True, default=str).encode()
    result = {
        "workflow_id": WORKFLOW_ID,
        "run_id": execution.run_id,
        "status": execution.status,
        "input_matches": execution.input == [VALUE],
        "input_digest": digest(VALUE),
        "output": execution.output,
        "history_bytes": len(history_bytes),
        "history_sha256": hashlib.sha256(history_bytes).hexdigest(),
        "workflow_completed_events": sum(
            event.get("event_type") == "WorkflowCompleted"
            for event in history.get("events", [])
        ),
    }
    if not result["input_matches"]:
        raise RuntimeError("edge input bytes differ")
    return result


async def main(mode):
    async with client() as runtime:
        if mode == "start":
            await runtime.start_workflow(
                workflow_type="restore-drill-complete",
                task_queue="restore-drill-edge",
                workflow_id=WORKFLOW_ID,
                input=[VALUE],
                execution_timeout_seconds=7200,
                run_timeout_seconds=7200,
            )
        elif mode == "resume":
            worker = Worker(
                runtime,
                task_queue="restore-drill-edge",
                workflows=[CompleteWorkflow],
                activities=[digest_activity],
            )
            await worker.run_until(workflow_id=WORKFLOW_ID, timeout=90)
            result = await runtime.get_workflow_handle(WORKFLOW_ID).result(timeout=20)
            if result != digest(VALUE):
                raise RuntimeError("edge result bytes differ")
        elif mode != "inspect":
            raise RuntimeError(f"unknown mode: {mode}")
        print(json.dumps({"phase": mode, "edge": await inspect(runtime)}, sort_keys=True))


if __name__ == "__main__":
    asyncio.run(main(sys.argv[1]))
