import assert from 'node:assert/strict';

// Reviewed concurrent waits (concurrent-waits-probe.php), recorded against
// published Server 2.11.5: two signal waits and a condition wait opened in
// one completion, each resolved by its own signal, and two waits for one
// signal name, whose buffered second signal is delivered only when the
// workflow declares its name or a later wait takes its identity. The
// fixture's `expected` holds the whole normalized
// observation; identities are labels by first appearance and instants are
// dropped, so every step compares exactly.
export function checkConcurrentWaits(fixture, observation) {
  const definition = fixture.concurrent_waits;
  const state = observation.concurrent_waits;
  const projection = {scope: 'concurrent_waits', runs: Object.keys(definition.expected).length};
  assert.ok(state && typeof state === 'object', 'actual concurrent waits observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded concurrent waits explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record concurrent waits');
  for (const [run, steps] of Object.entries(definition.expected)) {
    assert.ok(state[run] && typeof state[run] === 'object', `concurrent waits ${run} recorded`);
    for (const [step, expected] of Object.entries(steps)) {
      assert.deepStrictEqual(state[run][step], expected, `concurrent waits ${run} ${step}`);
    }
    assert.deepStrictEqual(Object.keys(state[run]).sort(), Object.keys(steps).sort(), `concurrent waits ${run} steps`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'concurrent waits runs');
  return projection;
}
