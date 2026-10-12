import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkPublishedSdkPackages} from './sdk-direction-contract.mjs';

const artifacts = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/ServerParityProfiles/published-sdk-updates/artifacts.json', import.meta.url)));
const failureArtifacts = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/ServerParityPending/published-sdk-update-failures/artifacts.json', import.meta.url)));
const artifactsFor = fixture => fixture.sdk_update_failure ? failureArtifacts : artifacts;
const languages = ['php', 'python', 'rust'];
const phases = ['original', 'update-1', 'duplicate-pending-1', 'resumed-1', 'duplicate-completed-1',
  'update-2', 'duplicate-pending-2', 'resumed-2', 'duplicate-completed-2', 'signal', 'replacement'];
const nonempty = value => assert(typeof value === 'string' && value.length > 0);
const frame = value => {
  if (typeof value === 'string') { nonempty(value); return value; }
  assert.equal(value?.codec, 'avro'); nonempty(value?.blob); return value.blob;
};
const one = (items, predicate, label) => {
  const matches = items.filter(predicate);
  assert.equal(matches.length, 1, label);
  return matches[0];
};
const publicHistory = events => events.map(({sequence, event_type, payload, timestamp}) =>
  ({sequence, event_type, payload, timestamp}));
const appliedCount = (fixture, index) => index + 1 - (fixture.sdk_update_failure ? 1 : 0);
const resultFor = (fixture, index) => ({value: fixture.update_values[index], applied: appliedCount(fixture, index)});
const typedResultFor = (fixture, index) => ({type: 'map', value: {
  value: fixture.typed_update_values[index], applied: {type: 'int64', value: String(appliedCount(fixture, index))},
}});
function checkTransport(wire) {
  assert.equal(wire.client_cancelled, false);
  assert.equal(wire.request_body_complete, true);
  assert.equal(wire.upstream_request_started, true);
  assert.equal(wire.transport_error, null);
}
function checkRows(events) {
  for (const event of events) {
    nonempty(event.id); nonempty(event.recorded_at);
    for (const field of ['workflow_task_id', 'workflow_command_id']) {
      assert(Object.hasOwn(event, field), 'complete worker row retains ' + field);
      if (event[field] !== null) nonempty(event[field]);
    }
    if (Object.hasOwn(event, 'namespace')) assert.equal(event.namespace, 'default');
  }
  assert.equal(new Set(events.map(event => event.id)).size, events.length);
}
function checkSnapshot(snapshot, observation, length, status) {
  for (const field of ['workflow_id', 'run_id', 'workflow_type', 'namespace', 'task_queue', 'payload_codec']) {
    assert.equal(snapshot[field], observation[field], 'original update snapshot ' + field);
  }
  assert.equal(snapshot.status, status);
  assert.equal(snapshot.events.length, length);
  assert.deepEqual(snapshot.events, observation.events.slice(0, length), 'full original update history prefix');
  assert.deepEqual(snapshot.typed_input, observation.typed_input);
  assert.deepEqual(snapshot.typed_output, status === 'completed' ? observation.typed_output : {type: 'null', value: null});
}
function checkSubmission(phase, wire, order, author) {
  assert.deepEqual(phase.pending_before_author, {client_running: true, completed_update_responses: 0},
    'both completed-wait clients are still pending before their cold author');
  const arrival = phase.submission;
  assert.deepEqual(Object.keys(arrival).sort(), ['schema', 'request_observation_id', 'method', 'path',
    'request_body', 'request_body_complete', 'upstream_request_started', 'response_observed'].sort(),
  'arrival evidence contains no invented response or acceptance');
  assert.equal(arrival.schema, 'durable-workflow.server-parity-http-request-arrival/v1');
  assert(Number.isInteger(arrival.request_observation_id) && arrival.request_observation_id > 0);
  assert.equal(arrival.method, wire.method); assert.equal(arrival.path, wire.path);
  assert.equal(arrival.request_observation_id, wire.request_observation_id);
  assert.equal(arrival.request_body_complete, true); assert.equal(arrival.upstream_request_started, true);
  assert.equal(arrival.response_observed, false);
  assert.deepEqual(JSON.parse(arrival.request_body), wire.request, 'complete submitted bytes match final original wire');
  assert.deepEqual(one(phase.request_arrivals, item => item.request_observation_id === arrival.request_observation_id,
    'one original arrival'), arrival);
  const event = one(order, item => item.sequence === phase.submission_order, 'actual submission observation order');
  assert.deepEqual(event, {sequence: phase.submission_order, event: 'request_arrival_observed',
    phase: phase.phase, request_observation_id: arrival.request_observation_id});
  assert(phase.started_order < phase.submission_order && phase.submission_order < author.started_order,
    'original/pending request is submitted before the cold author starts');
}

