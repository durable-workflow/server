import json
import os

import httpx

task_id = os.environ["QUALIFICATION_TASK_ID"]
url = os.environ["SERVER_URL"].rstrip("/") + f"/api/worker/workflow-tasks/{task_id}/complete"
response = httpx.post(
    url,
    headers={
        "Authorization": "Bearer " + os.environ["DRILL_WORKER_TOKEN"],
        "X-Namespace": "default",
        "X-Durable-Workflow-Protocol-Version": "1.19",
    },
    json={
        "lease_owner": "qualification-retry",
        "workflow_task_attempt": 1,
        "commands": [
            {"type": "record_side_effect", "result": index} for index in range(500)
        ],
    },
    timeout=30,
)
payload = response.json()
print(json.dumps({"status": response.status_code, "reason": payload.get("reason")}))
if response.status_code == 200:
    raise AssertionError("completed task accepted a duplicate completion")
