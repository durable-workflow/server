import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkSdkQueryDirection, checkSdkQueryDirectionArtifacts,
  checkSdkQueryDirectionPackages} from '../../scripts/conformance/server-parity/sdk-query-direction-contract.mjs';

const profile = new URL('../Fixtures/ServerParityProfiles/published-sdk-queries/', import.meta.url);
const artifacts = JSON.parse(readFileSync(new URL('artifacts.json', profile), 'utf8'));
const languages = ['php', 'python', 'rust'];
const clone = value => structuredClone(value);
const envelope = blob => ({codec: 'avro', blob});
const fixtureFor = (author, client) => JSON.parse(readFileSync(new URL('query-sdk-' + author + '-' + client + '.json', profile), 'utf8'));
const typedState = (fixture, count) => ({type: 'map', value: {
  received: {type: 'int64', value: String(count)}, last: count ? fixture.typed_signal_value : {type: 'null', value: null},
}});
function model(fixture, mode = 'http') {
  const spec = fixture.sdk_query_direction, workflowId = 'query-model', runId = 'original-query-run';
  const names = mode === 'embedded' ? fixture.embedded_expected_events : fixture.expected_events;
  const events = names.map((event_type, i) => ({id: 'event-' + i, sequence: i + 1, event_type, payload: {}}));
  events[1].payload.declared_queries = ['state'];
  const received = events.filter(item => item.event_type === 'SignalReceived');
  received.forEach((item, i) => { item.payload.workflow_command_id = 'signal-command-' + i; });
  const input = {type: 'list', value: [fixture.typed_value, {type: 'int64', value: '2'}]};
  const observation = {mode, workflow_id: workflowId, run_id: runId, workflow_type: fixture.workflow_type,
    namespace: 'default', task_queue: spec.task_queue, payload_codec: 'avro', events, typed_input: input,
    execution: {id: runId, workflow_instance_id: workflowId, status: 'completed', output_envelope: envelope('final-result')},
    sdk_php_source: artifacts.sdk_php_source_commit, sdk_composer_lock_sha256: artifacts.sdk_php_composer_lock_sha256,
    sdk_loaded_sources: clone(artifacts.sdk_php_loaded_sources)};
  const snapshot = (length, status) => ({workflow_id: workflowId, run_id: runId,
    workflow_type: fixture.workflow_type, namespace: 'default', task_queue: spec.task_queue,
    payload_codec: 'avro', status, typed_input: input, typed_output: status === 'completed'
      ? fixture.typed_signal_value : {type: 'null', value: null},
    events: clone(events.slice(0, length)),
    execution: {id: runId, workflow_instance_id: workflowId, status, last_history_sequence: length,
      last_progress_at: 'checkpoint-' + length, wait_reason: status === 'completed' ? null : 'committed-wait',
      control_plane: {last_progress_at: 'checkpoint-' + length, wait_reason: status === 'completed' ? null : 'committed-wait'},
      actions: {can_query: false, can_signal: status === 'waiting', can_archive: false}}});
  if (mode === 'embedded') {
    observation.sdk_query_direction = {applicable: false, reason: 'embedded_executes_php_author_definitions'};
    observation.workflow_package = artifacts.workflow;
    observation.workflow_source = artifacts.embedded_image_path_reference;
    observation.embedded_composer_lock_sha256 = artifacts.embedded_image_composer_lock_sha256;
    observation.workflow_loaded_sources = clone(artifacts.workflow_loaded_sources);
    observation.queries = [0, 1, 2].map(count => {
      const status = count === 2 ? 'completed' : 'waiting', before = snapshot([3, 7, 11][count], status);
      return {name: 'state', arguments: [], typed_arguments: {type: 'list', value: []},
        before: before.execution, after: clone(before.execution), history_before: before.events,
        history_after: clone(before.events), result: {received: count, last: count ? fixture.signal_value : null},
        typed_result: typedState(fixture, count)};
    });
    return clone(observation);
  }
  const phases = spec.phase_order.map((phase, i) => {
    const client = phase.startsWith('query-'), sender = phase.startsWith('signal-');
    const language = sender ? 'php' : (client ? spec.client : spec.workflow);
    const status = i >= 6 ? 'completed' : 'waiting';
    return {phase, language, pid: 100 + i, sdk_version: artifacts['sdk_' + language],
      workflow_id: workflowId, run_id: runId, requests: [],
      snapshot: snapshot([3, 3, 4, 8, 8, 9, 13, 13][i], status)};
  });
  const workflowTasks = [], completions = [], deliveries = [];
  for (const [turn, i] of [0, 3, 6].entries()) {
    const phase = phases[i], workerId = workflowId + ':' + phase.phase;
    Object.assign(phase, {worker_id: workerId, task_queue: spec.task_queue, worker_finished: true,
      workflow_invocations: spec.workflow === 'python' ? 2 : 1, activity_invocations: 0, query_invocations: 1,
      ...(spec.workflow === 'php' ? {query_completions: 1} : {})});
    phase.requests.push({method: 'POST', path: '/api/worker/register', status: 201,
      request: {worker_id: workerId, task_queue: spec.task_queue, supported_workflow_types: [fixture.workflow_type],
        supported_activity_types: [], workflow_command_contracts: {[fixture.workflow_type]: {queries: ['state']}}},
      response: {registered: true}});
    if (turn === 0) phase.requests.push({method: 'POST', path: '/api/workflows', status: 201,
      request: {workflow_id: workflowId}, response: {run_id: runId}});
    const task = {task_id: 'workflow-task-' + turn, lease_owner: 'workflow-lease-' + turn, workflow_task_attempt: 1,
      workflow_id: workflowId, run_id: runId, workflow_type: fixture.workflow_type,
      task_queue: spec.task_queue, history_events: clone(events.slice(0, [2, 4, 9][turn]))};
    phase.requests.push({method: 'POST', path: '/api/worker/workflow-tasks/poll', status: 200,
      request: {task_queue: spec.task_queue}, response: {task}});
    const commands = turn === 2 ? [{type: 'complete_workflow', result: envelope('final-result')}]
      : [{type: 'open_condition_wait', condition_key: 'payload:' + turn}];
    const complete = {method: 'POST', path: '/api/worker/workflow-tasks/' + task.task_id + '/complete', status: 200,
      request: {lease_owner: task.lease_owner, workflow_task_attempt: 1, commands},
      response: {task_id: task.task_id, run_id: runId, workflow_task_attempt: 1, recorded: true},
      decoded_commands: [{decoded: turn === 2 ? {result: fixture.signal_value} : {},
        typed_decoded: turn === 2 ? {result: fixture.typed_signal_value} : {}}]};
    phase.requests.push(complete);
    phase.checkpoint_workflow_ack = {path: complete.path, task_id: task.task_id,
      lease_owner: task.lease_owner, workflow_task_attempt: 1,
      request_index: phase.requests.indexOf(complete), status: 200};
    workflowTasks.push(task); completions.push(complete);
    const queryTask = {query_task_id: 'query-task-' + turn, lease_owner: 'query-lease-' + turn, query_task_attempt: 1,
      workflow_id: workflowId, run_id: runId, workflow_type: fixture.workflow_type, task_queue: spec.task_queue,
      query_name: 'state', query_arguments: envelope('empty-arguments'), workflow_arguments: envelope('workflow-arguments'),
      history_events: clone(phase.snapshot.events)};
    phase.requests.push({method: 'POST', path: '/api/worker/query-tasks/poll', status: 200,
      request: {task_queue: spec.task_queue}, response: {task: queryTask},
      typed_query_arguments: fixture.typed_query_arguments, typed_workflow_arguments: input});
    const value = {received: turn, last: turn ? fixture.signal_value : null};
    phase.requests.push({method: 'POST', path: '/api/worker/query-tasks/' + queryTask.query_task_id + '/complete', status: 200,
      request: {lease_owner: queryTask.lease_owner, query_task_attempt: 1, result_envelope: envelope('query-result-' + turn)},
      response: {query_task_id: queryTask.query_task_id, query_task_attempt: 1, outcome: 'completed', reason: null, status: 200},
      decoded_result: value, typed_decoded_result: typedState(fixture, turn)});
    const caller = phases[i + 1];
    Object.assign(caller, {query_sent: true, client_finished: true, snapshot_before: clone(phase.snapshot)});
    caller.snapshot_before.execution.actions.can_query = true; // The author is still registered here.
    const response = {workflow_id: workflowId, run_id: runId, query_name: 'state', reason: null,
      result: null, result_envelope: envelope('query-result-' + turn)};
    caller.requests.push({method: 'POST', path: '/api/workflows/' + workflowId + '/query/state', status: 200,
      request: spec.client === 'python' ? {} : {input: envelope('empty-arguments')}, response,
      typed_decoded_input: fixture.typed_query_arguments, decoded_result: value, typed_decoded_result: typedState(fixture, turn)});
    caller.client_result = spec.client === 'python' ? {...response, result: value} : value;
    caller.typed_client_result = spec.client === 'python' ? {type: 'map', value: {result: typedState(fixture, turn)}} : typedState(fixture, turn);
    if (turn < 2) {
      const sender = phases[i + 2];
      const receipt = {workflow_id: workflowId, run_id: runId, outcome: 'signal_received', command_id: 'signal-command-' + turn};
      Object.assign(sender, {signal_sent: true, sender_finished: true, snapshot_before: clone(caller.snapshot), client_result: receipt});
      sender.requests.push({method: 'POST', path: '/api/workflows/' + workflowId + '/signal/payload', status: 202,
        request: {input: envelope('signal-map')}, response: receipt,
        typed_decoded_input: {type: 'list', value: [fixture.typed_signal_value]}});
      deliveries.push({before: sender.snapshot_before.execution, response: receipt});
    }
  }
  observation.workflow_polls = workflowTasks;
  observation.workflow_completions = completions;
  observation.signal_deliveries = deliveries;
  observation.sdk_query_direction = {applicable: true, phases, observer_start: {role: 'observer',
    language: 'php', pid: 900, sdk_version: artifacts.sdk_php, run_id: runId},
    packages: {outcome: 'pass', rust: artifacts.published_sdk_artifacts.rust, python: artifacts.published_sdk_artifacts.python}};
  return clone(observation);
}

