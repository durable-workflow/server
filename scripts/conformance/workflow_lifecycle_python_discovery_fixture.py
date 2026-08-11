#!/usr/bin/env python3
"""Synthetic Server discovery response used by the Python lifecycle probe."""

from __future__ import annotations

import json
import sys
from typing import Any


RUNTIME_DISCOVERY_PATH = "/api/cluster/info"
QUERY_TASKS_CAPABILITY_PATH = "worker_protocol.server_capabilities.query_tasks"


def response_for(method: str, path: str) -> tuple[int, dict[str, Any]] | None:
    """Return the public Server response needed by capability-gated SDK calls."""

    if method == "GET" and path == RUNTIME_DISCOVERY_PATH:
        return (
            200,
            {
                "worker_protocol": {
                    "server_capabilities": {
                        "query_tasks": True,
                    },
                },
            },
        )

    return None


def self_test() -> None:
    discovery = response_for("GET", RUNTIME_DISCOVERY_PATH)
    if discovery is None:
        raise AssertionError("runtime discovery request was not handled")

    status, payload = discovery
    query_tasks = (
        payload.get("worker_protocol", {})
        .get("server_capabilities", {})
        .get("query_tasks")
    )
    if status != 200 or query_tasks is not True:
        raise AssertionError(
            "runtime discovery must advertise "
            f"{QUERY_TASKS_CAPABILITY_PATH}=true with HTTP 200"
        )

    print(
        json.dumps(
            {
                "method": "GET",
                "path": RUNTIME_DISCOVERY_PATH,
                "status": status,
                "capability_path": QUERY_TASKS_CAPABILITY_PATH,
                "capability_value": query_tasks,
            },
            sort_keys=True,
        )
    )


if __name__ == "__main__":
    if sys.argv[1:] != ["--self-test"]:
        raise SystemExit("usage: workflow_lifecycle_python_discovery_fixture.py --self-test")
    self_test()
