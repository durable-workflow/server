"""Normal installed Python Worker/client; the external observer holds real bytes."""
import asyncio
import importlib.metadata
import hashlib
import json
import logging
import os
import re
import sys
import time
from datetime import datetime
from pathlib import Path

from durable_workflow import Client, Worker, workflow
from durable_workflow.errors import QueryFailed
import durable_workflow

URL, MODE, PROTOCOL, JOURNAL = sys.argv[1:]
TYPE = 'parity.query-authority.python.v1'
EXPECTED = {'long': 9223372036854775807, 'nil': None, 'text': 'rangeλ'}
COUNTERS = {'calls': 0, 'returned': 0, 'cancelled': 0}
AUTHORITY_LOSSES = []


class AuthorityLog(logging.Handler):
    """Observe the installed SDK's public logging without changing its worker."""
    def emit(self, record):
        match = re.fullmatch(
            r"(?:query poll error|query delivery suppressed): query task ['\"]([A-Za-z0-9_-]{1,128})['\"] cannot continue: ([a-z_]{1,64})",
            record.getMessage())
        if match and len(AUTHORITY_LOSSES) < 16:
            loss = {'query_task_id': match[1], 'reason': match[2], 'observed_at': time.time()}
            AUTHORITY_LOSSES.append(loss)
            print('query authority refusal: ' + json.dumps(loss), file=sys.stderr)


worker_log = logging.getLogger('durable_workflow.worker')
worker_log.setLevel(logging.INFO)
worker_log.addHandler(AuthorityLog())


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
                    # Delivery is recorded before the SDK receives its response.
                    # Wait for its actual refusal before stopping the worker.
                    while (MODE == 'late-poll' and not any(
                               loss['query_task_id'] == event['claim']['query_task_id']
                               for loss in AUTHORITY_LOSSES for event in events() if event['event'] == 'claim')
                           or MODE == 'late-handler-async' and COUNTERS['cancelled'] != 1
                           or MODE == 'late-handler-sync' and COUNTERS['returned'] != 1):
                        await asyncio.sleep(.02)
            finally:
                await worker.stop()
                await runner
    assert time.monotonic() - started < 30
    observed = events()
    named = lambda name: [x for x in observed if x['event'] == name]
    assert not named('observer-error'), named('observer-error')
    assert len(named('claim')) == len(named('delivered')) == len(named('query-result')) == 1
    assert len(named('submission')) == len(named('acknowledgement')) == (1 if MODE == 'timely' else 0)
    assert named('deregistered')[-1]['status'] == 200
    assert COUNTERS == {'calls': 0 if MODE == 'late-poll' else 1,
                        'returned': 1 if MODE in ('timely', 'late-handler-sync') else 0,
                        'cancelled': 1 if MODE == 'late-handler-async' else 0}
    claim = named('claim')[0]['claim']
    assert named('claim')[0]['protocol'] == PROTOCOL
    assert named('claim')[0]['namespace'] == claim.get('namespace', namespace) == namespace
    assert claim['task_queue'] == claim['lease_owner'] == namespace
    assert claim['workflow_id'] == namespace and claim['run_id'] == handle.run_id
    discovery = named('discovery')
    if PROTOCOL == '1.20':
        assert discovery and all(x['status'] == 200 and x['query_task_authority'] is True for x in discovery), discovery
        if not named('status'):
            # Proven expiry needs no redundant HTTP observation. No callback
            # alone would also admit malformed metadata or an observer failure.
            bound = min(datetime.fromisoformat(claim[key].replace('Z', '+00:00')).timestamp()
                        for key in ('query_deadline_at', 'lease_expires_at'))
            assert MODE == 'late-poll'
            assert any(loss['query_task_id'] == claim['query_task_id']
                       and loss['reason'] in ('query_execution_budget_expired', 'query_task_timed_out', 'query_lease_expired')
                       and loss['observed_at'] >= bound for loss in AUTHORITY_LOSSES), AUTHORITY_LOSSES
    else:
        assert not named('status')
    for entry in named('status'):
        for field in ('query_task_id', 'query_task_attempt', 'lease_owner', 'query_admitted_at',
                      'query_deadline_at', 'lease_expires_at'):
            assert entry['observation'][field] == claim[field], field
    print(json.dumps({'outcome': 'pass', 'language': 'python', 'mode': MODE, 'protocol': PROTOCOL,
                      'sdk_version': importlib.metadata.version('durable-workflow'),
                      'namespace': namespace, 'run_id': handle.run_id, 'claim': claim,
                      'counters': COUNTERS, 'status_reads': len(named('status')),
                      'authority_losses': AUTHORITY_LOSSES,
                      'authority_discovery': discovery,
                      'history_sha256': named('query-result')[0]['history_sha256'],
                      'original_budget_seconds': 30, 'elapsed_seconds': time.monotonic() - started,
                      'worker_exit': 0}))


asyncio.run(main())

