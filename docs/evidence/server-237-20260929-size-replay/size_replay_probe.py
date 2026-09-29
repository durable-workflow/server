"""Published-SDK history-size probe with separately timed offline replay."""

import asyncio
import collections
import gzip
import json
import os
import resource
import sys
import time

from durable_workflow import Client, Replayer, Worker, activity, workflow


@activity.defn(name="history-qualification-size-boundary")
def boundary(index):
    return index


@workflow.defn(name="history-qualification-size-only")
class PaddedSideEffectHistory:
    def run(self, ctx, count, padding_bytes, activity_interval=100):
        padding = "x" * padding_bytes
        total = 0
        for index in range(count):
            recorded = yield ctx.side_effect(
                lambda value=index, data=padding: {"index": value, "padding": data}
            )
            if recorded != {"index": index, "padding": padding}:
                raise ValueError(f"side effect {index} did not replay its exact bytes")
            total += index
            if (index + 1) % activity_interval == 0 and index + 1 < count:
                acknowledged = yield ctx.schedule_activity(
                    "history-qualification-size-boundary", [index]
                )
                if acknowledged != index:
                    raise ValueError(f"activity boundary {index} returned {acknowledged!r}")
        return total


async def main(count: int, padding_bytes: int) -> None:
    if count < 1 or count > 12000:
        raise ValueError("count must be in [1, 12000]")
    if padding_bytes < 0 or padding_bytes > 8192:
        raise ValueError("padding_bytes must be in [0, 8192]")
    activity_interval = int(os.environ.get("PROBE_ACTIVITY_INTERVAL", "100"))
    if activity_interval < 1 or activity_interval > 1000:
        raise ValueError("PROBE_ACTIVITY_INTERVAL must be in [1, 1000]")
    replay_repetitions = int(os.environ.get("PROBE_REPLAY_REPETITIONS", "3"))
    if replay_repetitions < 1 or replay_repetitions > 5:
        raise ValueError("PROBE_REPLAY_REPETITIONS must be in [1, 5]")

    label = os.environ.get("PROBE_RUN_ID", f"size-{padding_bytes}")
    workflow_id = f"history-qualification-size-{count}-{padding_bytes}-{label}"
    queue = f"history-qualification-size-{count}-{padding_bytes}-{label}"
    expected_total = count * (count - 1) // 2
    start_input = (
        [count, padding_bytes]
        if activity_interval == 100
        else [count, padding_bytes, activity_interval]
    )

    async with Client(
        os.environ["SERVER_URL"],
        control_token=os.environ["DRILL_CONTROL_TOKEN"],
        worker_token=os.environ["DRILL_WORKER_TOKEN"],
        namespace="default",
    ) as client:
        handle = await client.start_workflow(
            workflow_type="history-qualification-size-only",
            task_queue=queue,
            workflow_id=workflow_id,
            input=start_input,
            execution_timeout_seconds=1800,
            run_timeout_seconds=1800,
        )
        worker = Worker(
            client,
            task_queue=queue,
            workflows=[PaddedSideEffectHistory],
            activities=[boundary],
        )
        worker_started = time.perf_counter()
        await worker.run_until(workflow_id=workflow_id, timeout=1800)
        worker_seconds = time.perf_counter() - worker_started
        worker_peak_rss_kib = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss

        execution = await client.describe_workflow(workflow_id)
        result = await handle.result(timeout=30)
        if result != expected_total:
            raise ValueError(f"wrong durable result: {result!r}")

        fetch_started = time.perf_counter()
        events = []
        page_count = 0
        page_token = None
        seen_tokens = set()
        while True:
            page = await client.get_history(
                workflow_id, execution.run_id, page_size=500, next_page_token=page_token
            )
            page_count += 1
            events.extend(page["events"])
            page_token = page.get("next_page_token")
            if not page_token:
                break
            if page_token in seen_tokens:
                raise ValueError("history pagination repeated a token")
            seen_tokens.add(page_token)
        history_fetch_seconds = time.perf_counter() - fetch_started

    sequences = [event["sequence"] for event in events]
    if sequences != list(range(1, len(events) + 1)):
        raise ValueError("history has a missing or reordered event")
    event_types = collections.Counter(event["event_type"] for event in events)
    expected_activities = (count - 1) // activity_interval
    if (
        event_types["SideEffectRecorded"] != count
        or event_types["WorkflowCompleted"] != 1
        or event_types["ActivityCompleted"] != expected_activities
    ):
        raise ValueError(f"unexpected event types: {dict(event_types)}")

    export_path = os.environ.get("PROBE_HISTORY_EXPORT_PATH")
    if export_path:
        with gzip.open(export_path, "wt", encoding="utf-8") as output:
            json.dump({"events": events}, output, sort_keys=True, separators=(",", ":"))

    replayer = Replayer(workflows=[PaddedSideEffectHistory])
    replay_seconds = []
    replay_command_types = []
    for _ in range(replay_repetitions):
        started = time.perf_counter()
        outcome = replayer.replay(
            events,
            start_input,
            workflow_type="history-qualification-size-only",
            workflow_id=workflow_id,
            run_id=execution.run_id,
        )
        replay_seconds.append(time.perf_counter() - started)
        replay_command_types = [type(command).__name__ for command in outcome.commands]
        completed = [
            command for command in outcome.commands
            if type(command).__name__ == "CompleteWorkflow"
        ]
        if len(completed) != 1 or completed[0].result != expected_total:
            raise ValueError("offline replay did not reproduce the exact completion")

    print(json.dumps({
        "schema": "server-237-size-replay-probe-v1",
        "workflow_id": workflow_id,
        "run_id": execution.run_id,
        "count": count,
        "padding_bytes": padding_bytes,
        "activity_interval": activity_interval,
        "result": result,
        "event_count": len(events),
        "event_types": dict(event_types),
        "history_pages": page_count,
        "history_fetch_seconds": history_fetch_seconds,
        "worker_seconds": worker_seconds,
        "worker_peak_rss_kib": worker_peak_rss_kib,
        "offline_replay_seconds": replay_seconds,
        "replay_command_types": replay_command_types,
        "replay_peak_rss_kib": resource.getrusage(resource.RUSAGE_SELF).ru_maxrss,
        "history_export_path": export_path,
    }, sort_keys=True))


if __name__ == "__main__":
    asyncio.run(main(int(sys.argv[1]), int(sys.argv[2])))
