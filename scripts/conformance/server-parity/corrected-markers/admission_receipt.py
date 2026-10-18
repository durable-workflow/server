"""Observe this fixture's original admission before killing its producer."""
import io
import json


def admission_acknowledged(journal, task, worker_id):
    if task.get("lease_owner") != worker_id:
        return False
    path = "/api/worker/workflow-tasks/" + task["id"] + "/complete"
    # Consume the growing observer journal one line at a time. Catalog and
    # history responses can legitimately make the whole file several MiB.
    lines = io.StringIO(journal) if isinstance(journal, str) else journal
    for line in lines:
        # The observer may be appending its last line during this read.
        if not line.endswith("\n"):
            continue
        wire = json.loads(line)
        if (wire.get("method") != "POST" or wire.get("path") != path
                or wire.get("status") != 200 or wire.get("client_cancelled") is not False
                or wire.get("transport_error") is not None
                or wire.get("request_body_complete") is not True
                or wire.get("upstream_request_started") is not True):
            continue
        request = json.loads(wire["request_body"])
        response = json.loads(wire["response_body"])
        if (request.get("lease_owner") == worker_id
                and request.get("workflow_task_attempt") == task["attempt_count"]
                and response.get("task_id") == task["id"]
                and response.get("outcome") == "completed"
                and response.get("recorded") is True):
            return True
    return False
