import assert from 'node:assert/strict';

// A child's resolution while its parent has a ready workflow task
// (child-open-parent-task-probe.php), recorded against published Server
// 2.11.7. The ready task is the parent's only resume: it replays the child's
// result and closes the run. The fixture's `expected` holds the observation.
export function checkChildOpenParentTask(fixture, observation) {
  const definition = fixture.child_open_parent_task;
  const state = observation.child_open_parent_task;
  const projection = {scope: 'child_open_parent_task', cases: Object.keys(definition.expected).length};
  assert.ok(state && typeof state === 'object', 'actual child open parent task observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded child open parent task explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP targets record a child resolution beside a ready parent task');
  for (const [name, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual(state[name], expected, `child open parent task ${name}`);
  }
  assert.deepStrictEqual(Object.keys(state).sort(), ['applicable', ...Object.keys(definition.expected)].sort(),
    'child open parent task cases');
  return projection;
}
