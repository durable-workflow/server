"""Real old-writer receipt creation and separate post-upgrade HTTP readback."""

import asyncio
import base64
import hashlib
import importlib.metadata
import json
import os
import sys
from pathlib import Path

import httpx
from definition import WaitingWorkflow

from durable_workflow import Client, Worker, serializer

ROOT = Path('/phase')
TOKEN = 'update-key-consumer'
EXPECTED = {'count': 17, 'typed': [9223372036854775807, None, 'Київ']}
MODE = os.environ['KEY_MODE']


def verify_install():
    dist = importlib.metadata.distribution('durable-workflow')
    assert dist.version == '2.6.1'
    checked = 0
    for entry in dist.files or []:
        if not str(entry).startswith('durable_workflow/') or entry.hash is None:
            continue
        path = dist.locate_file(entry)
        assert str(path).startswith('/opt/venv/'), path
        digest = base64.urlsafe_b64encode(hashlib.sha256(path.read_bytes()).digest())
        assert digest.decode().rstrip('=') == entry.hash.value.rstrip('='), str(entry)
        checked += 1
    assert checked > 25, checked
    return checked


class ObservedClient(Client):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.registered = asyncio.Event()

    async def register_worker(self, **kwargs):
        result = await super().register_worker(**kwargs)
        self.registered.set()
        return result


async def original(namespace):
    identity = os.environ['KEY_COHORT'] + '-' + MODE + '-' + namespace
    queue = 'q-' + identity if MODE == 'service' else 'default'
    headers = {'Authorization': 'Bearer ' + TOKEN, 'X-Namespace': namespace,
               'X-Durable-Workflow-Control-Plane-Version': '2'}
    evidence_path = ROOT / 'evidence' / (MODE + '-' + namespace + '.json')
    evidence_path.parent.mkdir(exist_ok=True)
    async with (
        Client('http://api:8080', token=TOKEN, namespace=namespace) as client,
        ObservedClient('http://api:8080', token='update-key-worker', namespace=namespace) as worker_client,
        httpx.AsyncClient(base_url='http://api:8080', headers=headers, timeout=10) as http,
    ):
        running = None
        worker = None
        if MODE == 'service':
            worker = Worker(worker_client, task_queue=queue, workflows=[WaitingWorkflow],
                            worker_id=identity, poll_timeout=1, heartbeat_interval=1,
                            max_concurrent_workflow_tasks=1, shutdown_timeout=5)
            running = asyncio.create_task(worker.run())
            await asyncio.wait_for(worker_client.registered.wait(), 8)
        try:
            handle = await client.start_workflow(
                workflow_type=('update-key-consumer' if MODE == 'service'
                               else 'UpdateRequestKeyConsumer\\WaitingWorkflow'),
                task_queue=queue, workflow_id=identity, input=[],
                run_timeout_seconds=60, execution_timeout_seconds=120,
            )
            response = await http.post('/api/workflows/' + identity + '/update/add', json={
                'input': {'codec': 'avro', 'blob': serializer.encode([17])},
                'request_id': 'legacy-caller-key', 'wait_for': 'completed',
                'wait_timeout_seconds': 5,
            }, headers={'X-Request-Id': 'legacy-caller-key'})
            assert response.status_code == 200, (response.status_code, response.text)
            receipt = response.json()
            assert receipt['update_status'] == 'completed', receipt
            assert receipt['result'] == EXPECTED, receipt
            await handle.signal('finish', [])
            assert await handle.result(timeout=8) == EXPECTED
            history = await http.get('/api/workflows/' + identity + '/history')
            history.raise_for_status()
            evidence = {'workflow_id': identity, 'run_id': handle.run_id,
                        'namespace': namespace, 'mode': MODE, 'receipt': receipt,
                        'history': history.json(), 'result': EXPECTED}
            evidence_path.write_text(json.dumps(evidence, ensure_ascii=False, indent=2))
        finally:
            if worker is not None:
                await worker.stop()
            if running is not None:
                await asyncio.wait_for(running, 8)


async def retained(namespace):
    path = ROOT / 'evidence' / (MODE + '-' + namespace + '.json')
    original = json.loads(path.read_text())
    headers = {'Authorization': 'Bearer ' + TOKEN, 'X-Namespace': namespace,
               'X-Durable-Workflow-Control-Plane-Version': '2'}
    async with httpx.AsyncClient(base_url='http://api:8080', headers=headers, timeout=10) as http:
        response = await http.post('/api/workflows/' + original['workflow_id'] + '/update/other', json={
            'input': {'codec': 'avro', 'blob': serializer.encode([99])},
            'request_id': 'legacy-caller-key', 'wait_for': 'completed',
            'wait_timeout_seconds': 5,
        }, headers={'X-Request-Id': 'new-proxy-correlation'})
        assert response.status_code == 200, (response.status_code, response.text)
        actual = response.json()
        for key in ('update_id', 'command_id', 'run_id', 'update_name', 'update_status', 'result'):
            assert actual[key] == original['receipt'][key], (key, actual, original['receipt'])
        history = await http.get('/api/workflows/' + original['workflow_id'] + '/history')
        history.raise_for_status()
        assert history.json()['events'] == original['history']['events']
        path.with_suffix('.retained.json').write_text(json.dumps(actual, ensure_ascii=False, indent=2))


async def main():
    assert MODE in ('service', 'embedded')
    stage = sys.argv[1]
    assert stage in ('create', 'retained')
    checked = verify_install()
    async with httpx.AsyncClient(base_url='http://api:8080', timeout=10) as http:
        if stage == 'create':
            response = await http.post('/api/namespaces', json={'name': 'key-upgrade'}, headers={
                'Authorization': 'Bearer ' + TOKEN, 'X-Durable-Workflow-Control-Plane-Version': '2',
            })
            assert response.status_code == 201, (response.status_code, response.text)
    for namespace in ('default', 'key-upgrade'):
        await asyncio.wait_for((original if stage == 'create' else retained)(namespace), 30)
    assert verify_install() == checked
    print(json.dumps({'stage': stage, 'mode': MODE, 'originals': 2,
                      'installed_sdk': '2.6.1', 'verified_files': checked}))


asyncio.run(main())
