import assert from 'node:assert/strict';

export function checkTerminalActivityFailure(fixture, raw, identities, projection, instant) {
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const nonempty = (value, label) => assert.ok(typeof value === 'string' && value.length > 0, label);
  const frame = value => typeof value === 'string' ? {codec: 'avro', blob: value} : value;
  const scheduled = raw.events[2];
  const started = raw.events.filter(event => event.event_type === 'ActivityStarted');
  const failed = raw.events.find(event => event.event_type === 'ActivityFailed');
  const activity = scheduled.payload.activity_execution_id;
  const policy = {snapshot_version: 1, ...fixture.retry_policy, start_to_close_timeout: null,
    schedule_to_start_timeout: null, schedule_to_close_timeout: null, heartbeat_timeout: null};
  const arguments_ = {type: 'list', value: [fixture.typed_value]};
  equal(started.length, fixture.expected_attempts, 'exact terminal attempt budget/filter');
  equal(failed.event_type, 'ActivityFailed', 'one terminal failure before catch completion');
  for (const id of [activity, failed.payload.failure_id, ...started.flatMap(event => [event.payload.activity_attempt_id, event.payload.task.id])]) {
    nonempty(id, 'original terminal activity/attempt/task/failure identity');
    identities.push(id);
  }
  equal(new Set(identities).size, identities.length, 'distinct original terminal identities');
  for (const event of raw.events.slice(2, failed.sequence)) {
    const payload = event.payload;
    equal(payload.activity_execution_id, activity, 'terminal original activity');
    equal(payload.activity.id, activity, 'terminal snapshot original execution');
    equal(payload.activity.idempotency_key, activity, 'terminal original side-effect identity');
    equal(payload.sequence, 1, 'one original author command');
    equal(payload.activity_type, fixture.failure_activity_type, 'terminal registered type');
    equal(payload.activity.queue, 'server-parity-v1', 'terminal original queue');
    equal(payload.activity.retry_policy, policy, 'terminal original retry policy');
    equal(frame(payload.activity.arguments), frame(scheduled.payload.activity.arguments), 'terminal original Avro argument bytes');
    equal(event.decoded.activity_arguments, [fixture.input], 'terminal decoded original arguments');
    equal(event.typed_decoded.activity_arguments, arguments_, 'terminal exact int64 input types');
    Object.assign(projection[event.sequence - 1], {activity_execution_id: '@activity:1', command_sequence: 1,
      activity_type: fixture.failure_activity_type, arguments: arguments_, retry_policy: policy});
  }
  for (const [index, event] of started.entries()) {
    equal(event.payload.attempt_number, index + 1, 'terminal durable attempt number');
    equal(event.payload.activity.attempt_count, index + 1, 'terminal execution attempt count');
    equal(event.payload.activity.status, 'running', 'terminal attempt was actually running');
    Object.assign(projection[event.sequence - 1], {activity_attempt_id: `@attempt:${index + 1}`, attempt_number: index + 1});
  }
  const terminal = failed.payload;
  equal(terminal.activity_attempt_id, started.at(-1).payload.activity_attempt_id, 'terminal closes original last attempt');
  equal(terminal.attempt_number, fixture.expected_attempts, 'terminal retained attempt number');
  equal(terminal.activity.attempt_count, fixture.expected_attempts, 'terminal retained attempt budget');
  equal(terminal.activity.status, 'failed', 'terminal original execution closed');
  assert.ok(instant(terminal.activity.closed_at) >= instant(started.at(-1).timestamp)
    && instant(terminal.activity.closed_at) <= instant(failed.timestamp), 'terminal execution closes during original failed turn');
  equal(terminal.non_retryable, fixture.non_retryable, 'budget exhaustion and non-retryable classification differ');
  equal(terminal.failure_category, 'activity', 'terminal failure category');
  equal(terminal.message, fixture.failure.message, 'terminal original failure message');
  equal(terminal.code, 0, 'terminal original failure code');
  equal(terminal.exception_type, raw.mode === 'embedded' ? null : fixture.failure.type, 'explicit embedded/external failure type');
  equal(terminal.exception_class, fixture.failure.type, 'terminal original exception class');
  equal(terminal.exception[raw.mode === 'embedded' ? 'class' : 'type'], fixture.failure.type, 'terminal retained failure payload type');
  equal(terminal.exception.message, fixture.failure.message, 'terminal retained failure payload message');
  const retries = raw.events.filter(event => event.event_type === 'ActivityRetryScheduled');
  equal(retries.length, fixture.expected_attempts - 1, 'only eligible retry is scheduled');
  if (retries.length === 1) {
    const retry = retries[0].payload;
    const first = started[0].payload;
    const second = started[1].payload;
    equal(retry.activity_attempt_id, first.activity_attempt_id, 'terminal retry original failed attempt');
    equal(retry.retry_after_attempt_id, first.activity_attempt_id, 'terminal retry original ancestor');
    equal(retry.retry_of_task_id, first.task.id, 'terminal retry original failed task');
    equal(retry.retry_task_id, second.task.id, 'terminal retry is the task actually executed');
    equal(retry.activity_attempt.id, first.activity_attempt_id, 'terminal first attempt is closed');
    equal(retry.activity_attempt.status, 'failed', 'terminal first attempt failed');
    equal(retry.activity_attempt.lease_owner, first.task.lease_owner, 'terminal first attempt original owner');
    equal(retry.message, fixture.failure.message, 'terminal first failure retained');
    const deadline = instant(retry.retry_available_at);
    equal(instant(second.task.available_at), deadline, 'terminal retry preserves exact original deadline');
    assert.ok(instant(second.task.leased_at) >= deadline && instant(started[1].timestamp) >= deadline, 'terminal retry cannot run early');
    assert.ok(deadline - 1_000_000_000n >= instant(started[0].timestamp)
      && deadline - 1_000_000_000n <= instant(retries[0].timestamp), 'terminal original one-second backoff');
    Object.assign(projection[retries[0].sequence - 1], {activity_attempt_id: '@attempt:1',
      retry_task_id: '@activity-task:2', retry_of_task_id: '@activity-task:1', failure: fixture.failure});
  }
  Object.assign(projection[failed.sequence - 1], {activity_attempt_id: `@attempt:${fixture.expected_attempts}`,
    attempt_number: fixture.expected_attempts, failure_id: '@failure:1', failure_category: 'activity',
    non_retryable: fixture.non_retryable, failure: fixture.failure});
  const duplicates = raw.activity_duplicate_receipts;
  equal(duplicates.history_before, duplicates.history_after, 'terminal duplicate failures/redelivery have no durable effect');
  if (raw.mode === 'embedded') {
    const handled = raw.events.at(-2);
    equal(handled.event_type, 'FailureHandled', 'embedded replay durably acknowledges the catch');
    for (const [key, value] of Object.entries({failure_id: terminal.failure_id, sequence: 1, failure_category: 'activity',
      source_kind: 'activity_execution', source_id: activity, propagation_kind: 'activity',
      exception_class: fixture.failure.type, message: fixture.failure.message, handled: true})) {
      equal(handled.payload[key], value, 'embedded handling preserves original failure relationship');
    }
    equal(duplicates.redelivered_task_ids, started.map(event => event.payload.task.id), 'actual closed activity queue redelivery');
    // The published HTTP protocol has no catch acknowledgement command and
    // omits this embedded bookkeeping event. Check its entire original
    // identity first; compare only the fixture's declared common catch behavior.
    projection.splice(handled.sequence - 1, 1);
    projection.forEach((event, index) => {event.sequence = index + 1;});
    return;
  }
  equal(raw.activity_polls.length, fixture.expected_attempts, 'actual SDK terminal activity claims');
  equal(raw.activity_outcomes.length, fixture.expected_attempts, 'actual SDK failure reports');
  equal(raw.workflow_polls.length, 2, 'one workflow resumption after terminal failure');
  equal(raw.workflow_completions.length, 2, 'one schedule and one caught-failure completion');
  equal(duplicates.failures.length, fixture.expected_attempts, 'repeat every original failure report');
  for (const [index, task] of raw.activity_polls.entries()) {
    const event = started[index].payload;
    equal(task.task_id, event.task.id, 'terminal SDK original task');
    equal(task.activity_attempt_id, event.activity_attempt_id, 'terminal SDK original attempt');
    equal(task.activity_execution_id, activity, 'terminal SDK original execution');
    equal(task.idempotency_key, activity, 'terminal SDK side-effect identity');
    equal(task.attempt_number, index + 1, 'terminal SDK attempt number');
    equal(task.run_id, raw.run_id, 'terminal SDK original run');
    equal(task.workflow_id, raw.workflow_id, 'terminal SDK original workflow');
    equal(task.lease_owner, event.task.lease_owner, 'terminal SDK original owner');
    equal(task.retry_policy, policy, 'terminal SDK original policy');
    equal(task.arguments, frame(scheduled.payload.activity.arguments), 'terminal SDK original argument frame');
    const outcome = raw.activity_outcomes[index];
    equal(outcome.path, `/api/worker/activity-tasks/${task.task_id}/fail`, 'terminal actual SDK failure endpoint');
    equal(outcome.request.activity_attempt_id, task.activity_attempt_id, 'terminal SDK report original attempt');
    equal(outcome.request.lease_owner, task.lease_owner, 'terminal SDK report original owner');
    equal(outcome.request.failure, {...fixture.failure, non_retryable: false}, 'terminal original SDK reported failure');
    equal(outcome.response.recorded, true, 'terminal failure was durably acknowledged');
    equal(outcome.response.task_id, task.task_id, 'terminal acknowledged original task');
    equal(outcome.response.activity_attempt_id, task.activity_attempt_id, 'terminal acknowledged original attempt');
    equal(outcome.response.next_task_id, index + 1 < fixture.expected_attempts ? started[index + 1].payload.task.id : raw.workflow_polls[1].task_id, 'acknowledged retry/resumption is original executed task');
    const duplicate = duplicates.failures[index];
    assert.ok([200, 409].includes(duplicate.status), 'terminal explicit duplicate receipt/refusal');
    equal(duplicate.response.recorded, false, 'terminal duplicate does not commit');
    equal(duplicate.response.task_id, task.task_id, 'terminal duplicate original task');
    assert.ok(['already_completed', 'stale_attempt'].includes(duplicate.response.reason), 'terminal duplicate explicit reason');
  }
  const history = events => events.map(({sequence, event_type, payload}) => ({sequence, event_type, payload}));
  for (const [index, task] of raw.workflow_polls.entries()) {
    equal(task.run_id, raw.run_id, 'terminal workflow claim original run');
    equal(task.workflow_id, raw.workflow_id, 'terminal workflow claim original instance');
    equal(history(task.history_events), history(raw.events.slice(0, index === 0 ? 2 : -1)), 'terminal original replay prefix with no intermediate resumption');
    const completion = raw.workflow_completions[index];
    equal(completion.path, `/api/worker/workflow-tasks/${task.task_id}/complete`, 'terminal actual workflow completion endpoint');
    equal(completion.request.lease_owner, task.lease_owner, 'terminal original workflow completion owner');
    equal(completion.request.workflow_task_attempt, task.workflow_task_attempt, 'terminal original workflow completion attempt');
    equal(completion.response.recorded, true, 'terminal original workflow turn acknowledged');
    equal(completion.request.commands.length, 1, 'terminal one authored command per turn');
    const command = completion.request.commands[0];
    equal(command.type, index === 0 ? 'schedule_activity' : 'complete_workflow', 'actual terminal schedule/caught failure completion');
    if (index === 0) {
      equal(command.retry_policy, fixture.retry_policy, 'terminal original authored policy');
      equal(command.arguments, frame(scheduled.payload.activity.arguments), 'terminal original authored input frame');
    } else {
      equal(command.result, raw.execution.output_envelope, 'terminal original caught failure result frame');
    }
  }
  equal(raw.caught_activity_failure, {type: fixture.failure.type, message: fixture.failure.message,
    non_retryable: fixture.non_retryable, history_event_type: 'ActivityFailed', payload: terminal}, 'SDK actually catches original durable terminal failure');
}
