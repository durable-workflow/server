import assert from 'node:assert/strict';

// Reviewed late requests from a replaced workflow task claim
// (WorkflowTaskOwnership::guard and WorkerController::guardWorkflowTaskOwnership).
// A worker recognizes a lost claim from these fields, so the bodies are exact
// apart from server timestamps. Embedded applications have no HTTP worker
// registration, so the projection is the definition alone.
export function checkLateWorkflowCompletion(fixture, observation, workflowId) {
  const definition = fixture.late_workflow_completion;
  const state = observation.late_workflow_completion;
  const projection = {scope: 'late_workflow_completion', expected_events: definition.expected_events};
  assert.ok(state && typeof state === 'object', 'actual late workflow completion observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_worker_registration_lifecycle'},
      'embedded late workflow completion explicitly inapplicable');
    return projection;
  }
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const worker = `${workflowId}-late-worker`;
  equal([state.applicable, state.worker_id, state.task_queue, state.peer_workflow_id],
    [true, worker, `${workflowId}-late`, `${workflowId}-late-peer`], 'late completion identities');
  const timestamp = (value, label) => assert.match(value, /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d{1,6})?Z$/, label);
  const body = receipt => {
    const {protocol_version, server_capabilities, ...rest} = receipt.response ?? {};
    assert.equal(protocol_version, '1.20', 'worker protocol refusal');
    assert.ok(server_capabilities && typeof server_capabilities === 'object', 'server capabilities');
    return rest;
  };
  for (const [claims, label] of [[state.first_claims, 'first'], [state.last_claims, 'last']]) {
    const [replaced, current] = claims;
    equal([replaced.workflow_task_attempt, current.task_id, current.workflow_task_attempt, current.lease_owner],
      [1, replaced.task_id, 2, worker], `${label} task claimed again as attempt 2 after re-registration`);
  }
  const [replaced] = state.first_claims;
  const fence = {task_id: replaced.task_id, workflow_task_attempt: 1};

  equal(state.while_leased.status, 409, 'late completion refused while the new claim holds the lease');
  equal(body(state.while_leased), {...fence, error: 'Workflow task lease attempt does not match the current claim.',
    reason: 'workflow_task_attempt_mismatch', current_attempt: 2}, 'attempt mismatch refusal');
  equal(state.heartbeat_while_leased.status, 409, 'late lease renewal refused while the new claim holds the lease');
  equal(body(state.heartbeat_while_leased), body(state.while_leased), 'renewal refusal matches the completion refusal');

  for (const key of ['after_completion', 'heartbeat_after_completion', 'failure_after_completion']) {
    equal(state[key].status, 409, `${key} refused`);
    equal(body(state[key]), {...fence, error: 'Workflow task is not currently leased.', reason: 'task_not_leased',
      task_status: 'completed'}, `${key}: the completed task is not leased`);
  }
  equal(state.history_while_open.map(event => event.event_type), definition.expected_events.slice(0, 3),
    'late requests recorded nothing while the run was open');

  const [lastReplaced] = state.last_claims;
  const closed = body(state.after_close);
  equal(state.after_close.status, 409, 'late completion refused after the run closed');
  timestamp(closed.run_closed_at, 'run closure time');
  const {run_closed_at, ...closedFields} = closed;
  equal(closedFields, {task_id: lastReplaced.task_id, workflow_task_attempt: 1, error: 'Workflow run is already closed.',
    reason: 'run_closed', stop_reason: 'run_completed', cancel_requested: false, can_continue: false,
    run_status: 'completed', run_closed_reason: 'completed', task_status: 'completed', lease_owner: worker,
    lease_expires_at: null}, 'run closed refusal: the completed task keeps its owner and has no lease expiry');
  equal(state.history.map(event => event.event_type), definition.expected_events, 'one completion recorded');
  return projection;
}
