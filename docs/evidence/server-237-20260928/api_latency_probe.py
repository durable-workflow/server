"""Sample ordinary authenticated Server API response time during mixed work."""

import json
import math
import os
import time

import httpx


def percentile(values, fraction):
    ordered = sorted(values)
    return ordered[math.ceil(len(ordered) * fraction) - 1]


base_url = os.environ["SERVER_URL"].rstrip("/")
url = base_url + ("/cluster/info" if base_url.endswith("/api") else "/api/cluster/info")
latencies = []
statuses = {}
with httpx.Client(timeout=10) as client:
    for _ in range(10):
        started = time.perf_counter()
        response = client.get(
            url,
            headers={"Authorization": "Bearer " + os.environ["DRILL_CONTROL_TOKEN"]},
        )
        latencies.append(time.perf_counter() - started)
        statuses[str(response.status_code)] = statuses.get(str(response.status_code), 0) + 1
        time.sleep(0.2)

print(json.dumps({
    "endpoint": "/api/cluster/info",
    "samples": len(latencies),
    "statuses": statuses,
    "p50_seconds": percentile(latencies, 0.50),
    "p95_seconds": percentile(latencies, 0.95),
    "p99_seconds": percentile(latencies, 0.99),
}, sort_keys=True))
