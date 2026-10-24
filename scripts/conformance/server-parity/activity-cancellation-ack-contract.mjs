import assert from 'node:assert/strict';

const MICROS = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/;
const ULID = /^[0-9a-z]{26}$/;
const REQUEST = /^[0-9A-Z]{26}$/;
const ACKS = ['before_request', 'before_delivery', 'foreign_owner', 'unknown_attempt', 'unknown_task', 'task_mismatch',
  'other_namespace', 'protocol_1_19', 'protocol_missing', 'protocol_1_21', 'missing_fields', 'non_string_fields',
  'long_request_id', 'wrong_request_id', 'acknowledged', 'duplicate', 'legacy_ack', 'immediate_ack',
  'stale_first_attempt_own_task', 'stale_first_attempt_new_task', 'stale_second_attempt_old_task', 'stale_second_attempt',
  'terminated_ack'];
const MAIN_EVENTS = ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled', 'ActivityStarted',
  'CooperativeCancellationRequested', 'ActivityCancelled', 'CooperativeCancellationDelivered'];

// Milliseconds since the epoch of a UTC timestamp with any fraction length.
function at(value, label) {
  assert.ok(typeof value === 'string', `${label}: a timestamp, got ${JSON.stringify(value)}`);
  const millis = Date.parse(value.replace(/\.(\d{3})\d*Z$/, '.$1Z'));
  assert.ok(!Number.isNaN(millis), `${label}: ${value}`);
  return millis;
}

