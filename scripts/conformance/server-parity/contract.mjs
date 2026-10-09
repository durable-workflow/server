import assert from 'node:assert/strict';

function instantNanoseconds(value) {
  const shape = typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.(\d{1,9}))?(?:Z|[+-]\d{2}:\d{2})$/.exec(value);
  const milliseconds = Date.parse(value);
  assert.ok(shape && Number.isFinite(milliseconds), 'persisted timer timestamp');
  // Date owns calendar/offset parsing, but truncates fractional milliseconds.
  // Restore only that omitted fraction using integer arithmetic; never round
  // a microsecond-early firing into an apparently equal deadline.
  return BigInt(milliseconds) * 1_000_000n + BigInt((shape[1] ?? '').padEnd(9, '0').slice(3));
}

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
  const output = fixture.signal_value ?? fixture.input;
  const typedOutput = fixture.typed_signal_value ?? fixture.typed_value;
  equal(observation.input, fixture.signal_count ? [fixture.input, fixture.signal_count] : [fixture.input], 'decoded workflow input');
  equal(observation.output, output, 'decoded workflow result');
  const typedArguments = {type: 'list', value: [fixture.typed_value, ...(fixture.signal_count ? [{type: 'int64', value: String(fixture.signal_count)}] : [])]};
  equal(observation.typed_input, typedArguments, 'decoded workflow input types and exact int64 values');
  equal(observation.typed_output, typedOutput, 'decoded workflow result types and exact int64 values');
  const events = observation.events;
  equal(events.map(event => event.event_type), observation.mode === 'embedded' && fixture.embedded_expected_events ? fixture.embedded_expected_events : fixture.expected_events, 'complete ordered event inventory');
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
  const identities = [workflowId, observation.run_id, accepted.workflow_command_id];
  equal(new Set(identities).size, identities.length, 'distinct workflow/run/command identities');
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
  equal(events.at(-1).decoded.output, output, 'committed workflow result');
  equal(events.at(-1).typed_decoded.output, typedOutput, 'committed workflow result types');
  const projectedEvents = events.map(({sequence, event_type}) => ({sequence, event_type}));
  const projectedQueries = [];
  if (fixture.queries) {
    equal(observation.queries.length, fixture.queries.length, 'complete query observation inventory');
    equal(started.declared_queries, [fixture.query_name], 'durable query declaration');
    for (const [index, expected] of fixture.queries.entries()) {
      const query = observation.queries[index];
      equal(query.name, fixture.query_name, 'declared query name');
      equal(query.arguments, fixture.query_arguments, 'decoded query arguments');
      equal(query.typed_arguments, fixture.typed_query_arguments, 'exact query argument types');
      for (const execution of [query.before, query.after]) {
        equal(execution.id ?? execution.run_id, observation.run_id, 'query original run');
        equal(execution.status, expected.status, 'query does not change run status');
      }
      equal(query.history_after, query.history_before, 'query leaves durable history unchanged');
      const applied = query.history_before.filter(event => event.event_type === 'SignalApplied');
      equal(applied.length, expected.after_signal_count, 'query observes the intended committed signal state');
      const prefix = events.slice(0, query.history_before.length).map(({sequence, event_type, payload}) => ({sequence, event_type, payload}));
      equal(query.history_before.map(({sequence, event_type, payload}) => ({sequence, event_type, payload})), prefix, 'query observes an original history prefix');
      const result = {request: fixture.query_arguments[0], delivered: expected.after_signal_count,
        last: expected.after_signal_count ? fixture.signal_value : null};
      const typedResult = {type: 'map', value: {request: fixture.typed_query_arguments.value[0],
        delivered: {type: 'int64', value: String(expected.after_signal_count)},
        last: expected.after_signal_count ? fixture.typed_signal_value : {type: 'null', value: null}}};
      equal(query.result, result, 'query result comes from real state and request');
      equal(query.typed_result, typedResult, 'exact query result types');
      if (observation.mode === 'http') {
        const task = query.worker.task;
        nonempty(task.query_task_id, 'actual routed query task');
        identities.push(task.query_task_id);
        equal(new Set(identities).size, identities.length, 'distinct query task identities');
        equal(task.workflow_id, workflowId, 'query task original workflow');
        equal(task.run_id, observation.run_id, 'query task original run');
        equal(task.workflow_type, fixture.workflow_type, 'query task workflow type');
        equal(task.query_name, fixture.query_name, 'query task declared name');
        equal(task.query_task_attempt, 1, 'first query task attempt');
        nonempty(task.lease_owner, 'query task lease owner');
        equal(task.query_arguments.codec, 'avro', 'query task argument codec');
        equal(query.worker.arguments, fixture.query_arguments, 'worker decoded actual query arguments');
        equal(query.worker.typed_arguments, fixture.typed_query_arguments, 'worker exact query argument types');
        equal(task.history_events.filter(event => event.event_type === 'SignalApplied').length, expected.after_signal_count, 'worker receives the intended committed history');
      }
      projectedQueries.push({name: query.name, status: expected.status, after_signal_count: expected.after_signal_count,
        arguments: query.typed_arguments, result: query.typed_result});
    }
  }
  if (fixture.signal_count) {
    assert.ok(['http', 'embedded'].includes(observation.mode), 'explicit signal authoring adapter');
    equal(observation.signal_deliveries.length, fixture.signal_count, 'complete delivered signal inventory');
    equal(started.declared_signals, ['payload'], 'durable signal declaration');
    let offset = 2;
    const commonEvents = projectedEvents.slice(0, 2);
    for (let index = 0; index < fixture.signal_count; index++) {
      const opened = events[offset++];
      const received = events[offset++];
      const cursor = events[offset++];
      const applied = events[offset++];
      const embedded = observation.mode === 'embedded';
      const satisfied = embedded ? applied : events[offset++];
      const waitId = opened.payload[embedded ? 'signal_wait_id' : 'condition_wait_id'];
      const signalId = received.payload.signal_id;
      const commandId = received.payload.workflow_command_id;
      for (const [value, label] of [[waitId, 'wait'], [signalId, 'signal'], [commandId, 'signal command']]) {
        nonempty(value, `durable ${label} identity`);
        identities.push(value);
      }
      equal(new Set(identities).size, identities.length, 'distinct wait/signal/command identities');
      const delivery = observation.signal_deliveries[index];
      equal(delivery.before.status, 'waiting', 'signal delivered only after a durable wait');
      equal(delivery.before.id ?? delivery.before.run_id, observation.run_id, 'waiting original run');
      equal(delivery.response.command_status ?? (delivery.response.accepted ? 'accepted' : 'rejected'), 'accepted', 'accepted signal command');
      equal(delivery.response.command_id, commandId, 'acknowledged signal command retained in history');
      equal(delivery.response.run_id, observation.run_id, 'acknowledged original run');
      equal(delivery.response.workflow_id, workflowId, 'acknowledged workflow');
      equal(delivery.response.outcome, 'signal_received', 'signal command outcome');
      equal(received.payload.workflow_instance_id, workflowId, 'signal workflow relationship');
      equal(received.payload.workflow_run_id, observation.run_id, 'signal run relationship');
      equal(received.payload.signal_name, 'payload', 'signal name');
      equal(received.payload.payload_codec, 'avro', 'signal argument codec');
      equal(received.decoded.arguments, [fixture.signal_value], 'decoded signal arguments');
      equal(received.typed_decoded.arguments, {type: 'list', value: [fixture.typed_signal_value]}, 'exact signal argument types');
      equal(opened.payload.sequence, index + 1, 'deterministic wait command sequence');
      if (embedded) {
        equal(opened.payload.signal_name, 'payload', 'direct signal wait name');
        equal(received.payload.signal_wait_id, waitId, 'received signal routes to original direct wait');
        equal(applied.payload.sequence, opened.payload.sequence, 'direct signal wait resolution sequence');
      } else {
        equal(opened.payload.condition_key, `payload:${index}`, 'deterministic wait key');
        assert.match(opened.payload.condition_definition_fingerprint, /^sha256:[a-f0-9]{64}$/, 'recorded condition fingerprint');
        for (const key of ['condition_wait_id', 'condition_key', 'condition_definition_fingerprint', 'sequence']) {
          equal(satisfied.payload[key], opened.payload[key], `condition resolution retains ${key}`);
        }
        equal(satisfied.payload.workflow_signal_id, signalId, 'condition resolved by accepted signal');
      }
      equal(satisfied.payload.signal_name, 'payload', 'condition resolution signal name');
      equal(satisfied.payload.signal_wait_id, received.payload.signal_wait_id, 'condition resolution signal wait relationship');
      nonempty(received.payload.signal_wait_id, 'signal routing wait identity');
      equal(cursor.payload.stream_key, `instance:${workflowId}`, 'message cursor stream');
      equal(cursor.payload.previous_position, index, 'message cursor previous position');
      equal(cursor.payload.new_position, index + 1, 'message cursor advances once per signal');
      equal(received.payload.command.id, commandId, 'recorded signal command identity');
      equal(received.payload.command.sequence, index + 2, 'control command sequence is separate from authored wait sequence');
      equal(received.payload.command.message_sequence, index + 1, 'ordered signal message sequence');
      for (const key of ['workflow_command_id', 'signal_id', 'signal_name', 'signal_wait_id']) {
        equal(applied.payload[key], received.payload[key], `applied signal retains ${key}`);
      }
      equal(applied.decoded.value, fixture.signal_value, 'applied signal value');
      equal(applied.typed_decoded.value, fixture.typed_signal_value, 'applied signal value types');
      commonEvents.push(
        {event_type: 'PayloadWaitOpened', wait_id: `@wait:${index + 1}`, command_sequence: index + 1},
        {event_type: 'SignalReceived', signal_id: `@signal:${index + 1}`, command_id: `@signal-command:${index + 1}`, signal_name: 'payload', arguments: {type: 'list', value: [fixture.typed_signal_value]}},
        {event_type: 'MessageCursorAdvanced', previous_position: index, new_position: index + 1},
        {event_type: 'SignalApplied', signal_id: `@signal:${index + 1}`, command_id: `@signal-command:${index + 1}`, value: fixture.typed_signal_value},
        {event_type: 'PayloadWaitResolved', wait_id: `@wait:${index + 1}`, signal_id: `@signal:${index + 1}`, command_sequence: index + 1},
      );
    }
    commonEvents.push({event_type: 'WorkflowCompleted'});
    projectedEvents.splice(0, projectedEvents.length, ...commonEvents.map((event, index) => ({...event, sequence: index + 1})));
  }
  if (fixture.timer_delays) {
    for (const [index, delay] of fixture.timer_delays.entries()) {
      const offset = 2 + index * 2;
      const [scheduled, fired] = events.slice(offset, offset + 2);
      const timerId = scheduled.payload.timer_id;
      nonempty(timerId, 'durable timer identity');
      identities.push(timerId);
      equal(new Set(identities).size, identities.length, 'distinct timer and run/command identities');
      const fireAt = instantNanoseconds(scheduled.payload.fire_at);
      const scheduledAt = Date.parse(scheduled.timestamp);
      // Published history timestamps can lose fractional seconds. Preserve the
      // exact payload deadline and allow only that known recorder precision.
      assert.ok(Math.abs(Number(fireAt / 1_000_000n) - scheduledAt - delay * 1000) < 1000, 'timer retains requested delay');
      // Embedded PHP's immediate TimerFired omits fire_at. The zero-delay
      // fixture explicitly permits that event shape; positive delays always
      // require the repeated deadline. Both retain scheduled authority and
      // must fire at or after its exact timestamp.
      if (!(delay === 0 && fixture.zero_delay_fired_fire_at_optional && fired.payload.fire_at === undefined)) {
        equal(fired.payload.fire_at, scheduled.payload.fire_at, 'firing retains original deadline');
      }
      const firedAt = instantNanoseconds(fired.payload.fired_at);
      assert.ok(firedAt >= fireAt, 'timer must not fire early');
      for (const [relative, event] of [scheduled, fired].entries()) {
        equal(event.payload.timer_id, timerId, 'same timer throughout history');
        equal(event.payload.sequence, index + 1, 'deterministic timer command sequence');
        equal(event.payload.delay_seconds, delay, 'persisted timer delay');
        Object.assign(projectedEvents[offset + relative], {timer_id: `@timer:${index + 1}`, command_sequence: index + 1, delay_seconds: delay});
      }
    }
  }
  if (fixture.activity) {
    const [scheduled, running, completed] = events.slice(2, 5);
    const id = scheduled.payload.activity_execution_id;
    const attempt = running.payload.activity_attempt_id;
    nonempty(id, 'activity identity');
    nonempty(attempt, 'activity attempt identity');
    assert.notEqual(id, attempt, 'distinct activity and attempt identities');
    identities.push(id, attempt);
    equal(new Set(identities).size, identities.length, 'bijective generated identity aliases');
    for (const [index, event] of [scheduled, running, completed].entries()) {
      equal(event.payload.activity_execution_id, id, 'same activity throughout history');
      equal(event.payload.activity_type, 'parity.v1.echo_activity', 'registered activity type');
      equal(event.payload.sequence, 1, 'deterministic activity command sequence');
      Object.assign(projectedEvents[index + 2], {activity_execution_id: '@activity:1', command_sequence: 1, activity_type: event.payload.activity_type});
    }
    equal(scheduled.decoded.activity_arguments, [fixture.input], 'scheduled activity arguments');
    equal(running.decoded.activity_arguments, [fixture.input], 'started activity arguments');
    equal(scheduled.typed_decoded.activity_arguments, typedArguments, 'scheduled activity argument types');
    equal(running.typed_decoded.activity_arguments, typedArguments, 'started activity argument types');
    for (const [index, event] of [running, completed].entries()) {
      equal(event.payload.activity_attempt_id, attempt, 'same committed attempt');
      equal(event.payload.attempt_number, 1, 'one attempt');
      Object.assign(projectedEvents[index + 3], {activity_attempt_id: '@attempt:1', attempt_number: 1});
    }
    equal(completed.decoded.result, fixture.input, 'committed activity result');
    equal(completed.typed_decoded.result, fixture.typed_value, 'committed activity result types');
  }
  return {
    fixture_id: fixture.id, workflow_id: workflowId, run_id: '@run:1', start_command_id: '@command:1',
    workflow_type: observation.workflow_type, namespace: observation.namespace,
    task_queue: observation.task_queue, status: observation.status, payload_codec: observation.payload_codec,
    input: observation.typed_input, output: observation.typed_output, execution_timeout_seconds: 3600,
    run_timeout_seconds: 600, events: projectedEvents, ...(fixture.queries ? {queries: projectedQueries} : {}),
  };
}

