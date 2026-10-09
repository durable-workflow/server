import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkObservation, compareRecords as compareCorpusRecords} from '../../scripts/conformance/server-parity/contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/one-activity.json', import.meta.url)));
const reviewed = {'one-activity.json': fixture};
const compareRecords = records => compareCorpusRecords(records, {'one-activity.json': 'fixture-sha'}, reviewed);
function observation(suffix = '') {
  const run = `run${suffix}`;
  const command = `command${suffix}`;
  const activity = `activity${suffix}`;
  const attempt = `attempt${suffix}`;
  const start = {workflow_instance_id: 'test-one-activity', workflow_run_id: run, workflow_command_id: command, workflow_type: fixture.workflow_type};
  const activityFields = {activity_execution_id: activity, activity_type: 'parity.v1.echo_activity', sequence: 1};
  const events = [
    {payload: {...start, outcome: 'started_new'}, decoded: {}},
    {payload: {...start, execution_timeout_seconds: 3600, run_timeout_seconds: 600, execution_deadline_at: '2026-01-01T01:00:00Z', run_deadline_at: '2026-01-01T00:10:00Z'}, decoded: {}},
    {payload: {...activityFields}, decoded: {activity_arguments: [fixture.input]}},
    {payload: {...activityFields, activity_attempt_id: attempt, attempt_number: 1}, decoded: {activity_arguments: [fixture.input]}},
    {payload: {...activityFields, activity_attempt_id: attempt, attempt_number: 1}, decoded: {result: fixture.input}},
    {payload: {}, decoded: {output: fixture.input}},
  ].map((event, index) => ({...event, typed_decoded: Object.fromEntries(Object.keys(event.decoded).map(key => [key, key === 'activity_arguments' ? {type: 'list', value: [fixture.typed_value]} : fixture.typed_value])), event_type: fixture.expected_events[index], sequence: index + 1, timestamp: `2026-01-01T00:00:0${index}Z`}));
  return structuredClone({sdk_php: '2.2.6', workflow_id: 'test-one-activity', run_id: run, workflow_type: fixture.workflow_type, namespace: 'default', task_queue: 'server-parity-v1', status: 'completed', payload_codec: 'avro', input: [fixture.input], output: fixture.input, typed_input: {type: 'list', value: [fixture.typed_value]}, typed_output: fixture.typed_value, execution: {started_at: '2026-01-01T00:00:00Z'}, events});
}
function record(suffix = '') {
  const raw = observation(suffix);
  return {schema: 'durable-workflow.server-parity-record/v1', target: `target${suffix}`, outcome: 'pass', runner_revision: 'a'.repeat(40), fixture_hashes: {'one-activity.json': 'fixture-sha'}, artifacts: {sdk_php: '2.2.6'}, cases: [{fixture_id: fixture.id, fixture, observation: raw, projection: checkObservation(fixture, raw, 'test-one-activity')}]};
}

test('independent generated IDs preserve their original relationships', () => {
  compareRecords([record(), record('-other-database')]);
});

