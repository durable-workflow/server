import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkPublishedSdkPackages} from './sdk-direction-contract.mjs';

const artifacts = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/ServerParityProfiles/published-sdk-signals/artifacts.json', import.meta.url), 'utf8'));
const namespaceArtifacts = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/ServerParityProfiles/published-sdk-namespace-signals/artifacts.json', import.meta.url), 'utf8'));
const languages = ['php', 'python', 'rust'];
const phases = ['original', 'signal-1', 'resumed', 'signal-2', 'replacement'];
const checkpointLengths = [3, 4, 8, 9, 13];
const claimLengths = [2, 4, 9];
const nonempty = value => assert(typeof value === 'string' && value.length > 0);
const history = events => events.map(({sequence, event_type, payload}) => ({sequence, event_type, payload}));
const frame = value => {
  if (typeof value === 'string') { nonempty(value); return value; }
  assert.equal(value?.codec, 'avro'); nonempty(value?.blob); return value.blob;
};

export function checkSdkSignalDirectionArtifacts(fixture, selected) {
  if (!fixture.sdk_signal_direction) return;
  const namespaced = fixture.namespace_signal_pair || fixture.namespace_signal_run;
  assert.deepEqual(selected, namespaced ? namespaceArtifacts : artifacts, 'complete reviewed published SDK signal tuple');
  const spec = fixture.sdk_signal_direction;
  assert(languages.includes(spec.workflow) && languages.includes(spec.client));
  assert.deepEqual(spec, {workflow: spec.workflow, client: spec.client, phase_order: phases,
    task_queue: 'server-parity-sdk-signals', signal_name: 'payload', target: 'instance-current-run',
    start_after_worker_registration: true, first_poll_after_start_receipt: true,
    start_client: 'php-observer', identical_repeated_payload: true});
  assert.equal(fixture.id, (namespaced ? 'signal-namespace-sdk-' : 'signal-sdk-') + spec.workflow + '-' + spec.client);
  assert.equal(fixture.workflow_type, 'parity.v1.sdk_signals');
  assert.equal(fixture.signal_count, 2);
  assert.equal(fixture.activity, false);
  assert.equal(fixture.typed_signal_value.value.count.value, fixture.namespace_signal_run === 'peer' ? '9007199254740995' : '9007199254740993');
}

export function checkSdkSignalDirectionPackages(fixture, observation, selected) {
  if (!fixture.sdk_signal_direction) return;
  checkSdkSignalDirectionArtifacts(fixture, selected);
  checkPublishedSdkPackages(observation, selected, observation.sdk_signal_direction);
}

