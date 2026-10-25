import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkPublishedSdkPackages} from './sdk-direction-contract.mjs';

const artifacts = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/ServerParityProfiles/published-sdk-queries/artifacts.json', import.meta.url), 'utf8'));
const languages = ['php', 'python', 'rust'];
const phases = ['original', 'query-0', 'signal-1', 'resumed', 'query-1', 'signal-2', 'replacement', 'query-2'];
const nonempty = value => assert(typeof value === 'string' && value.length > 0);
const history = events => events.map(({sequence, event_type, payload}) => ({sequence, event_type, payload}));
const publicHistory = events => events.map(({sequence, event_type, payload, timestamp}) => ({sequence, event_type, payload, timestamp}));
const workerHistory = events => events.map(({id, sequence, event_type, payload, workflow_task_id, workflow_command_id, recorded_at}) =>
  ({id, sequence, event_type, payload, workflow_task_id, workflow_command_id, recorded_at}));
function checkWorkerHistory(events) {
  for (const event of events) {
    nonempty(event.id); nonempty(event.recorded_at);
    for (const field of ['workflow_task_id', 'workflow_command_id']) {
      assert(Object.hasOwn(event, field), 'worker history retains ' + field);
      if (event[field] !== null) nonempty(event[field]);
    }
    if (Object.hasOwn(event, 'namespace')) assert.equal(event.namespace, 'default');
  }
  assert.equal(new Set(events.map(event => event.id)).size, events.length, 'unique original worker event IDs');
}
const frame = value => {
  if (typeof value === 'string') { nonempty(value); return value; }
  assert.equal(value?.codec, 'avro'); nonempty(value?.blob); return value.blob;
};
const typedState = (fixture, count) => ({type: 'map', value: {
  received: {type: 'int64', value: String(count)},
  last: count === 0 ? {type: 'null', value: null} : fixture.typed_signal_value,
}});
const state = (fixture, count) => ({received: count, last: count === 0 ? null : fixture.signal_value});
const one = (requests, predicate, message) => {
  const selected = requests.filter(predicate);
  assert.equal(selected.length, 1, message);
  return selected[0];
};
// A wrong status names the request and its observer transport flags, never
// payload bytes. Status 0 means no upstream status arrived, and the flags say
// whether the client closed first or the upstream connection failed.
function wireStatus(item, expected, label) {
  if (item.status === expected) return;
  const {method, path, status, client_cancelled, transport_error, request_body_complete, upstream_request_started} = item;
  assert.fail(`${label}: expected HTTP ${expected}, observed ${status} for ${method} ${path} `
    + JSON.stringify({client_cancelled, transport_error, request_body_complete, upstream_request_started}));
}
function unchanged(before, after, message) {
  assert.deepEqual(after, before, message);
}
function unchangedDurableSnapshot(before, after, message) {
  const hasAvailability = snapshot => Object.hasOwn(snapshot.execution?.actions ?? {}, 'can_query');
  assert.equal(hasAvailability(after), hasAvailability(before), 'computed query availability field stays present');
  const durable = snapshot => {
    // definition_routing (Server 2.10.0 and native) reports how the next ready
    // workflow task would route now, so worker presence changes it without
    // touching the run or its history.
    const {definition_routing: _routing, ...execution} = snapshot.execution ?? {};
    const stable = snapshot.execution ? {...snapshot, execution} : snapshot;
    if (!hasAvailability(stable)) return stable;
    assert.equal(typeof stable.execution.actions.can_query, 'boolean', 'actual computed query availability');
    const actions = {...stable.execution.actions};
    delete actions.can_query; // Only live worker availability; complete raw value stays recorded.
    return {...stable, execution: {...stable.execution, actions}};
  };
  unchanged(durable(before), durable(after), message);
}

