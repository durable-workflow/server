import assert from 'node:assert/strict';

function request(phase, stage, suffix, prefix) {
  const rows = phase.requests.filter(row => row.stage === stage && row.method === 'POST'
    && row.path.startsWith(prefix) && row.path.endsWith(suffix));
  assert.equal(rows.length, 1, 'one actual ' + stage + ' ' + suffix);
  assert.equal(rows[0].status, 200);
  return rows[0];
}

function pendingActivity(state, mode, activityId, queue) {
  assert.equal(state.status, 'waiting');
  assert.equal(state.output, null);
  assert.ok(!state.events.some(x => ['ActivityStarted', 'ActivityCompleted', 'WorkflowFailed'].includes(x.event_type)),
    'original activity has never started');
  if (mode === 'http') {
    assert.equal(state.debug_status, 200);
    const rows = state.debug.pending_activities;
    assert.equal(rows.length, 1);
    assert.equal(rows[0].activity_execution_id, activityId);
    assert.equal(rows[0].queue, queue);
    assert.equal(rows[0].status, 'pending');
    assert.equal(rows[0].attempt_count, 0);
    assert.equal(rows[0].started_at ?? null, null);
    assert.equal(rows[0].current_attempt_id ?? null, null);
    assert.deepEqual(state.debug.recent_failures, []);
  } else {
    const rows = state.tasks.filter(x => x.task_type === 'activity' && ['ready', 'leased'].includes(x.status));
    assert.equal(rows.length, 1);
    assert.equal(rows[0].payload.activity_execution_id, activityId);
    assert.equal(rows[0].queue, queue);
    assert.equal(rows[0].status, 'ready');
    assert.equal(rows[0].attempt_count, 0);
    assert.deepEqual(state.failures, []);
  }
}

