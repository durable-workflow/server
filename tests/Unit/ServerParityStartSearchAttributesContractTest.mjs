import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkStartSearchAttributes} from '../../scripts/conformance/server-parity/start-search-attributes-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/start-search-attributes.json', import.meta.url), 'utf8'));
const expected = fixture.start_search_attributes.expected;
const observation = () => structuredClone({mode: 'http', start_search_attributes: {applicable: true, ...expected}});
const cases = Object.keys(fixture.start_search_attributes.cases).length;

test('the published answers pass', () => {
  assert.deepStrictEqual(checkStartSearchAttributes(fixture, observation()), {scope: 'start_search_attributes', cases});
});

test('embedded applications have no HTTP registry', () => {
  assert.deepStrictEqual(checkStartSearchAttributes(fixture, {mode: 'embedded',
    start_search_attributes: {applicable: false, reason: 'embedded_has_no_http_search_attribute_registry'}}),
  {scope: 'start_search_attributes', cases});
  assert.throws(() => checkStartSearchAttributes(fixture, {mode: 'embedded', start_search_attributes: {applicable: true}}));
});

test('the fixture starts typed runs and refuses invalid values', () => {
  assert.equal(expected.typed.start.status, 201);
  assert.equal(expected.typed.described.When, '2026-10-28T10:00:00.000000Z');
  assert.deepStrictEqual(expected.typed.events.StartAccepted, expected.typed.events.WorkflowStarted);
  assert.deepStrictEqual(expected.typed.listed.workflow_ids, ['parity-v1-start-search-attributes-ssa-typed']);
  for (const name of ['unknown', 'wrong_type', 'numeric_string', 'list']) {
    assert.deepStrictEqual([expected[name].start.status, expected[name].start.reason], [422, 'validation_failed'], name);
    assert.deepStrictEqual(Object.keys(expected[name]), ['start'], name);
  }
  assert.deepStrictEqual(expected.null_value.described, {Count: 5});
  assert.deepStrictEqual([expected.empty.events.StartAccepted, expected.empty.events.WorkflowStarted], [null, 'absent']);
  assert.equal(expected.inferred_scalar.described.Seen, '2026-10-28T10:00:00Z');
  for (const name of ['long_key', 'too_many']) {
    assert.deepStrictEqual([expected[name].start.status, Object.keys(expected[name].start.errors)], [422, ['workflow_type']], name);
  }
  assert.equal(expected.long_key_reserved.start.reason, 'workflow_id_reserved_in_namespace');
});

for (const [name, mutate] of [
  ['the former 501 refusal', state => {
    state.typed = {start: {status: 501, reason: 'start_search_attributes_unavailable', outcome: null, message: null, errors: null}};
  }],
  ['an untyped description', state => { state.typed.described.When = '2026-10-28T10:00:00Z'; }],
  ['values missing from the start events', state => { state.typed.events.WorkflowStarted = 'absent'; }],
  ['an accepted unregistered attribute', state => { state.unknown.start.status = 201; }],
  ['a stored null', state => { state.null_value.described.CustomerTier = null; }],
  ['a run the visibility query misses', state => { state.typed.listed.workflow_ids = []; }],
  ['a Core key refusal under search_attributes', state => {
    state.long_key.start.errors = {search_attributes: state.long_key.start.errors.workflow_type};
  }],
  ['normalization before the reservation check', state => {
    state.long_key_reserved.start = structuredClone(state.long_key.start);
  }],
  ['an accepted 101-attribute start', state => { state.too_many.start.status = 201; }],
  ['an unexpected case', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.start_search_attributes);
  assert.throws(() => checkStartSearchAttributes(fixture, raw));
});
