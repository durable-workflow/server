"""Kill an installed Python caller, then recover its original durable call."""

from __future__ import annotations

import asyncio
import hashlib
import importlib.util
import json
import os
import signal
import subprocess
import sys
import time
import uuid
from importlib.metadata import version
from pathlib import Path

from durable_workflow import Client, Worker, serializer


def events(history, kind):
    return [event for event in history.get("events", []) if event.get("event_type") == kind]


async def run_worker(input_data, namespace):
    spec = importlib.util.spec_from_file_location(
        "nexus_recovery_application", Path(__file__).with_name("nexus-python-workflow-probe.py"),
    )
    application = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = application
    spec.loader.exec_module(application)
    print(json.dumps({"process_id": os.getpid(), "namespace": namespace,
                      "installed_sdk_version": version("durable-workflow")}), flush=True)
    stopping = asyncio.Event()
    loop = asyncio.get_running_loop()
    loop.add_signal_handler(signal.SIGTERM, stopping.set)
    async with Client("http://server:8080", namespace=namespace, worker_token=input_data["token"]) as client:
        worker = Worker(client, task_queue="nexus-python-workflow",
                        worker_id=f"nexus-python-recovery-{namespace}-{os.getpid()}",
                        workflows=[application.PythonNexusCaller], activities=[],
                        poll_timeout=1, shutdown_timeout=10)
        polling = asyncio.create_task(worker.run())
        stopped = asyncio.create_task(stopping.wait())
        try:
            done, _ = await asyncio.wait([polling, stopped], return_when=asyncio.FIRST_COMPLETED)
            if polling in done:
                await polling
        finally:
            await worker.stop()
            await polling
            stopped.cancel()
            await asyncio.gather(stopped, return_exceptions=True)
            loop.remove_signal_handler(signal.SIGTERM)


