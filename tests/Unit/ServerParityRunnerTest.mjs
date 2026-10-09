import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkObservation, compareRecords as compareCorpusRecords} from '../../scripts/conformance/server-parity/contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/one-activity.json', import.meta.url)));
const reviewed = {'one-activity.json': fixture};
const retryFixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/activity-retry.json', import.meta.url)));
const exhaustedFixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/activity-failure-exhausted.json', import.meta.url)));
const filteredFixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/activity-failure-filtered.json', import.meta.url)));
const cancellationFixtures = ['before-claim', 'pending-timer', 'leased-activity'].map(phase =>
  JSON.parse(readFileSync(new URL(`../Fixtures/ServerParity/cancel-${phase}.json`, import.meta.url))));
const compareRecords = records => compareCorpusRecords(records, {'one-activity.json': 'fixture-sha'}, reviewed);
function observation(suffix = '') {
  const run = `run${suffix}`;
  const command = `command${suffix}`;
  const activity = `activity${suffix}`;
  const attempt = `attempt${suffix}`;
  const start = {workflow_instance_id: 'test-one-activity', workflow_run_id: run, workflow_command_id: command, workflow_type: fixture.workflow_type};
  const activityFields = {activity_execution_id: activity, activity_type: 'parity.v1.echo_activity', sequence: 1};
  const events = [
    {payload: {...start, outcome: 'started_new'}, decoded: {}},
    {payload: {...start, execution_timeout_seconds: 3600, run_timeout_seconds: 600, execution_deadline_at: '2026-01-01T01:00:00Z', run_deadline_at: '2026-01-01T00:10:00Z'}, decoded: {}},
    {payload: {...activityFields}, decoded: {activity_arguments: [fixture.input]}},
    {payload: {...activityFields, activity_attempt_id: attempt, attempt_number: 1}, decoded: {activity_arguments: [fixture.input]}},
    {payload: {...activityFields, activity_attempt_id: attempt, attempt_number: 1}, decoded: {result: fixture.input}},
    {payload: {}, decoded: {output: fixture.input}},
  ].map((event, index) => ({...event, typed_decoded: Object.fromEntries(Object.keys(event.decoded).map(key => [key, key === 'activity_arguments' ? {type: 'list', value: [fixture.typed_value]} : fixture.typed_value])), event_type: fixture.expected_events[index], sequence: index + 1, timestamp: `2026-01-01T00:00:0${index}Z`}));
  return structuredClone({sdk_php: '2.2.6', workflow_id: 'test-one-activity', run_id: run, workflow_type: fixture.workflow_type, namespace: 'default', task_queue: 'server-parity-v1', status: 'completed', payload_codec: 'avro', input: [fixture.input], output: fixture.input, typed_input: {type: 'list', value: [fixture.typed_value]}, typed_output: fixture.typed_value, execution: {started_at: '2026-01-01T00:00:00Z'}, events});
}
function record(suffix = '') {
  const raw = observation(suffix);
  return {schema: 'durable-workflow.server-parity-record/v1', target: `target${suffix}`, outcome: 'pass', runner_revision: 'a'.repeat(40), fixture_hashes: {'one-activity.json': 'fixture-sha'}, artifacts: {sdk_php: '2.2.6'}, cases: [{fixture_id: fixture.id, fixture, observation: raw, projection: checkObservation(fixture, raw, 'test-one-activity')}]};
}

function cancellationObservation(fixture, mode = 'http') {
  const raw = observation();
  const {phase} = fixture.immediate_cancellation;
  const reason = mode === 'http'
    ? fixture.immediate_cancellation.http_reason ?? fixture.immediate_cancellation.reason
    : fixture.immediate_cancellation.reason;
  Object.assign(raw, {mode, workflow_type: fixture.workflow_type, status: 'cancelled',
    input: [fixture.input], typed_input: {type: 'list', value: [fixture.typed_value]},
    output: null, typed_output: {type: 'null', value: null}});
  raw.events = raw.events.slice(0, 2);
  for (const event of raw.events) event.payload.workflow_type = fixture.workflow_type;
  const frame = {codec: 'avro', blob: 'original-cancel-arguments'};
  const activity = {id: 'cancel-activity', arguments: frame, status: 'running', attempt_count: 1};
  const activityBase = {activity_execution_id: 'cancel-activity', activity_type: 'parity.v1.cancel_activity', sequence: 1, activity};
  if (phase === 'pending_timer') raw.events.push({payload: {timer_id: 'cancel-timer', sequence: 1, delay_seconds: 60, fire_at: '2026-01-01T00:01:02Z'}, decoded: {}, typed_decoded: {}});
  if (phase === 'leased_activity') raw.events.push(
    {payload: structuredClone(activityBase), decoded: {activity_arguments: [fixture.input]}, typed_decoded: {activity_arguments: {type: 'list', value: [fixture.typed_value]}}},
    {payload: {...structuredClone(activityBase), activity_attempt_id: 'cancel-attempt', attempt_number: 1, task: {id: 'cancel-task', lease_owner: 'cancel-owner'}},
      decoded: {activity_arguments: [fixture.input]}, typed_decoded: {activity_arguments: {type: 'list', value: [fixture.typed_value]}}});
  const common = {workflow_instance_id: raw.workflow_id, workflow_run_id: raw.run_id, workflow_command_id: 'cancel-command', reason};
  raw.events.push({payload: {...common, command_type: 'cancel'}, decoded: {}, typed_decoded: {}});
  if (phase === 'pending_timer') raw.events.push({payload: {...raw.events[2].payload, cancelled_at: '2026-01-01T00:00:04Z'}, decoded: {}, typed_decoded: {}});
  if (phase === 'leased_activity') raw.events.push({payload: {...structuredClone(activityBase), workflow_command_id: 'cancel-command',
    activity_attempt_id: 'cancel-attempt', attempt_number: 1,
    activity: {...activity, status: 'cancelled', closed_at: '2026-01-01T00:00:05Z'},
    activity_attempt: {id: 'cancel-attempt', activity_execution_id: 'cancel-activity', task_id: 'cancel-task',
      lease_owner: 'cancel-owner', status: 'cancelled', closed_at: '2026-01-01T00:00:05Z'}},
    decoded: {activity_arguments: [fixture.input]}, typed_decoded: {activity_arguments: {type: 'list', value: [fixture.typed_value]}}});
  raw.events.push({payload: {...common, failure_id: 'cancel-failure', failure_category: 'cancelled', closed_reason: 'cancelled',
    exception_class: 'Workflow\\V2\\Exceptions\\WorkflowCancelledException', message: `Workflow cancelled: ${reason}`}, decoded: {}, typed_decoded: {}});
  raw.events = raw.events.map((event, index) => ({...event, sequence: index + 1, event_type: fixture.expected_events[index], timestamp: `2026-01-01T00:00:0${index}Z`}));
  Object.assign(raw.execution, {closed_reason: 'cancelled', closed_at: raw.events.at(-1).timestamp});
  const history = raw.events.map(({decoded, typed_decoded, ...event}) => event);
  const accepted = {command_id: 'cancel-command', command_status: 'accepted', outcome: 'cancelled', command_sequence: 2,
    workflow_id: raw.workflow_id, run_id: raw.run_id, target_scope: fixture.immediate_cancellation.receipt_target_scope[mode]};
  const stale = {status: 409, response: {reason: 'run_cancelled', outcome: 'ignored', cancel_requested: true, can_continue: false,
    lease_owner: 'cancel-owner', lease_expires_at: null, recorded: false, task_id: 'cancel-task', activity_attempt_id: 'cancel-attempt',
    activity_status: 'cancelled', attempt_status: 'cancelled', task_status: 'cancelled'}};
  raw.cancellation = {before: history.slice(0, history.findIndex(event => event.event_type === 'CancelRequested')),
    accepted: {status: 200, response: accepted}, duplicate: {status: 409, response: {...accepted,
      command_id: 'rejected-cancel', command_sequence: 3, command_status: 'rejected', rejection_reason: 'run_not_active'}},
    history_before_duplicate: history, history_after_duplicate: history, fresh_history: history,
    fresh_execution: {id: raw.run_id, status: 'cancelled', output: null, cancelled: true},
    outcome: {type: 'DurableWorkflow\\Exception\\WorkflowCancelled', message: reason}, remaining_tasks: {workflow: null, activity: null},
    late_completion: phase === 'leased_activity' ? stale : null, redelivered_task_ids: phase === 'leased_activity' ? ['cancel-task'] : [],
    failures: [{id: 'cancel-failure', workflow_run_id: raw.run_id, source_kind: 'workflow_run', source_id: raw.run_id,
      propagation_kind: 'cancelled', failure_category: 'cancelled', exception_class: raw.events.at(-1).payload.exception_class,
      message: raw.events.at(-1).payload.message, handled: false}], tasks: [{id: 'cancel-task', status: 'cancelled', lease_expires_at: null}],
    attempts: [{id: 'cancel-attempt', workflow_task_id: 'cancel-task', status: 'cancelled', lease_expires_at: null}]};
  raw.workflow_polls = phase === 'before_claim' ? [] : [{}];
  raw.workflow_completions = phase === 'before_claim' ? [] : [{}];
  raw.activity_polls = phase === 'leased_activity' ? [{task_id: 'cancel-task', activity_attempt_id: 'cancel-attempt',
    activity_execution_id: 'cancel-activity', lease_owner: 'cancel-owner', arguments: frame}] : [];
  raw.activity_outcomes = phase === 'leased_activity' ? [{...stale, path: '/api/worker/activity-tasks/cancel-task/complete',
    request: {activity_attempt_id: 'cancel-attempt', lease_owner: 'cancel-owner'}, decoded_result: fixture.input, typed_result: fixture.typed_value}] : [];
  return structuredClone(raw);
}

