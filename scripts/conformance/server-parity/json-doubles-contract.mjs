import assert from 'node:assert/strict';

// JSON doubles keep every digit (json-doubles-probe.php, server#1010). PHP's
// json_decode and json_encode give a double back with the digits it came in
// with, so each value read back must be exactly the double its fixture text
// names, with two reviewed exceptions:
// - published PHP renders a typed double search attribute through PHP's
//   14-digit `precision` in the run description and the visibility list
//   (`php_search_attribute_precision`); native keeps every digit;
// - on MySQL, the json column type parses doubles itself, so history
//   payloads (WorkflowStarted, heartbeat details) hold MySQL's double for
//   both implementations (`mysql_json_doubles`, read from MySQL 8.0 with
//   CAST(... AS JSON)). MariaDB, SQLite and PostgreSQL keep the text.
// Expectations come from the fixture's texts, never from a server recording.
const HISTORY_READERS = ['started_memo', 'started_search_attributes', 'heartbeat_details'];
const ATTRIBUTE_READERS = ['described_search_attributes', 'listed_search_attributes'];
const READERS = ['described_memo', ...ATTRIBUTE_READERS, ...HISTORY_READERS];

export function checkJsonDoubles(fixture, observation) {
  const definition = fixture.json_doubles;
  const state = observation.json_doubles;
  const names = Object.keys(definition.doubles);
  const projection = {scope: 'json_doubles', values: names.length};
  assert.ok(state && typeof state === 'object', 'actual json doubles observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_json_bodies'},
      'embedded json doubles explicitly inapplicable');
    return projection;
  }
  assert.ok(names.length > 0, 'the fixture names doubles');
  assert.equal(state.applicable, true, 'HTTP targets record json doubles');
  const {implementation, storage} = state;
  assert.ok(implementation === null || implementation === 'rust', `published PHP or native, not ${implementation}`);
  assert.ok(storage === 'exact' || storage === 'mysql_json', `json doubles storage ${storage}`);
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', 'implementation', 'storage', ...READERS].sort(),
    'json doubles readers');
  const exact = (name) => {
    const value = Number(definition.doubles[name]);
    assert.ok(Number.isFinite(value) && !Number.isInteger(value), `fixture double ${name} has a fraction`);
    return value;
  };
  const stored = (name) => storage === 'mysql_json' && Object.hasOwn(definition.mysql_json_doubles, name)
    ? Number(definition.mysql_json_doubles[name]) : exact(name);
  const attribute = (name) => implementation === null
    ? Number(exact(name).toPrecision(definition.php_search_attribute_precision)) : exact(name);
  const mismatches = [];
  const expectReader = (reader, keyed, value) => {
    const actual = state[reader];
    if (!actual || typeof actual !== 'object') {
      mismatches.push(`${reader} not recorded (${JSON.stringify(actual)})`);
      return;
    }
    const keys = Object.fromEntries(names.map((name) => [keyed(name), name]));
    assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(keys).sort(), `json doubles ${reader} names`);
    for (const [key, name] of Object.entries(keys)) {
      // Object.is: the exact double, so one ULP off fails.
      if (!Object.is(actual[key], value(name))) mismatches.push(`${reader} ${key}: ${actual[key]} is not ${value(name)}`);
    }
  };
  const plain = (name) => name;
  const prefixed = (name) => definition.search_attribute_prefix + name;
  expectReader('described_memo', plain, exact);
  for (const reader of ATTRIBUTE_READERS) expectReader(reader, prefixed, attribute);
  expectReader('started_memo', plain, stored);
  expectReader('started_search_attributes', prefixed, stored);
  expectReader('heartbeat_details', plain, stored);
  assert.deepStrictEqual(mismatches, [], `json doubles (${implementation ?? 'php'}, ${storage}): ${mismatches.join('; ')}`);
  return projection;
}
