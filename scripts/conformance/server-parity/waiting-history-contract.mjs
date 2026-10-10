import assert from 'node:assert/strict';

export function checkWaitingHistory(fixture, observation, workflowId) {
  const definition = fixture.waiting_for_history, state = observation.waiting_for_history;
  const projection = {failure: definition.failure, original_activity_sequence: 2};
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_workflow_task_failure_endpoint'});
    return projection;
  }
  assert.equal(state.applicable, true, 'actual HTTP waiting acknowledgement');
  const worker = workflowId+'-waiting-worker', peer = workflowId+definition.peer_suffix, run = state.peer_run_id;
  assert.equal(state.worker_id, worker);
  assert.equal(state.peer_workflow_id, peer);
  assert.ok(typeof run === 'string' && run && run !== observation.run_id, 'distinct original pending run');
  const tasks = [state.first_task, state.replay_task, state.last_task];
  for (const task of tasks) {
    assert.equal(task.workflow_id, peer);
    assert.equal(task.run_id, run);
    assert.equal(task.lease_owner, worker);
    assert.ok(typeof task.task_id === 'string' && task.task_id, 'actual original task identity');
    assert.ok(Number.isSafeInteger(task.workflow_task_attempt) && task.workflow_task_attempt >= 1);
  }
  assert.equal(new Set(tasks.map(task=>task.task_id)).size, 3, 'timer and activity create distinct original replay tasks');
  for (const key of ['before','after_stale','after_acknowledgement','after_refusals','final']) {
    const snapshot = state[key];
    assert.equal(snapshot.workflow_id, peer);
    assert.equal(snapshot.run_id, run);
    assert.equal(snapshot.workflow_type, definition.peer_workflow_type);
    assert.equal(snapshot.namespace, 'default');
    assert.equal(snapshot.task_queue, 'server-parity-v1');
    assert.equal(snapshot.payload_codec, 'avro');
    assert.deepStrictEqual(snapshot.typed_input, {type: 'list', value: [fixture.typed_value]});
    assert.equal(snapshot.status, key === 'final' ? 'completed' : 'waiting');
    if (key !== 'final') assert.deepStrictEqual(snapshot.typed_output, {type: 'null', value: null});
  }
  const prefix = state.before.events;
  assert.deepStrictEqual(prefix.map(event=>event.event_type), definition.expected_peer_events.slice(0,5), 'actual timer firing precedes unresolved activity');
  assert.deepStrictEqual(prefix.map(event=>event.sequence), [1,2,3,4,5], 'complete original history order');
  for (const key of ['after_stale','after_acknowledgement','after_refusals']) {
    assert.deepStrictEqual(state[key].events, prefix, 'waiting acknowledgement/refusals cannot rewrite or append original history');
  }
  assert.deepStrictEqual(state.after_stale, state.before, 'stale attempt has no read-visible mutation');
  const task = state.replay_task;
  for (const key of ['stale_attempt','duplicate','late_completion']) {
    assert.equal(state[key].status, 409);
    assert.equal(state[key].response.task_id, task.task_id);
    assert.equal(state[key].response.workflow_task_attempt, task.workflow_task_attempt+(key==='stale_attempt'?1:0));
  }
  const acknowledgement = state.acknowledgement;
  for (const [field, expected] of Object.entries({task_id:task.task_id, workflow_task_attempt:task.workflow_task_attempt,
    outcome:'waiting_for_history', recorded:true, reason:null, next_task_id:null})) assert.deepStrictEqual(acknowledgement[field], expected);
  assert.equal(state.idle_poll, null, 'acknowledgement does not create an unsolicited task retry');
  const activity = state.activity_task;
  const scheduled = prefix[3];
  assert.equal(scheduled.payload.sequence, 2, 'original authored activity position');
  assert.equal(activity.activity_execution_id, scheduled.payload.activity_execution_id, 'finish the same original activity');
  assert.equal(activity.run_id, run);
  assert.equal(activity.lease_owner, worker);
  const events = state.final.events;
  assert.deepStrictEqual(events.map(event=>event.event_type), definition.expected_peer_events);
  assert.deepStrictEqual(events.slice(0,5), prefix, 'final workflow retains its complete original checkpoint');
  assert.deepStrictEqual(events.map(event=>event.sequence), [1,2,3,4,5,6,7,8]);
  assert.deepStrictEqual(events[6].typed_decoded.result, fixture.typed_value, 'original exact activity result');
  assert.deepStrictEqual(events[7].typed_decoded.output, fixture.typed_value, 'committed exact workflow result');
  assert.deepStrictEqual(state.final.typed_output, fixture.typed_value);
  const fails = state.requests.filter(request=>request.path.endsWith('/fail'));
  assert.equal(fails.length, 3, 'stale, accepted and duplicate exchanges actually occurred');
  assert.deepStrictEqual(fails.map(request=>request.status), [409,200,409]);
  for (const [index, request] of fails.entries()) {
    assert.equal(request.method, 'POST');
    assert.equal(request.path, '/api/worker/workflow-tasks/'+task.task_id+'/fail');
    assert.equal(request.namespace, 'default');
    assert.deepStrictEqual(request.request, {lease_owner:worker, workflow_task_attempt:task.workflow_task_attempt+(index===0?1:0), failure:definition.failure});
  }
  assert.deepStrictEqual(fails[1].response, acknowledgement, 'accepted SDK receipt comes from real transport');
  const activityCompletions = state.requests.filter(request=>/\/activity-tasks\/[^/]+\/complete$/.test(request.path));
  assert.equal(activityCompletions.length,1);
  assert.equal(activityCompletions[0].status,200);
  return projection;
}