for (const mode of ['http', 'embedded']) {
  test(`cancellation reason keeps the declared ${mode} boundary representation`, () => {
    const fixture = cancellationFixtures.find(fixture => fixture.id === 'cancel-before-claim');
    const raw = cancellationObservation(fixture, mode);
    const wrong = mode === 'http' ? fixture.immediate_cancellation.reason : fixture.immediate_cancellation.http_reason;
    for (const event of raw.events.filter(event => ['CancelRequested', 'WorkflowCancelled'].includes(event.event_type))) {
      event.payload.reason = wrong;
      if (event.event_type === 'WorkflowCancelled') event.payload.message = `Workflow cancelled: ${wrong}`;
    }
    assert.throws(() => checkObservation(fixture, raw, 'test-one-activity'), /cancellation reason/);
  });
}

for (const fixture of cancellationFixtures) {
  test(`${fixture.id} checks the shared immediate cancellation contract`, () => {
    assert.deepStrictEqual(checkObservation(fixture, cancellationObservation(fixture), 'test-one-activity'),
      checkObservation(fixture, cancellationObservation(fixture, 'embedded'), 'test-one-activity'));
  });
  for (const [label, mutate] of [
    ['replacement failure', raw => { raw.events.at(-1).payload.failure_id = raw.run_id; }],
    ['replacement reason', raw => { raw.events.at(-1).payload.reason = 'replacement'; }],
    ['success output', raw => { raw.output = fixture.input; }],
    ['accepted repeat', raw => { raw.cancellation.duplicate.status = 200; }],
    ['rewritten history', raw => { raw.cancellation.fresh_history.at(-1).payload.message = 'changed'; }],
    ['wrong typed result', raw => { raw.cancellation.outcome.type = 'RuntimeException'; }],
    ['remaining work', raw => { raw.cancellation.remaining_tasks.workflow = {task_id: 'new'}; }],
  ]) test(`${fixture.id} rejects ${label}`, () => {
    const raw = cancellationObservation(fixture);
    mutate(raw);
    assert.throws(() => checkObservation(fixture, raw, 'test-one-activity'));
  });
}
for (const [label, mutate] of [
  ['recomputed deadline', raw => { raw.events[4].payload.fire_at = '2026-01-01T00:02:02Z'; }],
  ['replacement timer', raw => { raw.events[4].payload.timer_id = 'replacement'; }],
]) test(`timer cancellation rejects ${label}`, () => {
  const fixture = cancellationFixtures[1], raw = cancellationObservation(fixture);
  mutate(raw);
  assert.throws(() => checkObservation(fixture, raw, 'test-one-activity'));
});
for (const [label, mutate] of [
  ['replacement attempt', raw => { raw.events[5].payload.activity_attempt_id = 'replacement'; }],
  ['retained lease', raw => { raw.events[5].payload.activity_attempt.lease_expires_at = '2026-01-01T00:01:00Z'; }],
  ['committed late result', raw => { raw.activity_outcomes[0].response.recorded = true; }],
  ['unfenced completion', raw => { raw.cancellation.late_completion.status = 200; }],
  ['wrong task owner', raw => { raw.activity_polls[0].lease_owner = 'replacement'; }],
]) test(`activity cancellation rejects ${label}`, () => {
  const fixture = cancellationFixtures[2], raw = cancellationObservation(fixture);
  mutate(raw);
  assert.throws(() => checkObservation(fixture, raw, 'test-one-activity'));
});

