import assert from 'node:assert/strict';

// Reviewed cooperative cancellation of a retried child
// (retried-child-cancellation-probe.php), recorded against published Server
// 2.11.7. The fixture's `expected` holds each case's whole observation.
export function checkRetriedChildCancellation(fixture, observation) {
  const definition = fixture.retried_child_cancellation;
  const state = observation.retried_child_cancellation;
  const projection = {scope: 'retried_child_cancellation', cases: Object.keys(definition.cases).length};
  assert.ok(state && typeof state === 'object', 'actual retried child cancellation observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded retried child cancellation explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record retried child cancellation');
  assert.deepStrictEqual(Object.keys(definition.expected).sort(), Object.keys(definition.cases).sort(),
    'every retried child cancellation case has an expectation');
  for (const [name, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(state[name], expected, `retried child cancellation ${name}`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'retried child cancellation cases');
  return projection;
}
