import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkObservation, compareRecords as compareCorpusRecords} from '../../scripts/conformance/server-parity/contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/one-activity.json', import.meta.url)));
const compareRecords = records => compareCorpusRecords(records, {'one-activity.json': 'fixture-sha'});
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
  assert.throws(() => compareCorpusRecords([record(), record('-other')], {'one-activity.json': 'fixture-sha', 'echo.json': 'required-fixture-sha'}));
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
