import assert from 'node:assert/strict';

const MICROS = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/;
const RENEWALS = ['renewed', 'renewed_on_protocol_1_19', 'extra_field', 'foreign_owner', 'unknown_attempt',
  'unknown_task', 'task_mismatch', 'missing_fields', 'non_string_fields', 'renewed_after_heartbeat',
  'renewed_after_completion', 'renewed_after_cancel', 'renewed_after_terminate'];

// Milliseconds since the epoch of a UTC timestamp with any fraction length.
function at(value, label) {
  assert.ok(typeof value === 'string', `${label}: a timestamp, got ${JSON.stringify(value)}`);
  const millis = Date.parse(value.replace(/\.(\d{3})\d*Z$/, '.$1Z'));
  assert.ok(!Number.isNaN(millis), `${label}: ${value}`);
  return millis;
}

// Reviewed runtime activity lease renewal (ActivityTaskController::renewLease
// over Workflow's ActivityLeaseRenewal). Identities, times and leases come
// from the observation; their relations and every other field are exact.
export function checkActivityLeaseRenewal(fixture, observation, workflowId) {
  const definition = fixture.activity_lease_renewal;
  const state = observation.activity_lease_renewal;
  const projection = {scope: 'activity_lease_renewal', lease_seconds: definition.lease_seconds};
  assert.ok(state && typeof state === 'object', 'actual lease renewal observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded lease renewal explicitly inapplicable');
    return projection;
  }
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const live = state.live;
  equal([state.applicable, state.namespace, live.workflow_id], [true, `${workflowId}-lease`.toLowerCase(), `${workflowId}-live`],
    'renewal namespace and run');
  for (const key of RENEWALS) {
    equal([state[key]?.response?.protocol_version, state[key]?.lease_renewal_capability], ['1.20', true],
      `${key} answers in worker protocol 1.20 and advertises renewal`);
  }
  // Workflow's attemptStatus for a claim, before any field is overridden.
  const attempt = (claim, fields = {}) => ({can_continue: true, cancel_requested: false, reason: null, heartbeat_recorded: false,
    workflow_instance_id: claim.workflow_id, workflow_run_id: claim.run_id, workflow_task_id: claim.task_id,
    activity_execution_id: claim.activity_execution_id, activity_attempt_id: claim.activity_attempt_id, attempt_number: 1,
    run_status: 'waiting', run_closed_reason: null, run_closed_at: null, activity_status: 'running', attempt_status: 'running',
    task_status: 'leased', lease_owner: claim.lease_owner, ...fields});

  // A live renewal: only the lease moves.
  const before = state.status_before.response;
  equal([state.status_before.status, before.can_continue], [200, true], 'the claim is live');
  equal(state.renewed.status, 200, 'renewal accepted');
  const {lease_expires_at: lease, server_time: serverTime, heartbeat_deadline_at: heartbeatDeadline, protocol_version: _p,
    ...renewed} = state.renewed.response;
  equal(renewed, {...attempt(live), last_heartbeat_at: null, lease_renewed: true, task_id: live.task_id, worker_session: null},
    'renewal status');
  assert.match(lease, MICROS, 'lease in UTC microseconds');
  assert.match(serverTime, MICROS, 'server time in UTC microseconds');
  const window = (at(lease, 'lease') - at(serverTime, 'server time')) / 1000;
  assert.ok(window > definition.lease_seconds - 1 && window <= definition.lease_seconds,
    `the lease runs ${definition.lease_seconds} s from the renewal, not ${window}`);
  assert.ok(at(lease, 'lease') > at(before.lease_expires_at, 'claim lease'), 'renewal extends the claim lease');
  equal(heartbeatDeadline, before.deadlines.heartbeat, 'renewal leaves the progress deadline');
  equal(at(state.status_after.response.lease_expires_at, 'status lease'), at(lease, 'lease'), 'status reads the renewed lease');
  equal(state.status_after.response.last_heartbeat_at, null, 'renewal records no heartbeat');
  equal(state.history_after, state.history_before, 'renewal adds no history');
  for (const key of ['renewed_on_protocol_1_19', 'extra_field']) {
    equal([state[key].status, state[key].response.lease_renewed], [200, true], `${key} renews`);
  }

  // Ownership, in PHP's order and shape, and Laravel's validation.
  const refusal = (status, task, attempt, error, reason, extra = {}) => ({status, lease_renewal_capability: true,
    response: {task_id: task, activity_attempt_id: attempt, error, reason, ...extra, protocol_version: '1.20'}});
  equal(state.foreign_owner, refusal(409, live.task_id, live.activity_attempt_id, 'Activity attempt lease is owned by another worker.',
    'lease_owner_mismatch', {lease_owner: live.lease_owner}), 'another owner');
  equal(state.unknown_attempt, refusal(404, live.task_id, 'no-such-attempt', 'Activity attempt not found.', 'attempt_not_found'),
    'unknown attempt');
  equal(state.unknown_task, refusal(404, 'no-such-task', live.activity_attempt_id, 'Activity task not found.', 'task_not_found'),
    'unknown task');
  equal(state.task_mismatch, refusal(409, live.workflow_task_id, live.activity_attempt_id,
    'Activity attempt is not leased for this task.', 'task_mismatch'), 'another task of the namespace');
  for (const key of ['missing_fields', 'non_string_fields']) {
    equal(state[key], {status: 422, lease_renewal_capability: true,
      response: {...definition.validation[key], protocol_version: '1.20'}}, key);
  }

  // A heartbeat's progress is reported; renewal keeps its deadline.
  equal([state.heartbeat.status, state.heartbeat.response.heartbeat_recorded], [200, true], 'heartbeat recorded');
  const beat = at(state.heartbeat.response.last_heartbeat_at, 'heartbeat');
  const afterBeat = state.renewed_after_heartbeat.response;
  equal([state.renewed_after_heartbeat.status, afterBeat.lease_renewed, afterBeat.heartbeat_recorded], [200, true, false],
    'renewal after a heartbeat');
  equal(at(afterBeat.last_heartbeat_at, 'reported heartbeat'), beat, 'the recorded heartbeat is reported');
  equal(at(afterBeat.heartbeat_deadline_at, 'heartbeat deadline') - beat, definition.heartbeat_timeout * 1000,
    'the heartbeat keeps its own deadline');

  // Closed attempts are refused with their status and no lease.
  equal(state.completed.status, 200, 'activity completed');
  const {last_heartbeat_at: closedBeat, heartbeat_deadline_at: closedDeadline, protocol_version: _c, ...closed} =
    state.renewed_after_completion.response;
  equal(state.renewed_after_completion.status, 409, 'a completed attempt is refused');
  equal(closed, {...attempt(live, {can_continue: false, reason: 'attempt_closed', activity_status: 'completed',
    attempt_status: 'completed', task_status: 'completed'}), lease_expires_at: null, lease_renewed: false}, 'completed attempt status');
  equal([at(closedBeat, 'closed heartbeat'), closedDeadline], [beat, afterBeat.heartbeat_deadline_at], 'closed attempt keeps its heartbeat');
  const stopped = (key, claim, command, runStatus, reason) => {
    equal(state[command], 200, `${command} accepted`);
    const {run_closed_at: closedAt, heartbeat_deadline_at: deadline, protocol_version: _s, ...body} = state[key].response;
    equal(state[key].status, 409, `${key} refused`);
    assert.match(closedAt, MICROS, `${key} close time in UTC microseconds`);
    at(deadline, `${key} heartbeat deadline`);
    const {run_closed_at: _r, ...expected} = attempt(claim, {can_continue: false, cancel_requested: true, reason, run_status: runStatus,
      run_closed_reason: runStatus, activity_status: 'cancelled', attempt_status: 'cancelled', task_status: 'cancelled'});
    equal(body, {...expected, lease_expires_at: null, last_heartbeat_at: null, lease_renewed: false}, `${key} status`);
  };
  stopped('renewed_after_cancel', state.cancelled_claim, 'cancel', 'cancelled', 'run_cancelled');
  stopped('renewed_after_terminate', state.terminated_claim, 'terminate', 'terminated', 'run_terminated');
  return projection;
}