export function checkPendingRangeRepair(fixture, observation, workflowId, instant) {
  const spec = fixture.range_repair, state = observation.range_repair;
  assert.equal(spec.checkpoint, 'timer_selected_with_activity_pending');
  assert.equal(spec.pending_activity, true);
  assert.equal(state.checkpoint, spec.checkpoint);
  assert.equal(state.change_id, spec.change_id);
  assert.deepEqual(spec.original_range, [1, 1]);
  assert.deepEqual(spec.incompatible_range, [2, 2]);
  assert.equal(spec.recorded_version, 1);
  const {original, incompatible, corrected} = state;
  const phases = [original, incompatible, corrected];
  assert.deepEqual(phases.map(x => x.phase), ['original', 'incompatible', 'corrected-repair']);
  assert.equal(new Set(phases.map(x => x.pid)).size, 3, 'cold author processes');
  for (const phase of phases) {
    assert.ok(Number.isInteger(phase.pid) && phase.pid > 0);
    assert.equal(phase.workflow_id, workflowId);
    assert.equal(phase.run_id, observation.run_id);
    assert.equal(phase.workflow_type, fixture.workflow_type);
    assert.equal(phase.namespace, 'default');
    assert.equal(phase.task_queue, 'server-parity-v1');
    assert.equal(phase.mode, observation.mode);
    assert.equal(phase.payload_codec, 'avro');
    assert.deepEqual(phase.typed_input, observation.typed_input);
    assert.match(phase.definition_sha256, /^[a-f0-9]{64}$/);
  }
  assert.notEqual(incompatible.definition_sha256, original.definition_sha256);
  assert.equal(corrected.definition_sha256, original.definition_sha256);
  assert.deepEqual(phases.map(x => x.activity_invocations), [0, 0, 1]);
  assert.deepEqual(phases.map(x => x.versions), [[1], [], [1, 1]]);
  assert.deepEqual(phases.map(x => x.status), ['waiting', 'waiting', 'completed']);
  assert.deepEqual(original.selections, []);
  assert.deepEqual(incompatible.selections, []);
  assert.equal(original.events.length, spec.original_event_count);
  assert.equal(incompatible.events.length, spec.blocked_event_count);
  assert.deepEqual(original.events.map(x => x.event_type), fixture.expected_events.slice(0, spec.original_event_count));
  assert.deepEqual(incompatible.events.map(x => x.event_type), fixture.expected_events.slice(0, spec.blocked_event_count));
  assert.deepEqual(incompatible.events.slice(0, original.events.length), original.events);
  assert.deepEqual(corrected.events.slice(0, incompatible.events.length), incompatible.events);
  assert.deepEqual(corrected.events, observation.events);
  assert.deepEqual(corrected.typed_output, fixture.typed_value);
  const {task: initialTask, ...marker} = original.events[2].payload;
  assert.deepEqual(marker, spec.marker);
  assert.ok(initialTask?.id);
  assert.equal(initialTask.status, 'leased');
  const event = name => {
    const rows = observation.events.filter(x => x.event_type === name);
    assert.equal(rows.length, 1, name + ' once');
    return rows[0];
  };
  const activity = event('ActivityScheduled'), scheduled = event('TimerScheduled'), fired = event('TimerFired');
  const selected = event('SelectionResolved'), repaired = event('RepairRequested'), completed = event('WorkflowCompleted');
  const activityId = activity.payload.activity_execution_id, timerId = scheduled.payload.timer_id;
  assert.ok(typeof activityId === 'string' && activityId);
  assert.ok(typeof timerId === 'string' && timerId && timerId !== activityId);
  for (const member of [activity, scheduled, fired]) {
    assert.equal(member.payload.parallel_group_id, 'select-calls:2:2');
    assert.equal(member.payload.parallel_group_kind, 'mixed');
    assert.equal(member.payload.parallel_group_mode, 'select');
    assert.equal(member.payload.parallel_group_base_sequence, 2);
    assert.equal(member.payload.parallel_group_size, 2);
    assert.equal(member.payload.parallel_group_index, member === activity ? 0 : 1);
    assert.equal(member.payload.selection_member_key, member === activity ? 'activity' : 'timer');
    assert.equal(member.payload.selection_member_base_sequence, member === activity ? 2 : 3);
    assert.equal(member.payload.selection_member_size, 1);
  }
  assert.equal(activity.payload.sequence, 2);
  assert.equal(scheduled.payload.sequence, 3);
  assert.equal(fired.payload.sequence, 3);
  assert.equal(fired.payload.timer_id, timerId);
  for (const timer of [scheduled, fired]) assert.equal(timer.payload.delay_seconds, spec.timer_delay_seconds);
  assert.equal(fired.payload.fire_at, scheduled.payload.fire_at, 'same persisted deadline');
  assert.ok(instant(fired.payload.fired_at) >= instant(scheduled.payload.fire_at), 'timer never fires early');
  assert.ok(instant(fired.timestamp) >= instant(fired.payload.fired_at));
  const winner = selected.payload;
  assert.equal(winner.selection_group_id, 'select-calls:2:2');
  assert.equal(winner.selection_group_base_sequence, 2);
  assert.equal(winner.selection_group_size, 2);
  assert.equal(winner.member_key, 'timer');
  assert.equal(winner.member_index, 1);
  assert.equal(winner.member_base_sequence, 3);
  assert.equal(winner.member_size, 1);
  assert.equal(winner.operation_kind, 'timer');
  assert.equal(winner.operation_identity, timerId);
  assert.equal(winner.outcome, 'completed');
  assert.equal(winner.resolution_event_type, 'TimerFired');
  assert.ok(winner.resolution_event_id);
  if (fired.id) assert.equal(winner.resolution_event_id, fired.id);
  assert.deepEqual(corrected.selections, Array.from({length: 2}, () => ({key: 'timer', kind: 'timer', identity: timerId})));
  const checkpoints = corrected.checkpoints;
  for (const checkpoint of [original, incompatible, checkpoints.after_repair, checkpoints.after_waiting]) {
    pendingActivity(checkpoint, observation.mode, activityId, spec.activity_queue);
  }
  assert.deepEqual(checkpoints.after_repair.events.slice(0, incompatible.events.length), incompatible.events);
  assert.deepEqual(checkpoints.after_repair.events, corrected.events.slice(0, spec.blocked_event_count + 1));
  assert.deepEqual(checkpoints.after_waiting.events, checkpoints.after_repair.events,
    'repair replay waits without another schedule or history mutation');
  const repair = repaired.payload, command = repair.command, blockedTask = repair.task_id;
  assert.ok(blockedTask && blockedTask !== initialTask.id);
  assert.equal(repair.task_type, 'workflow');
  assert.equal(repair.liveness_state, 'workflow_replay_blocked');
  assert.equal(repair.wait_kind, null);
  assert.equal(repair.task.id, blockedTask);
  assert.equal(repair.task.status, 'ready');
  assert.equal(repair.task.attempt_count, 1);
  assert.equal(repair.task.repair_count, 1);
  assert.equal(command.id, repair.workflow_command_id);
  assert.equal(command.sequence, 2);
  assert.equal(command.type, 'repair');
  assert.equal(command.status, 'accepted');
  assert.equal(command.outcome, 'repair_dispatched');
  assert.equal(command.resolved_run_id, observation.run_id);
  assert.equal(command.target_scope, observation.mode === 'http' ? 'instance' : 'run');
  assert.equal(command.requested_run_id ?? null, observation.mode === 'http' ? null : observation.run_id);
  assert.equal(command.source, observation.mode === 'http' ? 'control_plane' : 'php');
  assert.equal(command.payload_codec, 'avro');
  assert.deepEqual(repaired.decoded.repair_command,
    {liveness_state: 'workflow_replay_blocked', wait_kind: null, task_id: blockedTask, task_type: 'workflow'});
  assert.equal(corrected.repair.outcome, 'repair_dispatched');
  assert.equal(corrected.repair.run_id, observation.run_id);
  const finalTask = completed.payload.task;
  assert.ok(finalTask?.id && finalTask.id !== blockedTask, 'later activity-resumed completion task');
  assert.equal(finalTask.status, 'leased');
  assert.equal(finalTask.attempt_count, spec.final_task_attempt);
  assert.equal(finalTask.repair_count, spec.final_task_repair_count);
  if (observation.mode === 'http') {
    for (const phase of phases) {
      assert.equal(phase.worker_error, null);
      assert.ok(phase.diagnostics.includes('worker.deregistered'));
      assert.ok(phase.diagnostics.includes('worker.stopped'));
    }
    assert.equal(incompatible.debug.execution.liveness_state, 'workflow_replay_blocked');
    assert.deepEqual(incompatible.debug.pending_workflow_tasks, []);
    assert.equal(incompatible.debug.latest_workflow_task_failure.task_id, blockedTask);
    assert.equal(incompatible.debug.latest_workflow_task_failure.replay_blocked, true);
    assert.equal(incompatible.debug.latest_workflow_task_failure.reason, 'version_marker_incompatible_range');
    assert.equal(incompatible.debug.latest_workflow_task_failure.sequence, 1);
    const failure = request(incompatible, 'incompatible', '/fail', '/api/worker/workflow-tasks/');
    assert.equal(failure.path.split('/').at(-2), blockedTask);
    assert.equal(failure.request.workflow_task_attempt, 1);
    assert.equal(failure.request.failure.reason, 'version_marker_incompatible_range');
    assert.equal(failure.request.failure.type, 'DurableWorkflow\\Exception\\NonDeterministicWorkflow');
    assert.equal(failure.request.failure.sequence, 1);
    assert.equal(failure.response.recorded, true);
    assert.equal(failure.response.next_task_id, null);
    const waiting = request(corrected, 'repaired-wait', '/fail', '/api/worker/workflow-tasks/');
    assert.equal(waiting.path.split('/').at(-2), blockedTask);
    assert.equal(waiting.request.workflow_task_attempt, spec.repaired_task_attempt);
    assert.ok(waiting.request.lease_owner);
    assert.notEqual(waiting.request.lease_owner, failure.request.lease_owner, 'restored worker authority');
    assert.equal(waiting.request.failure.type, 'WorkflowTaskWaitingForHistory');
    assert.equal(waiting.response.outcome, 'waiting_for_history');
    assert.equal(waiting.response.recorded, true);
    assert.equal(waiting.response.next_task_id, null);
    assert.deepEqual(checkpoints.after_waiting.debug.pending_workflow_tasks, []);
    const finish = request(corrected, 'final', '/complete', '/api/worker/workflow-tasks/');
    assert.equal(finish.path.split('/').at(-2), finalTask.id);
    assert.equal(finish.request.workflow_task_attempt, spec.final_task_attempt);
    assert.deepEqual(finish.request.commands.map(x => x.type), ['complete_workflow']);
    assert.equal(finish.response.recorded, true);
    const acceptedRepair = request(corrected, 'corrected-repair', '/repair', '/api/workflows/');
    assert.ok(acceptedRepair.path.includes('/runs/' + observation.run_id + '/'));
    assert.equal(command.principal_type, 'auth:token');
    assert.equal(command.principal_id, 'legacy-token');
    assert.equal(command.principal_label, 'Admin');
    assert.deepEqual(command.context.principal, {type: 'auth:token', id: 'legacy-token', label: 'Admin'});
    assert.equal(command.caller_label, 'Standalone Server');
    assert.equal(command.auth_status, 'authorized');
    assert.equal(command.auth_method, 'token');
    assert.equal(command.request_method, 'POST');
    assert.equal(command.request_path, acceptedRepair.path);
    assert.match(command.request_fingerprint, /^sha256:[a-f0-9]{64}$/);
  } else {
    const blocked = incompatible.tasks.filter(x => x.payload?.replay_blocked === true);
    assert.equal(blocked.length, 1);
    assert.equal(blocked[0].id, blockedTask);
    assert.equal(blocked[0].status, 'failed');
    assert.equal(blocked[0].lease_expires_at, null);
    assert.equal(blocked[0].payload.replay_blocked_reason, 'version_marker_incompatible_range');
    assert.equal(blocked[0].payload.replay_blocked_workflow_sequence, 1);
    assert.equal(blocked[0].payload.replay_blocked_change_id, spec.change_id);
    assert.equal(blocked[0].payload.replay_blocked_recorded_version, 1);
    assert.equal(blocked[0].payload.replay_blocked_min_supported, 2);
    assert.equal(blocked[0].payload.replay_blocked_max_supported, 2);
    assert.equal(corrected.repair.accepted, true);
    const waited = checkpoints.after_waiting.tasks.find(x => x.id === blockedTask);
    assert.equal(waited.status, 'completed');
    assert.equal(waited.attempt_count, spec.repaired_task_attempt);
    assert.equal(waited.repair_count, spec.repaired_task_repair_count);
    assert.deepEqual(corrected.failures, []);
    assert.equal(corrected.tasks.find(x => x.id === finalTask.id).status, 'completed');
  }
  assert.deepEqual(corrected.events.filter(x => x.event_type === 'WorkflowFailed'), []);
  return {checkpoint: spec.checkpoint, change_id: spec.change_id, timer_winner: '@timer:1',
    activity_still_pending_at_block_and_repair: true, immutable_history: true, same_run: true,
    repaired_original_task: true, repaired_task_waits: true, final_task_is_later: true,
    activity_invocations: 1, repaired_task_attempt: spec.repaired_task_attempt,
    repaired_task_repair_count: spec.repaired_task_repair_count};
}
