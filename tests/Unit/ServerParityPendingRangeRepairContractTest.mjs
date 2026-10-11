import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkRangeRepair, checkRangeArtifacts} from '../../scripts/conformance/server-parity/range-repair-contract.mjs';
import {instantNanoseconds} from '../../scripts/conformance/server-parity/contract.mjs';

const directory = new URL('../Fixtures/ServerParityProfiles/version-range-repair/', import.meta.url);
const fixture = JSON.parse(readFileSync(new URL('range-selected-timer-activity-pending.json', directory)));
const frozen = JSON.parse(readFileSync(new URL('artifacts.json', directory)));
const artifacts = JSON.parse(readFileSync(new URL('../version-range-repair-pending/artifacts.json', directory)));
const priorFailed = JSON.parse(readFileSync(new URL('../version-range-repair-pending/prior-failed-artifacts.json', directory)));

test('pending reference binds its exact published tuple and preserves the prior failed cohort', () => {
  checkRangeArtifacts(fixture, artifacts);
  const {qualification_scope: failedScope, runtime_targets: failedTargets, ...priorTuple} = priorFailed;
  const {qualification_scope: earlierScope, ...earlierTuple} = frozen;
  assert.deepEqual(priorTuple, earlierTuple);
  for (const key of ['sdk_php', 'sdk_php_source_commit', 'sdk_php_composer_lock_sha256',
    'sdk_php_loaded_sources', 'resource_limits', 'database_memory_limits_bytes']) {
    assert.deepEqual(artifacts[key], priorFailed[key], 'unchanged ' + key);
  }
  assert.deepEqual(failedTargets, ['php', 'embedded']);
  assert.deepEqual(artifacts.runtime_targets, ['php', 'embedded']);
  assert.match(artifacts.qualification_scope, /native qualification is separate/);
  assert.match(readFileSync(new URL('range-selected-timer-activity-pending.json', directory), 'utf8'), /"count": 9007199254740993,/);
});

test('pending reference rejects a well-formed source substitution', () => {
  const substituted = structuredClone(artifacts);
  substituted.server.source_commit = 'f'.repeat(40);
  assert.throws(() => checkRangeArtifacts(fixture, substituted));
});