// Reviewed activity cancellation acknowledgement
// (ActivityTaskController::acknowledgeCancellation over Workflow's
// ActivityCancellationAcknowledgement::recordStopped), recorded against
// published Server 2.11.1. Identities and times come from the observation;
// their relations and every other field are exact.
//
// One documented reference boundary: published 2.11.1 delivers a cooperative
// cancellation of a retried activity to the execution's first task, not the
// live attempt's task (Workflow #923), so that attempt's acknowledgement is
// refused. The retried-attempt case accepts either the correct outcome or
// exactly that defect's signature, and nothing else; native's own execution
// tests require the correct outcome.
export function checkActivityCancellationAcknowledgement(fixture, observation, workflowId) {
  const definition = fixture.activity_cancellation_acknowledgement;
  const state = observation.activity_cancellation_acknowledgement;
  const projection = {scope: 'activity_cancellation_acknowledgement', cleanup_timeout_seconds: definition.cleanup_timeout_seconds};
  assert.ok(state && typeof state === 'object', 'actual cancellation acknowledgement observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded cancellation acknowledgement explicitly inapplicable');
    return projection;
  }
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const main = state.main;
  equal([state.applicable, state.namespace, main.workflow_id], [true, `${workflowId}-cack`.toLowerCase(), `${workflowId}-main`],
    'acknowledgement namespace and run');
  equal(state.discovery, {activity_cancellation_acknowledgement: true, cooperative_cancellation: true},
    'cluster info advertises cooperative cancellation and its acknowledgement');
  for (const key of ACKS) {
    equal([state[key]?.response?.protocol_version, state[key]?.acknowledgement_capability], ['1.20', true],
      `${key} answers in worker protocol 1.20 and advertises acknowledgement`);
  }

  // A cancellation request on the main run, read back from its workflow task.
  equal(state.request.status, 202, 'cancellation requested');
  const {cancellation_request: request, ...requested} = state.request.response;
  equal(requested, {workflow_id: main.workflow_id, run_id: main.run_id, accepted: true, duplicate: false, run_status: 'waiting',
    reason: null}, 'cancellation request receipt');
  assert.match(request.request_id, REQUEST, 'request id');
  assert.match(request.requested_at, MICROS, 'request time in UTC microseconds');
  equal(at(request.cleanup_deadline_at, 'cleanup deadline') - at(request.requested_at, 'request time'),
    definition.cleanup_timeout_seconds * 1000, 'the cleanup budget runs from the request');
  equal([request.delivery_sequence, request.delivered_at, request.history_refresh_page_token], [null, null, 'MA=='],
    'request not yet delivered');
  equal(state.poll_cancellation_request, request, 'the workflow task carries the request');
  const requestId = request.request_id;

  const ack = (claim, requestValue, fields) => ({task_id: claim.task_id, activity_attempt_id: claim.activity_attempt_id,
    lease_owner: claim.lease_owner, request_id: requestValue, acknowledged: false, duplicate: false, reason: null,
    history_event_id: null, heartbeat_recorded: false, ...fields, protocol_version: '1.20'});
  const refused = (key, claim, requestValue, reason) => equal(state[key],
    {status: 409, response: ack(claim, requestValue, {reason}), acknowledgement_capability: true}, key);
  refused('before_request', main, 'not-yet-requested', 'cancellation_request_mismatch');
  refused('before_delivery', main, requestId, 'activity_cancellation_not_recorded');

  // Workflow's attempt status for a claim, before any field is overridden.
  const status = (claim, fields = {}) => ({task_id: claim.task_id, activity_attempt_id: claim.activity_attempt_id,
    lease_owner: claim.lease_owner, can_continue: true, cancel_requested: false, reason: null, heartbeat_recorded: false,
    last_heartbeat_at: null, run_status: 'waiting', run_closed_reason: null, run_closed_at: null, activity_status: 'running',
    attempt_status: 'running', task_status: 'leased', worker_session: null, ...fields, protocol_version: '1.20'});
  const withoutTimes = (key, recorded) => {
    equal(recorded.status, 200, `${key} answers`);
    const {lease_expires_at: lease, deadlines, ...body} = recorded.response;
    equal(Object.keys(deadlines), ['start_to_close'], `${key} deadlines`);
    assert.match(deadlines.start_to_close, MICROS, `${key} start-to-close deadline`);
    return {lease, deadline: deadlines.start_to_close, body};
  };
  const pending = withoutTimes('status_before_delivery', state.status_before_delivery);
  assert.match(pending.lease, MICROS, 'a pending request leaves the lease');
  equal(pending.body, status(main), 'a pending request does not stop the attempt');

  // Delivery to the activity cancels its attempt; the worker learns it from
  // its heartbeat and finds the request on its status.
  const deliveryBody = (claim, requestValue) => ({delivered: true, task_id: state.delivery.response.task_id,
    workflow_run_id: claim.run_id, request_id: requestValue, sequence: 1, call_kind: 'activity', sequence_span: 1,
    operation_sequence: null, operation_sequence_span: 1, reason: null, protocol_version: '1.20'});
  equal(state.delivery, {status: 200, response: deliveryBody(main, requestId), acknowledgement_capability: true}, 'delivered');
  assert.match(state.delivery.response.task_id, ULID, 'delivered from the cancellation workflow task');
  equal(state.heartbeat_after_delivery, {status: 200, acknowledgement_capability: true, response: {task_id: main.task_id,
    activity_attempt_id: main.activity_attempt_id, lease_owner: main.lease_owner, cancel_requested: true, can_continue: false,
    reason: 'attempt_closed', run_closed_reason: null, run_closed_at: null, heartbeat_recorded: false, lease_expires_at: null,
    last_heartbeat_at: null, worker_session: null, protocol_version: '1.20'}}, 'heartbeat reports the stop');
  const stopped = (claim) => status(claim, {can_continue: false, cancel_requested: true, reason: 'attempt_closed',
    activity_status: 'cancelled', attempt_status: 'cancelled', task_status: 'cancelled'});
  const receipt = (cancellationEvent, fields = {}) => ({request_id: requestId, root_request_id: requestId,
    cleanup_deadline_at: request.cleanup_deadline_at, cancellation_history_event_id: cancellationEvent, callback_state: 'unknown',
    history_event_id: null, acknowledged_at: null, received_after_deadline: null, ...fields});
  const unacknowledged = withoutTimes('status_before_ack', state.status_before_ack);
  equal([unacknowledged.lease, unacknowledged.deadline], [null, pending.deadline], 'delivery ends the lease, not the deadline');
  const {cancellation_acknowledgement: before, ...beforeBody} = unacknowledged.body;
  const cancellationEvent = before?.cancellation_history_event_id;
  assert.match(cancellationEvent ?? '', ULID, 'the receipt names the ActivityCancelled event');
  equal(beforeBody, stopped(main), 'the delivered attempt is stopped');
  equal(before, receipt(cancellationEvent), 'the receipt before acknowledgement');
  equal(state.history_before_ack.map(event => event.event_type), MAIN_EVENTS, 'request and delivery history');

  // Ownership in PHP's order and shape, protocol, then Laravel's validation.
  // None records anything.
  const fault = (key, code, task, attempt, error, reason, extra = {}) => equal(state[key], {status: code,
    acknowledgement_capability: true, response: {task_id: task, activity_attempt_id: attempt, error, reason, ...extra,
      protocol_version: '1.20'}}, key);
  fault('foreign_owner', 409, main.task_id, main.activity_attempt_id, 'Activity attempt lease is owned by another worker.',
    'lease_owner_mismatch', {lease_owner: main.lease_owner});
  fault('unknown_attempt', 404, main.task_id, 'no-such-attempt', 'Activity attempt not found.', 'attempt_not_found');
  fault('unknown_task', 404, 'no-such-task', main.activity_attempt_id, 'Activity task not found.', 'task_not_found');
  fault('task_mismatch', 409, main.workflow_task_id, main.activity_attempt_id, 'Activity attempt is not leased for this task.',
    'task_mismatch');
  fault('other_namespace', 404, main.task_id, main.activity_attempt_id, 'Activity task not found.', 'task_not_found');
  equal(state.protocol_1_19, {status: 422, acknowledgement_capability: true, response: {acknowledged: false,
    reason: 'activity_cancellation_acknowledgement_not_supported', minimum_protocol_version: '1.20',
    unavailable: ['worker_protocol'], protocol_version: '1.20'}}, 'a 1.19 request');
  for (const key of ['protocol_missing', 'protocol_1_21', 'missing_fields', 'non_string_fields', 'long_request_id']) {
    const {status: code, ...body} = definition.refusals[key];
    equal(state[key], {status: code, acknowledgement_capability: true, response: {...body, protocol_version: '1.20'}}, key);
  }
  refused('wrong_request_id', main, 'other-request', 'cancellation_request_mismatch');
  equal(state.history_after_refusals, state.history_before_ack, 'refusals record nothing');

  // The acknowledgement: one event, then the receipt reads stopped and a
  // repeat returns the same event.
  const acknowledgedEvent = state.acknowledged?.response?.history_event_id;
  assert.match(acknowledgedEvent ?? '', ULID, 'acknowledgement event id');
  equal(state.acknowledged, {status: 200, acknowledgement_capability: true,
    response: ack(main, requestId, {acknowledged: true, history_event_id: acknowledgedEvent})}, 'acknowledged');
  const acknowledgedStatus = withoutTimes('status_after_ack', state.status_after_ack);
  const {cancellation_acknowledgement: after, ...afterBody} = acknowledgedStatus.body;
  equal([acknowledgedStatus.lease, acknowledgedStatus.deadline, afterBody], [null, pending.deadline, stopped(main)],
    'acknowledgement changes no attempt state');
  assert.match(after?.acknowledged_at ?? '', MICROS, 'acknowledgement time in UTC microseconds');
  equal(after, receipt(cancellationEvent, {callback_state: 'stopped', history_event_id: acknowledgedEvent,
    acknowledged_at: after.acknowledged_at, received_after_deadline: false}), 'the receipt after acknowledgement');
  assert.ok(at(after.acknowledged_at, 'acknowledged') < at(request.cleanup_deadline_at, 'deadline'), 'acknowledged within the budget');
  equal(state.duplicate, {status: 200, acknowledgement_capability: true,
    response: ack(main, requestId, {acknowledged: true, duplicate: true, history_event_id: acknowledgedEvent})}, 'duplicate');
  equal(state.completed_after_ack, {status: 409, acknowledgement_capability: true, response: {task_id: main.task_id,
    activity_attempt_id: main.activity_attempt_id, outcome: 'completed', recorded: false, reason: 'stale_attempt',
    next_task_id: null, lease_owner: main.lease_owner, activity_status: 'cancelled', attempt_status: 'cancelled',
    task_status: 'cancelled', protocol_version: '1.20'}}, 'a later outcome is refused');
  const history = state.history_after_ack;
  equal(history.slice(0, -1), state.history_before_ack, 'acknowledgement only appends');
  const event = history.at(-1);
  const delivered = history.at(-2);
  const {task, ...payload} = event.payload;
  equal([event.event_type, event.sequence, event.principal], ['ActivityCancellationAcknowledged', delivered.sequence + 1, null],
    'one acknowledgement event');
  equal(payload, {sequence: 1, activity_execution_id: main.activity_execution_id, activity_attempt_id: main.activity_attempt_id,
    lease_owner: main.lease_owner, cancellation_history_event_id: cancellationEvent, request_id: requestId,
    root_request_id: requestId, cleanup_deadline_at: request.cleanup_deadline_at, callback_state: 'stopped',
    evidence_source: 'activity_worker', acknowledged_at: after.acknowledged_at, received_after_deadline: false},
    'acknowledgement payload');
  const {available_at: available, leased_at: leased, connection, ...snapshot} = task;
  assert.match(available, MICROS, 'snapshot availability');
  assert.match(leased, MICROS, 'snapshot lease time');
  const cancelledTask = history.find(item => item.event_type === 'ActivityCancelled')?.payload?.task;
  equal([cancelledTask?.id, cancelledTask?.status], [main.task_id, 'cancelled'], 'delivery snapshots the cancelled activity task');
  assert.ok(typeof connection === 'string' && connection !== '', 'snapshot connection');
  equal(connection, cancelledTask.connection, 'both activity task snapshots name one storage connection');
  equal(snapshot, {id: main.task_id, type: 'activity', status: 'cancelled', lease_owner: main.lease_owner, attempt_count: 1,
    repair_count: 0, queue: main.queue}, 'acknowledgement snapshots the cancelled activity task, without a lease');

  // A claim polled on protocol 1.19 cannot acknowledge.
  const legacy = state.legacy_claim;
  equal([state.legacy_request, state.legacy_delivery.status, state.legacy_delivery.response.delivered], [202, 200, true],
    'legacy claim cancelled cooperatively');
  equal(state.legacy_ack, {status: 409, acknowledgement_capability: true, response: {acknowledged: false,
    reason: 'activity_cancellation_acknowledgement_not_supported', required_capability: 'cooperative_cancellation',
    minimum_protocol_version: '1.20', worker_id: legacy.lease_owner, unavailable: ['worker_claim_capability'],
    remediation: 'Use a runtime with activity stop acknowledgements and an original claim from a cooperative worker on protocol 1.20 or newer.',
    protocol_version: '1.20'}}, 'a 1.19 claim cannot acknowledge');

  // An immediate cancel records no cooperative request and no receipt.
  const immediate = state.immediate_claim;
  equal(state.immediate_cancel.status, 200, 'immediate cancel accepted');
  const commandId = state.immediate_cancel.response.command_id;
  assert.match(commandId, ULID, 'immediate cancel command');
  refused('immediate_ack', immediate, commandId, 'cancellation_request_mismatch');
  const cancelled = withoutTimes('immediate_status', state.immediate_status);
  assert.match(cancelled.body.run_closed_at ?? '', MICROS, 'immediate cancel closes the run');
  equal([cancelled.lease, cancelled.body], [null, status(immediate, {can_continue: false, cancel_requested: true,
    reason: 'run_cancelled', run_status: 'cancelled', run_closed_reason: 'cancelled', run_closed_at: cancelled.body.run_closed_at,
    activity_status: 'cancelled', attempt_status: 'cancelled', task_status: 'cancelled'})], 'immediate cancel status, no receipt');

  // A retried attempt: the first attempt is fenced, and each attempt belongs
  // to its own task.
  const [first, second] = [state.stale_first, state.stale_second];
  equal([state.stale_fail.status, state.stale_fail.response.recorded, state.stale_request, state.stale_delivery.status,
    state.stale_delivery.response.delivered], [200, true, 202, 200, true], 'retried attempt cancelled cooperatively');
  assert.notEqual(first.task_id, second.task_id, 'the retry has its own task');
  const staleRequest = state.stale_delivery.response.request_id;
  refused('stale_first_attempt_own_task', first, staleRequest, 'activity_cancellation_acknowledgement_fence_mismatch');
  fault('stale_first_attempt_new_task', 409, second.task_id, first.activity_attempt_id,
    'Activity attempt is not leased for this task.', 'task_mismatch');
  fault('stale_second_attempt_old_task', 409, first.task_id, second.activity_attempt_id,
    'Activity attempt is not leased for this task.', 'task_mismatch');
  const staleCancelled = state.stale_history.find(item => item.event_type === 'ActivityCancelled');
  equal(staleCancelled?.payload?.activity_attempt?.id, second.activity_attempt_id, 'the live attempt is cancelled');
  const correct = staleCancelled.payload.task?.id === second.task_id;
  if (correct) {
    equal(state.stale_second_attempt.status, 200, 'the live attempt acknowledges');
    equal(state.stale_second_attempt.response.acknowledged, true, 'the live attempt acknowledges');
  } else {
    // Workflow #923 in published 2.11.1: the delivery cancels the first,
    // already completed task, so the live task stays leased and the
    // acknowledgement is refused as not fenced.
    equal(staleCancelled.payload.task?.id, first.task_id, 'reference defect: delivery snapshots the first task');
    equal([state.stale_second_status.response.attempt_status, state.stale_second_status.response.task_status],
      ['cancelled', 'leased'], 'reference defect: the live task stays leased');
    refused('stale_second_attempt', second, staleRequest, 'activity_cancellation_not_fenced');
  }

  // A run terminated after delivery still records the late stop, after its
  // terminal event.
  const terminated = state.terminated_claim;
  equal([state.terminated_request, state.terminated_delivery.status, state.terminate], [202, 200, 200],
    'terminated after delivery');
  const terminatedEvent = state.terminated_ack?.response?.history_event_id;
  assert.match(terminatedEvent ?? '', ULID, 'terminated acknowledgement event id');
  equal(state.terminated_ack, {status: 200, acknowledgement_capability: true, response: ack(terminated,
    state.terminated_delivery.response.request_id, {acknowledged: true, history_event_id: terminatedEvent})},
    'a terminated run still acknowledges');
  equal(state.terminated_history, [...MAIN_EVENTS, 'TerminateRequested', 'WorkflowTerminated', 'ActivityCancellationAcknowledged'],
    'the late acknowledgement follows the terminal event');
  return projection;
}
