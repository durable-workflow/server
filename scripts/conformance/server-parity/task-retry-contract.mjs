import assert from 'node:assert/strict';

export function checkTaskRetry(fixture, observation, workflowId) {
  const definition = fixture.workflow_task_retry, state = observation.workflow_task_retry;
  const projection = {failures: definition.failures, attempts: [1, 2, 3], required_http_outcome: 'original_run_completed'};
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_workflow_task_retry_endpoint'});
    return projection;
  }
  assert.equal(state.applicable, true);
  const worker = workflowId+'-retry-worker', peer = workflowId+definition.peer_suffix, run = state.peer_run_id;
  assert.equal(state.worker_id, worker);
  assert.equal(state.peer_workflow_id, peer);
  assert.ok(typeof run === 'string' && run && run !== observation.run_id);
  assert.equal(state.steps.length, definition.failures.length);
  const prefix = state.before.events;
  assert.deepStrictEqual(prefix.map(e=>e.event_type), definition.expected_peer_events.slice(0, 2));
  assert.deepStrictEqual(prefix.map(e=>e.sequence), [1, 2]);
  const taskIds = [];
  function snapshot(value, terminal = false) {
    assert.equal(value.workflow_id, peer);
    assert.equal(value.run_id, run);
    assert.equal(value.workflow_type, definition.peer_workflow_type);
    assert.equal(value.namespace, 'default');
    assert.equal(value.task_queue, 'server-parity-v1');
    assert.equal(value.payload_codec, 'avro');
    assert.deepStrictEqual(value.typed_input, {type: 'list', value: [fixture.typed_value]});
    // The published projection stays pending until a durable command advances
    // history; acquiring or retrying this task alone does not make it running.
    assert.equal(value.status, terminal ? 'completed' : 'pending');
    assert.deepStrictEqual(value.typed_output, terminal ? fixture.typed_value : {type: 'null', value: null});
    assert.deepStrictEqual(terminal ? value.events.slice(0, 2) : value.events, prefix);
  }
  function task(value, attempt) {
    assert.equal(value.workflow_id, peer);
    assert.equal(value.run_id, run);
    assert.equal(value.lease_owner, worker);
    assert.equal(value.workflow_task_attempt, attempt);
    assert.ok(typeof value.task_id === 'string' && value.task_id);
  }
  snapshot(state.before);
  const fails = state.requests.filter(request=>request.path.endsWith('/fail'));
  assert.equal(fails.length, definition.failures.length * 3 + 1);
  for (const [index, step] of state.steps.entries()) {
    task(step.task, index + 1);
    task(step.next_task, index + 2);
    taskIds.push(step.task.task_id);
    if (index) assert.deepStrictEqual(step.task, state.steps[index - 1].next_task);
    for (const key of ['before', 'after_stale', 'after_accepted', 'after_refusals']) snapshot(step[key]);
    assert.deepStrictEqual(step.after_stale, step.before, 'stale failure has no read-visible effect');
    for (const key of ['stale', 'duplicate', 'late_completion']) {
      assert.equal(step[key].status, 409);
      assert.equal(step[key].response.task_id, step.task.task_id);
      assert.equal(step[key].response.workflow_task_attempt, index + 1 + (key === 'stale' ? 1 : 0));
      assert.equal(step[key].response.reason, key === 'stale' ? 'workflow_task_attempt_mismatch' : 'task_not_leased');
    }
    const accepted = step.accepted;
    for (const [key, expected] of Object.entries({task_id: step.task.task_id,
      workflow_task_attempt: index + 1, outcome: 'failed', recorded: true, reason: null,
      next_task_id: step.next_task.task_id})) assert.deepStrictEqual(accepted[key], expected);
    for (const [offset, expectedStatus] of [409, 200, 409].entries()) {
      const request = fails[index * 3 + offset];
      assert.equal(request.method, 'POST');
      assert.equal(request.namespace, 'default');
      assert.equal(request.path, '/api/worker/workflow-tasks/'+step.task.task_id+'/fail');
      assert.equal(request.status, expectedStatus);
      assert.deepStrictEqual(request.request, {lease_owner: worker,
        workflow_task_attempt: index + 1 + (offset === 0 ? 1 : 0), failure: definition.failures[index]});
    }
    assert.deepStrictEqual(fails[index * 3 + 1].response, accepted);
  }
  task(state.last_task, definition.failures.length + 1);
  assert.deepStrictEqual(state.last_task, state.steps.at(-1).next_task);
  taskIds.push(state.last_task.task_id);
  assert.equal(new Set(taskIds).size, taskIds.length, 'each accepted failure creates one fresh task');
  snapshot(state.final, true);
  assert.deepStrictEqual(state.final.events.map(e=>e.event_type), definition.expected_peer_events);
  assert.deepStrictEqual(state.final.events.map(e=>e.sequence), [1, 2, 3]);
  assert.deepStrictEqual(state.final.events.at(-1).typed_decoded.output, fixture.typed_value);
  assert.equal(state.idle_poll, null, 'refused duplicates cannot create extra retries');
  assert.equal(state.late_failure.status, 409);
  assert.equal(state.late_failure.response.task_id, state.last_task.task_id);
  assert.equal(state.late_failure.response.workflow_task_attempt, definition.failures.length + 1);
  assert.equal(state.late_failure.response.reason, 'task_not_leased');
  assert.deepStrictEqual(state.after_terminal_refusal, state.final);
  const late = fails.at(-1);
  assert.equal(late.status, 409);
  assert.equal(late.path, '/api/worker/workflow-tasks/'+state.last_task.task_id+'/fail');
  assert.deepStrictEqual(late.request, {lease_owner: worker, workflow_task_attempt: definition.failures.length + 1,
    failure: definition.failures[0]});
  const completions = state.requests.filter(request=>request.path.endsWith('/complete'));
  assert.deepStrictEqual(completions.map(request=>request.status), [409, 409, 200]);
  const completion = completions.at(-1);
  assert.equal(completion.path, '/api/worker/workflow-tasks/'+state.last_task.task_id+'/complete');
  assert.equal(completion.request.lease_owner, worker);
  assert.equal(completion.request.workflow_task_attempt, definition.failures.length + 1);
  assert.equal(completion.request.commands.length, 1);
  assert.equal(completion.request.commands[0].type, 'complete_workflow');
  assert.deepStrictEqual(completion.response, state.completion);
  assert.equal(state.completion.recorded, true);
  return projection;
}
