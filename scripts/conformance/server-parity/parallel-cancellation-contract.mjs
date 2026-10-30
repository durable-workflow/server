import assert from 'node:assert/strict';

// Reviewed cooperative cancellation at parallel groups and selection handles
// (parallel-cancellation-probe.php), recorded against published Server
// 2.11.7. The fixture's `expected` holds each case's whole observation.
export function checkParallelCancellation(fixture, observation) {
  const definition = fixture.parallel_cancellation;
  const state = observation.parallel_cancellation;
  const projection = {scope: 'parallel_cancellation', cases: Object.keys(definition.cases).length};
  assert.ok(state && typeof state === 'object', 'actual parallel cancellation observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded parallel cancellation explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record parallel cancellation');
  assert.deepStrictEqual(Object.keys(definition.expected).sort(), Object.keys(definition.cases).sort(),
    'every parallel cancellation case has an expectation');
  for (const [name, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(state[name], expected, `parallel cancellation ${name}`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'parallel cancellation cases');
  return projection;
}
