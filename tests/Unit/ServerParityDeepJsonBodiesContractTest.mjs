import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkDeepJsonBodies} from '../../scripts/conformance/server-parity/deep-json-bodies-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/deep-json-bodies.json', import.meta.url), 'utf8'));
const expected = fixture.deep_json_bodies.expected;
const observation = () => structuredClone({mode: 'http', deep_json_bodies: {applicable: true, ...expected}});

test('the published answers pass', () => {
  assert.deepStrictEqual(checkDeepJsonBodies(fixture, observation()), {scope: 'deep_json_bodies'});
});

test('embedded applications have no HTTP request bodies', () => {
  assert.deepStrictEqual(checkDeepJsonBodies(fixture,
    {mode: 'embedded', deep_json_bodies: {applicable: false, reason: 'embedded_has_no_http_request_bodies'}}),
  {scope: 'deep_json_bodies'});
});

test('bodies are read to 511 levels and refused at 512', () => {
  for (const levels of [128, 129, 511]) {
    assert.equal(expected[`start_${levels}`].status, 201, `start ${levels}`);
    assert.notEqual(expected[`definition_${levels}`].reason, 'malformed_json', `definition ${levels}`);
  }
  assert.deepStrictEqual([expected.start_512.status, expected.start_512.reason], [400, 'malformed_json']);
  assert.deepStrictEqual([expected.definition_512.status, expected.definition_512.reason], [400, 'malformed_json']);
});

for (const [name, mutate] of [
  ['a 129-level body refused as plain text', state => { state.start_129 = {status: 400, json: false, reason: null, message: null, json_error: null}; }],
  ['a 512-level body accepted', state => { state.start_512 = structuredClone(state.start_511); }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.deep_json_bodies);
  assert.throws(() => checkDeepJsonBodies(fixture, raw));
});
