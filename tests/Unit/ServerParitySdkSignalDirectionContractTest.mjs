import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkObservation} from '../../scripts/conformance/server-parity/contract.mjs';
import {checkSdkSignalDirectionArtifacts, checkSdkSignalDirectionPackages} from '../../scripts/conformance/server-parity/sdk-signal-direction-contract.mjs';

const profile = new URL('../Fixtures/ServerParityProfiles/published-sdk-signals/', import.meta.url);
const artifacts = JSON.parse(readFileSync(new URL('artifacts.json', profile), 'utf8'));
const fixtureFor = (author, client) => JSON.parse(readFileSync(new URL('signal-sdk-' + author + '-' + client + '.json', profile), 'utf8'));
const copy = value => structuredClone(value);
const workflowId = 'signal-original-workflow';
const phases = ['original', 'signal-1', 'resumed', 'signal-2', 'replacement'];
const lengths = [3, 4, 8, 9, 13];
const nil = {type: 'null', value: null};

function observed(fixture, mode = 'http') {
  const spec = fixture.sdk_signal_direction;
  const raw = {mode, workflow_id: workflowId, run_id: 'original-signal-run', workflow_type: fixture.workflow_type,
    namespace: 'default', task_queue: spec.task_queue, status: 'completed', payload_codec: 'avro',
    input: [fixture.input, 2], typed_input: {type: 'list', value: [fixture.typed_value, {type: 'int64', value: '2'}]},
    output: fixture.signal_value, typed_output: fixture.typed_signal_value,
    execution: {run_id: 'original-signal-run', status: 'completed', started_at: '2026-01-01T00:00:00Z',
      output_envelope: {codec: 'avro', blob: 'original-output-frame'}},
    sdk_php: artifacts.sdk_php, sdk_php_source: artifacts.sdk_php_source_commit,
    sdk_composer_lock_sha256: artifacts.sdk_php_composer_lock_sha256,
    sdk_loaded_sources: copy(artifacts.sdk_php_loaded_sources)};
  const start = {workflow_instance_id: workflowId, workflow_run_id: raw.run_id,
    workflow_command_id: 'original-start-command', workflow_type: fixture.workflow_type};
  raw.events = [
    {event_type: 'StartAccepted', payload: {...start, outcome: 'started_new'}, decoded: {}, typed_decoded: {}},
    {event_type: 'WorkflowStarted', payload: {...start, declared_signals: ['payload'],
      execution_timeout_seconds: 3600, run_timeout_seconds: 600,
      execution_deadline_at: '2026-01-01T01:00:00Z', run_deadline_at: '2026-01-01T00:10:00Z'}, decoded: {}, typed_decoded: {}},
  ];
  raw.signal_deliveries = [];
  for (let index = 0; index < 2; index++) {
    const opened = mode === 'embedded' ? {signal_wait_id: 'wait-' + index, signal_name: 'payload', sequence: index + 1}
      : {condition_wait_id: 'wait-' + index, condition_key: 'payload:' + index,
        condition_definition_fingerprint: 'sha256:' + 'a'.repeat(64), sequence: index + 1};
    const received = {workflow_instance_id: workflowId, workflow_run_id: raw.run_id,
      workflow_command_id: 'signal-command-' + index, signal_id: 'signal-' + index,
      signal_wait_id: (mode === 'embedded' ? 'wait-' : 'routing-wait-') + index,
      signal_name: 'payload', payload_codec: 'avro',
      command: {id: 'signal-command-' + index, sequence: index + 2, message_sequence: index + 1}};
    const receipt = {accepted: true, command_id: received.workflow_command_id, run_id: raw.run_id,
      workflow_id: workflowId, outcome: 'signal_received'};
    raw.signal_deliveries.push({before: {status: 'waiting', run_id: raw.run_id}, response: receipt});
    raw.events.push(
      {event_type: mode === 'embedded' ? 'SignalWaitOpened' : 'ConditionWaitOpened', payload: opened, decoded: {}, typed_decoded: {}},
      {event_type: 'SignalReceived', payload: received, decoded: {arguments: [fixture.signal_value]},
        typed_decoded: {arguments: {type: 'list', value: [fixture.typed_signal_value]}}},
      {event_type: 'MessageCursorAdvanced', payload: {stream_key: 'instance:' + workflowId,
        previous_position: index, new_position: index + 1}, decoded: {}, typed_decoded: {}},
      {event_type: 'SignalApplied', payload: {...received, ...(mode === 'embedded' ? {sequence: index + 1} : {})},
        decoded: {value: fixture.signal_value}, typed_decoded: {value: fixture.typed_signal_value}},
      ...(mode === 'embedded' ? [] : [{event_type: 'ConditionWaitSatisfied', payload: {...opened,
        workflow_signal_id: received.signal_id, signal_name: received.signal_name, signal_wait_id: received.signal_wait_id},
        decoded: {}, typed_decoded: {}}]));
  }
  raw.events.push({event_type: 'WorkflowCompleted', payload: {}, decoded: {output: raw.output}, typed_decoded: {output: raw.typed_output}});
  raw.events = raw.events.map((event, index) => ({...event, sequence: index + 1, timestamp: '2026-01-01T00:00:01Z'}));
  if (mode === 'embedded') {
    Object.assign(raw, {workflow_package: artifacts.workflow, workflow_source: artifacts.embedded_image_path_reference,
      embedded_composer_lock_sha256: artifacts.embedded_image_composer_lock_sha256,
      workflow_loaded_sources: copy(artifacts.workflow_loaded_sources),
      sdk_signal_direction: {applicable: false, reason: 'embedded_executes_php_author_definitions'}});
    return copy(raw);
  }
  raw.workflow_polls = []; raw.workflow_completions = [];
  const recorded = [];
  let turn = 0;
  const request = (path, body, response, status = 200) => ({method: 'POST', path, status, request: body, response});
  for (const [index, phase] of phases.entries()) {
    const sender = index === 1 || index === 3;
    const checkpoint = {workflow_id: workflowId, run_id: raw.run_id, workflow_type: raw.workflow_type,
      namespace: raw.namespace, task_queue: raw.task_queue, payload_codec: raw.payload_codec,
      status: index === 4 ? 'completed' : 'waiting', events: copy(raw.events.slice(0, lengths[index])),
      typed_input: copy(raw.typed_input), typed_output: copy(index === 4 ? raw.typed_output : nil),
      execution: {...raw.execution, status: index === 4 ? 'completed' : 'waiting',
        output_envelope: index === 4 ? raw.execution.output_envelope : null}};
    if (sender) {
      const signalIndex = index === 1 ? 0 : 1, receipt = copy(raw.signal_deliveries[signalIndex].response);
      raw.signal_deliveries[signalIndex].before = copy(recorded[index - 1].snapshot.execution);
      const wire = request('/api/workflows/' + workflowId + '/signal/payload',
        {input: {codec: 'avro', blob: 'signal-frame-' + signalIndex}}, receipt, 202);
      wire.decoded_input = [fixture.signal_value];
      wire.typed_decoded_input = {type: 'list', value: [fixture.typed_signal_value]};
      recorded.push({phase, language: spec.client, sdk_version: artifacts['sdk_' + spec.client],
        pid: 201 + index, workflow_id: workflowId, run_id: raw.run_id, sender_finished: true,
        signal_sent: true, client_result: spec.client === 'python' ? null : copy(receipt), requests: [wire], snapshot: checkpoint});
      continue;
    }
    const workerId = workflowId + ':' + phase;
    const task = {task_id: 'task-' + phase, lease_owner: workerId, workflow_task_attempt: 1,
      workflow_id: workflowId, run_id: raw.run_id, workflow_type: raw.workflow_type, task_queue: raw.task_queue,
      history_events: copy(raw.events.slice(0, [2, 4, 9][turn]))};
    const command = turn === 2 ? {type: 'complete_workflow', result: raw.execution.output_envelope}
      : {type: 'open_condition_wait', condition_key: 'payload:' + turn,
        condition_definition_fingerprint: raw.events[turn === 0 ? 2 : 7].payload.condition_definition_fingerprint};
    const requests = [request('/api/worker/register', {worker_id: workerId, task_queue: raw.task_queue,
      supported_workflow_types: [raw.workflow_type], supported_activity_types: []}, {registered: true}, 201)];
    if (turn === 0) requests.push(request('/api/workflows', {workflow_id: workflowId,
      workflow_type: raw.workflow_type, task_queue: raw.task_queue, input: {codec: 'avro', blob: 'input-frame'}}, {run_id: raw.run_id}, 201));
    requests.push(request('/api/worker/workflow-tasks/poll', {task_queue: raw.task_queue}, {task}));
    const completion = request('/api/worker/workflow-tasks/' + task.task_id + '/complete',
      {lease_owner: task.lease_owner, workflow_task_attempt: 1, commands: [command]},
      {recorded: true, task_id: task.task_id, run_id: raw.run_id});
    requests.push(completion);
    raw.workflow_polls.push(copy(task));
    raw.workflow_completions.push({...copy(completion), decoded_commands: [turn === 2
      ? {decoded: {result: raw.output}, typed_decoded: {result: raw.typed_output}}
      : {decoded: {}, typed_decoded: {}}]});
    recorded.push({phase, language: spec.workflow, sdk_version: artifacts['sdk_' + spec.workflow],
      pid: 201 + index, worker_finished: true, run_id: raw.run_id, worker_id: workerId,
      task_queue: raw.task_queue, workflow_invocations: 1, activity_invocations: 0, requests, snapshot: checkpoint});
    turn++;
  }
  raw.sdk_signal_direction = {applicable: true, phases: recorded,
    observer_start: {role: 'observer', language: 'php', sdk_version: artifacts.sdk_php, pid: 120, run_id: raw.run_id},
    packages: {outcome: 'pass', rust: copy(artifacts.published_sdk_artifacts.rust), python: copy(artifacts.published_sdk_artifacts.python)}};
  return copy(raw);
}