for (const [name, corrupt] of [
  ['accepted work without completion', raw => {raw.status = 'pending';}],
  ['lost completion event', raw => {raw.events.pop();}],
  ['duplicate committed completion', raw => {raw.events.push(structuredClone(raw.events.at(-1)));}],
  ['reordered events', raw => {[raw.events[2], raw.events[3]] = [raw.events[3], raw.events[2]];}],
  ['history sequence gap', raw => {raw.events[3].sequence++;}],
  ['changed public workflow ID', raw => {raw.workflow_id = 'different-workflow';}],
  ['broken run relationship', raw => {raw.events[1].payload.workflow_run_id = 'unrelated-run';}],
  ['broken command relationship', raw => {raw.events[1].payload.workflow_command_id = 'unrelated-command';}],
  ['changed activity identity', raw => {raw.events[4].payload.activity_execution_id = 'unrelated-activity';}],
  ['changed committed attempt', raw => {raw.events[4].payload.activity_attempt_id = 'stale-attempt';}],
  ['colliding generated identities', raw => {raw.events[3].payload.activity_attempt_id = raw.run_id; raw.events[4].payload.activity_attempt_id = raw.run_id;}],
  ['changed attempt count', raw => {raw.events[4].payload.attempt_number = 2;}],
  ['wrong activity arguments', raw => {raw.events[2].decoded.activity_arguments = ['wrong'];}],
  ['wrong activity result', raw => {raw.events[4].decoded.result = 'wrong';}],
  ['wrong typed workflow result', raw => {raw.output.count = '42';}],
  ['integral double substituted for int64', raw => {raw.typed_output.value.count = {type: 'double', value: 42};}],
  ['wrong committed history value type', raw => {raw.events[4].typed_decoded.result.value.count = {type: 'double', value: 42};}],
  ['extended run deadline', raw => {raw.events[1].payload.run_deadline_at = '2026-01-01T00:20:00Z';}],
]) {
  test(`refuses ${name}`, () => {
    const raw = observation();
    corrupt(raw);
    assert.throws(() => checkObservation(fixture, raw, 'test-one-activity'));
  });
}

const signalFixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/repeated-signal.json', import.meta.url)));
function signalObservation(mode = 'http') {
  const raw = observation();
  raw.mode = mode;
  raw.workflow_type = signalFixture.workflow_type;
  raw.input = [signalFixture.input, signalFixture.signal_count];
  raw.output = signalFixture.signal_value;
  raw.typed_input = {type: 'list', value: [signalFixture.typed_value, {type: 'int64', value: '2'}]};
  raw.typed_output = signalFixture.typed_signal_value;
  raw.signal_deliveries = [];
  raw.events = raw.events.slice(0, 2);
  for (const event of raw.events) event.payload.workflow_type = signalFixture.workflow_type;
  raw.events[1].payload.declared_signals = ['payload'];
  for (let index = 0; index < signalFixture.signal_count; index++) {
    const opened = mode === 'embedded' ? {signal_wait_id: `wait-${index}`, signal_name: 'payload', sequence: index + 1}
      : {condition_wait_id: `wait-${index}`, condition_key: `payload:${index}`, condition_definition_fingerprint: `sha256:${'a'.repeat(64)}`, sequence: index + 1};
    const received = {workflow_instance_id: raw.workflow_id, workflow_run_id: raw.run_id, workflow_command_id: `signal-command-${index}`, signal_id: `signal-${index}`, signal_wait_id: mode === 'embedded' ? `wait-${index}` : `routing-wait-${index}`, signal_name: 'payload', payload_codec: 'avro', command: {id: `signal-command-${index}`, sequence: index + 2, message_sequence: index + 1}};
    raw.signal_deliveries.push({before: {status: 'waiting', run_id: raw.run_id}, response: {accepted: true, command_id: received.workflow_command_id, run_id: raw.run_id, workflow_id: raw.workflow_id, outcome: 'signal_received'}});
    raw.events.push(
      {event_type: mode === 'embedded' ? 'SignalWaitOpened' : 'ConditionWaitOpened', payload: opened, decoded: {}, typed_decoded: {}},
      {event_type: 'SignalReceived', payload: received, decoded: {arguments: [signalFixture.signal_value]}, typed_decoded: {arguments: {type: 'list', value: [signalFixture.typed_signal_value]}}},
      {event_type: 'MessageCursorAdvanced', payload: {stream_key: `instance:${raw.workflow_id}`, previous_position: index, new_position: index + 1}, decoded: {}, typed_decoded: {}},
      {event_type: 'SignalApplied', payload: {...received, ...(mode === 'embedded' ? {sequence: index + 1} : {})}, decoded: {value: signalFixture.signal_value}, typed_decoded: {value: signalFixture.typed_signal_value}},
      ...(mode === 'embedded' ? [] : [{event_type: 'ConditionWaitSatisfied', payload: {...opened, workflow_signal_id: received.signal_id, signal_name: received.signal_name, signal_wait_id: received.signal_wait_id}, decoded: {}, typed_decoded: {}}]),
    );
  }
  raw.events.push({event_type: 'WorkflowCompleted', payload: {}, decoded: {output: signalFixture.signal_value}, typed_decoded: {output: signalFixture.typed_signal_value}});
  raw.events = raw.events.map((event, index) => ({...event, sequence: index + 1, timestamp: '2026-01-01T00:00:01Z'}));
  return structuredClone(raw);
}

