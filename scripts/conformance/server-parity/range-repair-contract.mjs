import assert from 'node:assert/strict';

export function checkRangeArtifacts(fixture, artifacts) {
  if (!fixture.range_repair) return;
  assert.equal(artifacts.server.version, '2.5.18', 'new published reference cohort');
  assert.equal(artifacts.workflow, '2.5.8');
  assert.equal(artifacts.sdk_php, '2.2.7');
  assert.match(artifacts.server.source_commit, /^[a-f0-9]{40}$/);
  assert.match(artifacts.workflow_source_commit, /^[a-f0-9]{40}$/);
  assert.match(artifacts.sdk_php_source_commit, /^[a-f0-9]{40}$/);
  assert.match(artifacts.sdk_php_composer_lock_sha256, /^[a-f0-9]{64}$/);
  assert.match(artifacts.embedded_image_composer_lock_sha256, /^[a-f0-9]{64}$/);
  assert.match(artifacts.embedded_image_path_reference, /^[a-f0-9]{40}$/);
  for (const architecture of ['amd64', 'arm64']) {
    assert.match(artifacts.server.images['linux/' + architecture], /^durableworkflow\/server@sha256:[a-f0-9]{64}$/);
  }
  assert.deepEqual(Object.keys(artifacts.workflow_loaded_sources).sort(),
    ['TaskRepair', 'VersionResolver', 'WorkflowExecutor'].map(name => 'src/V2/Support/' + name + '.php').sort());
}

export function checkRangePackages(fixture, observation, artifacts) {
  if (!fixture.range_repair) return;
  const state = observation.range_repair;
  assert.ok(state, 'all three actual cold phases');
  for (const phase of [observation, state.original, state.incompatible, state.corrected]) {
    assert.equal(phase.sdk_php, artifacts.sdk_php);
    assert.equal(phase.sdk_php_source, artifacts.sdk_php_source_commit);
    assert.equal(phase.sdk_composer_lock_sha256, artifacts.sdk_php_composer_lock_sha256);
    if (observation.mode !== 'embedded') continue;
    assert.equal(phase.workflow_package, artifacts.workflow);
    assert.equal(phase.workflow_source, artifacts.embedded_image_path_reference, 'normal path reference stays a path reference');
    assert.deepEqual(phase.workflow_provenance,
      ['https://github.com/durable-workflow/workflow.git', artifacts.workflow, artifacts.workflow_source_commit],
      'published image provenance supplies the source commit');
    assert.equal(phase.embedded_composer_lock_sha256, artifacts.embedded_image_composer_lock_sha256);
    assert.deepEqual(phase.workflow_loaded_sources, artifacts.workflow_loaded_sources, 'actually loaded published engine bytes');
  }
}

function oneRequest(phase, suffix, prefix) {
  const selected = phase.requests.filter(row => row.method === 'POST' && row.path.startsWith(prefix) && row.path.endsWith(suffix));
  assert.equal(selected.length, 1, 'one actual ' + suffix + ' request');
  assert.equal(selected[0].status, 200);
  return selected[0];
}

