import assert from 'node:assert/strict';

// The fixture's requests and answers with its namespace in place of "@own".
export function repairPassCases(fixture, namespace) {
  const own = value => JSON.parse(JSON.stringify(value).split('@own').join(namespace));
  const definition = fixture.repair_pass;
  return definition.requests.map(([label, body]) => [label, own(body), own(definition.expected[label])]);
}

// Reviewed repair pass answers (SystemController::repairPass), exact.
export function checkRepairPass(fixture, observation, workflowId) {
  const state = observation.repair_pass;
  const projection = {scope: 'repair_pass', cases: fixture.repair_pass.requests.length};
  assert.ok(state && typeof state === 'object', 'actual repair pass observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_system_routes'}, 'embedded repair pass explicitly inapplicable');
    return projection;
  }
  const namespace = `${workflowId}-repair`.toLowerCase();
  const cases = repairPassCases(fixture, namespace);
  assert.deepStrictEqual([state.applicable, state.namespace], [true, namespace], 'repair namespace');
  assert.deepStrictEqual(Object.keys(state.responses), cases.map(([label]) => label), 'every request recorded in order');
  for (const [label, , [status, body]] of cases) {
    const recorded = state.responses[label];
    assert.deepStrictEqual([recorded.status, recorded.response], [status, body], label);
  }
  return projection;
}