test('signal delivery/wait relationships agree across explicit service and embedded event shapes', () => {
  assert.deepStrictEqual(checkObservation(signalFixture, signalObservation(), 'test-one-activity'), checkObservation(signalFixture, signalObservation('embedded'), 'test-one-activity'));
});

const queryFixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/state-queries.json', import.meta.url)));
function queryObservation(mode = 'http') {
  const raw = signalObservation(mode);
  raw.workflow_type = queryFixture.workflow_type;
  for (const event of raw.events.slice(0, 2)) event.payload.workflow_type = queryFixture.workflow_type;
  raw.events[1].payload.declared_queries = ['state'];
  raw.queries = queryFixture.queries.map((expected, index) => {
    const end = index === 0 ? 3 : index === 1 ? (mode === 'http' ? 8 : 7) : raw.events.length;
    const history = structuredClone(raw.events.slice(0, end));
    return {name: 'state', arguments: queryFixture.query_arguments, typed_arguments: queryFixture.typed_query_arguments,
      before: {run_id: raw.run_id, status: expected.status}, after: {run_id: raw.run_id, status: expected.status},
      history_before: history, history_after: structuredClone(history),
      result: {request: queryFixture.query_arguments[0], delivered: expected.after_signal_count,
        last: index ? queryFixture.signal_value : null},
      typed_result: {type: 'map', value: {request: queryFixture.typed_query_arguments.value[0],
        delivered: {type: 'int64', value: String(index)}, last: index ? queryFixture.typed_signal_value : {type: 'null', value: null}}},
      ...(mode === 'http' ? {worker: {arguments: queryFixture.query_arguments, typed_arguments: queryFixture.typed_query_arguments,
        task: {query_task_id: `query-${index}`, query_task_attempt: 1, workflow_id: raw.workflow_id, run_id: raw.run_id,
          workflow_type: queryFixture.workflow_type, query_name: 'state', lease_owner: 'real-worker', query_arguments: {codec: 'avro'},
          history_events: history}}} : {})};
  });
  return structuredClone(raw);
}

test('waiting, signalled and completed queries preserve real state without history mutation', () => {
  assert.deepStrictEqual(checkObservation(queryFixture, queryObservation(), 'test-one-activity'),
    checkObservation(queryFixture, queryObservation('embedded'), 'test-one-activity'));
});

