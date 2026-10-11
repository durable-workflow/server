import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const artifacts = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/ServerParityProfiles/published-sdk-directions/artifacts.json', import.meta.url), 'utf8'));
const phases = ['original', 'activity', 'replacement'];
const queue = 'server-parity-sdk-activity';
const languages = ['php', 'python', 'rust'];

export function checkSdkDirectionArtifacts(fixture, selected) {
  if (!fixture.sdk_direction) return;
  assert.deepEqual(selected, artifacts, 'selected direction tuple matches the complete reviewed manifest');
  const spec = fixture.sdk_direction;
  assert(languages.includes(spec.workflow) && languages.includes(spec.activity));
  assert.deepEqual(spec, {workflow: spec.workflow, activity: spec.activity,
    workflow_queue: 'server-parity-sdk-workflow', activity_queue: queue,
    phase_order: phases, start_before_worker_registration: true});
  assert.equal(fixture.id, `sdk-${spec.workflow}-${spec.activity}`);
}

export function checkSdkDirectionPackages(fixture, observation, selected) {
  if (!fixture.sdk_direction) return;
  checkSdkDirectionArtifacts(fixture, selected);
  assert.equal(observation.sdk_php_source, selected.sdk_php_source_commit, 'installed PHP SDK source');
  assert.equal(observation.sdk_composer_lock_sha256, selected.sdk_php_composer_lock_sha256, 'complete PHP dependency lock');
  assert.deepEqual(observation.sdk_loaded_sources, selected.sdk_php_loaded_sources, 'actual autoloaded PHP worker and replayer');
  if (observation.mode === 'embedded') {
    assert.equal(observation.workflow_package, selected.workflow);
    assert.equal(observation.workflow_source, selected.embedded_image_path_reference, 'published image path reference, not a Git identity');
    assert.equal(observation.embedded_composer_lock_sha256, selected.embedded_image_composer_lock_sha256);
    assert.deepEqual(observation.workflow_loaded_sources, selected.workflow_loaded_sources);
  } else {
    assert.deepEqual(observation.sdk_direction.packages,
      {outcome: 'pass', rust: selected.published_sdk_artifacts.rust, python: selected.published_sdk_artifacts.python},
      'compiled registry dependency and actual hash-required wheel installation');
  }
}

