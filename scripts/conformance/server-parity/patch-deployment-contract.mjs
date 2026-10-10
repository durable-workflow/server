import assert from 'node:assert/strict';

export function checkPatchDeployment(fixture, observation, workflowId) {
  const spec = fixture.patch_deployment;
  const state = observation.patch_deployment;
  assert.ok(state, 'complete patch deployment observations');
  assert.equal(state.checkpoint, spec.checkpoint, 'declared original checkpoint');
  assert.equal(state.change_id, spec.change_id, 'original patch change ID');
  const {original, replacement} = state;
  assert.equal(original.phase, 'original');
  assert.equal(replacement.phase, 'replacement');
  assert.ok(Number.isInteger(original.pid) && original.pid > 0, 'original process identity');
  assert.ok(Number.isInteger(replacement.pid) && replacement.pid > 0, 'replacement process identity');
  assert.notEqual(original.pid, replacement.pid, 'actual cold replacement process');
  for (const phase of [original, replacement]) {
    assert.equal(phase.workflow_id, workflowId, 'same workflow across deployment');
    assert.equal(phase.run_id, observation.run_id, 'same original run across deployment');
    assert.equal(phase.workflow_type, fixture.workflow_type);
    assert.equal(phase.namespace, observation.namespace);
    assert.equal(phase.task_queue, observation.task_queue);
    assert.equal(phase.mode, observation.mode);
    assert.equal(phase.sdk_php, observation.sdk_php, 'unchanged exact SDK version');
    assert.equal(phase.sdk_php_source, observation.sdk_php_source, 'unchanged exact SDK source');
    assert.deepEqual(phase.typed_input, observation.typed_input, 'exact original input');
    assert.equal(phase.payload_codec, 'avro');
  }
  const checkpointLength = spec.checkpoint === 'activity_pending' ? 3 : 5;
  assert.ok(['activity_pending', 'activity_completed'].includes(spec.checkpoint));
  assert.deepEqual(original.events.map(event => event.event_type), fixture.expected_events.slice(0, checkpointLength),
    'original worker stops at the exact persisted checkpoint');
  assert.notEqual(original.status, 'completed', 'original worker leaves unfinished work');
  assert.equal(original.output, null, 'no premature outcome');
  assert.deepEqual(original.decisions, [], 'original author has no patch call');
  assert.deepEqual(observation.events.slice(0, checkpointLength), original.events, 'immutable complete checkpoint history');
  assert.equal(replacement.status, 'completed');
  assert.deepEqual(replacement.events, observation.events, 'replacement observes the committed complete history');
  assert.deepEqual(replacement.typed_output, observation.typed_output, 'replacement preserves typed outcome');
  assert.ok(replacement.decisions.length > 0, 'actual replacement patch execution');
  for (const decisions of replacement.decisions) {
    assert.equal(decisions.length, spec.repeated_calls);
    assert.deepEqual(decisions, spec.expected_decisions, 'one repeated legacy decision without consuming a command');
  }
  assert.equal(observation.events.filter(event => event.event_type === 'VersionMarkerRecorded').length, 0,
    'no marker added to an unmarked old run');
  assert.equal(observation.events[2].payload.sequence, 1, 'original activity stays at sequence one');
  if (observation.mode === 'http') {
    const commands = phase => phase.requests.filter(item => item.method === 'POST'
      && item.path.startsWith('/api/worker/workflow-tasks/') && item.path.endsWith('/complete'));
    const oldCompletions = commands(original);
    const newCompletions = commands(replacement);
    assert.equal(oldCompletions.length, 1, 'one accepted original authored turn');
    assert.equal(oldCompletions[0].status, 200);
    assert.deepEqual(oldCompletions[0].request.commands.map(command => command.type), ['schedule_activity']);
    assert.equal(oldCompletions[0].request.commands[0].activity_type, 'parity.v1.echo_activity');
    assert.ok(newCompletions.length > 0);
    assert.deepEqual(newCompletions.flatMap(item => item.request.commands.map(command => command.type)),
      ['complete_workflow'], 'replacement neither reschedules old activity nor emits marker/new activity');
    for (const phase of [original, replacement]) {
      assert.ok(phase.diagnostics.includes('worker.registered'));
      assert.ok(phase.diagnostics.includes('worker.stopped'));
      assert.ok(!phase.diagnostics.some(event => ['worker.failed', 'worker.handler_failed', 'worker.shutdown_failed'].includes(event)),
        'no hidden worker or shutdown failure');
      for (const item of phase.requests) assert.ok(item.status >= 200 && item.status < 300, 'all observed I/O succeeds');
    }
    const outcomes = phase => phase.requests.filter(item => item.method === 'POST'
      && item.path.startsWith('/api/worker/activity-tasks/') && item.path.endsWith('/complete'));
    assert.equal(outcomes(original).length, spec.checkpoint === 'activity_pending' ? 0 : 1);
    assert.equal(outcomes(replacement).length, spec.checkpoint === 'activity_pending' ? 1 : 0);
    assert.equal(outcomes(original).length + outcomes(replacement).length, 1, 'one actual SDK activity outcome');
  }
  return {checkpoint: spec.checkpoint, change_id: spec.change_id, command_sequence: 1,
    decisions: spec.expected_decisions, original_events: fixture.expected_events.slice(0, checkpointLength),
    cold_replacement: true, marker_count: 0, activity_outcomes: 1};
}
