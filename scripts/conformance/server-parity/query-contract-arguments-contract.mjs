import assert from 'node:assert/strict';

// Reviewed query arguments matched to a durable query contract
// (query-contract-arguments-probe.php), recorded against published Server
// 2.11.5 with Workflow 2.15.1: named and positional arguments normalize to
// the contract's positional order, a default fills a missing argument, the
// worker receives the normalized list encoded again, and each violation is
// refused with PHP's per-argument messages. A registered JSON default takes
// PHP's array shape; `sent_defaults` pins that those defaults were sent as
// JSON objects. The fixture's `expected` holds the whole observation.
export function checkQueryContractArguments(fixture, observation) {
  const definition = fixture.query_contract_arguments;
  const state = observation.query_contract_arguments;
  const projection = {scope: 'query_contract_arguments', cases: Object.keys(definition.cases).length};
  assert.ok(state && typeof state === 'object', 'actual query contract arguments observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded query contract arguments explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record query contract arguments');
  for (const [run, steps] of Object.entries(definition.expected)) {
    assert.ok(state[run] && typeof state[run] === 'object', `query contract arguments ${run} recorded`);
    // Published PHP answers an unencodable registered default with an
    // uncaught 500 and a generic body; only the status is pinned, so a
    // server may say what failed.
    if (steps.status === 500) {
      assert.equal(state[run].status, 500, `query contract arguments ${run} status`);
      assert.equal(state[run].validation_errors, null, `query contract arguments ${run} validation errors`);
      continue;
    }
    for (const [step, expected] of Object.entries(steps)) {
      assert.deepStrictEqual(state[run][step], expected, `query contract arguments ${run} ${step}`);
    }
    assert.deepStrictEqual(Object.keys(state[run]).sort(), Object.keys(steps).sort(), `query contract arguments ${run} steps`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'query contract arguments runs');
  return projection;
}