function retryObservation(mode = 'http') {
  const raw = observation();
  raw.mode = mode;
  raw.workflow_type = retryFixture.workflow_type;
  raw.input = [retryFixture.input];
  raw.typed_input = {type: 'list', value: [retryFixture.typed_value]};
  raw.output = retryFixture.output;
  raw.typed_output = retryFixture.typed_output;
  raw.events = raw.events.slice(0, 2);
  for (const event of raw.events) event.payload.workflow_type = raw.workflow_type;
  const arguments_ = {type: 'list', value: [retryFixture.typed_value]};
  const frame = {codec: 'avro', blob: 'original-arguments-frame'};
  const policy = {snapshot_version: 1, ...retryFixture.retry_policy, start_to_close_timeout: null,
    schedule_to_start_timeout: null, schedule_to_close_timeout: null, heartbeat_timeout: null};
  const base = {activity_execution_id: 'activity-retry', activity_type: retryFixture.retry_activity_type, sequence: 1,
    activity: {id: 'activity-retry', idempotency_key: 'activity-retry', queue: 'server-parity-v1', retry_policy: policy, arguments: frame}};
  const task = index => ({id: `activity-task-${index}`, lease_owner: `owner-${index}`,
    available_at: index === 1 ? '2026-01-01T00:00:01Z' : '2026-01-01T00:00:02.090000Z',
    leased_at: index === 1 ? '2026-01-01T00:00:01Z' : '2026-01-01T00:00:02.100000Z'});
  raw.events.push(
    {payload: {...base}, decoded: {activity_arguments: [retryFixture.input]}, typed_decoded: {activity_arguments: arguments_}},
    {payload: {...base, activity_attempt_id: 'attempt-1', attempt_number: 1, task: task(1)}, decoded: {activity_arguments: [retryFixture.input]}, typed_decoded: {activity_arguments: arguments_}},
    {payload: {...base, activity_attempt_id: 'attempt-1', retry_after_attempt_id: 'attempt-1', retry_after_attempt: 1,
      retry_task_id: 'activity-task-2', retry_of_task_id: 'activity-task-1', retry_backoff_seconds: 1,
      retry_available_at: '2026-01-01T00:00:02.090000Z', max_attempts: 2, retry_policy: policy,
      message: retryFixture.failure.message, exception_type: mode === 'embedded' ? null : retryFixture.failure.type,
      exception_class: retryFixture.failure.type,
      exception: mode === 'embedded' ? {class: retryFixture.failure.type, message: retryFixture.failure.message}
        : {...retryFixture.failure, non_retryable: false},
      activity_attempt: {id: 'attempt-1', activity_execution_id: 'activity-retry', task_id: 'activity-task-1',
        status: 'failed', lease_owner: 'owner-1', closed_at: '2026-01-01T00:00:01.095000Z'}},
      decoded: {activity_arguments: [retryFixture.input]}, typed_decoded: {activity_arguments: arguments_}},
    {payload: {...base, activity_attempt_id: 'attempt-2', attempt_number: 2, task: task(2)}, decoded: {activity_arguments: [retryFixture.input]}, typed_decoded: {activity_arguments: arguments_}},
    {payload: {...base, activity_attempt_id: 'attempt-2', attempt_number: 2, task: task(2), result: {codec: 'avro', blob: 'retry-result-frame'}},
      decoded: {activity_arguments: [retryFixture.input], result: retryFixture.output}, typed_decoded: {activity_arguments: arguments_, result: retryFixture.typed_output}},
    {payload: {}, decoded: {output: retryFixture.output}, typed_decoded: {output: retryFixture.typed_output}},
  );
  const times = ['00', '00', '00.900', '01', '01.100', '02.110', '02.120', '02.130'];
  raw.events = raw.events.map((event, index) => ({...structuredClone(event), event_type: retryFixture.expected_events[index], sequence: index + 1,
    timestamp: `2026-01-01T00:00:${times[index]}Z`}));
  raw.activity_polls = [1, 2].map(index => ({task_id: `activity-task-${index}`, activity_attempt_id: `attempt-${index}`,
    activity_execution_id: 'activity-retry', idempotency_key: 'activity-retry', run_id: raw.run_id, workflow_id: raw.workflow_id,
    activity_type: retryFixture.retry_activity_type, attempt_number: index, lease_owner: `owner-${index}`, retry_policy: policy, arguments: frame}));
  raw.activity_outcomes = raw.activity_polls.map((claim, index) => ({path: `/api/worker/activity-tasks/${claim.task_id}/${index === 0 ? 'fail' : 'complete'}`,
    request: {activity_attempt_id: claim.activity_attempt_id, lease_owner: claim.lease_owner,
      ...(index === 0 ? {failure: {...retryFixture.failure, non_retryable: false}} : {result: {codec: 'avro', blob: 'retry-result-frame'}})},
    response: {task_id: claim.task_id, activity_attempt_id: claim.activity_attempt_id, recorded: true, ...(index === 0 ? {next_task_id: 'activity-task-2'} : {})}}));
  raw.workflow_polls = [2, 7].map((prefix, index) => ({task_id: `workflow-task-${index}`, workflow_id: raw.workflow_id,
    run_id: raw.run_id, workflow_type: raw.workflow_type, lease_owner: 'workflow-owner', workflow_task_attempt: 1,
    history_events: structuredClone(raw.events.slice(0, prefix))}));
  raw.workflow_completions = raw.workflow_polls.map((task, index) => ({path: `/api/worker/workflow-tasks/${task.task_id}/complete`,
    request: {lease_owner: task.lease_owner, workflow_task_attempt: 1, commands: [index === 0
      ? {type: 'schedule_activity', activity_type: retryFixture.retry_activity_type, arguments: frame, retry_policy: retryFixture.retry_policy}
      : {type: 'complete_workflow', result: {codec: 'avro', blob: 'workflow-result-frame'}}]},
    response: {task_id: task.task_id, run_id: raw.run_id, recorded: true}}));
  raw.execution.output_envelope = {codec: 'avro', blob: 'workflow-result-frame'};
  raw.activity_duplicate_receipts = {history_before: ['original-history'], history_after_failure: ['original-history'], history_after_completion: ['original-history'],
    ...(mode === 'embedded' ? {redelivered_task_ids: ['activity-task-1', 'activity-task-2']}
      : {failure: {status: 409, response: {task_id: 'activity-task-1', recorded: false, reason: 'stale_attempt'}},
        completion: {status: 200, response: {task_id: 'activity-task-2', recorded: false, reason: 'already_completed'}}})};
  return structuredClone(raw);
}

test('reported failure retries preserve the common PHP/embedded durable contract', () => {
  assert.deepStrictEqual(checkObservation(retryFixture, retryObservation(), 'test-one-activity'),
    checkObservation(retryFixture, retryObservation('embedded'), 'test-one-activity'));
});

function terminalObservation(fixture, mode = 'http') {
  const raw = retryObservation(mode);
  raw.workflow_type = fixture.workflow_type;
  raw.input = [fixture.input];
  raw.typed_input = {type: 'list', value: [fixture.typed_value]};
  raw.output = fixture.output;
  raw.typed_output = fixture.typed_output;
  const policy = {snapshot_version: 1, ...fixture.retry_policy, start_to_close_timeout: null,
    schedule_to_start_timeout: null, schedule_to_close_timeout: null, heartbeat_timeout: null};
  for (const event of raw.events.slice(0, 2)) event.payload.workflow_type = fixture.workflow_type;
  for (const event of raw.events.slice(2, -1)) {
    event.payload.activity_type = fixture.failure_activity_type;
    Object.assign(event.payload.activity, {retry_policy: structuredClone(policy), status: 'running', attempt_count: event.payload.attempt_number ?? 1});
    event.decoded = {activity_arguments: [fixture.input]};
    event.typed_decoded = {activity_arguments: {type: 'list', value: [fixture.typed_value]}};
  }
  Object.assign(raw.events[4].payload, {message: fixture.failure.message,
    exception: mode === 'embedded' ? {class: fixture.failure.type, message: fixture.failure.message}
      : {...fixture.failure, non_retryable: false}});
  const failed = raw.events[6];
  failed.event_type = 'ActivityFailed';
  Object.assign(failed.payload, {activity_attempt_id: `attempt-${fixture.expected_attempts}`,
    attempt_number: fixture.expected_attempts, failure_id: 'original-failure', failure_category: 'activity',
    non_retryable: fixture.non_retryable, message: fixture.failure.message, code: 0,
    exception_class: fixture.failure.type, exception_type: mode === 'embedded' ? null : fixture.failure.type,
    exception: mode === 'embedded' ? {class: fixture.failure.type, message: fixture.failure.message}
      : {...fixture.failure, non_retryable: false}});
  Object.assign(failed.payload.activity, {status: 'failed', attempt_count: fixture.expected_attempts, closed_at: failed.timestamp});
  delete failed.payload.result;
  raw.events.at(-1).decoded = {output: fixture.output};
  raw.events.at(-1).typed_decoded = {output: fixture.typed_output};
  if (fixture.expected_attempts === 1) raw.events.splice(4, 2);
  if (mode === 'embedded') raw.events.splice(-1, 0, {event_type: 'FailureHandled', timestamp: '2026-01-01T00:00:02.125Z',
    payload: {failure_id: 'original-failure', sequence: 1, failure_category: 'activity', source_kind: 'activity_execution',
      source_id: 'activity-retry', propagation_kind: 'activity', exception_class: fixture.failure.type,
      message: fixture.failure.message, handled: true}, decoded: {}, typed_decoded: {}});
  raw.events.forEach((event, index) => {event.sequence = index + 1;});
  raw.activity_polls = raw.activity_polls.slice(0, fixture.expected_attempts);
  for (const task of raw.activity_polls) {
    task.activity_type = fixture.failure_activity_type;
    task.retry_policy = structuredClone(policy);
  }
  raw.activity_outcomes = raw.activity_polls.map((claim, index) => ({path: `/api/worker/activity-tasks/${claim.task_id}/fail`,
    request: {activity_attempt_id: claim.activity_attempt_id, lease_owner: claim.lease_owner, failure: {...fixture.failure, non_retryable: false}},
    response: {task_id: claim.task_id, activity_attempt_id: claim.activity_attempt_id, recorded: true,
      next_task_id: index + 1 === fixture.expected_attempts ? 'workflow-task-1' : 'activity-task-2'}}));
  raw.workflow_polls.forEach((task, index) => {
    task.workflow_type = raw.workflow_type;
    task.history_events = structuredClone(raw.events.slice(0, index === 0 ? 2 : -1));
  });
  Object.assign(raw.workflow_completions[0].request.commands[0], {activity_type: fixture.failure_activity_type, retry_policy: fixture.retry_policy});
  raw.caught_activity_failure = {type: fixture.failure.type, message: fixture.failure.message,
    non_retryable: fixture.non_retryable, history_event_type: 'ActivityFailed', payload: structuredClone(failed.payload)};
  raw.activity_duplicate_receipts = {history_before: ['original-history'], history_after: ['original-history'],
    ...(mode === 'embedded' ? {redelivered_task_ids: raw.activity_polls.map(task => task.task_id)}
      : {failures: raw.activity_polls.map(task => ({status: 409, response: {task_id: task.task_id, recorded: false, reason: 'stale_attempt'}}))})};
  return structuredClone(raw);
}

