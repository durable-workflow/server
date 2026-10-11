import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {checkRangeArtifacts, checkRangePackages, checkRangeRepair} from '../../scripts/conformance/server-parity/range-repair-contract.mjs';

const directory = new URL('../Fixtures/ServerParityProfiles/version-range-repair/', import.meta.url);
const fixture = JSON.parse(readFileSync(new URL('range-marked-activity-completed.json', directory)));
const artifacts = JSON.parse(readFileSync(new URL('artifacts.json', directory)));
const digest = bytes => createHash('sha256').update(bytes).digest('hex');

test('range profile locks normal published SDK source and dist', () => {
  checkRangeArtifacts(fixture, artifacts);
  const bytes = readFileSync(new URL('sdk/composer.lock', directory));
  assert.equal(digest(bytes), artifacts.sdk_php_composer_lock_sha256);
  const package_ = JSON.parse(bytes).packages.find(x => x.name === 'durable-workflow/sdk');
  assert.equal(package_.version, artifacts.sdk_php);
  assert.equal(package_.source.reference, artifacts.sdk_php_source_commit);
  assert.equal(package_.dist.reference, artifacts.sdk_php_source_commit);
  assert.equal(package_.dist.type, 'zip');
});

function model(mode) {
  const events = fixture.expected_events.map((event_type, index) => ({event_type, sequence: index + 1, payload:
    event_type === 'VersionMarkerRecorded' ? {...fixture.range_repair.marker, task: {id: 'initial', status: 'leased'}}
      : event_type === 'WorkflowCompleted' ? {task: {id: 'blocked', status: 'leased', lease_owner: 'corrected-worker', attempt_count: 2, repair_count: 1}}
        : {sequence: 2, activity_execution_id: 'original-activity'}}));
  const base = {mode, workflow_id: 'test', run_id: 'original-run', workflow_type: fixture.workflow_type,
    namespace: 'default', task_queue: 'server-parity-v1', payload_codec: 'avro',
    typed_input: {type: 'list', value: [fixture.typed_value]},
    sdk_php: artifacts.sdk_php, sdk_php_source: artifacts.sdk_php_source_commit,
    sdk_composer_lock_sha256: artifacts.sdk_php_composer_lock_sha256,
    workflow_package: artifacts.workflow, workflow_source: artifacts.embedded_image_path_reference,
    workflow_provenance: ['https://github.com/durable-workflow/workflow.git', artifacts.workflow, artifacts.workflow_source_commit],
    embedded_composer_lock_sha256: artifacts.embedded_image_composer_lock_sha256,
    workflow_loaded_sources: structuredClone(artifacts.workflow_loaded_sources),
    worker_error: null, debug_status: 200, diagnostics: ['worker.deregistered', 'worker.stopped']};
  const original = {...structuredClone(base), phase: 'original', pid: 101, status: 'waiting', output: null,
    versions: [1], activity_invocations: 0, definition_sha256: 'a'.repeat(64), events: structuredClone(events.slice(0, 4))};
  const incompatible = {...structuredClone(base), phase: 'incompatible', pid: 102, status: 'waiting', output: null,
    versions: [], activity_invocations: 1, definition_sha256: 'b'.repeat(64), events: structuredClone(events.slice(0, 6)),
    debug: {execution: {liveness_state: 'workflow_replay_blocked'}, pending_workflow_tasks: [], pending_activities: [], recent_failures: [],
      latest_workflow_task_failure: {task_id: 'blocked', replay_blocked: true, reason: 'version_marker_incompatible_range'}},
    requests: [{method: 'POST', path: '/api/worker/workflow-tasks/blocked/fail', status: 200,
      request: {workflow_task_attempt: 1, lease_owner: 'bad-worker', failure: {reason: 'version_marker_incompatible_range', sequence: 1}},
      response: {recorded: true, next_task_id: null}}],
    failures: [], tasks: [{id: 'blocked', task_type: 'workflow', status: 'failed', lease_expires_at: null, payload: {
      replay_blocked: true, replay_blocked_reason: 'version_marker_incompatible_range', replay_blocked_workflow_sequence: 1,
      replay_blocked_change_id: fixture.range_repair.change_id, replay_blocked_recorded_version: 1,
      replay_blocked_min_supported: 2, replay_blocked_max_supported: 2}}]};
  const corrected = {...structuredClone(base), phase: 'corrected-repair', pid: 103, status: 'completed',
    versions: [1], activity_invocations: 0, definition_sha256: original.definition_sha256,
    typed_output: fixture.typed_value, events: structuredClone(events), repair: {outcome: 'repair_dispatched', run_id: base.run_id, accepted: true},
    requests: [{method: 'POST', path: '/api/workflows/test/runs/original-run/repair', status: 200},
      {method: 'POST', path: '/api/worker/workflow-tasks/blocked/complete', status: 200,
        request: {workflow_task_attempt: 2, lease_owner: 'corrected-worker', commands: [{type: 'complete_workflow'}]}, response: {recorded: true}}],
    tasks: [{id: 'blocked', status: 'completed', attempt_count: 2, repair_count: 1}], failures: []};
  return {...structuredClone(corrected), range_repair: {checkpoint: fixture.range_repair.checkpoint,
    change_id: fixture.range_repair.change_id, original, incompatible, corrected}};
}

