import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {observed} from '../Fixtures/ServerParityReaders/sdk-signals.mjs';
import {checkObservation} from '../../scripts/conformance/server-parity/contract.mjs';
import {namespaceSignalRunFixture} from '../../scripts/conformance/server-parity/sdk-namespace-signal-contract.mjs';

const directory = new URL('../Fixtures/ServerParityPending/published-sdk-namespace-signals/', import.meta.url);
const fixtureFor = (author, client) => JSON.parse(readFileSync(new URL(`signal-namespace-sdk-${author}-${client}.json`, directory), 'utf8'));
const workflowId = 'namespace-original';
const copy = value => structuredClone(value);
const withNamespace = (value, namespace) => {
  if (Array.isArray(value)) value.forEach(item => withNamespace(item, namespace));
  else if (value && typeof value === 'object') {
    if (value.method && value.path) value.namespace = namespace;
    Object.values(value).forEach(item => withNamespace(item, namespace));
  }
  return value;
};

function pairModel(fixture, mode = 'http') {
  const runs = [false, true].map((peer, index) => withNamespace(observed(namespaceSignalRunFixture(fixture, peer), mode,
    workflowId + (peer ? ':peer' : ''), ':' + index, index * 500), peer ? fixture.namespace_signal_pair.peer_namespace : fixture.namespace));
  const checkpoint = (index, phase) => mode === 'http' ? copy(runs[index].sdk_signal_direction.phases[phase].snapshot)
    : {...copy(runs[index]), status: 'waiting', events: copy(runs[index].events.slice(0, 3)),
      output: null, typed_output: {type: 'null', value: null},
      execution: {...runs[index].execution, status: 'waiting', output_envelope: null}};
  const initial = [checkpoint(0, 0), checkpoint(1, 0)];
  const pair = {applicable: true, pair_started_at_unix: 1000, pair_finished_at_unix: 1014,
    pair_budget_seconds: 70, run_budget_seconds: 35, initial,
    run_budgets: [{started_at_unix: 1000, deadline_at_unix: 1035, finished_at_unix: 1011},
      {started_at_unix: 1001, deadline_at_unix: 1036, finished_at_unix: 1013}],
    foreign_signals: [], peer: runs[1]};
  for (const index of [0, 1]) {
    const before = mode === 'embedded' ? {runs: copy(initial),
      commands: runs.map(run => ({workflow_run_id: run.run_id, id: run.events[0].payload.workflow_command_id})),
      tasks: runs.map(run => ({workflow_run_id: run.run_id, namespace: run.namespace}))} : copy(initial);
    let result;
    if (mode === 'embedded') {
      result = {refused: true, typed_error: {class: 'Illuminate\\Database\\Eloquent\\ModelNotFoundException'}};
    } else {
      const language = fixture.sdk_signal_direction.client;
      const response = {reason: 'workflow_not_found'};
      result = {phase: 'foreign-signal', language, pid: 1001 + index, sdk_version: runs[index].sdk_signal_direction.phases[1].sdk_version,
        workflow_id: runs[index].workflow_id, run_id: runs[index].run_id, signal_sent: false, sender_finished: true,
        requests: [{method: 'POST', path: '/api/workflows/' + runs[index].workflow_id + '/signal/payload',
          namespace: runs[1 - index].namespace, request: {input: {codec: 'avro', blob: 'foreign-input'}}, status: 404, response}],
        typed_error: language === 'php' ? {class: 'DurableWorkflow\\Exception\\ServerException', status: 404, details: response}
          : language === 'rust' ? {class: 'durable_workflow::Error::Http', status: 404, body: JSON.stringify(response)}
          : {class: 'durable_workflow.errors.WorkflowNotFound', status: null, body: null}};
    }
    pair.foreign_signals.push({target_run: index, before, after: copy(before), result});
  }
  if (mode === 'embedded') {
    pair.foreign_polls = {applicable: false, reason: 'embedded_has_no_http_worker_poll'};
  } else {
    pair.administration = runs.map(run => ({created: true, namespace: {name: run.namespace, status: 'active', retention_days: 7}}));
    pair.timeline = [[0, 'original'], [1, 'original'], [0, 'signal-1'], [0, 'resumed'],
      [1, 'signal-1'], [1, 'resumed'], [0, 'signal-2'], [0, 'replacement'], [1, 'signal-2'], [1, 'replacement']]
      .map(([run, phase], index) => ({run, phase, at_unix: 1001 + index}));
    pair.foreign_polls = [[0, 1, 0], [1, 1, 2], [0, 3, 2], [1, 3, 4]].map(([index, phase, peerPhase], count) => {
      const workerId = runs[index].workflow_id + ':foreign-poll';
      const before = [0, 1].map(run => checkpoint(run, run === index ? phase : peerPhase));
      const request = (path, body, response, status = 200) => ({method: 'POST', path,
        namespace: runs[1 - index].namespace, request: body, response, status});
      return {target_run: index, after_signal: phase === 1 ? 'signal-1' : 'signal-2', before, after: copy(before),
        result: {phase: 'foreign-poll', language: 'php', sdk_version: runs[index].sdk_php,
          run_id: runs[index].run_id, worker_id: workerId, pid: 1010 + count, worker_finished: true,
          workflow_invocations: 0, activity_invocations: 0,
          requests: [request('/api/worker/register', {worker_id: workerId, task_queue: runs[index].task_queue,
            supported_workflow_types: [fixture.workflow_type], supported_activity_types: []}, {registered: true}, 201),
          request('/api/worker/workflow-tasks/poll', {worker_id: workerId, task_queue: runs[index].task_queue}, {task: null})]}};
    });
  }
  return {...runs[0], namespace_signal_pair: pair};
}

