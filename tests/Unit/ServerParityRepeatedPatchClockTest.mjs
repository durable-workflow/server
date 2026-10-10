import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkPatchDeployment, checkPublishedPatchArtifacts, checkPatchPackageObservation} from '../../scripts/conformance/server-parity/patch-deployment-contract.mjs';

const directory = new URL('../Fixtures/ServerParityProfiles/repeated-patch-clock/', import.meta.url);
const artifacts = JSON.parse(readFileSync(new URL('artifacts.json', directory)));
for (const checkpoint of ['pending', 'completed']) {
  const fixture = JSON.parse(readFileSync(new URL(`patch-repeated-activity-${checkpoint}.json`, directory)));
  function model(mode = 'embedded') {
    const events = fixture.expected_events.map((event_type, index) => ({event_type, sequence: index + 1,
      timestamp: `2026-01-01T00:00:0${index}.123456Z`, payload: event_type === 'VersionMarkerRecorded'
        ? {...fixture.patch_deployment.marker, task: {id: 'task', type: 'workflow', status: 'leased', lease_owner: 'original-worker'}}
        : {sequence: 2, activity_execution_id: 'original-activity'}}));
    const base = {mode, sdk_php: artifacts.sdk_php, sdk_php_source: artifacts.sdk_php_source_commit,
      workflow_id: 'test', run_id: 'original-run', workflow_type: fixture.workflow_type,
      namespace: 'default', task_queue: 'server-parity-v1', payload_codec: 'avro',
      typed_input: {type: 'list', value: [fixture.typed_value]},
      workflow_package: artifacts.workflow, workflow_source: artifacts.workflow_source_commit,
      workflow_loaded_sources: artifacts.workflow_loaded_sources,
      instance: {id: 'test', namespace: 'default', workflow_type: fixture.workflow_type, current_run_id: 'original-run'}};
    const completion = commands => ({method: 'POST', path: '/api/worker/workflow-tasks/task/complete', status: 200,
      request: {commands, lease_owner: 'original-worker'}});
    const activity = {method: 'POST', path: '/api/worker/activity-tasks/original-activity/complete', status: 200, request: {}};
    const original = {...structuredClone(base), phase: 'original', pid: 101, status: 'waiting', output: null,
      decisions: [[true, true]], events: events.slice(0, checkpoint === 'pending' ? 4 : 6),
      diagnostics: ['worker.registered', 'worker.stopped'],
      requests: [completion([{type: 'record_version_marker', ...fixture.patch_deployment.marker},
        {type: 'schedule_activity', activity_type: 'parity.v1.echo_activity'}]), ...(checkpoint === 'completed' ? [activity] : [])]};
    const replacement = {...structuredClone(base), phase: 'replacement', pid: 102, status: 'completed',
      typed_output: fixture.typed_value, events: structuredClone(events), decisions: [[true, true], [true, true]],
      ...(mode === 'embedded' ? {embedded_clock_probe: {clocks: [[events[2].timestamp, events[2].timestamp], [events[2].timestamp, events[2].timestamp]]}} : {}),
      diagnostics: ['worker.registered', 'worker.stopped'],
      requests: [...(checkpoint === 'pending' ? [activity] : []), completion([{type: 'complete_workflow'}])]};
    return {...structuredClone(replacement), patch_deployment: {checkpoint: fixture.patch_deployment.checkpoint,
      change_id: fixture.patch_deployment.change_id, original, replacement}};
  }
  for (const mode of ['embedded', 'http']) test(`${checkpoint} ${mode}: modeled repeated marker replay`, () => {
    const raw = model(mode);
    checkPublishedPatchArtifacts(fixture, artifacts);
    checkPatchPackageObservation(fixture, raw, artifacts);
    assert.equal(checkPatchDeployment(fixture, raw, 'test').marker_count, 1);
  });
  for (const [name, mutate] of [
    ['missing clock snapshots', x => delete x.replacement.embedded_clock_probe],
    ['empty clock snapshots', x => x.replacement.embedded_clock_probe.clocks = []],
    ['one replay omitted', x => x.replacement.embedded_clock_probe.clocks.pop()],
    ['second call omitted', x => x.replacement.embedded_clock_probe.clocks[0].pop()],
    ['first clock changed', x => x.replacement.embedded_clock_probe.clocks[0][0] = '2026-01-01T00:00:02.123455Z'],
    ['second clock changed', x => x.replacement.embedded_clock_probe.clocks[0][1] = '2026-01-01T00:00:03.123456Z'],
    ['microseconds truncated', x => x.replacement.embedded_clock_probe.clocks[1][1] = '2026-01-01T00:00:02.123Z'],
    ['original clock fabricated', x => x.original.embedded_clock_probe = {clocks: []}],
    ['same process', x => x.replacement.pid = x.original.pid],
    ['replacement changes run', x => x.replacement.run_id = 'new-run'],
    ['original marker changed', x => x.original.events[2].timestamp = '2026-01-01T00:00:02.123455Z'],
    ['second decision changed', x => x.replacement.decisions[0][1] = false],
    ['second marker added', x => x.replacement.events.push(structuredClone(x.replacement.events[2]))],
    ['activity position changed', x => x.replacement.events[3].payload.sequence = 3],
  ]) test(`${checkpoint}: model rejects ${name}`, () => {
    const raw = model(); mutate(raw.patch_deployment);
    raw.events = raw.patch_deployment.replacement.events;
    raw.embedded_clock_probe = raw.patch_deployment.replacement.embedded_clock_probe;
    assert.throws(() => checkPatchDeployment(fixture, raw, 'test'));
  });
  test(`${checkpoint}: model rejects HTTP clock claim`, () => {
    const raw = model('http'); raw.embedded_clock_probe = {clocks: []};
    assert.throws(() => checkPatchDeployment(fixture, raw, 'test'));
  });
  for (const phase of ['original', 'replacement']) for (const [name, mutate] of [
    ['old installed version', x => x.workflow_package = '2.5.5'],
    ['wrong installed reference', x => x.workflow_source = 'a'.repeat(40)],
    ['reference missing', x => delete x.workflow_source],
    ['loaded source missing', x => delete x.workflow_loaded_sources],
    ...Object.keys(artifacts.workflow_loaded_sources).map(path => [`old loaded ${path}`, x => x.workflow_loaded_sources[path] = '0'.repeat(64)]),
  ]) test(`${checkpoint} ${phase}: model rejects ${name}`, () => {
    const raw = model(); mutate(raw.patch_deployment[phase]);
    assert.throws(() => checkPatchPackageObservation(fixture, raw, artifacts));
  });
  test(`${checkpoint}: model rejects stale source inventory`, () => {
    const tuple = structuredClone(artifacts); delete tuple.workflow_loaded_sources['src/V2/Support/VersionDecisions.php'];
    assert.throws(() => checkPublishedPatchArtifacts(fixture, tuple));
  });
}