function model(mode) {
  const typed = {type: 'list', value: [fixture.typed_value]};
  const base = {mode, workflow_id: 'test', run_id: 'original-run', workflow_type: fixture.workflow_type,
    namespace: 'default', task_queue: 'server-parity-v1', payload_codec: 'avro', typed_input: typed,
    worker_error: null, debug_status: 200, diagnostics: ['worker.deregistered', 'worker.stopped']};
  const events = fixture.expected_events.map((event_type, i) => ({
    event_type, sequence: i + 1, timestamp: '2026-10-11T00:00:04.000000Z', payload: {},
  }));
  events[2].payload = {...fixture.range_repair.marker, task: {id: 'initial', status: 'leased'}};
  const group = (index, key, kind) => ({parallel_group_id: 'select-calls:2:2', parallel_group_kind: 'mixed',
    parallel_group_mode: 'select', parallel_group_base_sequence: 2, parallel_group_size: 2,
    parallel_group_index: index, selection_member_key: key, selection_member_base_sequence: index + 2,
    selection_member_size: 1});
  events[3].payload = {sequence: 2, activity_execution_id: 'activity', ...group(0, 'activity', 'activity')};
  events[4].payload = {sequence: 3, timer_id: 'timer', delay_seconds: 3,
    fire_at: '2026-10-11T00:00:03.000001Z', ...group(1, 'timer', 'timer')};
  events[5].payload = {...structuredClone(events[4].payload), fired_at: '2026-10-11T00:00:03.000001Z'};
  events[6].payload = {selection_group_id: 'select-calls:2:2', selection_group_base_sequence: 2,
    selection_group_size: 2, member_key: 'timer', member_index: 1, member_base_sequence: 3,
    member_size: 1, operation_kind: 'timer', operation_identity: 'timer', outcome: 'completed',
    resolution_event_type: 'TimerFired', resolution_event_id: 'timer-event'};
  const repairValues = {liveness_state: 'workflow_replay_blocked', wait_kind: fixture.range_repair.repair_wait_kind, task_id: 'blocked', task_type: 'workflow'};
  events[7].payload = {...repairValues, workflow_command_id: 'repair-command',
    task: {id: 'blocked', status: 'ready', attempt_count: 1, repair_count: 1},
    command: {id: 'repair-command', sequence: 2, type: 'repair', status: 'accepted', outcome: 'repair_dispatched',
      resolved_run_id: base.run_id, target_scope: mode === 'http' ? 'instance' : 'run',
      requested_run_id: mode === 'http' ? null : base.run_id, source: mode === 'http' ? 'control_plane' : 'php',
      payload_codec: 'avro', principal_type: 'auth:token', principal_id: 'legacy-token', principal_label: 'Admin',
      context: {principal: {type: 'auth:token', id: 'legacy-token', label: 'Admin'}},
      caller_label: 'Standalone Server', request_path: '/api/workflows/test/runs/original-run/repair', auth_status: 'authorized',
      auth_method: 'token', request_method: 'POST', request_fingerprint: 'sha256:' + 'a'.repeat(64)}};
  events[7].decoded = {repair_command: repairValues};
  events[10].payload = {task: {id: 'final', status: 'leased', attempt_count: 1, repair_count: 0}};
  const pending = {activity_execution_id: 'activity', queue: fixture.range_repair.activity_queue,
    status: 'pending', attempt_count: 0};
  const activityTask = {id: 'activity-task', task_type: 'activity', status: 'ready', attempt_count: 0,
    queue: fixture.range_repair.activity_queue, payload: {activity_execution_id: 'activity'}};
  const blocked = {id: 'blocked', task_type: 'workflow', status: 'failed', lease_expires_at: null,
    payload: {replay_blocked: true, replay_blocked_reason: 'version_marker_incompatible_range',
      replay_blocked_workflow_sequence: 1, replay_blocked_change_id: fixture.range_repair.change_id,
      replay_blocked_recorded_version: 1, replay_blocked_min_supported: 2, replay_blocked_max_supported: 2}};
  const snapshot = length => ({...structuredClone(base), status: 'waiting', output: null,
    events: structuredClone(events.slice(0, length)), failures: [],
    tasks: [structuredClone(activityTask)], debug: {pending_activities: [structuredClone(pending)],
      pending_workflow_tasks: [], recent_failures: [], execution: {liveness_state: 'workflow_replay_blocked'}}});
  const original = {...snapshot(5), phase: 'original', pid: 101, versions: [1],
    activity_invocations: 0, definition_sha256: 'a'.repeat(64), selections: []};
  const incompatible = {...snapshot(7), phase: 'incompatible', pid: 102, versions: [],
    activity_invocations: 0, definition_sha256: 'b'.repeat(64), selections: [],
    tasks: [structuredClone(activityTask), blocked],
    requests: [{stage: 'incompatible', method: 'POST', path: '/api/worker/workflow-tasks/blocked/fail', status: 200,
      request: {workflow_task_attempt: 1, lease_owner: 'incompatible-worker', failure: {reason: 'version_marker_incompatible_range',
        type: 'DurableWorkflow\\Exception\\NonDeterministicWorkflow', sequence: 1}},
      response: {recorded: true, next_task_id: null}}]};
  incompatible.debug.latest_workflow_task_failure = {task_id: 'blocked', replay_blocked: true,
    reason: 'version_marker_incompatible_range', sequence: 1};
  const afterRepair = snapshot(8), afterWaiting = snapshot(8);
  afterWaiting.tasks.push({id: 'blocked', status: 'completed', attempt_count: 2, repair_count: 1});
  const corrected = {...structuredClone(base), phase: 'corrected-repair', pid: 103, status: 'completed',
    versions: [1, 1], selections: [{key: 'timer', kind: 'timer', identity: 'timer'}, {key: 'timer', kind: 'timer', identity: 'timer'}],
    activity_invocations: 1, definition_sha256: original.definition_sha256, typed_output: structuredClone(fixture.typed_value),
    events: structuredClone(events), repair: {accepted: true, outcome: 'repair_dispatched', run_id: base.run_id},
    checkpoints: {after_repair: afterRepair, after_waiting: afterWaiting}, failures: [],
    tasks: [{id: 'final', status: 'completed'}],
    requests: [
      {stage: 'repaired-wait', method: 'POST', path: '/api/worker/workflow-tasks/blocked/fail', status: 200,
        request: {workflow_task_attempt: 2, lease_owner: 'restored-worker', failure: {type: 'WorkflowTaskWaitingForHistory'}},
        response: {outcome: 'waiting_for_history', recorded: true, next_task_id: null}},
      {stage: 'final', method: 'POST', path: '/api/worker/workflow-tasks/final/complete', status: 200,
        request: {workflow_task_attempt: 1, commands: [{type: 'complete_workflow'}]}, response: {recorded: true}},
      {stage: 'corrected-repair', method: 'POST', path: '/api/workflows/test/runs/original-run/repair', status: 200},
    ]};
  return {...structuredClone(corrected), range_repair: {checkpoint: fixture.range_repair.checkpoint,
    change_id: fixture.range_repair.change_id, original, incompatible, corrected}};
}

