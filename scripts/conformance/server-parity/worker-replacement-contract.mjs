import assert from 'node:assert/strict';

// Reviewed replacement of a worker process holding an activity lease
// (WorkerController::releaseLeasedActivityTasksForReplacedWorker and the
// stale completion receipt). Embedded applications have no HTTP worker
// registration, so the projection is the definition alone.
export function checkWorkerReplacement(fixture, observation, workflowId) {
  const definition = fixture.worker_replacement;
  const state = observation.worker_replacement;
  const projection = {scope: 'worker_replacement', expected_events: definition.expected_events};
  assert.ok(state && typeof state === 'object', 'actual worker replacement observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_worker_registration_lifecycle'},
      'embedded worker replacement explicitly inapplicable');
    return projection;
  }
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const worker = `${workflowId}-replaced-worker`;
  equal([state.applicable, state.worker_id, state.task_queue, state.peer_workflow_id],
    [true, worker, `${workflowId}-replacement`, `${workflowId}-replacement-peer`], 'replacement identities');
  for (const key of ['registration', 'replacement_registration']) {
    equal([state[key].worker_id, state[key].registered], [worker, true], `${key} receipt`);
  }
  const original = state.original_claim;
  const replacement = state.replacement_claim;
  assert.ok(original && replacement, 'the released task is claimed again at once');
  equal([original.attempt_number, original.lease_owner], [1, worker], 'original attempt');
  equal([replacement.task_id, replacement.attempt_number, replacement.lease_owner],
    [original.task_id, 2, worker], 'the same task claimed again as attempt 2 under a one-attempt policy');
  assert.notEqual(replacement.activity_attempt_id, original.activity_attempt_id, 'a new attempt identity');
  equal(state.history_after_replacement.map(event => event.event_type), definition.expected_events.slice(0, 4),
    'releasing the lease records no history');

  const {protocol_version, server_capabilities, ...stale} = state.stale_completion.response;
  equal(state.stale_completion.status, 409, 'stale completion refused');
  equal(stale, {task_id: original.task_id, activity_attempt_id: original.activity_attempt_id, outcome: 'completed',
    recorded: false, reason: 'stale_attempt', next_task_id: null, lease_owner: worker, activity_status: 'running',
    attempt_status: 'expired', task_status: 'leased'}, 'published stale completion receipt');
  const completion = state.completion;
  equal(completion.status, 200, 'replacement completion accepted');
  equal([completion.response.task_id, completion.response.activity_attempt_id, completion.response.outcome,
    completion.response.recorded, completion.response.reason],
  [original.task_id, replacement.activity_attempt_id, 'completed', true, null], 'replacement completion recorded once');

  const history = state.history;
  equal(history.map(event => event.event_type), definition.expected_events, 'complete replacement history');
  const started = history.filter(event => event.event_type === 'ActivityStarted').map(event => event.payload);
  equal(started.map(payload => [payload.activity_attempt_id, payload.attempt_number]),
    [[original.activity_attempt_id, 1], [replacement.activity_attempt_id, 2]], 'each attempt started once');
  const completed = history.find(event => event.event_type === 'ActivityCompleted').payload;
  equal([completed.activity_attempt_id, completed.attempt_number], [replacement.activity_attempt_id, 2],
    'only the replacement attempt completes');
  return projection;
}
