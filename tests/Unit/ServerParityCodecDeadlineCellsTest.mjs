import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import test from 'node:test';

const CELLS = new URL('../../scripts/conformance/server-parity/codec-deadline-cells.sh', import.meta.url).pathname;
const DATABASES = ['sqlite', 'postgres-16', 'postgres-17', 'mysql-8.0', 'mysql-8.4', 'mariadb-10.11'];

// Runs the cell runner in a scratch tree whose cell and cleanup scripts are
// stubs. The cell records its scope and arguments, refuses a unique scope the
// real cell refuses, leaves a state directory and exits FAIL_<database>
// (default 0). The cleanup records the scope it retires.
function runCells(environment = {}) {
  const root = mkdtempSync(join(tmpdir(), 'dw-codec-cells-'));
  try {
    const scripts = join(root, 'scripts/conformance/server-parity');
    mkdirSync(scripts, {recursive: true});
    copyFileSync(CELLS, join(scripts, 'codec-deadline-cells.sh'));
    writeFileSync(join(scripts, 'codec-operations-cell.sh'), `#!/usr/bin/env bash
set -euo pipefail
echo "$RESOURCE_SCOPE $*" >> "$EVENTS"
[[ "$2" =~ ^[a-z0-9][a-z0-9-]{1,54}$ ]] || exit 2
mkdir -p "$5/state"
fail="FAIL_\${1//[.-]/_}"
exit "\${!fail:-0}"
`);
    writeFileSync(join(scripts, 'codec-operations-cleanup.sh'), `#!/usr/bin/env bash
set -euo pipefail
echo "cleanup $1 $RESOURCE_SCOPE" >> "$EVENTS"
`);
    const events = join(root, 'events.log');
    const child = spawnSync('bash', ['scripts/conformance/server-parity/codec-deadline-cells.sh'], {
      cwd: root, encoding: 'utf8', timeout: 20000,
      env: {...process.env, ...environment, EVENTS: events, RESOURCE_SCOPE: 'dw-codec-1-1-job'},
    });
    const statuses = Object.fromEntries(DATABASES.map(database => [database,
      readFileSync(join(root, 'codec-evidence', `${database}.status`), 'utf8').trim()]));
    const states = DATABASES.filter(database => {
      try { readFileSync(join(root, 'codec-evidence', database, 'state')); return true; } catch (error) { return error.code !== 'ENOENT'; }
    });
    return {child, statuses, states, events: readFileSync(events, 'utf8').trim().split('\n')};
  } finally {
    rmSync(root, {recursive: true, force: true});
  }
}

// Each cell, then its own cleanup, before the next cell starts.
const sequence = () => DATABASES.flatMap(database => [
  `dw-codec-1-1-job-${database} ${database} cpu-${database.replaceAll('.', '-')} codec-native-build corrected-marker-adapters/php codec-evidence/${database}`,
  `cleanup cell dw-codec-1-1-job-${database}`,
]);

test('every cell runs once, in its own scope, is retired before the next, and leaves no state behind', () => {
  const {child, statuses, states, events} = runCells();
  assert.equal(child.status, 0, child.stderr);
  assert.deepEqual(events, sequence());
  assert.deepEqual(new Set(Object.values(statuses)), new Set(['0']));
  assert.deepEqual(states, []);
});

// Run 40110569900 on #748: the MySQL-family cells exited 2 because their
// unique scopes (cpu-mysql-8.0, ...) held a dot the cell refuses.
test('every cell gets a unique scope the cell accepts', () => {
  const {statuses} = runCells();
  assert.deepEqual(statuses, Object.fromEntries(DATABASES.map(database => [database, '0'])));
});

test('a failed cell is retired, still lets every later cell run, and fails the runner after all of them', () => {
  const {child, statuses, events} = runCells({FAIL_postgres_16: '3'});
  assert.equal(child.status, 1);
  assert.deepEqual(events, sequence());
  assert.equal(statuses['postgres-16'], '3');
  assert.match(child.stderr, /^::error::Deadline-health cell postgres-16 exited 3$/m);
  assert.equal(child.stderr.match(/::error::/g).length, 1);
});

test('the runner refuses to start without an owning resource scope', () => {
  const child = spawnSync('bash', [CELLS], {encoding: 'utf8', cwd: tmpdir(), env: {...process.env, RESOURCE_SCOPE: ''}});
  assert.equal(child.status, 2);
  assert.match(child.stderr, /Set the owning isolated resource scope/);
});