export function checkSdkQueryDirectionArtifacts(fixture, selected) {
  if (!fixture.sdk_query_direction) return;
  assert.deepEqual(selected, artifacts, 'complete reviewed published query tuple');
  const spec = fixture.sdk_query_direction;
  assert(languages.includes(spec.workflow) && languages.includes(spec.client));
  assert.deepEqual(spec, {workflow: spec.workflow, client: spec.client, phase_order: phases,
    task_queue: 'server-parity-sdk-queries', query_name: 'state', target: 'instance-current-run',
    start_after_worker_registration: true, first_poll_after_start_receipt: true,
    start_client: 'php-observer', signal_client: 'php', signal_name: 'payload',
    query_arguments: [], cold_author_processes: 3, query_client_processes: 3});
  assert.equal(fixture.id, 'query-sdk-' + spec.workflow + '-' + spec.client);
  assert.equal(fixture.workflow_type, 'parity.v1.sdk_queries');
  assert.equal(fixture.activity, false); assert.equal(fixture.signal_count, 2);
  assert.equal(fixture.query_name, 'state');
  assert.deepEqual(fixture.query_arguments, []);
  assert.deepEqual(fixture.typed_query_arguments, {type: 'list', value: []});
  assert.deepEqual(fixture.query_checkpoints, [{after_signal_count: 0, status: 'waiting'},
    {after_signal_count: 1, status: 'waiting'}, {after_signal_count: 2, status: 'completed'}]);
  assert.equal(fixture.typed_signal_value.value.count.value, '9007199254740993');
}

export function checkSdkQueryDirectionPackages(fixture, observation, selected) {
  if (!fixture.sdk_query_direction) return;
  checkSdkQueryDirectionArtifacts(fixture, selected);
  checkPublishedSdkPackages(observation, selected, observation.sdk_query_direction);
}

