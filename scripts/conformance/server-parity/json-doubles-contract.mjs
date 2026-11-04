import assert from 'node:assert/strict';

// JSON doubles keep every digit (json-doubles-probe.php, server#1010). PHP's
// json_decode and json_encode give a double back with the digits it came in
// with, so each value read back must be exactly the double its fixture text
// names. Expectations come from the fixture's input texts, never from a
// recording, so a rounded answer cannot be written into the fixture.
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
  const doubles = {};
  const attributes = {};
  for (const name of names) {
    const value = Number(definition.doubles[name]);
    assert.ok(Number.isFinite(value) && !Number.isInteger(value), `fixture double ${name} has a fraction`);
    doubles[name] = value;
    attributes[definition.search_attribute_prefix + name] = value;
  }
  const expected = {applicable: true, described_memo: doubles, described_search_attributes: attributes,
    started_memo: doubles, started_search_attributes: attributes, heartbeat_details: doubles};
  assert.deepStrictEqual(Object.keys(state).sort(), Object.keys(expected).sort(), 'json doubles readers');
  for (const [reader, values] of Object.entries(expected)) {
    if (reader === 'applicable') {
      assert.equal(state.applicable, true, 'HTTP targets record json doubles');
      continue;
    }
    assert.ok(state[reader] && typeof state[reader] === 'object', `json doubles ${reader} recorded`);
    assert.deepStrictEqual(Object.keys(state[reader]).sort(), Object.keys(values).sort(), `json doubles ${reader} names`);
    for (const [name, value] of Object.entries(values)) {
      // Object.is: the exact double, so one ULP off fails.
      assert.ok(Object.is(state[reader][name], value), `json doubles ${reader} ${name}: ${state[reader][name]} is not ${value}`);
    }
  }
  return projection;
}
