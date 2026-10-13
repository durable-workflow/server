import assert from 'node:assert/strict';
import {gunzipSync, inflateSync, brotliDecompressSync} from 'node:zlib';

export function checkActivityGlobalRetry(fixture, observation, workflowId, instant) {
  const spec = fixture.activity_deadline_receipts, state = observation.activity_deadline_receipts;
  const http = observation.mode === 'http';
  assert(['http', 'embedded'].includes(observation.mode));
  assert.equal(observation.workflow_id, workflowId);
  assert.equal(observation.workflow_type, fixture.workflow_type);
  assert.equal(observation.namespace, 'default');
  assert.equal(observation.status, 'global_deadline_observed');
  assert.deepEqual(observation.typed_input, {type: 'list', value: [fixture.typed_value]});
  assert.deepEqual(observation.typed_output, {type: 'null', value: null});
  assert.equal(state.driver, http ? 'installed_sdk_worker_protocol' : 'real_queue_then_public_embedded_bridges');
  assert.equal(state.overall_budget_seconds, spec.overall_budget_seconds);
  assert(state.global_elapsed_seconds >= spec.schedule_to_close_timeout + .25);
  assert(state.global_elapsed_seconds < spec.overall_budget_seconds);
  const policy = {snapshot_version: 1, max_attempts: spec.max_attempts,
    backoff_seconds: spec.backoff_seconds, non_retryable_error_types: [],
    start_to_close_timeout: spec.start_to_close_timeout, schedule_to_start_timeout: null,
    schedule_to_close_timeout: spec.schedule_to_close_timeout, heartbeat_timeout: null};
  const first = state.first_attempt, second = state.second_attempt;
  for (const [index, task] of [first, second].entries()) {
    assert.equal(task.workflow_id, workflowId);
    assert.equal(task.run_id, observation.run_id);
    assert.equal(task.activity_execution_id, first.activity_execution_id);
    assert.equal(task.attempt_number, index + 1);
    assert.deepEqual(task.retry_policy, policy);
  }
  assert.notEqual(first.task_id, second.task_id);
  assert.notEqual(first.activity_attempt_id, second.activity_attempt_id);
  assert(second.attempt_number < spec.max_attempts, 'Global deadline wins while another attempt remains');
  if (http) {
    assert.equal(state.admission.recorded, true);
    assert.equal(state.command.start_to_close_timeout, spec.start_to_close_timeout);
    assert.equal(state.command.schedule_to_close_timeout, spec.schedule_to_close_timeout);
    assert.deepEqual(state.command.retry_policy, {max_attempts: spec.max_attempts,
      backoff_seconds: spec.backoff_seconds, non_retryable_error_types: []});
    assert.equal(state.first_refusal.status, 409);
  } else {
    for (const key of ['max_attempts', 'start_to_close_timeout', 'schedule_to_close_timeout']) {
      assert.equal(state.authored_options[key], spec[key]);
    }
    assert.deepEqual(state.authored_options.backoff, spec.backoff_seconds);
    assert.match(state.worker_output, /RunWorkflowTask.*DONE/);
    assert.equal(state.activity_invocations, 0);
    assert.equal(state.first_enforcement.enforced, true);
    assert.equal(state.global_enforcement.enforced, true);
    assert.equal(state.first_refusal.recorded, false);
    assert.deepEqual(state.requests, []);
  }
  const events = observation.events;
  assert.deepEqual(events.map(row => row.event_type), fixture.expected_events);
  assert.deepEqual(events.map(row => row.sequence), [1, 2, 3, 4, 5, 6, 7]);
  for (const row of events) assert.match(row.timestamp, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/);
  for (const snapshot of [state.before_expiry, state.after_retry]) {
    assert.equal(snapshot.workflow_id, workflowId);
    assert.equal(snapshot.run_id, observation.run_id);
    assert.deepEqual(events.slice(0, snapshot.events.length), snapshot.events);
    for (const name of ['execution_deadline_at', 'run_deadline_at']) {
      assert.equal(snapshot.execution[name], observation.execution[name]);
    }
  }
  assert.equal(state.before_expiry.events.length, 4);
  assert.equal(state.after_retry.events.length, 6);
  for (const row of events.slice(2)) {
    assert.equal(row.payload.activity_execution_id, first.activity_execution_id);
    assert.deepEqual(row.payload.activity.retry_policy, policy);
  }
  assert.deepEqual(events[2].typed_decoded.arguments, {type: 'list', value: [fixture.typed_value]});
  const retry = events[4], started = events[5], timeout = events[6];
  assert.equal(retry.payload.timeout_kind, 'start_to_close');
  assert.equal(retry.payload.retry_after_attempt_id, first.activity_attempt_id);
  assert.equal(retry.payload.retry_task_id, second.task_id);
  assert.equal(retry.payload.retry_of_task_id, first.task_id);
  assert.equal(retry.payload.retry_backoff_seconds, spec.backoff_seconds[0]);
  assert(instant(started.timestamp) >= instant(retry.payload.retry_available_at));
  assert.equal(started.payload.activity_attempt_id, second.activity_attempt_id);
  assert.equal(timeout.payload.activity_attempt_id, second.activity_attempt_id);
  assert.equal(timeout.payload.timeout_kind, 'schedule_to_close');
  const originalDeadline = http ? timeout.payload.schedule_to_close_deadline_at
    : state.before_expiry.activities[0].schedule_to_close_deadline_at;
  assert(instant(timeout.timestamp) >= instant(originalDeadline), 'Never accept early firing at the recorded precision');
  if (!http) {
    assert.equal(state.after_retry.activities[0].schedule_to_close_deadline_at, originalDeadline);
    assert.equal(observation.activities[0].schedule_to_close_deadline_at, originalDeadline);
  }
  assert(instant(timeout.timestamp) < instant(started.timestamp) + BigInt(spec.start_to_close_timeout) * 1_000_000_000n,
    'Global expiry precedes the second attempt close deadline');
  assert.equal(state.status_after_expiry.can_continue, false);
  assert.deepEqual(state.late_outcomes.map(row => row.id), ['complete', 'fail', 'heartbeat']);
  for (const row of state.late_outcomes) {
    const result = row.response;
    if (http && row.id !== 'heartbeat') assert.equal(row.status, 409);
    if ('recorded' in result) assert.equal(result.recorded, false);
    if ('heartbeat_recorded' in result) assert.equal(result.heartbeat_recorded, false);
    if ('can_continue' in result) assert.equal(result.can_continue, false);
    if (!http && row.id !== 'heartbeat') assert.equal(result.recorded, false);
    if (row.id === 'heartbeat') {
      if (http) assert([200, 409].includes(row.status));
      if (!http || row.status === 200) {
        assert.equal(result.can_continue, false);
        assert.equal(result.heartbeat_recorded, false);
        if (result.lease_expires_at !== null) assert.equal(result.lease_expires_at, state.status_after_expiry.lease_expires_at);
        assert.equal(result.last_heartbeat_at, state.status_after_expiry.last_heartbeat_at);
      }
    }
  }
  assert(state.post_expiry_activity_polls.length > 0);
  for (const poll of state.post_expiry_activity_polls) assert.deepEqual(poll, http ? null : []);
  assert.deepEqual(state.extra_parent_poll, http ? null : []);
  assert.equal(state.parent_delivery.workflow_id, workflowId);
  assert.equal(state.parent_delivery.run_id, observation.run_id);
  assert.notEqual(state.parent_delivery.task_id, state.workflow_task.task_id);
  assert.equal(state.parent_delivery.workflow_task_attempt, 1);
  for (const row of state.requests) {
    assert.equal(row.transport_error, null);
    assert.equal(row.client_cancelled, false);
    const decode = {identity: x => x, gzip: gunzipSync, deflate: inflateSync, br: brotliDecompressSync}[row.response_encoding];
    assert(decode);
    assert.equal(decode(Buffer.from(row.response_bytes_base64, 'base64')).toString('utf8'), row.response_body);
  }
  return {fixture_id: fixture.id, workflow_id: workflowId, status: 'global_deadline_observed',
    attempts: 2, retries: 1, terminal_timeout: 'schedule_to_close', activity_completions: 0,
    workflow_completions: 0, parent_timeout_delivery: true, policy};
}
