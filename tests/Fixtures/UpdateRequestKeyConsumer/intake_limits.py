"""Published API intake limits and immutable retained rejection readback."""

import asyncio
import base64
import datetime
import hashlib
import importlib.metadata
import json
import os
import shutil
import sqlite3
import sys
from pathlib import Path

ROOT = Path('/phase')
TABLES = ('workflow_commands', 'workflow_updates', 'workflow_runs',
          'workflow_tasks', 'workflow_history_events')
TOKEN = 'update-key-consumer'


def encode_blob(value):
    if isinstance(value, bytes):
        return {'base64': base64.b64encode(value).decode()}
    raise TypeError(type(value).__name__)


def audit(stage):
    source = ROOT / 'data/database.sqlite'
    assert not Path(str(source) + '-wal').exists(), 'stopped DELETE database required'
    before = hashlib.sha256(source.read_bytes()).hexdigest()
    copied = Path('/audit/database.sqlite')
    shutil.copyfile(source, copied)
    db = sqlite3.connect('file:' + str(copied) + '?mode=ro', uri=True)
    db.row_factory = sqlite3.Row
    db.execute('PRAGMA query_only=ON')
    snapshot = {}
    for table in TABLES:
        rows = db.execute('SELECT * FROM ' + table + ' ORDER BY id LIMIT 1001').fetchall()
        assert len(rows) <= 1000, table
        snapshot[table] = [dict(row) for row in rows]
    snapshot = json.loads(json.dumps(snapshot, default=encode_blob))
    assert len(snapshot['workflow_runs']) == 4
    assert all(row['status'] == 'waiting' for row in snapshot['workflow_runs'])
    assert sum(row['repair_count'] for row in snapshot['workflow_tasks']) == 0
    assert db.execute('SELECT COUNT(*) FROM workflow_worker_registrations').fetchone()[0] == 0
    assert db.execute('SELECT COUNT(*) FROM failed_jobs').fetchone()[0] == 0
    for run in snapshot['workflow_runs']:
        started = datetime.datetime.fromisoformat(run['started_at'])
        assert (datetime.datetime.fromisoformat(run['run_deadline_at']) - started).total_seconds() == 60
        assert (datetime.datetime.fromisoformat(run['execution_deadline_at']) - started).total_seconds() == 120
    if stage == 'audit-original':
        assert len(snapshot['workflow_updates']) == 2
        assert all(row['status'] == 'accepted' for row in snapshot['workflow_updates'])
    elif stage == 'audit-refused':
        original = json.loads((ROOT / 'evidence/audit-original.json').read_text())['snapshot']
        assert snapshot['workflow_tasks'] == original['workflow_tasks'], 'tasks changed after refusal'
        original_events = {row['id'] for row in original['workflow_history_events']}
        preserved = [row for row in snapshot['workflow_history_events'] if row['id'] in original_events]
        assert preserved == original['workflow_history_events']
        added = [row for row in snapshot['workflow_history_events'] if row['id'] not in original_events]
        assert len(added) == 4
        assert {row['workflow_run_id'] for row in added} == {row['id'] for row in original['workflow_runs']}
        for event in added:
            assert event['event_type'] == 'UpdateRejected' and event['workflow_task_id'] is None
            payload = json.loads(event['payload'])
            assert payload['workflow_command_id'] == event['workflow_command_id']
            assert payload['command']['rejection_reason'] == 'structural_limit_exceeded'
            assert payload['command']['request_id'] == 'over-limit'
            assert payload['validation_errors']['arguments']
        current_runs = {row['id']: row for row in snapshot['workflow_runs']}
        bookkeeping = {'last_history_sequence', 'last_command_sequence', 'last_progress_at', 'updated_at'}
        for run in original['workflow_runs']:
            current = current_runs[run['id']]
            assert {k: v for k, v in current.items() if k not in bookkeeping} == {
                k: v for k, v in run.items() if k not in bookkeeping
            }
            assert current['last_history_sequence'] == run['last_history_sequence'] + 1
            assert current['last_command_sequence'] == run['last_command_sequence'] + 1
        for table in ('workflow_commands', 'workflow_updates'):
            current = {row['id']: row for row in snapshot[table]}
            assert all(current[row['id']] == row for row in original[table]), table
            assert len(current) == len(original[table]) + 4, table
        assert sum(row['status'] == 'rejected' for row in snapshot['workflow_updates']) == 4
    elif stage == 'audit-retained':
        refused = json.loads((ROOT / 'evidence/audit-refused.json').read_text())['snapshot']
        assert snapshot == refused, 'retained receipt read mutated durable rows'
    else:
        raise ValueError(stage)
    db.close()
    assert hashlib.sha256(source.read_bytes()).hexdigest() == before
    assert hashlib.sha256(copied.read_bytes()).hexdigest() == before
    result = {'stage': stage, 'database_sha256': before, 'source_unchanged': True,
              'counts': {table: len(rows) for table, rows in snapshot.items()}, 'snapshot': snapshot}
    (Path('/evidence') / (stage + '.json')).write_text(json.dumps(result, ensure_ascii=False, indent=2))
    print(json.dumps({key: value for key, value in result.items() if key != 'snapshot'}))