export function checkSdkUpdateDirectionArtifacts(fixture, selected) {
  if (!fixture.sdk_update_direction) return;
  assert.deepEqual(selected, artifactsFor(fixture), 'complete reviewed update tuple');
  const spec = fixture.sdk_update_direction;
  assert(languages.includes(spec.workflow) && languages.includes(spec.client));
  assert.deepEqual(spec, {workflow: spec.workflow, client: spec.client, phase_order: phases,
    task_queue: fixture.sdk_update_failure ? 'server-parity-sdk-update-failures' : 'server-parity-sdk-updates', update_name: 'set_payload', target: 'instance-current-run',
    start_after_worker_registration: true, first_poll_after_start_receipt: true,
    start_client: 'php-observer', signal_client: 'php', signal_name: 'payload',
    cold_author_processes: 4, update_client_processes: 6, pending_submission_before_author: true});
  assert.equal(fixture.id, (fixture.sdk_update_failure ? 'update-failure-sdk-' : 'update-sdk-') + spec.workflow + '-' + spec.client);
  assert.equal(fixture.workflow_type, 'parity.v1.sdk_updates');
  assert.equal(fixture.update_name, 'set_payload'); assert.equal(fixture.activity, false);
  assert.equal(fixture.signal_count, 1); assert.equal(fixture.update_values.length, 2);
  assert.equal(fixture.typed_update_values[0].value.number.value, '9007199254740993');
  if (fixture.sdk_update_failure) {
    assert.deepEqual(fixture.sdk_update_failure, {failed_index: 0,
      handler_message: 'parity update handler failure λ', mutation_before_failure: false});
    assert.equal(fixture.update_values[0].fail_handler, true);
    assert.equal(fixture.typed_update_values[0].value.fail_handler.value, true);
    assert.deepEqual(fixture.output, {values: [fixture.update_values[1]]});
    assert.deepEqual(fixture.typed_output, {type: 'map', value: {values: {type: 'list', value: [fixture.typed_update_values[1]]}}});
  }
}
export function checkSdkUpdateDirectionPackages(fixture, observation, selected) {
  if (!fixture.sdk_update_direction) return;
  checkSdkUpdateDirectionArtifacts(fixture, selected);
  checkPublishedSdkPackages(observation, selected, observation.sdk_update_direction);
}
export function checkSdkUpdateDirection(fixture, observation, workflowId) {
  if (!fixture.sdk_update_direction) return null;
  checkSdkUpdateDirectionArtifacts(fixture, artifactsFor(fixture));
  const fails = Boolean(fixture.sdk_update_failure);
  const direction = observation.sdk_update_direction, spec = fixture.sdk_update_direction;
  const projection = {two_ordered_updates: true, pending_and_completed_deduplication: true, exact_typed_completion: true,
    ...(fails ? {failed_handler_preserved: true, parent_continues: true} : {})};
  assert.deepEqual(observation.events[1].payload.declared_updates, ['set_payload']);
  if (observation.mode === 'embedded') {
    assert.deepEqual(direction, {applicable: false, reason: 'embedded_executes_php_author_definitions'});
    assert.equal(observation.updates.length, 2);
    return projection; // The common reader checks actual PHP update methods and full lifecycle.
  }
  assert.equal(direction.applicable, true);
  assert.deepEqual(direction.phases.map(item => item.phase), phases);
  assert.equal(new Set(direction.phases.map(item => item.pid)).size, 11, 'eleven separate actual SDK processes');
  const observer = direction.observer_start, order = direction.observer_order;
  assert.deepEqual(observer, {role: 'observer', language: 'php', pid: observer.pid,
    sdk_version: artifacts.sdk_php, run_id: observation.run_id});
  assert(Number.isInteger(observer.pid) && observer.pid > 0);
  assert(!direction.phases.some(item => item.pid === observer.pid));
  assert.deepEqual(order.map(item => item.sequence), order.map((_, index) => index + 1));
  assert.equal(order.length, 22, 'complete observer causal inventory');
  assert.deepEqual(order.filter(item => item.event === 'actor_started').map(item => item.phase), phases);
  const tasks = [], completions = [], deliveries = [], receipts = [[], []];
  let priorRows = [];
  for (const [index, phase] of direction.phases.entries()) {
    const caller = /^(update|duplicate-pending|duplicate-completed)-/.test(phase.phase);
    const sender = phase.phase === 'signal';
    const language = sender ? 'php' : caller ? spec.client : spec.workflow;
    assert.equal(phase.language, language); assert.equal(phase.sdk_version, artifacts['sdk_' + language]);
    assert(Number.isInteger(phase.pid) && phase.pid > 0);
    assert.equal(phase.workflow_id, workflowId); assert.equal(phase.run_id, observation.run_id);
    assert.equal(phase.worker_error, undefined); assert.equal(phase.observer_error, undefined);
    assert.deepEqual(one(order, event => event.sequence === phase.started_order, 'original actor start'),
      {sequence: phase.started_order, event: 'actor_started', phase: phase.phase});
    checkSnapshot(phase.snapshot, observation, (fails ? [3, 6, 6, 6, 6, 10, 10, 10, 10, 11, 15]
      : [3, 7, 7, 7, 7, 11, 11, 11, 11, 12, 16])[index],
      index === 10 ? 'completed' : 'waiting');
    if (caller || sender) {
      assert(!phase.requests.some(wire => wire.path.startsWith('/api/worker/')), 'ordinary independent client never authors a worker task');
      assert(!phase.requests.some(wire => wire.method === 'POST' && wire.path === '/api/workflows'), 'no new run from a duplicate');
      const path = '/api/workflows/' + workflowId + (sender ? '/signal/payload' : '/update/set_payload');
      const wire = one(phase.requests, wire => wire.method === 'POST' && wire.path === path, 'one actual original control request');
      checkTransport(wire);
      assert.equal(wire.response.workflow_id, workflowId); assert.equal(wire.response.run_id, observation.run_id);
      frame(wire.request.input);
      if (sender) {
        assert.equal(phase.signal_sent, true); assert.equal(phase.sender_finished, true);
        assert.equal(wire.status, 202); assert.equal(wire.response.outcome, 'signal_received');
        assert.deepEqual(phase.client_result, wire.response);
        assert.deepEqual(wire.typed_decoded_input, {type: 'list', value: [fixture.typed_signal_value]});
        assert.deepEqual(phase.snapshot_before, direction.phases[8].snapshot);
        deliveries.push({before: phase.snapshot_before.execution, response: wire.response});
        continue;
      }
      const number = Number(phase.phase.slice(-1)) - 1;
      const original = phase.phase.startsWith('update-');
      const author = direction.phases[number === 0 ? 3 : 7];
      assert.equal(phase.update_sent, true); assert.equal(phase.client_finished, true);
      assert.equal(phase.request_id, workflowId + ':update:' + number);
      assert.deepEqual(Object.keys(wire.request).sort(), ['input', 'request_id', 'wait_for'].sort());
      assert.equal(wire.request.request_id, phase.request_id); assert.equal(wire.request.wait_for, 'completed');
      const failed = fails && number === 0;
      assert.equal(wire.status, failed ? 422 : 200); assert.equal(wire.response.update_status, failed ? 'failed' : 'completed');
      assert.equal(wire.response.update_name, 'set_payload');
      const accepted = observation.events.filter(item => item.event_type === 'UpdateAccepted')[number];
      const completed = observation.events.filter(item => item.event_type === 'UpdateCompleted')[number];
      assert.equal(wire.response.command_id, accepted.payload.workflow_command_id);
      assert.equal(wire.response.update_id, accepted.payload.update_id);
      assert.deepEqual(wire.decoded_input, [original ? fixture.update_values[number] : fixture.duplicate_update_value]);
      assert.deepEqual(wire.typed_decoded_input, {type: 'list', value: [original ? fixture.typed_update_values[number]
        : {type: 'map', value: {message: {type: 'string', value: 'duplicate must not apply'}}}]});
      if (failed) {
        assert.equal(wire.response.outcome, 'update_failed');
        assert.equal(wire.response.reason, null);
        assert.equal(wire.response.failure_id, completed.payload.failure_id);
        assert.equal(wire.response.failure_message, completed.payload.message);
        assert.equal(wire.response.result, null); assert.equal(wire.response.result_envelope, null);
        assert.equal(wire.decoded_result, undefined); assert.equal(wire.typed_decoded_result, undefined);
        assert.equal(phase.client_result, undefined); assert.equal(phase.typed_client_result, undefined);
        assert.deepEqual(phase.client_failure.body, wire.response, 'actual SDK failure retains original response');
        assert.equal(phase.client_failure.status, 422);
        assert.equal(phase.client_failure.class, {php: 'DurableWorkflow\\Exception\\UpdateFailed',
          python: 'durable_workflow.errors.UpdateFailed', rust: 'durable_workflow::Error::Http'}[language]);
        nonempty(phase.client_failure.message);
        if (language === 'php') assert.equal(phase.client_failure.reason, null);
        if (language === 'python') for (const key of ['workflow_id', 'run_id', 'update_id', 'failure_id']) {
          assert.equal(phase.client_failure[key], wire.response[key]);
        }
      } else {
        assert.equal(phase.client_failure, undefined);
        assert.deepEqual(wire.decoded_result, resultFor(fixture, number));
        assert.deepEqual(wire.typed_decoded_result, typedResultFor(fixture, number));
        assert.equal(frame(wire.response.result_envelope), frame(completed.payload.result));
        if (language === 'python') {
          assert.deepEqual(phase.client_result, {...wire.response, result: wire.response.result ?? wire.decoded_result});
          assert.deepEqual(phase.typed_client_result.value.result, typedResultFor(fixture, number));
        } else {
          assert.deepEqual(phase.client_result, resultFor(fixture, number));
          assert.deepEqual(phase.typed_client_result, typedResultFor(fixture, number));
        }
      }
      if (!phase.phase.startsWith('duplicate-completed-')) {
        checkSubmission(phase, wire, order, author);
        if (original) {
          checkSnapshot(phase.acceptance_snapshot, observation, number === 0 ? 4 : fails ? 7 : 8, 'waiting');
          assert(phase.submission_order < phase.acceptance_order);
          assert(phase.acceptance_order < direction.phases[number === 0 ? 2 : 6].started_order);
          assert.deepEqual(one(order, event => event.sequence === phase.acceptance_order, 'server acceptance observation'),
            {sequence: phase.acceptance_order, event: 'server_acceptance_observed', phase: phase.phase,
              update_id: accepted.payload.update_id});
        } else {
          checkSnapshot(phase.submission_snapshot, observation, number === 0 ? 4 : fails ? 7 : 8, 'waiting');
          assert.deepEqual(phase.submission_snapshot.events, phase.snapshot_before.events,
            'pending duplicate submission creates no extra durable admission');
        }
      } else {
        assert.deepEqual(phase.snapshot.events, phase.snapshot_before.events, 'completed duplicate leaves complete history unchanged');
        assert.deepEqual(phase.snapshot.execution, phase.snapshot_before.execution, 'completed duplicate leaves complete original run state unchanged');
        assert(author.checkpoint_ack_order < phase.started_order);
      }
      receipts[number].push(wire.response);
      continue;
    }
    const turn = [0, 3, 7, 10].indexOf(index);
    assert.equal(phase.worker_finished, true);
    assert.equal(phase.worker_id, workflowId + ':' + phase.phase); assert.equal(phase.task_queue, spec.task_queue);
    assert.equal(phase.activity_invocations, 0);
    assert.equal(phase.workflow_invocations, language === 'python' ? 1 : [1, 0, 0, 1][turn],
      'explicit published workflow replay count');
    assert.equal(phase.update_invocations, language === 'python' ? (fails ? [0, 1, 1, 1] : [0, 1, 2, 2])[turn] : [0, 1, 1, 0][turn],
      'historical Python callbacks stay distinct from durable applications');
    if (language === 'php') assert.equal(phase.workflow_completions, 1);
    const register = one(phase.requests, wire => wire.method === 'POST' && wire.path === '/api/worker/register', 'one real cold worker registration');
    checkTransport(register); assert.equal(register.status, 201); assert.equal(register.response.registered, true);
    assert.equal(register.request.worker_id, phase.worker_id); assert.equal(register.request.task_queue, spec.task_queue);
    assert.deepEqual(register.request.supported_workflow_types, [fixture.workflow_type]);
    assert.deepEqual(register.request.supported_activity_types, []);
    assert.deepEqual(register.request.workflow_command_contracts[fixture.workflow_type].updates, ['set_payload']);
    const claim = one(phase.requests, wire => wire.path === '/api/worker/workflow-tasks/poll' && wire.response?.task, 'one original cold worker claim');
    checkTransport(claim); assert.equal(claim.status, 200);
    const task = claim.response.task;
    for (const key of ['workflow_id', 'run_id', 'workflow_type', 'task_queue']) assert.equal(task[key], observation[key]);
    assert.equal(task.workflow_task_attempt, 1); nonempty(task.task_id); nonempty(task.lease_owner);
    checkRows(task.history_events);
    assert.deepEqual(task.history_events.slice(0, priorRows.length), priorRows,
      'cold author retains original row identities, links and timestamps');
    priorRows = task.history_events;
    assert.deepEqual(publicHistory(task.history_events.map(event => ({...event, timestamp: event.recorded_at}))),
      publicHistory(observation.events.slice(0, (fails ? [2, 4, 7, 11] : [2, 4, 8, 12])[turn])), 'complete original claimed public history');
    const complete = one(phase.requests, wire => wire.method === 'POST'
      && wire.path === '/api/worker/workflow-tasks/' + task.task_id + '/complete', 'one matching fenced author completion');
    checkTransport(complete); assert.equal(complete.status, 200); assert.equal(complete.response.recorded, true);
    assert.equal(complete.response.task_id, task.task_id); assert.equal(complete.response.run_id, observation.run_id);
    assert.equal(complete.response.workflow_task_attempt, 1);
    assert.equal(complete.response.reason ?? null, null);
    assert.equal(complete.request.lease_owner, task.lease_owner); assert.equal(complete.request.workflow_task_attempt, 1);
    assert.equal(complete.request.commands.length, 1);
    const command = complete.request.commands[0];
    if (turn === 0) {
      assert.equal(command.type, 'open_condition_wait'); assert.equal(command.condition_key, 'payload:0');
      const start = one(phase.requests, wire => wire.method === 'POST' && wire.path === '/api/workflows', 'one actual observer start');
      checkTransport(start); assert.equal(start.status, 201);
      assert.equal(start.request.workflow_id, workflowId); assert.equal(start.response.run_id, observation.run_id);
      assert(phase.requests.indexOf(register) < phase.requests.indexOf(start));
      assert(phase.requests.indexOf(start) < phase.requests.indexOf(claim));
      const observed = one(order, event => event.event === 'start_receipt_observed', 'one observed original start receipt');
      assert.deepEqual(observed, {sequence: observed.sequence, event: 'start_receipt_observed', phase: 'original', run_id: observation.run_id});
      assert(phase.started_order < observed.sequence && observed.sequence < phase.checkpoint_ack_order);
    } else {
      assert(!phase.requests.some(wire => wire.method === 'POST' && wire.path === '/api/workflows'));
      if (turn === 3) {
        assert.equal(command.type, 'complete_workflow');
        assert.deepEqual(complete.decoded_commands[0].typed_decoded.result, fixture.typed_output);
        assert.equal(frame(command.result), frame(observation.execution.output_envelope));
      } else {
        const accepted = observation.events.filter(item => item.event_type === 'UpdateAccepted')[turn - 1];
        const completed = observation.events.filter(item => item.event_type === 'UpdateCompleted')[turn - 1];
        assert.equal(task.workflow_update_id, accepted.payload.update_id);
        const failed = fails && turn === 1;
        assert.equal(command.type, failed ? 'fail_update' : 'complete_update'); assert.equal(command.update_id, task.workflow_update_id);
        if (failed) {
          const expected = {php: {type: 'RuntimeException', class: 'RuntimeException', message: fixture.sdk_update_failure.handler_message},
            python: {type: 'ValueError', class: 'builtins.ValueError', message: 'update handler failed: ' + fixture.sdk_update_failure.handler_message},
            rust: {type: 'UpdateFailed', class: 'RuntimeException', message: 'worker loop error: ' + fixture.sdk_update_failure.handler_message}}[language];
          assert.equal(command.result, undefined); assert.equal(command.message, expected.message);
          assert.equal(command.exception_type, expected.type); assert.equal(command.non_retryable, true);
          assert.equal(command.exception_class, language === 'python' ? expected.class : undefined);
          for (const [key, value] of Object.entries({exception_type: expected.type, exception_class: expected.class, message: expected.message})) {
            assert.equal(completed.payload[key], value);
          }
          assert.deepEqual(complete.decoded_commands[0].typed_decoded, {}, 'failure has no manufactured result frame');
        } else {
        assert.equal(frame(command.result), frame(completed.payload.result));
        assert.deepEqual(complete.decoded_commands[0].typed_decoded.result, typedResultFor(fixture, turn - 1));
        }
      }
    }
    assert.deepEqual(phase.checkpoint_workflow_ack, {path: complete.path, task_id: task.task_id,
      lease_owner: task.lease_owner, workflow_task_attempt: 1, request_index: phase.requests.indexOf(complete), status: 200});
    assert.deepEqual(one(order, event => event.sequence === phase.checkpoint_ack_order, 'actual fenced receipt observation'),
      {sequence: phase.checkpoint_ack_order, event: 'checkpoint_receipt_observed', phase: phase.phase,
        task_id: task.task_id, request_index: phase.requests.indexOf(complete)});
    assert(phase.started_order < phase.checkpoint_ack_order);
    tasks.push(task); completions.push(complete);
  }
  assert.equal(new Set(tasks.map(task => task.task_id)).size, 4);
  assert.deepEqual(observation.workflow_polls, tasks); assert.deepEqual(observation.workflow_completions, completions);
  assert.deepEqual(observation.signal_deliveries, deliveries);
  for (const [index, update] of observation.updates.entries()) assert.deepEqual(fails && index === 0 ? update.failed_receipts : update.completed_receipts, receipts[index]);
  return projection;
}
