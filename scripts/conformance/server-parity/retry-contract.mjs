import assert from 'node:assert/strict';

export function checkActivityRetry(fixture, raw, identities, projection, instant) {
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const nonempty = (value, label) => assert.ok(typeof value === 'string' && value.length > 0, label);
  const [scheduled, first, retry, second, completed] = raw.events.slice(2, 7);
  const activity = scheduled.payload.activity_execution_id;
  const firstAttempt = first.payload.activity_attempt_id;
  const secondAttempt = second.payload.activity_attempt_id;
  const firstTask = first.payload.task.id;
  const secondTask = second.payload.task.id;
  const policy = {snapshot_version: 1, ...fixture.retry_policy, start_to_close_timeout: null,
    schedule_to_start_timeout: null, schedule_to_close_timeout: null, heartbeat_timeout: null};
  for (const id of [activity, firstAttempt, secondAttempt, firstTask, secondTask]) {
    nonempty(id, 'retry original identity');
    identities.push(id);
  }
  equal(new Set(identities).size, identities.length, 'distinct original activity/attempt/task identities');
  const arguments_ = {type: 'list', value: [fixture.typed_value]};
  const frame = value => typeof value === 'string' ? {codec: 'avro', blob: value} : value;
  for (const [index, event] of [scheduled, first, retry, second, completed].entries()) {
    const payload = event.payload;
    equal(payload.activity_execution_id, activity, 'retry preserves original activity execution');
    equal(payload.activity_type, fixture.retry_activity_type, 'retry registered activity type');
    equal(payload.sequence, 1, 'retry preserves original author sequence');
    equal(payload.activity.id, activity, 'retry snapshot original execution');
    equal(payload.activity.idempotency_key, activity, 'retry preserves side-effect idempotency identity');
    equal(payload.activity.queue, 'server-parity-v1', 'retry preserves activity routing');
    equal(payload.activity.retry_policy, policy, 'immutable retry policy snapshot');
    equal(event.decoded.activity_arguments, [fixture.input], 'retry preserves decoded arguments');
    equal(event.typed_decoded.activity_arguments, arguments_, 'retry exact int64 argument types');
    equal(frame(payload.activity.arguments), frame(scheduled.payload.activity.arguments), 'retry preserves original Avro argument frame');
    Object.assign(projection[index + 2], {activity_execution_id: '@activity:1', command_sequence: 1,
      activity_type: fixture.retry_activity_type, arguments: arguments_, retry_policy: policy});
  }
  for (const [event, attempt, number] of [[first, firstAttempt, 1], [second, secondAttempt, 2], [completed, secondAttempt, 2]]) {
    equal(event.payload.activity_attempt_id, attempt, 'original retry attempt relationship');
    equal(event.payload.attempt_number, number, 'durable activity attempt number');
    Object.assign(projection[event.sequence - 1], {activity_attempt_id: `@attempt:${number}`, attempt_number: number});
  }
  equal(retry.payload.activity_attempt_id, firstAttempt, 'retry resolves the failed attempt');
  equal(retry.payload.retry_after_attempt_id, firstAttempt, 'retry follows original failed attempt');
  equal(retry.payload.retry_after_attempt, 1, 'retry follows first attempt');
  equal(retry.payload.retry_of_task_id, firstTask, 'retry follows original leased task');
  equal(retry.payload.retry_task_id, secondTask, 'new retry task is the task actually executed');
  equal(retry.payload.max_attempts, 2, 'retry attempt budget');
  equal(retry.payload.retry_backoff_seconds, 1, 'retry backoff');
  equal(retry.payload.retry_policy, policy, 'retry event retains original policy');
  equal(retry.payload.message, fixture.failure.message, 'original failure message');
  if (raw.mode === 'embedded') {
    equal(retry.payload.exception_type, null, 'embedded Throwable has no external failure type');
    equal(retry.payload.exception_class, fixture.failure.type, 'embedded original Throwable class');
    equal(retry.payload.exception.class, fixture.failure.type, 'embedded retained failure class');
  } else {
    equal(retry.payload.exception_type, fixture.failure.type, 'external original failure type');
    equal(retry.payload.exception.type, fixture.failure.type, 'external retained failure type');
    equal(retry.payload.exception.non_retryable, false, 'external retryable failure');
  }
  equal(retry.payload.exception.message, fixture.failure.message, 'retained failure message');
  const closed = retry.payload.activity_attempt;
  equal(closed.id, firstAttempt, 'closed failure original attempt');
  equal(closed.activity_execution_id, activity, 'closed failure original activity');
  equal(closed.task_id, firstTask, 'closed failure original task');
  equal(closed.status, 'failed', 'first callback failure is closed durably');
  equal(closed.lease_owner, first.payload.task.lease_owner, 'closed failure original owner');
  const deadline = instant(retry.payload.retry_available_at);
  const computed = deadline - 1_000_000_000n;
  assert.ok(computed >= instant(first.timestamp) && computed <= instant(retry.timestamp), 'one-second deadline was computed during the original failed turn');
  assert.ok(instant(closed.closed_at) >= computed && instant(closed.closed_at) <= instant(retry.timestamp), 'failed attempt closed before retry commit');
  equal(instant(second.payload.task.available_at), deadline, 'retry task retains its original exact deadline');
  assert.ok(instant(second.payload.task.leased_at) >= deadline && instant(second.timestamp) >= deadline, 'retry cannot claim or execute early');
  equal(completed.decoded.result, fixture.output, 'only successful retry resolves the activity');
  equal(completed.typed_decoded.result, fixture.typed_output, 'retry exact result types');
  equal(completed.payload.task.id, secondTask, 'committed retry original task');
  Object.assign(projection[4], {activity_attempt_id: '@attempt:1', retry_task_id: '@activity-task:2',
    retry_of_task_id: '@activity-task:1', retry_after_attempt: 1, retry_backoff_seconds: 1,
    max_attempts: 2, failure: fixture.failure});

  if (raw.mode !== 'embedded') {
    equal(raw.activity_polls.length, 2, 'two actual SDK activity claims');
    equal(raw.activity_outcomes.length, 2, 'one reported failure and one successful completion');
    equal(raw.workflow_polls.length, 2, 'no workflow resume during retry backoff');
    equal(raw.workflow_completions.length, 2, 'one authored activity and one terminal workflow completion');
    for (const [index, task] of raw.workflow_polls.entries()) {
      equal(task.workflow_id, raw.workflow_id, 'workflow retry original instance');
      equal(task.run_id, raw.run_id, 'workflow retry original run');
      equal(task.workflow_type, raw.workflow_type, 'workflow retry original type');
      nonempty(task.lease_owner, 'original workflow owner');
      const history = events => events.map(({sequence, event_type, payload}) => ({sequence, event_type, payload}));
      equal(history(task.history_events), history(raw.events.slice(0, index === 0 ? 2 : 7)), 'SDK original history prefix before and after retry');
      const completion = raw.workflow_completions[index];
      equal(completion.path, `/api/worker/workflow-tasks/${task.task_id}/complete`, 'original workflow task completion');
      equal(completion.request.lease_owner, task.lease_owner, 'original workflow completion owner');
      equal(completion.request.workflow_task_attempt, task.workflow_task_attempt, 'original workflow completion attempt');
      equal(completion.response.task_id, task.task_id, 'workflow completion original task');
      equal(completion.response.run_id, raw.run_id, 'workflow completion original run');
      equal(completion.response.recorded, true, 'workflow turn durably committed');
      equal(completion.request.commands.length, 1, 'one original workflow command per turn');
      const command = completion.request.commands[0];
      equal(command.type, index === 0 ? 'schedule_activity' : 'complete_workflow', 'SDK original workflow turn');
      if (index === 0) {
        equal(command.activity_type, fixture.retry_activity_type, 'SDK original activity authoring');
        equal(command.retry_policy, fixture.retry_policy, 'SDK original authored retry options');
        equal(command.arguments, frame(scheduled.payload.activity.arguments), 'SDK authored argument frame retained');
      } else {
        equal(command.result, raw.execution.output_envelope, 'SDK workflow result frame retained');
      }
    }
    for (const [index, task] of raw.activity_polls.entries()) {
      const event = index === 0 ? first : second;
      equal(task.task_id, event.payload.task.id, 'SDK claim original task');
      equal(task.activity_attempt_id, event.payload.activity_attempt_id, 'SDK claim original attempt');
      equal(task.activity_execution_id, activity, 'SDK claim original execution');
      equal(task.idempotency_key, activity, 'SDK claim unchanged side-effect identity');
      equal(task.attempt_number, index + 1, 'SDK original attempt number');
      equal(task.run_id, raw.run_id, 'SDK retry original run');
      equal(task.workflow_id, raw.workflow_id, 'SDK retry original workflow');
      equal(task.activity_type, fixture.retry_activity_type, 'SDK retry type');
      equal(task.lease_owner, event.payload.task.lease_owner, 'SDK original lease owner');
      equal(task.retry_policy, policy, 'SDK retry policy snapshot');
      equal(task.arguments, frame(scheduled.payload.activity.arguments), 'SDK original argument frame');
      const outcome = raw.activity_outcomes[index];
      equal(outcome.path, `/api/worker/activity-tasks/${task.task_id}/${index === 0 ? 'fail' : 'complete'}`, 'actual SDK outcome endpoint');
      equal(outcome.request.activity_attempt_id, task.activity_attempt_id, 'SDK outcome original attempt');
      equal(outcome.request.lease_owner, task.lease_owner, 'SDK outcome original owner');
      equal(outcome.response.task_id, task.task_id, 'acknowledged outcome original task');
      equal(outcome.response.activity_attempt_id, task.activity_attempt_id, 'acknowledged outcome original attempt');
      equal(outcome.response.recorded, true, 'SDK outcome is durably recorded');
      if (index === 0) {
        equal(outcome.request.failure, {...fixture.failure, non_retryable: false}, 'original SDK failure report');
        equal(outcome.response.next_task_id, secondTask, 'acknowledged retry is the executed task');
      } else {
        equal(outcome.request.result, completed.payload.result, 'SDK successful retry frame unchanged');
      }
    }
  }
  const duplicate = raw.activity_duplicate_receipts;
  equal(duplicate.history_after_failure, duplicate.history_before, 'duplicate failure creates no retry or history');
  equal(duplicate.history_after_completion, duplicate.history_before, 'duplicate completion creates no result or history');
  if (raw.mode === 'embedded') {
    equal(duplicate.redelivered_task_ids, [firstTask, secondTask], 'real queue redelivery of original closed tasks');
    return;
  }
  for (const [kind, task] of [['failure', firstTask], ['completion', secondTask]]) {
    const receipt = duplicate[kind];
    assert.ok([200, 409].includes(receipt.status), 'qualified duplicate outcome status');
    equal(receipt.response.recorded, false, 'duplicate outcome never records another effect');
    equal(receipt.response.task_id, task, 'duplicate outcome retains original task');
    assert.ok(['already_completed', 'stale_attempt'].includes(receipt.response.reason), 'explicit committed duplicate refusal/receipt');
  }
}
