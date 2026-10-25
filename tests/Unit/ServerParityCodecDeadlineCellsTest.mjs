import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import test from 'node:test';

const CELLS = new URL('../../scripts/conformance/server-parity/codec-deadline-cells.sh', import.meta.url).pathname;
const DATABASES = ['sqlite', 'postgres-16', 'postgres-17', 'mysql-8.0', 'mysql-8.4', 'mariadb-10.11'];

// Runs the cell runner in a scratch tree whose cell script is a stub that
// behaves like the real cell's lifecycle. It refuses to start while another
// cell's actors are live (exit 9), marks its own actors live, records its
// scope and arguments, leaves a state directory, and exits FAIL_<database>
// (default 0). Its EXIT trap stops its actors, unless KEEP_LIVE_<database>
// simulates a cell that leaves them running.
const CELL = new URL('../../scripts/conformance/server-parity/codec-operations-cell.sh', import.meta.url).pathname;

function runCells(environment = {}) {
  const root = mkdtempSync(join(tmpdir(), 'dw-codec-cells-'));
  try {
    const scripts = join(root, 'scripts/conformance/server-parity');
    mkdirSync(scripts, {recursive: true});
    mkdirSync(join(root, 'live'));
    copyFileSync(CELLS, join(scripts, 'codec-deadline-cells.sh'));
    writeFileSync(join(scripts, 'codec-operations-cell.sh'), `#!/usr/bin/env bash
set -euo pipefail
key="\${1//[.-]/_}"
if compgen -G "$LIVE/*" > /dev/null; then echo "overlap $1" >> "$EVENTS"; exit 9; fi
keep="KEEP_LIVE_$key"
stop() { [[ -n "\${!keep:-}" ]] || rm -f "$LIVE/$1"; }
trap 'stop "$1"' EXIT
touch "$LIVE/$1"
echo "$RESOURCE_SCOPE $*" >> "$EVENTS"
[[ "$2" =~ ^[a-z0-9][a-z0-9-]{1,54}$ ]] || exit 2
mkdir -p "$5/state"
fail="FAIL_$key"
exit "\${!fail:-0}"
`);
    const events = join(root, 'events.log');
    const child = spawnSync('bash', ['scripts/conformance/server-parity/codec-deadline-cells.sh'], {
      cwd: root, encoding: 'utf8', timeout: 20000,
      env: {...process.env, ...environment, EVENTS: events, LIVE: join(root, 'live'), RESOURCE_SCOPE: 'dw-codec-1-1-job'},
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

const started = database =>
  `dw-codec-1-1-job-${database} ${database} cpu-${database.replaceAll('.', '-')} codec-native-build corrected-marker-adapters/php codec-evidence/${database}`;

test('every cell runs once, in its own scope, after the previous one stopped, and leaves no state behind', () => {
  const {child, statuses, states, events} = runCells();
  assert.equal(child.status, 0, child.stderr);
  assert.deepEqual(events, DATABASES.map(started));
  assert.deepEqual(new Set(Object.values(statuses)), new Set(['0']));
  assert.deepEqual(states, []);
});

// Run 40110569900 on #748: the MySQL-family cells exited 2 because their
// unique scopes (cpu-mysql-8.0, ...) held a dot the cell refuses.
test('every cell gets a unique scope the cell accepts', () => {
  const {statuses} = runCells();
  assert.deepEqual(statuses, Object.fromEntries(DATABASES.map(database => [database, '0'])));
});

test('a failed cell stops its actors, still lets every later cell run, and fails the runner after all of them', () => {
  const {child, statuses, events} = runCells({FAIL_postgres_16: '3'});
  assert.equal(child.status, 1);
  assert.deepEqual(events, DATABASES.map(started));
  assert.equal(statuses['postgres-16'], '3');
  assert.match(child.stderr, /^::error::Deadline-health cell postgres-16 exited 3$/m);
  assert.equal(child.stderr.match(/::error::/g).length, 1);
});

// Counterfactual: a failed cell that left its actors running would overlap
// the next cell, which refuses to start.
test('a failed cell that kept its actors live is caught by the next cell', () => {
  const {child, statuses, events} = runCells({FAIL_postgres_16: '3', KEEP_LIVE_postgres_16: '1'});
  assert.equal(child.status, 1);
  assert.equal(statuses['postgres-17'], '9');
  assert.ok(events.includes('overlap postgres-17'), events.join('\n'));
});

// The real cell's exit trap stops both long-lived actors by their exact
// names, harmlessly when they never started, and is installed before the
// first container starts.
test('the real cell stops its server and database on every exit', () => {
  const source = readFileSync(CELL, 'utf8');
  const trap = /^cleanup\(\) \{\n([\s\S]*?)^\}\ntrap cleanup EXIT$/m.exec(source);
  assert.ok(trap, 'cleanup trap');
  assert.match(trap[1], /^ {2}docker stop --time 10 "\$native_server" >\/dev\/null 2>&1 \|\| true$/m);
  assert.match(trap[1], /^ {2}docker stop --time 10 "\$db" >\/dev\/null 2>&1 \|\| true$/m);
  assert.ok(source.indexOf('trap cleanup EXIT') < source.indexOf('docker run'), 'trap before the first container');
  assert.doesNotMatch(source, /codec-operations-cleanup\.sh/);
});

test('the runner refuses to start without an owning resource scope', () => {
  const child = spawnSync('bash', [CELLS], {encoding: 'utf8', cwd: tmpdir(), env: {...process.env, RESOURCE_SCOPE: ''}});
  assert.equal(child.status, 2);
  assert.match(child.stderr, /Set the owning isolated resource scope/);
});