export function compareRecords(records, expectedFixtureHashes, expectedFixtures) {
  assert.ok(records.length >= 2, 'at least two recordings are required');
  const reference = records[0];
  for (const record of records) {
    assert.equal(record.schema, 'durable-workflow.server-parity-record/v1');
    assert.equal(record.outcome, 'pass', `${record.target} recording must have passed contract assertions`);
    assert.deepStrictEqual(record.fixture_hashes, expectedFixtureHashes, 'recording covers the current reviewed fixture corpus');
    assert.ok(record.cases.length > 0, 'recorded fixture inventory must not be empty');
    assert.equal(new Set(record.cases.map(c => c.fixture_id)).size, record.cases.length, 'each fixture is recorded exactly once');
    assert.deepStrictEqual(record.cases.map(c => `${c.fixture_id}.json`).sort(), Object.keys(record.fixture_hashes).sort(), 'every hashed fixture has a recorded case');
    assert.deepStrictEqual(record.fixture_hashes, reference.fixture_hashes, 'same reviewed fixture bytes');
    assert.deepStrictEqual(record.artifacts, reference.artifacts, 'same consumer tuple');
    assert.equal(record.runner_revision, reference.runner_revision, 'same runner revision');
    assert.deepStrictEqual(record.cases.map(c => c.fixture_id), reference.cases.map(c => c.fixture_id), 'complete same fixture inventory');
    // Recheck raw observations. A saved pass label or edited projection is not
    // authority, and a missing or corrupted record must never compare green.
    for (const [index, item] of record.cases.entries()) {
      const baseline = reference.cases[index];
      assert.equal(item.fixture_id, item.fixture.id, 'case identity matches fixture');
      assert.deepStrictEqual(item.fixture, expectedFixtures[`${item.fixture_id}.json`], 'case expectations match the current reviewed source fixture');
      if (item.fixture.signal_count) assert.equal(item.observation.mode, record.mode, 'signal observation uses its recording adapter');
      assert.equal(item.observation.sdk_php, record.artifacts.sdk_php, 'installed published SDK matches tuple');
      if (record.mode === 'embedded') assert.equal(item.observation.workflow_package, record.artifacts.workflow, 'installed Workflow matches tuple');
      assert.deepStrictEqual(item.fixture, baseline.fixture, 'same fixture expectations');
      const checked = checkObservation(item.fixture, item.observation, item.projection.workflow_id);
      assert.deepStrictEqual(item.projection, checked, 'projection matches raw observation');
      assert.deepStrictEqual(checked, baseline.projection, `${record.target}: ${item.fixture_id}`);
    }
  }
}
