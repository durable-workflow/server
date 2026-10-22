"""Normal installed Python worker for explicit slow activity-lease controls."""

import asyncio
import importlib.metadata
import json
import os
import signal
import time
from pathlib import Path

from durable_workflow import Client, Worker, activity, workflow

STATE = Path('/state')


def record(value):
    with (STATE / 'worker-events.jsonl').open('a') as stream:
        stream.write(json.dumps(value, ensure_ascii=False) + '\n')


@activity.defn(name='qualification.blocking-python')
def blocking(seconds: int, mode: str):
    info = activity.context().info
    with (STATE / 'invocations.jsonl').open('a') as stream:
        stream.write(json.dumps({
            'mode': mode, 'attempt': info.attempt_number,
            'task_id': info.task_id, 'activity_attempt_id': info.activity_attempt_id,
            'pid': os.getpid(), 'monotonic_ns': time.monotonic_ns(),
        }) + '\n')
    if mode == 'long' or info.attempt_number == 1:
        time.sleep(seconds)
    return [9007199254740993, None, 'λ']


@workflow.defn(name='qualification.runtime-lease-python')
class LeaseWorkflow:
    def run(self, context, seconds: int, mode: str):
        return (yield context.schedule_activity(
            'qualification.blocking-python', [seconds, mode],
            retry_policy={'max_attempts': 2, 'backoff_seconds': [0]},
        ))


class ObservedClient(Client):
    async def register_worker(self, **kwargs):
        result = await super().register_worker(**kwargs)
        record({'event': 'worker.registered', 'worker_id': kwargs['worker_id']})
        return result


async def main():
    assert importlib.metadata.version('durable-workflow') == '2.8.0'
    (STATE / 'worker.pid').write_text(str(os.getpid()))
    async with ObservedClient(
        os.environ['SERVER_URL'], token='synthetic',
        namespace=os.environ.get('NAMESPACE', 'default'),
    ) as client:
        info = await client.get_cluster_info()
        assert info['worker_protocol']['server_capabilities']['activity_lease_renewal'] is True
        worker = Worker(
            client, task_queue='lease-qualification-python', workflows=[LeaseWorkflow],
            activities=[blocking], worker_id=os.environ['WORKER_ID'], poll_timeout=1,
            heartbeat_interval=1, max_concurrent_activity_tasks=1,
        )
        loop = asyncio.get_running_loop()
        loop.add_signal_handler(signal.SIGTERM, lambda: asyncio.create_task(worker.stop()))
        await worker.run()
    record({'event': 'worker.stopped'})


asyncio.run(main())