for (const fixture of [exhaustedFixture, filteredFixture]) {
  test(`${fixture.id} preserves catchable failure and common PHP/embedded semantics`, () => {
    assert.deepStrictEqual(checkObservation(fixture, terminalObservation(fixture), 'test-one-activity'),
      checkObservation(fixture, terminalObservation(fixture, 'embedded'), 'test-one-activity'));
  });
}
for (const [name, corrupt] of [
  ['wrong original handled failure', raw => {raw.events.at(-2).payload.failure_id = 'other';}],
  ['wrong original handled activity', raw => {raw.events.at(-2).payload.source_id = 'other';}],
  ['unacknowledged embedded catch', raw => {raw.events.at(-2).payload.handled = false;}],
]) {
  test(`refuses embedded terminal ${name}`, () => {
    const raw = terminalObservation(filteredFixture, 'embedded');
    corrupt(raw);
    assert.throws(() => checkObservation(filteredFixture, raw, raw.workflow_id));
  });
}
for (const [name, corrupt] of [
  ['lost failure identity', raw => {raw.events[6].payload.failure_id = null;}],
  ['reused failure identity', raw => {raw.events[6].payload.failure_id = raw.run_id;}],
  ['wrong terminal execution', raw => {raw.events[6].payload.activity_execution_id = 'other';}],
  ['wrong terminal attempt', raw => {raw.events[6].payload.activity_attempt_id = 'attempt-1';}],
  ['unclosed terminal execution', raw => {raw.events[6].payload.activity.status = 'running';}],
  ['early terminal closure', raw => {raw.events[6].payload.activity.closed_at = '2026-01-01T00:00:00Z';}],
  ['budget exhaustion incorrectly non-retryable', raw => {raw.events[6].payload.non_retryable = true;}],
  ['wrong failure category', raw => {raw.events[6].payload.failure_category = 'workflow';}],
  ['wrong terminal exception', raw => {raw.events[6].payload.exception.type = 'other';}],
  ['wrong terminal message', raw => {raw.events[6].payload.message = 'other';}],
  ['changed terminal arguments', raw => {raw.events[6].payload.activity.arguments.blob = 'other';}],
  ['lost exact terminal int64', raw => {raw.events[6].typed_decoded.activity_arguments.value[0].value.large.value = '9007199254740992';}],
  ['changed terminal retry filter', raw => {raw.events[6].payload.activity.retry_policy.non_retryable_error_types.push('RuntimeException');}],
  ['early terminal retry', raw => {raw.events[5].payload.task.leased_at = '2026-01-01T00:00:02.089999Z';}],
  ['wrong acknowledged resumption', raw => {raw.activity_outcomes[1].response.next_task_id = 'other';}],
  ['unacknowledged terminal report', raw => {raw.activity_outcomes[1].response.recorded = false;}],
  ['intermediate terminal workflow resumption', raw => {raw.workflow_polls.push({});}],
  ['caught failure loses original payload', raw => {raw.caught_activity_failure.payload.failure_id = 'other';}],
  ['terminal SDK report targets wrong attempt', raw => {raw.activity_outcomes[1].request.activity_attempt_id = 'attempt-1';}],
  ['duplicate terminal report commits again', raw => {raw.activity_duplicate_receipts.failures[1].response.recorded = true;}],
  ['terminal duplicate changes history', raw => {raw.activity_duplicate_receipts.history_after.push('extra');}],
]) {
  test(`refuses terminal ${name}`, () => {
    const raw = terminalObservation(exhaustedFixture);
    corrupt(raw);
    assert.throws(() => checkObservation(exhaustedFixture, raw, raw.workflow_id));
  });
}
for (const [name, corrupt] of [
  ['replaced activity identity', raw => {raw.events[5].payload.activity_execution_id = 'different';}],
  ['reused retry attempt', raw => {raw.events[5].payload.activity_attempt_id = 'attempt-1';}],
  ['reused retry task', raw => {raw.events[5].payload.task.id = 'activity-task-1';}],
  ['wrong retry ancestor', raw => {raw.events[4].payload.retry_after_attempt_id = 'different';}],
  ['wrong executed retry task', raw => {raw.events[4].payload.retry_task_id = 'different';}],
  ['wrong failure owner', raw => {raw.events[4].payload.activity_attempt.lease_owner = 'different';}],
  ['unclosed failure', raw => {raw.events[4].payload.activity_attempt.status = 'running';}],
  ['changed failure type', raw => {raw.events[4].payload.exception.type = 'different';}],
  ['changed failure message', raw => {raw.events[4].payload.message = 'different';}],
  ['replaced argument bytes', raw => {raw.events[5].payload.activity.arguments.blob = 'different';}],
  ['changed retry policy', raw => {raw.events[5].payload.activity.retry_policy.max_attempts = 3;}],
  ['early claim by one microsecond', raw => {raw.events[5].payload.task.leased_at = '2026-01-01T00:00:02.089999Z';}],
  ['recomputed backoff deadline', raw => {raw.events[5].payload.task.available_at = '2026-01-01T00:00:02.090001Z';}],
  ['extended failure deadline', raw => {raw.events[4].payload.retry_available_at = '2026-01-01T00:00:03Z';}],
  ['wrong typed retry result', raw => {raw.events[6].typed_decoded.result.value.echo.value.large.value = '9007199254740992';}],
  ['SDK claim changed execution', raw => {raw.activity_polls[1].activity_execution_id = 'different';}],
  ['SDK completion uses stale attempt', raw => {raw.activity_outcomes[1].request.activity_attempt_id = 'attempt-1';}],
  ['unacknowledged failure', raw => {raw.activity_outcomes[0].response.recorded = false;}],
  ['intermediate workflow resumption', raw => {raw.workflow_polls.push({});}],
  ['duplicate failure creates history', raw => {raw.activity_duplicate_receipts.history_after_failure.push('new');}],
  ['duplicate completion records again', raw => {raw.activity_duplicate_receipts.completion.response.recorded = true;}],
]) {
  test(`refuses retry ${name}`, () => {
    const raw = retryObservation();
    corrupt(raw);
    assert.throws(() => checkObservation(retryFixture, raw, raw.workflow_id));
  });
}

test('independent generated IDs preserve their original relationships', () => {
  compareRecords([record(), record('-other-database')]);
});

