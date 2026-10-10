import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkLegacyMarkerArtifacts, checkLegacyMarkerPackageObservation, checkLegacyMarkerDeployment} from '../../scripts/conformance/server-parity/legacy-marker-contract.mjs';
const profile = new URL('../Fixtures/ServerParityProfiles/legacy-marker-aliases/', import.meta.url);
const artifacts = JSON.parse(readFileSync(new URL('artifacts.json', profile)));

for (const id of ['legacy-two-markers-pending', 'legacy-two-markers-completed', 'legacy-one-marker-control', 'fresh-rust-marker-deduplication']) {
  const fixture = JSON.parse(readFileSync(new URL(id+'.json', profile)));
  const spec = fixture.patch_deployment, count = spec.legacy_marker_history.original_marker_count;
  function model(mode = 'embedded') {
    const events = fixture.expected_events.map((event_type, index) => ({sequence: index + 1, event_type,
      timestamp: `2026-01-01T00:00:0${index}.123456Z`, payload: event_type === 'VersionMarkerRecorded'
        ? {...spec.marker, sequence: index - 1, task: {id: 'original-task', type: 'workflow', status: 'leased', lease_owner: 'test:original'}}
        : {sequence: count + 1}}));
    const current = {workflow_package: artifacts.workflow, workflow_source: artifacts.workflow_source_commit,
      embedded_composer_lock_sha256: artifacts.embedded_composer_lock_sha256, workflow_loaded_sources: artifacts.workflow_loaded_sources};
    const old = {workflow_package: artifacts.embedded_original.version, workflow_source: artifacts.embedded_original.source_commit,
      embedded_composer_lock_sha256: artifacts.embedded_original.composer_lock_sha256, workflow_loaded_sources: artifacts.embedded_original.loaded_sources};
    const base = {mode, sdk_php: artifacts.sdk_php, sdk_php_source: artifacts.sdk_php_source_commit,
      workflow_id: 'test', run_id: 'original-run', workflow_type: fixture.workflow_type,
      namespace: 'default', task_queue: 'server-parity-v1', payload_codec: 'avro',
      typed_input: {type: 'list', value: [fixture.typed_value]}, ...current,
      instance: {id: 'test', current_run_id: 'original-run', namespace: 'default', workflow_type: fixture.workflow_type}};
    const io = (method, path, request = {}) => ({method, path, request, status: 200, transport_error: null, client_cancelled: false});
    const registration = role => [io('POST', '/api/worker/register', {worker_id: 'test:'+role, task_queue: 'server-parity-v1'}),
      io('DELETE', '/api/worker/registrations/'+role)];
    const outcome = io('POST', '/api/worker/activity-tasks/original-activity/complete');
    const {sequence, ...fields} = spec.marker;
    const original = {...structuredClone(base), ...(spec.legacy_marker_history.embedded_original === '2.5.5' ? old : {}),
      phase: 'original', pid: 101, status: 'pending', output: null, worker_finished: true,
      producer: mode === 'http' ? spec.producer : {applicable: false, reason: 'embedded_executes_php_author_definitions'},
      decisions: [Array(spec.legacy_marker_history.original_calls).fill(true)],
      events: structuredClone(events.slice(0, (spec.checkpoint === 'activity_pending' ? 3 : 5) + count)),
      requests: [...registration('original'), io('POST', '/api/worker/workflow-tasks/original-task/complete', {
        lease_owner: 'test:original', ...(spec.producer.language === 'python' ? {sticky_cache: {ttl_seconds: 1}} : {}),
        commands: [...Array.from({length: count}, () => ({type: 'record_version_marker', ...fields})),
          {type: 'schedule_activity', activity_type: 'parity.v1.echo_activity'}]}),
      ...(spec.checkpoint === 'activity_completed' ? [...registration('original-activity'), outcome] : [])]};
    const replacement = {...structuredClone(base), phase: 'replacement', pid: 102, status: 'completed',
      typed_output: fixture.typed_value, worker_finished: true, decisions: [[true, true]], events: structuredClone(events),
      consumer: mode === 'http' ? spec.consumer : {applicable: false, reason: 'embedded_executes_php_author_definitions'},
      ...(mode === 'embedded' ? {embedded_clock_probe: {clocks: [spec.embedded_clock_probe.marker_sequences.map(sequence => events[sequence + 1].timestamp)]}} : {}),
      requests: [...registration('replacement'), ...(spec.checkpoint === 'activity_pending' ? [outcome] : []),
        io('POST', '/api/worker/workflow-tasks/replacement-task/complete', {lease_owner: 'test:replacement', commands: [{type: 'complete_workflow'}]})]};
    return {...structuredClone(replacement), patch_deployment: {checkpoint: spec.checkpoint, change_id: spec.change_id, original, replacement}};
  }
  for (const mode of ['http', 'embedded']) test(`${id} ${mode}: preserve actual retained positions and cold original-run completion`, () => {
    const raw = model(mode);
    checkLegacyMarkerArtifacts(fixture, artifacts);
    checkLegacyMarkerPackageObservation(fixture, raw, artifacts);
    const projection = checkLegacyMarkerDeployment(fixture, raw, 'test');
    assert.equal(projection.marker_count, count);
    assert.equal(projection.command_sequence, count + 1);
  });
  for (const [name, mutate] of [
    ['missing original event', x => x.patch_deployment.original.events.pop()],
    ['new run', x => x.patch_deployment.replacement.run_id = 'replacement-run'],
    ['same process', x => x.patch_deployment.replacement.pid = 101],
    ['wrong second decision', x => x.patch_deployment.replacement.decisions[0][1] = false],
    ['changed original timestamp', x => x.patch_deployment.original.events[2].timestamp = '2026-01-01T00:00:02.123455Z'],
    ['dropped physical marker', x => x.events.splice(2, 1)],
    ['appended marker', x => x.events.push(structuredClone(x.events[2]))],
    ['conflicting version', x => x.events[2].payload.version = 2],
    ['invalid range', x => x.events[2].payload.min_supported = 2],
    ['activity shifted', x => x.events[2 + count].payload.sequence++],
    ['missing clock', x => delete x.patch_deployment.replacement.embedded_clock_probe],
    ['changed second clock', x => x.patch_deployment.replacement.embedded_clock_probe.clocks[0][1] = '2026-01-01T00:00:09.123456Z'],
    ['clock precision lost', x => x.patch_deployment.replacement.embedded_clock_probe.clocks[0][0] = '2026-01-01T00:00:02.123Z'],
  ]) test(`${id}: rejects ${name}`, () => {
    const raw = model(); mutate(raw);
    assert.throws(() => checkLegacyMarkerDeployment(fixture, raw, 'test'));
  });
  for (const phase of ['original', 'replacement']) for (const field of ['workflow_package', 'workflow_source', 'embedded_composer_lock_sha256', 'workflow_loaded_sources']) {
    test(`${id} ${phase}: rejects missing ${field}`, () => {
      const raw = model(); delete raw.patch_deployment[phase][field];
      assert.throws(() => checkLegacyMarkerPackageObservation(fixture, raw, artifacts));
    });
  }
  test(`${id}: rejects a retargeted published archive`, () => {
    const changed = structuredClone(artifacts); changed.published_sdk_artifacts.rust.archive_sha256 = '0'.repeat(64);
    assert.throws(() => checkLegacyMarkerArtifacts(fixture, changed));
  });
  test(`${id}: rejects a fabricated HTTP author clock`, () => {
    const raw = model('http'); raw.embedded_clock_probe = {clocks: []};
    assert.throws(() => checkLegacyMarkerDeployment(fixture, raw, 'test'));
  });
}
