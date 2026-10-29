import assert from 'node:assert/strict';

// Reviewed worker-plane error bodies (worker-error-bodies-probe.php),
// recorded against published Server 2.11.5: poll validation failures,
// unknown activity tasks and unknown routes or methods, each in the worker
// envelope. The fixture's `expected` holds the whole observation.
export function checkWorkerErrorBodies(fixture, observation) {
  const definition = fixture.worker_error_bodies;
  const state = observation.worker_error_bodies;
  const projection = {scope: 'worker_error_bodies', requests: Object.keys(definition.requests).length};
  assert.ok(state && typeof state === 'object', 'actual worker error bodies observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded worker error bodies explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record worker error bodies');
  for (const [request, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(state[request], expected, `worker error bodies ${request}`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'worker error bodies requests');
  return projection;
}