for (const [name, corrupt] of [
  ['accepted work without completion', raw => {raw.status = 'pending';}],
  ['lost completion event', raw => {raw.events.pop();}],
  ['duplicate committed completion', raw => {raw.events.push(structuredClone(raw.events.at(-1)));}],
  ['reordered events', raw => {[raw.events[2], raw.events[3]] = [raw.events[3], raw.events[2]];}],
  ['history sequence gap', raw => {raw.events[3].sequence++;}],
  ['changed public workflow ID', raw => {raw.workflow_id = 'different-workflow';}],
  ['broken run relationship', raw => {raw.events[1].payload.workflow_run_id = 'unrelated-run';}],
  ['broken command relationship', raw => {raw.events[1].payload.workflow_command_id = 'unrelated-command';}],
  ['changed activity identity', raw => {raw.events[4].payload.activity_execution_id = 'unrelated-activity';}],
  ['changed committed attempt', raw => {raw.events[4].payload.activity_attempt_id = 'stale-attempt';}],
  ['colliding generated identities', raw => {raw.events[3].payload.activity_attempt_id = raw.run_id; raw.events[4].payload.activity_attempt_id = raw.run_id;}],
  ['changed attempt count', raw => {raw.events[4].payload.attempt_number = 2;}],
  ['wrong activity arguments', raw => {raw.events[2].decoded.activity_arguments = ['wrong'];}],
  ['wrong activity result', raw => {raw.events[4].decoded.result = 'wrong';}],
  ['wrong typed workflow result', raw => {raw.output.count = '42';}],
  ['integral double substituted for int64', raw => {raw.typed_output.value.count = {type: 'double', value: 42};}],
  ['wrong committed history value type', raw => {raw.events[4].typed_decoded.result.value.count = {type: 'double', value: 42};}],
  ['extended run deadline', raw => {raw.events[1].payload.run_deadline_at = '2026-01-01T00:20:00Z';}],
]) {
  test(`refuses ${name}`, () => {
    const raw = observation();
    corrupt(raw);
    assert.throws(() => checkObservation(fixture, raw, 'test-one-activity'));
  });
}

const signalFixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/repeated-signal.json', import.meta.url)));
const childFixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/two-child-workflows.json', import.meta.url)));
function childObservation(mode = 'http') {
  const raw = observation();
  raw.mode = mode;
  raw.workflow_type = childFixture.workflow_type;
  raw.input = [childFixture.input];
  raw.typed_input = {type: 'list', value: [childFixture.typed_value]};
  raw.output = childFixture.output;
  raw.typed_output = childFixture.typed_output;
  raw.execution.output_envelope = {codec: 'avro', blob: 'parent-result-frame'};
  raw.events = raw.events.slice(0, 2);
  for (const event of raw.events) event.payload.workflow_type = raw.workflow_type;
  raw.children = childFixture.input.payloads.map((input, index) => {
    const child = observation(`-child-${index}`);
    child.workflow_id = `child-instance-${index}`;
    child.workflow_type = childFixture.child_workflow_type;
    child.input = [input];
    child.typed_input = {type: 'list', value: [childFixture.typed_value.value.payloads.value[index]]};
    child.output = childFixture.output.child_results[index];
    child.typed_output = childFixture.typed_output.value.child_results.value[index];
    child.execution.input_envelope = {codec: 'avro', blob: `child-arguments-frame-${index}`};
    child.execution.output_envelope = {codec: 'avro', blob: `child-result-frame-${index}`};
    child.execution.closed_at = '2026-01-01T00:00:01.123456Z';
    for (const event of child.events.slice(0, 2)) {
      Object.assign(event.payload, {workflow_instance_id: child.workflow_id, workflow_run_id: child.run_id, workflow_type: child.workflow_type});
    }
    const link = {sequence: index + 1, child_call_id: `child-call-${index}`, workflow_link_id: `child-call-${index}`,
      child_workflow_instance_id: child.workflow_id, child_workflow_run_id: child.run_id,
      child_workflow_type: child.workflow_type, parent_close_policy: 'abandon', cancellation_policy: 'abandon'};
    Object.assign(child.events[1].payload, {parent_workflow_instance_id: raw.workflow_id, parent_workflow_run_id: raw.run_id,
      parent_sequence: index + 1, child_call_id: link.child_call_id, workflow_link_id: link.workflow_link_id});
    for (const event of child.events.slice(2, 4)) {
      event.payload.activity = {arguments: child.execution.input_envelope};
      event.decoded.activity_arguments = [input];
      event.typed_decoded.activity_arguments = child.typed_input;
    }
    child.events[4].decoded.result = input;
    child.events[4].typed_decoded.result = child.typed_input.value[0];
    child.events[5].decoded.output = child.output;
    child.events[5].typed_decoded.output = child.typed_output;
    if (mode === 'http') child.events.shift();
    child.events = child.events.map((event, position) => ({...event, sequence: position + 1, timestamp: '2026-01-01T00:00:01Z'}));
    raw.events.push(
      {event_type: 'ChildWorkflowScheduled', payload: {...link}, decoded: {}, typed_decoded: {}},
      {event_type: 'ChildRunStarted', payload: {...link, child_run_number: 1}, decoded: {}, typed_decoded: {}},
      {event_type: 'ChildRunCompleted', payload: {...link, child_run_number: 1, child_status: 'completed', closed_at: child.execution.closed_at},
        decoded: {output: child.output, result: child.output}, typed_decoded: {output: child.typed_output, result: child.typed_output}},
    );
    return child;
  });
  raw.events.push({event_type: 'WorkflowCompleted', payload: {}, decoded: {output: raw.output}, typed_decoded: {output: raw.typed_output}});
  raw.events = raw.events.map((event, index) => ({...event, sequence: index + 1, timestamp: '2026-01-01T00:00:01Z'}));
  raw.workflow_polls = [];
  raw.workflow_completions = [];
  for (const owner of [raw, ...raw.children]) {
    const parent = owner === raw;
    for (let turn = 0; turn < (parent ? 3 : 2); turn++) {
      const complete = turn === (parent ? 2 : 1);
      const index = parent ? turn : raw.children.indexOf(owner);
      const task = {task_id: `task-${owner.workflow_id}-${turn}`, lease_owner: 'published-worker', workflow_task_attempt: 1,
        workflow_id: owner.workflow_id, run_id: owner.run_id, workflow_type: owner.workflow_type,
        history_events: structuredClone(owner.events.slice(0, parent ? 2 + turn * 3 : 1 + turn * 3))};
      if (parent && turn > 0) {
        const child = raw.children[turn - 1];
        Object.assign(task, {child_call_id: `child-call-${turn - 1}`, child_workflow_run_id: child.run_id,
          resume_source_kind: 'child_workflow_run', resume_source_id: child.run_id, workflow_event_type: 'ChildRunCompleted',
          workflow_sequence: turn, open_wait_id: `child:child-call-${turn - 1}`});
      }
      const command = {type: complete ? 'complete_workflow' : parent ? 'start_child_workflow' : 'schedule_activity',
        ...(parent ? {workflow_type: childFixture.child_workflow_type} : {activity_type: 'parity.v1.echo_activity'}),
        ...(complete ? {result: owner.execution.output_envelope} : {arguments: raw.children[index].execution.input_envelope})};
      const decoded = complete ? {result: owner.output} : {arguments: [childFixture.input.payloads[index]]};
      const typed = complete ? {result: owner.typed_output} : {arguments: {type: 'list', value: [childFixture.typed_value.value.payloads.value[index]]}};
      raw.workflow_polls.push(task);
      raw.workflow_completions.push({path: `/api/worker/workflow-tasks/${task.task_id}/complete`,
        request: {lease_owner: task.lease_owner, workflow_task_attempt: 1, commands: [command]},
        response: {task_id: task.task_id, run_id: owner.run_id, recorded: true},
        decoded_commands: [{decoded, typed_decoded: typed}]});
    }
  }
  return structuredClone(raw);
}

test('sequential child/activity results agree across explicit HTTP and embedded child histories', () => {
  assert.deepStrictEqual(checkObservation(childFixture, childObservation(), 'test-one-activity'),
    checkObservation(childFixture, childObservation('embedded'), 'test-one-activity'));
});

