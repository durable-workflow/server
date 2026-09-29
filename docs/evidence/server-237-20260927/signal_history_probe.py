"""Bounded signal-history probe using a published Python SDK and Server."""

import asyncio
import collections
import json
import os
import resource
import sys
import time

from durable_workflow import Client, Worker, activity, workflow
from durable_workflow.external_storage import ExternalPayloadCache


@activity.defn(name="history-qualification-mixed-boundary")
def mixed_boundary(index):
    return index


@workflow.defn(name="history-qualification-signals")
class SignalHistory:
    def __init__(self):
        self.seen = set()
        self.total = 0
        self.payload_bytes = 0

    @workflow.signal("append")
    def append(self, index, payload=None):
        if index in self.seen:
            raise ValueError(f"duplicate signal index: {index}")
        if self.payload_bytes and payload != "p" * self.payload_bytes:
            raise ValueError(f"signal {index} payload did not survive replay")
        self.seen.add(index)
        self.total += index

    def run(self, ctx, target, mixed_interval=0, continue_new=False, stage=0, carried_total=0, payload_bytes=0):
        self.payload_bytes = payload_bytes
        if stage == 1:
            return {"count": target, "total": carried_total}

        yield ctx.wait_condition(lambda: len(self.seen) == target, key="all-signals")
        if mixed_interval:
            for index in range(mixed_interval, target + 1, mixed_interval):
                acknowledged = yield ctx.schedule_activity("history-qualification-mixed-boundary", [index])
                if acknowledged != index:
                    raise ValueError(f"activity boundary {index} returned {acknowledged!r}")
                yield ctx.sleep(0)

        if continue_new:
            return ctx.continue_as_new(target, mixed_interval, False, 1, self.total)
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
    mixed_interval = int(os.environ.get("PROBE_MIXED_INTERVAL", "0"))
    if mixed_interval < 0 or mixed_interval > target:
        raise ValueError("PROBE_MIXED_INTERVAL must be in [0, target]")
    continue_as_new = os.environ.get("PROBE_CONTINUE_AS_NEW") == "1"
    payload_bytes = int(os.environ.get("PROBE_SIGNAL_PAYLOAD_BYTES", "0"))
    if payload_bytes < 0 or payload_bytes > 1024 * 1024:
        raise ValueError("PROBE_SIGNAL_PAYLOAD_BYTES must be in [0, 1048576]")
    resume_run_id = os.environ.get("PROBE_RESUME_INITIAL_RUN_ID")
    worker_window = int(os.environ.get("PROBE_WORKER_WINDOW_SECONDS", "1800"))
    if worker_window < 1 or worker_window > 3600:
        raise ValueError("PROBE_WORKER_WINDOW_SECONDS must be in [1, 3600]")
    cache_entries = int(os.environ.get("PROBE_EXTERNAL_CACHE_ENTRIES", "0"))
    if cache_entries < 0 or cache_entries > 10000:
        raise ValueError("PROBE_EXTERNAL_CACHE_ENTRIES must be in [0, 10000]")
    external_cache = ExternalPayloadCache(max_entries=cache_entries) if cache_entries else None
    workflow_id = f"history-qualification-signals-{target}-{run_label}"
    queue = f"history-signals-{target}-{run_label}"
    if payload_bytes:
        async with Client(
            os.environ["SERVER_URL"],
            control_token=os.environ["DRILL_ADMIN_TOKEN"],
            worker_token=os.environ["DRILL_WORKER_TOKEN"],
            namespace="default",
        ) as admin_client:
            await admin_client.set_namespace_external_storage(
                "default", driver="local", threshold_bytes=1024
            )
    async with Client(
        os.environ["SERVER_URL"],
        control_token=os.environ["DRILL_CONTROL_TOKEN"],
        worker_token=os.environ["DRILL_WORKER_TOKEN"],
        namespace="default",
        external_storage_cache=external_cache,
    ) as client:
        if resume_run_id:
            handle = client.get_workflow_handle(workflow_id, run_id=resume_run_id)
            latencies = []
            offer_seconds = None
            print(json.dumps({"phase": "resuming", "initial_run_id": resume_run_id}), flush=True)
        else:
            handle = await client.start_workflow(
                workflow_type="history-qualification-signals",
                task_queue=queue,
                workflow_id=workflow_id,
                input=[target, mixed_interval, continue_as_new, 0, 0, payload_bytes]
                if mixed_interval or continue_as_new or payload_bytes else [target],
                execution_timeout_seconds=3600,
                run_timeout_seconds=3600,
            )
            first_worker = Worker(
                client, task_queue=queue, workflows=[SignalHistory], activities=[mixed_boundary]
            )
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
                args = [index, "p" * payload_bytes] if payload_bytes else [index]
                await client.signal_workflow(workflow_id, "append", args=args)
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
            offer_seconds = time.monotonic() - offer_started
            print(json.dumps({
                "phase": "offered_all",
                "offered_signals": len(latencies),
                "offer_seconds": offer_seconds,
                "signal_api_p50_seconds": percentile(latencies, 0.50),
                "signal_api_p95_seconds": percentile(latencies, 0.95),
                "signal_api_p99_seconds": percentile(latencies, 0.99),
            }, sort_keys=True), flush=True)
            if os.environ.get("PROBE_OFFER_ONLY") == "1":
                # Keep acknowledged signals pending for published-image cleanup checks.
                print(json.dumps({
                    "phase": "offered_only",
                    "initial_run_id": handle.run_id,
                    "workflow_id": workflow_id,
                    "acknowledged_signals": len(latencies),
                }, sort_keys=True), flush=True)
                return

        worker = Worker(
            client,
            task_queue=queue,
            workflows=[SignalHistory],
            activities=[mixed_boundary],
            external_storage_cache=external_cache,
        )
        print(json.dumps({
            "phase": "worker_cache",
            "external_cache_entries": worker.external_storage_cache.max_entries,
            "external_cache_bytes": worker.external_storage_cache.max_bytes,
        }, sort_keys=True), flush=True)
        resumed_at = time.monotonic()
        try:
            await worker.run_until(workflow_id=workflow_id, timeout=worker_window)
        except Exception:
            print(json.dumps({
                "phase": "worker_stopped_before_terminal",
                "workflow_id": workflow_id,
                "worker_peak_rss_kib": resource.getrusage(resource.RUSAGE_SELF).ru_maxrss,
                "resume_elapsed_seconds": time.monotonic() - resumed_at,
            }, sort_keys=True), flush=True)
            raise
        finished_at = time.monotonic()
        worker_peak_rss_kib = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
        result_handle = client.get_workflow_handle(workflow_id) if continue_as_new else handle
        result = await result_handle.result(timeout=30)
        expected = {"count": target, "total": target * (target - 1) // 2}
        if result != expected:
            raise ValueError(f"wrong signal result: {result!r}; expected={expected!r}")

        execution = await client.describe_workflow(workflow_id)
        run_ids = [handle.run_id]
        if continue_as_new:
            if execution.run_id == handle.run_id:
                raise ValueError("continue-as-new did not create a successor run")
            run_ids.append(execution.run_id)
        types = collections.Counter()
        event_count = 0
        page_count = 0
        for run_id in run_ids:
            last_sequence = 0
            token = None
            seen_tokens = set()
            while True:
                page = await client.get_history(
                    workflow_id, run_id, page_size=1000, next_page_token=token
                )
                page_count += 1
                for event in page["events"]:
                    sequence = event["sequence"]
                    if sequence <= last_sequence:
                        raise ValueError(f"history sequence did not advance for run {run_id}")
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
        if types["WorkflowContinuedAsNew"] != int(continue_as_new):
            raise ValueError(f"wrong continuation count: {dict(types)}")
        expected_boundaries = target // mixed_interval if mixed_interval else 0
        for event_type in (
            "ActivityScheduled", "ActivityCompleted", "TimerScheduled", "TimerFired",
        ):
            if types[event_type] != expected_boundaries:
                raise ValueError(f"wrong {event_type} count: {dict(types)}")
        if types["ActivityStarted"] < expected_boundaries:
            raise ValueError(f"missing activity starts: {dict(types)}")
        print(json.dumps({
            "schema": "server-237-signal-history-probe-v2",
            "workflow_id": workflow_id,
            "run_ids": run_ids,
            "target_signals": target,
            "signal_concurrency": fanout,
            "mixed_interval": mixed_interval,
            "signal_payload_bytes": payload_bytes,
            "continue_as_new": continue_as_new,
            "worker_window_seconds": worker_window,
            "resumed": bool(resume_run_id),
            "offered_signals": len(latencies) if not resume_run_id else None,
            "result": result,
            "offer_seconds": offer_seconds,
            "signal_api_p50_seconds": percentile(latencies, 0.50) if latencies else None,
            "signal_api_p95_seconds": percentile(latencies, 0.95) if latencies else None,
            "signal_api_p99_seconds": percentile(latencies, 0.99) if latencies else None,
            "resume_to_complete_seconds": finished_at - resumed_at,
            "worker_peak_rss_kib": worker_peak_rss_kib,
            "event_count": event_count,
            "history_pages": page_count,
            "activity_retry_starts": types["ActivityStarted"] - expected_boundaries,
            "event_types": dict(types),
        }, sort_keys=True))


if __name__ == "__main__":
    asyncio.run(main(int(sys.argv[1])))
