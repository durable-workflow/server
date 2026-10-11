import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkPublishedSdkPackages} from './sdk-direction-contract.mjs';

const artifacts = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/ServerParityProfiles/published-sdk-child-directions/artifacts.json', import.meta.url), 'utf8'));
const phases = ['original', 'child', 'replacement'];
const languages = ['php', 'python', 'rust'];

export function checkSdkChildDirectionArtifacts(fixture, selected) {
  if (!fixture.sdk_child_direction) return;
  assert.deepEqual(selected, artifacts, 'complete reviewed child direction tuple');
  const spec = fixture.sdk_child_direction;
  assert(languages.includes(spec.parent) && languages.includes(spec.child));
  assert.deepEqual(spec, {parent: spec.parent, child: spec.child,
    parent_queue: 'server-parity-sdk-parent', child_queue: 'server-parity-sdk-child',
    phase_order: phases, start_before_worker_registration: true});
  assert.equal(fixture.id, 'child-sdk-' + spec.parent + '-' + spec.child);
  assert.equal(fixture.workflow_type, 'parity.v1.one_child');
  assert.equal(fixture.child_workflow_type, 'parity.v1.echo_child');
  assert.equal(fixture.child_count, 1);
  assert.equal(fixture.activity, false);
}

export function checkSdkChildDirectionPackages(fixture, observation, selected) {
  if (!fixture.sdk_child_direction) return;
  checkSdkChildDirectionArtifacts(fixture, selected);
  checkPublishedSdkPackages(observation, selected, observation.sdk_child_direction);
}