for (const author of ['php', 'python', 'rust']) for (const client of ['php', 'python', 'rust']) for (const mode of ['http', 'embedded']) {
  test('check signal direction guard model ' + author + '/' + client + ' ' + mode, () => {
    const fixture = fixtureFor(author, client), value = observed(fixture, mode);
    checkSdkSignalDirectionPackages(fixture, value, artifacts);
    assert.deepEqual(checkObservation(fixture, value, workflowId).sdk_signal_direction,
      {two_distinct_identical_signals: true, exact_typed_completion: true});
  });
}
const fixture = fixtureFor('python', 'python');
const replacement = value => value.sdk_signal_direction.phases[4];
const firstSender = value => value.sdk_signal_direction.phases[1];
const completion = value => replacement(value).requests.at(-1);
const mutations = {
  'same process replay': value => {replacement(value).pid = value.sdk_signal_direction.phases[0].pid;},
  'missing cold process': value => {value.sdk_signal_direction.phases.pop();},
  'observer is SDK worker': value => {value.sdk_signal_direction.observer_start.pid = replacement(value).pid;},
  'wrong sender language': value => {firstSender(value).language = 'rust';},
  'wrong installed version': value => {replacement(value).sdk_version = '0.0.0';},
  'unfinished sender': value => {firstSender(value).sender_finished = false;},
  'invented Python receipt': value => {firstSender(value).client_result = copy(firstSender(value).requests[0].response);},
  'second run': value => {replacement(value).run_id = 'other-run';},
  'second workflow callback': value => {replacement(value).workflow_invocations = 2;},
  'activity callback': value => {replacement(value).activity_invocations = 1;},
  'wrong checkpoint queue': value => {replacement(value).snapshot.task_queue = 'other';},
  'wrong checkpoint namespace': value => {replacement(value).snapshot.namespace = 'other';},
  'original not waiting': value => {value.sdk_signal_direction.phases[0].snapshot.status = 'completed';},
  'first signal missing': value => {value.sdk_signal_direction.phases[2].snapshot.events.pop();},
  'cold history truncated': value => {replacement(value).requests[1].response.task.history_events.pop();},
  'wrong claimed run': value => {replacement(value).requests[1].response.task.run_id = 'other';},
  'wrong claimed queue': value => {replacement(value).requests[1].response.task.task_queue = 'other';},
  'registration refused': value => {replacement(value).requests[0].response.registered = false;},
  'extra activity type': value => {replacement(value).requests[0].request.supported_activity_types = ['activity'];},
  'completion refused': value => {completion(value).response.recorded = false;},
  'stale lease': value => {completion(value).request.lease_owner = 'stale';},
  'stale attempt': value => {completion(value).request.workflow_task_attempt = 2;},
  'wrong task receipt': value => {completion(value).response.task_id = 'other';},
  'cold replay reopens wait': value => {completion(value).request.commands[0].type = 'open_condition_wait';},
  'unbound completion copy': value => {value.workflow_completions[2].response.recorded = false;},
  'result frame replaced': value => {value.execution.output_envelope = {codec: 'avro', blob: 'other'};},
  'int64 sender rounded': value => {firstSender(value).requests[0].typed_decoded_input.value[0].value.count.value = '9007199254740992';},
  'int64 result rounded': value => {value.workflow_completions[2].decoded_commands[0].typed_decoded.result.value.count.value = '9007199254740992';},
  'new start before cold replay': value => {replacement(value).requests.push(copy(value.sdk_signal_direction.phases[0].requests[1]));},
  'start before registration': value => {value.sdk_signal_direction.phases[0].requests.reverse();},
  'signal refused': value => {firstSender(value).requests[0].status = 409;},
  'created signal status': value => {firstSender(value).requests[0].status = 201;},
  'wrong accepted run': value => {firstSender(value).requests[0].response.run_id = 'other';},
  'run selector added': value => {firstSender(value).requests[0].request.run_id = value.run_id;},
  'unbound delivery receipt': value => {value.signal_deliveries[0].response.command_id = 'other';},
  'coalesced signal command': value => {value.sdk_signal_direction.phases[3].requests[0].response.command_id = firstSender(value).requests[0].response.command_id;},
  'wrong next wait key': value => {value.sdk_signal_direction.phases[2].requests.at(-1).request.commands[0].condition_key = 'payload:0';},
  'changed original wait fingerprint': value => {replacement(value).requests[1].response.task.history_events[2].payload.condition_definition_fingerprint = 'sha256:' + 'b'.repeat(64);},
};
for (const [name, mutate] of Object.entries(mutations)) test('refuse signal direction ' + name, () => {
  const value = observed(fixture); mutate(value);
  assert.throws(() => checkObservation(fixture, value, workflowId));
});
test('signal package guard refuses source and tuple substitutions', () => {
  const value = observed(fixture); value.sdk_signal_direction.packages.python.archive_sha256 = 'a'.repeat(64);
  assert.throws(() => checkSdkSignalDirectionPackages(fixture, value, artifacts));
  const altered = copy(artifacts); altered.server.source_commit = 'b'.repeat(40);
  assert.throws(() => checkSdkSignalDirectionArtifacts(fixture, altered));
});
