import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkSdkDirection, checkSdkDirectionArtifacts, checkSdkDirectionPackages} from '../../scripts/conformance/server-parity/sdk-direction-contract.mjs';

const profile = new URL('../Fixtures/ServerParityProfiles/published-sdk-directions/', import.meta.url);
const artifacts = JSON.parse(readFileSync(new URL('artifacts.json', profile), 'utf8'));
const copy = value => structuredClone(value);
const workflowId = 'sdk-direction-reader-original';
const runId = 'run-original';

function observed(fixture) {
  const events = fixture.expected_events.map((event_type, index) => ({
    event_type, sequence: index + 1, timestamp: '2026-01-01T00:00:00.123456Z',
    payload: {workflow_run_id: runId, retained_identity: 'event-' + index,
      ...(index === 1 ? {workflow_definition_fingerprint: null, workflow_definition_fingerprint_source: 'type'} : {})},
  }));
  const typedInput = {type: 'list', value: [fixture.typed_value]};
  const observation = {mode: 'http', run_id: runId, events, typed_input: typedInput,
    sdk_php_source: artifacts.sdk_php_source_commit,
    sdk_composer_lock_sha256: artifacts.sdk_php_composer_lock_sha256,
    sdk_loaded_sources: copy(artifacts.sdk_php_loaded_sources),
    sdk_direction: {applicable: true, packages: {outcome: 'pass',
      rust: copy(artifacts.published_sdk_artifacts.rust), python: copy(artifacts.published_sdk_artifacts.python)}, phases: []}};
  for (const [index, phase] of ['original', 'activity', 'replacement'].entries()) {
    const language = fixture.sdk_direction[index === 1 ? 'activity' : 'workflow'];
    const taskQueue = index === 1 ? 'server-parity-sdk-activity' : 'server-parity-sdk-workflow';
    const kind = index === 1 ? 'activity' : 'workflow';
    const workerId = workflowId + ':' + phase;
    const request = (path, body, response) => ({method: 'POST', path, status: 200, request: body, response});
    const requests = [];
    if (index === 0) requests.push(request('/api/workflows', {workflow_id: workflowId}, {run_id: runId}));
    requests.push(request('/api/worker/register', {worker_id: workerId, task_queue: taskQueue,
      supported_workflow_types: index === 1 ? [] : [fixture.workflow_type],
      supported_activity_types: index === 1 ? ['parity.v1.echo_activity'] : []}, {}));
    requests.push(request('/api/worker/' + kind + '-tasks/poll', {}, {task: {task_id: 'task-' + phase}}));
    const command = index === 0 ? {type: 'schedule_activity', activity_type: 'parity.v1.echo_activity', queue: 'server-parity-sdk-activity'}
      : {type: 'complete_workflow'};
    requests.push(request('/api/worker/' + kind + '-tasks/task-' + phase + '/complete',
      index === 1 ? {} : {commands: [command]}, {recorded: true}));
    observation.sdk_direction.phases.push({phase, language, sdk_version: artifacts['sdk_' + language],
      pid: index + 100, worker_finished: true, run_id: runId, worker_id: workerId, task_queue: taskQueue,
      workflow_invocations: index === 1 ? 0 : 1, activity_invocations: index === 1 ? 1 : 0, requests,
      snapshot: {workflow_id: workflowId, run_id: runId, status: index === 2 ? 'completed' : 'waiting',
        events: copy(events.slice(0, [3, 5, 6][index])), typed_input: copy(typedInput),
        typed_output: index === 2 ? copy(fixture.typed_value) : {type: 'null', value: null}}});
  }
  return observation;
}

for (const workflow of ['php', 'python', 'rust']) for (const activity of ['php', 'python', 'rust']) {
  const fixture = JSON.parse(readFileSync(new URL('sdk-' + workflow + '-' + activity + '.json', profile), 'utf8'));
  test('checked published direction ' + workflow + '/' + activity, () => {
    const observation = observed(fixture);
    checkSdkDirectionArtifacts(fixture, artifacts);
    checkSdkDirectionPackages(fixture, observation, artifacts);
    assert.deepEqual(checkSdkDirection(fixture, observation, workflowId),
      {one_original_activity: true, exact_typed_completion: true});
  });
}

