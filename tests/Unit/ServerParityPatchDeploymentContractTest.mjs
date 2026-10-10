import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkPatchDeployment} from '../../scripts/conformance/server-parity/patch-deployment-contract.mjs';

for (const family of ['insertion', 'marked', 'created']) for (const checkpoint of ['pending', 'completed']) {
  const fixture = JSON.parse(readFileSync(new URL(`../Fixtures/ServerParityPending/patch-${family}-activity-${checkpoint}.json`, import.meta.url)));
  const marked = family !== 'insertion';
  const checkpointLength = (checkpoint === 'pending' ? 3 : 5) + Number(marked);
  function model(mode = 'http') {
    const events = fixture.expected_events.map((event_type, index) => ({event_type, sequence: index + 1,
      timestamp: `2026-01-01T00:00:0${index}Z`, payload: event_type === 'VersionMarkerRecorded'
        ? {...structuredClone(fixture.patch_deployment.marker), task: {id: 'task', type: 'workflow', status: 'leased', lease_owner: 'original-worker'}} : {sequence: marked ? 2 : 1, activity_execution_id: 'original-activity'}}));
    const base = {mode, sdk_php: '2.2.6', sdk_php_source: 'a'.repeat(40), workflow_id: 'test', run_id: 'original-run',
      workflow_type: fixture.workflow_type, namespace: 'default', task_queue: 'server-parity-v1',
      payload_codec: 'avro', typed_input: {type: 'list', value: [fixture.typed_value]}};
    base.workflow_package = '2.5.5';
    base.instance = {id: 'test', namespace: 'default', workflow_type: fixture.workflow_type, current_run_id: 'original-run'};
    const workflow = commands => ({method: 'POST', path: '/api/worker/workflow-tasks/task/complete',
      status: 200, request: {commands, lease_owner: 'original-worker'}});
    const activity = {method: 'POST', path: '/api/worker/activity-tasks/activity/complete', status: 200, request: {}};
    const original = {...base, phase: 'original', pid: 101, status: 'waiting', output: null,
      decisions: marked ? [fixture.patch_deployment.expected_decisions] : [],
      events: events.slice(0, checkpointLength),
      diagnostics: ['worker.registered', 'worker.stopped'],
      requests: [workflow([...(marked ? [{type: 'record_version_marker', ...fixture.patch_deployment.marker}] : []), {type: 'schedule_activity', activity_type: 'parity.v1.echo_activity'}]),
        ...(checkpoint === 'completed' ? [activity] : [])]};
    const replacement = {...base, phase: 'replacement', pid: 102, status: 'completed',
      typed_output: fixture.typed_value, decisions: [fixture.patch_deployment.expected_decisions], events,
      diagnostics: ['worker.registered', 'worker.stopped'],
      requests: [...(checkpoint === 'pending' ? [activity] : []), workflow([{type: 'complete_workflow'}])]};
    return {...structuredClone(replacement),
      patch_deployment: {checkpoint: fixture.patch_deployment.checkpoint,
        change_id: fixture.patch_deployment.change_id, original: structuredClone(original), replacement: structuredClone(replacement)}};
  }
  for (const mode of ['http', 'embedded']) {
    test(`${checkpoint} ${mode}: model accepts explicit cold legacy replay`, () => {
      assert.equal(checkPatchDeployment(fixture, model(mode), 'test').marker_count, Number(marked));
    });
    const mutations = [
      ['same worker process', x => x.replacement.pid = x.original.pid],
      ['replacement changes run', x => x.replacement.run_id = 'new-run'],
      ['checkpoint advances early', x => x.original.events.push({event_type: 'WorkflowCompleted'})],
      ['checkpoint history changes', x => x.original.events[2].payload.activity_execution_id = 'different'],
      ['original finishes early', x => x.original.status = 'completed'],
      ['wrong branch chosen', x => x.replacement.decisions[0][0] = !marked],
      ['second call drifts', x => x.replacement.decisions[0][1] = !marked],
      ['no actual patch replay', x => x.replacement.decisions = []],
      ['missing repeated call', x => x.replacement.decisions[0].pop()],
      ['input int64 changes', x => x.replacement.typed_input.value[0].value.count.value = '9007199254740992'],
      ['SDK version retargeted', x => x.replacement.sdk_php = 'latest'],
      ['SDK source retargeted', x => x.replacement.sdk_php_source = 'b'.repeat(40)],
    ];
    for (const [label, mutate] of mutations) {
      test(`${checkpoint} ${mode}: model rejects ${label}`, () => {
        const raw = model(mode); mutate(raw.patch_deployment);
        assert.throws(() => checkPatchDeployment(fixture, raw, 'test'));
      });
    }
  }
  if (marked) for (const mode of ['http', 'embedded']) for (const [label, mutate] of [
    ['missing original decisions', x => x.original.decisions = []],
    ['original decision drifts', x => x.original.decisions[0][0] = false],
    ['marker change ID drifts', x => x.replacement.events[2].payload.change_id = 'different'],
    ['marker version drifts', x => x.replacement.events[2].payload.version = 2],
    ['marker range drifts', x => x.replacement.events[2].payload.min_supported = 0],
    ['marker has extra field', x => x.replacement.events[2].payload.payload_codec = 'avro'],
    ['marker sequence drifts', x => x.replacement.events[2].payload.sequence = 2],
    ['second marker added', x => x.replacement.events.push(structuredClone(x.replacement.events[2]))],
  ]) {
    test(`${family} ${checkpoint} ${mode}: model rejects ${label}`, () => {
      const raw = model(mode); mutate(raw.patch_deployment);
      raw.events = raw.patch_deployment.replacement.events;
      assert.throws(() => checkPatchDeployment(fixture, raw, 'test'));
    });
  }
  for (const [label, mutate] of [
    ['missing physical namespace', x => x.original.instance.namespace = null],
    ['replacement crosses namespace', x => x.replacement.instance.namespace = 'other'],
    ['instance points at another run', x => x.replacement.instance.current_run_id = 'new-run'],
    ['instance identity changes', x => x.replacement.instance.id = 'new-instance'],
    ['original package retargeted', x => x.original.workflow_package = '2.5.4'],
    ['replacement package retargeted', x => x.replacement.workflow_package = '2.5.6'],
  ]) {
    test(`${checkpoint} embedded: model rejects ${label}`, () => {
      const raw = model('embedded'); mutate(raw.patch_deployment);
      assert.throws(() => checkPatchDeployment(fixture, raw, 'test'));
    });
  }
  for (const [label, mutate] of [
    ['marker emitted', x => x.requests.find(item => item.path.includes('workflow-tasks')).request.commands.push({type: 'record_version_marker'})],
    ['old activity scheduled again', x => x.requests.find(item => item.path.includes('workflow-tasks')).request.commands.push({type: 'schedule_activity'})],
    ['hidden worker failure', x => x.diagnostics.push('worker.failed')],
    ['hidden shutdown failure', x => x.diagnostics.push('worker.shutdown_failed')],
    ['accepted request refused', x => x.requests[0].status = 409],
    ['duplicated activity outcome', x => x.requests.push({method: 'POST', path: '/api/worker/activity-tasks/duplicate/complete', status: 200, request: {}})],
  ]) {
    test(`${checkpoint} HTTP: model rejects ${label}`, () => {
      const raw = model(); mutate(raw.patch_deployment.replacement);
      assert.throws(() => checkPatchDeployment(fixture, raw, 'test'));
    });
  }
}