export function checkSdkChildDirection(fixture, observation, workflowId) {
  const spec = fixture.sdk_child_direction;
  if (!spec) return null;
  checkSdkChildDirectionArtifacts(fixture, artifacts);
  const direction = observation.sdk_child_direction;
  const projection = {one_original_child: true, exact_typed_completion: true};
  if (observation.mode === 'embedded') {
    assert.deepEqual(direction, {applicable: false, reason: 'embedded_executes_php_author_definitions'});
    return projection;
  }
  assert.equal(direction.applicable, true);
  assert.deepEqual(direction.phases.map(phase => phase.phase), phases);
  assert.equal(new Set(direction.phases.map(phase => phase.pid)).size, 3, 'three distinct actual processes');
  assert.equal(observation.children.length, 1);
  const child = observation.children[0];
  const [original, childPhase, replacement] = direction.phases;
  const claims = [];
  for (const [index, phase] of direction.phases.entries()) {
    const childWorker = index === 1;
    const actor = childWorker ? child : observation;
    const language = spec[childWorker ? 'child' : 'parent'];
    assert.equal(phase.language, language);
    assert.equal(phase.sdk_version, artifacts['sdk_' + language]);
    assert(Number.isInteger(phase.pid) && phase.pid > 0);
    assert.equal(phase.worker_finished, true);
    assert.equal(phase.worker_error, undefined);
    assert.equal(phase.run_id, actor.run_id, 'phase executes its original parent or child run');
    assert.equal(phase.worker_id, actor.workflow_id + ':' + phase.phase);
    assert.equal(phase.task_queue, childWorker ? spec.child_queue : spec.parent_queue);
    assert.equal(phase.activity_invocations, 0);
    assert(childWorker ? phase.workflow_invocations === 1
      : Number.isInteger(phase.workflow_invocations) && phase.workflow_invocations > 0);
    const snapshot = phase.snapshot;
    assert.equal(snapshot.workflow_id, workflowId);
    assert.equal(snapshot.run_id, observation.run_id, 'original parent snapshot');
    assert.equal(snapshot.status, index === 2 ? 'completed' : 'waiting');
    assert.equal(snapshot.events.length, [4, 5, 6][index]);
    assert.deepEqual(snapshot.events, observation.events.slice(0, snapshot.events.length), 'full unchanged parent prefix');
    assert.deepEqual(snapshot.typed_input, observation.typed_input);
    assert.deepEqual(snapshot.typed_output, index === 2 ? fixture.typed_output : {type: 'null', value: null});
    const childSnapshot = phase.child_snapshot;
    for (const key of ['workflow_id', 'run_id', 'workflow_type', 'namespace', 'task_queue', 'payload_codec']) {
      assert.equal(childSnapshot[key], child[key], 'original child ' + key + ' at every checkpoint');
    }
    assert.deepEqual(childSnapshot.typed_input, child.typed_input);
    assert.deepEqual(childSnapshot.events, index === 0 ? child.events.slice(0, 1) : child.events, 'full unchanged child prefix');
    assert.deepEqual(childSnapshot.typed_output, index === 0 ? {type: 'null', value: null} : child.typed_output);
    if (index !== 0) assert.equal(childSnapshot.status, 'completed');

    const registrations = phase.requests.filter(item => item.method === 'POST' && item.path === '/api/worker/register');
    assert.equal(registrations.length, 1);
    const registration = registrations[0];
    assert.equal(registration.status, 201);
    assert.equal(registration.response.registered, true);
    assert.equal(registration.request.worker_id, phase.worker_id);
    assert.equal(registration.request.task_queue, phase.task_queue);
    assert.deepEqual(registration.request.supported_workflow_types, [childWorker ? fixture.child_workflow_type : fixture.workflow_type]);
    assert.deepEqual(registration.request.supported_activity_types, []);
    const polls = phase.requests.filter(item => item.path === '/api/worker/workflow-tasks/poll' && item.response?.task);
    assert.equal(polls.length, 1, 'one actual original workflow task per phase');
    assert.equal(polls[0].status, 200);
    const task = polls[0].response.task;
    assert.equal(task.workflow_id, actor.workflow_id);
    assert.equal(task.run_id, actor.run_id);
    assert.equal(task.workflow_type, actor.workflow_type);
    assert.equal(task.task_queue, phase.task_queue);
    assert.equal(task.workflow_task_attempt, 1);
    assert(typeof task.lease_owner === 'string' && task.lease_owner.length > 0);
    assert(typeof task.task_id === 'string' && task.task_id.length > 0);
    claims.push(task.task_id);
    const outcomes = phase.requests.filter(item => item.method === 'POST'
      && /^\/api\/worker\/workflow-tasks\/[^/]+\/complete$/.test(item.path));
    assert.equal(outcomes.length, 1);
    const outcome = outcomes[0];
    assert.equal(outcome.status, 200);
    assert.equal(outcome.response.recorded, true);
    assert.equal(outcome.path, '/api/worker/workflow-tasks/' + task.task_id + '/complete');
    assert.equal(outcome.request.lease_owner, task.lease_owner);
    assert.equal(outcome.request.workflow_task_attempt, task.workflow_task_attempt);
    assert.equal(outcome.request.commands.length, 1);
    const command = outcome.request.commands[0];
    assert.equal(command.type, index === 0 ? 'start_child_workflow' : 'complete_workflow');
    if (index === 0) {
      assert.equal(command.workflow_type, fixture.child_workflow_type);
      assert.equal(command.queue, spec.child_queue);
      assert.equal(command.parent_close_policy ?? 'abandon', 'abandon');
      assert.equal(command.cancellation_policy ?? 'abandon', 'abandon');
    }
    assert(!phase.requests.some(item => item.path === '/api/worker/activity-tasks/poll' && item.response?.task), 'no activity claimed');
  }
  assert.equal(new Set(claims).size, 3, 'original parent, child and resumed parent tasks are distinct');
  const starts = direction.phases.flatMap(phase => phase.requests.filter(item => item.method === 'POST' && item.path === '/api/workflows'));
  assert.equal(starts.length, 1, 'only the original parent is client-started');
  assert.equal(starts[0].status, 201);
  assert.equal(starts[0].request.workflow_id, workflowId);
  assert.equal(starts[0].request.workflow_type, fixture.workflow_type);
  assert.equal(starts[0].request.task_queue, spec.parent_queue);
  assert.equal(starts[0].response.run_id, observation.run_id);
  assert(original.requests.indexOf(starts[0]) < original.requests.findIndex(item => item.path === '/api/worker/register'));
  assert.equal(original.snapshot.events[1].payload.workflow_definition_fingerprint == null, true);
  assert.notEqual(original.snapshot.events[1].payload.workflow_definition_fingerprint_source, 'worker');
  assert.deepEqual(replacement.snapshot.events, observation.events);
  assert.deepEqual(childPhase.child_snapshot.events, replacement.child_snapshot.events, 'cold replay does not mutate the completed child');
  return projection;
}
