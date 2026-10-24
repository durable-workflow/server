import assert from 'node:assert/strict';

const MICROS = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/;

// Milliseconds since the epoch of a UTC timestamp with any fraction length.
function at(value, label) {
  assert.ok(typeof value === 'string', `${label}: a timestamp, got ${JSON.stringify(value)}`);
  const millis = Date.parse(value.replace(/\.(\d{3})\d*Z$/, '.$1Z'));
  assert.ok(!Number.isNaN(millis), `${label}: ${value}`);
  return millis;
}

// Reviewed workflow-task poll request idempotency (WorkflowTaskPoller with
// WorkflowTaskPollRequestStore and PollRequestLeaseBinding), recorded against
// published Server 2.11.1. A request id replays its last answer: an empty
// poll stays empty while its result is cached, even after work arrives; a
// lease replays with its current lease, renewed by a heartbeat; a padded id is
// the same id. Once its task is no longer deliverable the id polls afresh: it
// leases the next ready task, or answers empty. Identities and times come from
// the observation; their relations and every other field are exact.
export function checkWorkflowPollRequest(fixture, observation, workflowId) {
  const state = observation.workflow_poll_request;
  const projection = {scope: 'workflow_poll_request'};
  assert.ok(state && typeof state === 'object', 'actual workflow poll request observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded poll requests explicitly inapplicable');
    return projection;
  }
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const prefix = `${workflowId}-wpr`;
  equal([state.applicable, state.namespace, state.queue, state.worker, state.first_workflow_id],
    [true, prefix.toLowerCase(), `${prefix}-queue`, `${prefix}-worker`, `${prefix}-first`], 'poll request namespace, queue and worker');
  const none = {status: 200, poll_status: 'empty', task_id: null, workflow_id: null, attempt: null, lease_owner: null,
    lease_expires_at: null, reason: null};
  equal(state.empty, none, 'an empty poll');
  equal(state.empty_replay, none, 'its id replays empty after work arrived');
  const lease = (key, workflow) => {
    const delivery = state[key];
    assert.ok(typeof delivery?.task_id === 'string' && delivery.task_id !== '', `${key}: a task`);
    assert.match(delivery.lease_expires_at ?? '', MICROS, `${key}: lease in UTC microseconds`);
    equal(delivery, {status: 200, poll_status: 'leased', task_id: delivery.task_id, workflow_id: workflow, attempt: 1,
      lease_owner: state.worker, lease_expires_at: delivery.lease_expires_at, reason: null}, `${key}: a lease`);
    return delivery;
  };
  const leased = lease('leased', `${prefix}-first`);
  equal(state.leased_replay, leased, 'a fresh id replays its lease');
  equal(state.heartbeat.status, 200, 'the workflow task heartbeat renews the lease');
  assert.match(state.heartbeat.lease_expires_at ?? '', MICROS, 'renewed lease in UTC microseconds');
  assert.ok(at(state.heartbeat.lease_expires_at, 'renewed') > at(leased.lease_expires_at, 'leased'), 'the heartbeat extends the lease');
  equal(state.heartbeat_replay, {...leased, lease_expires_at: state.heartbeat.lease_expires_at}, 'the replay shows the renewed lease');
  equal(state.padded_replay, state.heartbeat_replay, 'a padded id is the same id');
  equal(state.first_completed, 200, 'first task completed');
  const next = lease('after_completion', `${prefix}-second`);
  assert.notEqual(next.task_id, leased.task_id, 'a completed delivery polls afresh and leases the next task');
  equal(state.after_completion_replay, next, 'and replays it');
  equal(state.second_completed, 200, 'second task completed');
  equal(state.after_drain, none, 'with nothing ready the id answers empty');
  return projection;
}
