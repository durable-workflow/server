import assert from 'node:assert/strict';

// Reviewed descriptions after more than 64 updates (many-updates-probe.php),
// recorded against published Server 2.11.5: a run waiting for a signal takes
// 65 updates, each accepted and completed by its own workflow task, and is
// still described as waiting for the signal; one more update left accepted
// is described as the run's open update. The fixture's `expected` holds the
// whole observation.
export function checkManyUpdates(fixture, observation) {
  const definition = fixture.many_updates;
  const state = observation.many_updates;
  const projection = {scope: 'many_updates', steps: Object.keys(definition.expected).length};
  assert.ok(state && typeof state === 'object', 'actual many updates observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded many updates explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record many updates');
  for (const [step, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(state[step], expected, `many updates ${step}`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'many updates steps');
  return projection;
}