export function checkSdkQueryDirection(fixture, observation, workflowId) {
  if (!fixture.sdk_query_direction) return null;
  checkSdkQueryDirectionArtifacts(fixture, artifacts);
  const spec = fixture.sdk_query_direction, direction = observation.sdk_query_direction;
  const projection = {three_fresh_readonly_queries: true, exact_typed_completion: true};
  assert.deepEqual(observation.events[1].payload.declared_queries, ['state'], 'durable query declaration');
  if (observation.mode === 'embedded') {
    assert.deepEqual(direction, {applicable: false, reason: 'embedded_executes_php_author_definitions'});
    assert.equal(observation.queries.length, 3);
    for (const [count, query] of observation.queries.entries()) {
      assert.equal(query.name, 'state');
      assert.deepEqual(query.arguments, []);
      assert.deepEqual(query.typed_arguments, fixture.typed_query_arguments);
      assert.equal(query.before.status, count === 2 ? 'completed' : 'waiting');
      assert.equal(query.before.id, observation.run_id);
      unchanged(query.before, query.after, 'embedded query leaves complete run state unchanged');
      unchanged(query.history_before, query.history_after, 'embedded query leaves complete original history unchanged');
      assert.equal(query.history_before.length, [3, 7, 11][count]);
      assert.deepEqual(history(query.history_before), history(observation.events.slice(0, [3, 7, 11][count])));
      assert.deepEqual(query.result, state(fixture, count));
      assert.deepEqual(query.typed_result, typedState(fixture, count));
    }
    return projection;
  }
  assert.equal(direction.applicable, true);
  assert.deepEqual(direction.phases.map(item => item.phase), phases);
  assert.equal(new Set(direction.phases.map(item => item.pid)).size, 8, 'eight distinct actual SDK processes');
  const observer = direction.observer_start;
  assert.equal(observer.role, 'observer'); assert.equal(observer.language, 'php');
  assert.equal(observer.sdk_version, artifacts.sdk_php);
  assert(Number.isInteger(observer.pid) && observer.pid > 0);
  assert(!direction.phases.some(item => item.pid === observer.pid));
  assert.equal(observer.run_id, observation.run_id);
  const queryIds = [], workflowTasks = [], workflowCompletions = [], signalIds = [];
  let priorQueryHistory = [];
  for (const [index, phase] of direction.phases.entries()) {
    const caller = phase.phase.startsWith('query-');
    const sender = phase.phase.startsWith('signal-');
    const language = sender ? 'php' : (caller ? spec.client : spec.workflow);
    assert.equal(phase.language, language);
    assert.equal(phase.sdk_version, artifacts['sdk_' + language]);
    assert(Number.isInteger(phase.pid) && phase.pid > 0);
    assert.equal(phase.workflow_id, workflowId);
    assert.equal(phase.run_id, observation.run_id);
    assert.equal(phase.worker_error, undefined);
    assert.equal(phase.observer_error, undefined);
    const snapshot = phase.snapshot;
    for (const key of ['workflow_id', 'run_id', 'workflow_type', 'namespace', 'task_queue', 'payload_codec']) {
      assert.equal(snapshot[key], observation[key], 'same original query run ' + key);
    }
    assert.deepEqual(snapshot.typed_input, observation.typed_input);
    const length = [3, 3, 4, 8, 8, 9, 13, 13][index];
    assert.equal(snapshot.events.length, length);
    assert.deepEqual(snapshot.events, observation.events.slice(0, length), 'full original history prefix');
    assert.deepEqual(snapshot.typed_output, index >= 6 ? fixture.typed_signal_value : {type: 'null', value: null});
    if (sender) {
      assert.equal(phase.sender_finished, true); assert.equal(phase.signal_sent, true);
      assert(!phase.requests.some(item => item.path.startsWith('/api/worker/')));
      assert(!phase.requests.some(item => item.method === 'POST' && item.path === '/api/workflows'));
      const wire = one(phase.requests, item => item.method === 'POST'
        && item.path === '/api/workflows/' + workflowId + '/signal/payload', 'one actual signal stimulus');
      wireStatus(wire, 202, phase.phase + ' signal');
      assert.equal(wire.response.run_id, observation.run_id);
      assert.equal(wire.response.workflow_id, workflowId);
      assert.equal(wire.response.outcome, 'signal_received');
      nonempty(wire.response.command_id); signalIds.push(wire.response.command_id);
      assert.deepEqual(phase.client_result, wire.response);
      assert.deepEqual(wire.typed_decoded_input, {type: 'list', value: [fixture.typed_signal_value]});
      frame(wire.request.input);
      assert.deepEqual(phase.snapshot_before, direction.phases[index - 1].snapshot);
      continue;
    }
    assert.equal(snapshot.status, index >= 6 ? 'completed' : 'waiting');
    if (caller) {
      const count = Number(phase.phase.slice(-1));
      assert.equal(phase.query_sent, true); assert.equal(phase.client_finished, true);
      assert(!phase.requests.some(item => item.path.startsWith('/api/worker/')));
      assert(!phase.requests.some(item => item.method === 'POST' && item.path === '/api/workflows'));
      unchangedDurableSnapshot(phase.snapshot_before, snapshot, 'query leaves every original durable field/history unchanged');
      unchangedDurableSnapshot(phase.snapshot_before, direction.phases[index - 1].snapshot, 'same acknowledged author snapshot');
      const wire = one(phase.requests, item => item.method === 'POST'
        && item.path === '/api/workflows/' + workflowId + '/query/state', 'one normal instance-scoped query');
      wireStatus(wire, 200, phase.phase + ' query');
      assert.equal(wire.response.workflow_id, workflowId);
      assert.equal(wire.response.run_id, observation.run_id);
      assert.equal(wire.response.query_name, 'state');
      assert.equal(wire.response.reason, null);
      assert.deepEqual(wire.typed_decoded_input, fixture.typed_query_arguments);
      assert.deepEqual(wire.decoded_result, state(fixture, count));
      assert.deepEqual(wire.typed_decoded_result, typedState(fixture, count));
      if (language === 'python') {
        assert.deepEqual(wire.request, {}, 'published zero-argument Python omits input');
        assert.deepEqual(phase.client_result, {...wire.response,
          result: wire.response.result ?? wire.decoded_result}, 'actual hydrated Python response object');
        assert.deepEqual(phase.typed_client_result.value.result, typedState(fixture, count));
      } else {
        assert.deepEqual(Object.keys(wire.request), ['input']);
        frame(wire.request.input);
        assert.deepEqual(phase.client_result, state(fixture, count));
        assert.deepEqual(phase.typed_client_result, typedState(fixture, count));
      }
      const author = direction.phases[index - 1];
      const claim = one(author.requests, item => item.path === '/api/worker/query-tasks/poll'
        && item.response?.task, 'one actual query worker claim');
      const task = claim.response.task;
      assert.equal(claim.method, 'POST'); wireStatus(claim, 200, author.phase + ' query claim');
      assert.equal(claim.request.task_queue, spec.task_queue);
      assert.equal(task.workflow_id, workflowId); assert.equal(task.run_id, observation.run_id);
      assert.equal(task.workflow_type, fixture.workflow_type); assert.equal(task.query_name, 'state');
      assert.equal(task.task_queue, spec.task_queue);
      assert.equal(task.query_task_attempt, 1);
      nonempty(task.query_task_id); nonempty(task.lease_owner); queryIds.push(task.query_task_id);
      checkWorkerHistory(task.history_events);
      assert.deepEqual(publicHistory(task.history_events.map(event => ({...event, timestamp: event.recorded_at}))),
        publicHistory(snapshot.events), 'complete immutable query public history and timestamps');
      const workflowHistory = workflowTasks.at(-1).history_events;
      assert.deepEqual(workerHistory(task.history_events.slice(0, workflowHistory.length)), workerHistory(workflowHistory),
        'query retains actual workflow claim row IDs, links and timestamps');
      assert.deepEqual(task.history_events.slice(0, priorQueryHistory.length), priorQueryHistory,
        'repeated query retains complete earlier worker history rows');
      priorQueryHistory = task.history_events;
      assert.deepEqual(claim.typed_query_arguments, fixture.typed_query_arguments);
      assert.deepEqual(claim.typed_workflow_arguments, {type: 'list', value: [
        fixture.typed_value, {type: 'int64', value: '2'}]});
      frame(task.query_arguments); frame(task.workflow_arguments);
      if (wire.request.input) assert.equal(frame(wire.request.input), frame(task.query_arguments));
      const complete = one(author.requests, item => item.method === 'POST'
        && item.path === '/api/worker/query-tasks/' + task.query_task_id + '/complete', 'actual original query completion');
      wireStatus(complete, 200, author.phase + ' query completion');
      assert.equal(complete.request.lease_owner, task.lease_owner);
      assert.equal(complete.request.query_task_attempt, task.query_task_attempt);
      assert.equal(complete.response.query_task_id, task.query_task_id);
      assert.equal(complete.response.query_task_attempt, task.query_task_attempt);
      assert.equal(complete.response.outcome, 'completed');
      if (Object.hasOwn(complete.response, 'reason')) assert.equal(complete.response.reason, null);
      assert.equal(complete.response.status, 200);
      assert.equal(frame(complete.request.result_envelope), frame(wire.response.result_envelope));
      assert.deepEqual(complete.decoded_result, state(fixture, count));
      assert.deepEqual(complete.typed_decoded_result, typedState(fixture, count));
      continue;
    }
    assert.equal(phase.worker_finished, true);
    assert.equal(phase.worker_id, workflowId + ':' + phase.phase);
    assert.equal(phase.task_queue, spec.task_queue);
    assert.equal(phase.workflow_invocations, language === 'python' ? 2 : 1, 'actual language-specific workflow/query replay callbacks');
    assert.equal(phase.query_invocations, 1); assert.equal(phase.activity_invocations, 0);
    if (language === 'php') assert.equal(phase.query_completions, 1);
    const register = one(phase.requests, item => item.method === 'POST'
      && item.path === '/api/worker/register', 'one ordinary worker registration');
    wireStatus(register, 201, phase.phase + ' registration'); assert.equal(register.response.registered, true);
    assert.equal(register.request.worker_id, phase.worker_id);
    assert.equal(register.request.task_queue, spec.task_queue);
    assert.deepEqual(register.request.supported_workflow_types, [fixture.workflow_type]);
    assert.deepEqual(register.request.supported_activity_types, []);
    assert.deepEqual(register.request.workflow_command_contracts[fixture.workflow_type].queries, ['state']);
    const claim = one(phase.requests, item => item.path === '/api/worker/workflow-tasks/poll'
      && item.response?.task, 'one original workflow claim per cold author');
    const task = claim.response.task;
    wireStatus(claim, 200, phase.phase + ' workflow claim');
    for (const key of ['workflow_id', 'run_id', 'workflow_type', 'task_queue']) assert.equal(task[key], observation[key]);
    assert.equal(task.workflow_task_attempt, 1); nonempty(task.task_id); nonempty(task.lease_owner);
    checkWorkerHistory(task.history_events);
    assert.deepEqual(workerHistory(task.history_events.slice(0, priorQueryHistory.length)), workerHistory(priorQueryHistory),
      'later workflow claim retains earlier query row IDs, links and timestamps');
    const turn = [0, 3, 6].indexOf(index);
    assert.deepEqual(history(task.history_events), history(observation.events.slice(0, [2, 4, 9][turn])));
    const complete = one(phase.requests, item => item.method === 'POST'
      && item.path === '/api/worker/workflow-tasks/' + task.task_id + '/complete', 'original fenced workflow completion');
    wireStatus(complete, 200, phase.phase + ' workflow completion'); assert.equal(complete.response.recorded, true);
    assert.equal(complete.response.task_id, task.task_id); assert.equal(complete.response.run_id, observation.run_id);
    assert.equal(complete.request.lease_owner, task.lease_owner);
    assert.equal(complete.request.workflow_task_attempt, task.workflow_task_attempt);
    assert.equal(complete.request.commands.length, 1);
    assert.equal(complete.request.commands[0].type, turn === 2 ? 'complete_workflow' : 'open_condition_wait');
    if (turn === 2) {
      assert.deepEqual(complete.decoded_commands[0].typed_decoded.result, fixture.typed_signal_value);
      assert.equal(frame(complete.request.commands[0].result), frame(observation.execution.output_envelope));
    } else assert.equal(complete.request.commands[0].condition_key, 'payload:' + turn);
    if (turn === 0) {
      const start = one(phase.requests, item => item.method === 'POST'
        && item.path === '/api/workflows', 'one real observer start');
      wireStatus(start, 201, phase.phase + ' start');
      assert.equal(start.request.workflow_id, workflowId); assert.equal(start.response.run_id, observation.run_id);
      assert(phase.requests.indexOf(register) < phase.requests.indexOf(start));
      assert(phase.requests.indexOf(start) < phase.requests.indexOf(claim), 'original first claim after successful start receipt');
    } else assert(!phase.requests.some(item => item.path === '/api/workflows'));
    assert.deepEqual(phase.checkpoint_workflow_ack, {path: complete.path, task_id: task.task_id,
      lease_owner: task.lease_owner, workflow_task_attempt: task.workflow_task_attempt,
      request_index: phase.requests.indexOf(complete), status: 200}, 'matching workflow receipt observed before snapshot');
    workflowTasks.push(task); workflowCompletions.push(complete);
  }
  assert.equal(new Set(queryIds).size, 3, 'each repeated query receives its own actual task');
  assert.equal(new Set(workflowTasks.map(item => item.task_id)).size, 3);
  assert.equal(new Set(signalIds).size, 2);
  assert.deepEqual(observation.workflow_polls, workflowTasks);
  assert.deepEqual(observation.workflow_completions, workflowCompletions);
  assert.equal(observation.signal_deliveries.length, 2);
  for (const [i, delivery] of observation.signal_deliveries.entries()) {
    assert.equal(delivery.response.command_id, signalIds[i]);
    assert.equal(observation.events[i === 0 ? 3 : 8].payload.workflow_command_id, signalIds[i]);
  }
  return projection;
}
