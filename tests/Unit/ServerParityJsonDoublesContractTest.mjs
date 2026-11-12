import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkJsonDoubles} from '../../scripts/conformance/server-parity/json-doubles-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/json-doubles.json', import.meta.url), 'utf8'));
const definition = fixture.json_doubles;
const names = Object.keys(definition.doubles);
const exact = Object.fromEntries(names.map((name) => [name, Number(definition.doubles[name])]));
const keyed = (values) => Object.fromEntries(names.map((name) => [definition.search_attribute_prefix + name, values[name]]));
// PHP's 14-digit rendering of a typed double search attribute, as published
// 2.11.8 answered: 1.4820142857143, 124435.71428571, 33.333333333333, 0.70710678118655, 1.
const php14 = Object.fromEntries(names.map((name) => [name, Number(exact[name].toPrecision(14))]));
const mysql = {...exact, ...Object.fromEntries(Object.entries(definition.mysql_json_doubles).map(([name, text]) => [name, Number(text)]))};
const projection = {scope: 'json_doubles', values: names.length};

// The observation each implementation recorded on each storage (lab2, published
// PHP 2.11.8 and native at this PR, SQLite and MySQL 8.0.46), written out as
// JSON text and read back, as the recorder's observation file is.
const observation = (implementation, storage) => {
  const attributes = implementation === null ? php14 : exact;
  // Native keeps exact text on MySQL from schema 13 (server#1016).
  const history = storage === 'mysql_json' && implementation === null ? mysql : exact;
  return JSON.parse(JSON.stringify({mode: 'http', json_doubles: {applicable: true, implementation, storage,
    described_memo: exact, described_search_attributes: keyed(attributes), listed_search_attributes: keyed(attributes),
    started_memo: history, started_search_attributes: keyed(history), heartbeat_details: history}}));
};

for (const implementation of [null, 'rust']) {
  for (const storage of ['exact', 'mysql_json']) {
    test(`${implementation ?? 'php'} on ${storage} storage passes`, () => {
      assert.deepStrictEqual(checkJsonDoubles(fixture, observation(implementation, storage)), projection);
    });
  }
}

test('embedded applications are explicitly inapplicable', () => {
  assert.deepStrictEqual(checkJsonDoubles(fixture, {mode: 'embedded',
    json_doubles: {applicable: false, reason: 'embedded_has_no_http_json_bodies'}}), projection);
  assert.throws(() => checkJsonDoubles(fixture, {mode: 'embedded', json_doubles: {applicable: true}}));
});

test('the fixture names hard 17-digit doubles and the reviewed exceptions', () => {
  assert.equal(definition.doubles.d0, '1.4820142857142857');
  for (const name of names) {
    assert.equal(String(Number(definition.doubles[name])), definition.doubles[name], name);
  }
  assert.equal(definition.php_search_attribute_precision, 14);
  assert.equal(String(php14.d0), '1.4820142857143');
  assert.equal(php14.d4, 1);
  // MySQL's values are one ULP from the input, and only where MySQL changes them.
  for (const [name, text] of Object.entries(definition.mysql_json_doubles)) {
    assert.ok(Object.hasOwn(definition.doubles, name), name);
    assert.notEqual(text, definition.doubles[name], name);
    assert.equal(Number(text), oneUlpDown(exact[name]), name);
  }
});

function oneUlpDown(value) {
  const view = new DataView(new ArrayBuffer(8));
  view.setFloat64(0, value);
  view.setBigUint64(0, view.getBigUint64(0) - 1n);
  return view.getFloat64(0);
}

// Native 3.0.5 parsed without float_roundtrip: one ULP below on every reader.
for (const reader of ['described_memo', 'described_search_attributes', 'listed_search_attributes',
  'started_memo', 'started_search_attributes', 'heartbeat_details']) {
  test(`native one ULP off in ${reader} fails`, () => {
    const recorded = observation('rust', 'exact');
    const key = reader.endsWith('search_attributes') ? definition.search_attribute_prefix + 'd0' : 'd0';
    recorded.json_doubles[reader][key] = oneUlpDown(exact.d0);
    assert.throws(() => checkJsonDoubles(fixture, recorded), new RegExp(`${reader} ${key}: 1.4820142857142855`));
  });
}

for (const [label, implementation, storage, mutate] of [
  ['native with PHP\'s 14-digit search attributes', 'rust', 'exact',
    (state) => { state.described_search_attributes = keyed(php14); }],
  ['PHP with exact search attributes', null, 'exact', (state) => { state.listed_search_attributes = keyed(exact); }],
  ['MySQL rounding on exact storage', 'rust', 'exact', (state) => { state.heartbeat_details = mysql; }],
  ['exact history from PHP on MySQL storage', null, 'mysql_json', (state) => { state.started_memo = exact; }],
  ['native MySQL rounding on MySQL storage', 'rust', 'mysql_json', (state) => { state.heartbeat_details = mysql; }],
  ['MySQL rounding in the described memo', null, 'mysql_json', (state) => { state.described_memo = mysql; }],
  ['an unknown implementation', 'go', 'exact', () => {}],
  ['an unnamed storage', 'rust', 'exact', (state) => { delete state.storage; }],
  ['an unknown storage', 'rust', 'exact', (state) => { state.storage = 'sqlite'; }],
  ['a missing reader', 'rust', 'exact', (state) => { delete state.heartbeat_details; }],
  ['an absent reader', 'rust', 'exact', (state) => { state.started_memo = 'absent'; }],
  ['a missing value', 'rust', 'exact', (state) => { delete state.described_memo.d2; }],
  ['an extra value', 'rust', 'exact', (state) => { state.described_memo.extra = 1.5; }],
  ['a value as a string', 'rust', 'exact', (state) => { state.heartbeat_details.d0 = '1.4820142857142857'; }],
  ['an inapplicable HTTP target', 'rust', 'exact', (state) => { state.applicable = false; }],
]) {
  test(`${label} fails`, () => {
    const recorded = observation(implementation, storage);
    mutate(recorded.json_doubles);
    assert.throws(() => checkJsonDoubles(fixture, recorded));
  });
}
