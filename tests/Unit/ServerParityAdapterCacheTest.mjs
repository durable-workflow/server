// The published SDK adapters' Cargo registry and build tree are cached
// between runs, but only pushes to main save them: a pull request or merge
// group can restore main's cache and never write one a later run reads.
import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

const workflow = readFileSync(resolve('.github/workflows/server-parity.yml'), 'utf8');

function job(name) {
  const start = workflow.indexOf(`\n  ${name}:\n`);
  assert.ok(start >= 0, name);
  const next = workflow.slice(start + 1).search(/\n {2}[a-z0-9-]+:\n/);
  return next < 0 ? workflow.slice(start) : workflow.slice(start, start + 1 + next);
}

const steps = block => block.split(/\n {6}(?=- (?:name|uses):)/).slice(1);

test('only the adapter job caches, and only the Cargo registry and build tree', () => {
  const uses = workflow.match(/uses: actions\/cache[^\s]*/g) ?? [];
  assert.deepEqual(uses, [
    'uses: actions/cache/restore@55cc8345863c7cc4c66a329aec7e433d2d1c52a9',
    'uses: actions/cache/save@55cc8345863c7cc4c66a329aec7e433d2d1c52a9',
  ]);
  const adapters = steps(job('published-sdk-adapters'));
  const restore = adapters.findIndex(step => step.includes('actions/cache/restore@'));
  const save = adapters.findIndex(step => step.includes('actions/cache/save@'));
  const builds = adapters.map((step, index) => [step, index]).filter(([step]) => /-adapters\.sh\n?$/m.test(step)).map(([, index]) => index);
  assert.equal(builds.length, 5);
  assert.ok(restore >= 0 && restore < Math.min(...builds), 'restored before the first build');
  assert.ok(save > Math.max(...builds), 'saved after the last build');
  for (const index of [restore, save]) {
    assert.match(adapters[index], /path: \|\n {12}sdk-cargo\n {12}sdk-target\n/);
  }
  // No restore-keys: a run gets this exact key's tree or builds from nothing.
  assert.doesNotMatch(adapters[restore], /restore-keys/);
  assert.match(adapters[restore], /key: sdk-adapter-cargo-v1-rust-[0-9a-f]{64}-\$\{\{ hashFiles\(/);
  assert.match(adapters[save], /if: \$\{\{ github\.event_name == 'push' && github\.ref == 'refs\/heads\/main' && /);
});

test('the cache key follows the adapter build image and locks', () => {
  const block = job('published-sdk-adapters');
  const [image] = block.match(/mirror\.gcr\.io\/library\/rust@sha256:([0-9a-f]{64})/).slice(1);
  assert.ok(block.includes(`key: sdk-adapter-cargo-v1-rust-${image}-`), 'key names the pulled Rust image');
  for (const input of ["'scripts/conformance/server-parity/*/rust/Cargo.lock'", "'scripts/conformance/server-parity/*/rust/Cargo.toml'", "'scripts/conformance/server-parity/*-adapters.sh'"]) {
    assert.ok(block.includes(input), input);
  }
});
