import assert from 'node:assert/strict';

// Reviewed query arguments the server cannot decode
// (query-arguments-probe.php), recorded against published Server 2.11.5:
// a run that declares no queries routes the query with its arguments as
// given, so its worker leases the undecodable or non-list blob and answers;
// a run that declares the query refuses those arguments before routing.
// The fixture's `expected` holds the whole observation.
export function checkQueryArgumentRouting(fixture, observation) {
  const definition = fixture.query_argument_routing;
  const state = observation.query_argument_routing;
  const projection = {scope: 'query_argument_routing', runs: Object.keys(definition.expected).length};
  assert.ok(state && typeof state === 'object', 'actual query arguments observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded query arguments explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record query arguments');
  for (const [run, steps] of Object.entries(definition.expected)) {
    assert.ok(state[run] && typeof state[run] === 'object', `query arguments ${run} recorded`);
    for (const [step, expected] of Object.entries(steps)) {
      assert.deepStrictEqual(state[run][step], expected, `query arguments ${run} ${step}`);
    }
    assert.deepStrictEqual(Object.keys(state[run]).sort(), Object.keys(steps).sort(), `query arguments ${run} steps`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'query arguments runs');
  return projection;
}