for (const [name, corrupt] of [
  ['missing real child', raw => {raw.children.pop();}],
  ['child remains pending', raw => {raw.children[0].status = 'pending';}],
  ['changed resolved child run', raw => {raw.events[4].payload.child_workflow_run_id = 'another-run';}],
  ['changed original child link', raw => {raw.events[3].payload.workflow_link_id = 'another-link';}],
  ['reused child identity', raw => {raw.children[1].workflow_id = raw.children[0].workflow_id;}],
  ['changed child parent run', raw => {raw.children[0].events[0].payload.parent_workflow_run_id = 'another-parent';}],
  ['changed original child argument type', raw => {raw.children[0].typed_input.value[0].value.number = {type: 'double', value: 42};}],
  ['stale previous child result', raw => {raw.events[7].decoded.result = raw.children[0].output;}],
  ['changed original child policy', raw => {raw.events[2].payload.parent_close_policy = 'terminate';}],
  ['child closure timestamp differs from committed run', raw => {raw.events[4].payload.closed_at = '2026-01-01T00:00:03Z';}],
  ['duplicate child terminal effect', raw => {raw.children[0].events.push(structuredClone(raw.children[0].events.at(-1)));}],
  ['missing nested activity completion', raw => {raw.children[0].events.splice(3, 1);}],
  ['nested activity stale attempt', raw => {raw.children[0].events[3].payload.activity_attempt_id = 'stale-attempt';}],
  ['child wrong queue', raw => {raw.children[0].task_queue = 'another-queue';}],
  ['parent resumes different child', raw => {raw.workflow_polls[1].child_workflow_run_id = raw.children[1].run_id;}],
  ['completion uses different owner', raw => {raw.workflow_completions[0].request.lease_owner = 'old-worker';}],
  ['completion uses different attempt', raw => {raw.workflow_completions[0].request.workflow_task_attempt = 2;}],
  ['worker receives stale parent history', raw => {raw.workflow_polls[1].history_events.pop();}],
  ['SDK changes scheduled child arguments', raw => {raw.workflow_completions[0].decoded_commands[0].decoded.arguments = ['changed'];}],
  ['SDK changes committed child result types', raw => {raw.workflow_completions[4].decoded_commands[0].typed_decoded.result = {type: 'null', value: null};}],
  ['SDK argument frame differs from original child input', raw => {raw.workflow_completions[0].request.commands[0].arguments = {codec: 'avro', blob: 'different-arguments-frame'};}],
  ['SDK result frame differs from durable child output', raw => {raw.workflow_completions[4].request.commands[0].result = {codec: 'avro', blob: 'different-result-frame'};}],
]) {
  test(`refuses ${name}`, () => {
    const raw = childObservation();
    corrupt(raw);
    assert.throws(() => checkObservation(childFixture, raw, 'test-one-activity'));
  });
}

function signalObservation(mode = 'http') {
  const raw = observation();
  raw.mode = mode;
  raw.workflow_type = signalFixture.workflow_type;
  raw.input = [signalFixture.input, signalFixture.signal_count];
  raw.output = signalFixture.signal_value;
  raw.typed_input = {type: 'list', value: [signalFixture.typed_value, {type: 'int64', value: '2'}]};
  raw.typed_output = signalFixture.typed_signal_value;
  raw.signal_deliveries = [];
  raw.events = raw.events.slice(0, 2);
  for (const event of raw.events) event.payload.workflow_type = signalFixture.workflow_type;
  raw.events[1].payload.declared_signals = ['payload'];
  for (let index = 0; index < signalFixture.signal_count; index++) {
    const opened = mode === 'embedded' ? {signal_wait_id: `wait-${index}`, signal_name: 'payload', sequence: index + 1}
      : {condition_wait_id: `wait-${index}`, condition_key: `payload:${index}`, condition_definition_fingerprint: `sha256:${'a'.repeat(64)}`, sequence: index + 1};
    const received = {workflow_instance_id: raw.workflow_id, workflow_run_id: raw.run_id, workflow_command_id: `signal-command-${index}`, signal_id: `signal-${index}`, signal_wait_id: mode === 'embedded' ? `wait-${index}` : `routing-wait-${index}`, signal_name: 'payload', payload_codec: 'avro', command: {id: `signal-command-${index}`, sequence: index + 2, message_sequence: index + 1}};
    raw.signal_deliveries.push({before: {status: 'waiting', run_id: raw.run_id}, response: {accepted: true, command_id: received.workflow_command_id, run_id: raw.run_id, workflow_id: raw.workflow_id, outcome: 'signal_received'}});
    raw.events.push(
      {event_type: mode === 'embedded' ? 'SignalWaitOpened' : 'ConditionWaitOpened', payload: opened, decoded: {}, typed_decoded: {}},
      {event_type: 'SignalReceived', payload: received, decoded: {arguments: [signalFixture.signal_value]}, typed_decoded: {arguments: {type: 'list', value: [signalFixture.typed_signal_value]}}},
      {event_type: 'MessageCursorAdvanced', payload: {stream_key: `instance:${raw.workflow_id}`, previous_position: index, new_position: index + 1}, decoded: {}, typed_decoded: {}},
      {event_type: 'SignalApplied', payload: {...received, ...(mode === 'embedded' ? {sequence: index + 1} : {})}, decoded: {value: signalFixture.signal_value}, typed_decoded: {value: signalFixture.typed_signal_value}},
      ...(mode === 'embedded' ? [] : [{event_type: 'ConditionWaitSatisfied', payload: {...opened, workflow_signal_id: received.signal_id, signal_name: received.signal_name, signal_wait_id: received.signal_wait_id}, decoded: {}, typed_decoded: {}}]),
    );
  }
  raw.events.push({event_type: 'WorkflowCompleted', payload: {}, decoded: {output: signalFixture.signal_value}, typed_decoded: {output: signalFixture.typed_signal_value}});
  raw.events = raw.events.map((event, index) => ({...event, sequence: index + 1, timestamp: '2026-01-01T00:00:01Z'}));
  return structuredClone(raw);
}

test('signal delivery/wait relationships agree across explicit service and embedded event shapes', () => {
  assert.deepStrictEqual(checkObservation(signalFixture, signalObservation(), 'test-one-activity'), checkObservation(signalFixture, signalObservation('embedded'), 'test-one-activity'));
});

const queryFixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/state-queries.json', import.meta.url)));
function queryObservation(mode = 'http') {
  const raw = signalObservation(mode);
  raw.workflow_type = queryFixture.workflow_type;
  for (const event of raw.events.slice(0, 2)) event.payload.workflow_type = queryFixture.workflow_type;
  raw.events[1].payload.declared_queries = ['state'];
  raw.queries = queryFixture.queries.map((expected, index) => {
    const end = index === 0 ? 3 : index === 1 ? (mode === 'http' ? 8 : 7) : raw.events.length;
    const history = structuredClone(raw.events.slice(0, end));
    return {name: 'state', arguments: queryFixture.query_arguments, typed_arguments: queryFixture.typed_query_arguments,
      before: {run_id: raw.run_id, status: expected.status}, after: {run_id: raw.run_id, status: expected.status},
      history_before: history, history_after: structuredClone(history),
      result: {request: queryFixture.query_arguments[0], delivered: expected.after_signal_count,
        last: index ? queryFixture.signal_value : null},
      typed_result: {type: 'map', value: {request: queryFixture.typed_query_arguments.value[0],
        delivered: {type: 'int64', value: String(index)}, last: index ? queryFixture.typed_signal_value : {type: 'null', value: null}}},
      ...(mode === 'http' ? {worker: {arguments: queryFixture.query_arguments, typed_arguments: queryFixture.typed_query_arguments,
        task: {query_task_id: `query-${index}`, query_task_attempt: 1, workflow_id: raw.workflow_id, run_id: raw.run_id,
          workflow_type: queryFixture.workflow_type, query_name: 'state', lease_owner: 'real-worker', query_arguments: {codec: 'avro'},
          history_events: history}}} : {})};
  });
  return structuredClone(raw);
}

test('waiting, signalled and completed queries preserve real state without history mutation', () => {
  assert.deepStrictEqual(checkObservation(queryFixture, queryObservation(), 'test-one-activity'),
    checkObservation(queryFixture, queryObservation('embedded'), 'test-one-activity'));
});

