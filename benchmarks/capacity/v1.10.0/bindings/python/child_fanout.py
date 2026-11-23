"""Run the Python binding of the native child fan-out capacity cell."""

import argparse
import asyncio
from collections.abc import Generator
from importlib.metadata import version
import json
import os
from time import perf_counter_ns
from typing import Any

from durable_workflow import Client, Worker, workflow
from durable_workflow.workflow import WorkflowContext


PARENT_TYPE = "capacity.v1_10.child_parent"
LEAF_TYPE = "capacity.v1_10.child_leaf"
PARENT_QUEUE = "capacity-v1-10-parent"
LEAF_QUEUE = "capacity-v1-10-leaf"
WIDTHS = (10, 1000)


@workflow.defn(name=LEAF_TYPE)
class ChildLeaf:
    def run(self, context: WorkflowContext, index: int) -> int:
        return index


@workflow.defn(name=PARENT_TYPE)
class ChildParent:
    def run(self, context: WorkflowContext, width: int) -> Generator[Any, Any, int]:
        if width not in WIDTHS:
            raise ValueError("Use width 10 for the control or width 1000 for the capacity cell.")
        expected = list(range(1, width + 1))
        results = yield [
            context.start_child_workflow(LEAF_TYPE, [index], task_queue=LEAF_QUEUE)
            for index in expected
        ]
        if results != expected:
            raise RuntimeError("The child barrier returned unexpected values or ordering.")
        return sum(results)


async def run(args: argparse.Namespace) -> None:
    async with Client(
        os.environ["DURABLE_WORKFLOW_RUNTIME_URL"],
        namespace=os.environ.get("DURABLE_WORKFLOW_NAMESPACE", "default"),
        token=os.environ["DURABLE_WORKFLOW_TOKEN"],
    ) as client:
        if args.role != "run":
            queue, workflow_class = {
                "parent": (PARENT_QUEUE, ChildParent),
                "leaf": (LEAF_QUEUE, ChildLeaf),
            }[args.role]
            await Worker(
                client,
                task_queue=queue,
                workflows=[workflow_class],
                max_concurrent_workflow_tasks=1,
                max_concurrent_activity_tasks=1,
            ).run()
            return

        started = perf_counter_ns()
        handle = await client.start_workflow(
            workflow_type=PARENT_TYPE,
            task_queue=PARENT_QUEUE,
            workflow_id=args.workflow_id,
            input=[args.width],
        )
        result = await handle.result(timeout=240, poll_interval=0.25, result_type=int)
        if result != args.width * (args.width + 1) // 2:
            raise RuntimeError("The parent completed with the wrong result.")
        print(json.dumps({
            "sdk_version": version("durable-workflow"),
            "workflow_id": args.workflow_id,
            "run_id": handle.run_id,
            "width": args.width,
            "result": result,
            "elapsed_ms": (perf_counter_ns() - started) / 1_000_000,
        }))


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="role", required=True)
    commands.add_parser("parent")
    commands.add_parser("leaf")
    client = commands.add_parser("run")
    client.add_argument("width", type=int, choices=WIDTHS)
    client.add_argument("workflow_id")
    asyncio.run(run(parser.parse_args()))


if __name__ == "__main__":
    main()