const fixture = JSON.parse(readFileSync(new URL('sdk-python-rust.json', profile), 'utf8'));
const mutations = {
  'missing cold process': value => value.sdk_direction.phases.pop(),
  'reused process': value => {value.sdk_direction.phases[2].pid = value.sdk_direction.phases[0].pid;},
  'wrong actual SDK language': value => {value.sdk_direction.phases[1].language = 'php';},
  'wrong installed version': value => {value.sdk_direction.phases[1].sdk_version = '0.0.0';},
  'changed original run': value => {value.sdk_direction.phases[1].run_id = 'other-run';},
  'SDK phase failure': value => {value.sdk_direction.phases[0].worker_finished = false;},
  'duplicate activity invocation': value => {value.sdk_direction.phases[1].activity_invocations = 2;},
  'workflow worker executes activity': value => {value.sdk_direction.phases[0].activity_invocations = 1;},
  'activity worker replays workflow': value => {value.sdk_direction.phases[1].workflow_invocations = 1;},
  'queue collapsed': value => {value.sdk_direction.phases[1].task_queue = 'server-parity-v1';},
  'prefix rewritten': value => {value.sdk_direction.phases[0].snapshot.events[0].payload.retained_identity = 'changed';},
  'activity checkpoint missing': value => {value.sdk_direction.phases[1].snapshot.events.pop();},
  'int64 rounded at cold replay': value => {value.sdk_direction.phases[2].snapshot.typed_output.value.count.value = '9007199254740992';},
  'SDK registration fabricated': value => {value.sdk_direction.phases[1].requests[0].status = 422;},
  'activity registration contains workflow': value => {value.sdk_direction.phases[1].requests[0].request.supported_workflow_types.push(fixture.workflow_type);},
  'completion missing': value => {value.sdk_direction.phases[2].requests.pop();},
  'completion was refused': value => {value.sdk_direction.phases[2].requests.at(-1).response.recorded = false;},
  'authored activity queue changed': value => {value.sdk_direction.phases[0].requests.at(-1).request.commands[0].queue = 'server-parity-v1';},
  'same workflow task replayed': value => {value.sdk_direction.phases[2].requests[1].response.task.task_id = 'task-original';},
  'worker fingerprint before start': value => {
    value.events[1].payload.workflow_definition_fingerprint = 'sha256:unexpected';
    for (const phase of value.sdk_direction.phases) phase.snapshot.events[1].payload.workflow_definition_fingerprint = 'sha256:unexpected';
  },
};
for (const [name, mutate] of Object.entries(mutations)) test('refuse ' + name, () => {
  const value = observed(fixture);
  mutate(value);
  assert.throws(() => checkSdkDirection(fixture, value, workflowId));
});
test('refuse complete manifest source/checksum substitution', () => {
  for (const mutate of [
    value => {value.server.source_commit = 'a'.repeat(40);},
    value => {value.published_sdk_artifacts.rust.archive_sha256 = 'b'.repeat(64);},
    value => {value.runtime_targets = ['php', 'embedded'];},
  ]) {
    const selected = copy(artifacts); mutate(selected);
    assert.throws(() => checkSdkDirectionArtifacts(fixture, selected));
  }
});
test('refuse wrong actual installed sources, dependency lock and compiled identity', () => {
  for (const mutate of [
    value => {value.sdk_php_source = 'a'.repeat(40);},
    value => {value.sdk_composer_lock_sha256 = 'b'.repeat(64);},
    value => {value.sdk_loaded_sources[Object.keys(value.sdk_loaded_sources)[0]] = 'c'.repeat(64);},
    value => {value.sdk_direction.packages.python.archive_sha256 = 'd'.repeat(64);},
  ]) {
    const value = observed(fixture); mutate(value);
    assert.throws(() => checkSdkDirectionPackages(fixture, value, artifacts));
  }
});
test('embedded authored counterpart explicitly excludes language transport', () => {
  const value = observed(fixture);
  Object.assign(value, {mode: 'embedded', workflow_package: artifacts.workflow,
    workflow_source: artifacts.embedded_image_path_reference,
    embedded_composer_lock_sha256: artifacts.embedded_image_composer_lock_sha256,
    workflow_loaded_sources: copy(artifacts.workflow_loaded_sources),
    sdk_direction: {applicable: false, reason: 'embedded_executes_php_author_definitions'}});
  checkSdkDirectionPackages(fixture, value, artifacts);
  assert.deepEqual(checkSdkDirection(fixture, value, workflowId),
    {one_original_activity: true, exact_typed_completion: true});
  value.sdk_direction.applicable = true;
  assert.throws(() => checkSdkDirection(fixture, value, workflowId));
});
