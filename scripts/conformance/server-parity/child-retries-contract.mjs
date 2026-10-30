import assert from 'node:assert/strict';

// Reviewed child retry and timeout options (child-retries-probe.php),
// recorded against published Server 2.11.7. The fixture's `expected` holds
// the whole observation of each case; IDs are labels in order of first
// appearance and deadlines are recorded by presence.
export function checkChildRetries(fixture, observation) {
  const definition = fixture.child_retries;
  const state = observation.child_retries;
  const projection = {scope: 'child_retries', cases: Object.keys(definition.cases).length};
  assert.ok(state && typeof state === 'object', 'actual child retries observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded child retries explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record child retries');
  assert.deepStrictEqual(Object.keys(definition.expected).sort(), Object.keys(definition.cases).sort(),
    'every child retries case has an expectation');
  for (const [name, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(state[name], expected, `child retries ${name}`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'child retries cases');
  return projection;
}