for (const [name, corrupt] of [
  ['missing completed query', raw => {raw.queries.pop();}],
  ['query alters durable history', raw => {raw.queries[0].history_after.push(structuredClone(raw.events[3]));}],
  ['query changes run status', raw => {raw.queries[0].after.status = 'running';}],
  ['query targets another run', raw => {raw.queries[0].before.run_id = 'different-run';}],
  ['query loses exact argument type', raw => {raw.queries[0].typed_arguments.value[0].value.number.type = 'double';}],
  ['query substitutes workflow result', raw => {raw.queries[1].result = raw.output;}],
  ['query returns stale state', raw => {raw.queries[1].result.delivered = 0;}],
  ['query changes last applied value', raw => {raw.queries[1].result.last.count++;}],
  ['query loses exact result type', raw => {raw.queries[2].typed_result.value.request.value.number.type = 'double';}],
  ['query worker observes another run', raw => {raw.queries[0].worker.task.run_id = 'different-run';}],
  ['query worker loses argument type', raw => {raw.queries[0].worker.typed_arguments.value[0].value.number.type = 'double';}],
  ['query task identity is reused', raw => {raw.queries[1].worker.task.query_task_id = raw.queries[0].worker.task.query_task_id;}],
  ['query worker receives stale history', raw => {raw.queries[1].worker.task.history_events = raw.queries[0].history_before;}],
]) {
  test(`refuses ${name}`, () => {
    const raw = queryObservation();
    corrupt(raw);
    assert.throws(() => checkObservation(queryFixture, raw, 'test-one-activity'));
  });
}
for (const [name, corrupt] of [
  ['deduplicated repeated signal', raw => {raw.events.splice(7, 5);}],
  ['reused signal identity', raw => {raw.events[8].payload.signal_id = raw.events[3].payload.signal_id;}],
  ['reused signal command', raw => {raw.events[8].payload.workflow_command_id = raw.events[3].payload.workflow_command_id;}],
  ['wrong signal run', raw => {raw.events[3].payload.workflow_run_id = 'other-run';}],
  ['changed signal value', raw => {raw.events[3].decoded.arguments = [signalFixture.input];}],
  ['changed signal type', raw => {raw.events[3].typed_decoded.arguments.value[0].value.count.type = 'double';}],
  ['wrong acknowledged command', raw => {raw.signal_deliveries[0].response.command_id = 'other';}],
  ['signal sent before durable wait', raw => {raw.signal_deliveries[0].before.status = 'running';}],
  ['changed condition fingerprint', raw => {raw.events[6].payload.condition_definition_fingerprint = `sha256:${'b'.repeat(64)}`;}],
  ['wrong condition signal', raw => {raw.events[6].payload.workflow_signal_id = 'other';}],
  ['workflow input substituted for signal result', raw => {raw.output = signalFixture.input;}],
  ['missing service SignalApplied', raw => {raw.events.splice(5, 1);}],
  ['lost message cursor progress', raw => {raw.events[4].payload.new_position = 0;}],
  ['message order changed', raw => {raw.events[3].payload.command.message_sequence = 2;}],
]) {
  test(`refuses ${name}`, () => {
    const raw = signalObservation();
    corrupt(raw);
    assert.throws(() => checkObservation(signalFixture, raw, 'test-one-activity'));
  });
}

test('a saved pass and copied projection cannot hide corrupted raw history', () => {
  const changed = record('-other');
  changed.cases[0].observation.events[4].payload.activity_attempt_id = 'stale';
  assert.throws(() => compareRecords([record(), changed]));
});

test('a one-unit int64 error beyond JSON precision remains observable', () => {
  const large = structuredClone(fixture);
  large.input.count = Number('9007199254740993');
  large.typed_value.value.count.value = '9007199254740993';
  const raw = observation();
  raw.input = [large.input];
  raw.output = large.input;
  raw.typed_input = {type: 'list', value: [large.typed_value]};
  raw.typed_output = structuredClone(large.typed_value);
  raw.typed_output.value.count.value = '9007199254740992';
  assert.equal(Number('9007199254740993'), Number('9007199254740992'));
  assert.throws(() => checkObservation(large, raw, 'test-one-activity'), /decoded workflow result types and exact int64 values/);
});

test('refuses partial inventories, blocked records and changed fixtures or consumers', () => {
  for (const corrupt of [
    value => {value.cases = [];},
    value => {value.outcome = 'runner-blocked';},
    value => {value.fixture_hashes['one-activity.json'] = 'different';},
    value => {value.artifacts.sdk_php = 'different';},
    value => {value.runner_revision = 'b'.repeat(40);},
    value => {value.cases[0].observation.sdk_php = 'wrong-installed-version';},
  ]) {
    const changed = record();
    corrupt(changed);
    assert.throws(() => compareRecords([record(), changed]));
  }
});

