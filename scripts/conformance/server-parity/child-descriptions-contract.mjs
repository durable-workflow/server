import assert from 'node:assert/strict';

// Reviewed parent descriptions while children are open
// (child-descriptions-probe.php), recorded against published Server 2.11.5.
// The fixture's `expected` holds the whole observation.
export function checkChildDescriptions(fixture, observation) {
  const definition = fixture.child_descriptions;
  const state = observation.child_descriptions;
  const projection = {scope: 'child_descriptions', cases: Object.keys(definition.cases).length};
  assert.ok(state && typeof state === 'object', 'actual child descriptions observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded child descriptions explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record child descriptions');
  for (const [run, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(state[run], expected, `child descriptions ${run}`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'child descriptions cases');
  return projection;
}
