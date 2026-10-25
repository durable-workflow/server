// Server #754: the SQLite job's separate update-validation record retries the
// published PHP record once, and only when that record failed after PHP
// logged "database is locked". Native and embedded records never retry.
import test from 'node:test';
import assert from 'node:assert/strict';
import {chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, symlinkSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {spawnSync} from 'node:child_process';

const root = resolve('.');

// Records follow OUTCOMES, one "<target><attempt>=<outcome>" per record
// (default pass). fail fails without a lock error; fail-locked fails after PHP
// logs one during the record; fail-lockbefore and fail-lockafter fail with
// one logged 5 s before or after it. `docker logs` honors --since and --until.
const fakeDocker = `#!/usr/bin/env bash
set -u
dir=$(cd "$(dirname "$0")/.." && pwd)
echo "$*" >> "$dir/calls"
case "$1" in
  inspect) cat "$dir/state" ;;
  stop) echo exited > "$dir/state" ;;
  start) echo running > "$dir/state" ;;
  logs)
    test "\${!#}" = dw-parity-7-2-sqlite-server || exit 3
    since=0 until=99999999999999999999
    while (($# > 1)); do
      case "$1" in
        --since) since=$(date -d "$2" +%s%N); shift ;;
        --until) until=$(date -d "$2" +%s%N); shift ;;
      esac
      shift
    done
    touch "$dir/serverlog"
    while read -r at line; do
      if ((at >= since && at <= until)); then echo "$line"; fi
    done < "$dir/serverlog" ;;
  run)
    command= target= output= prefix=
    while (($#)); do
      case "$1" in
        record | compare) command=$1 ;;
        --target) target=$2; shift ;;
        --output) output=$2; shift ;;
        --prefix) prefix=$2; shift ;;
      esac
      shift
    done
    if test "$command" = compare; then echo '{"outcome":"pass"}'; exit 0; fi
    attempt=$(( $(cat "$dir/count-$target" 2>/dev/null || echo 0) + 1 ))
    echo "$attempt" > "$dir/count-$target"
    echo "$target $prefix" >> "$dir/records"
    outcome=pass
    for entry in \${OUTCOMES:-}; do
      if test "\${entry%%=*}" = "$target$attempt"; then outcome=\${entry#*=}; fi
    done
    file="$dir/parity-evidence/\${output##*/}"
    lock="production.ERROR: SQLSTATE[HY000]: General error: 5 database is locked"
    echo "$(date +%s%N) 172.18.0.4 - - POST /api/worker/update-validation-tasks/poll 200" >> "$dir/serverlog"
    case "$outcome" in
      pass) echo '{"outcome":"pass"}' > "$file"; exit 0 ;;
      fail-locked) echo "$(date +%s%N) $lock" >> "$dir/serverlog" ;;
      fail-lockbefore) echo "$(( $(date +%s%N) - 5000000000 )) $lock" >> "$dir/serverlog" ;;
      fail-lockafter) echo "$(( $(date +%s%N) + 5000000000 )) $lock" >> "$dir/serverlog" ;;
    esac
    echo '{"outcome":"product-fail"}' > "$file"
    echo "== update-validation (product-fail): update validation lost_approve" > "\${file%.json}.failure-excerpt.txt"
    exit 1 ;;
  *) exit 2 ;;
esac
`;

function run(outcomes) {
  const dir = mkdtempSync(join(tmpdir(), 'update-validation-retry-'));
  for (const name of ['scripts', 'tests']) symlinkSync(join(root, name), join(dir, name));
  for (const name of ['bin', 'parity-evidence', 'parity-state']) mkdirSync(join(dir, name));
  writeFileSync(join(dir, 'bin', 'docker'), fakeDocker);
  writeFileSync(join(dir, 'bin', 'jq'), '#!/bin/sh\necho fake-image\n');
  for (const tool of ['docker', 'jq']) chmodSync(join(dir, 'bin', tool), 0o755);
  writeFileSync(join(dir, 'state'), 'running\n');
  writeFileSync(join(dir, 'records'), '');
  const result = spawnSync('bash', ['scripts/conformance/server-parity/update-validation-sqlite.sh', 'http://parity-server:8080', 'http://parity-rust:8080'], {
    cwd: dir, encoding: 'utf8',
    env: {...process.env, PATH: `${join(dir, 'bin')}:${process.env.PATH}`, OUTCOMES: outcomes,
      RESOURCE_SCOPE: 'dw-parity-7-2-sqlite', GITHUB_RUN_ID: '7', GITHUB_RUN_ATTEMPT: '2', GITHUB_JOB: 'sqlite',
      DW_PARITY_RUNNER_REVISION: 'a'.repeat(40), DW_PARITY_MAINTENANCE_POLL_SECONDS: '0'},
  });
  const read = name => readFileSync(join(dir, name), 'utf8');
  return {
    status: result.status, stdout: result.stdout, stderr: result.stderr,
    records: read('records').trim().split('\n').filter(Boolean),
    state: read('state').trim(),
    evidence: name => existsSync(join(dir, 'parity-evidence', name)),
    read: name => read(join('parity-evidence', name)),
  };
}

test('a passing PHP record runs every target once under the usual prefix', () => {
  const result = run('');
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(result.records, ['php parity-v1', 'embedded parity-v1', 'rust parity-v1']);
  assert.doesNotMatch(result.stdout, /::warning/);
  assert.equal(result.evidence('update-validation-php.attempt-1.json'), false);
  assert.equal(result.evidence('update-validation-maintenance-schedule-retry.txt'), false);
  assert.equal(result.state, 'running');
});

test('a PHP record that lost a write to the SQLite lock runs once more, and every target takes the new prefix', () => {
  const result = run('php1=fail-locked');
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(result.records, ['php parity-v1', 'php parity-v1-retry', 'embedded parity-v1-retry', 'rust parity-v1-retry']);
  assert.match(result.stdout, /^::warning title=Published PHP update-validation record retried::The PHP record failed \(== update-validation \(product-fail\): update validation lost_approve \) after published PHP logged 'database is locked'/m);
  // The first attempt's record, excerpt and lock lines stay as evidence.
  assert.match(result.read('update-validation-php.attempt-1.json'), /product-fail/);
  assert.match(result.read('update-validation-php.attempt-1.failure-excerpt.txt'), /lost_approve/);
  assert.match(result.read('update-validation-php.attempt-1.locked.txt'), /database is locked/);
  assert.match(result.read('update-validation-php.json'), /"pass"/);
  assert.equal(result.evidence('update-validation-php.failure-excerpt.txt'), false);
  // Both PHP records ran with maintenance stopped, and it is running again.
  for (const schedule of ['update-validation-maintenance-schedule.txt', 'update-validation-maintenance-schedule-retry.txt']) {
    assert.match(result.read(schedule), /^during php record: exited$/m, schedule);
    assert.match(result.read(schedule), /^after php record: running/m, schedule);
  }
  assert.equal(result.state, 'running');
});

test('a PHP failure without the lock error during the record fails at once', () => {
  // No lock error at all, or one PHP logged only before or after the record.
  for (const outcome of ['fail', 'fail-lockbefore', 'fail-lockafter']) {
    const result = run(`php1=${outcome}`);
    assert.equal(result.status, 1, outcome);
    assert.deepEqual(result.records, ['php parity-v1'], outcome);
    assert.doesNotMatch(result.stdout, /::warning/, outcome);
    assert.equal(result.evidence('update-validation-php.attempt-1.locked.txt'), false, outcome);
    assert.match(result.read('update-validation-php.failure-excerpt.txt'), /lost_approve/, outcome);
    assert.equal(result.state, 'running', outcome);
  }
});

test('a PHP retry that fails again fails the record', () => {
  const result = run('php1=fail-locked php2=fail-locked');
  assert.equal(result.status, 1);
  assert.deepEqual(result.records, ['php parity-v1', 'php parity-v1-retry']);
  assert.equal(result.state, 'running');
});

test('a native or embedded failure is never retried, even after a PHP retry', () => {
  for (const [outcomes, records] of [
    ['rust1=fail-locked', ['php parity-v1', 'embedded parity-v1', 'rust parity-v1']],
    ['embedded1=fail', ['php parity-v1', 'embedded parity-v1']],
    ['php1=fail-locked rust1=fail', ['php parity-v1', 'php parity-v1-retry', 'embedded parity-v1-retry', 'rust parity-v1-retry']],
  ]) {
    const result = run(outcomes);
    assert.equal(result.status, 1, outcomes);
    assert.deepEqual(result.records, records, outcomes);
  }
});
