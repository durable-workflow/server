#!/usr/bin/env python3
"""Write real 3.8 API receipts for the separately qualified legacy upgrade cell.

Run with the published Python SDK installed for its official Avro codec. This
does not run an SDK Worker or transform database rows. Stop the disposable
baseline Server and back up its database before the manifest's upgrade step.
Published 3.8 retains ordinary update keys; its ordinary signal rows have no
stored caller key and are controls, not physical legacy-key readback proof.
"""

import argparse
import hashlib
import importlib.metadata
import json
from pathlib import Path
import re
from urllib.error import HTTPError
from urllib.parse import urlparse
from urllib.request import Request, urlopen


class SeedClient:
    def __init__(self, url):
        parsed = urlparse(url)
        if parsed.scheme != "http" or parsed.hostname != "127.0.0.1" or not parsed.port or parsed.path:
            raise ValueError("seed URL must be an explicit disposable http://127.0.0.1:port")
        self.url = url
        self.protocol = None

    def request(self, method, path, body=None, expected=200):
        headers = {"Authorization": "Bearer test-token", "X-Namespace": "default",
                   "Accept": "application/json", "Content-Type": "application/json"}
        if path.startswith("/api/worker/"):
            headers["X-Durable-Workflow-Protocol-Version"] = self.protocol
        else:
            headers["X-Durable-Workflow-Control-Plane-Version"] = "2"
        data = None if body is None else json.dumps(body).encode()
        try:
            response = urlopen(Request(self.url + path, data=data, headers=headers, method=method), timeout=30)
        except HTTPError as error:
            response = error
        with response:
            raw = response.read(1_048_577)
            if len(raw) > 1_048_576:
                raise ValueError("seed response exceeds 1 MiB")
            value = json.loads(raw)
            if response.status != expected:
                raise ValueError(f"{method} {path}: expected {expected}, got {response.status}: {value}")
            return value

    def poll(self, worker, queue):
        value = self.request("POST", "/api/worker/workflow-tasks/poll",
                             {"worker_id": worker, "task_queue": queue, "timeout_seconds": 0})
        task = value.get("task")
        if not isinstance(task, dict) or not task.get("task_id"):
            raise ValueError("seed expected a real leased workflow task")
        return task

    def finish(self, task, commands):
        return self.request("POST", f"/api/worker/workflow-tasks/{task['task_id']}/complete",
                            {"lease_owner": task["lease_owner"],
                             "workflow_task_attempt": task["workflow_task_attempt"], "commands": commands})


def seed(client, prefix, manifest):
    info = client.request("GET", "/api/cluster/info")
    if info.get("version") != manifest["physical_upgrade"]["baseline"]["tag"]:
        raise ValueError("physical seed must run on the manifest's published baseline version")
    client.protocol = info["worker_protocol"]["version"]
    # Import only for the codec. All seed admissions below use raw producer APIs.
    from durable_workflow import serializer

    arguments = serializer.envelope([manifest["legacy_readback"]["original_input"]], codec="avro")
    null = serializer.envelope(None, codec="avro")
    for value, envelope in [([1], arguments), (None, null)]:
        if serializer.decode_envelope(envelope, codec="avro") != value:
            raise ValueError("official codec round-trip failed")
    parameters = [{"name": "value", "type": "int", "required": True, "variadic": False,
                   "default_available": False, "allows_null": False}]
    cases = []
    for kind in manifest["legacy_readback"]["types"]:
        for state in ["open", "completed", "continued"]:
            workflow = f"{prefix}{kind}-{state}"
            worker, queue = workflow + "-worker", workflow + "-queue"
            client.request("POST", "/api/worker/register", {
                "worker_id": worker, "task_queue": queue, "runtime": "php",
                "supported_workflow_types": ["echo"], "supported_activity_types": [],
                "capabilities": ["workflow_updates"],
                "capability_manifest": {name: {"supported": False, "minimum_protocol_version": "1.18"}
                                        for name in ["local_activities", "worker_sessions", "sticky_execution"]},
                "workflow_command_contracts": {"echo": {
                    "signals": ["finish"], "signal_contracts": [{"name": "finish", "parameters": parameters}],
                    "updates": ["set"], "update_validators": [],
                    "update_contracts": [{"name": "set", "parameters": parameters}]}}}, expected=201)
            started = client.request("POST", "/api/workflows", {
                "workflow_id": workflow, "workflow_type": "echo", "task_queue": queue,
                "input": arguments}, expected=201)
            task = client.poll(worker, queue)
            if task["run_id"] != started["run_id"]:
                raise ValueError("seed claimed another workflow's task")
            client.finish(task, [{"type": "open_signal_wait", "signal_name": "finish"}])
            name = "finish" if kind == "signal" else "set"
            path = f"/api/workflows/{workflow}/{kind}/{name}"
            body = {"input": arguments, "request_id": manifest["keys"]["original"]}
            if kind == "update":
                body["wait_for"] = "accepted"
            receipt = client.request("POST", path, body, expected=202)
            if state != "open":
                task = client.poll(worker, queue)
                if task["run_id"] != started["run_id"]:
                    raise ValueError("seed close claimed another workflow's task")
                commands = []
                if kind == "update":
                    commands.append({"type": "complete_update", "update_id": receipt["update_id"], "result": null})
                commands.append({"type": "complete_workflow" if state == "completed" else "continue_as_new",
                                 "result": null})
                client.finish(task, commands)
            history = client.request("GET", f"/api/workflows/{workflow}/runs/{started['run_id']}/history")
            cases.append({"kind": kind, "state": state, "workflow_id": workflow,
                          "path": path, "original_body": body, "receipt": receipt, "history": history})
    return {"schema": "durable-workflow.command-key-physical-seed.v1",
            "scope": "Real baseline API admissions; not a synthetic transform, upgrade result or SDK Worker proof.",
            "baseline": manifest["physical_upgrade"]["baseline"], "advertised_protocol": client.protocol,
            "codec_package": {"name": "durable-workflow", "version": importlib.metadata.version("durable-workflow")},
            "cases": cases}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", required=True)
    parser.add_argument("--prefix", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if not re.fullmatch(r"[A-Za-z0-9-]{1,32}", args.prefix):
        parser.error("prefix must contain 1–32 ASCII letters, digits or hyphens")
    if args.output.exists():
        parser.error("output must be a new file")
    root = Path(__file__).resolve().parents[2]
    raw = (root / "resources/platform-protocol-specs/command-key-legacy-v1-vectors.json").read_bytes()
    result = seed(SeedClient(args.url), args.prefix, json.loads(raw))
    result["manifest_sha256"] = hashlib.sha256(raw).hexdigest()
    with args.output.open("x") as output:
        output.write(json.dumps(result, indent=2) + "\n")
    print(f"Seeded {len(result['cases'])} real baseline receipts. Stop and back up before upgrading.")


if __name__ == "__main__":
    main()