for (const mode of ['http', 'embedded']) {
  test(mode + ': modeled three-phase range repair', () => {
    const raw = model(mode);
    checkRangePackages(fixture, raw, artifacts);
    assert.equal(checkRangeRepair(fixture, raw, 'test').same_task, true);
  });
  for (const [name, change] of [
    ['same cold process', x => x.incompatible.pid = x.original.pid],
    ['changed run', x => x.corrected.run_id = 'another-run'],
    ['premature terminal failure', x => x.incompatible.status = 'failed'],
    ['incompatible call returns version', x => x.incompatible.versions = [1]],
    ['activity executes twice', x => x.corrected.activity_invocations = 1],
    ['replacement source differs', x => x.corrected.definition_sha256 = 'c'.repeat(64)],
    ['original marker rewritten', x => x.incompatible.events[2].payload.version = 2],
    ['blocked history rewritten', x => x.corrected.events[5].payload.sequence = 3],
    ['repair creates another task', x => x.corrected.events[7].payload.task.id = 'another-task'],
    ['repair resets attempt', x => x.corrected.events[7].payload.task.attempt_count = 1],
    ['repair count missing', x => delete x.corrected.events[7].payload.task.repair_count],
  ]) test(mode + ': model refuses ' + name, () => {
    const raw = model(mode); change(raw.range_repair);
    raw.events = raw.range_repair.corrected.events;
    assert.throws(() => checkRangeRepair(fixture, raw, 'test'));
  });
}
for (const [name, change] of [
  ['automatic retry', x => x.debug.pending_workflow_tasks = [{id: 'retry'}]],
  ['still pending activity', x => x.debug.pending_activities = [{id: 'original-activity'}]],
  ['ordinary failure category', x => x.requests[0].request.failure.reason = 'application_error'],
  ['failure loses sequence', x => delete x.requests[0].request.failure.sequence],
  ['failure creates next task', x => x.requests[0].response.next_task_id = 'retry'],
  ['missing replay block flag', x => delete x.debug.latest_workflow_task_failure.replay_blocked],
]) test('HTTP model refuses ' + name, () => {
  const raw = model('http'); change(raw.range_repair.incompatible);
  assert.throws(() => checkRangeRepair(fixture, raw, 'test'));
});
for (const [name, change] of [
  ['blocked lease retained', x => x.tasks[0].lease_expires_at = '2026-01-01T00:00:00Z'],
  ['range identity changed', x => x.tasks[0].payload.replay_blocked_change_id = 'another-change'],
  ['recorded version changed', x => x.tasks[0].payload.replay_blocked_recorded_version = 2],
  ['terminal failure row', x => x.failures = [{id: 'failure'}]],
]) test('embedded model refuses ' + name, () => {
  const raw = model('embedded'); change(raw.range_repair.incompatible);
  assert.throws(() => checkRangeRepair(fixture, raw, 'test'));
});
for (const [name, change] of [
  ['unqualified SDK version', x => x.sdk_php = '2.2.6'],
  ['source path reference used as commit', x => x.workflow_provenance[2] = artifacts.embedded_image_path_reference],
  ['changed installed dependency lock', x => x.embedded_composer_lock_sha256 = '0'.repeat(64)],
  ['stale loaded executor', x => x.workflow_loaded_sources['src/V2/Support/WorkflowExecutor.php'] = '0'.repeat(64)],
]) test('package model refuses ' + name, () => {
  const raw = model('embedded'); change(raw.range_repair.incompatible);
  assert.throws(() => checkRangePackages(fixture, raw, artifacts));
});
