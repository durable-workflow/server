import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkObservation} from '../../scripts/conformance/server-parity/contract.mjs';
import {checkSdkTimerAuthorArtifacts, checkSdkTimerAuthorPackages} from '../../scripts/conformance/server-parity/sdk-timer-author-contract.mjs';

const profile = new URL('../Fixtures/ServerParityProfiles/published-sdk-timers/', import.meta.url);
const artifacts = JSON.parse(readFileSync(new URL('artifacts.json', profile), 'utf8'));
const fixtureFor = language => JSON.parse(readFileSync(new URL('timer-sdk-' + language + '.json', profile), 'utf8'));
const copy = value => structuredClone(value);
const workflowId = 'timer-original-workflow';
const time = '2026-01-01T00:00:00.123456Z';
const fireAt = '2026-01-01T00:00:06.123456Z';
const nil = {type: 'null', value: null};

function observed(fixture, mode = 'http') {
  const raw = {mode, workflow_id: workflowId, run_id: 'original-timer-run',
    workflow_type: fixture.workflow_type, namespace: 'default', task_queue: fixture.sdk_timer_author.task_queue,
    status: 'completed', payload_codec: 'avro', input: [fixture.input], typed_input: {type: 'list', value: [fixture.typed_value]},
    output: fixture.input, typed_output: fixture.typed_value,
    execution: {started_at: time, output_envelope: {codec: 'avro', blob: 'original-output-frame'}},
    sdk_php: artifacts.sdk_php, sdk_php_source: artifacts.sdk_php_source_commit,
    sdk_composer_lock_sha256: artifacts.sdk_php_composer_lock_sha256,
    sdk_loaded_sources: copy(artifacts.sdk_php_loaded_sources)};
  const start = {workflow_instance_id: workflowId, workflow_run_id: raw.run_id,
    workflow_command_id: 'original-start-command', workflow_type: fixture.workflow_type};
  const timer = {timer_id: 'original-timer', sequence: 1, delay_seconds: 6, fire_at: fireAt};
  const event = (event_type, payload, timestamp = time, decoded = {}, typed_decoded = {}) =>
    ({event_type, payload, timestamp, decoded, typed_decoded});
  raw.events = [
    event('StartAccepted', {...start, outcome: 'started_new'}),
    event('WorkflowStarted', {...start, execution_timeout_seconds: 3600, run_timeout_seconds: 600,
      execution_deadline_at: '2026-01-01T01:00:00.123456Z', run_deadline_at: '2026-01-01T00:10:00.123456Z'}),
    event('TimerScheduled', {...timer}),
    event('TimerFired', {...timer, fired_at: '2026-01-01T00:00:06.123457Z'}, fireAt),
    event('WorkflowCompleted', {}, '2026-01-01T00:00:06.200000Z', {output: raw.output}, {output: raw.typed_output}),
  ];
  raw.events.forEach((event, index) => {event.sequence = index + 1;});
  if (mode === 'embedded') {
    Object.assign(raw, {workflow_package: artifacts.workflow, workflow_source: artifacts.embedded_image_path_reference,
      embedded_composer_lock_sha256: artifacts.embedded_image_composer_lock_sha256,
      workflow_loaded_sources: copy(artifacts.workflow_loaded_sources),
      sdk_timer_author: {applicable: false, reason: 'embedded_executes_php_author_definitions'}});
    return copy(raw);
  }
  raw.workflow_polls = [];
  raw.workflow_completions = [];
  const recordedPhases = [];
  for (const [index, phase] of ['original', 'replacement'].entries()) {
    const workerId = workflowId + ':' + phase;
    const task = {task_id: 'task-' + phase, lease_owner: workerId, workflow_task_attempt: 1,
      workflow_id: workflowId, run_id: raw.run_id, workflow_type: raw.workflow_type, task_queue: raw.task_queue,
      history_events: copy(raw.events.slice(0, index === 0 ? 2 : 4))};
    const command = index === 0 ? {type: 'start_timer', delay_seconds: 6}
      : {type: 'complete_workflow', result: raw.execution.output_envelope};
    const request = (path, body, response, status = 200) => ({method: 'POST', path, status, request: body, response});
    const requests = [];
    if (index === 0) requests.push(request('/api/workflows', {workflow_id: workflowId,
      workflow_type: raw.workflow_type, task_queue: raw.task_queue}, {run_id: raw.run_id}, 201));
    requests.push(request('/api/worker/register', {worker_id: workerId, task_queue: raw.task_queue,
      supported_workflow_types: [raw.workflow_type], supported_activity_types: []}, {registered: true}, 201));
    requests.push(request('/api/worker/workflow-tasks/poll', {task_queue: raw.task_queue}, {task}));
    const completion = request('/api/worker/workflow-tasks/' + task.task_id + '/complete',
      {lease_owner: task.lease_owner, workflow_task_attempt: 1, commands: [command]},
      {recorded: true, task_id: task.task_id, run_id: raw.run_id});
    requests.push(completion);
    raw.workflow_polls.push(task);
    raw.workflow_completions.push({...completion, decoded_commands: [index === 0
      ? {decoded: {}, typed_decoded: {}}
      : {decoded: {result: raw.output}, typed_decoded: {result: raw.typed_output}}]});
    const snapshot = {...raw, status: index === 0 ? 'waiting' : 'completed',
      events: raw.events.slice(0, index === 0 ? 3 : 5), typed_output: index === 0 ? nil : raw.typed_output};
    recordedPhases.push({phase, language: fixture.sdk_timer_author.language,
      sdk_version: artifacts['sdk_' + fixture.sdk_timer_author.language], pid: 201 + index,
      worker_finished: true, run_id: raw.run_id, worker_id: workerId, task_queue: raw.task_queue,
      workflow_invocations: 1, activity_invocations: 0, requests, snapshot});
  }
  raw.sdk_timer_author = {applicable: true, phases: recordedPhases, packages: {outcome: 'pass',
    rust: copy(artifacts.published_sdk_artifacts.rust), python: copy(artifacts.published_sdk_artifacts.python)}};
  return copy(raw);
}