for (const author of ['php', 'python', 'rust']) for (const client of ['php', 'python', 'rust']) {
  test(`paired namespace signal reader model ${author}/${client}`, () => {
    const fixture = fixtureFor(author, client);
    const http = checkObservation(fixture, pairModel(fixture), workflowId);
    const embedded = checkObservation(fixture, pairModel(fixture, 'embedded'), workflowId);
    assert.deepEqual(http, embedded, 'same reviewed semantics, with explicit embedded transport inapplicability');
  });
}
const fixture = fixtureFor('python', 'python');
test('retain equivalent encoded and unencoded peer workflow path', () => {
  const php = fixtureFor('php', 'php'), value = pairModel(php);
  for (const phase of value.namespace_signal_pair.peer.sdk_signal_direction.phases) {
    for (const wire of phase.requests) if (wire.path.endsWith('/signal/payload')) wire.path = wire.path.replace(':peer', '%3Apeer');
  }
  value.namespace_signal_pair.foreign_signals[1].result.requests[0].path =
    value.namespace_signal_pair.foreign_signals[1].result.requests[0].path.replace(':peer', '%3Apeer');
  checkObservation(php, value, workflowId);
});
const mutations = {
  'same namespace': value => { value.namespace_signal_pair.peer.namespace = value.namespace; },
  'same original run': value => { value.namespace_signal_pair.peer.run_id = value.run_id; },
  'peer result leaks primary value': value => { value.namespace_signal_pair.peer.typed_output = copy(value.typed_output); },
  'peer rounded int64': value => { value.namespace_signal_pair.peer.typed_output.value.count.value = '9007199254740996'; },
  'peer package retuple': value => { value.namespace_signal_pair.peer.sdk_signal_direction.packages.rust.version = '3.5.0'; },
  'peer SDK source substitution': value => { value.namespace_signal_pair.peer.sdk_loaded_sources = {}; },
  'foreign signal admitted': value => { value.namespace_signal_pair.foreign_signals[0].result.requests[0].status = 202; },
  'foreign sender says admitted': value => { value.namespace_signal_pair.foreign_signals[0].result.signal_sent = true; },
  'foreign signal wrong namespace': value => { value.namespace_signal_pair.foreign_signals[0].result.requests[0].namespace = value.namespace; },
  'foreign signal run selector': value => { value.namespace_signal_pair.foreign_signals[0].result.requests[0].request.run_id = value.run_id; },
  'foreign signal untyped error': value => { value.namespace_signal_pair.foreign_signals[0].result.typed_error.class = 'RuntimeError'; },
  'foreign signal missing': value => { value.namespace_signal_pair.foreign_signals.pop(); },
  'foreign signal changes history': value => { value.namespace_signal_pair.foreign_signals[0].after[0].events.pop(); },
  'foreign signal changes cursor': value => { value.namespace_signal_pair.foreign_signals[0].after[0].execution.message_cursor_position = 3; },
  'foreign signal changes peer': value => { value.namespace_signal_pair.foreign_signals[0].after[1].status = 'running'; },
  'original peer was not waiting': value => { value.namespace_signal_pair.initial[1].status = 'running'; },
  'signal before peer wait': value => { [value.namespace_signal_pair.timeline[1], value.namespace_signal_pair.timeline[2]] = [value.namespace_signal_pair.timeline[2], value.namespace_signal_pair.timeline[1]]; },
  'original deadline refreshed': value => { value.namespace_signal_pair.run_budgets[0].deadline_at_unix += 35; },
  'original budget exceeded': value => { value.namespace_signal_pair.run_budgets[0].finished_at_unix = 1040; },
  'pair budget exceeded': value => { value.namespace_signal_pair.pair_finished_at_unix = 1071; },
  'foreign Worker claimed target': value => { value.namespace_signal_pair.foreign_polls[0].result.requests[1].response.task = copy(value.workflow_polls[1]); },
  'foreign Worker executed callback': value => { value.namespace_signal_pair.foreign_polls[0].result.workflow_invocations = 1; },
  'foreign Worker registration incompatible': value => { value.namespace_signal_pair.foreign_polls[0].result.requests[0].request.supported_workflow_types = ['other']; },
  'foreign Worker uses target namespace': value => { value.namespace_signal_pair.foreign_polls[0].result.requests[1].namespace = value.namespace; },
  'foreign Worker changes history': value => { value.namespace_signal_pair.foreign_polls[0].after[0].events.pop(); },
  'foreign Worker duplicate poll': value => { value.namespace_signal_pair.foreign_polls[0].result.requests.push(copy(value.namespace_signal_pair.foreign_polls[0].result.requests[1])); },
  'foreign Worker missing second probe': value => { value.namespace_signal_pair.foreign_polls.pop(); },
  'empty poll before target was ready': value => { value.namespace_signal_pair.foreign_polls[0].before[0] = copy(value.namespace_signal_pair.initial[0]); value.namespace_signal_pair.foreign_polls[0].after[0] = copy(value.namespace_signal_pair.initial[0]); },
  'cold author wrong namespace header': value => { value.sdk_signal_direction.phases[2].requests[1].namespace = value.namespace_signal_pair.peer.namespace; },
  'foreign process reused original': value => { value.namespace_signal_pair.foreign_polls[0].result.pid = value.sdk_signal_direction.phases[0].pid; },
};
for (const [name, mutate] of Object.entries(mutations)) test('refuse paired namespace ' + name, () => {
  const value = pairModel(fixture); mutate(value);
  assert.throws(() => checkObservation(fixture, value, workflowId));
});
for (const field of ['commands', 'tasks']) test('refuse embedded foreign mutation of ' + field, () => {
  const value = pairModel(fixture, 'embedded');
  value.namespace_signal_pair.foreign_signals[0].after[field].pop();
  assert.throws(() => checkObservation(fixture, value, workflowId));
});
