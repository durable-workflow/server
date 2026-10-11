import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkPublishedSdkPackages} from './sdk-direction-contract.mjs';

const artifacts = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/ServerParityProfiles/published-sdk-timers/artifacts.json', import.meta.url), 'utf8'));
const phases = ['original', 'replacement'];
const languages = ['php', 'python', 'rust'];
const history = events => events.map(({sequence, event_type, payload}) => ({sequence, event_type, payload}));
const nonempty = value => assert(typeof value === 'string' && value.length > 0);
const frame = value => {
  if (typeof value === 'string') { nonempty(value); return value; }
  assert.equal(value?.codec, 'avro');
  nonempty(value?.blob);
  return value.blob;
};

export function checkSdkTimerAuthorArtifacts(fixture, selected) {
  if (!fixture.sdk_timer_author) return;
  assert.deepEqual(selected, artifacts, 'complete reviewed published SDK timer tuple');
  const spec = fixture.sdk_timer_author;
  assert(languages.includes(spec.language));
  assert.deepEqual(spec, {language: spec.language, phase_order: phases,
    task_queue: 'server-parity-sdk-timer', start_before_worker_registration: true,
    requested_delay_ms: 5250, rounded_delay_seconds: 6});
  assert.equal(fixture.id, 'timer-sdk-' + spec.language);
  assert.equal(fixture.workflow_type, 'parity.v1.sdk_timer');
  assert.equal(fixture.activity, false);
  assert.equal(fixture.input.sleep_ms, spec.requested_delay_ms);
  assert.deepEqual(fixture.timer_delays, [spec.rounded_delay_seconds]);
  assert.equal(fixture.typed_value.value.sleep_ms.value, String(spec.requested_delay_ms));
}

export function checkSdkTimerAuthorPackages(fixture, observation, selected) {
  if (!fixture.sdk_timer_author) return;
  checkSdkTimerAuthorArtifacts(fixture, selected);
  checkPublishedSdkPackages(observation, selected, observation.sdk_timer_author);
}

