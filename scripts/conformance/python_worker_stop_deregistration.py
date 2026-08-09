"""Orderly-deregistration evidence for the published Python worker scenario."""

from __future__ import annotations

from typing import Any


async def verify_stopped_worker_absent(
    client: Any,
    worker_id: str,
    *,
    server_error_type: type[BaseException],
) -> dict[str, Any]:
    """Prove that ``Worker.stop()`` removed a worker without deleting it again."""

    inventory = await client.list_workers()
    workers = getattr(inventory, "workers", None)
    if not isinstance(workers, list):
        raise RuntimeError("worker inventory did not return a worker list")

    worker_ids = [
        candidate_id
        for worker in workers
        if isinstance(candidate_id := getattr(worker, "worker_id", None), str)
    ]
    if worker_id in worker_ids:
        raise RuntimeError(
            f"managed Worker.stop() left worker {worker_id!r} in the worker inventory"
        )

    try:
        detail = await client.describe_worker(worker_id)
    except server_error_type as error:
        status = getattr(error, "status", None)
        reason_reader = getattr(error, "reason", None)
        reason = reason_reader() if callable(reason_reader) else None
        if status != 404 or reason not in {"worker_not_found", "not_found"}:
            raise RuntimeError(
                "stopped worker detail returned an unexpected typed error: "
                f"status={status!r}, reason={reason!r}"
            ) from error
    else:
        raise RuntimeError(
            f"managed Worker.stop() left worker {worker_id!r} addressable: {detail!r}"
        )

    return {
        "authoritative_action": "Worker.stop",
        "worker_id": worker_id,
        "inventory": {
            "namespace": getattr(inventory, "namespace", None),
            "worker_ids": worker_ids,
            "worker_absent": True,
        },
        "detail": {
            "status": status,
            "reason": reason,
            "worker_absent": True,
        },
    }