test('two empty recordings cannot establish parity', () => {
  const empty = record();
  empty.cases = [];
  empty.fixture_hashes = {};
  assert.throws(() => compareRecords([empty, structuredClone(empty)]));
});

test('two matching subsets cannot omit a required current fixture', () => {
  assert.throws(() => compareCorpusRecords([record(), record('-other')], {'one-activity.json': 'fixture-sha', 'echo.json': 'required-fixture-sha'}, reviewed));
});

test('matching recordings cannot omit hashed fixtures or repeat one case', () => {
  for (const corrupt of [
    value => {value.fixture_hashes['echo.json'] = 'missing-case-sha';},
    value => {value.cases.push(structuredClone(value.cases[0]));},
  ]) {
    const changed = record();
    corrupt(changed);
    assert.throws(() => compareRecords([changed, structuredClone(changed)]));
  }
});

test('matching forged expectations and pass labels cannot replace reviewed source fixtures', () => {
  const records = [structuredClone(record()), structuredClone(record('-other'))];
  for (const value of records) {
    const item = value.cases[0];
    item.fixture.expected_events[2] = 'UnreviewedReplacement';
    item.observation.events[2].event_type = 'UnreviewedReplacement';
    item.projection = checkObservation(item.fixture, item.observation, 'test-one-activity');
  }
  assert.throws(() => compareRecords(records), /current reviewed source fixture/);
});

const timerFixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/two-timers.json', import.meta.url)));
function timerObservation() {
  const raw = observation();
  raw.workflow_type = timerFixture.workflow_type;
  raw.input = [timerFixture.input];
  raw.output = timerFixture.input;
  raw.typed_input = {type: 'list', value: [timerFixture.typed_value]};
  raw.typed_output = timerFixture.typed_value;
  raw.events = [raw.events[0], raw.events[1], ...timerFixture.timer_delays.flatMap((delay, index) => {
    const scheduled = {timer_id: `timer-${index}`, sequence: index + 1, delay_seconds: delay, fire_at: `2026-01-01T00:00:0${index + delay + 2}Z`};
    return [
      {payload: scheduled, timestamp: `2026-01-01T00:00:0${index + 2}Z`},
      {payload: {...scheduled, fired_at: scheduled.fire_at}, timestamp: scheduled.fire_at},
    ].map(event => ({...event, decoded: {}, typed_decoded: {}}));
  }), {payload: {}, decoded: {output: timerFixture.input}, typed_decoded: {output: timerFixture.typed_value}, timestamp: '2026-01-01T00:00:05Z'}]
    .map((event, index) => ({...event, event_type: timerFixture.expected_events[index], sequence: index + 1}));
  for (const event of raw.events.slice(0, 2)) event.payload.workflow_type = timerFixture.workflow_type;
  return raw;
}

