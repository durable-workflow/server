import assert from 'node:assert/strict';
import {gunzipSync, inflateSync, brotliDecompressSync} from 'node:zlib';

export function checkActivityDeadlineReceiptPackages(fixture, observation, artifacts) {
  if (!fixture.activity_deadline_receipts) return;
  assert.equal(observation.sdk_php, artifacts.sdk_php);
  assert.equal(observation.sdk_php_source, artifacts.sdk_php_source_commit);
  assert.equal(observation.sdk_composer_lock_sha256, artifacts.sdk_composer_lock_sha256);
  assert.deepEqual(observation.sdk_loaded_sources, artifacts.published_sdk_artifacts.php.loaded_sources);
}

export function checkActivityDeadlineReceipts(fixture, observation, workflowId, instant) {
  const spec = fixture.activity_deadline_receipts, state = observation.activity_deadline_receipts;
  assert.equal(observation.mode, 'http', 'Embedded counterpart remains an explicit required follow-through');
  assert.equal(state.driver, 'installed_sdk_worker_protocol');
  assert.equal(observation.workflow_id, workflowId);
  assert.equal(observation.workflow_type, fixture.workflow_type);
  assert.equal(observation.namespace, 'default');
  assert.equal(observation.status, 'completed');
  assert.deepEqual(observation.typed_input, {type: 'list', value: [fixture.typed_value]});
  assert.deepEqual(observation.typed_output, fixture.typed_value);
  assert.equal(state.overall_budget_seconds, spec.overall_budget_seconds);
  assert(state.global_elapsed_seconds >= spec.schedule_to_close_timeout + 0.25);
  assert(state.global_elapsed_seconds < spec.overall_budget_seconds);
  const policy = {snapshot_version: 1, max_attempts: spec.max_attempts,
    backoff_seconds: spec.backoff_seconds, non_retryable_error_types: [],
    start_to_close_timeout: spec.start_to_close_timeout,
    schedule_to_start_timeout: null, schedule_to_close_timeout: spec.schedule_to_close_timeout,
    heartbeat_timeout: null};
  assert.deepEqual(state.command.retry_policy, {max_attempts: spec.max_attempts,
    backoff_seconds: spec.backoff_seconds, non_retryable_error_types: []});
  assert.equal(state.command.start_to_close_timeout, spec.start_to_close_timeout);
  assert.equal(state.command.schedule_to_close_timeout, spec.schedule_to_close_timeout);
  assert.equal(state.admission.recorded, true);
  assert.equal(state.first_refusal.status, 409);
  const first = state.first_attempt, second = state.second_attempt;
  for (const [index, task] of [first, second].entries()) {
    assert.equal(task.workflow_id, workflowId);
    assert.equal(task.run_id, observation.run_id);
    assert.equal(task.activity_execution_id, first.activity_execution_id);
    assert.equal(task.attempt_number, index + 1);
    assert.deepEqual(task.retry_policy, policy);
  }
  assert.notEqual(second.task_id, first.task_id);
  assert.notEqual(second.activity_attempt_id, first.activity_attempt_id);
  assert.equal(state.activity_completion.recorded, true);
  assert.equal(state.workflow_completion.recorded, true);
  const activityReceipt = receipt => {
    assert([200, 409].includes(receipt.status), 'Retained receipt or permitted closed-attempt response');
    assert.equal(receipt.response.recorded, false);
    assert.equal(receipt.response.outcome, 'completed');
    if (receipt.status === 409) assert.equal(receipt.response.reason, 'stale_attempt');
  };
  activityReceipt(state.duplicate);
  assert.equal(state.conflict.status, 409);
  assert([200, 409].includes(state.workflow_duplicate.status));
  if (state.workflow_duplicate.status === 409) assert.equal(state.workflow_duplicate.response.reason, 'run_closed');
  else assert.equal(state.workflow_duplicate.response.recorded, false);
  assert.equal(state.parent_delivery.workflow_id, workflowId);
  assert.equal(state.parent_delivery.run_id, observation.run_id);
  assert.notEqual(state.parent_delivery.task_id, state.workflow_task.task_id);
  assert.equal(state.parent_delivery.workflow_task_attempt, 1);
  const events = observation.events;
  assert.deepEqual(events.map(e => e.event_type), fixture.expected_events);
  assert.deepEqual(events.map(e => e.sequence), events.map((_, i) => i + 1));
  for (const event of events) assert.match(event.timestamp, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/);
  for (const snapshot of [state.before_expiry, state.after_retry, state.committed]) {
    assert.equal(snapshot.run_id, observation.run_id);
    assert.equal(snapshot.workflow_id, workflowId);
    assert.deepEqual(events.slice(0, snapshot.events.length), snapshot.events);
    for (const name of ['execution_deadline_at', 'run_deadline_at']) {
      assert.equal(snapshot.execution[name], observation.execution[name]);
    }
  }
  assert.equal(state.before_expiry.events.length, 4);
  assert.equal(state.after_retry.events.length, 6);
  if (spec.active_receipt_control) {
    activityReceipt(state.active_before_expiry);
    assert.equal(state.committed.status, 'waiting');
    assert.equal(state.active_after_expiry.status, 'waiting');
    assert.equal(state.committed.events.length, 7);
    assert.deepEqual(state.committed.events, state.active_after_expiry.events,
      'Before/after expiry duplicates leave the active parent and entire committed prefix unchanged');
    assert.deepEqual(events.slice(0, 7), state.committed.events);
  } else assert.deepEqual(state.committed.events, events, 'Elapsed duplicates/conflict leave every original event unchanged');
  const scheduled = events[2], started = events[3], retry = events[4], restarted = events[5], completed = events[6];
  for (const event of [scheduled, started, retry, restarted, completed]) {
    assert.equal(event.payload.activity_execution_id, first.activity_execution_id);
    assert.deepEqual(event.payload.activity.retry_policy, policy);
  }
  assert.equal(started.payload.activity_attempt_id, first.activity_attempt_id);
  assert.equal(restarted.payload.activity_attempt_id, second.activity_attempt_id);
  assert.equal(completed.payload.activity_attempt_id, second.activity_attempt_id);
  assert.equal(retry.payload.timeout_kind, 'start_to_close');
  assert.equal(retry.payload.retry_after_attempt, 1);
  assert.equal(retry.payload.retry_after_attempt_id, first.activity_attempt_id);
  assert.equal(retry.payload.retry_task_id, second.task_id);
  assert.equal(retry.payload.retry_of_task_id, first.task_id);
  assert.equal(retry.payload.retry_backoff_seconds, spec.backoff_seconds[0]);
  assert(instant(retry.timestamp) >= instant(started.timestamp) + BigInt(spec.start_to_close_timeout) * 1_000_000_000n);
  assert(instant(restarted.timestamp) >= instant(retry.payload.retry_available_at));
  assert(instant(completed.timestamp) < instant(restarted.timestamp) + BigInt(spec.start_to_close_timeout) * 1_000_000_000n);
  assert.deepEqual(completed.typed_decoded.result, fixture.typed_value);
  assert.deepEqual(events[7].typed_decoded.output, fixture.typed_value);
  const wire = state.requests.map(row => {
    assert.equal(row.transport_error, null);
    assert.equal(row.client_cancelled, false);
    const decode = {identity: x => x, gzip: gunzipSync, deflate: inflateSync, br: brotliDecompressSync}[row.response_encoding];
    assert(decode);
    assert.equal(decode(Buffer.from(row.response_bytes_base64, 'base64')).toString('utf8'), row.response_body);
    return {...row, request: row.request_body ? JSON.parse(row.request_body) : null,
      response: row.response_body ? JSON.parse(row.response_body) : null};
  });
  const activity = wire.filter(row => row.method === 'POST' && /\/activity-tasks\/[^/]+\/complete$/.test(row.path));
  assert.equal(activity.length, spec.active_receipt_control ? 5 : 4);
  const duplicateIndex = spec.active_receipt_control ? 3 : 2;
  assert.equal(activity[0].status, 409);
  assert.equal(activity[1].status, 200);
  assert.equal(activity.at(-1).status, 409);
  assert.equal(activity[0].request.activity_attempt_id, first.activity_attempt_id);
  assert.equal(activity[1].request.activity_attempt_id, second.activity_attempt_id);
  for (const receipt of activity.slice(2, -1)) assert.equal(activity[1].request_body, receipt.request_body,
    'Submitted duplicate bytes match the original; terminal metadata alone does not prove content verification');
  assert.notEqual(activity[1].request_body, activity.at(-1).request_body);
  assert.deepEqual(activity[duplicateIndex].response, state.duplicate.response);
  assert.deepEqual(activity.at(-1).response, state.conflict.response);
  const workflow = wire.filter(row => row.method === 'POST' && /\/workflow-tasks\/[^/]+\/complete$/.test(row.path));
  assert.equal(workflow.length, 3);
  assert.deepEqual(workflow.slice(0, 2).map(row => row.status), [200, 200]);
  assert.equal(workflow[2].status, state.workflow_duplicate.status);
  assert.equal(workflow[1].request_body, workflow[2].request_body);
  assert.deepEqual(workflow[2].response, state.workflow_duplicate.response);
  return {fixture_id: fixture.id, workflow_id: workflowId, status: 'completed',
    attempts: 2, retry_events: 1, activity_completions: 1, workflow_completions: 1,
    elapsed_identical_receipts: 'one_original_commit_without_mutation',
    content_equality_from_terminal_metadata: 'not_inferred', conflicting_outcome: 'refused', policy};
}
