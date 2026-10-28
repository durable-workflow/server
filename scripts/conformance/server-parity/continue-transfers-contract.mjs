import assert from 'node:assert/strict';

// Reviewed continue-as-new and close over what a run leaves behind
// (continue-transfers-probe.php), recorded against published Server 2.11.5:
// a run that is the caller of an accepted service call and the linked run of
// another continues as new; a run with a query admitted while its workflow
// task is leased continues as new, and another such run is terminated; a
// parent is terminated after its child continued as new. Core touches
// neither the calls nor the queries: the calls stay with the predecessor,
// each query is answered from the run it was admitted against, and the
// parent-close policy reaches the child's current run.
// The fixture's `expected` holds the whole normalized observation; run ids
// are labels by first appearance, so every step compares exactly.
export function checkContinueTransfers(fixture, observation) {
  const definition = fixture.continue_transfers;
  const state = observation.continue_transfers;
  const projection = {scope: 'continue_transfers', runs: Object.keys(definition.expected).length};
  assert.ok(state && typeof state === 'object', 'actual continue transfers observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded continue transfers explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record continue transfers');
  for (const [run, steps] of Object.entries(definition.expected)) {
    assert.ok(state[run] && typeof state[run] === 'object', `continue transfers ${run} recorded`);
    for (const [step, expected] of Object.entries(steps)) {
      assert.deepStrictEqual(state[run][step], expected, `continue transfers ${run} ${step}`);
    }
    assert.deepStrictEqual(Object.keys(state[run]).sort(), Object.keys(steps).sort(), `continue transfers ${run} steps`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'continue transfers runs');
  return projection;
}
