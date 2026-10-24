import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import test from 'node:test';

const CELLS = new URL('../../scripts/conformance/server-parity/codec-deadline-cells.sh', import.meta.url).pathname;
const DATABASES = ['sqlite', 'postgres-16', 'postgres-17', 'mysql-8.0', 'mysql-8.4', 'mariadb-10.11'];

// Runs the cell runner in a scratch tree whose cell script is a stub: it
// records its scope and arguments, leaves a state directory, and exits
// FAIL_<database> (default 0).
function runCells(environment = {}) {
  const root = mkdtempSync(join(tmpdir(), 'dw-codec-cells-'));
  try {
    const scripts = join(root, 'scripts/conformance/server-parity');
    mkdirSync(scripts, {recursive: true});
    copyFileSync(CELLS, join(scripts, 'codec-deadline-cells.sh'));
    writeFileSync(join(scripts, 'codec-operations-cell.sh'), `#!/usr/bin/env bash
set -euo pipefail
echo "$RESOURCE_SCOPE $*" >> "$EVENTS"
mkdir -p "$5/state"
fail="FAIL_\${1//[.-]/_}"
exit "\${!fail:-0}"
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

test('every cell runs once, in its own scope, and leaves no state behind', () => {
  const {child, statuses, states, events} = runCells();
  assert.equal(child.status, 0, child.stderr);
  assert.deepEqual(events, DATABASES.map(database =>
    `dw-codec-1-1-job-${database} ${database} cpu-${database} codec-native-build corrected-marker-adapters/php codec-evidence/${database}`));
  assert.deepEqual(new Set(Object.values(statuses)), new Set(['0']));
  assert.deepEqual(states, []);
});

test('a failed cell still lets every later cell run, and fails the runner after all of them', () => {
  const {child, statuses, events} = runCells({FAIL_postgres_16: '3'});
  assert.equal(child.status, 1);
  assert.equal(events.length, DATABASES.length);
  assert.equal(statuses['postgres-16'], '3');
  assert.match(child.stderr, /^::error::Deadline-health cell postgres-16 exited 3$/m);
  assert.equal(child.stderr.match(/::error::/g).length, 1);
});

test('the runner refuses to start without an owning resource scope', () => {
  const child = spawnSync('bash', [CELLS], {encoding: 'utf8', cwd: tmpdir(), env: {...process.env, RESOURCE_SCOPE: ''}});
  assert.equal(child.status, 2);
  assert.match(child.stderr, /Set the owning isolated resource scope/);
});