export function checkSdkTimerAuthor(fixture, observation, workflowId) {
  const spec = fixture.sdk_timer_author;
  if (!spec) return null;
  checkSdkTimerAuthorArtifacts(fixture, artifacts);
  const author = observation.sdk_timer_author;
  const projection = {one_original_timer: true, rounded_delay_seconds: 6, exact_typed_completion: true};
  if (observation.mode === 'embedded') {
    assert.deepEqual(author, {applicable: false, reason: 'embedded_executes_php_author_definitions'});
    return projection;
  }
  assert.equal(author.applicable, true);
  assert.deepEqual(author.phases.map(phase => phase.phase), phases);
  assert.equal(new Set(author.phases.map(phase => phase.pid)).size, 2, 'distinct actual original and cold replay processes');
  const claims = [], completions = [];
  for (const [index, phase] of author.phases.entries()) {
    assert.equal(phase.language, spec.language);
    assert.equal(phase.sdk_version, artifacts['sdk_' + spec.language]);
    assert(Number.isInteger(phase.pid) && phase.pid > 0);
    assert.equal(phase.worker_finished, true);
    assert.equal(phase.worker_error, undefined);
    assert.equal(phase.run_id, observation.run_id, 'same original run in both processes');
    assert.equal(phase.worker_id, workflowId + ':' + phase.phase);
    assert.equal(phase.task_queue, spec.task_queue);
    assert.equal(phase.workflow_invocations, 1);
    assert.equal(phase.activity_invocations, 0);
    const snapshot = phase.snapshot;
    for (const key of ['workflow_id', 'run_id', 'workflow_type', 'namespace', 'task_queue', 'payload_codec']) {
      assert.equal(snapshot[key], observation[key], 'original run ' + key + ' at both checkpoints');
    }
    assert.equal(snapshot.status, index === 0 ? 'waiting' : 'completed');
    assert.equal(snapshot.events.length, index === 0 ? 3 : 5);
    assert.deepEqual(snapshot.events, observation.events.slice(0, snapshot.events.length), 'full unchanged original timer history prefix');
    assert.deepEqual(snapshot.typed_input, observation.typed_input);
    assert.deepEqual(snapshot.typed_output, index === 0 ? {type: 'null', value: null} : fixture.typed_value);
    const registrations = phase.requests.filter(item => item.method === 'POST' && item.path === '/api/worker/register');
    assert.equal(registrations.length, 1);
    const registration = registrations[0];
    assert.equal(registration.status, 201);
    assert.equal(registration.response.registered, true);
    assert.equal(registration.request.worker_id, phase.worker_id);
    assert.equal(registration.request.task_queue, spec.task_queue);
    assert.deepEqual(registration.request.supported_workflow_types, [fixture.workflow_type]);
    assert.deepEqual(registration.request.supported_activity_types, []);
    const polls = phase.requests.filter(item => item.path === '/api/worker/workflow-tasks/poll' && item.response?.task);
    assert.equal(polls.length, 1, 'one actual claimed workflow task per process');
    assert.equal(polls[0].method, 'POST');
    assert.equal(polls[0].status, 200);
    assert.equal(polls[0].request.task_queue, spec.task_queue);
    const task = polls[0].response.task;
    assert.equal(task.workflow_id, workflowId);
    assert.equal(task.run_id, observation.run_id);
    assert.equal(task.workflow_type, fixture.workflow_type);
    assert.equal(task.task_queue, spec.task_queue);
    assert.equal(task.workflow_task_attempt, 1);
    nonempty(task.lease_owner);
    nonempty(task.task_id);
    assert.deepEqual(history(task.history_events), history(observation.events.slice(0, index === 0 ? 2 : 4)),
      'cold SDK receives the actual original timer scheduling and firing history');
    claims.push(task);
    const outcomes = phase.requests.filter(item => item.method === 'POST'
      && /^\/api\/worker\/workflow-tasks\/[^/]+\/complete$/.test(item.path));
    assert.equal(outcomes.length, 1, 'one committed scheduling or completion receipt');
    const outcome = outcomes[0];
    assert.equal(outcome.status, 200);
    assert.equal(outcome.response.recorded, true);
    assert.equal(outcome.response.task_id, task.task_id);
    assert.equal(outcome.response.run_id, observation.run_id);
    assert.equal(outcome.path, '/api/worker/workflow-tasks/' + task.task_id + '/complete');
    assert.equal(outcome.request.lease_owner, task.lease_owner);
    assert.equal(outcome.request.workflow_task_attempt, task.workflow_task_attempt);
    assert.equal(outcome.request.commands.length, 1);
    const command = outcome.request.commands[0];
    assert.equal(command.type, index === 0 ? 'start_timer' : 'complete_workflow');
    if (index === 0) assert.equal(command.delay_seconds, spec.rounded_delay_seconds);
    completions.push(outcome);
    assert(!phase.requests.some(item => item.path === '/api/worker/activity-tasks/poll' && item.response?.task), 'no activity claimed');
  }
  assert.equal(new Set(claims.map(task => task.task_id)).size, 2, 'original and resumed durable workflow tasks are distinct');
  assert.deepEqual(observation.workflow_polls, claims, 'all recorded claims are actual phase claims');
  assert.equal(observation.workflow_completions.length, 2);
  for (const [index, completion] of observation.workflow_completions.entries()) {
    for (const key of ['method', 'path', 'status', 'request', 'response']) {
      assert.deepEqual(completion[key], completions[index][key], 'decoded completion retains actual receipt ' + key);
    }
    assert.equal(completion.decoded_commands.length, 1);
    if (index === 0) assert.deepEqual(completion.decoded_commands[0], {decoded: {}, typed_decoded: {}});
    else {
      assert.equal(frame(completion.request.commands[0].result), frame(observation.execution.output_envelope), 'SDK result frame is durably preserved');
      assert.deepEqual(completion.decoded_commands[0].decoded.result, fixture.input);
      assert.deepEqual(completion.decoded_commands[0].typed_decoded.result, fixture.typed_value, 'committed exact int64, Unicode, null and duration');
    }
  }
  const [original] = author.phases;
  const starts = author.phases.flatMap(phase => phase.requests.filter(item => item.method === 'POST' && item.path === '/api/workflows'));
  assert.equal(starts.length, 1, 'only the original run is client-started');
  assert.equal(starts[0].status, 201);
  assert.equal(starts[0].request.workflow_id, workflowId);
  assert.equal(starts[0].request.workflow_type, fixture.workflow_type);
  assert.equal(starts[0].request.task_queue, spec.task_queue);
  assert.equal(starts[0].response.run_id, observation.run_id);
  assert(original.requests.indexOf(starts[0]) < original.requests.findIndex(item => item.path === '/api/worker/register'));
  assert.equal(original.snapshot.events[1].payload.workflow_definition_fingerprint == null, true);
  assert.notEqual(original.snapshot.events[1].payload.workflow_definition_fingerprint_source, 'worker');
  return projection;
}
