"""Offer a bounded set of numbered signals to the published PHP worker probe."""

import asyncio
import json
import os
import time

from durable_workflow import Client


def percentile(samples, fraction):
    ordered = sorted(samples)
    return ordered[min(len(ordered) - 1, int((len(ordered) - 1) * fraction))]


async def main():
    workflow_id = os.environ["PROBE_WORKFLOW_ID"]
    target = int(os.environ.get("PROBE_TARGET", "4000"))
    fanout = int(os.environ.get("PROBE_SIGNAL_CONCURRENCY", "8"))
    if target < 1 or target > 6000 or fanout < 1 or fanout > 16:
        raise ValueError("target or fanout exceeds the bounded probe limits")

    latencies = []
    async with Client(
        os.environ["PROBE_RUNTIME_URL"],
        control_token=os.environ["PROBE_CONTROL_TOKEN"],
        namespace="default",
    ) as client:
        async def offer(index):
            started = time.monotonic()
            await client.signal_workflow(workflow_id, "append", args=[index])
            latencies.append(time.monotonic() - started)

        started = time.monotonic()
        for first in range(0, target, fanout):
            await asyncio.gather(
                *(offer(index) for index in range(first, min(first + fanout, target)))
            )
            if len(latencies) % 1000 < fanout or len(latencies) == target:
                print(json.dumps({"phase": "offered", "signals": len(latencies)}), flush=True)
        print(json.dumps({
            "phase": "offered_all",
            "offered_signals": len(latencies),
            "offer_seconds": time.monotonic() - started,
            "signal_api_p50_seconds": percentile(latencies, 0.50),
            "signal_api_p95_seconds": percentile(latencies, 0.95),
            "signal_api_p99_seconds": percentile(latencies, 0.99),
        }, sort_keys=True), flush=True)


if __name__ == "__main__":
    asyncio.run(main())
