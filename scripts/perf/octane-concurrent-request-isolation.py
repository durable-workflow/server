"""Bounded concurrent auth and namespace isolation probe for disposable #137 stacks."""

import concurrent.futures
import json
import os
import random
import secrets
import time
import urllib.error
import urllib.request


BASE = os.environ.get("DW_PROBE_URL", "").rstrip("/")
ADMIN = os.environ.get("DW_PROBE_TOKEN", "")
WORKER = os.environ.get("DW_PROBE_WORKER_TOKEN", "")
if not BASE or not ADMIN or not WORKER:
    raise SystemExit("DW_PROBE_URL, DW_PROBE_TOKEN, and DW_PROBE_WORKER_TOKEN are required")


def request(token, method, path, namespace=None, body=None):
    headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "Authorization": f"Bearer {token}",
        "X-Durable-Workflow-Control-Plane-Version": "2",
    }
    if namespace is not None:
        headers["X-Namespace"] = namespace
    payload = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(BASE + path, data=payload, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=15) as response:
            status, raw = response.status, response.read()
    except urllib.error.HTTPError as error:
        status, raw = error.code, error.read()
    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError as error:
        raise RuntimeError(f"{method} {path}: invalid JSON at HTTP {status}") from error
    if not isinstance(decoded, dict):
        raise RuntimeError(f"{method} {path}: response is not a JSON object")
    return status, decoded


suffix = secrets.token_hex(5)
alpha, beta = "probe-alpha-" + suffix, "probe-beta-" + suffix
alpha_id, beta_id = "probe-alpha-workflow-" + suffix, "probe-beta-workflow-" + suffix
for namespace in (alpha, beta):
    status, _ = request(ADMIN, "POST", "/api/namespaces", body={"name": namespace})
    if status != 201:
        raise RuntimeError(f"create {namespace}: HTTP {status}")
for namespace, workflow_id in ((alpha, alpha_id), (beta, beta_id)):
    status, response = request(
        ADMIN,
        "POST",
        "/api/workflows",
        namespace,
        {
            "workflow_id": workflow_id,
            "workflow_type": "probe.concurrent-isolation",
            "task_queue": "probe-isolation",
            "input": [],
        },
    )
    if status != 201 or response.get("namespace") != namespace:
        raise RuntimeError(f"start {namespace}: HTTP {status}, wrong namespace")


def check(case):
    label, token, namespace, workflow_id, expected_status, expected_namespace = case
    started = time.monotonic()
    status, response = request(token, "GET", "/api/workflows/" + workflow_id, namespace)
    if status != expected_status:
        raise RuntimeError(f"{label}: expected HTTP {expected_status}, received {status}")
    if expected_namespace is not None and response.get("namespace") != expected_namespace:
        raise RuntimeError(f"{label}: response escaped requested namespace")
    return time.monotonic() - started


cases = [
    ("alpha own", ADMIN, alpha, alpha_id, 200, alpha),
    ("beta own", ADMIN, beta, beta_id, 200, beta),
    ("alpha cross", ADMIN, beta, alpha_id, 404, None),
    ("beta cross", ADMIN, alpha, beta_id, 404, None),
    ("alpha invalid", "invalid-probe-token", alpha, alpha_id, 401, None),
    ("beta invalid", "invalid-probe-token", beta, beta_id, 401, None),
    ("alpha worker", WORKER, alpha, alpha_id, 403, None),
    ("beta worker", WORKER, beta, beta_id, 403, None),
] * 2

durations = []
with concurrent.futures.ThreadPoolExecutor(max_workers=16) as pool:
    for round_number in range(40):
        shuffled = list(cases)
        random.shuffle(shuffled)
        try:
            durations.extend(pool.map(check, shuffled))
        except Exception as error:
            raise RuntimeError(f"concurrent round {round_number + 1}: {error}") from error

for label, namespace, workflow_id in (("alpha final", alpha, alpha_id), ("beta final", beta, beta_id)):
    status, response = request(ADMIN, "GET", "/api/workflows/" + workflow_id, namespace)
    if status != 200 or response.get("namespace") != namespace:
        raise RuntimeError(f"{label}: isolation lost after concurrent rounds")

durations.sort()
print(json.dumps({
    "probe": "concurrent-request-isolation",
    "namespaces": 2,
    "created_workflows": 2,
    "server_workers": 4,
    "max_requests_per_worker": 50,
    "rounds": 40,
    "max_concurrent_clients": 16,
    "checked_requests": len(durations),
    "p95_response_seconds": round(durations[int(len(durations) * 0.95)], 3),
    "result": "pass",
}, sort_keys=True))
