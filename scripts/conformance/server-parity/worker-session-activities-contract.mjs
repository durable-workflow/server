import assert from 'node:assert/strict';

// Reviewed session-bound activities, recorded against published Server
// 2.11.1. The fixture's `expected` holds the whole normalized observation,
// with the probe's workflow prefix as `{id}`; session times are spans or
// presence flags. Every step compares exactly.
export function checkWorkerSessionActivities(fixture, observation, workflowId) {
  const state = observation.worker_session_activities;
  const projection = {scope: 'worker_session_activities'};
  assert.ok(state && typeof state === 'object', 'actual worker session activities observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded worker session activities explicitly inapplicable');
    return projection;
  }
  const expected = fixture.worker_session_activities.expected;
  const actual = JSON.parse(JSON.stringify(state).replaceAll(workflowId, '{id}'));
  for (const [step, value] of Object.entries(expected)) {
    assert.deepStrictEqual(actual[step], value, `worker session activities ${step}`);
  }
  assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(expected).sort(), 'worker session activities steps');
  return projection;
}
