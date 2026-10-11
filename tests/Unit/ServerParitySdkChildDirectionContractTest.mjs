import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkObservation} from '../../scripts/conformance/server-parity/contract.mjs';
import {checkSdkChildDirectionArtifacts, checkSdkChildDirectionPackages} from '../../scripts/conformance/server-parity/sdk-child-direction-contract.mjs';

const profile = new URL('../Fixtures/ServerParityProfiles/published-sdk-child-directions/', import.meta.url);
const artifacts = JSON.parse(readFileSync(new URL('artifacts.json', profile), 'utf8'));
const fixtureFor = (parent, child) => JSON.parse(readFileSync(new URL('child-sdk-' + parent + '-' + child + '.json', profile), 'utf8'));
const copy = value => structuredClone(value);
const workflowId = 'child-direction-original';
const time = '2026-01-01T00:00:00.123456Z';
const closed = '2026-01-01T00:00:01.123456Z';
const nil = {type: 'null', value: null};

function observed(fixture, mode = 'http') {
  const input = fixture.input.payloads[0], typed = fixture.typed_value.value.payloads.value[0];
  const raw = {mode, workflow_id: workflowId, run_id: 'original-parent-run',
    workflow_type: fixture.workflow_type, namespace: 'default', task_queue: fixture.sdk_child_direction.parent_queue,
    status: 'completed', payload_codec: 'avro', input: [fixture.input], typed_input: {type: 'list', value: [fixture.typed_value]},
    output: fixture.output, typed_output: fixture.typed_output,
    execution: {started_at: time, output_envelope: {codec: 'avro', blob: 'parent-output-frame'}},
    sdk_php: artifacts.sdk_php, sdk_php_source: artifacts.sdk_php_source_commit,
    sdk_composer_lock_sha256: artifacts.sdk_php_composer_lock_sha256,
    sdk_loaded_sources: copy(artifacts.sdk_php_loaded_sources)};
  const start = {workflow_instance_id: workflowId, workflow_run_id: raw.run_id,
    workflow_command_id: 'original-parent-command', workflow_type: fixture.workflow_type};
  const link = {sequence: 1, child_call_id: 'original-child-call', workflow_link_id: 'original-child-call',
    child_workflow_instance_id: 'original-child', child_workflow_run_id: 'original-child-run',
    child_workflow_type: fixture.child_workflow_type, parent_close_policy: 'abandon', cancellation_policy: 'abandon'};
  const event = (event_type, payload, decoded = {}, typed_decoded = {}) => ({event_type, payload, decoded, typed_decoded, timestamp: closed});
  const childStart = {workflow_instance_id: link.child_workflow_instance_id, workflow_run_id: link.child_workflow_run_id,
    workflow_type: fixture.child_workflow_type, parent_workflow_instance_id: workflowId,
    parent_workflow_run_id: raw.run_id, parent_sequence: 1, child_call_id: link.child_call_id, workflow_link_id: link.workflow_link_id};
  const child = {workflow_id: link.child_workflow_instance_id, run_id: link.child_workflow_run_id,
    workflow_type: fixture.child_workflow_type, namespace: 'default', task_queue: fixture.sdk_child_direction.child_queue,
    status: 'completed', payload_codec: 'avro', input: [input], typed_input: {type: 'list', value: [typed]},
    output: input, typed_output: typed,
    execution: {closed_at: closed, input_envelope: {codec: 'avro', blob: 'child-input-frame'},
      output_envelope: {codec: 'avro', blob: 'child-output-frame'}},
    events: [event('WorkflowStarted', childStart), event('WorkflowCompleted', {}, {output: input}, {output: typed})]};
  if (mode === 'embedded') {
    childStart.workflow_command_id = 'original-child-command';
    child.events.unshift(event('StartAccepted', {...childStart}));
    Object.assign(raw, {workflow_package: artifacts.workflow, workflow_source: artifacts.embedded_image_path_reference,
      embedded_composer_lock_sha256: artifacts.embedded_image_composer_lock_sha256,
      workflow_loaded_sources: copy(artifacts.workflow_loaded_sources)});
  }
  child.events.forEach((event, index) => {event.sequence = index + 1;});
  raw.children = [child];
  raw.events = [
    event('StartAccepted', {...start, outcome: 'started_new'}),
    event('WorkflowStarted', {...start, execution_timeout_seconds: 3600, run_timeout_seconds: 600,
      execution_deadline_at: '2026-01-01T01:00:00.123456Z', run_deadline_at: '2026-01-01T00:10:00.123456Z'}),
    event('ChildWorkflowScheduled', {...link}),
    event('ChildRunStarted', {...link, child_run_number: 1}),
    event('ChildRunCompleted', {...link, child_run_number: 1, child_status: 'completed', closed_at: closed},
      {output: input, result: input}, {output: typed, result: typed}),
    event('WorkflowCompleted', {}, {output: raw.output}, {output: raw.typed_output}),
  ];
  raw.events.forEach((event, index) => {event.sequence = index + 1;});
  if (mode === 'embedded') {
    raw.sdk_child_direction = {applicable: false, reason: 'embedded_executes_php_author_definitions'};
    return copy(raw);
  }
  raw.workflow_polls = [];
  raw.workflow_completions = [];
  const recordedPhases = [];
  for (const [index, phase] of ['original', 'child', 'replacement'].entries()) {
    const actor = index === 1 ? child : raw;
    const workerId = actor.workflow_id + ':' + phase;
    const task = {task_id: 'task-' + phase, lease_owner: workerId, workflow_task_attempt: 1,
      workflow_id: actor.workflow_id, run_id: actor.run_id, workflow_type: actor.workflow_type, task_queue: actor.task_queue,
      history_events: copy(actor.events.slice(0, index === 1 ? 1 : index === 0 ? 2 : 5))};
    if (index === 2) Object.assign(task, {child_call_id: link.child_call_id, child_workflow_run_id: child.run_id,
      resume_source_kind: 'child_workflow_run', resume_source_id: child.run_id, workflow_event_type: 'ChildRunCompleted',
      workflow_sequence: 1, open_wait_id: 'child:' + link.child_call_id});
    const decoded = index === 0 ? {arguments: [input]} : {result: actor.output};
    const typedDecoded = index === 0 ? {arguments: child.typed_input} : {result: actor.typed_output};
    const command = index === 0 ? {type: 'start_child_workflow', workflow_type: fixture.child_workflow_type,
      queue: child.task_queue, arguments: child.execution.input_envelope}
      : {type: 'complete_workflow', result: actor.execution.output_envelope};
    const request = (path, body, response, status = 200) => ({method: 'POST', path, status, request: body, response});
    const requests = [];
    if (index === 0) requests.push(request('/api/workflows', {workflow_id: workflowId,
      workflow_type: fixture.workflow_type, task_queue: raw.task_queue}, {run_id: raw.run_id}, 201));
    requests.push(request('/api/worker/register', {worker_id: workerId, task_queue: actor.task_queue,
      supported_workflow_types: [actor.workflow_type], supported_activity_types: []}, {registered: true}, 201));
    requests.push(request('/api/worker/workflow-tasks/poll', {}, {task}));
    const completion = request('/api/worker/workflow-tasks/' + task.task_id + '/complete',
      {lease_owner: task.lease_owner, workflow_task_attempt: 1, commands: [command]},
      {recorded: true, run_id: actor.run_id, task_id: task.task_id});
    completion.decoded_commands = [{decoded, typed_decoded: typedDecoded}];
    requests.push(completion);
    raw.workflow_polls.push(task);
    raw.workflow_completions.push(completion);
    const parentSnapshot = {...raw, status: index === 2 ? 'completed' : 'waiting',
      events: copy(raw.events.slice(0, [4, 5, 6][index])),
      typed_output: index === 2 ? copy(raw.typed_output) : copy(nil)};
    delete parentSnapshot.workflow_polls; delete parentSnapshot.workflow_completions;
    const childSnapshot = {...child, events: copy(index === 0 ? child.events.slice(0, 1) : child.events),
      status: index === 0 ? 'running' : 'completed', typed_output: index === 0 ? copy(nil) : copy(child.typed_output)};
    const language = fixture.sdk_child_direction[index === 1 ? 'child' : 'parent'];
    recordedPhases.push({phase, pid: 100 + index, language, sdk_version: artifacts['sdk_' + language],
      run_id: actor.run_id, worker_id: workerId, task_queue: actor.task_queue,
      workflow_invocations: 1, activity_invocations: 0, worker_finished: true, requests,
      snapshot: parentSnapshot, child_snapshot: childSnapshot});
  }
  raw.sdk_child_direction = {applicable: true, phases: recordedPhases, packages: {outcome: 'pass',
    rust: copy(artifacts.published_sdk_artifacts.rust), python: copy(artifacts.published_sdk_artifacts.python)}};
  return copy(raw);
}

