import assert from 'node:assert/strict';

// Reviewed immediate-cancel semantics. Cooperative cleanup and propagation to
// children/updates remain separate gates; raw representations are retained.
export function checkImmediateCancellation(fixture, observation) {
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const nonempty = (value, label) => assert.ok(typeof value === 'string' && value.length > 0, label);
  const time = (value, label) => { const parsed = Date.parse(value); assert.ok(Number.isFinite(parsed), label); return parsed; };
  const {phase, reason, delay_seconds: delay} = fixture.immediate_cancellation;
  const events = observation.events;
  const rawEvents = events.map(({decoded, typed_decoded, ...event}) => event);
  const cancellation = observation.cancellation;
  const requested = events.find(event => event.event_type === 'CancelRequested');
  const terminal = events.at(-1);
  const commandId = requested.payload.workflow_command_id;
  const failureId = terminal.payload.failure_id;
  const identities = [observation.workflow_id, observation.run_id, events[0].payload.workflow_command_id, commandId, failureId];
  for (const identity of identities) nonempty(identity, 'original cancellation identity');
  equal(new Set(identities).size, identities.length, 'distinct cancellation/run/failure identities');
  equal(cancellation.before, rawEvents.slice(0, requested.sequence - 1), 'cancel follows original durable history prefix');
  equal(cancellation.history_before_duplicate, rawEvents, 'duplicate starts from the full cancelled history');
  equal(cancellation.history_after_duplicate, rawEvents, 'repeat cancellation has no history effect');
  equal(cancellation.fresh_history, rawEvents, 'fresh client and stale outcomes preserve original history');
  for (const event of [requested, terminal]) {
    equal(event.payload.workflow_instance_id, observation.workflow_id, 'cancel original workflow');
    equal(event.payload.workflow_run_id, observation.run_id, 'cancel original run');
    equal(event.payload.workflow_command_id, commandId, 'original cancel command across lifecycle');
    equal(event.payload.reason, reason, 'immutable original cancellation reason');
  }
  equal(requested.payload.command_type, 'cancel', 'immediate cancel command');
  equal(terminal.payload.failure_category, 'cancelled', 'typed cancellation failure category');
  equal(terminal.payload.closed_reason, 'cancelled', 'terminal cancellation reason');
  equal(terminal.payload.exception_class, 'Workflow\\V2\\Exceptions\\WorkflowCancelledException', 'published cancellation exception identity');
  equal(terminal.payload.message, `Workflow cancelled: ${reason}`, 'original cancellation failure message');
  equal(Object.keys(terminal.decoded), [], 'cancellation commits no workflow result');
  equal(Object.keys(terminal.typed_decoded), [], 'cancellation has no typed success result');
  equal(observation.execution.closed_reason, 'cancelled', 'durable run closure');
  assert.ok(time(observation.execution.closed_at, 'durable cancellation close timestamp') >= time(observation.execution.started_at, 'original start time'), 'cancellation closes the original run');
  const fresh = cancellation.fresh_execution;
  equal(fresh.run_id ?? fresh.id, observation.run_id, 'fresh original run identity');
  equal(fresh.status, 'cancelled', 'fresh durable cancellation');
  equal(fresh.output, null, 'no fresh success output');
  const accepted = cancellation.accepted;
  equal(accepted.status, 200, 'accepted cancellation transport');
  equal(accepted.response.command_id, commandId, 'acknowledged original cancellation command');
  equal(accepted.response.command_status, 'accepted', 'accepted cancellation command');
  equal(accepted.response.outcome, 'cancelled', 'terminal cancellation acknowledgment');
  equal(accepted.response.command_sequence, 2, 'cancel follows original start command');
  const duplicate = cancellation.duplicate;
  equal(duplicate.status, 409, 'repeat cancellation refusal');
  equal(duplicate.response.command_status, 'rejected', 'repeat cancellation command is rejected');
  equal(duplicate.response.rejection_reason ?? duplicate.response.reason, 'run_not_active', 'closed run cancellation refusal');
  equal(duplicate.response.command_sequence, 3, 'refused command remains inspectable');
  nonempty(duplicate.response.command_id, 'rejected command identity');
  identities.push(duplicate.response.command_id);
  equal(new Set(identities).size, identities.length, 'rejected command cannot replace original cancellation');
  for (const receipt of [accepted, duplicate]) {
    equal(receipt.response.workflow_id, observation.workflow_id, 'cancellation receipt workflow');
    equal(receipt.response.run_id, observation.run_id, 'cancellation receipt run');
    equal(receipt.response.target_scope, fixture.immediate_cancellation.receipt_target_scope[observation.mode], 'declared cancellation receipt target scope');
  }
  if (phase === 'pending_timer') {
    const scheduled = events[2].payload;
    const cancelled = events[4].payload;
    nonempty(scheduled.timer_id, 'original pending timer identity');
    identities.push(scheduled.timer_id);
    for (const field of ['timer_id', 'sequence', 'delay_seconds', 'fire_at']) {
      equal(cancelled[field], scheduled[field], `cancelled timer retains original ${field}`);
    }
    equal(scheduled.sequence, 1, 'original timer command sequence');
    equal(scheduled.delay_seconds, delay, 'original timer delay');
    assert.ok(time(cancelled.cancelled_at, 'timer cancellation time') < time(scheduled.fire_at, 'original timer deadline'), 'pending timer cancelled before its original deadline');
  }
  if (phase === 'leased_activity') {
    const scheduled = events[2].payload;
    const started = events[3].payload;
    const cancelled = events[5].payload;
    const arguments_ = {type: 'list', value: [fixture.typed_value]};
    for (const event of [events[2], events[3], events[5]]) {
      equal(event.payload.activity_execution_id, scheduled.activity_execution_id, 'original cancelled activity');
      equal(event.payload.activity_type, 'parity.v1.cancel_activity', 'registered cancelled activity type');
      equal(event.payload.sequence, 1, 'original activity command position');
      equal(event.payload.activity.arguments, scheduled.activity.arguments, 'immutable cancelled Avro arguments');
      equal(event.decoded.activity_arguments, [fixture.input], 'original cancelled activity arguments');
      equal(event.typed_decoded.activity_arguments, arguments_, 'exact cancelled activity argument types');
    }
    equal(cancelled.workflow_command_id, commandId, 'activity cancellation original control command');
    equal(cancelled.activity_attempt_id, started.activity_attempt_id, 'original leased attempt cancelled');
    equal(cancelled.attempt_number, 1, 'cancelled first attempt');
    equal(cancelled.activity.status, 'cancelled', 'durable activity closure');
    equal(cancelled.activity.attempt_count, 1, 'no cancellation retry');
    equal(cancelled.activity_attempt.status, 'cancelled', 'durable attempt closure');
    equal(cancelled.activity_attempt.id, started.activity_attempt_id, 'cancelled attempt snapshot identity');
    equal(cancelled.activity_attempt.activity_execution_id, scheduled.activity_execution_id, 'cancelled attempt execution');
    equal(cancelled.activity_attempt.task_id, started.task.id, 'cancelled original leased task');
    equal(cancelled.activity_attempt.lease_owner, started.task.lease_owner, 'original attempt owner');
    // Frozen PHP omits expiry from this history snapshot. Physical embedded
    // rows and actual HTTP stale-result refusal separately prove lease closure.
    equal(Object.hasOwn(cancelled.activity_attempt, 'lease_expires_at'), false, 'published cancelled attempt snapshot omits expiry');
    time(cancelled.activity.closed_at, 'closed activity timestamp');
    time(cancelled.activity_attempt.closed_at, 'closed attempt timestamp');
    identities.push(scheduled.activity_execution_id, started.activity_attempt_id, started.task.id);
    if (observation.mode === 'http') {
      equal(observation.activity_polls.length, 1, 'one actual cancelled activity claim');
      const claim = observation.activity_polls[0];
      equal(claim.task_id, started.task.id, 'actual cancelled task claim');
      equal(claim.activity_attempt_id, started.activity_attempt_id, 'actual cancelled attempt claim');
      equal(claim.activity_execution_id, scheduled.activity_execution_id, 'actual cancelled execution claim');
      equal(claim.lease_owner, started.task.lease_owner, 'actual cancelled claim owner');
      equal(claim.arguments, scheduled.activity.arguments, 'actual cancelled argument frame');
      equal(observation.activity_outcomes.length, 1, 'normal worker submits its stale result once');
      const report = observation.activity_outcomes[0];
      equal(report.status, 409, 'normal worker receives stale result refusal');
      equal(report.path, `/api/worker/activity-tasks/${claim.task_id}/complete`, 'original stale completion endpoint');
      equal(report.request.activity_attempt_id, claim.activity_attempt_id, 'normal worker submits original attempt');
      equal(report.request.lease_owner, claim.lease_owner, 'normal worker submits original owner');
      for (const receipt of [report, cancellation.late_completion]) {
        equal(receipt.status, 409, 'late activity completion refused');
        equal(receipt.response.recorded, false, 'late activity result is not durable');
        equal(receipt.response.reason, 'stale_attempt', 'late cancelled attempt fencing');
        equal(receipt.response.task_id, claim.task_id, 'late refusal original task');
        equal(receipt.response.activity_attempt_id, claim.activity_attempt_id, 'late refusal original attempt');
        for (const field of ['activity_status', 'attempt_status', 'task_status']) equal(receipt.response[field], 'cancelled', `late refusal ${field}`);
      }
    } else {
      equal(cancellation.redelivered_task_ids, [started.task.id], 'original cancelled task redelivered through real queue');
      equal(cancellation.attempts.length, 1, 'one original physical embedded attempt');
      const attempt = cancellation.attempts[0];
      equal(attempt.id, started.activity_attempt_id, 'physical cancelled attempt identity');
      equal(attempt.workflow_task_id, started.task.id, 'physical cancelled task identity');
      equal(attempt.status, 'cancelled', 'physical attempt cancelled');
      equal(attempt.lease_expires_at, null, 'physical cancelled attempt releases lease');
    }
  }
  equal(new Set(identities).size, identities.length, 'all cancellation work identities remain distinct');
  if (observation.mode === 'http') {
    equal(cancellation.outcome, {type: 'DurableWorkflow\\Exception\\WorkflowCancelled', message: reason}, 'actual SDK typed cancelled result');
    equal(cancellation.remaining_tasks, {workflow: null, activity: null}, 'cancelled run yields no remaining work');
    equal(observation.workflow_polls.length, phase === 'before_claim' ? 0 : 1, 'actual workflow claim count');
    equal(observation.workflow_completions.length, phase === 'before_claim' ? 0 : 1, 'one actual scheduling completion');
    if (phase !== 'leased_activity') equal(observation.activity_polls, [], 'no unexpected activity claim');
  } else {
    equal(fresh.cancelled, true, 'embedded public cancellation outcome');
    equal(cancellation.failures.length, 1, 'one original embedded cancellation failure');
    const failure = cancellation.failures[0];
    equal(failure.id, failureId, 'original durable cancellation failure');
    equal(failure.workflow_run_id, observation.run_id, 'failure original run');
    equal(failure.source_kind, 'workflow_run', 'failure source kind');
    equal(failure.source_id, observation.run_id, 'failure original source');
    equal(failure.propagation_kind, 'cancelled', 'failure cancellation propagation');
    equal(failure.failure_category, 'cancelled', 'failure typed category');
    equal(failure.exception_class, terminal.payload.exception_class, 'failure original exception class');
    equal(failure.message, terminal.payload.message, 'failure original message');
    equal(failure.handled, false, 'cancellation failure not swallowed');
    for (const task of cancellation.tasks) {
      assert.ok(['completed', 'cancelled'].includes(task.status), 'no remaining embedded ready/leased task');
      if (task.status === 'cancelled') equal(task.lease_expires_at, null, 'cancelled embedded task releases lease');
    }
  }
}
