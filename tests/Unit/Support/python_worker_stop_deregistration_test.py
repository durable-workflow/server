from __future__ import annotations

import sys
import unittest
from pathlib import Path
from types import SimpleNamespace

REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPOSITORY_ROOT / "scripts" / "conformance"))

from python_worker_stop_deregistration import verify_stopped_worker_absent  # noqa: E402


class FakeServerError(Exception):
    def __init__(self, status: int, reason: str) -> None:
        super().__init__(f"server returned {status}: {reason}")
        self.status = status
        self._reason = reason

    def reason(self) -> str:
        return self._reason


class StoppedWorkerClient:
    def __init__(
        self,
        *,
        inventory_worker_ids: list[str] | None = None,
        detail_error: BaseException | None = None,
        detail: object | None = None,
    ) -> None:
        self.inventory_worker_ids = inventory_worker_ids or []
        self.detail_error = detail_error or FakeServerError(404, "worker_not_found")
        self.detail = detail
        self.deregister_calls = 0
        self.detail_calls = 0

    async def list_workers(self) -> object:
        return SimpleNamespace(
            namespace="python-parity-fixture",
            workers=[
                SimpleNamespace(worker_id=worker_id)
                for worker_id in self.inventory_worker_ids
            ],
        )

    async def describe_worker(self, worker_id: str) -> object:
        self.detail_calls += 1
        if self.detail is not None:
            return self.detail
        raise self.detail_error

    async def deregister_worker(self, worker_id: str) -> object:
        self.deregister_calls += 1
        raise FakeServerError(404, "worker_not_found")


class WorkerStopDeregistrationRegressionTest(unittest.IsolatedAsyncioTestCase):
    async def test_managed_stop_absence_passes_without_duplicate_cleanup(self) -> None:
        stopped_worker_id = "python-parity-worker-1"

        legacy_client = StoppedWorkerClient()
        with self.assertRaises(FakeServerError):
            await legacy_client.deregister_worker(stopped_worker_id)
        self.assertEqual(1, legacy_client.deregister_calls)

        for reason in ("worker_not_found", "not_found"):
            with self.subTest(reason=reason):
                corrected_client = StoppedWorkerClient(
                    detail_error=FakeServerError(404, reason)
                )
                evidence = await verify_stopped_worker_absent(
                    corrected_client,
                    stopped_worker_id,
                    server_error_type=FakeServerError,
                )

                self.assertEqual(0, corrected_client.deregister_calls)
                self.assertEqual(1, corrected_client.detail_calls)
                self.assertEqual("Worker.stop", evidence["authoritative_action"])
                self.assertTrue(evidence["inventory"]["worker_absent"])
                self.assertEqual(
                    {"status": 404, "reason": reason, "worker_absent": True},
                    evidence["detail"],
                )

    async def test_inventory_fails_closed_when_stopped_worker_remains(self) -> None:
        client = StoppedWorkerClient(inventory_worker_ids=["python-parity-worker-1"])

        with self.assertRaisesRegex(
            RuntimeError, "left worker.*in the worker inventory"
        ):
            await verify_stopped_worker_absent(
                client,
                "python-parity-worker-1",
                server_error_type=FakeServerError,
            )

        self.assertEqual(0, client.detail_calls)

    async def test_detail_fails_closed_for_other_status_or_reason(self) -> None:
        for error in (
            FakeServerError(500, "worker_not_found"),
            FakeServerError(404, "namespace_not_found"),
        ):
            with self.subTest(status=error.status, reason=error.reason()):
                client = StoppedWorkerClient(detail_error=error)
                with self.assertRaisesRegex(RuntimeError, "unexpected typed error"):
                    await verify_stopped_worker_absent(
                        client,
                        "python-parity-worker-1",
                        server_error_type=FakeServerError,
                    )

    async def test_detail_fails_closed_when_worker_is_still_addressable(self) -> None:
        client = StoppedWorkerClient(detail={"worker_id": "python-parity-worker-1"})

        with self.assertRaisesRegex(RuntimeError, "left worker.*addressable"):
            await verify_stopped_worker_absent(
                client,
                "python-parity-worker-1",
                server_error_type=FakeServerError,
            )

    async def test_detail_fails_closed_for_an_untyped_lookup_error(self) -> None:
        client = StoppedWorkerClient(detail_error=ConnectionError("connection refused"))

        with self.assertRaises(ConnectionError):
            await verify_stopped_worker_absent(
                client,
                "python-parity-worker-1",
                server_error_type=FakeServerError,
            )


if __name__ == "__main__":
    unittest.main()