for (const parent of ['php', 'python', 'rust']) for (const child of ['php', 'python', 'rust']) {
  test('published child direction contract ' + parent + '/' + child, () => {
    const fixture = fixtureFor(parent, child);
    const http = observed(fixture), embedded = observed(fixture, 'embedded');
    checkSdkChildDirectionArtifacts(fixture, artifacts);
    checkSdkChildDirectionPackages(fixture, http, artifacts);
    checkSdkChildDirectionPackages(fixture, embedded, artifacts);
    assert.deepEqual(checkObservation(fixture, http, workflowId), checkObservation(fixture, embedded, workflowId));
  });
}
const fixture = fixtureFor('python', 'rust');
const mutations = {
  'missing child phase': value => value.sdk_child_direction.phases.splice(1, 1),
  'reused process': value => {value.sdk_child_direction.phases[2].pid = 100;},
  'wrong child SDK': value => {value.sdk_child_direction.phases[1].language = 'php';},
  'wrong installed version': value => {value.sdk_child_direction.phases[1].sdk_version = '0.0.0';},
  'child phase runs parent': value => {value.sdk_child_direction.phases[1].run_id = value.run_id;},
  'child executes twice': value => {value.sdk_child_direction.phases[1].workflow_invocations = 2;},
  'parent executes activity': value => {value.sdk_child_direction.phases[0].activity_invocations = 1;},
  'cold parent changed run': value => {value.sdk_child_direction.phases[2].snapshot.run_id = 'other';},
  'parent prefix rewritten': value => {value.sdk_child_direction.phases[0].snapshot.events[2].payload.child_call_id = 'other';},
  'child prefix rewritten': value => {value.sdk_child_direction.phases[0].child_snapshot.events[0].payload.parent_sequence = 2;},
  'child queue collapsed': value => {value.children[0].task_queue = value.task_queue;},
  'original link changed': value => {value.events[3].payload.workflow_link_id = 'other';},
  'original child missing': value => {value.children = [];},
  'extra child': value => {value.children.push(copy(value.children[0]));},
  'child history omits terminal': value => {value.children[0].events.pop();},
  'parent gets stale result': value => {value.events[4].typed_decoded.result.value.count.value = '9007199254740992';},
  'child output int64 rounded': value => {value.children[0].typed_output.value.count.value = '9007199254740992';},
  'child closure changed': value => {value.events[4].payload.closed_at = time;},
  'cold parent issues new child': value => {value.sdk_child_direction.phases[2].requests.at(-1).request.commands[0].type = 'start_child_workflow';},
  'child registers parent': value => {value.sdk_child_direction.phases[1].requests[0].request.supported_workflow_types = [fixture.workflow_type];},
  'child registers activity': value => {value.sdk_child_direction.phases[1].requests[0].request.supported_activity_types = ['parity.v1.echo_activity'];},
  'registration refused': value => {value.sdk_child_direction.phases[1].requests[0].response.registered = false;},
  'completion refused': value => {value.sdk_child_direction.phases[1].requests.at(-1).response.recorded = false;},
  'completion stale lease': value => {value.sdk_child_direction.phases[1].requests.at(-1).request.lease_owner = 'stale';},
  'completion stale attempt': value => {value.sdk_child_direction.phases[1].requests.at(-1).request.workflow_task_attempt = 2;},
  'parent receives stale history': value => {value.workflow_polls[2].history_events.pop();},
  'parent resumes wrong child': value => {value.workflow_polls[2].child_workflow_run_id = 'other';},
  'child scheduled on wrong queue': value => {value.sdk_child_direction.phases[0].requests.at(-1).request.commands[0].queue = 'other';},
  'child argument frame replaced': value => {value.workflow_completions[0].request.commands[0].arguments = {codec: 'avro', blob: 'other'};},
  'child result frame replaced': value => {value.workflow_completions[1].request.commands[0].result = {codec: 'avro', blob: 'other'};},
  'child result decoded incorrectly': value => {value.workflow_completions[1].decoded_commands[0].typed_decoded.result = nil;},
  'original start not new': value => {value.sdk_child_direction.phases[0].requests[0].status = 200;},
  'start after registration': value => {value.sdk_child_direction.phases[0].requests.reverse();},
  'worker fingerprint before start': value => {value.sdk_child_direction.phases[0].snapshot.events[1].payload.workflow_definition_fingerprint = 'unexpected';},
};
for (const [name, mutate] of Object.entries(mutations)) test('refuse child direction ' + name, () => {
  const value = observed(fixture); mutate(value);
  assert.throws(() => checkObservation(fixture, value, workflowId));
});
test('child package checks reject substituted source and wheel identity', () => {
  for (const mutate of [
    value => {value.sdk_php_source = 'a'.repeat(40);},
    value => {value.sdk_composer_lock_sha256 = 'b'.repeat(64);},
    value => {value.sdk_child_direction.packages.python.archive_sha256 = 'c'.repeat(64);},
  ]) {
    const value = observed(fixture); mutate(value);
    assert.throws(() => checkSdkChildDirectionPackages(fixture, value, artifacts));
  }
  const altered = copy(artifacts); altered.server.source_commit = 'd'.repeat(40);
  assert.throws(() => checkSdkChildDirectionArtifacts(fixture, altered));
});
