"""Application fixture using the selected installed Python SDK."""
import asyncio
import importlib.metadata
import json
import os
import signal
import sys
import time

from durable_workflow import Client, Worker, workflow

TYPE = "conformance.rust-build-cohort"


def client(cls=Client):
    return cls(os.environ["DW_WV_SERVER_URL"], token=os.getenv("DW_WV_AUTH_TOKEN", "dev-token"),
               namespace=os.environ["DW_WV_NAMESPACE"])


async def main():
    if sys.argv[1] == "--client":
        request = json.load(sys.stdin)
        async with client() as api:
            if request["action"] == "start":
                handle = await api.start_workflow(workflow_type=TYPE, workflow_id=request["workflow_id"],
                                                  task_queue=request["task_queue"], input=[request["workflow_id"]])
                result = {"workflow_id": handle.workflow_id, "run_id": handle.run_id}
            else:
                handle = api.get_workflow_handle(request["workflow_id"], run_id=request["run_id"], workflow_type=TYPE)
                if request["action"] == "signal":
                    await handle.signal(request["signal"], [])
                    result = {"acknowledged": True}
                elif request["action"] == "result":
                    result = await handle.result(timeout=15)
                else:
                    raise ValueError("unknown client action")
        print(json.dumps(result), flush=True)
        return

    queue, worker_id, build, producer, trace_path = sys.argv[2:]

    def trace(record):
        with open(trace_path, "a", encoding="utf8") as output:
            output.write(json.dumps({"observed_at": time.time(), **record}) + "\n")

    class ObservedClient(Client):
        async def register_worker(self, **kwargs):
            response = await super().register_worker(**kwargs)
            print(json.dumps({"registered": True, "pid": os.getpid(), "worker_id": worker_id,
                              "sdk_version": importlib.metadata.version("durable-workflow")}), flush=True)
            return response

        async def poll_workflow_task(self, **kwargs):
            try:
                if not os.path.exists(trace_path + ".enabled"):
                    trace({"kind": "paused"})
                    while not os.path.exists(trace_path + ".enabled"):
                        await asyncio.sleep(0.02)
                task = await super().poll_workflow_task(**kwargs)
                trace({"kind": "poll", "task": task})
                return task
            except Exception as error:
                trace({"kind": "error", "error": str(error)})
                raise

    @workflow.defn(name=TYPE)
    class Cohort:
        def __init__(self):
            self.finished = False

        @workflow.signal("finish")
        def finish(self):
            self.finished = True

        def run(self, context, workflow_id):
            def effect():
                trace({"kind": "producer", "workflow_id": workflow_id, "producer": producer})
                return producer

            recorded = yield context.side_effect(effect)
            trace({"kind": "callback", "workflow_id": workflow_id, "recorded": recorded})
            yield context.wait_condition(lambda: self.finished, key="mixed-build-finish")
            return {"producer": recorded}

    async with client(ObservedClient) as api:
        worker = Worker(api, task_queue=queue, workflows=[Cohort], worker_id=worker_id,
                        build_id=build, poll_timeout=1, heartbeat_interval=10)
        loop = asyncio.get_running_loop()
        for signum in (signal.SIGTERM, signal.SIGINT):
            loop.add_signal_handler(signum, lambda: asyncio.create_task(worker.stop()))
        await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