for (const mode of ['http', 'embedded']) {
  test(mode + ': modeled timer selection preserves pending work through repair and waiting', () => {
    assert.equal(checkRangeRepair(fixture, model(mode), 'test', instantNanoseconds).repaired_task_waits, true);
  });
  for (const [name, change] of [
    ['same process', x => x.incompatible.pid = x.original.pid],
    ['activity ran before blockage', x => x.incompatible.activity_invocations = 1],
    ['repair changes original history', x => x.corrected.checkpoints.after_repair.events[2].payload.version = 2],
    ['waiting adds another schedule', x => x.corrected.checkpoints.after_waiting.events.push(x.original.events[3])],
    ['winner becomes activity', x => x.corrected.events[6].payload.member_key = 'activity'],
    ['timer ID changed', x => x.corrected.events[5].payload.timer_id = 'other'],
    ['timer fires one microsecond early', x => x.corrected.events[5].payload.fired_at = '2026-10-11T00:00:03.000000Z'],
    ['selection loses timer identity', x => x.corrected.selections[0].identity = 'other'],
    ['original definition changes', x => x.corrected.definition_sha256 = 'c'.repeat(64)],
    ['int64 output changes', x => x.corrected.typed_output.value.count.value = '9007199254740992'],
    ['repair codec loses pending wait kind', x => x.corrected.events[7].decoded.repair_command.wait_kind = null],
    ['repair changes task', x => x.corrected.events[7].payload.task.id = 'other'],
    ['final task reuses waiting task', x => x.corrected.events[10].payload.task.id = 'blocked'],
    ['final task invents a repair', x => x.corrected.events[10].payload.task.repair_count = 1],
    ['pending original activity disappears', x => mode === 'http'
      ? x.incompatible.debug.pending_activities = [] : x.incompatible.tasks = x.incompatible.tasks.filter(t => t.task_type !== 'activity')],
    ['pending activity starts during repair', x => mode === 'http'
      ? x.corrected.checkpoints.after_repair.debug.pending_activities[0].attempt_count = 1
      : x.corrected.checkpoints.after_repair.tasks[0].attempt_count = 1],
    ['waiting task wrong attempt', x => mode === 'http'
      ? x.corrected.requests[0].request.workflow_task_attempt = 1
      : x.corrected.checkpoints.after_waiting.tasks[1].attempt_count = 1],
  ]) {
    test(mode + ': reject ' + name, () => {
      const raw = model(mode);
      change(raw.range_repair);
      assert.throws(() => checkRangeRepair(fixture, raw, 'test', instantNanoseconds));
    });
  }
}