for (const language of ['php', 'python', 'rust']) for (const mode of ['http', 'embedded']) {
  test('check timer author guard model ' + language + ' ' + mode, () => {
    const fixture = fixtureFor(language), value = observed(fixture, mode);
    checkSdkTimerAuthorPackages(fixture, value, artifacts);
    assert.deepEqual(checkObservation(fixture, value, workflowId).sdk_timer_author,
      {one_original_timer: true, rounded_delay_seconds: 6, exact_typed_completion: true});
  });
}
const fixture = fixtureFor('python');
const replacement = value => value.sdk_timer_author.phases[1];
const completion = value => replacement(value).requests.at(-1);
const mutations = {
  'same process replay': value => {replacement(value).pid = value.sdk_timer_author.phases[0].pid;},
  'missing cold process': value => {value.sdk_timer_author.phases.pop();},
  'wrong author language': value => {replacement(value).language = 'rust';},
  'wrong installed version': value => {replacement(value).sdk_version = '0.0.0';},
  'unfinished SDK': value => {replacement(value).worker_finished = false;},
  'second run': value => {replacement(value).run_id = 'replacement-run';},
  'second workflow callback': value => {replacement(value).workflow_invocations = 2;},
  'activity callback': value => {replacement(value).activity_invocations = 1;},
  'wrong checkpoint queue': value => {replacement(value).snapshot.task_queue = 'other';},
  'wrong checkpoint namespace': value => {replacement(value).snapshot.namespace = 'other';},
  'original not pending': value => {value.sdk_timer_author.phases[0].snapshot.status = 'completed';},
  'original timer missing': value => {value.sdk_timer_author.phases[0].snapshot.events.pop();},
  'cold timer identity changed': value => {replacement(value).requests[1].response.task.history_events[2].payload.timer_id = 'other';},
  'cold deadline reset': value => {replacement(value).requests[1].response.task.history_events[2].payload.fire_at = '2026-01-01T00:00:12Z';},
  'cold history truncated': value => {replacement(value).requests[1].response.task.history_events.pop();},
  'wrong claimed run': value => {replacement(value).requests[1].response.task.run_id = 'other';},
  'wrong claimed queue': value => {replacement(value).requests[1].response.task.task_queue = 'other';},
  'registration refused': value => {replacement(value).requests[0].response.registered = false;},
  'extra activity type': value => {replacement(value).requests[0].request.supported_activity_types = ['activity'];},
  'completion refused': value => {completion(value).response.recorded = false;},
  'stale lease': value => {completion(value).request.lease_owner = 'stale';},
  'stale attempt': value => {completion(value).request.workflow_task_attempt = 2;},
  'wrong task receipt': value => {completion(value).response.task_id = 'other';},
  'timer rescheduled': value => {completion(value).request.commands[0].type = 'start_timer';},
  'timer truncated to five': value => {value.sdk_timer_author.phases[0].requests.at(-1).request.commands[0].delay_seconds = 5;},
  'unbound completion copy': value => {value.workflow_completions[1].response = {...value.workflow_completions[1].response, recorded: false};},
  'result frame replaced': value => {value.execution.output_envelope = {codec: 'avro', blob: 'other'};},
  'int64 result rounded': value => {value.workflow_completions[1].decoded_commands[0].typed_decoded.result.value.count.value = '9007199254740992';},
  'new start before cold replay': value => {replacement(value).requests.push(copy(value.sdk_timer_author.phases[0].requests[0]));},
  'original start reused': value => {value.sdk_timer_author.phases[0].requests[0].status = 200;},
  'start after registration': value => {value.sdk_timer_author.phases[0].requests.reverse();},
};
for (const [name, mutate] of Object.entries(mutations)) test('refuse timer author ' + name, () => {
  const value = observed(fixture); mutate(value);
  assert.throws(() => checkObservation(fixture, value, workflowId));
});
test('persisted original deadline refuses one nanosecond early firing', () => {
  const value = observed(fixture, 'embedded');
  value.events[3].payload.fired_at = '2026-01-01T00:00:06.123455999Z';
  assert.throws(() => checkObservation(fixture, value, workflowId), /must not fire early/);
});
test('timer package guard rejects source, dependency and tuple substitutions', () => {
  for (const mutate of [
    value => {value.sdk_php_source = 'a'.repeat(40);},
    value => {value.sdk_composer_lock_sha256 = 'b'.repeat(64);},
    value => {value.sdk_timer_author.packages.python.archive_sha256 = 'c'.repeat(64);},
  ]) {
    const value = observed(fixture); mutate(value);
    assert.throws(() => checkSdkTimerAuthorPackages(fixture, value, artifacts));
  }
  const altered = copy(artifacts); altered.server.source_commit = 'd'.repeat(40);
  assert.throws(() => checkSdkTimerAuthorArtifacts(fixture, altered));
  const alteredFixture = copy(fixture); alteredFixture.input.sleep_ms = 5000;
  assert.throws(() => checkSdkTimerAuthorArtifacts(alteredFixture, artifacts));
});
