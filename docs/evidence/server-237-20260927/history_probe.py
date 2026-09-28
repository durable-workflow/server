"""Bounded published-SDK probe for a single run's event-history cost."""

import asyncio
import collections
import json
import os
import resource
import sys
import time

from durable_workflow import Client, Worker, activity, workflow


@activity.defn(name="history-qualification-boundary")
def boundary(index):
    return index


@workflow.defn(name="history-qualification-side-effects")
class SideEffectHistory:
    def run(self, ctx, count, activity_interval=75):
        total = 0
        for index in range(count):
            recorded = yield ctx.side_effect(lambda value=index: value)
            if recorded != index:
                raise ValueError(f"side effect {index} replayed as {recorded!r}")
            total += recorded
            if (index + 1) % activity_interval == 0 and index + 1 < count:
                acknowledged = yield ctx.schedule_activity("history-qualification-boundary", [index])
                if acknowledged != index:
                    raise ValueError(f"activity boundary {index} returned {acknowledged!r}")
        return total


async def main(count):
    if count < 1 or count > 12000:
        raise ValueError("count must be in [1, 12000]")
    worker_timeout = int(os.environ.get("PROBE_WORKER_TIMEOUT_SECONDS", "900"))
    if worker_timeout < 1 or worker_timeout > 3600:
        raise ValueError("PROBE_WORKER_TIMEOUT_SECONDS must be in [1, 3600]")
    activity_interval = int(os.environ.get("PROBE_ACTIVITY_INTERVAL", "75"))
    if activity_interval < 1 or activity_interval > 1000:
        raise ValueError("PROBE_ACTIVITY_INTERVAL must be in [1, 1000]")
    run_id = os.environ.get("PROBE_RUN_ID", "first")
    workflow_id = f"history-qualification-side-effects-{count}-{run_id}"
    queue = f"history-qualification-{count}-{run_id}"
    async with Client(
        os.environ["SERVER_URL"],
        control_token=os.environ["DRILL_CONTROL_TOKEN"],
        worker_token=os.environ["DRILL_WORKER_TOKEN"],
        namespace="default",
    ) as client:
        start = time.monotonic()
        handle = await client.start_workflow(
            workflow_type="history-qualification-side-effects",
            task_queue=queue,
            workflow_id=workflow_id,
            input=[count] if activity_interval == 75 else [count, activity_interval],
            execution_timeout_seconds=3600,
            run_timeout_seconds=3600,
        )
        started = time.monotonic()
        worker = Worker(client, task_queue=queue, workflows=[SideEffectHistory], activities=[boundary])
        await worker.run_until(workflow_id=workflow_id, timeout=worker_timeout)
        finished = time.monotonic()
        worker_peak_rss_kib = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
        execution = await client.describe_workflow(workflow_id)
        types = collections.Counter()
        event_count = 0
        page_count = 0
        last_sequence = 0
        page_token = None
        seen_tokens = set()
        while True:
            page = await client.get_history(
                workflow_id, execution.run_id, page_size=1000, next_page_token=page_token
            )
            page_count += 1
            for event in page["events"]:
                sequence = event["sequence"]
                if sequence <= last_sequence:
                    raise ValueError(f"history sequence did not advance after {last_sequence}")
                last_sequence = sequence
                event_count += 1
                types[event["event_type"]] += 1
            page_token = page.get("next_page_token")
            if not page_token:
                break
            if page_token in seen_tokens:
                raise ValueError("history pagination repeated a token")
            seen_tokens.add(page_token)
        result = await handle.result(timeout=30)
        if result != count * (count - 1) // 2:
            raise ValueError(f"wrong result: {result!r}; status={execution.status}; events={dict(types)}; output={execution.output!r}")
        expected_activities = (count - 1) // activity_interval
        if (
            types["SideEffectRecorded"] != count
            or types["WorkflowCompleted"] != 1
            or any(types[event_type] != expected_activities for event_type in (
                "ActivityScheduled", "ActivityStarted", "ActivityCompleted"
            ))
        ):
            raise ValueError(f"unexpected history event counts: {types}")
        print(json.dumps({
            "schema": "server-237-history-probe-v1",
            "workflow_id": workflow_id,
            "run_id": execution.run_id,
            "count": count,
            "activity_interval": activity_interval,
            "result": result,
            "start_to_worker_seconds": started - start,
            "worker_seconds": finished - started,
            "worker_timeout_seconds": worker_timeout,
            "event_count": event_count,
            "history_pages": page_count,
            "event_types": dict(types),
            "worker_peak_rss_kib": worker_peak_rss_kib,
        }, sort_keys=True))


if __name__ == "__main__":
    asyncio.run(main(int(sys.argv[1])))
