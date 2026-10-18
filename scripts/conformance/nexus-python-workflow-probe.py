"""Execute installed Python Nexus authoring against an actual PHP SDK worker."""

from __future__ import annotations

import asyncio
import contextlib
import json
import os
import sys
import time
from importlib.metadata import version

from durable_workflow import Client, Worker, WorkflowCancelled, serializer

from nexus_python_caller_workflow import PythonNexusCaller


async def execute(input_data, namespace):
    started_at = time.monotonic()
    observation = None
    receipts = []
    worker_requests = []
    request_rows = {}
    empty_activity_polls = 0
    rejected = asyncio.Event()

    async def submitting(request):
        if "/worker/" not in request.url.path:
            return
        body = json.loads(request.content) if request.content else {}
        row = {"method": request.method, "path": request.url.path, "status": None,
               "started_at": time.time(), "request_id": body.get("poll_request_id")}
        if request.url.path.endswith("/register"):
            row["workflow_definition_fingerprints"] = body.get("workflow_definition_fingerprints")
        request_rows[id(request)] = row
        worker_requests.append(row)

    async def observe(response):
        nonlocal empty_activity_polls
        path = response.request.url.path
        if "/worker/" in path:
            await response.aread()
            try:
                body = response.json()
            except ValueError:
                body = {}
            row = request_rows.pop(id(response.request), None)
            if row is None:
                row = {"method": response.request.method, "path": path}
                worker_requests.append(row)
            row.update(status=response.status_code, finished_at=time.time(), reason=body.get("reason"),
                       poll_status=body.get("poll_status"), retry_after_seconds=body.get("retry_after_seconds"),
                       task_id=(body.get("task") or {}).get("id"))
            if row["poll_status"] == "no_activity_capability":
                empty_activity_polls += 1
                if empty_activity_polls > 1:
                    worker_requests.remove(row)
        if "/service-endpoints/" not in path and not (
            "/workflow-tasks/" in path and path.endswith("/complete")
        ):
            return
        await response.aread()
        receipts.append({
            "method": response.request.method,
            "path": response.request.url.path,
            "request_namespace": response.request.headers.get("X-Namespace"),
            "request": json.loads(response.request.content) if response.request.content else None,
            "status": response.status_code,
            "response": response.json(),
        })
        if response.status_code >= 400 and "/service-endpoints/" in path:
            rejected.set()

    async with Client("http://server:8080", namespace=namespace,
                      token=input_data["token"], worker_token=input_data["token"]) as client:
        client._http.event_hooks["response"] = [observe]
        client._http.event_hooks["request"] = [submitting]
        worker = Worker(client, task_queue="nexus-python-workflow",
                        worker_id=f"nexus-python-caller-{namespace}-{os.getpid()}",
                        workflows=[PythonNexusCaller], activities=[],
                        **({"capabilities": ["cooperative_cancellation"]}
                           if input_data.get("scenario") == "caller_cancelled" else {}))
        polling = asyncio.create_task(worker.run())
        try:
            handle = await client.start_workflow(
                workflow_type="nexus.fixture.python.caller",
                task_queue="nexus-python-workflow",
                workflow_id=f"nexus-python-caller-{namespace}-{input_data.get('scenario', 'completed')}-{os.getpid()}",
                input=[input_data["endpoint"], input_data["service"],
                       input_data["operation"], input_data.get("name", "world"), "shared" if namespace != "shared" else None],
            )
            observation = {
                "caller_namespace": namespace, "target_namespace": "shared",
                "scenario": input_data.get("scenario", "completed"),
                "workflow_id": handle.workflow_id, "run_id": handle.run_id,
                "process_id": os.getpid(), "installed_sdk_version": version("durable-workflow"),
                "workflow_definition_fingerprint": worker.workflow_definition_fingerprints["nexus.fixture.python.caller"],
                "public_method": "WorkflowContext.call_nexus_service",
            }
            if input_data.get("scenario") in ("cancelled", "caller_cancelled"):
                async with Client("http://server:8080", namespace="shared", token=input_data["token"]) as service_client:
                    async def cancel_waiting_service():
                        while True:
                            history = await handle.get_history()
                            started = [event["payload"] for event in history.get("events", [])
                                       if event.get("event_type") == "ServiceCallStarted"]
                            linked = started[0].get("service_call", {}) if started else {}
                            linked_id, linked_run = linked.get("linked_workflow_instance_id"), linked.get("linked_workflow_run_id")
                            if linked_id and linked_run:
                                service_handle = service_client.get_workflow_handle(linked_id, run_id=linked_run)
                                service_history = await service_handle.get_history()
                                if any(event.get("event_type") == "TimerScheduled" for event in service_history.get("events", [])):
                                    observation["service_call_before_cancellation"] = linked
                                    if input_data.get("scenario") == "caller_cancelled":
                                        return [await client.request_workflow_cancellation(
                                            handle.workflow_id, run_id=handle.run_id,
                                            reason="fixture waiting caller cancellation", cleanup_timeout_seconds=30),
                                            await client.request_workflow_cancellation(
                                            handle.workflow_id, run_id=handle.run_id,
                                            reason="duplicate must not replace reason", cleanup_timeout_seconds=1)]
                                    return [await service_client.request_workflow_cancellation(
                                        linked_id, run_id=linked_run, reason="fixture service cancellation", cleanup_timeout_seconds=30),
                                        await service_client.request_workflow_cancellation(
                                        linked_id, run_id=linked_run, reason="duplicate must not replace reason", cleanup_timeout_seconds=1)]
                            await asyncio.sleep(.1)
                    requests = await asyncio.wait_for(cancel_waiting_service(), timeout=20)
                    observation["caller_cancellation_requests" if input_data.get("scenario") == "caller_cancelled"
                                else "linked_cancellation_requests"] = requests
            finishing = asyncio.create_task(handle.result(timeout=30))
            refusal = asyncio.create_task(rejected.wait())
            try:
                done, _ = await asyncio.wait([finishing, refusal], return_when=asyncio.FIRST_COMPLETED)
                if finishing in done:
                    observation["result"] = await finishing
                else:
                    observation["failure"] = {
                        "type": "NexusAdmissionRefused",
                        "message": receipts[-1]["response"].get("reason"),
                    }
            except (Exception, WorkflowCancelled) as error:
                observation["failure"] = {"type": type(error).__name__, "message": str(error)}
            finally:
                for pending in [finishing, refusal]:
                    pending.cancel()
                await asyncio.gather(finishing, refusal, return_exceptions=True)
            observation["history"] = await handle.get_history()
            observation["receipts"] = receipts
            observation["worker_requests"] = worker_requests
            observation["empty_activity_polls"] = empty_activity_polls
            observation["polling_stopped"] = polling.done()
            observation["poller_tasks"] = []
            for task in worker._poller_tasks:
                frames = []
                coroutine = task.get_coro()
                while coroutine is not None:
                    frame = getattr(coroutine, "cr_frame", None)
                    if frame is not None:
                        frames.append({"file": os.path.basename(frame.f_code.co_filename),
                                       "function": frame.f_code.co_name, "line": frame.f_lineno})
                    coroutine = getattr(coroutine, "cr_await", None)
                observation["poller_tasks"].append({"done": task.done(), "cancelled": task.cancelled(),
                    "error": str(task.exception()) if task.done() and not task.cancelled() else None, "frames": frames})
            commands = [command for row in receipts
                        if row["method"] == "POST" and row["path"].endswith("/complete")
                        and 200 <= row["status"] < 300 and row["request_namespace"] == namespace
                        for command in row["request"].get("commands", [])
                        if command.get("type") == "start_service_operation"]
            events = observation["history"].get("events", [])
            admissions = [event["payload"] for event in events
                          if event.get("event_type", event.get("type")) == "ServiceCallStarted"]
            observation["request_qualified"] = any(
                command.get("endpoint_name") == input_data["endpoint"]
                and command.get("service_name") == input_data["service"]
                and command.get("operation_name") == input_data["operation"]
                and command.get("metadata", {}).get("caller_sdk_language") == "sdk-python"
                and command.get("namespace", namespace) == "shared"
                and command.get("payload_codec") == "avro"
                and isinstance(command.get("request_payload"), str)
                and serializer.decode(command["request_payload"], codec="avro") == [input_data.get("name", "world")]
                for command in commands
            ) and any(
                event.get("caller_workflow_instance_id") == handle.workflow_id
                and event.get("caller_workflow_run_id") == handle.run_id
                and event.get("caller_sdk_language") == "sdk-python"
                for event in admissions
            )
            recorded = observation.get("result", {})
            surface = recorded.get("response_or_failure_surface", {}) or (admissions[0].get("service_call", {}) if admissions else {})
            surface = surface.get("service_call", surface)
            linked_id = surface.get("linked_workflow_instance_id")
            linked_run_id = surface.get("linked_workflow_run_id")
            if linked_id and linked_run_id:
                async with Client("http://server:8080", namespace="shared", token=input_data["token"]) as service_client:
                    service_handle = service_client.get_workflow_handle(linked_id, run_id=linked_run_id)
                    try:
                        observation["linked_service_result"] = await service_handle.result(timeout=10)
                    except (Exception, WorkflowCancelled) as error:
                        observation["linked_service_failure"] = {"type": type(error).__name__, "message": str(error)}
                    observation["linked_service_history"] = await service_handle.get_history()
                observation["linked_service_run_id"] = linked_run_id
            expected_name = input_data.get("name", "world")
            expected_result = {"language": input_data.get("service_language", "php"),
                               "name": expected_name, "greeting": f"hello, {expected_name}"}
            observation["service_input_qualified"] = observation.get("linked_service_result") == expected_result
            service_result = recorded.get("result")
            if isinstance(service_result, str):
                service_result = serializer.decode(service_result, codec="avro")
            observation["decoded_service_result"] = service_result
            observation["completed"] = recorded.get("status") == "completed" and service_result == expected_result
            observation["typed_failure_qualified"] = (
                recorded.get("status") == "failed"
                and recorded.get("failure_class") == "NexusOperationFailed"
                and recorded.get("failure_message") == "fixture service failure"
            )
            observation["cancellation_qualified"] = (
                recorded.get("status") == "cancelled"
                and recorded.get("failure_class") == "NexusOperationFailed"
            )
            observation["authorization_qualified"] = (
                input_data.get("scenario") == "permission_denied"
                and not admissions
                and recorded.get("failure_class") == "NexusOperationFailed"
                and len([event for event in events if event.get("event_type") == "ServiceCallFailed"
                         and event.get("payload", {}).get("outcome") == "rejected_forbidden"]) == 1
            )
            if input_data.get("scenario") == "caller_cancelled":
                observation["caller_status"] = (await client.describe_workflow_run(handle.workflow_id, handle.run_id)).status
                observation["caller_cancellation_qualified"] = (
                    observation["caller_status"].lower() == "cancelled"
                    and sum(event.get("event_type") == "WorkflowCancelled"
                            for event in observation.get("linked_service_history", {}).get("events", [])) == 1
                )
            observation["deadline_qualified"] = (
                recorded.get("status") == "failed"
                and recorded.get("failure_class") == "NexusOperationFailed"
                and surface.get("outcome") == "timed_out"
            )
            if observation["deadline_qualified"]:
                async with Client("http://server:8080", namespace="shared", token=input_data["token"]) as replay_client:
                    replay_client._http.event_hooks["response"] = [observe]
                    duplicate = await replay_client.execute_nexus_operation(
                        input_data["endpoint"], input_data["service"], input_data["operation"],
                        [input_data.get("name", "world")], caller_namespace=namespace,
                        idempotency_key=commands[0]["idempotency_key"],
                        caller_workflow_instance_id=handle.workflow_id,
                        caller_workflow_run_id=handle.run_id,
                        wait_for="completed", wait_timeout_seconds=5, raise_on_failure=False,
                    )
                observation["duplicate_result"] = duplicate.to_recorded_payload()
            return observation
        finally:
            await asyncio.wait_for(worker.stop(), timeout=10)
            polling.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await polling
            if input_data.get("scenario") == "permission_denied" and observation is not None:
                observation.update(budget_seconds=30, elapsed_seconds=time.monotonic() - started_at,
                                   worker_stopped=True)


async def main():
    with open(sys.argv[1], encoding="utf-8") as source:
        input_data = json.load(source)
    if input_data.get("scenario") == "caller_cancelled":
        os.environ["DURABLE_WORKFLOW_WORKER_PROTOCOL_VERSION"] = "1.20"
    observations = []
    for namespace in ["shared", "tenant-b"]:
        observations.append(await execute(input_data, namespace))
    print(json.dumps({
        "scope": f"python_caller_{input_data.get('service_language', 'php')}_workflow_service",
        "outcome": "pass" if all(row[{"failed": "typed_failure_qualified", "cancelled": "cancellation_qualified", "deadline": "deadline_qualified",
                                     "caller_cancelled": "caller_cancellation_qualified",
                                     "permission_denied": "authorization_qualified"}
                                     .get(input_data.get("scenario"), "completed")] for row in observations) else "fail",
        "observations": observations,
        "recovery_qualified": False,
        "cancellation_qualified": False,
    }))


if __name__ == "__main__":
    asyncio.run(main())
