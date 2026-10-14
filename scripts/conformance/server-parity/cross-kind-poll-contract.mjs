import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';

export function checkCrossKindPollWake(record, profile) {
  assert.equal(record.outcome, 'observed', 'the original workflow completes');
  assert.equal(record.worker_exit, 0, 'managed worker deregisters');
  assert(['activity', 'query'].includes(record.kind));
  assert.equal(record.original_budget_seconds, 35);
  assert.equal(record.cross_kind_capability, true, 'actual worker opts in');
  assert.equal(record.namespace, 'default', 'this recipe uses the explicit default namespace');
  assert.equal(record.sdk_version, profile.artifacts.sdk_php_version);
  assert.equal(record.sdk_source, profile.artifacts.sdk_php_source);
  assert.equal(record.adapter_sha256, profile.workload.adapter_sha256);
  for (const key of ['worker_id', 'task_queue', 'workflow_id', 'run_id']) {
    assert.equal(typeof record[key], 'string', key);
    assert(record[key].length > 0, key);
  }
  for (const key of ['case_started_at', 'observed_at', 'held_observed_at', 'start_request_at', 'start_accepted_at']) {
    assert(Number.isFinite(record[key]), key);
  }
  assert(record.observed_at <= record.case_started_at + 35, 'original budget is not rebased');
  assert(record.observed_at >= record.start_accepted_at);
  assert(record.held_observed_at >= record.case_started_at);
  assert(record.start_accepted_at >= record.start_request_at);
  assert(record.start_request_at >= record.held_observed_at);
  const heldIndex = record.polls.findIndex(poll => poll.request_id === record.held_request_id);
  assert(heldIndex >= 0, 'the actual held request is retained');
  const held = record.polls[heldIndex];
  assert.equal(record.polls.filter(poll => poll.request_id === record.held_request_id).length, 1);
  assert.equal(held.path, `/api/worker/${record.kind}-tasks/poll`);
  assert.equal(held.requested_seconds, 5);
  assert.equal(held.http, 200);
  assert.equal(held.poll_status, 'task_queue_changed', 'the server emits its explicit wake hint');
  assert.equal(held.task_is_null, true, 'task is explicitly null');
  assert.equal(held.task_id, null, 'the hint grants no lease');
  assert(held.started_at <= record.held_observed_at);
  assert(held.responded_at >= record.start_request_at, 'admission occurs while the request is held');
  const following = record.polls.slice(heldIndex + 1);
  const sweepIndex = following.findIndex(poll => poll.path === '/api/worker/workflow-tasks/poll');
  assert(sweepIndex >= 0, 'the same worker sweeps workflow work');
  for (const poll of following.slice(0, sweepIndex + 1)) {
    assert.equal(poll.requested_seconds, 0, 'no intervening held empty wait');
    assert(poll.started_at >= held.responded_at);
  }
  const sweep = following[sweepIndex];
  assert.equal(sweep.http, 200);
  assert(['task', 'leased'].includes(sweep.poll_status));
  assert.equal(sweep.task_is_null, false);
  assert.equal(typeof sweep.task_id, 'string');
  assert(sweep.task_id.length > 0, 'the ordinary poll separately grants a task');
  for (const poll of [held, sweep]) {
    assert(Number.isFinite(poll.started_at) && Number.isFinite(poll.responded_at));
    assert(poll.responded_at >= poll.started_at);
    assert.equal(poll.worker_id, record.worker_id);
    assert.equal(poll.task_queue, record.task_queue);
    assert.equal(poll.namespace_header, record.namespace);
  }
  assert.equal(sweep.namespace_header, held.namespace_header, 'the sweep keeps its namespace');
  assert.deepEqual(record.history_events, ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled',
    'ActivityStarted', 'ActivityCompleted', 'WorkflowCompleted']);
  assert.equal(record.output_bytes, 1024);
  assert.equal(record.output_sha256, createHash('sha256').update('s'.repeat(1024)).digest('hex'));
  return {kind: record.kind, wake: true};
}
