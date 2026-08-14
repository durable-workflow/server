from __future__ import annotations

import os

import httpx

from . import serializer


__version__ = "0.4.91"


class RuntimeDiscoveryUnavailable(Exception):
    pass


class InvalidArgument(Exception):
    pass


class WorkflowAlreadyStarted(Exception):
    pass


class WorkflowCancelled(BaseException):
    pass


class WorkflowTerminated(Exception):
    pass


class ContinueAsNew:
    pass


class StartChildWorkflow:
    pass


class ChildWorkflowRetryPolicy:
    pass


class WorkflowHandle:
    def __init__(self, client: "Client", workflow_id: str, run_id: str) -> None:
        self.client = client
        self.workflow_id = workflow_id
        self.run_id = run_id

    async def signal(self, name: str, arguments: list[object]) -> None:
        await self.client._request(
            "POST",
            f"/workflows/{self.workflow_id}/signal/{name}",
            json={"input": serializer.encode(arguments)},
        )

    async def query(self, name: str) -> object:
        mode = os.environ.get("PYTHON_DISCOVERY_REJECTION_MODE", "unserved")
        if mode == "valid_response":
            discovery = await self.client._request("GET", "/cluster/info")
            query_tasks = (
                discovery.get("worker_protocol", {})
                .get("server_capabilities", {})
                .get("query_tasks")
            )
            if query_tasks is not True:
                raise AssertionError("the harness did not return query_tasks=true")

        raise RuntimeDiscoveryUnavailable("the SDK refused runtime discovery")


class Client:
    def __init__(self, base_url: str, *, token: str, namespace: str) -> None:
        self.base_url = base_url
        self.timeout = 30
        self._http = httpx.AsyncClient(base_url=base_url, timeout=self.timeout)
        self._headers = {
            "Authorization": f"Bearer {token}",
            "X-Namespace": namespace,
            "X-Durable-Workflow-Control-Plane-Version": "2",
        }

    async def _request(
        self,
        method: str,
        path: str,
        *,
        json: object = None,
        context: str = "",
    ) -> object:
        del context
        response = await self._http.request(
            method,
            f"/api{path}",
            json=json,
            headers=self._headers,
        )
        payload = response.json()
        if response.status_code == 422:
            raise InvalidArgument(str(payload))
        return payload

    async def start_workflow(
        self,
        *,
        workflow_type: str,
        task_queue: str,
        workflow_id: str,
        input: list[object] | None = None,
        duplicate_policy: str = "fail",
        execution_timeout_seconds: int | None = None,
        run_timeout_seconds: int | None = None,
    ) -> WorkflowHandle:
        payload = await self._request(
            "POST",
            "/workflows",
            json={
                "workflow_type": workflow_type,
                "task_queue": task_queue,
                "workflow_id": workflow_id,
                "input": serializer.encode(input or []),
                "duplicate_policy": duplicate_policy,
                "execution_timeout_seconds": execution_timeout_seconds,
                "run_timeout_seconds": run_timeout_seconds,
            },
        )
        return WorkflowHandle(self, payload["workflow_id"], payload["run_id"])

    async def aclose(self) -> None:
        await self._http.aclose()
