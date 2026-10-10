import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkPatchDeployment, checkPublishedPatchArtifacts} from '../../scripts/conformance/server-parity/patch-deployment-contract.mjs';

const directory = new URL('../Fixtures/ServerParityProfiles/published-sdk-patch-replay/', import.meta.url);
const artifacts = JSON.parse(readFileSync(new URL('artifacts.json', directory)));
for (const language of ['rust', 'python']) for (const checkpoint of ['pending', 'completed']) {
  const fixture = JSON.parse(readFileSync(new URL(`patch-${language}-activity-${checkpoint}.json`, directory)));
  function model(mode = 'http') {
    const events = fixture.expected_events.map((event_type, index) => ({event_type, sequence: index + 1,
      timestamp: `2026-01-01T00:00:0${index}Z`, payload: {sequence: 1, activity_execution_id: 'original-activity'}}));
    const base = {mode, sdk_php: '2.2.6', sdk_php_source: 'a'.repeat(40), workflow_id: 'test', run_id: 'original-run',
      workflow_type: fixture.workflow_type, namespace: 'default', task_queue: 'server-parity-v1', payload_codec: 'avro',
      typed_input: {type: 'list', value: [structuredClone(fixture.typed_value)]}, workflow_package: '2.5.5',
      instance: {id: 'test', namespace: 'default', workflow_type: fixture.workflow_type, current_run_id: 'original-run'}};
    const completion = commands => ({method: 'POST', path: '/api/worker/workflow-tasks/task/complete', status: 200, request: {commands}});
    const activity = {method: 'POST', path: '/api/worker/activity-tasks/original-activity/complete', status: 200, request: {}};
    const original = {...structuredClone(base), phase: 'original', pid: 101, status: 'waiting', output: null, decisions: [],
      events: events.slice(0, checkpoint === 'pending' ? 3 : 5), diagnostics: ['worker.registered', 'worker.stopped'],
      requests: [{...completion([{type: 'schedule_activity', activity_type: 'parity.v1.echo_activity'}]),
        request: {commands: [{type: 'schedule_activity', activity_type: 'parity.v1.echo_activity'}], sticky_cache: {ttl_seconds: 1}}},
        ...(checkpoint === 'completed' ? [activity] : [])]};
    const replacement = {...structuredClone(base), phase: 'replacement', pid: 102, status: 'completed',
      typed_output: structuredClone(fixture.typed_value), events: structuredClone(events), decisions: [[false, false]], worker_finished: true,
      consumer: mode === 'embedded' ? {applicable: false, reason: 'embedded_executes_php_author_definitions'} : structuredClone(fixture.patch_deployment.consumer),
      requests: [{method: 'POST', path: '/api/worker/register', status: 200}, ...(checkpoint === 'pending' ? [activity] : []),
        completion([{type: 'complete_workflow'}]), {method: 'DELETE', path: '/api/worker/registrations/replacement', status: 200}]};
    return {...structuredClone(replacement), patch_deployment: {checkpoint: fixture.patch_deployment.checkpoint,
      change_id: fixture.patch_deployment.change_id, original: structuredClone(original), replacement: structuredClone(replacement)}};
  }
  for (const mode of ['http', 'embedded']) {
    test(`${language} ${checkpoint} ${mode}: model preserves original run`, () => {
      checkPublishedPatchArtifacts(fixture, artifacts);
      assert.equal(checkPatchDeployment(fixture, model(mode), 'test').marker_count, 0);
    });
    for (const [name, mutate] of [
      ['same process', x => x.replacement.pid = x.original.pid],
      ['different original run', x => x.replacement.run_id = 'replacement-run'],
      ['history rewritten', x => x.original.events[2].payload.activity_execution_id = 'other'],
      ['worker identity bypassed', x => x.original.events[1].payload.workflow_definition_fingerprint_source = 'worker'],
      ['wrong legacy branch', x => x.replacement.decisions[0][0] = true],
      ['second call drifts', x => x.replacement.decisions[0][1] = true],
      ['no actual decisions', x => x.replacement.decisions = []],
      ['int64 rounded', x => x.replacement.typed_input.value[0].value.count.value = '9007199254740992'],
      ['consumer mislabeled', x => x.replacement.consumer = {language: 'other', version: 'latest'}],
    ]) test(`${language} ${checkpoint} ${mode}: model rejects ${name}`, () => {
      const raw = model(mode); mutate(raw.patch_deployment);
      assert.throws(() => checkPatchDeployment(fixture, raw, 'test'));
    });
  }
  for (const [name, mutate] of [
    ['unrecorded registration', x => x.requests = x.requests.filter(r => r.path !== '/api/worker/register')],
    ['missing shutdown', x => x.worker_finished = false],
    ['missing deregistration', x => x.requests = x.requests.filter(r => r.method !== 'DELETE')],
    ['SDK refusal hidden', x => x.requests[0].status = 409],
    ['new activity emitted', x => x.requests.find(r => r.path.endsWith('/complete') && r.path.includes('workflow-tasks')).request.commands.push({type: 'schedule_activity'})],
    ['new marker emitted', x => x.requests.find(r => r.path.endsWith('/complete') && r.path.includes('workflow-tasks')).request.commands.push({type: 'record_version_marker'})],
  ]) test(`${language} ${checkpoint} HTTP: model rejects ${name}`, () => {
    const raw = model(); mutate(raw.patch_deployment.replacement);
    assert.throws(() => checkPatchDeployment(fixture, raw, 'test'));
  });
  function pressureModel() {
    const raw = model();
    const request = {worker_id: 'test:replacement', task_queue: 'server-parity-v1', poll_request_id: 'original-poll', timeout_seconds: 1};
    const pressure = {method: 'POST', path: '/api/worker/workflow-tasks/poll', status: 503, request,
      response: {task: null, poll_status: 'backend_lock_pressure'}, response_retry_after: '1', client_cancelled: false, transport_error: null};
    raw.patch_deployment.replacement.requests.splice(1, 0, pressure, {...structuredClone(pressure), status: 200, response: {task: {task_id: 'original-task'}}});
    return raw;
  }
  test(`${language} ${checkpoint}: model accepts same-request pressure recovery`, () => checkPatchDeployment(fixture, pressureModel(), 'test'));
  for (const [name, mutate] of [
    ['missing Retry-After', requests => delete requests[1].response_retry_after],
    ['zero Retry-After', requests => requests[1].response_retry_after = '0'],
    ['oversized retry hint', requests => requests[1].response_retry_after = '26'],
    ['missing poll identity', requests => delete requests[1].request.poll_request_id],
    ['new retry identity', requests => requests[2].request.poll_request_id = 'new-poll'],
    ['changed retry request', requests => requests[2].request.timeout_seconds = 2],
    ['no successful retry', requests => requests.splice(2, 1)],
    ['hidden leased task', requests => requests[1].response.task = {task_id: 'unknown-task'}],
    ['other pressure', requests => requests[1].response.poll_status = 'other'],
  ]) test(`${language} ${checkpoint}: model rejects ${name}`, () => {
    const raw = pressureModel(); mutate(raw.patch_deployment.replacement.requests);
    assert.throws(() => checkPatchDeployment(fixture, raw, 'test'));
  });
  function cancelledModel() {
    const raw = model();
    const requests = raw.patch_deployment.replacement.requests;
    requests.splice(requests.length - 1, 0, {method: 'POST', path: '/api/worker/query-tasks/poll', status: 0,
      request: {worker_id: 'test:replacement', task_queue: 'server-parity-v1'}, client_cancelled: true, transport_error: null});
    return raw;
  }
  test(`${language} ${checkpoint}: model accepts query cancellation after completion`, () => checkPatchDeployment(fixture, cancelledModel(), 'test'));
  for (const [name, mutate] of [
    ['completed HTTP refusal', requests => requests.at(-2).status = 409],
    ['cancelled workflow poll', requests => requests.at(-2).path = '/api/worker/workflow-tasks/poll'],
    ['hidden network failure', requests => requests.at(-2).transport_error = 'ECONNRESET'],
    ['other cancelled worker', requests => requests.at(-2).request.worker_id = 'other-worker'],
    ['other cancelled queue', requests => requests.at(-2).request.task_queue = 'other-queue'],
    ['cancellation before completion', requests => requests.unshift(requests.splice(-2, 1)[0])],
  ]) test(`${language} ${checkpoint}: model rejects ${name}`, () => {
    const raw = cancelledModel(); mutate(raw.patch_deployment.replacement.requests);
    assert.throws(() => checkPatchDeployment(fixture, raw, 'test'));
  });
  for (const [name, mutate] of [
    ['old frozen SDK version', x => x[`sdk_${language}`] = language === 'rust' ? '3.4.1' : '2.5.0'],
    ['archive retargeted', x => x.published_sdk_artifacts[language].archive_sha256 = '0'.repeat(64)],
    ['source omitted', x => delete x.published_sdk_artifacts[language].source_commit],
  ]) test(`${language} ${checkpoint}: model rejects ${name}`, () => {
    const tuple = structuredClone(artifacts); mutate(tuple);
    assert.throws(() => checkPublishedPatchArtifacts(fixture, tuple));
  });
}