for (const [name, corrupt] of [
  ['missing completed query', raw => {raw.queries.pop();}],
  ['query alters durable history', raw => {raw.queries[0].history_after.push(structuredClone(raw.events[3]));}],
  ['query changes run status', raw => {raw.queries[0].after.status = 'running';}],
  ['query targets another run', raw => {raw.queries[0].before.run_id = 'different-run';}],
  ['query loses exact argument type', raw => {raw.queries[0].typed_arguments.value[0].value.number.type = 'double';}],
  ['query substitutes workflow result', raw => {raw.queries[1].result = raw.output;}],
  ['query returns stale state', raw => {raw.queries[1].result.delivered = 0;}],
  ['query changes last applied value', raw => {raw.queries[1].result.last.count++;}],
  ['query loses exact result type', raw => {raw.queries[2].typed_result.value.request.value.number.type = 'double';}],
  ['query worker observes another run', raw => {raw.queries[0].worker.task.run_id = 'different-run';}],
  ['query worker loses argument type', raw => {raw.queries[0].worker.typed_arguments.value[0].value.number.type = 'double';}],
  ['query task identity is reused', raw => {raw.queries[1].worker.task.query_task_id = raw.queries[0].worker.task.query_task_id;}],
  ['query worker receives stale history', raw => {raw.queries[1].worker.task.history_events = raw.queries[0].history_before;}],
]) {
  test(`refuses ${name}`, () => {
    const raw = queryObservation();
    corrupt(raw);
    assert.throws(() => checkObservation(queryFixture, raw, 'test-one-activity'));
  });
}
for (const [name, corrupt] of [
  ['deduplicated repeated signal', raw => {raw.events.splice(7, 5);}],
  ['reused signal identity', raw => {raw.events[8].payload.signal_id = raw.events[3].payload.signal_id;}],
  ['reused signal command', raw => {raw.events[8].payload.workflow_command_id = raw.events[3].payload.workflow_command_id;}],
  ['wrong signal run', raw => {raw.events[3].payload.workflow_run_id = 'other-run';}],
  ['changed signal value', raw => {raw.events[3].decoded.arguments = [signalFixture.input];}],
  ['changed signal type', raw => {raw.events[3].typed_decoded.arguments.value[0].value.count.type = 'double';}],
  ['wrong acknowledged command', raw => {raw.signal_deliveries[0].response.command_id = 'other';}],
  ['signal sent before durable wait', raw => {raw.signal_deliveries[0].before.status = 'running';}],
  ['changed condition fingerprint', raw => {raw.events[6].payload.condition_definition_fingerprint = `sha256:${'b'.repeat(64)}`;}],
  ['wrong condition signal', raw => {raw.events[6].payload.workflow_signal_id = 'other';}],
  ['workflow input substituted for signal result', raw => {raw.output = signalFixture.input;}],
  ['missing service SignalApplied', raw => {raw.events.splice(5, 1);}],
  ['lost message cursor progress', raw => {raw.events[4].payload.new_position = 0;}],
  ['message order changed', raw => {raw.events[3].payload.command.message_sequence = 2;}],
]) {
  test(`refuses ${name}`, () => {
    const raw = signalObservation();
    corrupt(raw);
    assert.throws(() => checkObservation(signalFixture, raw, 'test-one-activity'));
  });
}

test('a saved pass and copied projection cannot hide corrupted raw history', () => {
  const changed = record('-other');
  changed.cases[0].observation.events[4].payload.activity_attempt_id = 'stale';
  assert.throws(() => compareRecords([record(), changed]));
});

test('a one-unit int64 error beyond JSON precision remains observable', () => {
  const large = structuredClone(fixture);
  large.input.count = Number('9007199254740993');
  large.typed_value.value.count.value = '9007199254740993';
  const raw = observation();
  raw.input = [large.input];
  raw.output = large.input;
  raw.typed_input = {type: 'list', value: [large.typed_value]};
  raw.typed_output = structuredClone(large.typed_value);
  raw.typed_output.value.count.value = '9007199254740992';
  assert.equal(Number('9007199254740993'), Number('9007199254740992'));
  assert.throws(() => checkObservation(large, raw, 'test-one-activity'), /decoded workflow result types and exact int64 values/);
});

test('refuses partial inventories, blocked records and changed fixtures or consumers', () => {
  for (const corrupt of [
    value => {value.cases = [];},
    value => {value.outcome = 'runner-blocked';},
    value => {value.fixture_hashes['one-activity.json'] = 'different';},
    value => {value.artifacts.sdk_php = 'different';},
    value => {value.runner_revision = 'b'.repeat(40);},
    value => {value.cases[0].observation.sdk_php = 'wrong-installed-version';},
  ]) {
    const changed = record();
    corrupt(changed);
    assert.throws(() => compareRecords([record(), changed]));
  }
});

test('two empty recordings cannot establish parity', () => {
  const empty = record();
  empty.cases = [];
  empty.fixture_hashes = {};
  assert.throws(() => compareRecords([empty, structuredClone(empty)]));
});

test('two matching subsets cannot omit a required current fixture', () => {
  assert.throws(() => compareCorpusRecords([record(), record('-other')], {'one-activity.json': 'fixture-sha', 'echo.json': 'required-fixture-sha'}, reviewed));
});

test('matching recordings cannot omit hashed fixtures or repeat one case', () => {
  for (const corrupt of [
    value => {value.fixture_hashes['echo.json'] = 'missing-case-sha';},
    value => {value.cases.push(structuredClone(value.cases[0]));},
  ]) {
    const changed = record();
    corrupt(changed);
    assert.throws(() => compareRecords([changed, structuredClone(changed)]));
  }
});

test('matching forged expectations and pass labels cannot replace reviewed source fixtures', () => {
  const records = [structuredClone(record()), structuredClone(record('-other'))];
  for (const value of records) {
    const item = value.cases[0];
    item.fixture.expected_events[2] = 'UnreviewedReplacement';
    item.observation.events[2].event_type = 'UnreviewedReplacement';
    item.projection = checkObservation(item.fixture, item.observation, 'test-one-activity');
  }
  assert.throws(() => compareRecords(records), /current reviewed source fixture/);
});

const timerFixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/two-timers.json', import.meta.url)));
function timerObservation() {
  const raw = observation();
  raw.workflow_type = timerFixture.workflow_type;
  raw.input = [timerFixture.input];
  raw.output = timerFixture.input;
  raw.typed_input = {type: 'list', value: [timerFixture.typed_value]};
  raw.typed_output = timerFixture.typed_value;
  raw.events = [raw.events[0], raw.events[1], ...timerFixture.timer_delays.flatMap((delay, index) => {
    const scheduled = {timer_id: `timer-${index}`, sequence: index + 1, delay_seconds: delay, fire_at: `2026-01-01T00:00:0${index + delay + 2}Z`};
    return [
      {payload: scheduled, timestamp: `2026-01-01T00:00:0${index + 2}Z`},
      {payload: {...scheduled, fired_at: scheduled.fire_at}, timestamp: scheduled.fire_at},
    ].map(event => ({...event, decoded: {}, typed_decoded: {}}));
  }), {payload: {}, decoded: {output: timerFixture.input}, typed_decoded: {output: timerFixture.typed_value}, timestamp: '2026-01-01T00:00:05Z'}]
    .map((event, index) => ({...event, event_type: timerFixture.expected_events[index], sequence: index + 1}));
  for (const event of raw.events.slice(0, 2)) event.payload.workflow_type = timerFixture.workflow_type;
  return raw;
}

