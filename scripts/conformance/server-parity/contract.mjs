import assert from 'node:assert/strict';

// Only this fixture's declared semantics are projected. The full observation
// remains in the recording, including implementation metadata and timestamps.
export function checkObservation(fixture, observation, workflowId) {
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  const nonempty = (value, label) => assert.ok(typeof value === 'string' && value.length > 0, label);
  equal(observation.workflow_id, workflowId, 'public workflow identity');
  nonempty(observation.run_id, 'run identity');
  equal(observation.workflow_type, fixture.workflow_type, 'registered workflow type');
  equal(observation.namespace, 'default', 'namespace');
  equal(observation.task_queue, 'server-parity-v1', 'task queue');
  equal(observation.status, 'completed', 'actual durable completion');
  equal(observation.payload_codec, 'avro', 'payload codec');
  equal(observation.input, [fixture.input], 'decoded workflow input');
  equal(observation.output, fixture.input, 'decoded workflow result');
  const events = observation.events;
  equal(events.map(event => event.event_type), fixture.expected_events, 'complete ordered event inventory');
  equal(events.map(event => event.sequence), events.map((_, index) => index + 1), 'durable history sequence');
  const accepted = events[0].payload;
  const started = events[1].payload;
  for (const payload of [accepted, started]) {
    equal(payload.workflow_instance_id, workflowId, 'history workflow relationship');
    equal(payload.workflow_run_id, observation.run_id, 'history run relationship');
    equal(payload.workflow_type, fixture.workflow_type, 'history registered type');
    nonempty(payload.workflow_command_id, 'durable start command identity');
  }
  equal(started.workflow_command_id, accepted.workflow_command_id, 'same accepted start command');
  equal(accepted.outcome, 'started_new', 'start command outcome');
  equal(started.execution_timeout_seconds, 3600, 'execution timeout');
  equal(started.run_timeout_seconds, 600, 'run timeout');
  const startTime = Date.parse(observation.execution.started_at);
  assert.ok(Number.isFinite(startTime), 'persisted start timestamp');
  for (const [name, seconds] of [['execution_deadline_at', 3600], ['run_deadline_at', 600]]) {
    const delta = Date.parse(started[name]) - startTime;
    assert.ok(Number.isFinite(delta) && Math.abs(delta - seconds * 1000) < 1000, `${name} retains its original budget`);
  }
  let previousTime = -Infinity;
  for (const event of events) {
    const time = Date.parse(event.timestamp);
    assert.ok(Number.isFinite(time) && time >= previousTime, 'ordered recorded timestamps');
    previousTime = time;
  }
  equal(events.at(-1).decoded.output, fixture.input, 'committed workflow result');
  const projectedEvents = events.map(({sequence, event_type}) => ({sequence, event_type}));
  if (fixture.activity) {
    const [scheduled, running, completed] = events.slice(2, 5);
    const id = scheduled.payload.activity_execution_id;
    const attempt = running.payload.activity_attempt_id;
    nonempty(id, 'activity identity');
    nonempty(attempt, 'activity attempt identity');
    assert.notEqual(id, attempt, 'distinct activity and attempt identities');
    for (const [index, event] of [scheduled, running, completed].entries()) {
      equal(event.payload.activity_execution_id, id, 'same activity throughout history');
      equal(event.payload.activity_type, 'parity.v1.echo_activity', 'registered activity type');
      equal(event.payload.sequence, 1, 'deterministic activity command sequence');
      Object.assign(projectedEvents[index + 2], {activity_execution_id: '@activity:1', command_sequence: 1, activity_type: event.payload.activity_type});
    }
    equal(scheduled.decoded.activity_arguments, [fixture.input], 'scheduled activity arguments');
    equal(running.decoded.activity_arguments, [fixture.input], 'started activity arguments');
    for (const [index, event] of [running, completed].entries()) {
      equal(event.payload.activity_attempt_id, attempt, 'same committed attempt');
      equal(event.payload.attempt_number, 1, 'one attempt');
      Object.assign(projectedEvents[index + 3], {activity_attempt_id: '@attempt:1', attempt_number: 1});
    }
    equal(completed.decoded.result, fixture.input, 'committed activity result');
  }
  return {
    fixture_id: fixture.id, workflow_id: workflowId, run_id: '@run:1', start_command_id: '@command:1',
    workflow_type: observation.workflow_type, namespace: observation.namespace,
    task_queue: observation.task_queue, status: observation.status, payload_codec: observation.payload_codec,
    input: observation.input, output: observation.output, execution_timeout_seconds: 3600,
    run_timeout_seconds: 600, events: projectedEvents,
  };
}

export function compareRecords(records) {
  assert.ok(records.length >= 2, 'at least two recordings are required');
  const reference = records[0];
  for (const record of records) {
    assert.equal(record.schema, 'durable-workflow.server-parity-record/v1');
    assert.equal(record.outcome, 'pass', `${record.target} recording must have passed contract assertions`);
    assert.deepStrictEqual(record.fixture_hashes, reference.fixture_hashes, 'same reviewed fixture bytes');
    assert.deepStrictEqual(record.artifacts, reference.artifacts, 'same consumer tuple');
    assert.equal(record.runner_revision, reference.runner_revision, 'same runner revision');
    assert.deepStrictEqual(record.cases.map(c => c.fixture_id), reference.cases.map(c => c.fixture_id), 'complete same fixture inventory');
    // Recheck raw observations. A saved pass label or edited projection is not
    // authority, and a missing or corrupted record must never compare green.
    for (const [index, item] of record.cases.entries()) {
      const baseline = reference.cases[index];
      assert.deepStrictEqual(item.fixture, baseline.fixture, 'same fixture expectations');
      const checked = checkObservation(item.fixture, item.observation, item.projection.workflow_id);
      assert.deepStrictEqual(item.projection, checked, 'projection matches raw observation');
      assert.deepStrictEqual(checked, baseline.projection, `${record.target}: ${item.fixture_id}`);
    }
  }
}
