"""Exercise a published PHP Server's retention and projection HTTP surfaces."""

from __future__ import annotations

import asyncio
import json
import os
import sys
from pathlib import Path
from typing import Any
from uuid import uuid4

import httpx
from durable_workflow import Client, Worker, workflow


@workflow.defn(name="qualification.retention-projection")
class RetentionWorkflow:
    def run(self, ctx: Any, value: Any) -> Any:
        yield from ()
        return value


async def main() -> None:
    phase, state_file = sys.argv[1:]
    state_path = Path(state_file)
    url = os.environ["DURABLE_WORKFLOW_SERVER_URL"].rstrip("/")
    token = os.environ["DURABLE_WORKFLOW_AUTH_TOKEN"]
    headers = {
        "Authorization": f"Bearer {token}",
        "X-Namespace": "default",
        "X-Durable-Workflow-Control-Plane-Version": "2",
    }
    async with asyncio.timeout(30), Client(url, token=token, namespace="default") as client:
        async with httpx.AsyncClient(base_url=url, headers=headers, timeout=5) as control:
            if phase == "admit-prune":
                identity = f"retention-{uuid4().hex}"
                value = {"integer": 9223372036854775807, "null": None, "text": "retained 雪"}
                handle = await client.start_workflow(
                    workflow_type="qualification.retention-projection", task_queue=identity,
                    workflow_id=identity, input=[value],
                    execution_timeout_seconds=120, run_timeout_seconds=60,
                )
                worker = Worker(
                    client, task_queue=identity, workflows=[RetentionWorkflow], activities=[],
                    poll_timeout=0.2, shutdown_timeout=5,
                )
                await worker.run_until(workflow_id=identity, timeout=20, poll_interval=0.1)
                assert await handle.result(timeout=5) == value
                before = await handle.describe_run()
                assert before.status.lower() == "completed"
                listing = await client.list_workflows(workflow_type="qualification.retention-projection")
                assert any(item.run_id == handle.run_id for item in listing.executions)
                state = {"workflow_id": identity, "run_id": handle.run_id}
                state_path.write_text(json.dumps(state), encoding="utf-8")
                response = await control.post("/api/system/retention/pass", json={"run_ids": [handle.run_id]})
                response.raise_for_status()
                pruned = response.json()
                assert pruned["processed"] == 1
                assert pruned["pruned"] == 1
                assert pruned["failed"] == 0
            else:
                assert phase == "verify"
                state = json.loads(state_path.read_text(encoding="utf-8"))

            health_response = await control.get("/api/system/health")
            health_response.raise_for_status()
            health = health_response.json()["health"]
            projection = next(check for check in health["checks"] if check["name"] == "run_summary_projection")
            metrics_response = await control.get("/api/system/metrics")
            metrics_response.raise_for_status()
            metric = metrics_response.json()["metrics"]["dw_projection_drift_total"]
            run_metric = next(row for row in metric["by_table"] if row["table"] == "run_summaries")
            operator_response = await control.get("/api/system/operator-metrics")
            operator_response.raise_for_status()
            lag = operator_response.json()["operator_metrics"]["projections"]["run_summaries"]
            print(json.dumps({"phase": phase, **state, "projection": projection,
                              "run_summary_metric": run_metric, "lag": lag}), flush=True)
            assert projection["status"] == "ok"
            assert run_metric["needs_rebuild"] == 0
            assert lag["missing"] == 0
            assert lag["needs_rebuild"] == 0
            assert lag["oldest_missing_run_started_at"] is None
            assert lag["max_missing_run_age_ms"] == 0
            listing = await client.list_workflows(workflow_type="qualification.retention-projection")
            assert all(item.run_id != state["run_id"] for item in listing.executions)
            for _ in range(2):
                response = await control.post("/api/system/retention/pass", json={})
                response.raise_for_status()
                assert response.json()["processed"] == 0
                assert response.json()["failed"] == 0


asyncio.run(main())