test('zero and delayed timers retain distinct identities and command sequences', () => {
  const checked = checkObservation(timerFixture, timerObservation(), 'test-one-activity');
  assert.equal(checked.events[2].timer_id, '@timer:1');
  assert.equal(checked.events[4].timer_id, '@timer:2');
});
test('explicit zero-delay event shape retains scheduled deadline authority', () => {
  const raw = timerObservation();
  delete raw.events[3].payload.fire_at;
  checkObservation(timerFixture, raw, 'test-one-activity');
  const strict = {...timerFixture, zero_delay_fired_fire_at_optional: false};
  assert.throws(() => checkObservation(strict, raw, 'test-one-activity'));
});
for (const [name, corrupt] of [
  ['early timer firing', raw => {raw.events[5].payload.fired_at = '2026-01-01T00:00:03.999Z';}],
  ['microsecond-early timer firing', raw => {
    raw.events[4].payload.fire_at = raw.events[5].payload.fire_at = '2026-01-01T00:00:04.123456Z';
    raw.events[5].payload.fired_at = '2026-01-01T00:00:04.123455Z';
  }],
  ['changed timer identity', raw => {raw.events[3].payload.timer_id = 'other';}],
  ['reused timer identity', raw => {raw.events[4].payload.timer_id = raw.events[2].payload.timer_id;}],
  ['extended timer deadline', raw => {raw.events[5].payload.fire_at = '2026-01-01T00:00:06Z';}],
  ['missing positive-delay deadline', raw => {delete raw.events[5].payload.fire_at;}],
  ['changed timer command sequence', raw => {raw.events[4].payload.sequence = 1;}],
  ['changed timer delay', raw => {raw.events[5].payload.delay_seconds = 2;}],
  ['duplicate timer firing', raw => {raw.events.splice(4, 0, structuredClone(raw.events[3]));}],
]) {
  test(`refuses ${name}`, () => {
    const raw = timerObservation();
    corrupt(raw);
    assert.throws(() => checkObservation(timerFixture, raw, 'test-one-activity'));
  });
}

const updateFixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/state-updates.json', import.meta.url)));
function updateObservation(mode = 'http') {
  const signal = signalObservation(mode);
  const raw = structuredClone(signal);
  raw.workflow_type = updateFixture.workflow_type;
  raw.input = [updateFixture.input, 1];
  raw.typed_input = {type: 'list', value: [updateFixture.typed_value, {type: 'int64', value: '1'}]};
  raw.output = updateFixture.output;
  raw.typed_output = updateFixture.typed_output;
  raw.events = signal.events.slice(0, 3);
  raw.events[0].payload.workflow_type = raw.events[1].payload.workflow_type = updateFixture.workflow_type;
  raw.events[1].payload.declared_updates = ['set_payload'];
  raw.updates = [];
  for (const [index, value] of updateFixture.update_values.entries()) {
    const arguments_ = {type: 'list', value: [updateFixture.typed_update_values[index]]};
    const fields = {update_id: `update-${index}`, workflow_command_id: `update-command-${index}`,
      workflow_instance_id: raw.workflow_id, workflow_run_id: raw.run_id, update_name: 'set_payload'};
    raw.events.push({event_type: 'UpdateAccepted', payload: {...fields, command: {id: fields.workflow_command_id, sequence: index + 2, message_sequence: index + 1}},
      decoded: {arguments: [value]}, typed_decoded: {arguments: arguments_}});
    const snapshot = structuredClone(raw.events);
    const result = {value, applied: index + 1};
    const typedResult = {type: 'map', value: {value: updateFixture.typed_update_values[index], applied: {type: 'int64', value: String(index + 1)}}};
    const sequence = mode === 'embedded' ? 1 : index + 2;
    raw.events.push(
      {event_type: 'UpdateApplied', payload: {...fields, sequence}, decoded: {arguments: [value]}, typed_decoded: {arguments: arguments_}},
      {event_type: 'UpdateCompleted', payload: {...fields, sequence}, decoded: {result}, typed_decoded: {result: typedResult}},
      {event_type: 'MessageCursorAdvanced', payload: {stream_key: `instance:${raw.workflow_id}`, previous_position: index, new_position: index + 1}, decoded: {}, typed_decoded: {}},
    );
    const receipt = {accepted: true, command_id: fields.workflow_command_id, update_id: fields.update_id,
      workflow_id: raw.workflow_id, run_id: raw.run_id, update_name: 'set_payload', update_status: 'accepted'};
    raw.updates.push({name: 'set_payload', request_id: `${raw.workflow_id}:update:${index}`,
      arguments: [value], typed_arguments: arguments_, before: {status: 'waiting', run_id: raw.run_id},
      accepted: receipt, duplicate_accepted: structuredClone(receipt),
      history_after_acceptance: snapshot, history_after_duplicate: structuredClone(snapshot),
      history_before_result: structuredClone(raw.events), history_after_result: structuredClone(raw.events),
      result, typed_result: typedResult,
      ...(mode === 'http' ? {worker: {arguments: [value], typed_arguments: arguments_, task: {
        task_id: `update-task-${index}`, workflow_update_id: fields.update_id,
        workflow_id: raw.workflow_id, run_id: raw.run_id, workflow_type: raw.workflow_type,
        workflow_task_attempt: 1, lease_owner: 'actual-worker', history_events: snapshot}}} : {})});
  }
  const received = structuredClone(signal.events[3]);
  received.payload.command.sequence = 4;
  received.payload.command.message_sequence = 3;
  received.decoded.arguments = [updateFixture.signal_value];
  received.typed_decoded.arguments = {type: 'list', value: [updateFixture.typed_signal_value]};
  const applied = structuredClone(signal.events[5]);
  applied.decoded.value = updateFixture.signal_value;
  applied.typed_decoded.value = updateFixture.typed_signal_value;
  raw.signal_deliveries = [signal.signal_deliveries[0]];
  raw.events.push(received,
    {event_type: 'MessageCursorAdvanced', payload: {stream_key: `instance:${raw.workflow_id}`, previous_position: 2, new_position: 3}, decoded: {}, typed_decoded: {}},
    applied,
    ...(mode === 'http' ? [signal.events[6]] : []),
    {event_type: 'WorkflowCompleted', payload: {}, decoded: {output: updateFixture.output}, typed_decoded: {output: updateFixture.typed_output}},
  );
  raw.events = raw.events.map((event, index) => ({...event, sequence: index + 1, timestamp: '2026-01-01T00:00:01Z'}));
  return structuredClone(raw);
}

test('updates retain original identities/results through duplicates and mutate workflow state in order', () => {
  assert.deepStrictEqual(checkObservation(updateFixture, updateObservation(), 'test-one-activity'),
    checkObservation(updateFixture, updateObservation('embedded'), 'test-one-activity'));
});
for (const [name, corrupt] of [
  ['missing update result', raw => {raw.updates.pop();}],
  ['duplicate update changes its command', raw => {raw.updates[0].duplicate_accepted.command_id = 'other';}],
  ['duplicate update changes its identity', raw => {raw.updates[0].duplicate_accepted.update_id = 'other';}],
  ['pending duplicate appends history', raw => {raw.updates[0].history_after_duplicate.push(raw.events[3]);}],
  ['completed duplicate appends history', raw => {raw.updates[0].history_after_result.push(raw.events[3]);}],
  ['update result ignores state', raw => {raw.updates[1].result.applied = 1;}],
  ['update replaces exact int64 argument', raw => {raw.updates[0].typed_arguments.value[0].value.number.type = 'double';}],
  ['update replaces exact int64 result', raw => {raw.updates[0].typed_result.value.value.value.number.type = 'double';}],
  ['update history targets another run', raw => {raw.events[4].payload.workflow_run_id = 'other';}],
  ['update completion loses original identity', raw => {raw.events[5].payload.update_id = 'other';}],
  ['update completion changes callback position', raw => {raw.events[5].payload.sequence++;}],
  ['update advances cursor twice', raw => {raw.events[6].payload.new_position++;}],
  ['update worker executes another update', raw => {raw.updates[0].worker.task.workflow_update_id = 'other';}],
  ['update worker sees stale state', raw => {raw.updates[1].worker.task.history_events = raw.updates[0].worker.task.history_events;}],
  ['finishing signal loses update cursor order', raw => {raw.events[12].payload.previous_position = 0;}],
  ['workflow forgets first update', raw => {raw.output.values.shift();}],
]) {
  test(`refuses ${name}`, () => {
    const raw = updateObservation();
    corrupt(raw);
    assert.throws(() => checkObservation(updateFixture, raw, 'test-one-activity'));
  });
}
