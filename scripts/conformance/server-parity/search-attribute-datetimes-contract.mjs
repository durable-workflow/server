import assert from 'node:assert/strict';

// Reviewed datetime search attribute values and per-run attribute limits
// (search-attribute-datetimes-probe.php), recorded against published Server
// 2.11.7. The fixture's `expected` holds the whole observation.
export function checkSearchAttributeDatetimes(fixture, observation) {
  const definition = fixture.search_attribute_datetimes;
  const state = observation.search_attribute_datetimes;
  const cases = Object.keys(definition.values).length * 2 + Object.keys(definition.limits).length;
  const projection = {scope: 'search_attribute_datetimes', cases};
  assert.ok(state && typeof state === 'object', 'actual search attribute datetimes observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded search attribute datetimes explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record search attribute datetimes');
  for (const path of ['start', 'upsert']) {
    assert.deepStrictEqual(Object.keys(definition.expected[path]).sort(), Object.keys(definition.values).sort(),
      `every ${path} value has an expectation`);
  }
  assert.deepStrictEqual(Object.keys(definition.expected.limits).sort(), Object.keys(definition.limits).sort(),
    'every limit case has an expectation');
  for (const [step, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(state[step], expected, `search attribute datetimes ${step}`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'search attribute datetimes steps');
  return projection;
}
