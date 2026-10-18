import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {checkPatchDeployment, checkPublishedPatchArtifacts, checkPatchPackageObservation} from '../../scripts/conformance/server-parity/patch-deployment-contract.mjs';

const directory = new URL('../Fixtures/ServerParityProfiles/repeated-patch-clock/', import.meta.url);
const artifacts = JSON.parse(readFileSync(new URL('artifacts.json', directory)));
test('selected embedded project locks its complete published dependency set', () => {
  const bytes = readFileSync(new URL('embedded/composer.lock', directory));
  assert.equal(createHash('sha256').update(bytes).digest('hex'), artifacts.embedded_composer_lock_sha256);
  const engine = JSON.parse(bytes).packages.find(package_ => package_.name === 'durable-workflow/workflow');
  assert.equal(engine.version.replace(/^v/, ''), artifacts.workflow);
  assert.equal(engine.source.reference, artifacts.workflow_source_commit);
  assert.equal(engine.dist.reference, artifacts.workflow_source_commit);
});
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
      embedded_composer_lock_sha256: artifacts.embedded_composer_lock_sha256,
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
  function pressureModel(phaseName = 'replacement') {
    const raw = model('http'), phase = raw.patch_deployment[phaseName];
    const discovery = {protocol_version: '1.20', server_capabilities: {long_poll_timeout: 30}};
    const request = {worker_id: `test:${phaseName}`, task_queue: raw.task_queue,
      poll_request_id: 'original-php-poll', timeout_seconds: 0, history_page_size: 500};
    phase.diagnostics.splice(1, 0, 'worker.retrying');
    phase.requests.unshift({method: 'POST', path: '/api/worker/register', status: 201, response: discovery},
      {method: 'POST', path: '/api/worker/workflow-tasks/poll', status: 503, request,
        response: {...structuredClone(discovery), task: null, poll_status: 'backend_lock_pressure',
          reason: 'backend_lock_pressure', error: 'Backend is locked.', message: 'Retry with backoff.',
          namespace: raw.namespace, task_queue: raw.task_queue, task_kind: 'workflow_task',
          retry_after_seconds: 1, backend: {driver: 'sqlite', lock_pressure: true}}},
      {method: 'POST', path: '/api/worker/workflow-tasks/poll', status: 200,
        request: {...request, poll_request_id: 'next-php-poll'}, response: {task: {task_id: 'original-task'}}});
    return raw;
  }
  for (const phaseName of ['original', 'replacement']) test(`${checkpoint} ${phaseName}: PHP recovers declared pressure with original poll authority`, () =>
    checkPatchDeployment(fixture, pressureModel(phaseName), 'test'));
  for (const phaseName of ['original', 'replacement']) for (const [name, mutate] of [
    ['other HTTP failure', phase => phase.requests[1].status = 500],
    ['activity poll', phase => phase.requests[1].path = '/api/worker/activity-tasks/poll'],
    ['completion failure', phase => phase.requests[1].path = '/api/worker/workflow-tasks/task/complete'],
    ['leased task in pressure', phase => phase.requests[1].response.task = {task_id: 'hidden'}],
    ['wrong pressure reason', phase => phase.requests[1].response.reason = 'backend_unavailable'],
    ['generic storage failure', phase => {phase.requests[1].response.reason = 'storage_unavailable'; phase.requests[1].response.poll_status = 'storage_unavailable';}],
    ['unknown response field', phase => phase.requests[1].response.status = 'completed'],
    ['changed namespace', phase => phase.requests[1].response.namespace = 'other'],
    ['changed queue', phase => phase.requests[1].response.task_queue = 'other'],
    ['changed task kind', phase => phase.requests[1].response.task_kind = 'activity_task'],
    ['changed backend', phase => phase.requests[1].response.backend.driver = 'mysql'],
    ['no lock', phase => phase.requests[1].response.backend.lock_pressure = false],
    ['zero retry hint', phase => phase.requests[1].response.retry_after_seconds = 0],
    ['unbounded retry hint', phase => phase.requests[1].response.retry_after_seconds = 26],
    ['changed protocol', phase => phase.requests[1].response.protocol_version = 'other'],
    ['missing protocol', phase => {phase.requests[0].response.protocol_version = null; phase.requests[1].response.protocol_version = null;}],
    ['changed discovery', phase => phase.requests[1].response.server_capabilities.long_poll_timeout = 31],
    ['empty discovery', phase => {phase.requests[0].response.server_capabilities = {}; phase.requests[1].response.server_capabilities = {};}],
    ['malformed discovery', phase => {phase.requests[0].response.server_capabilities = []; phase.requests[1].response.server_capabilities = [];}],
    ['unrecorded registration', phase => phase.requests.shift()],
    ['missing diagnostic', phase => phase.diagnostics = phase.diagnostics.filter(value => value !== 'worker.retrying')],
    ['missing recovery', phase => phase.requests.splice(2, 1)],
    ['changed recovery worker', phase => phase.requests[2].request.worker_id = 'other'],
    ['changed recovery options', phase => phase.requests[2].request.history_page_size = 1],
    ['missing poll identity', phase => delete phase.requests[1].request.poll_request_id],
    ['missing recovery identity', phase => delete phase.requests[2].request.poll_request_id],
    ['cancelled transport', phase => phase.requests[1].client_cancelled = true],
    ['network error', phase => phase.requests[1].transport_error = 'ECONNRESET'],
  ]) test(`${checkpoint} ${phaseName}: PHP pressure refuses ${name}`, () => {
    const raw = pressureModel(phaseName); mutate(raw.patch_deployment[phaseName]);
    assert.throws(() => checkPatchDeployment(fixture, raw, 'test'));
  });
  test(`${checkpoint}: incomplete original-phase pressure stays refused`, () => {
    const raw = pressureModel();
    raw.patch_deployment.original.requests.unshift(structuredClone(raw.patch_deployment.replacement.requests[1]));
    assert.throws(() => checkPatchDeployment(fixture, raw, 'test'));
  });
  test(`${checkpoint}: a legacy language producer cannot use the PHP pressure contract`, () => {
    const raw = pressureModel('original'), legacy = structuredClone(fixture);
    legacy.patch_deployment.legacy_marker_history = {original_calls: 1};
    assert.throws(() => checkPatchDeployment(legacy, raw, 'test'));
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
    ['dependency lock changed', x => x.embedded_composer_lock_sha256 = '0'.repeat(64)],
    ['dependency lock missing', x => delete x.embedded_composer_lock_sha256],
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
