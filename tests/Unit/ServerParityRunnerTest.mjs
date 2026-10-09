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
