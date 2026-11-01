import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {chmodSync, copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import test from 'node:test';

const CELLS = new URL('../../scripts/conformance/server-parity/codec-deadline-cells.sh', import.meta.url).pathname;
const DATABASES = ['sqlite', 'postgres-16', 'postgres-17', 'mysql-8.0', 'mysql-8.4', 'mariadb-10.11'];

// Runs the cell runner in a scratch tree whose cell script is a stub with the
// real cell's lifecycle. It refuses to start while any cell's actors are live
// (exit 9), marks its server and database live, records its scope and
// arguments, leaves a state directory, and exits FAIL_<database> (default 0).
// Its EXIT trap is the real cell's cleanup() function, verbatim, so a refused
// stop behaves exactly as it would in the real cell. A fake docker keeps live
// actors as marker files: `stop` removes one, or exits 42 and keeps it when
// STOP_REFUSED names it; `inspect` reports a live actor running, a stopped one
// absent, and fails outright when INSPECT_FAILS is set.
const CELL = new URL('../../scripts/conformance/server-parity/codec-operations-cell.sh', import.meta.url).pathname;
const CLEANUP = /^cleanup\(\) \{\n[\s\S]*?^\}\n/m.exec(readFileSync(CELL, 'utf8'))[0];

function runCells(environment = {}) {
  const root = mkdtempSync(join(tmpdir(), 'dw-codec-cells-'));
  try {
    const scripts = join(root, 'scripts/conformance/server-parity');
    mkdirSync(scripts, {recursive: true});
    mkdirSync(join(root, 'live'));
    mkdirSync(join(root, 'bin'));
    copyFileSync(CELLS, join(scripts, 'codec-deadline-cells.sh'));
    writeFileSync(join(root, 'bin', 'docker'), `#!/usr/bin/env bash
command=$1; shift
name=\${!#}
case "$command" in
  logs) exit 0 ;;
  stop)
    if [[ " \${STOP_REFUSED:-} " == *" $name "* ]]; then exit 42; fi
    rm -f "$LIVE/$name" ;;
  inspect)
    if [[ -n "\${INSPECT_FAILS:-}" ]]; then echo "Cannot connect to the Docker daemon" >&2; exit 1; fi
    if [[ -e "$LIVE/$name" ]]; then echo true; exit 0; fi
    if [[ -n "\${LOWERCASE_ABSENT:-}" ]]; then echo "error: no such object: $name" >&2; else echo "Error: No such object: $name" >&2; fi
    exit 1 ;;
  *) exit 2 ;;
esac
`);
    chmodSync(join(root, 'bin', 'docker'), 0o755);
    writeFileSync(join(scripts, 'codec-operations-cell.sh'), `#!/usr/bin/env bash
set -euo pipefail
key="\${1//[.-]/_}"
if compgen -G "$LIVE/*" > /dev/null; then echo "overlap $1" >> "$EVENTS"; exit 9; fi
native_server="$RESOURCE_SCOPE-native"
db="$RESOURCE_SCOPE-db"
output="$5"
mkdir -p "$output/state"
${CLEANUP}trap cleanup EXIT
touch "$LIVE/$native_server" "$LIVE/$db"
echo "$RESOURCE_SCOPE $*" >> "$EVENTS"
[[ "$2" =~ ^[a-z0-9][a-z0-9-]{1,54}$ ]] || exit 2
fail="FAIL_$key"
exit "\${!fail:-0}"
`);
    const events = join(root, 'events.log');
    const child = spawnSync('bash', ['scripts/conformance/server-parity/codec-deadline-cells.sh'], {
      cwd: root, encoding: 'utf8', timeout: 20000,
      env: {...process.env, ...environment, PATH: `${join(root, 'bin')}:${process.env.PATH}`,
        EVENTS: events, LIVE: join(root, 'live'), RESOURCE_SCOPE: 'dw-codec-1-1-job'},
    });
    const statuses = Object.fromEntries(DATABASES.map(database => [database,
      readFileSync(join(root, 'codec-evidence', `${database}.status`), 'utf8').trim()]));
    const states = DATABASES.filter(database => existsSync(join(root, 'codec-evidence', database, 'state')));
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

// Run 41338240707 on #925: the Ubuntu 26 runner image's Docker CLI reports a
// removed container in lower case, which the absence check did not accept.
test("a removed container reported in lower case counts as stopped", () => {
  const {child, statuses, events} = runCells({LOWERCASE_ABSENT: '1'});
  assert.equal(child.status, 0, child.stderr);
  assert.deepEqual(events, DATABASES.map(started));
  assert.deepEqual(new Set(Object.values(statuses)), new Set(['0']));
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

// Cloud's review of #748: the real cleanup() ignores a refused `docker stop`.
// The runner must confirm the actors stopped before it removes the state or
// starts another cell; when it cannot, it keeps the state and halts.
test("a refused stop in the real cleanup keeps the cell's state and runs no later cell", () => {
  const {child, statuses, states, events} = runCells({FAIL_postgres_16: '3', STOP_REFUSED: 'dw-codec-1-1-job-postgres-16-db'});
  assert.equal(child.status, 1);
  assert.deepEqual(events, DATABASES.slice(0, 2).map(started), 'no later cell started, so none overlapped');
  assert.equal(statuses['postgres-16'], '3');
  for (const database of DATABASES.slice(2)) assert.equal(statuses[database], 'skipped after postgres-16', database);
  assert.deepEqual(states, ['postgres-16']);
  assert.match(child.stderr, /^::error::dw-codec-1-1-job-postgres-16-db is still running$/m);
  assert.match(child.stderr, /^::error::Deadline-health cell postgres-16 left its cleanup unconfirmed; keeping its state and running no later cell$/m);
});

test('a refused stop after a successful workload halts the same way', () => {
  const {child, statuses, states, events} = runCells({STOP_REFUSED: 'dw-codec-1-1-job-sqlite-native'});
  assert.equal(child.status, 1);
  assert.deepEqual(events, [started('sqlite')]);
  assert.equal(statuses.sqlite, '0');
  assert.deepEqual(states, ['sqlite']);
});

test('a cleanup the runner cannot inspect halts rather than assuming it stopped', () => {
  const {child, statuses, states, events} = runCells({INSPECT_FAILS: '1'});
  assert.equal(child.status, 1);
  assert.deepEqual(events, [started('sqlite')]);
  assert.deepEqual(states, ['sqlite']);
  assert.equal(statuses['postgres-16'], 'skipped after sqlite');
  assert.match(child.stderr, /^::error::Could not confirm that dw-codec-1-1-job-sqlite-native stopped: Cannot connect to the Docker daemon$/m);
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
