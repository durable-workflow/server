import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkChildVisibility} from '../../scripts/conformance/server-parity/child-visibility-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/child-visibility.json', import.meta.url), 'utf8'));
const expected = fixture.child_visibility.expected;
const observation = () => structuredClone({mode: 'http', child_visibility: {applicable: true, ...expected}});

test('the published answers pass', () => {
  assert.deepStrictEqual(checkChildVisibility(fixture, observation()), {scope: 'child_visibility'});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkChildVisibility(fixture,
    {mode: 'embedded', child_visibility: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'child_visibility'});
});

test('the child and its retry carry the parent memo and search attribute', () => {
  const {memo, search_attributes: attributes} = fixture.child_visibility;
  for (const run of [expected.child, expected.retry]) {
    assert.deepStrictEqual([run.memo, run.search_attributes], [memo, attributes]);
  }
  assert.deepStrictEqual(expected.runs.map(run => run.memo), [memo, memo]);
  assert.deepStrictEqual(expected.listed.map(row => row.workflow), ['child', 'child', 'parent']);
});

for (const [name, mutate] of [
  ['a child without the parent memo', state => { state.child.memo = []; }],
  ['a retry without the search attribute', state => { state.retry.search_attributes = []; }],
  ['a child missing from the query', state => { state.listed = state.listed.filter(row => row.workflow !== 'child'); }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.child_visibility);
  assert.throws(() => checkChildVisibility(fixture, raw));
});
