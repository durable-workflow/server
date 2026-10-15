"""Normal installed Python Worker/client; the external observer holds real bytes."""
import asyncio
import importlib.metadata
import hashlib
import json
import os
import sys
import time
from pathlib import Path

from durable_workflow import Client, Worker, workflow
from durable_workflow.errors import QueryFailed
import durable_workflow

URL, MODE, PROTOCOL, JOURNAL = sys.argv[1:]
TYPE = 'parity.query-authority.python.v1'
EXPECTED = {'long': 9223372036854775807, 'nil': None, 'text': 'rangeλ'}
COUNTERS = {'calls': 0, 'returned': 0, 'cancelled': 0}


def events():
    text = Path(JOURNAL).read_text() if Path(JOURNAL).exists() else ''
    # Only a complete line is an observation; the next read sees an append
    # that was still in flight. No record or response is rewritten.
    return [json.loads(line) for line in text.split('\n')[:-1] if line]


@workflow.defn(name=TYPE)
class QueryWorkflow:
    def run(self, ctx, value):
        self.value = value
        yield ctx.sleep(60)
        return value

    @workflow.query('value')
    async def current(self):
        COUNTERS['calls'] += 1
        assert self.value == 7
        try:
            if MODE == 'late-handler-async':
                await asyncio.sleep(6)
            elif MODE == 'late-handler-sync':
                time.sleep(6)
            COUNTERS['returned'] += 1
            return EXPECTED
        except asyncio.CancelledError:
            COUNTERS['cancelled'] += 1
            raise


async def main():
    artifacts = json.loads(Path(os.environ['DW_PARITY_QUERY_PROFILE']).read_text())
    identity = artifacts['sdk_python']
    assert importlib.metadata.version('durable-workflow') == identity['version']
    package = Path(durable_workflow.__file__).parent
    hashes = {str(path.relative_to(package)): hashlib.sha256(path.read_bytes()).hexdigest()
              for path in package.rglob('*') if path.is_file() and (path.suffix == '.py' or path.name == 'py.typed')}
    assert len(hashes) == identity['loaded_files']
    assert hashlib.sha256(json.dumps(hashes, sort_keys=True, separators=(',', ':')).encode()).hexdigest() == identity['loaded_tree_sha256']
    assert PROTOCOL in ('1.20', '1.19') and (MODE == 'timely' or PROTOCOL == '1.20')
    os.environ['DURABLE_WORKFLOW_WORKER_PROTOCOL_VERSION'] = PROTOCOL
    started = time.monotonic()
    namespace = 'query-python-' + os.urandom(6).hex()
    async with asyncio.timeout(30):
        async with Client(URL, token=os.environ['DW_PARITY_TOKEN']) as admin:
            await admin.create_namespace(namespace)
        async with Client(URL, token=os.environ['DW_PARITY_TOKEN'], namespace=namespace) as client:
            worker = Worker(client, workflows=[QueryWorkflow], task_queue=namespace,
                            worker_id=namespace, poll_timeout=8, shutdown_timeout=5,
                            require_query_task_authority=PROTOCOL == '1.20')
            runner = asyncio.create_task(worker.run())
            try:
                while not any(x['event'] == 'registered' and x['status'] == 201 for x in events()):
                    if runner.done():
                        await runner
                        raise RuntimeError('Worker stopped before registration')
                    await asyncio.sleep(.02)
                handle = await client.start_workflow(workflow_type=TYPE, task_queue=namespace,
                                                     workflow_id=namespace, input=[7])
                while (await handle.describe()).status.lower() != 'waiting':
                    if runner.done():
                        await runner
                        raise RuntimeError('Worker stopped before timer admission')
                    await asyncio.sleep(.02)
                if MODE == 'timely':
                    result = await client.query_workflow(namespace, 'value', args=[])
                    assert result['result'] == EXPECTED and type(result['result']['long']) is int
                else:
                    try:
                        await client.query_workflow(namespace, 'value', args=[])
                        raise AssertionError('An expired original succeeded')
                    except QueryFailed as error:
                        assert error.status == 504 and error.reason == 'query_worker_execution_timeout'
                    while (MODE == 'late-poll' and not any(x['event'] == 'delivered' for x in events())
                           or MODE == 'late-handler-async' and COUNTERS['cancelled'] != 1
                           or MODE == 'late-handler-sync' and COUNTERS['returned'] != 1):
                        await asyncio.sleep(.02)
            finally:
                await worker.stop()
                await runner
    assert time.monotonic() - started < 30
    observed = events()
    named = lambda name: [x for x in observed if x['event'] == name]
    assert not named('observer-error')
    assert len(named('claim')) == len(named('delivered')) == len(named('query-result')) == 1
    assert len(named('submission')) == len(named('acknowledgement')) == (1 if MODE == 'timely' else 0)
    assert named('deregistered')[-1]['status'] == 200
    assert COUNTERS == {'calls': 0 if MODE == 'late-poll' else 1,
                        'returned': 1 if MODE in ('timely', 'late-handler-sync') else 0,
                        'cancelled': 1 if MODE == 'late-handler-async' else 0}
    claim = named('claim')[0]['claim']
    assert named('claim')[0]['protocol'] == PROTOCOL
    assert bool(named('status')) == (PROTOCOL == '1.20')
    for entry in named('status'):
        for field in ('query_task_id', 'query_task_attempt', 'lease_owner', 'query_admitted_at',
                      'query_deadline_at', 'lease_expires_at'):
            assert entry['observation'][field] == claim[field], field
    print(json.dumps({'outcome': 'pass', 'language': 'python', 'mode': MODE, 'protocol': PROTOCOL,
                      'sdk_version': importlib.metadata.version('durable-workflow'),
                      'namespace': namespace, 'run_id': handle.run_id, 'claim': claim,
                      'counters': COUNTERS, 'status_reads': len(named('status')),
                      'history_sha256': named('query-result')[0]['history_sha256'],
                      'original_budget_seconds': 30, 'elapsed_seconds': time.monotonic() - started,
                      'worker_exit': 0}))


asyncio.run(main())

