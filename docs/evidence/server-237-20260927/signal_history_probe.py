"""Bounded signal-history probe using a published Python SDK and Server."""

import asyncio
import collections
import json
import os
import resource
import sys
import time

from durable_workflow import Client, Worker, workflow


@workflow.defn(name="history-qualification-signals")
class SignalHistory:
    def __init__(self):
        self.seen = set()
        self.total = 0

    @workflow.signal("append")
    def append(self, index):
        if index in self.seen:
            raise ValueError(f"duplicate signal index: {index}")
        self.seen.add(index)
        self.total += index

    def run(self, ctx, target):
        yield ctx.wait_condition(lambda: len(self.seen) == target, key="all-signals")
        return {"count": len(self.seen), "total": self.total}


def percentile(samples, fraction):
    ordered = sorted(samples)
    return ordered[min(len(ordered) - 1, int((len(ordered) - 1) * fraction))]


async def main(target):
    if target < 1 or target > 12000:
        raise ValueError("target must be in [1, 12000]")
    run_label = os.environ.get("PROBE_RUN_ID", "first")
    fanout = int(os.environ.get("PROBE_SIGNAL_CONCURRENCY", "1"))
    if fanout < 1 or fanout > 16:
        raise ValueError("PROBE_SIGNAL_CONCURRENCY must be in [1, 16]")
    workflow_id = f"history-qualification-signals-{target}-{run_label}"
    queue = f"history-signals-{target}-{run_label}"
    async with Client(
        os.environ["SERVER_URL"],
        control_token=os.environ["DRILL_CONTROL_TOKEN"],
        worker_token=os.environ["DRILL_WORKER_TOKEN"],
        namespace="default",
    ) as client:
        handle = await client.start_workflow(
            workflow_type="history-qualification-signals",
            task_queue=queue,
            workflow_id=workflow_id,
            input=[target],
            execution_timeout_seconds=3600,
            run_timeout_seconds=3600,
        )
        first_worker = Worker(client, task_queue=queue, workflows=[SignalHistory])
        first_task = asyncio.create_task(first_worker.run())
        waiting_by = time.monotonic() + 30
        while True:
            execution = await client.describe_workflow(workflow_id)
            if execution.status == "waiting":
                break
            if time.monotonic() >= waiting_by:
                raise TimeoutError(f"workflow did not enter waiting state: {execution.status}")
            await asyncio.sleep(0.2)
        await first_worker.stop()
        await first_task

        latencies = []

        async def send_signal(index):
            sent_at = time.monotonic()
            await client.signal_workflow(workflow_id, "append", args=[index])
            latencies.append(time.monotonic() - sent_at)

        offer_started = time.monotonic()
        for start in range(0, target, fanout):
            await asyncio.gather(
                *(send_signal(index) for index in range(start, min(start + fanout, target)))
            )
            if (start + fanout) % 1000 < fanout or start + fanout >= target:
                print(
                    json.dumps({"phase": "offered", "signals": len(latencies)}, sort_keys=True),
                    flush=True,
                )
        offer_finished = time.monotonic()

        worker = Worker(client, task_queue=queue, workflows=[SignalHistory])
        resumed_at = time.monotonic()
        await worker.run_until(workflow_id=workflow_id, timeout=900)
        finished_at = time.monotonic()
        worker_peak_rss_kib = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
        result = await handle.result(timeout=30)
        expected = {"count": target, "total": target * (target - 1) // 2}
        if result != expected:
            raise ValueError(f"wrong signal result: {result!r}; expected={expected!r}")

        execution = await client.describe_workflow(workflow_id)
        types = collections.Counter()
        event_count = 0
        page_count = 0
        last_sequence = 0
        token = None
        seen_tokens = set()
        while True:
            page = await client.get_history(
                workflow_id, execution.run_id, page_size=1000, next_page_token=token
            )
            page_count += 1
            for event in page["events"]:
                sequence = event["sequence"]
                if sequence <= last_sequence:
                    raise ValueError("history sequence did not advance")
                last_sequence = sequence
                event_count += 1
                types[event["event_type"]] += 1
            token = page.get("next_page_token")
            if not token:
                break
            if token in seen_tokens:
                raise ValueError("history pagination repeated a token")
            seen_tokens.add(token)
        if types["SignalReceived"] != target or types["WorkflowCompleted"] != 1:
            raise ValueError(f"wrong signal event counts: {dict(types)}")
        print(json.dumps({
            "schema": "server-237-signal-history-probe-v1",
            "workflow_id": workflow_id,
            "run_id": execution.run_id,
            "target_signals": target,
            "signal_concurrency": fanout,
            "offered_signals": len(latencies),
            "result": result,
            "offer_seconds": offer_finished - offer_started,
            "signal_api_p50_seconds": percentile(latencies, 0.50),
            "signal_api_p95_seconds": percentile(latencies, 0.95),
            "signal_api_p99_seconds": percentile(latencies, 0.99),
            "resume_to_complete_seconds": finished_at - resumed_at,
            "worker_peak_rss_kib": worker_peak_rss_kib,
            "event_count": event_count,
            "history_pages": page_count,
            "event_types": dict(types),
        }, sort_keys=True))


if __name__ == "__main__":
    asyncio.run(main(int(sys.argv[1])))
