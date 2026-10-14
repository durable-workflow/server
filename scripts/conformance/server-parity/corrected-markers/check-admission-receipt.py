"""The killed-producer checkpoint must not race its committed response."""
import copy
import json
import sys
import tempfile
import unittest

from admission_receipt import admission_acknowledged

WORKER = "original:workflow:original"
TASK = {"id": "original-task", "lease_owner": WORKER, "attempt_count": 1}
WIRE = {"method": "POST", "path": "/api/worker/workflow-tasks/original-task/complete",
        "status": 200, "client_cancelled": False, "transport_error": None,
        "request_body_complete": True, "upstream_request_started": True,
        "request_body": json.dumps({"lease_owner": WORKER, "workflow_task_attempt": 1}),
        "response_body": json.dumps({"task_id": TASK["id"], "outcome": "completed", "recorded": True})}


class AdmissionReceiptTests(unittest.TestCase):
    def test_durable_history_alone_and_partial_response_do_not_authorize_the_kill(self):
        self.assertFalse(admission_acknowledged("", TASK, WORKER))
        self.assertFalse(admission_acknowledged(json.dumps(WIRE), TASK, WORKER))
        self.assertTrue(admission_acknowledged(json.dumps(WIRE) + "\n", TASK, WORKER))

    def test_only_the_original_forwarded_successful_completion_qualifies(self):
        for field, value in [("method", "DELETE"), ("path", "/api/worker/workflow-tasks/poll"),
                             ("status", 0), ("status", 409), ("client_cancelled", True),
                             ("transport_error", "ECONNRESET"), ("request_body_complete", False),
                             ("upstream_request_started", False)]:
            with self.subTest(field=field, value=value):
                changed = dict(WIRE, **{field: value})
                self.assertFalse(admission_acknowledged(json.dumps(changed) + "\n", TASK, WORKER))

    def test_other_owners_attempts_tasks_and_unrecorded_receipts_do_not_qualify(self):
        for body, field, value in [("request_body", "lease_owner", "foreign"),
                                   ("request_body", "workflow_task_attempt", 2),
                                   ("response_body", "task_id", "foreign"),
                                   ("response_body", "recorded", False),
                                   ("response_body", "outcome", "failed")]:
            with self.subTest(field=field, value=value):
                changed = copy.deepcopy(WIRE)
                content = json.loads(changed[body])
                content[field] = value
                changed[body] = json.dumps(content)
                self.assertFalse(admission_acknowledged(json.dumps(changed) + "\n", TASK, WORKER))
        self.assertFalse(admission_acknowledged(json.dumps(WIRE) + "\n",
                                               dict(TASK, lease_owner="foreign"), WORKER))

    def test_malformed_completed_lines_are_not_silently_ignored(self):
        with self.assertRaises(json.JSONDecodeError):
            admission_acknowledged("{broken}\n", TASK, WORKER)

    def test_large_journal_stream_requires_the_matching_complete_receipt(self):
        with tempfile.TemporaryFile(mode="w+t") as journal:
            unrelated = json.dumps({"method": "GET", "path": "/api/history", "body": "x" * 1024}) + "\n"
            for _ in range(4096):
                journal.write(unrelated)
            self.assertGreater(journal.tell(), 4 * 1024 * 1024)
            journal.seek(0)
            self.assertFalse(admission_acknowledged(journal, TASK, WORKER))
            journal.seek(0, 2)
            journal.write(json.dumps(WIRE))
            journal.seek(0)
            self.assertFalse(admission_acknowledged(journal, TASK, WORKER))
            journal.seek(0, 2)
            journal.write("\n")
            journal.seek(0)
            self.assertTrue(admission_acknowledged(journal, TASK, WORKER))


if __name__ == "__main__":
    result = unittest.main(argv=[sys.argv[0]], exit=False).result
    if not result.wasSuccessful():
        sys.exit(1)
    for path in sys.argv[1:]:
        with open(path) as source:
            observation = json.load(source)["observation"]
        original = observation["corrected_markers"]["phases"]["original"]
        scheduled = next(event for event in original["events"] if event["event_type"] == "ActivityScheduled")
        journal = "".join(json.dumps(wire) + "\n" for wire in observation["corrected_markers"]["requests"])
        assert not admission_acknowledged(journal, scheduled["payload"]["task"],
                                          observation["workflow_id"] + ":workflow:original")
        print("Actual committed-history/cancelled-response checkpoint correctly remains unqualified")
