// Mutation controls operate on actual recorder output, never producer inputs.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkCorrectedMarkers, checkCorrectedMarkerPackages} from '../corrected-marker-contract.mjs';

const [fixturePath, observationPath, artifactsPath] = process.argv.slice(2);
const fixture = JSON.parse(readFileSync(fixturePath));
const original = JSON.parse(readFileSync(observationPath));
const artifacts = artifactsPath ? JSON.parse(readFileSync(artifactsPath)) : null;
const read = value => {
  if (artifacts) checkCorrectedMarkerPackages(fixture, value, artifacts);
  return checkCorrectedMarkers(fixture, value, original.workflow_id);
};
read(original);
const mutations = {
  'missing marker bounds': value => { delete value.events[2].payload.min_supported; },
  'changed numeric marker': value => { value.events[2].payload.version += 1; },
  'changed physical marker position': value => { value.events[2].sequence += 1; },
  'changed marker microclock': value => { value.events[2].timestamp = '2000-01-01T00:00:00.000000Z'; },
  'changed original prefix': value => { value.corrected_markers.phases.original.events[2].payload.change_id = 'different'; },
  'changed command bound': value => { value.corrected_markers.phases.original.execution.run_deadline_at = '2000-01-01T00:00:00Z'; },
  'missing cold process': value => { value.corrected_markers.phases.replacement.actor.pid = value.corrected_markers.phases.original.actor.pid; },
  'wrong original run': value => { value.corrected_markers.phases.replacement.actor.run_id = 'different'; },
  'changed typed int64': value => { value.typed_output.value.typed.value.integer.value = '9007199254740992'; },
  'missing terminal outcome': value => { value.status = 'waiting'; },
  'empty cold poll counted as completion': value => { value.corrected_markers.phases.replacement.actor.outcome = {Ok: 0}; },
  'removed actual completion': value => { value.events.pop(); },
  'trimmed explicit retry list': value => {
    value.events.find(event => event.event_type === 'ActivityScheduled').payload.activity.retry_policy.backoff_seconds = [1];
  },
  'changed policy null': value => {
    value.events.find(event => event.event_type === 'ActivityScheduled').payload.activity.retry_policy.heartbeat_timeout = 0;
  },
  'corrupted whole response bytes': value => {
    value.corrected_markers.requests.find(item => item.status === 200).response_bytes_base64 = '';
  },
  'cancelled committed outcome': value => {
    const request = value.corrected_markers.requests.find(item => item.path.endsWith('/complete'));
    request.client_cancelled = true;
  },
};
if (original.mode === 'embedded') {
  delete mutations['corrupted whole response bytes'];
  delete mutations['cancelled committed outcome'];
  Object.assign(mutations, {
    'missing public clock': value => { delete value.corrected_markers.phases.replacement.clocks; },
    'changed original clock': value => { value.corrected_markers.phases.original.clocks[0][0] = '2000-01-01T00:00:00.000000Z'; },
    'changed cached helper clock': value => {
      value.corrected_markers.phases.replacement.clocks[0][fixture.corrected_markers.embedded_clock_positions.replacement.length - 1] = '2000-01-01T00:00:00.000000Z'; },
    'activity ran in cold workflow process': value => { value.corrected_markers.phases.replacement.activity_invocations = 1; },
    'changed original task run': value => { value.corrected_markers.phases.replacement.tasks[0].workflow_run_id = 'different'; },
  });
  if (artifacts) Object.assign(mutations, {
    'changed original package': value => { value.corrected_markers.phases.original.workflow_package = '0.0.0'; },
    'changed current loaded source': value => { value.corrected_markers.phases.replacement.workflow_loaded_sources['src/V2/Support/WorkflowExecutor.php'] = '0'.repeat(64); },
    'changed role dependency lock': value => { value.corrected_markers.phases.activity.embedded_composer_lock_sha256 = '0'.repeat(64); },
  });
}
for (const [name, mutate] of Object.entries(mutations)) {
  const corrupted = structuredClone(original);
  mutate(corrupted);
  assert.throws(() => read(corrupted), undefined, name);
}
console.log(JSON.stringify({outcome: 'pass', fixture_id: fixture.id, actual_record: true,
  mutation_controls: Object.keys(mutations)}));
