import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkJsonDoubles} from '../../scripts/conformance/server-parity/json-doubles-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/json-doubles.json', import.meta.url), 'utf8'));
const definition = fixture.json_doubles;
const names = Object.keys(definition.doubles);
const doubles = Object.fromEntries(names.map((name) => [name, Number(definition.doubles[name])]));
const attributes = Object.fromEntries(names.map((name) => [definition.search_attribute_prefix + name, doubles[name]]));
// What an exact server answers, written out as JSON text and read back, as
// the recorder's observation file is.
const exact = () => JSON.parse(JSON.stringify({mode: 'http', json_doubles: {applicable: true,
  described_memo: doubles, described_search_attributes: attributes,
  started_memo: doubles, started_search_attributes: attributes, heartbeat_details: doubles}}));

test('exact doubles pass on every reader', () => {
  assert.deepStrictEqual(checkJsonDoubles(fixture, exact()), {scope: 'json_doubles', values: names.length});
});

test('embedded applications are explicitly inapplicable', () => {
  assert.deepStrictEqual(checkJsonDoubles(fixture, {mode: 'embedded',
    json_doubles: {applicable: false, reason: 'embedded_has_no_http_json_bodies'}}), {scope: 'json_doubles', values: names.length});
  assert.throws(() => checkJsonDoubles(fixture, {mode: 'embedded', json_doubles: {applicable: true}}));
});

test('the fixture names hard 17-digit doubles that round-trip in JavaScript', () => {
  assert.equal(definition.doubles.d0, '1.4820142857142857');
  for (const name of names) {
    assert.equal(String(Number(definition.doubles[name])), definition.doubles[name], name);
  }
});

// The value native 3.0.5 answered before float_roundtrip: one ULP below.
const oneUlpDown = (value) => {
  const view = new DataView(new ArrayBuffer(8));
  view.setFloat64(0, value);
  view.setBigUint64(0, view.getBigUint64(0) - 1n);
  return view.getFloat64(0);
};

for (const reader of ['described_memo', 'started_memo', 'heartbeat_details']) {
  test(`one ULP off in ${reader} fails`, () => {
    const observation = exact();
    observation.json_doubles[reader].d0 = oneUlpDown(doubles.d0);
    assert.equal(String(observation.json_doubles[reader].d0), '1.4820142857142855');
    assert.throws(() => checkJsonDoubles(fixture, observation), new RegExp(`${reader} d0`));
  });
}

for (const reader of ['described_search_attributes', 'started_search_attributes']) {
  test(`one ULP off in ${reader} fails`, () => {
    const observation = exact();
    const name = definition.search_attribute_prefix + 'd1';
    observation.json_doubles[reader][name] = oneUlpDown(doubles.d1);
    assert.throws(() => checkJsonDoubles(fixture, observation), new RegExp(`${reader} ${name}`));
  });
}

for (const [label, mutate] of [
  ['a missing reader', (state) => { delete state.heartbeat_details; }],
  ['an absent reader', (state) => { state.started_memo = 'absent'; }],
  ['a missing value', (state) => { delete state.described_memo.d2; }],
  ['an extra value', (state) => { state.described_memo.extra = 1.5; }],
  ['a value as a string', (state) => { state.heartbeat_details.d0 = '1.4820142857142857'; }],
  ['an integer-truncated value', (state) => { state.described_search_attributes.ParityDouble_d4 = 1; }],
  ['an inapplicable HTTP target', (state) => { state.applicable = false; }],
]) {
  test(`${label} fails`, () => {
    const observation = exact();
    mutate(observation.json_doubles);
    assert.throws(() => checkJsonDoubles(fixture, observation));
  });
}
