import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkSdkUpdateDirection, checkSdkUpdateDirectionArtifacts,
  checkSdkUpdateDirectionPackages} from '../../scripts/conformance/server-parity/sdk-update-direction-contract.mjs';

const profile = new URL('../Fixtures/ServerParityProfiles/published-sdk-updates/', import.meta.url);
const artifacts = JSON.parse(readFileSync(new URL('artifacts.json', profile)));
const languages = ['php', 'python', 'rust'], clone = value => structuredClone(value);
const envelope = blob => ({codec: 'avro', blob});
const fixtureFor = (author, client) => JSON.parse(readFileSync(new URL('update-sdk-' + author + '-' + client + '.json', profile)));
const failureProfile = new URL('../Fixtures/ServerParityPending/published-sdk-update-failures/', import.meta.url);
const failureArtifacts = JSON.parse(readFileSync(new URL('artifacts.json', failureProfile)));
const failureFixtureFor = (author, client) => JSON.parse(readFileSync(new URL('update-failure-sdk-' + author + '-' + client + '.json', failureProfile)));
const typedResult = (fixture, index) => ({type: 'map', value: {
  value: fixture.typed_update_values[index], applied: {type: 'int64', value: String(index + 1 - (fixture.sdk_update_failure ? 1 : 0))},
}});
const transport = {client_cancelled: false, request_body_complete: true, upstream_request_started: true, transport_error: null};
function model(fixture, mode = 'http') {
  const fails = Boolean(fixture.sdk_update_failure);
  const handlerFailure = {php: {exception_type: 'RuntimeException', exception_class: 'RuntimeException', message: 'parity update handler failure λ'},
    python: {exception_type: 'ValueError', exception_class: 'builtins.ValueError', message: 'update handler failed: parity update handler failure λ'},
    rust: {exception_type: 'UpdateFailed', exception_class: 'RuntimeException', message: 'worker loop error: parity update handler failure λ'}}[fixture.sdk_update_direction.workflow];
  const spec = fixture.sdk_update_direction, workflowId = 'update-model', runId = 'original-update-run';
  const events = (mode === 'http' ? fixture.expected_events : fixture.embedded_expected_events)
    .map((event_type, index) => ({event_type, sequence: index + 1,
      timestamp: '2026-10-12T01:00:' + String(index).padStart(2, '0') + '.000000Z', payload: {}}));
  events[1].payload.declared_updates = ['set_payload'];
  events.filter(event => event.event_type === 'UpdateAccepted').forEach((event, index) =>
    Object.assign(event.payload, {update_id: 'update-' + index, workflow_command_id: 'update-command-' + index}));
  events.filter(event => event.event_type === 'UpdateCompleted').forEach((event, index) => {
    if (fails && index === 0) Object.assign(event.payload, {failure_id: 'original-failure', ...handlerFailure});
    else event.payload.result = envelope('update-result-' + index);
  });
  const input = {type: 'list', value: [fixture.typed_value, {type: 'int64', value: '1'}]};
  const observation = {mode, workflow_id: workflowId, run_id: runId, workflow_type: fixture.workflow_type,
    namespace: 'default', task_queue: spec.task_queue, payload_codec: 'avro', events,
    typed_input: input, typed_output: fixture.typed_output, updates: [],
    execution: {id: runId, output_envelope: envelope('final-result')},
    sdk_php_source: artifacts.sdk_php_source_commit, sdk_composer_lock_sha256: artifacts.sdk_php_composer_lock_sha256,
    sdk_loaded_sources: clone(artifacts.sdk_php_loaded_sources)};
  if (mode === 'embedded') {
    observation.sdk_update_direction = {applicable: false, reason: 'embedded_executes_php_author_definitions'};
    observation.workflow_package = artifacts.workflow;
    observation.workflow_source = artifacts.embedded_image_path_reference;
    observation.embedded_composer_lock_sha256 = artifacts.embedded_image_composer_lock_sha256;
    observation.workflow_loaded_sources = clone(artifacts.workflow_loaded_sources);
    observation.updates = [{}, {}];
    return clone(observation);
  }
  const snapshot = (length, status = 'waiting') => ({workflow_id: workflowId, run_id: runId,
    workflow_type: fixture.workflow_type, namespace: 'default', task_queue: spec.task_queue, payload_codec: 'avro',
    status, typed_input: input, typed_output: status === 'completed' ? fixture.typed_output : {type: 'null', value: null},
    events: clone(events.slice(0, length)), execution: {id: runId, status, history_sequence: length,
      last_progress_at: 'checkpoint-' + length, message_cursor: length === 16 ? 3 : length >= 11 ? 2 : length >= 7 ? 1 : 0}});
  const workerEvents = events.map(({sequence, event_type, payload, timestamp}, index) => ({
    id: 'event-' + index, sequence, event_type, payload, recorded_at: timestamp,
    workflow_task_id: null, workflow_command_id: payload.workflow_command_id ?? null, namespace: 'default',
  }));
  const order = [];
  const trace = (event, phase, details = {}) => {
    const sequence = order.length + 1;
    order.push({sequence, event, phase, ...details});
    return sequence;
  };
  const phases = spec.phase_order.map((phase, index) => {
    const caller = /^(update|duplicate)/.test(phase);
    const language = phase === 'signal' ? 'php' : caller ? spec.client : spec.workflow;
    return {phase, language, pid: 100 + index, sdk_version: artifacts['sdk_' + language],
      workflow_id: workflowId, run_id: runId, requests: [], request_arrivals: [],
      snapshot: snapshot((fails ? [3, 6, 6, 6, 6, 10, 10, 10, 10, 11, 15] : [3, 7, 7, 7, 7, 11, 11, 11, 11, 12, 16])[index], index === 10 ? 'completed' : 'waiting')};
  });
  const tasks = [], completions = [];
  for (const [turn, index] of [0, 3, 7, 10].entries()) {
    const phase = phases[index], workerId = workflowId + ':' + phase.phase;
    Object.assign(phase, {worker_id: workerId, task_queue: spec.task_queue, worker_finished: true,
      workflow_invocations: spec.workflow === 'python' ? 1 : [1, 0, 0, 1][turn],
      update_invocations: spec.workflow === 'python' ? (fails ? [0, 1, 1, 1] : [0, 1, 2, 2])[turn] : [0, 1, 1, 0][turn],
      activity_invocations: 0, ...(spec.workflow === 'php' ? {workflow_completions: 1} : {})});
    phase.requests.push({...transport, method: 'POST', path: '/api/worker/register', status: 201,
      request: {worker_id: workerId, task_queue: spec.task_queue, supported_workflow_types: [fixture.workflow_type],
        supported_activity_types: [], workflow_command_contracts: {[fixture.workflow_type]: {updates: ['set_payload']}}},
      response: {registered: true}});
    if (turn === 0) phase.requests.push({...transport, method: 'POST', path: '/api/workflows', status: 201,
      request: {workflow_id: workflowId}, response: {run_id: runId}});
    const task = {task_id: 'task-' + turn, lease_owner: workerId, workflow_task_attempt: 1,
      workflow_id: workflowId, run_id: runId, workflow_type: fixture.workflow_type, task_queue: spec.task_queue,
      history_events: clone(workerEvents.slice(0, (fails ? [2, 4, 7, 11] : [2, 4, 8, 12])[turn])),
      ...(turn === 1 || turn === 2 ? {workflow_update_id: 'update-' + (turn - 1)} : {})};
    phase.requests.push({...transport, method: 'POST', path: '/api/worker/workflow-tasks/poll', status: 200,
      response: {task}});
    const command = fails && turn === 1 ? {type: 'fail_update', update_id: task.workflow_update_id,
      exception_type: handlerFailure.exception_type, ...(spec.workflow === 'python' ? {exception_class: handlerFailure.exception_class} : {}),
      message: handlerFailure.message, non_retryable: true}
      : turn === 0 ? {type: 'open_condition_wait', condition_key: 'payload:0'}
      : turn === 3 ? {type: 'complete_workflow', result: envelope('final-result')}
        : {type: 'complete_update', update_id: task.workflow_update_id, result: envelope('update-result-' + (turn - 1))};
    const complete = {...transport, method: 'POST', path: '/api/worker/workflow-tasks/' + task.task_id + '/complete',
      status: 200, request: {lease_owner: workerId, workflow_task_attempt: 1, commands: [command]},
      response: {task_id: task.task_id, run_id: runId, workflow_task_attempt: 1, recorded: true},
      decoded_commands: [{typed_decoded: turn === 0 || fails && turn === 1 ? {} : {result: turn === 3 ? fixture.typed_output : typedResult(fixture, turn - 1)}}]};
    phase.requests.push(complete);
    phase.checkpoint_workflow_ack = {path: complete.path, task_id: task.task_id, lease_owner: workerId,
      workflow_task_attempt: 1, request_index: phase.requests.indexOf(complete), status: 200};
    tasks.push(task); completions.push(complete);
  }
  for (const [number, indexes] of [[1, 2, 4], [5, 6, 8]].entries()) {
    const receipts = [];
    for (const index of indexes) {
      const phase = phases[index], original = phase.phase.startsWith('update-');
      const failed = fails && number === 0;
      const value = {value: fixture.update_values[number], applied: number + 1 - (fails ? 1 : 0)};
      const response = {workflow_id: workflowId, run_id: runId, update_name: 'set_payload', update_status: 'completed',
        command_id: 'update-command-' + number, update_id: 'update-' + number,
        result: null, result_envelope: envelope('update-result-' + number)};
      if (failed) Object.assign(response, {update_status: 'failed', outcome: 'update_failed', reason: null,
        failure_id: 'original-failure', failure_message: handlerFailure.message, result_envelope: null});
      const request = {input: envelope(original ? 'original-value-' + number : 'duplicate-value'),
        request_id: workflowId + ':update:' + number, wait_for: 'completed'};
      Object.assign(phase, {client_finished: true, update_sent: true, request_id: request.request_id,
        client_result: spec.client === 'python' ? {...response, result: value} : value,
        typed_client_result: spec.client === 'python' ? {type: 'map', value: {result: typedResult(fixture, number)}} : typedResult(fixture, number)});
      if (failed) {
        delete phase.client_result; delete phase.typed_client_result;
        phase.client_failure = {class: {php: 'DurableWorkflow\\Exception\\UpdateFailed', python: 'durable_workflow.errors.UpdateFailed',
          rust: 'durable_workflow::Error::Http'}[spec.client], message: 'actual SDK error', status: 422, body: response,
          ...(spec.client === 'php' ? {reason: null} : {}), ...(spec.client === 'python' ? {workflow_id: workflowId,
            run_id: runId, update_id: response.update_id, failure_id: response.failure_id} : {})};
      }
      const wire = {...transport, request_observation_id: 1, method: 'POST',
        path: '/api/workflows/' + workflowId + '/update/set_payload', status: failed ? 422 : 200, request, response,
        decoded_input: [original ? fixture.update_values[number] : fixture.duplicate_update_value],
        typed_decoded_input: {type: 'list', value: [original ? fixture.typed_update_values[number]
          : {type: 'map', value: {message: {type: 'string', value: 'duplicate must not apply'}}}]},
        decoded_result: value, typed_decoded_result: typedResult(fixture, number)};
      if (failed) { delete wire.decoded_result; delete wire.typed_decoded_result; }
      phase.requests.push(wire);
      if (!phase.phase.startsWith('duplicate-completed-')) {
        phase.pending_before_author = {client_running: true, completed_update_responses: 0};
        phase.submission = {schema: 'durable-workflow.server-parity-http-request-arrival/v1',
          request_observation_id: 1, method: wire.method, path: wire.path, request_body: JSON.stringify(request),
          request_body_complete: true, upstream_request_started: true, response_observed: false};
        phase.request_arrivals.push(clone(phase.submission));
        if (original) phase.acceptance_snapshot = snapshot(number === 0 ? 4 : fails ? 7 : 8);
        else {
          phase.snapshot_before = snapshot(number === 0 ? 4 : fails ? 7 : 8);
          phase.submission_snapshot = clone(phase.snapshot_before);
        }
      } else phase.snapshot_before = clone(phase.snapshot);
      receipts.push(response);
    }
    observation.updates.push(fails && number === 0 ? {failed_receipts: receipts} : {completed_receipts: receipts});
  }
  const sender = phases[9], signalResponse = {workflow_id: workflowId, run_id: runId,
    command_id: 'signal-command', outcome: 'signal_received'};
  Object.assign(sender, {signal_sent: true, sender_finished: true, snapshot_before: clone(phases[8].snapshot), client_result: signalResponse});
  sender.requests.push({...transport, method: 'POST', path: '/api/workflows/' + workflowId + '/signal/payload', status: 202,
    request: {input: envelope('finish-signal')}, response: signalResponse,
    typed_decoded_input: {type: 'list', value: [fixture.typed_signal_value]}});
  for (const phase of phases) {
    phase.started_order = trace('actor_started', phase.phase);
    if (phase.phase === 'original') trace('start_receipt_observed', phase.phase, {run_id: runId});
    if (phase.submission) {
      phase.submission_order = trace('request_arrival_observed', phase.phase, {request_observation_id: 1});
      if (phase.phase.startsWith('update-')) phase.acceptance_order = trace('server_acceptance_observed', phase.phase,
        {update_id: 'update-' + (Number(phase.phase.slice(-1)) - 1)});
    }
    if (phase.worker_finished) phase.checkpoint_ack_order = trace('checkpoint_receipt_observed', phase.phase,
      {task_id: phase.checkpoint_workflow_ack.task_id, request_index: phase.checkpoint_workflow_ack.request_index});
  }
  observation.workflow_polls = tasks; observation.workflow_completions = completions;
  observation.signal_deliveries = [{before: sender.snapshot_before.execution, response: signalResponse}];
  observation.sdk_update_direction = {applicable: true, phases, observer_order: order,
    observer_start: {role: 'observer', language: 'php', pid: 900, sdk_version: artifacts.sdk_php, run_id: runId},
    packages: {outcome: 'pass', rust: artifacts.published_sdk_artifacts.rust, python: artifacts.published_sdk_artifacts.python}};
  return clone(observation);
}
for (const author of languages) for (const client of languages) {
  const fixture = fixtureFor(author, client);
  for (const mode of ['http', 'embedded']) test(author + '/' + client + ' ' + mode + ' original update identities', () => {
    const observation = model(fixture, mode);
    checkSdkUpdateDirectionArtifacts(fixture, artifacts);
    checkSdkUpdateDirectionPackages(fixture, observation, artifacts);
    assert.deepEqual(checkSdkUpdateDirection(fixture, observation, 'update-model'),
      {two_ordered_updates: true, pending_and_completed_deduplication: true, exact_typed_completion: true});
  });
}
const fixture = fixtureFor('python', 'python');
const mutations = [
  ['reused process', o => { o.sdk_update_direction.phases[2].pid = 101; }],
  ['wrong SDK tuple', o => { o.sdk_update_direction.phases[1].sdk_version = 'new'; }],
  ['lost observer', o => { o.sdk_update_direction.observer_start.run_id = 'foreign'; }],
  ['missing registration', o => { o.sdk_update_direction.phases[0].requests.shift(); }],
  ['worker declaration omits updates', o => { o.sdk_update_direction.phases[0].requests[0].request.workflow_command_contracts[fixture.workflow_type].updates = []; }],
  ['pending duplicate has response fields', o => { o.sdk_update_direction.phases[2].submission.status = 202; }],
  ['pending client has already stopped', o => { o.sdk_update_direction.phases[2].pending_before_author.client_running = false; }],
  ['pending client already received a completion', o => { o.sdk_update_direction.phases[2].pending_before_author.completed_update_responses = 1; }],
  ['arrival falsely claims acceptance', o => { o.sdk_update_direction.phases[2].submission.response_observed = true; }],
  ['pending submission after author', o => { o.sdk_update_direction.phases[2].submission_order = 10; }],
  ['arrival bytes do not match original request', o => { o.sdk_update_direction.phases[2].submission.request_body = '{}'; }],
  ['arrival belongs to another wire', o => { o.sdk_update_direction.phases[2].submission.request_observation_id = 2; }],
  ['arrival missing from original journal', o => { o.sdk_update_direction.phases[2].request_arrivals = []; }],
  ['original update lacks durable acceptance snapshot', o => { o.sdk_update_direction.phases[1].acceptance_snapshot.events.pop(); }],
  ['pending duplicate creates another admission', o => { o.sdk_update_direction.phases[2].submission_snapshot.events.push({event_type: 'UpdateAccepted'}); }],
  ['completed duplicate changes run progress', o => { o.sdk_update_direction.phases[4].snapshot.execution.last_progress_at = 'changed'; }],
  ['client rounded int64', o => { o.sdk_update_direction.phases[1].typed_client_result.value.result.value.value.value.number.value = '9007199254740992'; }],
  ['duplicate returns its changed value', o => { o.sdk_update_direction.phases[2].requests[0].decoded_result.value = fixture.duplicate_update_value; }],
  ['hydrated Python loses update identity', o => { delete o.sdk_update_direction.phases[1].client_result.update_id; }],
  ['completed request presented as accepted', o => { o.sdk_update_direction.phases[1].requests[0].response.update_status = 'accepted'; }],
  ['duplicate uses a fresh request ID', o => { o.sdk_update_direction.phases[2].requests[0].request.request_id = 'new-request'; }],
  ['query-style unowned client path', o => { o.sdk_update_direction.phases[2].requests[0].path += '/runs/foreign'; }],
  ['client injects a worker claim', o => { o.sdk_update_direction.phases[2].requests.push({method: 'POST', path: '/api/worker/workflow-tasks/poll'}); }],
  ['foreign update task', o => { o.sdk_update_direction.phases[3].requests[1].response.task.workflow_update_id = 'foreign'; }],
  ['stale author lease', o => { o.sdk_update_direction.phases[3].requests[2].request.lease_owner = 'stale'; }],
  ['completion for another task', o => { o.sdk_update_direction.phases[3].requests[2].response.task_id = 'foreign'; }],
  ['completion not recorded', o => { o.sdk_update_direction.phases[3].requests[2].response.recorded = false; }],
  ['incomplete checkpoint request', o => { o.sdk_update_direction.phases[3].requests[2].request_body_complete = false; }],
  ['wrong checkpoint observation', o => { o.sdk_update_direction.phases[3].checkpoint_workflow_ack.request_index = 0; }],
  ['missing original event ID', o => { delete o.sdk_update_direction.phases[3].requests[1].response.task.history_events[0].id; }],
  ['cold worker changes earlier row', o => { o.sdk_update_direction.phases[7].requests[1].response.task.history_events[0].id = 'changed'; }],
  ['cold worker changes an additional original row field', o => { o.sdk_update_direction.phases[7].requests[1].response.task.history_events[0].changed = true; }],
  ['cold worker changes timestamp', o => { o.sdk_update_direction.phases[7].requests[1].response.task.history_events[0].recorded_at = 'changed'; }],
  ['cold worker loses command link', o => { delete o.sdk_update_direction.phases[7].requests[1].response.task.history_events[0].workflow_command_id; }],
  ['cold worker invents namespace', o => { o.sdk_update_direction.phases[7].requests[1].response.task.history_events[0].namespace = 'foreign'; }],
  ['Python replay callbacks hidden', o => { o.sdk_update_direction.phases[7].update_invocations = 1; }],
  ['replacement claims another history prefix', o => { o.sdk_update_direction.phases[10].requests[1].response.task.history_events.pop(); }],
  ['finishing signal changes exact input', o => { o.sdk_update_direction.phases[9].requests[0].typed_decoded_input = {type: 'null', value: null}; }],
  ['duplicate receipt missing', o => { o.updates[0].completed_receipts.pop(); }],
];
for (const [name, mutate] of mutations) test('rejects ' + name, () => {
  const observation = model(fixture); mutate(observation);
  assert.throws(() => checkSdkUpdateDirection(fixture, observation, 'update-model'));
});

