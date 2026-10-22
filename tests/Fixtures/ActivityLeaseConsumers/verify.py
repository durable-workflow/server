import json
from pathlib import Path

root = Path('/evidence')
expected = [9007199254740993, None, 'λ']


def load(path):
    return json.loads((root / path).read_text())


def run_rows(snapshot, table, run_id):
    return [row for row in snapshot[table] if row['workflow_run_id'] == run_id]


def verify(language, folder, mode, run_id, result_name, prefix_name, owner_snapshots=None):
    long_running = mode in ('long', 'cooperative')
    snapshot = load(('long' if long_running else 'loss') + '-final.json')
    prefix = load(prefix_name)
    result = load(folder + '/' + result_name)
    assert result['run_id'] == run_id, result
    assert result['result'] == expected, result
    elapsed = result['elapsed_seconds']
    if long_running:
        assert 360 <= elapsed < 420, elapsed
    else:
        assert elapsed < 30, elapsed
    run = next(row for row in snapshot['workflow_runs'] if row['id'] == run_id)
    old_run = next(row for row in prefix['workflow_runs'] if row['id'] == run_id)
    assert run['status'] == 'completed', run
    assert run['namespace'] == ('default' if long_running else 'lease-loss')
    for key in ('started_at', 'run_timeout_seconds', 'run_deadline_at', 'execution_deadline_at', 'arguments'):
        assert run[key] == old_run[key], (key, run[key], old_run[key])
    assert run['run_timeout_seconds'] == 420
    tasks = run_rows(snapshot, 'workflow_tasks', run_id)
    activities = run_rows(snapshot, 'activity_executions', run_id)
    attempts = sorted(run_rows(snapshot, 'activity_attempts', run_id), key=lambda row: row['attempt_number'])
    events = run_rows(snapshot, 'workflow_history_events', run_id)
    invocations = [json.loads(line) for line in (root / folder / 'invocations.jsonl').read_text().splitlines()]
    count = 1 if long_running else 2
    assert len(activities) == 1 and activities[0]['status'] == 'completed'
    assert activities[0]['attempt_count'] == count
    assert activities[0]['last_heartbeat_at'] is None
    assert len(attempts) == count and [row['attempt_number'] for row in attempts] == list(range(1, count + 1))
    assert [row['status'] for row in attempts] == (['completed'] if long_running else ['expired', 'completed'])
    assert all(row['last_heartbeat_at'] is None for row in attempts)
    assert len({row['activity_execution_id'] for row in attempts}) == 1
    assert [row['attempt'] for row in invocations] == list(range(1, count + 1))
    assert [row['activity_attempt_id'] for row in invocations] == [row['id'] for row in attempts]
    task_ids = {row['id'] for row in tasks}
    assert all(row['task_id'] in task_ids for row in invocations)
    assert len(tasks) == (3 if long_running else 4)
    assert all(row['repair_count'] == 0 for row in tasks)
    assert all(row['attempt_count'] == 1 for row in tasks if row['task_type'] == 'workflow')
    by_task = {row['id']: row for row in tasks}
    assert all(by_task[row['task_id']]['attempt_count'] == row['attempt'] for row in invocations)
    assert all(row['status'] == 'completed' for row in tasks if row['id'] != invocations[0]['task_id'] or long_running)
    counts = {}
    for row in events:
        counts[row['event_type']] = counts.get(row['event_type'], 0) + 1
    assert counts.get('ActivityStarted') == count
    assert counts.get('ActivityCompleted') == 1 and counts.get('WorkflowCompleted') == 1
    assert counts.get('ActivityHeartbeatRecorded', 0) == 0
    by_id = {row['id']: row for row in events}
    preserved = run_rows(prefix, 'workflow_history_events', run_id)
    assert all(by_id[row['id']] == row for row in preserved)
    assert snapshot['failed_jobs'] == 0
    assert snapshot['read_only_sha256_before'] == snapshot['read_only_sha256_after']
    if mode == 'loss':
        assert isinstance(owner_snapshots, list) and len(owner_snapshots) == 2
        first, second = [load(path) for path in owner_snapshots]
        for table, identity in (('workflow_tasks', invocations[0]['task_id']),
                                ('activity_attempts', invocations[0]['activity_attempt_id'])):
            before = next(row for row in first[table] if row['id'] == identity)
            after = next(row for row in second[table] if row['id'] == identity)
            assert before['lease_expires_at'] is not None
            assert before['lease_expires_at'] == after['lease_expires_at'], (table, before, after)
        retries = [json.loads(row['payload']) for row in events if row['event_type'] == 'ActivityRetryScheduled']
        assert len(retries) == 1 and retries[0]['failure_reason'] == 'lease_expired'
        assert retries[0]['retry_of_task_id'] == invocations[0]['task_id']
        assert retries[0]['retry_task_id'] == invocations[1]['task_id']
        assert retries[0]['retry_policy']['max_attempts'] == 2
    return {'language': language, 'mode': mode, 'run_id': run_id, 'elapsed_seconds': elapsed,
            'tasks': len(tasks), 'attempts': count, 'events': counts,
            'preserved_history_rows': len(preserved), 'application_heartbeats': 0, 'task_repairs': 0}


cohort = load('cohort.json')
print(json.dumps([verify(**row) for row in cohort], ensure_ascii=False, indent=2))