for (const author of languages) for (const client of languages) {
  const fixture = fixtureFor(author, client);
  for (const mode of ['http', 'embedded']) test(author + '/' + client + ' ' + mode + ' committed query snapshots', () => {
    const observation = model(fixture, mode);
    checkSdkQueryDirectionArtifacts(fixture, artifacts);
    checkSdkQueryDirectionPackages(fixture, observation, artifacts);
    assert.deepEqual(checkSdkQueryDirection(fixture, observation, 'query-model'),
      {three_fresh_readonly_queries: true, exact_typed_completion: true});
  });
}
const fixture = fixtureFor('python', 'python');
const mutations = [
  ['reused process', o => { o.sdk_query_direction.phases[4].pid = 100; }],
  ['wrong client language', o => { o.sdk_query_direction.phases[1].language = 'rust'; }],
  ['wrong published SDK', o => { o.sdk_query_direction.phases[1].sdk_version = 'unfrozen'; }],
  ['missing query declaration', o => { o.events[1].payload.declared_queries = []; }],
  ['mutated original history', o => { o.sdk_query_direction.phases[1].snapshot.events[0].payload.changed = true; }],
  ['query changes run state', o => { o.sdk_query_direction.phases[1].snapshot.execution.message_cursor = 1; }],
  ['query changes progress time', o => { o.sdk_query_direction.phases[1].snapshot.execution.last_progress_at = 'changed'; }],
  ['query changes wait reason', o => { o.sdk_query_direction.phases[4].snapshot.execution.wait_reason = 'prior-wait'; }],
  ['query changes control-plane progress', o => { o.sdk_query_direction.phases[1].snapshot.execution.control_plane.last_progress_at = 'changed'; }],
  ['query changes control-plane wait reason', o => { o.sdk_query_direction.phases[4].snapshot.execution.control_plane.wait_reason = 'changed'; }],
  ['query changes another action', o => { o.sdk_query_direction.phases[1].snapshot.execution.actions.can_signal = false; }],
  ['query availability loses boolean type', o => { o.sdk_query_direction.phases[1].snapshot.execution.actions.can_query = 'false'; }],
  ['query availability disappears', o => { delete o.sdk_query_direction.phases[1].snapshot.execution.actions.can_query; }],
  ['missing workflow checkpoint receipt', o => { delete o.sdk_query_direction.phases[0].checkpoint_workflow_ack; }],
  ['checkpoint receipt uses another task', o => { o.sdk_query_direction.phases[0].checkpoint_workflow_ack.task_id = 'different'; }],
  ['checkpoint receipt uses another request', o => { o.sdk_query_direction.phases[0].checkpoint_workflow_ack.request_index = 0; }],
  ['query changes history ID', o => { o.sdk_query_direction.phases[1].snapshot.events[0].id = 'different-event'; }],
  ['missing query request', o => { o.sdk_query_direction.phases[1].requests = []; }],
  ['supplied Python empty input', o => { o.sdk_query_direction.phases[1].requests[0].request.input = envelope('empty-arguments'); }],
  ['old query result after resumption', o => { o.sdk_query_direction.phases[4].requests[0].decoded_result.received = 0; }],
  ['int64 rounded by client', o => { o.sdk_query_direction.phases[4].typed_client_result.value.result.value.last.value.count.value = '9007199254740992'; }],
  ['lost hydrated response identity', o => { delete o.sdk_query_direction.phases[1].client_result.run_id; }],
  ['incorrect query argument types', o => { o.sdk_query_direction.phases[1].requests[0].typed_decoded_input = {type: 'null', value: null}; }],
  ['foreign query run', o => { o.sdk_query_direction.phases[0].requests.find(r => r.path === '/api/worker/query-tasks/poll').response.task.run_id = 'foreign-run'; }],
  ['stale query attempt', o => { o.sdk_query_direction.phases[0].requests.find(r => r.path === '/api/worker/query-tasks/poll').response.task.query_task_attempt = 2; }],
  ['missing query lease', o => { o.sdk_query_direction.phases[0].requests.find(r => r.path === '/api/worker/query-tasks/poll').response.task.lease_owner = ''; }],
  ['incomplete query history', o => { o.sdk_query_direction.phases[0].requests.find(r => r.path === '/api/worker/query-tasks/poll').response.task.history_events.pop(); }],
  ['query uses foreign event ID', o => { o.sdk_query_direction.phases[0].requests.find(r => r.path === '/api/worker/query-tasks/poll').response.task.history_events[0].id = 'foreign-event'; }],
  ['query completion wrong lease', o => { o.sdk_query_direction.phases[0].requests.find(r => /query-tasks\/.*\/complete/.test(r.path)).request.lease_owner = 'old-lease'; }],
  ['query completion wrong task', o => { o.sdk_query_direction.phases[0].requests.find(r => /query-tasks\/.*\/complete/.test(r.path)).response.query_task_id = 'foreign-query'; }],
  ['query completion failed', o => { o.sdk_query_direction.phases[0].requests.find(r => /query-tasks\/.*\/complete/.test(r.path)).response.outcome = 'failed'; }],
  ['receipt envelope replaced', o => { o.sdk_query_direction.phases[1].requests[0].response.result_envelope.blob = 'foreign-result'; }],
  ['missing author query callback', o => { o.sdk_query_direction.phases[0].query_invocations = 0; }],
  ['uniform callback count hides Python replay', o => { o.sdk_query_direction.phases[0].workflow_invocations = 1; }],
  ['unregistered query', o => { o.sdk_query_direction.phases[0].requests[0].request.workflow_command_contracts[fixture.workflow_type].queries = []; }],
  ['query starts another run', o => { o.sdk_query_direction.phases[1].requests.push({method: 'POST', path: '/api/workflows', status: 201}); }],
  ['first workflow claim before start receipt', o => {
    const requests = o.sdk_query_direction.phases[0].requests;
    [requests[1], requests[2]] = [requests[2], requests[1]];
  }],
];
for (const [name, mutate] of mutations) test('reject ' + name, () => {
  const observation = model(fixture);
  mutate(observation);
  assert.throws(() => checkSdkQueryDirection(fixture, observation, 'query-model'));
});
for (const [name, mutate] of [
  ['changed durable state', o => { o.queries[1].after.updated_at = 'changed'; }],
  ['changed original event', o => { o.queries[1].history_after[0].id = 'changed'; }],
  ['cached old state', o => { o.queries[1].result.received = 0; }],
  ['rounded int64', o => { o.queries[1].typed_result.value.last.value.count.value = '9007199254740992'; }],
]) test('embedded rejects ' + name, () => {
  const observation = model(fixture, 'embedded'); mutate(observation);
  assert.throws(() => checkSdkQueryDirection(fixture, observation, 'query-model'));
});
test('reject package tuple drift', () => {
  const observation = model(fixture);
  observation.sdk_query_direction.packages.python.version = 'unfrozen';
  assert.throws(() => checkSdkQueryDirectionPackages(fixture, observation, artifacts));
});