def verify_install():
    dist = importlib.metadata.distribution('durable-workflow')
    assert dist.version == '2.6.1'
    checked = 0
    for entry in dist.files or []:
        if not str(entry).startswith('durable_workflow/') or entry.hash is None:
            continue
        path = dist.locate_file(entry)
        assert str(path).startswith('/opt/venv/'), path
        actual = base64.urlsafe_b64encode(hashlib.sha256(path.read_bytes()).digest()).decode().rstrip('=')
        assert actual == entry.hash.value.rstrip('='), str(entry)
        checked += 1
    assert checked > 25
    return checked


async def business(stage):
    import httpx
    from durable_workflow import Client, Worker, serializer
    from definition import WaitingWorkflow

    checked = verify_install()

    def headers(namespace):
        return {'Authorization': 'Bearer ' + TOKEN, 'X-Namespace': namespace,
                'X-Durable-Workflow-Control-Plane-Version': '2'}

    async def update(http, case, name, key, amount, proxy):
        return await http.post('/api/workflows/' + case['workflow_id'] + '/update/' + name,
                               json={'input': {'codec': 'avro', 'blob': serializer.encode([amount])},
                                     'request_id': key},
                               headers={**headers(case['namespace']), 'X-Request-Id': proxy})

    originals_path = ROOT / 'evidence/intake-originals.json'
    async with httpx.AsyncClient(base_url='http://api:8080', timeout=5) as http:
        if stage == 'create':
            response = await http.post('/api/namespaces', json={'name': 'intake-limit'}, headers=headers('default'))
            assert response.status_code == 201, (response.status_code, response.text)
            cases = []

            class ObservedClient(Client):
                def __init__(self, **kwargs):
                    super().__init__('http://api:8080', **kwargs)
                    self.registered = asyncio.Event()
                    self.completed = 0

                async def register_worker(self, **kwargs):
                    result = await super().register_worker(**kwargs)
                    self.registered.set()
                    return result

                async def complete_workflow_task(self, **kwargs):
                    result = await super().complete_workflow_task(**kwargs)
                    self.completed += 1
                    return result

            for namespace in ('default', 'intake-limit'):
                async with (
                    Client('http://api:8080', token=TOKEN, namespace=namespace) as client,
                    ObservedClient(token='update-key-worker', namespace=namespace) as worker_client,
                ):
                    queue = os.environ['KEY_COHORT'] + '-' + namespace
                    worker = Worker(worker_client, task_queue=queue, workflows=[WaitingWorkflow],
                                    worker_id=queue, poll_timeout=1, heartbeat_interval=1,
                                    max_concurrent_workflow_tasks=1, shutdown_timeout=5)
                    running = asyncio.create_task(worker.run())
                    try:
                        await asyncio.wait_for(worker_client.registered.wait(), 8)
                        for kind in ('pending_update_count', 'payload_size'):
                            identity = queue + '-' + kind
                            expected = worker_client.completed + 1
                            handle = await client.start_workflow(
                                workflow_type='update-key-consumer', workflow_id=identity,
                                task_queue=queue, input=[], run_timeout_seconds=60, execution_timeout_seconds=120,
                            )
                            case = {'namespace': namespace, 'kind': kind, 'workflow_id': identity,
                                    'run_id': handle.run_id}
                            cases.append(case)
                            (ROOT / 'evidence/intake-admitted.json').write_text(json.dumps(cases))
                            async def completed():
                                while worker_client.completed < expected:
                                    assert not running.done(), 'worker stopped before the initial wait'
                                    await asyncio.sleep(0.02)
                            await asyncio.wait_for(completed(), 8)
                    finally:
                        await worker.stop()
                        await asyncio.wait_for(running, 8)
            for case in cases:
                if case['kind'] == 'pending_update_count':
                    response = await update(http, case, 'add', 'first-pending', 17, 'initial-proxy')
                    assert response.status_code == 202, (response.status_code, response.text)
                    case['first_receipt'] = response.json()
                response = await http.get('/api/workflows/' + case['workflow_id'], headers=headers(case['namespace']))
                response.raise_for_status()
                case['original'] = response.json()
                assert case['original']['run_id'] == case['run_id']
                assert case['original']['status'] == 'waiting'
                response = await http.get('/api/workflows/' + case['workflow_id'] + '/runs/' + case['run_id'] + '/history',
                                          headers=headers(case['namespace']))
                response.raise_for_status()
                case['history'] = response.json()['events']
            originals_path.write_text(json.dumps(cases, ensure_ascii=False, indent=2))
            print(json.dumps({'stage': stage, 'originals': len(cases), 'installed_hashes': checked,
                              'run_ids': [case['run_id'] for case in cases]}))
        else:
            cases = json.loads(originals_path.read_text())
            selected = cases if stage == 'retained' else [case for case in cases if case['kind'] == stage]
            assert len(selected) == (4 if stage == 'retained' else 2)
            results = []
            for case in selected:
                retained = stage == 'retained'
                response = await update(http, case, 'other' if retained else 'add', 'over-limit',
                                        99 if retained else 17, 'changed-proxy' if retained else 'original-proxy')
                assert response.status_code == 409, (response.status_code, response.text)
                result = response.json()
                assert result['reason'] == 'structural_limit_exceeded', result
                assert result['structural_limit_kind'] == case['kind'], result
                assert result['structural_limit_configured'] == 1, result
                assert result['structural_limit_value'] >= 1, result
                assert result['run_id'] == case['run_id'], result
                path = ROOT / 'evidence' / (case['namespace'] + '-' + case['kind'] + '.rejection.json')
                if retained:
                    original = json.loads(path.read_text())['receipt']
                    for field in ('reason', 'command_id', 'update_id', 'run_id', 'structural_limit_kind',
                                  'structural_limit_configured', 'structural_limit_value'):
                        assert result[field] == original[field], (field, result, original)
                else:
                    path.write_text(json.dumps({'receipt': result}, ensure_ascii=False, indent=2))
                response = await http.get('/api/workflows/' + case['workflow_id'], headers=headers(case['namespace']))
                response.raise_for_status()
                current = response.json()
                for field in ('status', 'run_id', 'run_deadline_at', 'execution_deadline_at'):
                    assert current[field] == case['original'][field], (field, current, case['original'])
                response = await http.get('/api/workflows/' + case['workflow_id'] + '/runs/' + case['run_id'] + '/history',
                                          headers=headers(case['namespace']))
                response.raise_for_status()
                events = response.json()['events']
                assert events[:len(case['history'])] == case['history']
                assert len(events) == len(case['history']) + 1
                assert events[-1]['event_type'] == 'UpdateRejected'
                if retained:
                    assert events == json.loads(path.read_text())['history']
                else:
                    path.write_text(json.dumps({'receipt': result, 'history': events}, ensure_ascii=False, indent=2))
                results.append({'run_id': case['run_id'], 'kind': case['kind'], 'namespace': case['namespace'],
                                'command_id': result['command_id'], 'update_id': result['update_id']})
            (ROOT / 'evidence' / (stage + '.json')).write_text(json.dumps(results, ensure_ascii=False, indent=2))
            print(json.dumps({'stage': stage, 'results': results, 'installed_hashes': checked}))


if __name__ == '__main__':
    selected_stage = sys.argv[1]
    if selected_stage.startswith('audit-'):
        audit(selected_stage)
    else:
        asyncio.run(asyncio.wait_for(business(selected_stage), timeout=30))