for (const author of languages) for (const client of languages) {
  const failedFixture = failureFixtureFor(author, client);
  test(author + '/' + client + ' failed handler keeps original failure across cold checkpoints', () => {
    const observation = model(failedFixture);
    checkSdkUpdateDirectionArtifacts(failedFixture, failureArtifacts);
    checkSdkUpdateDirectionPackages(failedFixture, observation, failureArtifacts);
    assert.equal(checkSdkUpdateDirection(failedFixture, observation, 'update-model').failed_handler_preserved, true);
  });
}
const failedFixture = failureFixtureFor('python', 'python');
for (const [name, mutate] of [
  ['failed handler reported as success', o => { o.sdk_update_direction.phases[1].requests[0].status = 200; }],
  ['failure outcome changed to rejection', o => { o.sdk_update_direction.phases[1].requests[0].response.outcome = 'update_rejected'; }],
  ['failure ID changed on duplicate', o => { o.sdk_update_direction.phases[2].requests[0].response.failure_id = 'new-failure'; }],
  ['failed handler manufactures result', o => { o.sdk_update_direction.phases[1].requests[0].response.result_envelope = envelope('invented'); }],
  ['SDK swallows failed outcome', o => { delete o.sdk_update_direction.phases[1].client_failure; }],
  ['SDK failure loses original response', o => { o.sdk_update_direction.phases[1].client_failure.body = {}; }],
  ['failure changes typed SDK class', o => { o.sdk_update_direction.phases[1].client_failure.class = 'GenericError'; }],
  ['failure loses SDK identity', o => { delete o.sdk_update_direction.phases[1].client_failure.failure_id; }],
  ['failed handler has a successful command', o => { o.sdk_update_direction.phases[3].requests[2].request.commands[0].type = 'complete_update'; }],
  ['failed handler changes original message', o => { o.sdk_update_direction.phases[3].requests[2].request.commands[0].message = 'changed'; }],
  ['failed handler changes original exception class', o => { o.sdk_update_direction.phases[3].requests[2].request.commands[0].exception_class = 'Changed'; }],
  ['failed handler changes retry policy', o => { o.sdk_update_direction.phases[3].requests[2].request.commands[0].non_retryable = false; }],
  ['failed callback replayed as successful state', o => { o.sdk_update_direction.phases[7].update_invocations = 2; }],
  ['failure duplicate receipt missing', o => { o.updates[0].failed_receipts.pop(); }],
]) test('rejects ' + name, () => {
  const observation = model(failedFixture); mutate(observation);
  assert.throws(() => checkSdkUpdateDirection(failedFixture, observation, 'update-model'));
});
