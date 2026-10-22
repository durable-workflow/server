"""Run with both API and queue stopped, source bind read-only, /audit tmpfs."""

import datetime
import hashlib
import json
import os
import shutil
import sqlite3
import sys
from pathlib import Path

root = Path('/phase')
source = root / 'data/database.sqlite'
assert not Path(str(source) + '-wal').exists(), 'Stop and checkpoint SQLite before audit'
before_hash = hashlib.sha256(source.read_bytes()).hexdigest()
shutil.copyfile(source, '/audit/database.sqlite')
db = sqlite3.connect('file:/audit/database.sqlite?mode=ro', uri=True)
db.row_factory = sqlite3.Row
db.execute('PRAGMA query_only=ON')
tables = {}
for table in ('workflow_runs', 'workflow_commands', 'workflow_updates', 'workflow_history_events', 'workflow_tasks'):
    tables[table] = [dict(row) for row in db.execute('SELECT * FROM ' + table + ' ORDER BY id')]
assert len(tables['workflow_runs']) == 2
assert len(tables['workflow_updates']) == 2
for run in tables['workflow_runs']:
    assert run['status'] == 'completed', run
    start = datetime.datetime.fromisoformat(run['started_at'])
    assert (datetime.datetime.fromisoformat(run['run_deadline_at']) - start).total_seconds() == 60
    assert (datetime.datetime.fromisoformat(run['execution_deadline_at']) - start).total_seconds() == 120
assert all(row['status'] == 'completed' and row['repair_count'] == 0 for row in tables['workflow_tasks'])
assert all(row['status'] == 'completed' for row in tables['workflow_updates'])
assert db.execute('SELECT COUNT(*) FROM workflow_worker_registrations').fetchone()[0] == 0
db.close()
snapshot_path = root / 'evidence' / (os.environ['KEY_MODE'] + '-rows.json')
if sys.argv[1] == 'before':
    assert any(row.get('request_id') == 'legacy-caller-key' for row in tables['workflow_commands'])
    snapshot_path.write_text(json.dumps(tables, ensure_ascii=False, indent=2))
else:
    original = json.loads(snapshot_path.read_text())
    for table, rows in original.items():
        assert len(rows) == len(tables[table]), table
        for old, new in zip(rows, tables[table], strict=True):
            assert all(new[key] == value for key, value in old.items()), (table, old['id'])
assert hashlib.sha256(source.read_bytes()).hexdigest() == before_hash
print(json.dumps({'stage': sys.argv[1], 'mode': os.environ['KEY_MODE'], 'runs': 2,
                  'updates': 2, 'tasks': len(tables['workflow_tasks']),
                  'history_events': len(tables['workflow_history_events']),
                  'database_sha256': before_hash, 'unchanged_source_bytes': True}))