async def execute(input_data, namespace):
    external = input_data.get("scenario") == "external_payload"
    log_path = Path(f"/tmp/nexus-python-recovery-{namespace}-{os.getpid()}.log")
    row = {"caller_namespace": namespace, "target_namespace": "shared", "scenario": "caller_recovery",
           "installed_sdk_version": version("durable-workflow"), "budget_seconds": 30 if external else 60,
           "recovery_qualified": False}
    deadline = time.monotonic() + row["budget_seconds"]
    if external:
        row["scenario"] = "external_payload"
    with log_path.open("ab", buffering=0) as log:
        def start_worker():
            return subprocess.Popen([sys.executable, __file__, sys.argv[1], "worker", namespace],
                                    stdin=subprocess.DEVNULL, stdout=log, stderr=log)

        worker = start_worker()
        row["worker_process_id"] = worker.pid
        try:
            async with (
                Client("http://server:8080", namespace=namespace, token=input_data["token"]) as client,
                Client("http://server:8080", namespace="shared", token=input_data["token"]) as service_client,
            ):
                handle = await client.start_workflow(
                    workflow_type="nexus.fixture.python.caller", task_queue="nexus-python-workflow",
                    workflow_id=f"nexus-python-recovery-{namespace}-{uuid.uuid4().hex[:12]}",
                    input=[input_data["endpoint"], input_data["service"], input_data["operation"],
                           "external" if external else "recover", None if namespace == "shared" else "shared"],
                )
                row.update(workflow_id=handle.workflow_id, run_id=handle.run_id)
                ready_until = min(deadline, time.monotonic() + 20)
                while time.monotonic() < ready_until:
                    history = await handle.get_history()
                    starts = events(history, "ServiceCallStarted")
                    linked = starts[0]["payload"].get("service_call", {}) if starts else {}
                    linked_id, linked_run = linked.get("linked_workflow_instance_id"), linked.get("linked_workflow_run_id")
                    if linked_id and linked_run:
                        service_handle = service_client.get_workflow_handle(linked_id, run_id=linked_run)
                        service_history = await service_handle.get_history()
                        if len(starts) == 1 and len(events(service_history, "TimerScheduled")) == 1 \
                                and not events(service_history, "WorkflowCompleted") \
                                and not events(history, "ServiceCallCompleted"):
                            row.update(before_kill_history=history, before_kill_service_history=service_history,
                                       original_service_call=linked, original_service_call_id=starts[0]["payload"]["service_call_id"],
                                       linked_service_run_id=linked_run)
                            break
                    await asyncio.sleep(.1)
                if "original_service_call_id" not in row:
                    raise RuntimeError("The original call and waiting linked service were not observed before the kill.")
                worker.kill()
                exit_code = await asyncio.to_thread(worker.wait, timeout=2)
                row["killed_worker"] = {"pid": worker.pid, "running": worker.poll() is None,
                                        "signaled": exit_code < 0, "termsig": -exit_code if exit_code < 0 else 0}
                row["killed_worker_exit_code"] = exit_code
                if exit_code != -signal.SIGKILL:
                    raise RuntimeError("The original caller process was not confirmed dead from SIGKILL.")
                worker = None

                if "backend_resume_prefix" in input_data:
                    resume_path = Path(f"/work/{input_data['backend_resume_prefix']}-{namespace}.json")
                    print(json.dumps({"phase": "backend_interruption_ready", "caller_namespace": namespace,
                                      "resume_file": resume_path.name, "remaining_seconds": deadline - time.monotonic(),
                                      "timer_fire_at": events(service_history, "TimerScheduled")[0]["payload"]["fire_at"],
                                      "workflow_id": handle.workflow_id, "run_id": handle.run_id,
                                      "service_call_id": row["original_service_call_id"]}), flush=True)
                    while not resume_path.is_file() and time.monotonic() < deadline:
                        await asyncio.sleep(.1)
                    if not resume_path.is_file():
                        raise RuntimeError("Backend restoration exceeded the original recovery budget.")
                    row["backend_interruption"] = json.loads(resume_path.read_text())
                    if not row["backend_interruption"].get("restored"):
                        raise RuntimeError(f"The original database was not restored: {row['backend_interruption'].get('error')}")

                row["linked_service_result"] = await service_handle.result(timeout=max(1, min(20, deadline - time.monotonic())))
                row["linked_service_history"] = await service_handle.get_history()
                row["before_replacement_history"] = await handle.get_history()
                worker = start_worker()
                row["replacement_worker_process_id"] = worker.pid
                row["result"] = await handle.result(timeout=max(1, min(30, deadline - time.monotonic())))
                decoded = row["result"].get("result")
                row["decoded_service_result"] = serializer.decode(decoded, codec="avro") if isinstance(decoded, str) else decoded
                row["history"] = await handle.get_history()
                row["elapsed_seconds"] = row["budget_seconds"] - max(0, deadline - time.monotonic())
                row["recovery_qualified"] = row["elapsed_seconds"] < row["budget_seconds"] \
                    and row["result"].get("service_call_id") == row["original_service_call_id"] \
                    and len(events(row["history"], "ServiceCallStarted")) == 1 \
                    and len(events(row["history"], "ServiceCallCompleted")) == 1
        except Exception as failure:
            row["failure"] = {"type": type(failure).__name__, "message": str(failure)}
            row["last_observed_caller_event_types"] = [event.get("event_type") for event in locals().get("history", {}).get("events", [])]
            row["last_observed_service_event_types"] = [event.get("event_type") for event in locals().get("service_history", {}).get("events", [])]
        finally:
            if worker is not None and worker.poll() is None:
                worker.terminate()
                try:
                    row["replacement_worker_exit_code"] = await asyncio.to_thread(worker.wait, timeout=15)
                except subprocess.TimeoutExpired:
                    worker.kill()
                    row["replacement_worker_exit_code"] = await asyncio.to_thread(worker.wait, timeout=2)
            row["worker_log"] = log_path.read_text()
            if external:
                content = row["worker_log"]
                row["worker_log"] = {"bytes": len(content.encode()), "sha256": hashlib.sha256(content.encode()).hexdigest(),
                                     "head": content[:900], "tail": content[-900:]}
    return row


async def main():
    input_data = json.loads(Path(sys.argv[1]).read_text())
    if len(sys.argv) > 2 and sys.argv[2] == "worker":
        await run_worker(input_data, sys.argv[3])
        return
    observations = [await execute(input_data, namespace) for namespace in ["shared", "tenant-b"]]
    def compact(value):
        if isinstance(value, str) and len(value.encode()) > 2048:
            return {"omitted_bytes": len(value.encode()), "sha256": hashlib.sha256(value.encode()).hexdigest()}
        if isinstance(value, dict):
            return {key: compact(item) for key, item in value.items()}
        if isinstance(value, list):
            return [compact(item) for item in value]
        return value
    if input_data.get("scenario") == "external_payload":
        observations = compact(observations)
    print(json.dumps({"scope": "actual_python_caller_sigkill_recovery", "observations": observations,
                      "outcome": "pass" if all(row["recovery_qualified"] for row in observations) else "fail"}))


if __name__ == "__main__":
    asyncio.run(main())
