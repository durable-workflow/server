import assert from 'node:assert/strict';

// Reviewed workflow task completions with more than 100 commands
// (large-command-batches-probe.php), recorded against published Server
// 2.11.5: an external worker's completion of 150 timers or 150 activities is
// accepted in one batch, creates a task for each, and leaves the run waiting
// on its first open wait. So is an all group of 150 activities. A group
// declaring 1,001 members, above the command batch size, is refused before
// any guard with an error on each command's parallel_group_path. The
// fixture's `expected` holds the whole observation.
export function checkLargeCommandBatches(fixture, observation) {
  const definition = fixture.large_command_batches;
  const state = observation.large_command_batches;
  const projection = {scope: 'large_command_batches', runs: Object.keys(definition.expected).length};
  assert.ok(state && typeof state === 'object', 'actual large command batches observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded large command batches explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record large command batches');
  for (const [run, steps] of Object.entries(definition.expected)) {
    assert.ok(state[run] && typeof state[run] === 'object', `large command batches ${run} recorded`);
    for (const [step, expected] of Object.entries(steps)) {
      assert.deepStrictEqual(state[run][step], expected, `large command batches ${run} ${step}`);
    }
    assert.deepStrictEqual(Object.keys(state[run]).sort(), Object.keys(steps).sort(), `large command batches ${run} steps`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'large command batches runs');
  return projection;
}