test('zero and delayed timers retain distinct identities and command sequences', () => {
  const checked = checkObservation(timerFixture, timerObservation(), 'test-one-activity');
  assert.equal(checked.events[2].timer_id, '@timer:1');
  assert.equal(checked.events[4].timer_id, '@timer:2');
});
test('explicit zero-delay event shape retains scheduled deadline authority', () => {
  const raw = timerObservation();
  delete raw.events[3].payload.fire_at;
  checkObservation(timerFixture, raw, 'test-one-activity');
  const strict = {...timerFixture, zero_delay_fired_fire_at_optional: false};
  assert.throws(() => checkObservation(strict, raw, 'test-one-activity'));
});
for (const [name, corrupt] of [
  ['early timer firing', raw => {raw.events[5].payload.fired_at = '2026-01-01T00:00:03.999Z';}],
  ['microsecond-early timer firing', raw => {
    raw.events[4].payload.fire_at = raw.events[5].payload.fire_at = '2026-01-01T00:00:04.123456Z';
    raw.events[5].payload.fired_at = '2026-01-01T00:00:04.123455Z';
  }],
  ['changed timer identity', raw => {raw.events[3].payload.timer_id = 'other';}],
  ['reused timer identity', raw => {raw.events[4].payload.timer_id = raw.events[2].payload.timer_id;}],
  ['extended timer deadline', raw => {raw.events[5].payload.fire_at = '2026-01-01T00:00:06Z';}],
  ['missing positive-delay deadline', raw => {delete raw.events[5].payload.fire_at;}],
  ['changed timer command sequence', raw => {raw.events[4].payload.sequence = 1;}],
  ['changed timer delay', raw => {raw.events[5].payload.delay_seconds = 2;}],
  ['duplicate timer firing', raw => {raw.events.splice(4, 0, structuredClone(raw.events[3]));}],
]) {
  test(`refuses ${name}`, () => {
    const raw = timerObservation();
    corrupt(raw);
    assert.throws(() => checkObservation(timerFixture, raw, 'test-one-activity'));
  });
}

const updateFixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/state-updates.json', import.meta.url)));
function updateObservation(mode = 'http') {
  const signal = signalObservation(mode);
  const raw = structuredClone(signal);
  raw.workflow_type = updateFixture.workflow_type;
  raw.input = [updateFixture.input, 1];
  raw.typed_input = {type: 'list', value: [updateFixture.typed_value, {type: 'int64', value: '1'}]};
  raw.output = updateFixture.output;
  raw.typed_output = updateFixture.typed_output;
  raw.events = signal.events.slice(0, 3);
  raw.events[0].payload.workflow_type = raw.events[1].payload.workflow_type = updateFixture.workflow_type;
  raw.events[1].payload.declared_updates = ['set_payload'];
  raw.updates = [];
  for (const [index, value] of updateFixture.update_values.entries()) {
    const arguments_ = {type: 'list', value: [updateFixture.typed_update_values[index]]};
    const fields = {update_id: `update-${index}`, workflow_command_id: `update-command-${index}`,
      workflow_instance_id: raw.workflow_id, workflow_run_id: raw.run_id, update_name: 'set_payload'};
    raw.events.push({event_type: 'UpdateAccepted', payload: {...fields, command: {id: fields.workflow_command_id, sequence: index + 2, message_sequence: index + 1}},
      decoded: {arguments: [value]}, typed_decoded: {arguments: arguments_}});
    const snapshot = structuredClone(raw.events);
    const result = {value, applied: index + 1};
    const typedResult = {type: 'map', value: {value: updateFixture.typed_update_values[index], applied: {type: 'int64', value: String(index + 1)}}};
    const sequence = mode === 'embedded' ? 1 : index + 2;
    raw.events.push(
      {event_type: 'UpdateApplied', payload: {...fields, sequence}, decoded: {arguments: [value]}, typed_decoded: {arguments: arguments_}},
      {event_type: 'UpdateCompleted', payload: {...fields, sequence}, decoded: {result}, typed_decoded: {result: typedResult}},
      {event_type: 'MessageCursorAdvanced', payload: {stream_key: `instance:${raw.workflow_id}`, previous_position: index, new_position: index + 1}, decoded: {}, typed_decoded: {}},
    );
    const receipt = {accepted: true, command_id: fields.workflow_command_id, update_id: fields.update_id,
      workflow_id: raw.workflow_id, run_id: raw.run_id, update_name: 'set_payload', update_status: 'accepted'};
    raw.updates.push({name: 'set_payload', request_id: `${raw.workflow_id}:update:${index}`,
      arguments: [value], typed_arguments: arguments_, before: {status: 'waiting', run_id: raw.run_id},
      accepted: receipt, duplicate_accepted: structuredClone(receipt),
      history_after_acceptance: snapshot, history_after_duplicate: structuredClone(snapshot),
      history_before_result: structuredClone(raw.events), history_after_result: structuredClone(raw.events),
      result, typed_result: typedResult,
      ...(mode === 'http' ? {worker: {arguments: [value], typed_arguments: arguments_, task: {
        task_id: `update-task-${index}`, workflow_update_id: fields.update_id,
        workflow_id: raw.workflow_id, run_id: raw.run_id, workflow_type: raw.workflow_type,
        workflow_task_attempt: 1, lease_owner: 'actual-worker', history_events: snapshot}}} : {})});
  }
  const received = structuredClone(signal.events[3]);
  received.payload.command.sequence = 4;
  received.payload.command.message_sequence = 3;
  received.decoded.arguments = [updateFixture.signal_value];
  received.typed_decoded.arguments = {type: 'list', value: [updateFixture.typed_signal_value]};
  const applied = structuredClone(signal.events[5]);
  applied.decoded.value = updateFixture.signal_value;
  applied.typed_decoded.value = updateFixture.typed_signal_value;
  raw.signal_deliveries = [signal.signal_deliveries[0]];
  raw.events.push(received,
    {event_type: 'MessageCursorAdvanced', payload: {stream_key: `instance:${raw.workflow_id}`, previous_position: 2, new_position: 3}, decoded: {}, typed_decoded: {}},
    applied,
    ...(mode === 'http' ? [signal.events[6]] : []),
    {event_type: 'WorkflowCompleted', payload: {}, decoded: {output: updateFixture.output}, typed_decoded: {output: updateFixture.typed_output}},
  );
  raw.events = raw.events.map((event, index) => ({...event, sequence: index + 1, timestamp: '2026-01-01T00:00:01Z'}));
  return structuredClone(raw);
}

