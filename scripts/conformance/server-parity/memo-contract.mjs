import assert from 'node:assert/strict';

const scalar = (type, value) => ({type, value});
const map = value => ({type: 'map', value});
const list = value => ({type: 'list', value});

// Assert the published merge/replay contract against actual observations. The
// producer's raw JSON remains untouched so its int64 literals are not rounded.
export function checkMemoRecord(record, fixture, artifacts, mode = 'http') {
  const spec = fixture.memo_replay;
  const initialOnly = spec.initial_only === true;
  const input = fixture.typed_value;
  const visibleInitial = map({delete: scalar('string', 'present'), empty: list([]), keep: input,
    replace: scalar('string', 'initial')});
  const initial = initialOnly ? map({...visibleInitial.value, deleted_at_start: scalar('null', null)}) : visibleInitial;
  const added = list([input.value.integer, input.value.optional, input.value.unicode]);
  const waiting = initialOnly ? visibleInitial : map({added, empty: list([]), keep: input, replace: scalar('boolean', false)});
  const completed = initialOnly ? visibleInitial : map({counter: scalar('int64', '7'), empty: list([]), keep: input,
    replace: scalar('string', 'final')});
  const entries = [map({added, delete: scalar('null', null), replace: scalar('boolean', false)}),
    map({added: scalar('null', null), counter: scalar('int64', '7'), replace: scalar('string', 'final')})];
  assert.equal(record.outcome, 'observed', 'all original phases finish');
  assert.equal(spec.phase_budget_seconds, 30);
  assert.equal(spec.overall_budget_seconds, 90);
  assert.equal(spec.updates.length, initialOnly ? 0 : 2);
  assert.deepEqual(Object.keys(record.phases), ['original', 'activity', 'replacement']);
  const phases = record.phases;
  const original = phases.original;
  const final = phases.replacement;
  assert.deepEqual(mode === 'http' ? record.initial.typed_memo : original.typed_initial_memo, visibleInitial,
    'original initial memo retains its typed values');
  assert.deepEqual(original.typed_memo, waiting, 'waiting memo merge');
  assert.deepEqual(phases.activity.typed_memo, waiting, 'activity leaves memo unchanged');
  assert.deepEqual(final.typed_memo, completed, 'completed memo merge');
  const pids = [];
  for (const [name, phase] of Object.entries(phases)) {
    const actor = mode === 'http' ? phase.actor : phase;
    assert(Number.isSafeInteger(actor.pid) && actor.pid > 0);
    pids.push(actor.pid);
    assert.equal(phase.run_id, original.run_id, 'same original run');
    assert.equal(phase.workflow_id, original.workflow_id, 'same original workflow');
    assert.equal(phase.workflow_type, fixture.workflow_type);
    assert.equal(phase.namespace, 'default');
    assert.equal(phase.task_queue, original.workflow_id + '-workflow');
    assert.equal(phase.payload_codec, 'avro');
    assert.equal(phase.status, name === 'replacement' ? 'completed' : 'waiting');
    assert.deepEqual(phase.typed_input, list([input]));
    assert.equal(actor.worker_finished, true);
    assert.equal(actor.activity_invocations, name === 'activity' ? 1 : 0);
    if (mode === 'http') {
      assert.equal(actor.sdk_version, artifacts.sdk_php);
      assert.equal(actor.sdk_source, artifacts.sdk_php_source_commit);
      assert.equal(actor.sdk_composer_lock_sha256, artifacts.sdk_composer_lock_sha256);
      assert.deepEqual(actor.sdk_loaded_sources, artifacts.published_sdk_artifacts.php.loaded_sources);
    } else {
      assert.equal(actor.workflow_package, artifacts.workflow);
      assert.equal(actor.workflow_source, artifacts.workflow_source_commit);
      assert.equal(actor.embedded_composer_lock_sha256, artifacts.embedded_composer_lock_sha256);
      assert.deepEqual(actor.workflow_loaded_sources, artifacts.workflow_loaded_sources);
    }
    for (const key of ['execution_deadline_at', 'run_deadline_at']) {
      assert.equal(phase.execution[key], original.execution[key], 'original immutable ' + key);
    }
  }
  assert.equal(new Set(pids).size, 3, 'three independent cold processes');
  assert.deepEqual(final.typed_output, input, 'one exact typed activity result');
  for (const event of original.events.slice(0, 2)) {
    assert.deepEqual(event.typed_decoded.initial_memo, initial, 'original authored memo in durable start history');
  }
  assert.deepEqual(phases.activity.events.slice(0, original.events.length), original.events,
    'original pending history prefix');
  assert.deepEqual(final.events.slice(0, phases.activity.events.length), phases.activity.events,
    'original completed activity prefix');
  const memoEvents = final.events.filter(event => event.event_type === 'MemoUpserted');
  const eventTypes = initialOnly
    ? ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled', 'ActivityStarted', 'ActivityCompleted', 'WorkflowCompleted']
    : ['StartAccepted', 'WorkflowStarted', 'MemoUpserted', 'ActivityScheduled', 'ActivityStarted', 'ActivityCompleted', 'MemoUpserted', 'WorkflowCompleted'];
  assert.deepEqual(final.events.map(event => event.event_type), eventTypes,
    'exact durable event ordering');
  assert.deepEqual(final.events.map(event => event.sequence), eventTypes.map((_, i) => i + 1), 'contiguous original history');
  assert.equal(memoEvents.length, initialOnly ? 0 : 2, 'only authored memo commands create memo events');
  for (const [index, event] of memoEvents.entries()) {
    assert.equal(event.payload.sequence, index === 0 ? 1 : 3, 'original command sequence');
    assert.deepEqual(event.typed_decoded.entries, entries[index], 'canonical typed entries');
    assert.deepEqual(event.typed_decoded.merged, index === 0 ? waiting : completed, 'canonical typed merged memo');
    assert.deepEqual(Object.keys(event.typed_decoded.entries.value), Object.keys(event.typed_decoded.entries.value).sort());
    assert.deepEqual(Object.keys(event.typed_decoded.merged.value), Object.keys(event.typed_decoded.merged.value).sort());
  }
  for (const type of ['ActivityScheduled', 'ActivityStarted', 'ActivityCompleted', 'WorkflowCompleted']) {
    assert.equal(final.events.filter(event => event.event_type === type).length, 1, 'one durable ' + type);
  }
  const [scheduled, started, finished] = ['ActivityScheduled', 'ActivityStarted', 'ActivityCompleted']
    .map(type => final.events.find(event => event.event_type === type));
  assert.equal(typeof scheduled.payload.activity_execution_id, 'string');
  assert(scheduled.payload.activity_execution_id.length > 0);
  for (const event of [scheduled, started, finished]) {
    assert.equal(event.payload.sequence, initialOnly ? 1 : 2, 'original activity command');
    assert.equal(event.payload.activity_execution_id, scheduled.payload.activity_execution_id, 'one original activity identity');
    assert.equal(event.payload.activity_type, 'parity.v1.memo_echo');
    assert.equal(event.payload.task.repair_count, 0, 'no manual activity repair');
    assert.deepEqual(event.payload.activity.retry_policy, {snapshot_version: 1, max_attempts: 1, backoff_seconds: [],
      start_to_close_timeout: 120, schedule_to_start_timeout: null, schedule_to_close_timeout: 600,
      heartbeat_timeout: null, non_retryable_error_types: []}, 'complete frozen retry snapshot');
  }
  assert.equal(started.payload.attempt_number, 1);
  assert.equal(finished.payload.attempt_number, 1);
  assert.equal(finished.payload.activity_attempt_id, started.payload.activity_attempt_id, 'same original activity attempt');
  assert.equal(finished.payload.task.id, started.payload.task.id, 'same original activity task');
  assert.deepEqual(finished.typed_decoded.result, input, 'canonical typed activity result');
  for (const event of memoEvents) {
    assert.equal(event.payload.task.repair_count, 0, 'no manual memo task repair');
    assert.equal(event.payload.task.attempt_count, 1, 'first memo task attempt');
  }
  if (mode === 'http') {
    if (!initialOnly) {
      assert.equal(record.discovery.workflow_memo_updates.supported, true, 'runtime explicitly discovers support');
      assert(record.discovery.supported_workflow_task_commands.includes('upsert_memo'));
    }
    const duplicate = record.duplicate.receipt;
    assert.deepEqual(record.duplicate.submitted_request,record.duplicate.request.request,
      'resubmit all captured SDK completion metadata');
    // Memo idempotency promises no second application. A completed-task fence
    // does not promise content-verified receipt reconciliation or HTTP 200.
    assert([200, 409].includes(duplicate.status), 'explicit committed duplicate response');
    if (duplicate.status === 409) {
      assert.equal(duplicate.response.reason, 'task_not_leased');
      assert.equal(duplicate.response.task_status, 'completed');
    } else assert.equal(duplicate.response.recorded,false);
    assert.deepEqual(record.duplicate.before.events, record.duplicate.after.events, 'duplicate changes no history');
    assert.deepEqual(record.duplicate.before.typed_memo, record.duplicate.after.typed_memo, 'duplicate changes no memo');
    const conflict=record.conflicting_completion;
    assert.notDeepEqual(conflict.submitted_request,record.duplicate.submitted_request);
    assert.equal(conflict.receipt.status,409,'conflicting completion refuses');
    assert(['task_not_leased','conflicting_completion'].includes(conflict.receipt.response.reason));
    assert.deepEqual(conflict.before.events,conflict.after.events,'conflict changes no history');
    assert.deepEqual(conflict.before.typed_memo,conflict.after.typed_memo,'conflict changes no memo');
    const registration = original.actor.receipts.find(receipt => receipt.path === '/api/worker/register');
    assert(registration?.request.capabilities.includes('memo_upserts'), 'actual author advertises memo capability');
  }
  return {workflow_id: original.workflow_id, namespace: 'default', workflow_type: fixture.workflow_type, typed_input: input, waiting_memo: waiting,
    completed_memo: completed, typed_output: final.typed_output,
    memo_sequences: memoEvents.map(event => event.payload.sequence),
    activity_count: 1, memo_event_count: initialOnly ? 0 : 2, cold_processes: 3};
}
