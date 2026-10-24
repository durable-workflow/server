// The checks a GitHub merge queue waits for must run on merge_group, and
// queue branches must not be scanned twice. Source-only: enabling the queue
// is a separate repository setting.
import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const workflow = name => readFileSync(`.github/workflows/${name}`, 'utf8');
// The top-level `on:` block, up to the next top-level key.
const triggers = text => {
  const start = text.indexOf('\non:\n');
  assert.notEqual(start, -1, 'workflow has an on: block');
  const rest = text.slice(start + 5);
  const end = rest.search(/\n[a-z_-]+:/);
  return end === -1 ? rest : rest.slice(0, end);
};

for (const name of ['phpunit-feature.yml', 'server-parity.yml', 'sonar.yml', 'public-boundary.yml']) {
  test(`${name} runs for merge queue groups`, () => {
    assert.match(triggers(workflow(name)), /^ {2}merge_group:\s*$/m);
  });
}

test('PHPUnit Feature diffs a queue group against its base', () => {
  const text = workflow('phpunit-feature.yml');
  const bases = text.match(/github\.event_name == 'merge_group' && github\.event\.merge_group\.base_sha/g) ?? [];
  assert.equal(bases.length, 3, 'OpenAPI and both corpus base revisions');
  assert.match(text, /github\.event\.pull_request\.base\.sha \|\| github\.event\.merge_group\.base_sha \|\| github\.event\.before/);
});

test('Public Boundary scans a queue group over its exact range, once', () => {
  const text = workflow('public-boundary.yml');
  assert.match(text, /"merge_group" \]\]; then\n\s+range="\$\{\{ github\.event\.merge_group\.base_sha \}\}\.\.\$\{\{ github\.event\.merge_group\.head_sha \}\}"/);
  assert.match(triggers(text), /^ {2}push:\n {4}branches-ignore:\n {6}- gh-readonly-queue\/\*\*$/m);
});

test('superseded-run cancellation never cancels a queue group', () => {
  for (const name of ['phpunit-feature.yml', 'server-parity.yml', 'sonar.yml']) {
    assert.match(workflow(name), /cancel-in-progress: \$\{\{ github\.event_name == 'pull_request' \}\}/, name);
  }
});
