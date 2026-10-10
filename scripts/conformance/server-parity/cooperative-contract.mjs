import assert from 'node:assert/strict';

// Root cancellation and cleanup are checked against the published 1.20
// contract. Adapter-specific requester/source fields are declared explicitly;
// immutable context, deadlines, history and payload values are never normalized.
export function checkCooperativeCancellation(fixture, raw, identities, projection, instant) {
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const nonempty = (value, label) => assert.ok(typeof value === 'string' && value.length > 0, label);
  const {phase, reason, cleanup_timeout_seconds: budget, cleanup_outcome: outcome} = fixture.cooperative_cancellation;
  const state = raw.cooperative;
  const events = raw.events;
  const history = events.map(({decoded, typed_decoded, ...event}) => event);
  const requested = events.find(event => event.event_type === 'CooperativeCancellationRequested');
  const delivered = events.find(event => event.event_type === 'CooperativeCancellationDelivered');
  const terminal = events.at(-1);
  const context = requested.payload.cancellation;
  const command = requested.payload.command;
  const requestId = requested.payload.workflow_command_id;
  nonempty(requestId, 'original cooperative request identity');
  nonempty(terminal.payload.failure_id, 'original cooperative failure identity');
  identities.push(requestId, terminal.payload.failure_id);
  equal(context.schema, 'durable-workflow.cancellation-context/v1', 'published cancellation context schema');
  equal(context.request_id, requestId, 'context belongs to original request');
  equal(context.root_request_id, requestId, 'root request is immutable');
  equal(context.root_workflow_instance_id, raw.workflow_id, 'root workflow is immutable');
  equal(context.root_workflow_run_id, raw.run_id, 'root run is immutable');
  equal(context.parent_request_id, null, 'root has no parent cancellation');
  equal(context.reason, reason, 'original cooperative reason');
  equal(context.requester, fixture.requester[raw.mode], 'original declared adapter requester');
  equal(context.source, raw.mode === 'http' ? 'control_plane' : 'php', 'original declared adapter source');
  equal(context.lineage, [{request_id: requestId, workflow_instance_id: raw.workflow_id, workflow_run_id: raw.run_id}], 'original root lineage');
  const requestedAt = instant(context.requested_at);
  const deadline = instant(context.cleanup_deadline_at);
  equal(deadline - requestedAt, BigInt(budget) * 1_000_000_000n, 'exact original cleanup budget');
  equal(requested.payload.command_type, 'request_cancellation', 'cooperative control command');
  equal(requested.payload.reason, reason, 'durable original cooperative reason');
  equal(requested.payload.cleanup_deadline_at, context.cleanup_deadline_at, 'durable original deadline');
  equal(requested.payload.workflow_instance_id, raw.workflow_id, 'request original workflow');
  equal(requested.payload.workflow_run_id, raw.run_id, 'request original run');
  equal(command.id, requestId, 'canonical cancellation command');
  equal(command.sequence, 2, 'cancellation follows original start command');
  equal(command.type, 'request_cancellation', 'stored cooperative command type');
  equal(command.target_scope, 'run', 'explicit selected run cancellation');
  equal(command.resolved_run_id, raw.run_id, 'command resolves original run');
  equal(command.status, 'accepted', 'stored cancellation accepted');
  equal(command.outcome, 'cancellation_requested', 'accepted request precedes cleanup');
  equal(command.accepted_at, context.requested_at, 'original accepted timestamp');
  equal(command.applied_at, context.requested_at, 'original applied timestamp');
  equal(requested.decoded.cancellation_command, {reason, cleanup_deadline_at: context.cleanup_deadline_at, cancellation: context}, 'official Avro decoder preserves complete command context');
  equal(state.before, history.slice(0, requested.sequence - 1), 'request follows actual original history');
  equal(state.history_before_pending_duplicate, history.slice(0, requested.sequence), 'accepted history precedes actual delivery');
  equal(state.history_after_pending_duplicate, state.history_before_pending_duplicate, 'pending duplicate adds no history');
  equal(state.history_before_terminal_duplicate, history, 'terminal duplicate starts from actual full history');
  equal(state.history_after_terminal_duplicate, history, 'terminal duplicate adds no history');
  equal(state.fresh_history, history, 'fresh reader preserves original history');
  equal(state.after_request.run_id ?? state.after_request.id, raw.run_id, 'accepted request original run');
  equal(state.after_request.status, phase === 'before_claim' ? 'pending' : 'waiting', 'acceptance leaves the original run open');
  equal(state.after_request.closed_at, null, 'acceptance does not close the run');
  equal(delivered.payload.workflow_command_id, requestId, 'delivery belongs to original request');
  equal(delivered.payload.workflow_run_id, raw.run_id, 'delivery original run');
  equal(delivered.payload.cancellation, context, 'delivery retains complete canonical context');
  equal(delivered.payload.sequence, 1, 'delivery original authored timer boundary');
  equal(delivered.payload.call_kind, 'timer', 'delivery original call kind');
  assert.ok(instant(delivered.timestamp) < deadline, 'canonical delivery precedes original cleanup deadline');
  nonempty(delivered.payload.task.id, 'actual delivery task identity');
  nonempty(delivered.payload.task.lease_owner, 'actual delivery owner');
  equal(delivered.payload.task.attempt_count, 1, 'original delivery task attempt');

  const generatedReason = outcome === 'completed' ? 'Cooperative cancellation completed.' : 'Cooperative cancellation cleanup deadline expired.';
  for (const field of ['workflow_instance_id', 'workflow_run_id', 'workflow_command_id']) {
    equal(terminal.payload[field], requested.payload[field], `terminal original ${field}`);
  }
  equal(terminal.payload.reason, generatedReason, 'published generated terminal reason');
  equal(terminal.payload.message, `Workflow cancelled: ${generatedReason}`, 'published generated terminal diagnostic');
  equal(terminal.payload.failure_category, 'cancelled', 'typed cooperative failure');
  equal(terminal.payload.exception_class, 'Workflow\\V2\\Exceptions\\WorkflowCancelledException', 'published cooperative cancellation exception');
  equal(terminal.payload.closed_reason, 'cancelled', 'cooperative terminal closure');
  equal(Object.keys(terminal.decoded), [], 'cleanup does not commit workflow success');
  equal(raw.execution.closed_reason, 'cancelled', 'persisted cooperative closure');
  equal(state.fresh_execution.run_id ?? state.fresh_execution.id, raw.run_id, 'fresh selected run');
  equal(state.fresh_execution.status, 'cancelled', 'fresh durable cooperative cancellation');
  equal(state.fresh_execution.output, null, 'fresh cancellation has no successful result');
  const cleanup = terminal.payload.cancellation_cleanup;
  equal(cleanup.request_id, requestId, 'cleanup original request');
  equal(cleanup.outcome, outcome, 'reviewed cleanup outcome');
  equal(cleanup.cleanup_deadline_at, context.cleanup_deadline_at, 'cleanup never extends original budget');
  equal(cleanup.delivery_sequence, 1, 'cleanup original delivery boundary');
  nonempty(cleanup.delivery_history_event_id, 'canonical delivery history identity');
  equal(cleanup.finished_at, raw.execution.closed_at, 'cleanup closes at persisted terminal time');
  assert.ok(outcome === 'completed' ? instant(cleanup.finished_at) < deadline : instant(cleanup.finished_at) >= deadline, 'cleanup completion obeys original deadline');

  const timers = events.filter(event => event.event_type === 'TimerScheduled');
  const cleanupTimer = timers.at(-1);
  equal(timers.length, phase === 'before_claim' ? 1 : 2, 'complete authored timer inventory');
  equal(cleanupTimer.payload.sequence, 2, 'shielded cleanup follows delivered author position');
  equal(cleanupTimer.payload.delay_seconds, fixture.input.cleanup_delay_seconds, 'actual shielded cleanup timer delay');
  assert.ok(cleanupTimer.sequence > delivered.sequence, 'cleanup timer is authored after delivery');
  assert.ok(instant(cleanupTimer.timestamp) < deadline, 'cleanup started before original deadline');
  for (const [index, scheduled] of timers.entries()) {
    const id = scheduled.payload.timer_id;
    nonempty(id, 'original cooperative timer identity');
    identities.push(id);
    const closed = events.find(event => ['TimerCancelled', 'TimerFired'].includes(event.event_type) && event.payload.timer_id === id);
    assert.ok(closed, 'every original cooperative timer closes');
    for (const field of ['timer_id', 'sequence', 'delay_seconds', 'fire_at']) equal(closed.payload[field], scheduled.payload[field], `timer preserves original ${field}`);
    const original = phase === 'pending_timer' && index === 0;
    equal(closed.event_type, original || outcome === 'deadline_expired' ? 'TimerCancelled' : 'TimerFired', 'reviewed original timer disposition');
    if (original) {
      equal(scheduled.payload.sequence, 1, 'original wait author position');
      equal(scheduled.payload.delay_seconds, fixture.cooperative_cancellation.original_delay_seconds, 'original wait budget');
      assert.ok(closed.sequence > requested.sequence && closed.sequence < delivered.sequence, 'delivery transaction cancels original wait before recording delivery');
      assert.ok(instant(closed.payload.cancelled_at) < instant(scheduled.payload.fire_at), 'original timer cancelled before firing');
    } else if (outcome === 'deadline_expired') {
      assert.ok(instant(closed.payload.cancelled_at) >= deadline, 'cleanup timer cancelled only at original deadline');
    } else {
      assert.ok(instant(closed.payload.fired_at) >= instant(scheduled.payload.fire_at), 'shielded cleanup timer never fires early');
    }
    for (const event of [scheduled, closed]) Object.assign(projection[event.sequence - 1], {
      timer_id: `@timer:${index + 1}`, command_sequence: scheduled.payload.sequence,
      delay_seconds: scheduled.payload.delay_seconds,
    });
  }

  const activityEvents = events.filter(event => event.event_type.startsWith('Activity'));
  if (outcome === 'completed') {
    equal(activityEvents.map(event => event.event_type), ['ActivityScheduled', 'ActivityStarted', 'ActivityHeartbeatRecorded', 'ActivityCompleted'], 'actual complete cleanup activity lifecycle');
    const [scheduled, started, heartbeat, completed] = activityEvents;
    const activityId = scheduled.payload.activity_execution_id;
    const attemptId = started.payload.activity_attempt_id;
    nonempty(activityId, 'original cleanup activity');
    nonempty(attemptId, 'original cleanup attempt');
    identities.push(activityId, attemptId, started.payload.task.id);
    for (const event of activityEvents) {
      equal(event.payload.activity_execution_id, activityId, 'cleanup activity identity across lifecycle');
      equal(event.payload.activity_type, 'parity.v1.cooperative_cleanup_activity', 'registered cleanup activity type');
      equal(event.payload.sequence, 3, 'cleanup activity original author sequence');
      equal(event.decoded.activity_arguments, [fixture.input, context], 'cleanup receives original value and canonical context');
      equal(event.typed_decoded.activity_arguments.value[0], fixture.typed_value, 'cleanup exact int64 argument');
      if (event !== scheduled) {
        equal(event.payload.activity_attempt_id, attemptId, 'cleanup original attempt across lifecycle');
        equal(event.payload.attempt_number, 1, 'cleanup has one attempt');
      }
      Object.assign(projection[event.sequence - 1], {activity_execution_id: '@activity:1', command_sequence: 3,
        activity_type: 'parity.v1.cooperative_cleanup_activity', ...(event === scheduled ? {} : {activity_attempt_id: '@attempt:1', attempt_number: 1})});
    }
    equal(heartbeat.payload.progress, {details: {request_id: requestId}}, 'actual cleanup heartbeat retains original request');
    equal(completed.decoded.result, {value: fixture.input, cancellation: context}, 'cleanup activity returns original context');
    equal(completed.typed_decoded.result.value.value, fixture.typed_value, 'cleanup exact int64 result');
  } else {
    equal(activityEvents, [], 'expired cleanup never executes subsequent activity');
  }
  equal(new Set(identities).size, identities.length, 'distinct original cooperative identities');

  if (raw.mode === 'http') {
    for (const [index, receipt] of [state.accepted, state.pending_duplicate, state.post_terminal_duplicate].entries()) {
      equal(receipt.accepted, true, 'original request remains accepted');
      equal(receipt.duplicate, index > 0, 'repeat retains original request');
      equal(receipt.workflow_id, raw.workflow_id, 'receipt original workflow');
      equal(receipt.run_id, raw.run_id, 'receipt original run');
      const pending = receipt.cancellation_request;
      equal(pending.request_id, requestId, 'receipt original request');
      equal(pending.requested_at, context.requested_at, 'receipt original request time');
      equal(pending.cleanup_deadline_at, context.cleanup_deadline_at, 'repeat cannot renew original budget');
      nonempty(pending.history_refresh_page_token, 'opaque refresh token retained');
      equal(pending.history_refresh_page_token, state.accepted.cancellation_request.history_refresh_page_token, 'original refresh cursor');
      equal(pending.delivery_sequence, index === 2 ? 1 : null, 'delivery metadata progresses once');
      if (index < 2) equal(pending.delivered_at, null, 'acceptance does not claim delivery');
      else assert.ok(instant(pending.delivered_at) < deadline, 'final pending receipt retains original delivery time');
    }
    equal(state.outcome, {type: 'DurableWorkflow\\Exception\\WorkflowCancelled', message: generatedReason}, 'published SDK reports actual typed cancellation');
    const deliveryReports = state.traffic.filter(item => item.path.endsWith('/deliver-cancellation'));
    equal(deliveryReports.length, 1, 'normal worker makes one canonical delivery');
    const report = deliveryReports[0];
    equal(report.status, 200, 'actual canonical delivery accepted');
    equal(report.path, `/api/worker/workflow-tasks/${delivered.payload.task.id}/deliver-cancellation`, 'original delivery endpoint');
    equal(report.request.lease_owner, delivered.payload.task.lease_owner, 'actual original delivery owner');
    equal(report.request.workflow_task_attempt, delivered.payload.task.attempt_count, 'actual original delivery attempt');
    for (const packet of [report.request, report.response]) {
      equal(packet.request_id, requestId, 'actual original delivery request');
      equal(packet.sequence, 1, 'actual delivery author position');
      equal(packet.call_kind, 'timer', 'actual delivery call kind');
      equal(packet.sequence_span ?? 1, 1, 'actual original delivery span');
    }
    equal(report.response.delivered, true, 'worker observes committed delivery');
    // Remote cleanup leases are short renewable intervals within the root
    // deadline. Embedded queue leases use the host application's queue policy.
    const expires = instant(delivered.payload.task.lease_expires_at);
    assert.ok(expires <= deadline && expires <= instant(delivered.timestamp) + 10_000_000_000n, 'remote delivery lease cannot extend root budget or renewal interval');
    let rootRenewals = 0;
    for (const renewal of state.traffic.filter(item => /workflow-tasks\/[^/]+\/heartbeat$/.test(item.path) && item.status === 200)) {
      if (!renewal.response.cancellation_request) {
        equal(phase, 'pending_timer', 'only an original pre-request claim has no cancellation observation');
        equal(renewal.path, `/api/worker/workflow-tasks/${timers[0].payload.task.id}/heartbeat`, 'pre-request renewal belongs to original wait claim');
        continue;
      }
      rootRenewals++;
      equal(renewal.response.cancellation_request.request_id, requestId, 'renewal retains original root');
      equal(renewal.response.cancellation_request.cleanup_deadline_at, context.cleanup_deadline_at, 'renewal cannot create a new cleanup budget');
      assert.ok(instant(renewal.response.lease_expires_at) <= deadline, 'renewed remote lease respects root deadline');
    }
    assert.ok(rootRenewals > 0, 'actual worker renews an observed root cancellation claim');
  } else {
    equal(state.pending_duplicate, state.accepted, 'embedded pending duplicate retains original command/context');
    equal(state.post_terminal_duplicate, state.accepted, 'embedded terminal duplicate retains original command/context');
    equal(state.accepted.context, context, 'embedded command original context');
    equal(state.accepted.command.command_id, requestId, 'embedded receipt original command');
    equal(cleanup.delivery_history_event_id, state.delivery_history_event_id, 'physical canonical delivery history identity');
    equal(state.failures.length, 1, 'one physical cooperative failure');
    const failure = state.failures[0];
    equal(failure.id, terminal.payload.failure_id, 'physical original cancellation failure');
    equal(failure.source_kind, 'workflow_run', 'physical cancellation failure source');
    equal(failure.source_id, raw.run_id, 'physical cancellation original run');
    equal(failure.failure_category, 'cancelled', 'physical typed cancellation category');
    equal(failure.exception_class, terminal.payload.exception_class, 'physical cancellation exception');
    equal(failure.message, terminal.payload.message, 'physical terminal diagnostic');
    for (const task of state.tasks) {
      assert.ok(['completed', 'cancelled'].includes(task.status), 'closed cleanup has no remaining runnable task');
      equal(task.lease_expires_at, null, 'closed cleanup releases all task leases');
    }
    equal(state.attempts.length, outcome === 'completed' ? 1 : 0, 'physical cleanup attempt inventory');
    if (outcome === 'completed') {
      equal(state.attempts[0].id, activityEvents[1].payload.activity_attempt_id, 'physical original cleanup attempt');
      equal(state.attempts[0].status, 'completed', 'physical cleanup activity actually completed');
      equal(state.attempts[0].lease_expires_at, null, 'physical cleanup attempt releases lease');
    } else {
      assert.ok(state.watchdog.some(pass => pass.cancellation_deadlines_enforced === 1), 'installed watchdog enforces actual original deadline');
    }
  }
  for (const event of [requested, delivered, terminal]) Object.assign(projection[event.sequence - 1], {request_id: '@command:2'});
  return {request_id: '@command:2', root_request_id: '@command:2', root_workflow_id: raw.workflow_id,
    root_run_id: '@run:1', parent_request_id: null, reason, cleanup_timeout_seconds: budget,
    delivery_sequence: 1, call_kind: 'timer', cleanup_outcome: outcome};
}