export function checkSdkSignalDirection(fixture, observation, workflowId) {
  if (!fixture.sdk_signal_direction) return null;
  checkSdkSignalDirectionArtifacts(fixture, fixture.namespace_signal_run ? namespaceArtifacts : artifacts);
  const spec = fixture.sdk_signal_direction, direction = observation.sdk_signal_direction;
  const projection = {two_distinct_identical_signals: true, exact_typed_completion: true};
  if (observation.mode === 'embedded') {
    assert.deepEqual(direction, {applicable: false, reason: 'embedded_executes_php_author_definitions'});
    return projection;
  }
  assert.equal(direction.applicable, true);
  assert.deepEqual(direction.phases.map(item => item.phase), phases);
  assert.equal(new Set(direction.phases.map(item => item.pid)).size, 5, 'five distinct actual SDK processes');
  const observer = direction.observer_start;
  assert.equal(observer.role, 'observer');
  assert.equal(observer.language, 'php');
  assert.equal(observer.sdk_version, artifacts.sdk_php);
  assert(Number.isInteger(observer.pid) && observer.pid > 0);
  assert(!direction.phases.some(item => item.pid === observer.pid), 'observer is separate from all SDK phases');
  assert.equal(observer.run_id, observation.run_id);
  const claims = [], completions = [], signals = [], starts = [];
  let turn = 0;
  for (const [index, phase] of direction.phases.entries()) {
    const sender = index === 1 || index === 3;
    assert.equal(phase.language, sender ? spec.client : spec.workflow);
    assert.equal(phase.sdk_version, artifacts['sdk_' + phase.language]);
    assert(Number.isInteger(phase.pid) && phase.pid > 0);
    assert.equal(phase.run_id, observation.run_id);
    assert.equal(phase.worker_error, undefined);
    assert.equal(phase.observer_error, undefined);
    const snapshot = phase.snapshot;
    for (const key of ['workflow_id', 'run_id', 'workflow_type', 'namespace', 'task_queue', 'payload_codec']) {
      assert.equal(snapshot[key], observation[key], 'original run ' + key + ' throughout the phases');
    }
    assert.equal(snapshot.events.length, checkpointLengths[index]);
    assert.deepEqual(snapshot.events, observation.events.slice(0, checkpointLengths[index]), 'complete unchanged original history prefix');
    assert.deepEqual(snapshot.typed_input, observation.typed_input);
    assert.deepEqual(snapshot.typed_output, index === 4 ? fixture.typed_signal_value : {type: 'null', value: null});
    if (sender) assert(['waiting', 'running'].includes(snapshot.status), 'accepted signal leaves original run nonterminal');
    else assert.equal(snapshot.status, index === 4 ? 'completed' : 'waiting');
    for (const request of phase.requests) if (request.method === 'POST' && request.path === '/api/workflows') starts.push({index, request});
    if (fixture.namespace_signal_run) {
      for (const request of phase.requests) assert.equal(request.namespace, observation.namespace, 'actual namespace header in every SDK phase');
    }
    if (sender) {
      assert.equal(phase.sender_finished, true);
      assert.equal(phase.signal_sent, true);
      assert.equal(phase.workflow_id, workflowId);
      assert(!phase.requests.some(item => item.path.startsWith('/api/worker/')), 'normal signal client performs no worker operations');
      const requests = phase.requests.filter(item => item.method === 'POST' && decodeURIComponent(item.path) === '/api/workflows/' + workflowId + '/signal/payload');
      assert.equal(requests.length, 1, 'one actual instance-scoped signal request per client process');
      const request = requests[0], receipt = request.response;
      assert.equal(request.status, 202, 'normal accepted signal uses the published HTTP 202 contract');
      assert.equal(request.request.run_id, undefined, 'normal common instance API has no run selector');
      frame(request.request.input);
      assert.deepEqual(request.decoded_input, [fixture.signal_value]);
      assert.deepEqual(request.typed_decoded_input, {type: 'list', value: [fixture.typed_signal_value]});
      assert.equal(receipt.command_status ?? (receipt.accepted ? 'accepted' : 'rejected'), 'accepted');
      assert.equal(receipt.outcome, 'signal_received');
      assert.equal(receipt.workflow_id, workflowId);
      assert.equal(receipt.run_id, observation.run_id);
      nonempty(receipt.command_id);
      if (spec.client === 'python') assert.equal(phase.client_result, null, 'published Python has no client receipt object');
      else assert.deepEqual(phase.client_result, receipt, 'retain the actual published client result');
      const delivery = observation.signal_deliveries[signals.length];
      assert.deepEqual(delivery.response, receipt, 'bind delivered signal to its actual receipt');
      assert.deepEqual(delivery.before, direction.phases[index - 1].snapshot.execution);
      assert.equal(observation.events[index === 1 ? 3 : 8].payload.workflow_command_id, receipt.command_id);
      signals.push(receipt.command_id);
      continue;
    }
    assert.equal(phase.worker_finished, true);
    assert.equal(phase.worker_id, workflowId + ':' + phase.phase);
    assert.equal(phase.task_queue, spec.task_queue);
    assert.equal(phase.workflow_invocations, 1);
    assert.equal(phase.activity_invocations, 0);
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
    assert.equal(polls.length, 1);
    assert.equal(polls[0].method, 'POST'); assert.equal(polls[0].status, 200);
    assert.equal(polls[0].request.task_queue, spec.task_queue);
    const task = polls[0].response.task;
    for (const key of ['workflow_id', 'run_id', 'workflow_type', 'task_queue']) assert.equal(task[key], observation[key]);
    nonempty(task.task_id); nonempty(task.lease_owner);
    assert.equal(task.workflow_task_attempt, 1);
    assert.deepEqual(history(task.history_events), history(observation.events.slice(0, claimLengths[turn])));
    claims.push(task);
    const outcomes = phase.requests.filter(item => item.method === 'POST' && /^\/api\/worker\/workflow-tasks\/[^/]+\/complete$/.test(item.path));
    assert.equal(outcomes.length, 1);
    const outcome = outcomes[0];
    assert.equal(outcome.status, 200); assert.equal(outcome.response.recorded, true);
    assert.equal(outcome.response.task_id, task.task_id);
    assert.equal(outcome.response.run_id, observation.run_id);
    assert.equal(outcome.path, '/api/worker/workflow-tasks/' + task.task_id + '/complete');
    assert.equal(outcome.request.lease_owner, task.lease_owner);
    assert.equal(outcome.request.workflow_task_attempt, task.workflow_task_attempt);
    assert.equal(outcome.request.commands.length, 1);
    const command = outcome.request.commands[0];
    assert.equal(command.type, turn === 2 ? 'complete_workflow' : 'open_condition_wait');
    if (turn < 2) {
      const opened = observation.events[turn === 0 ? 2 : 7].payload;
      assert.equal(command.condition_key, 'payload:' + turn);
      assert.equal(command.condition_definition_fingerprint, opened.condition_definition_fingerprint);
      if (spec.workflow === 'rust') {
        const occurrence = 'rust:condition-wait:' + turn;
        assert.equal(command.condition_wait_occurrence_id, occurrence, 'actual authored Rust condition occurrence');
        assert.equal(opened.condition_wait_occurrence_id, occurrence, 'opening retains authored occurrence');
        assert.equal(observation.events[turn === 0 ? 6 : 11].payload.condition_wait_occurrence_id,
          occurrence, 'matching satisfaction retains the original authored occurrence');
      }
    }
    assert(!phase.requests.some(item => item.path === '/api/worker/activity-tasks/poll' && item.response?.task));
    completions.push(outcome); turn++;
  }
  assert.equal(new Set(signals).size, 2, 'identical values are two distinct accepted signal commands');
  assert.equal(new Set(claims.map(item => item.task_id)).size, 3);
  assert.deepEqual(observation.workflow_polls, claims, 'bind flattened claims to their real phase receipts');
  assert.equal(observation.workflow_completions.length, 3);
  for (const [index, outcome] of completions.entries()) {
    const {decoded_commands, ...wire} = observation.workflow_completions[index];
    assert.deepEqual(wire, outcome, 'bind flattened completions to real phase receipts');
    assert.equal(decoded_commands.length, 1);
    if (index === 2) {
      assert.deepEqual(decoded_commands[0].decoded.result, fixture.signal_value);
      assert.deepEqual(decoded_commands[0].typed_decoded.result, fixture.typed_signal_value);
      assert.equal(frame(outcome.request.commands[0].result), frame(observation.execution.output_envelope));
    } else {
      assert.deepEqual(decoded_commands[0], {decoded: {}, typed_decoded: {}});
    }
  }
  assert.equal(starts.length, 1, 'no cold restart creates another run');
  assert.equal(starts[0].index, 0);
  assert.equal(starts[0].request.status, 201);
  assert.equal(starts[0].request.response.run_id, observation.run_id);
  for (const key of ['workflow_id', 'workflow_type', 'task_queue']) assert.equal(starts[0].request.request[key], observation[key]);
  assert(direction.phases[0].requests.findIndex(item => item.path === '/api/worker/workflow-tasks/poll' && item.response?.task)
    > direction.phases[0].requests.indexOf(starts[0].request), 'original workflow claim follows the real accepted start receipt');
  assert(direction.phases[0].requests.indexOf(starts[0].request) >
    direction.phases[0].requests.findIndex(item => item.path === '/api/worker/register'), 'start follows the actual successful registration');
  return projection;
}
