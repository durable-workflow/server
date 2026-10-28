import assert from 'node:assert/strict';

// Reviewed search attribute values on a workflow start
// (start-search-attributes-probe.php), recorded against published Server
// 2.11.5: values are validated against the namespace's registry, typed,
// stored and recorded on StartAccepted and WorkflowStarted; without
// registered definitions their types are inferred. The fixture's `expected`
// holds the whole observation.
export function checkStartSearchAttributes(fixture, observation) {
  const definition = fixture.start_search_attributes;
  const state = observation.start_search_attributes;
  const projection = {scope: 'start_search_attributes', cases: Object.keys(definition.expected).length};
  assert.ok(state && typeof state === 'object', 'actual start search attributes observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_search_attribute_registry'},
      'embedded start search attributes explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record start search attributes');
  assert.deepStrictEqual(Object.keys(definition.expected).sort(), Object.keys(definition.cases).sort(),
    'every start search attributes case has an expectation');
  for (const [name, steps] of Object.entries(definition.expected)) {
    assert.ok(state[name] && typeof state[name] === 'object', `start search attributes ${name} recorded`);
    for (const [step, expected] of Object.entries(steps)) {
      assert.deepStrictEqual(state[name][step], expected, `start search attributes ${name} ${step}`);
    }
    assert.deepStrictEqual(Object.keys(state[name]).sort(), Object.keys(steps).sort(), `start search attributes ${name} steps`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'start search attributes cases');
  return projection;
}