export function checkRangeRepair(fixture, observation, workflowId) {
  const spec = fixture.range_repair, state = observation.range_repair;
  assert.ok(state, 'range repair phases');
  assert.equal(state.checkpoint, 'activity_completed_before_range_failure');
  assert.equal(state.checkpoint, spec.checkpoint);
  assert.equal(state.change_id, spec.change_id);
  assert.deepEqual(spec.original_range, [1, 1]);
  assert.deepEqual(spec.incompatible_range, [2, 2]);
  assert.equal(spec.recorded_version, 1);
  const {original, incompatible, corrected} = state;
  const phases = [original, incompatible, corrected];
  assert.deepEqual(phases.map(x => x.phase), ['original', 'incompatible', 'corrected-repair']);
  assert.equal(new Set(phases.map(x => x.pid)).size, 3, 'three actual cold author processes');
  for (const phase of phases) {
    assert.ok(Number.isInteger(phase.pid) && phase.pid > 0);
    assert.equal(phase.workflow_id, workflowId);
    assert.equal(phase.run_id, observation.run_id);
    assert.equal(phase.workflow_type, observation.workflow_type);
    assert.equal(phase.namespace, 'default');
    assert.equal(phase.task_queue, 'server-parity-v1');
    assert.equal(phase.mode, observation.mode);
    assert.deepEqual(phase.typed_input, observation.typed_input);
    assert.equal(phase.payload_codec, 'avro');
    assert.match(phase.definition_sha256, /^[a-f0-9]{64}$/);
  }
  assert.notEqual(incompatible.definition_sha256, original.definition_sha256);
  assert.equal(corrected.definition_sha256, original.definition_sha256, 'byte-identical compatible source restoration');
  assert.deepEqual(original.versions, [1]);
  assert.deepEqual(incompatible.versions, [], 'out-of-range call does not produce a decision');
  assert.deepEqual(corrected.versions, [1]);
  assert.deepEqual(phases.map(x => x.activity_invocations), [0, 1, 0], 'completed activity is not executed again');
  assert.equal(original.status, 'waiting');
  assert.equal(incompatible.status, 'waiting', 'blocked replay does not close the run');
  assert.equal(corrected.status, 'completed');
  assert.equal(original.output, null);
  assert.equal(incompatible.output, null);
  assert.equal(original.events.length, spec.original_event_count);
  assert.equal(incompatible.events.length, spec.blocked_event_count);
  assert.deepEqual(original.events.map(x => x.event_type), fixture.expected_events.slice(0, spec.original_event_count));
  assert.deepEqual(incompatible.events.map(x => x.event_type), fixture.expected_events.slice(0, spec.blocked_event_count));
  assert.deepEqual(incompatible.events.slice(0, original.events.length), original.events, 'immutable original history bytes');
  assert.deepEqual(corrected.events.slice(0, incompatible.events.length), incompatible.events, 'immutable blocked history bytes');
  assert.deepEqual(corrected.events, observation.events);
  assert.deepEqual(corrected.typed_output, fixture.typed_value);
  for (const type of ['VersionMarkerRecorded', 'ActivityScheduled', 'ActivityCompleted', 'RepairRequested', 'WorkflowCompleted']) {
    assert.equal(observation.events.filter(x => x.event_type === type).length, 1, type + ' committed once');
  }
  assert.ok(!observation.events.some(x => x.event_type === 'WorkflowFailed'));
  const {task: markerTask, ...marker} = original.events[2].payload;
  assert.deepEqual(marker, spec.marker, 'the original frozen five-field marker');
  assert.ok(markerTask && typeof markerTask.id === 'string' && markerTask.id);
  assert.equal(markerTask.status, 'leased');
  const finalTask = corrected.events.at(-1).payload.task;
  assert.ok(finalTask && typeof finalTask.id === 'string' && finalTask.id);
  assert.equal(finalTask.status, 'leased', 'completion annotates the actual lease');
  assert.equal(finalTask.attempt_count, spec.final_task_attempt);
  assert.equal(finalTask.repair_count, spec.final_task_repair_count);
  assert.equal(corrected.repair.outcome, 'repair_dispatched');
  assert.equal(corrected.repair.run_id, observation.run_id);
  let blockedTask;
  if (observation.mode === 'http') {
    for (const phase of phases) {
      assert.equal(phase.worker_error, null);
      assert.ok(phase.diagnostics.includes('worker.deregistered'));
      assert.ok(phase.diagnostics.includes('worker.stopped'));
      assert.equal(phase.debug_status, 200);
    }
    assert.equal(incompatible.debug.execution.liveness_state, 'workflow_replay_blocked');
    assert.deepEqual(incompatible.debug.pending_workflow_tasks, []);
    assert.deepEqual(incompatible.debug.pending_activities, []);
    assert.deepEqual(incompatible.debug.recent_failures, []);
    const failure = oneRequest(incompatible, '/fail', '/api/worker/workflow-tasks/');
    blockedTask = failure.path.split('/').at(-2);
    assert.equal(failure.request.workflow_task_attempt, 1);
    assert.equal(failure.request.failure.reason, 'version_marker_incompatible_range');
    assert.equal(failure.request.failure.sequence, 1);
    assert.equal(failure.response.recorded, true);
    assert.equal(failure.response.next_task_id, null, 'no automatic retry');
    assert.equal(incompatible.debug.latest_workflow_task_failure.task_id, blockedTask);
    assert.equal(incompatible.debug.latest_workflow_task_failure.replay_blocked, true);
    assert.equal(incompatible.debug.latest_workflow_task_failure.reason, failure.request.failure.reason);
    const repair = oneRequest(corrected, '/repair', '/api/workflows/');
    assert.ok(repair.path.includes('/runs/' + observation.run_id + '/'), 'explicit selected original run');
    const completion = oneRequest(corrected, '/complete', '/api/worker/workflow-tasks/');
    assert.equal(completion.path.split('/').at(-2), blockedTask);
    assert.equal(completion.request.workflow_task_attempt, spec.final_task_attempt);
    assert.equal(completion.request.lease_owner, finalTask.lease_owner);
    assert.notEqual(completion.request.lease_owner, failure.request.lease_owner, 'new worker authority');
    assert.deepEqual(completion.request.commands.map(x => x.type), ['complete_workflow']);
    assert.equal(completion.response.recorded, true);
  } else {
    assert.deepEqual(incompatible.failures, []);
    assert.deepEqual(incompatible.tasks.filter(x => x.task_type === 'workflow' && ['ready', 'leased'].includes(x.status)), []);
    assert.deepEqual(incompatible.tasks.filter(x => x.task_type === 'activity' && ['ready', 'leased'].includes(x.status)), []);
    const blocked = incompatible.tasks.filter(x => x.payload?.replay_blocked === true);
    assert.equal(blocked.length, 1);
    blockedTask = blocked[0].id;
    assert.equal(blocked[0].status, 'failed');
    assert.equal(blocked[0].lease_expires_at, null, 'released blocked lease');
    assert.deepEqual(Object.fromEntries(Object.entries(blocked[0].payload).filter(([key]) => key.startsWith('replay_blocked'))), {
      replay_blocked: true, replay_blocked_reason: 'version_marker_incompatible_range',
      replay_blocked_workflow_sequence: 1, replay_blocked_change_id: spec.change_id,
      replay_blocked_recorded_version: 1, replay_blocked_min_supported: 2, replay_blocked_max_supported: 2,
    }, 'actual embedded range diagnostic representation');
    assert.equal(corrected.repair.accepted, true);
    const repaired = corrected.tasks.find(x => x.id === blockedTask);
    assert.equal(repaired.status, 'completed');
    assert.equal(repaired.attempt_count, spec.final_task_attempt);
    assert.equal(repaired.repair_count, spec.final_task_repair_count);
    assert.deepEqual(corrected.failures, []);
  }
  assert.equal(finalTask.id, blockedTask, 'repair reuses the original logical workflow task');
  return {checkpoint: spec.checkpoint, change_id: spec.change_id, recorded_version: 1,
    incompatible_range: [2, 2], blocked_history_events: spec.blocked_event_count,
    same_run: true, same_task: true, restored_definition: true, activity_invocations: 1,
    final_task_attempt: spec.final_task_attempt, final_task_repair_count: spec.final_task_repair_count};
}
