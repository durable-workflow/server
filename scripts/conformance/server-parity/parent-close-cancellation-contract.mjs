import assert from 'node:assert/strict';

// Reviewed parent-close cancellation (parent-close-cancellation-probe.php),
// recorded against published Server 2.11.5: a parent closes while a child it
// started under the request_cancellation parent-close policy is open, with
// no cooperative request of its own. It completes or fails in the batch that
// starts its child, or is terminated over HTTP with two such children. The
// parent records one ParentCloseCancellationRequested origin after its
// terminal event, at the terminal event's time with a 600-second budget,
// then ParentClosePolicyApplied for each child, which takes a descendant
// request under that root and receives it in its first task.
// The fixture's `expected` holds the whole normalized observation; ids are
// labels by first appearance, so every step compares exactly.
export function checkParentCloseCancellation(fixture, observation) {
  const definition = fixture.parent_close_cancellation;
  const state = observation.parent_close_cancellation;
  const projection = {scope: 'parent_close_cancellation', cases: Object.keys(definition.expected).length};
  assert.ok(state && typeof state === 'object', 'actual parent-close cancellation observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded parent-close cancellation explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record parent-close cancellation');
  assert.deepStrictEqual(Object.keys(definition.expected).sort(), Object.keys(definition.cases).sort(),
    'every parent-close cancellation case has an expectation');
  for (const [name, steps] of Object.entries(definition.expected)) {
    assert.ok(state[name] && typeof state[name] === 'object', `parent-close cancellation ${name} recorded`);
    for (const [step, expected] of Object.entries(steps)) {
      assert.deepStrictEqual(state[name][step], expected, `parent-close cancellation ${name} ${step}`);
    }
    assert.deepStrictEqual(Object.keys(state[name]).sort(), Object.keys(steps).sort(), `parent-close cancellation ${name} steps`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'parent-close cancellation cases');
  return projection;
}
