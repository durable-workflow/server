// The fixture jobs pull their common pinned images with bounded retries before
// their first container (run 40164159608 lost 24 jobs to one "unknown blob").
import test from 'node:test';
import assert from 'node:assert/strict';
import {chmodSync, mkdtempSync, readFileSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {spawnSync} from 'node:child_process';

const workflow = readFileSync(resolve('.github/workflows/server-parity.yml'), 'utf8');
const script = resolve('scripts/ci/pull-images.sh');

function job(name) {
  const start = workflow.indexOf(`\n  ${name}:\n`);
  assert.ok(start >= 0, name);
  const next = workflow.slice(start + 1).search(/\n {2}[a-z0-9-]+:\n/);
  return next < 0 ? workflow.slice(start) : workflow.slice(start, start + 1 + next);
}

const pulls = {
  'published-sdk-adapters': [],
  'mysql-execution': ['"$MYSQL_IMAGE"', '"$(baseline php-2.5.14)"'],
  'postgres-storage': ['"$POSTGRES_IMAGE"', '"$(baseline php-2.5.14)"'],
  'execution-and-codec': ['"$(baseline php-2.5.14)"'],
};

test('each container-heavy fixture job pulls its pinned images first, with retries', () => {
  for (const [name, extra] of Object.entries(pulls)) {
    const nativePin = workflow.match(/^  NATIVE_RUST_IMAGE: (mirror\.gcr\.io\/library\/rust@sha256:[0-9a-f]{64})$/m)?.[1];
    assert.ok(nativePin, 'the shared native compiler must have an immutable pin');
    const block = job(name).replaceAll('${NATIVE_RUST_IMAGE}', nativePin);
    const steps = block.split(/\n {6}(?=- (?:name|uses):)/).slice(1);
    assert.match(steps[0], /^- uses: actions\/checkout@/, name);
    const pull = steps[1];
    assert.match(pull, /- name: Pull the pinned images, retrying registry errors\n/, name);
    assert.match(pull, /bash scripts\/ci\/pull-images\.sh \\\n/, name);
    assert.match(pull, /"\$\(baseline php-2\.11\.8\)"/, name);
    for (const image of extra) assert.ok(pull.includes(image), `${name} pulls ${image}`);
    // Every Rust image the job runs is in its pull list, so a digest bump
    // cannot leave the pull behind.
    const rust = new Set(block.match(/mirror\.gcr\.io\/library\/rust@sha256:[0-9a-f]{64}/g));
    assert.ok(rust.size > 0, name);
    for (const image of rust) assert.ok(pull.includes(image), `${name} pulls ${image}`);
  }
});

test('the workflow runs when the pull script changes', () => {
  assert.match(workflow, /\n {6}- scripts\/ci\/pull-images\.sh\n/);
});

// Each `docker pull` fails while the counter file says so; every call is logged.
const fakeDocker = `#!/usr/bin/env bash
dir=$(dirname "$0")
echo "$*" >> "$dir/calls"
test "$1" = pull || exit 3
remaining=$(cat "$dir/failures")
if ((remaining > 0)); then echo $((remaining - 1)) > "$dir/failures"; echo "unknown blob" >&2; exit 1; fi
`;

function run(failures, args, env = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'pull-images-'));
  writeFileSync(join(dir, 'docker'), fakeDocker);
  chmodSync(join(dir, 'docker'), 0o755);
  writeFileSync(join(dir, 'failures'), `${failures}\n`);
  writeFileSync(join(dir, 'calls'), '');
  const result = spawnSync('bash', [script, ...args], {
    encoding: 'utf8',
    env: {...process.env, PATH: `${dir}:${process.env.PATH}`, DW_PULL_BACKOFF_SECONDS: '0', ...env},
  });
  return {...result, calls: readFileSync(join(dir, 'calls'), 'utf8').trim().split('\n').filter(Boolean)};
}

test('a pull that fails retries until it succeeds', () => {
  const result = run(2, ['registry/a@sha256:1', 'registry/b@sha256:2']);
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(result.calls, [
    'pull --quiet registry/a@sha256:1', 'pull --quiet registry/a@sha256:1', 'pull --quiet registry/a@sha256:1',
    'pull --quiet registry/b@sha256:2',
  ]);
  assert.match(result.stderr, /pull of registry\/a@sha256:1 failed \(attempt 1 of 4\)/);
  assert.match(result.stdout, /pulled registry\/a@sha256:1 \(attempt 3\)\npulled registry\/b@sha256:2 \(attempt 1\)/);
});

test('a pull that keeps failing stops at the attempt bound and fails the step', () => {
  const result = run(99, ['registry/a@sha256:1', 'registry/b@sha256:2'], {DW_PULL_ATTEMPTS: '3'});
  assert.equal(result.status, 1);
  assert.equal(result.calls.length, 3);
  assert.match(result.stderr, /could not pull registry\/a@sha256:1 after 3 attempts/);
});

test('empty arguments are skipped and malformed bounds are refused', () => {
  assert.deepEqual(run(0, ['', 'registry/a@sha256:1', '']).calls, ['pull --quiet registry/a@sha256:1']);
  for (const env of [{DW_PULL_ATTEMPTS: '0'}, {DW_PULL_ATTEMPTS: 'x'}, {DW_PULL_BACKOFF_SECONDS: '-1'}]) {
    const result = run(0, ['registry/a@sha256:1'], env);
    assert.equal(result.status, 2, JSON.stringify(env));
    assert.deepEqual(result.calls, []);
  }
});
