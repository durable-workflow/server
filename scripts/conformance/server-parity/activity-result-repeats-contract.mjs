import assert from 'node:assert/strict';

// Reviewed repeats of a finished activity attempt's result
// (activity-result-repeats-probe.php), recorded against published Server
// 2.11.7. The fixture's `expected` holds each case's whole observation.
export function checkActivityResultRepeats(fixture, observation) {
  const definition = fixture.activity_result_repeats;
  const state = observation.activity_result_repeats;
  const projection = {scope: 'activity_result_repeats', cases: Object.keys(definition.cases).length};
  assert.ok(state && typeof state === 'object', 'actual activity result repeats observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded activity result repeats explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record activity result repeats');
  assert.deepStrictEqual(Object.keys(definition.expected).sort(), Object.keys(definition.cases).sort(),
    'every activity result repeats case has an expectation');
  for (const [name, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(state[name], expected, `activity result repeats ${name}`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'activity result repeats cases');
  return projection;
}
