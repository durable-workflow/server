import assert from 'node:assert/strict';

// Reviewed worker sessions (WorkerSessionController, WorkerSessionRegistry),
// recorded against published Server 2.11.1. The fixture's `expected` holds
// the whole normalized observation, with the probe's workflow prefix as
// `{id}`; session times are spans and presence flags, since PHP keeps them in
// whole seconds, and the list is compared by session ID because ties within
// a second order by ID. Every step compares exactly.
//
// Published defect boundary: a reacquired session keeps its previous
// failure_reason, because renewHeldSession filters its own `failure_reason =>
// null` out (fixture `published_defects`). At those paths the published value
// or the intended null is accepted. The only other allowance is PHP's
// creation-second boundary on ttl_span (creationSecond).
// PHP computes a new session's ttl_expires_at from its request clock, then
// Eloquent stamps created_at when it inserts the row. When the insert lands in
// the next second, the probe's ttl_span (ttl_expires_at - created_at) reads one
// second short. That span, and only that one, is read as the recorded one.
// Native stamps both from one clock reading.
function creationSecond(actual, expected) {
  if (actual === null || expected === null || typeof actual !== 'object' || typeof expected !== 'object') return;
  if (Number.isInteger(actual.ttl_span) && Number.isInteger(expected.ttl_span) && actual.ttl_span === expected.ttl_span - 1) {
    actual.ttl_span = expected.ttl_span;
  }
  for (const key of Object.keys(expected)) creationSecond(actual[key], expected[key]);
}

export function checkWorkerSessions(fixture, observation, workflowId) {
  const state = observation.worker_sessions;
  const projection = {scope: 'worker_sessions'};
  assert.ok(state && typeof state === 'object', 'actual worker sessions observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded worker sessions explicitly inapplicable');
    return projection;
  }
  const definition = fixture.worker_sessions;
  const actual = JSON.parse(JSON.stringify(state).replaceAll(workflowId, '{id}'));
  const defect = definition.published_defects.reacquired_failure_reason;
  const tolerate = (session, published, label) => {
    assert.ok(session && typeof session === 'object', `${label}: a session`);
    if (session.failure_reason === published) session.failure_reason = null;
  };
  tolerate(actual.reacquired?.response?.session, defect.published[0], 'reacquired');
  tolerate(actual.orphan_reacquired?.response?.session, defect.published[1], 'orphan reacquired');
  tolerate((actual.list_sessions ?? []).find(session => session.session_id === '{id}-wss-held'), defect.published[2], 'listed held session');
  for (const [step, expected] of Object.entries(definition.expected)) {
    creationSecond(actual[step], expected);
    assert.deepStrictEqual(actual[step], expected, `worker sessions ${step}`);
  }
  assert.deepStrictEqual(Object.keys(actual).sort(), Object.keys(definition.expected).sort(), 'worker sessions steps');
  return projection;
}
