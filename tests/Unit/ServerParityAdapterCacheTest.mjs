// The published SDK adapters' public Cargo registry data and build tree are
// cached between runs, but only successful pushes to main save them: a pull
// request or merge group can restore main's cache and never write one a
// later run reads. The key covers the toolchain, the runner and every
// tracked adapter input, and an empty input list fails the job.
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

test('only the adapter job caches, and only public registry data and the build tree', () => {
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
    // Never the whole CARGO_HOME, where credentials would live.
    assert.match(adapters[index], /path: \|\n {12}sdk-cargo\/registry\n {12}sdk-cargo\/git\n {12}sdk-target\n/);
    assert.match(adapters[index], /key: \$\{\{ steps\.adapter_key\.outputs\.key \}\}$/m);
  }
  // No restore-keys: a run gets this exact key's tree or builds from nothing.
  assert.doesNotMatch(adapters[restore], /restore-keys/);
  assert.match(adapters[save], /if: \$\{\{ success\(\) && github\.event_name == 'push' && github\.ref == 'refs\/heads\/main' && /);
});

test('the cache key follows the toolchain, the runner and every tracked adapter input', () => {
  const block = job('published-sdk-adapters');
  const key = steps(block).find(step => step.includes('id: adapter_key'));
  assert.ok(key, 'a key step');
  const [image] = block.match(/mirror\.gcr\.io\/library\/rust@sha256:([0-9a-f]{64})/).slice(1);
  assert.ok(key.includes('sdk-adapter-cargo-v1-${RUNNER_OS}-${RUNNER_ARCH}-rust-' + image + '-${digest}'), 'key names the runner and the pulled Rust image');
  assert.match(key, /git ls-files --/);
  for (const adapter of ['published-sdk-patch', 'sdk-directions', 'legacy-marker-aliases', 'corrected-markers', 'query-authority']) {
    assert.ok(key.includes(`scripts/conformance/server-parity/${adapter} \\\n`), adapter);
  }
  assert.ok(key.includes("':(glob)scripts/conformance/server-parity/*-adapters.sh'"));
  // An empty or partial input list fails instead of keying a cache.
  assert.match(key, /grep -c '\/rust\/Cargo\.lock\$'\)" -eq 5/);
  assert.match(key, /grep -c -- '-adapters\.sh\$'\)" -eq 5/);
  // The five build scripts are the job's adapter build steps.
  const builds = steps(block).filter(step => /-adapters\.sh\n?$/m.test(step));
  assert.equal(builds.length, 5);
});
