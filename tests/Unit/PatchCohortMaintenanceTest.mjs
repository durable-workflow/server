// Server #721: the SQLite published-SDK patch cohort stops PHP's maintenance
// loop for its PHP record. Every failure path must leave the loop restored or
// fail loudly, and never record a restart that did not stay running.
import test from 'node:test';
import assert from 'node:assert/strict';
import {chmodSync, existsSync, mkdtempSync, readFileSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {spawnSync} from 'node:child_process';

const helper = resolve('scripts/conformance/server-parity/published-sdk-patch/maintenance.sh');

// One container's state in a file; every call is logged.
// SCENARIO=stop-nonzero: the stop takes effect but exits 1.
// SCENARIO=immediate-exit: start exits 0, the container runs for one
// inspection and then exits.
const fakeDocker = `#!/usr/bin/env bash
set -u
dir=$(dirname "$0")
echo "$*" >> "$dir/calls"
case "$1" in
  inspect)
    state=$(cat "$dir/state")
    if test "$state" = running-once; then echo exited > "$dir/state"; state=running; fi
    echo "$state" ;;
  stop) echo exited > "$dir/state"; test "$SCENARIO" != stop-nonzero ;;
  start)
    if test "$SCENARIO" = immediate-exit; then echo running-once > "$dir/state"; else echo running > "$dir/state"; fi ;;
  *) exit 2 ;;
esac
`;

function run(scenario, command) {
  const dir = mkdtempSync(join(tmpdir(), 'patch-maintenance-'));
  writeFileSync(join(dir, 'docker'), fakeDocker);
  chmodSync(join(dir, 'docker'), 0o755);
  writeFileSync(join(dir, 'state'), 'running\n');
  writeFileSync(join(dir, 'calls'), '');
  const result = spawnSync('bash', ['-c',
    `set -euo pipefail; . "$HELPER"; run_without_maintenance maint "$SCHEDULE" -- ${command}`], {
    encoding: 'utf8',
    env: {...process.env, PATH: `${dir}:${process.env.PATH}`, HELPER: helper,
      SCHEDULE: join(dir, 'schedule.txt'), SCENARIO: scenario, DW_PARITY_MAINTENANCE_POLL_SECONDS: '0.05'},
  });
  const read = name => (existsSync(join(dir, name)) ? readFileSync(join(dir, name), 'utf8') : '');
  const calls = read('calls').trim().split('\n');
  return {
    status: result.status,
    stderr: result.stderr,
    schedule: read('schedule.txt').trim().split('\n'),
    starts: calls.filter(call => call.startsWith('start ')).length,
    stopIndex: calls.findIndex(call => call.startsWith('stop ')),
    lastStart: calls.findLastIndex(call => call.startsWith('start ')),
    state: read('state').trim(),
  };
}

test('a normal window stops, runs, and restores a loop that stays running', () => {
  const result = run('normal', 'true');
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(result.schedule, ['before: running', 'during php record: exited', 'after php record: running for 0.05s x3']);
  assert.equal(result.starts, 1, 'restoration is disarmed after the sustained check');
  assert.equal(result.state, 'running');
});

test('a stop that takes effect but exits nonzero still restores', () => {
  const result = run('stop-nonzero', 'true');
  assert.notEqual(result.status, 0);
  assert.ok(result.lastStart > result.stopIndex, 'a start follows the stop');
  assert.equal(result.state, 'running');
  assert.deepEqual(result.schedule, ['before: running']);
});

test('a failed PHP record restores the loop', () => {
  const result = run('normal', 'false');
  assert.notEqual(result.status, 0);
  assert.equal(result.state, 'running');
  assert.ok(result.starts >= 1);
  assert.ok(!result.schedule.some(line => line.startsWith('after php record')));
});

test('a restart that exits at once stays armed and fails without recording running', () => {
  const result = run('immediate-exit', 'true');
  assert.notEqual(result.status, 0);
  assert.ok(result.schedule.includes('after php record: exited'), result.schedule.join('\n'));
  assert.ok(!result.schedule.some(line => line.startsWith('after php record: running')));
  // Three attempts after the record, then three more from the still-armed trap.
  assert.equal(result.starts, 6);
  assert.match(result.stderr, /did not stay running/);
});