export function checkSdkDirection(fixture, observation, workflowId) {
  const spec = fixture.sdk_direction;
  if (!spec) return null;
  checkSdkDirectionArtifacts(fixture, artifacts);
  const direction = observation.sdk_direction;
  if (observation.mode === 'embedded') {
    assert.deepEqual(direction, {applicable: false, reason: 'embedded_executes_php_author_definitions'});
    return {one_original_activity: true, exact_typed_completion: true};
  }
  assert.equal(direction.applicable, true);
  assert.equal(direction.phases.length, 3, 'all three actual processes completed');
  assert.deepEqual(direction.phases.map(phase => phase.phase), phases);
  assert.equal(new Set(direction.phases.map(phase => phase.pid)).size, 3, 'distinct original, activity and cold replay processes');
  const [original, activity, replacement] = direction.phases;
  for (const [index, phase] of direction.phases.entries()) {
    const language = spec[index === 1 ? 'activity' : 'workflow'];
    assert.equal(phase.language, language, 'actual published phase language');
    assert.equal(phase.sdk_version, artifacts[`sdk_${language}`], 'actual installed or compiled SDK version');
    assert(Number.isInteger(phase.pid) && phase.pid > 0);
    assert.equal(phase.worker_finished, true, 'normal SDK task loop and shutdown succeeded');
    assert.equal(phase.worker_error, undefined);
    assert.equal(phase.run_id, observation.run_id, 'same original run in every process');
    assert.equal(phase.worker_id, `${workflowId}:${phase.phase}`);
    assert.equal(phase.task_queue, index === 1 ? queue : 'server-parity-sdk-workflow');
    assert.equal(phase.activity_invocations, index === 1 ? 1 : 0, 'original activity executed only by the separate worker');
    assert(index === 1 ? phase.workflow_invocations === 0
      : Number.isInteger(phase.workflow_invocations) && phase.workflow_invocations > 0);
    const snapshot = phase.snapshot;
    assert.equal(snapshot.workflow_id, workflowId);
    assert.equal(snapshot.run_id, observation.run_id);
    assert.equal(snapshot.events.length, [3, 5, 6][index]);
    assert.deepEqual(snapshot.events, observation.events.slice(0, snapshot.events.length), 'full persisted checkpoint prefix unchanged');
    assert.deepEqual(snapshot.typed_input, observation.typed_input);
    assert.deepEqual(snapshot.typed_output, index === 2 ? fixture.typed_value : {type: 'null', value: null});
    assert.equal(snapshot.status, index === 2 ? 'completed' : 'waiting', 'real durable checkpoint state');
    const registrations = phase.requests.filter(item => item.method === 'POST' && item.path === '/api/worker/register');
    assert.equal(registrations.length, 1, 'one real SDK registration for this phase');
    const registration = registrations[0];
    assert.equal(registration.status, 201, 'new phase worker registration was created');
    assert.equal(registration.response.registered, true);
    assert.equal(registration.request.worker_id, phase.worker_id);
    assert.equal(registration.request.task_queue, phase.task_queue);
    assert.deepEqual(registration.request.supported_workflow_types, index === 1 ? [] : [fixture.workflow_type]);
    assert.deepEqual(registration.request.supported_activity_types, index === 1 ? ['parity.v1.echo_activity'] : []);
    const kind = index === 1 ? 'activity' : 'workflow';
    const claims = phase.requests.filter(item => item.path === `/api/worker/${kind}-tasks/poll` && item.response?.task);
    assert.equal(claims.length, 1, 'one original claimed task in each phase');
    assert.equal(claims[0].status, 200);
    const outcomes = phase.requests.filter(item => item.method === 'POST'
      && new RegExp(`^/api/worker/${kind}-tasks/[^/]+/complete$`).test(item.path));
    assert.equal(outcomes.length, 1, 'real SDK commits the phase once');
    assert.equal(outcomes[0].status, 200);
    assert.equal(outcomes[0].response.recorded, true);
    if (index !== 1) {
      const commands = outcomes[0].request.commands;
      assert.equal(commands.length, 1);
      assert.equal(commands[0].type, index === 0 ? 'schedule_activity' : 'complete_workflow');
      if (index === 0) {
        assert.equal(commands[0].activity_type, 'parity.v1.echo_activity');
        assert.equal(commands[0].queue, queue, 'separate activity queue survives authoring');
      }
    }
  }
  const starts = original.requests.filter(item => item.method === 'POST' && item.path === '/api/workflows');
  assert.equal(starts.length, 1, 'original published language client starts the run');
  assert.equal(starts[0].status, 201, 'original run was newly created');
  assert.equal(starts[0].request.workflow_id, workflowId);
  assert.equal(starts[0].response.run_id, observation.run_id);
  assert(original.requests.indexOf(starts[0]) < original.requests.findIndex(item => item.path === '/api/worker/register'));
  assert.equal(original.snapshot.events[1].payload.workflow_definition_fingerprint == null, true, 'start precedes worker definition');
  assert.notEqual(original.snapshot.events[1].payload.workflow_definition_fingerprint_source, 'worker');
  assert.deepEqual(replacement.snapshot.events, observation.events);
  const workflowClaim = phase => phase.requests.find(item => item.path === '/api/worker/workflow-tasks/poll' && item.response?.task).response.task.task_id;
  assert.notEqual(workflowClaim(original), workflowClaim(replacement), 'completion uses a later workflow task');
  assert.deepEqual(activity.snapshot.events.slice(0, 3), original.snapshot.events);
  return {one_original_activity: true, exact_typed_completion: true};
}
