import assert from 'node:assert/strict';
import {gunzipSync, inflateSync, brotliDecompressSync} from 'node:zlib';

export function checkActivityHeartbeatRenewal(fixture, observation, workflowId, instant) {
  const spec = fixture.activity_deadline_receipts, state = observation.activity_deadline_receipts;
  const http = observation.mode === 'http';
  assert(['http', 'embedded'].includes(observation.mode));
  assert.equal(state.driver, http ? 'installed_sdk_worker_protocol' : 'real_queue_then_public_embedded_bridges');
  assert.equal(observation.workflow_id, workflowId);
  assert.equal(observation.workflow_type, fixture.workflow_type);
  assert.equal(observation.namespace, 'default');
  assert.equal(observation.status, 'completed');
  assert.deepEqual(observation.typed_input, {type: 'list', value: [fixture.typed_value]});
  assert.deepEqual(observation.typed_output, fixture.typed_value);
  assert.equal(state.overall_budget_seconds, spec.overall_budget_seconds);
  assert(state.global_elapsed_seconds >= spec.schedule_to_close_timeout + .25);
  assert(state.global_elapsed_seconds < spec.overall_budget_seconds);
  const policy = {snapshot_version: 1, max_attempts: spec.max_attempts,
    backoff_seconds: spec.backoff_seconds, non_retryable_error_types: [],
    start_to_close_timeout: spec.start_to_close_timeout, schedule_to_start_timeout: null,
    schedule_to_close_timeout: spec.schedule_to_close_timeout, heartbeat_timeout: spec.heartbeat_timeout};
  const first = state.first_attempt;
  assert.equal(first.workflow_id, workflowId);
  assert.equal(first.run_id, observation.run_id);
  assert.equal(first.attempt_number, 1);
  assert.deepEqual(first.retry_policy, policy);
  if (http) {
    assert.equal(state.admission.recorded, true);
    assert.equal(state.command.heartbeat_timeout, spec.heartbeat_timeout);
    for (const key of ['start_to_close_timeout', 'schedule_to_close_timeout']) assert.equal(state.command[key], spec[key]);
    assert.deepEqual(state.command.retry_policy, {max_attempts: 1, backoff_seconds: [], non_retryable_error_types: []});
  } else {
    for (const key of ['start_to_close_timeout', 'schedule_to_close_timeout', 'heartbeat_timeout', 'max_attempts']) {
      assert.equal(state.authored_options[key], spec[key]);
    }
    assert.deepEqual(state.authored_options.backoff, []);
    assert.equal(state.activity_invocations, 0);
    assert.match(state.worker_output, /RunWorkflowTask.*DONE/);
    assert.deepEqual(state.requests, []);
  }
  const response = value => http ? value.response : value;
  const one = response(state.heartbeat_one), two = response(state.heartbeat_two), late = response(state.late_heartbeat);
  if (http) {
    assert.equal(state.heartbeat_one.status, 200);
    assert.equal(state.heartbeat_two.status, 200);
    assert([200, 409].includes(state.late_heartbeat.status));
  }
  for (const value of [one, two]) {
    assert.equal(value.heartbeat_recorded, true);
    assert.equal(value.can_continue, true);
  }
  assert(instant(one.lease_expires_at) > instant(first.lease_expires_at));
  assert(instant(two.lease_expires_at) > instant(one.lease_expires_at));
  assert(instant(two.last_heartbeat_at) >= instant(state.before_expiry.events[3].timestamp) + BigInt(spec.heartbeat_timeout) * 1_000_000_000n);
  assert(instant(two.last_heartbeat_at) < instant(one.last_heartbeat_at) + BigInt(spec.heartbeat_timeout) * 1_000_000_000n);
  for (const status of [state.status_one, state.status_after_initial_expiry]) {
    assert.equal(status.can_continue, true);
    assert.equal(status.heartbeat_recorded, false);
    assert.equal(status.lease_expires_at, one.lease_expires_at);
    assert.equal(status.last_heartbeat_at, one.last_heartbeat_at);
  }
  assert.deepEqual(state.after_first_heartbeat.events, state.after_status_read.events);
  assert.equal(state.activity_completion.recorded, true);
  assert.equal(state.workflow_completion[http ? 'recorded' : 'completed'], true);
  assert.equal(late.heartbeat_recorded, false);
  assert.equal(late.can_continue, false);
  assert.deepEqual(state.committed.events, observation.events);
  assert.deepEqual(state.post_completion_activity_poll, http ? null : []);
  assert.equal(state.parent_delivery.workflow_id, workflowId);
  assert.equal(state.parent_delivery.run_id, observation.run_id);
  assert.notEqual(state.parent_delivery.task_id, state.workflow_task.task_id);
  const events = observation.events;
  assert.deepEqual(events.map(row => row.event_type), fixture.expected_events);
  assert.deepEqual(events.map(row => row.sequence), [1, 2, 3, 4, 5, 6, 7, 8]);
  for (const row of events) assert.match(row.timestamp, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/);
  for (const row of events.slice(2, 7)) {
    assert.equal(row.payload.activity_execution_id, first.activity_execution_id);
    assert.deepEqual(row.payload.activity.retry_policy, policy);
    assert.deepEqual(row.typed_decoded.activity_arguments, {type: 'list', value: [fixture.typed_value]});
  }
  for (const row of events.slice(3, 7)) assert.equal(row.payload.activity_attempt_id, first.activity_attempt_id);
  assert.deepEqual(events[4].payload.progress, {details: {message: 'first renewal λ'}});
  assert.deepEqual(events[5].payload.progress, {details: {message: 'second renewal λ'}});
  assert.deepEqual(events[6].typed_decoded.result, fixture.typed_value);
  assert.deepEqual(events[7].typed_decoded.output, fixture.typed_value);
  assert(instant(events[6].timestamp) < instant(events[3].timestamp) + BigInt(spec.start_to_close_timeout) * 1_000_000_000n);
  assert(instant(events[6].timestamp) < instant(events[2].timestamp) + BigInt(spec.schedule_to_close_timeout) * 1_000_000_000n);
  for (const snapshot of [state.before_expiry, state.after_first_heartbeat, state.after_status_read, state.after_second_heartbeat]) {
    assert.equal(snapshot.run_id, observation.run_id);
    assert.deepEqual(events.slice(0, snapshot.events.length), snapshot.events);
    for (const key of ['run_deadline_at', 'execution_deadline_at']) assert.equal(snapshot.execution[key], observation.execution[key]);
  }
  assert.equal(state.before_expiry.events.length, 4);
  assert.equal(state.after_first_heartbeat.events.length, 5);
  assert.equal(state.after_second_heartbeat.events.length, 6);
  if (http) {
    const wire = state.requests.map(row => {
      assert.equal(row.transport_error, null);
      assert.equal(row.client_cancelled, false);
      const decode = {identity: x => x, gzip: gunzipSync, deflate: inflateSync, br: brotliDecompressSync}[row.response_encoding];
      assert(decode);
      assert.equal(decode(Buffer.from(row.response_bytes_base64, 'base64')).toString('utf8'), row.response_body);
      return row;
    });
    const heartbeats = wire.filter(row => row.method === 'POST' && /\/activity-tasks\/[^/]+\/heartbeat$/.test(row.path));
    assert.equal(heartbeats.length, 3);
    for (const [i, row] of heartbeats.entries()) {
      const original = [state.heartbeat_one, state.heartbeat_two, state.late_heartbeat][i];
      assert.equal(row.status, original.status);
      assert.deepEqual(JSON.parse(row.response_body), original.response);
      assert.equal(JSON.parse(row.request_body).activity_attempt_id, first.activity_attempt_id);
    }
  }
  return {fixture_id: fixture.id, workflow_id: workflowId, status: 'completed',
    attempts: 1, heartbeats: 2, status_renews: false, activity_completions: 1,
    workflow_completions: 1, post_terminal_progress: 'refused', policy};
}