test('updates retain original identities/results through duplicates and mutate workflow state in order', () => {
  assert.deepStrictEqual(checkObservation(updateFixture, updateObservation(), 'test-one-activity'),
    checkObservation(updateFixture, updateObservation('embedded'), 'test-one-activity'));
});
for (const [name, corrupt] of [
  ['missing update result', raw => {raw.updates.pop();}],
  ['duplicate update changes its command', raw => {raw.updates[0].duplicate_accepted.command_id = 'other';}],
  ['duplicate update changes its identity', raw => {raw.updates[0].duplicate_accepted.update_id = 'other';}],
  ['pending duplicate appends history', raw => {raw.updates[0].history_after_duplicate.push(raw.events[3]);}],
  ['completed duplicate appends history', raw => {raw.updates[0].history_after_result.push(raw.events[3]);}],
  ['update result ignores state', raw => {raw.updates[1].result.applied = 1;}],
  ['update replaces exact int64 argument', raw => {raw.updates[0].typed_arguments.value[0].value.number.type = 'double';}],
  ['update replaces exact int64 result', raw => {raw.updates[0].typed_result.value.value.value.number.type = 'double';}],
  ['update history targets another run', raw => {raw.events[4].payload.workflow_run_id = 'other';}],
  ['update completion loses original identity', raw => {raw.events[5].payload.update_id = 'other';}],
  ['update completion changes callback position', raw => {raw.events[5].payload.sequence++;}],
  ['update advances cursor twice', raw => {raw.events[6].payload.new_position++;}],
  ['update worker executes another update', raw => {raw.updates[0].worker.task.workflow_update_id = 'other';}],
  ['update worker sees stale state', raw => {raw.updates[1].worker.task.history_events = raw.updates[0].worker.task.history_events;}],
  ['finishing signal loses update cursor order', raw => {raw.events[12].payload.previous_position = 0;}],
  ['workflow forgets first update', raw => {raw.output.values.shift();}],
]) {
  test(`refuses ${name}`, () => {
    const raw = updateObservation();
    corrupt(raw);
    assert.throws(() => checkObservation(updateFixture, raw, 'test-one-activity'));
  });
}
