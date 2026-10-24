import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkMessageStreams} from '../../scripts/conformance/server-parity/message-streams-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/message-streams.json', import.meta.url)));
// What published Server 2.11.1 answered for this fixture's probe.
const recorded = JSON.parse(readFileSync(new URL('../Fixtures/ServerParityExpected/message-streams-observation.json', import.meta.url)));
const model = () => structuredClone({mode: 'http', message_streams: recorded.message_streams});
const projection = {scope: 'message_streams', first_run_events: 17, continued_run_events: 9};
const check = observation => checkMessageStreams(fixture, observation, 'probe-5');
const stream = (state, step, name) => state[step].response.streams.find(entry => entry.stream_name === name);

test('published PHP message streams pass', () => assert.deepStrictEqual(check(model()), projection));
test('embedded explicitly declares HTTP message streams inapplicable', () => assert.deepStrictEqual(check(
  {mode: 'embedded', message_streams: {applicable: false, reason: 'embedded_has_no_http_message_streams'}}), projection));
test('the recorded observation keeps the published reference', () => {
  assert.match(recorded.recorded_from, /^published Server 2\.11\.1 /);
  assert.equal(recorded.message_streams.workflow_id, 'probe-5-ms');
});

for (const [label, mutate] of [
  ['a missing observation', s => { for (const key of Object.keys(s)) delete s[key]; }],
  ['no contract in cluster info', s => s.cluster_contract = null],
  ['another contract version', s => s.cluster_contract.version = 2],
  ['the capability as a plain flag', s => s.worker_capability = false],
  ['an unsupported capability', s => s.worker_capability.supported = false],
  ['a stream before any append', s => s.list_before.response.count = 1],
  ['an unknown stream answered', s => s.show_before = {status: 200, response: {workflow_id: s.workflow_id, stream: null}}],
  ['a bad stream name served', s => s.show_bad_name = {status: 404, response: {reason: 'message_stream_not_found'}}],
  ['an unknown workflow listed', s => s.list_unknown = {status: 200, response: {workflow_id: 'x', count: 0, streams: []}}],
  ['a wait ahead of the cursor accepted', s => s.complete1_refusals.wait_ahead = s.complete1],
  ['a cursor past the stream accepted', s => s.complete1_refusals.cursor_ahead.status = 200],
  ['two waits on one stream accepted', s => s.complete1_refusals.repeated_wait.response.errors = {}],
  ['stream metadata before protocol 1.15 accepted', s => s.complete1_refusals.old_protocol.status = 200],
  ['the wait not recorded', s => stream(s, 'list_waiting', 'orders').pending_wait = null],
  ['a wait time without microseconds', s => stream(s, 'list_waiting', 'orders').pending_wait.since = '2026-10-23T18:00:00Z'],
  ['the first message at position 0', s => s.appends.m1.response.position = 0],
  ['an accepted append without its run', s => delete s.appends.m1.response.run_id],
  ['a duplicate appended again', s => s.appends.m1_duplicate = {status: 202, response: {...s.appends.m2_sdk.response, message_id: 'm1', position: 3}}],
  ['a conflicting payload accepted', s => Object.assign(s.appends.m1_conflict, {status: 200})],
  ['a missing message id accepted', s => s.appends.no_id.status = 202],
  ['a long message id accepted', s => s.appends.long_id.status = 202],
  ['input that is not a list accepted', s => s.appends.bad_input.status = 202],
  ['an append to an unknown workflow with the service shape', s => s.appends.unknown_workflow.response.duplicate = false],
  ['the runtime signal accepted from a client', s => s.appends.reserved_signal = {status: 202, response: {outcome: 'signal_received'}}],
  ['a refused stream name counted', s => stream(s, 'list_after_appends', 'orders').malformed_count = 5],
  ['a malformed append not counted', s => stream(s, 'list_after_appends', 'orders').malformed_count = 3],
  ['a raw refused message id kept', s => stream(s, 'list_after_appends', 'orders').last_input_message_id = 'bad id!'],
  ['a wait kept after an append', s => { stream(s, 'list_after_appends', 'orders').pending_wait = stream(s, 'list_waiting', 'orders').pending_wait; }],
  ['streams listed out of name order', s => s.list_after_appends.response.streams.reverse()],
  ['a show unlike the list', s => s.show_after_appends.response.stream.duplicate_count = 0],
  ['messages delivered out of order', s => s.poll2.history.reverse()],
  ['two messages in one signal', s => s.poll2.history[0].deliveries.push(s.poll2.history[1].deliveries[0])],
  ['a payload delivered by reference', s => s.poll2.history[0].deliveries[0].payload_envelope = ['codec', 'external_storage']],
  ['a negative cursor accepted', s => s.complete2_refusals.negative.status = 200],
  ['a wait behind the new cursor accepted', s => s.complete2_refusals.wait_behind.status = 200],
  ['the next message task not reported', s => s.complete2.response.created_task_ids = []],
  ['no stream cursor event', s => s.history_after2 = s.history_after2.filter(event => event.stream_key !== 'orders')],
  ['the stream cursor before the task completed', s => {
    s.history_after2.find(event => event.stream_key === 'orders').task_status = 'leased';
  }],
  ['the cursor not moved', s => stream(s, 'list_after2', 'orders').cursor_position = 0],
  ['the third message without the cursor', s => s.appends.m3.response.cursor_position = 0],
  ['the cursor not carried to the continued run', s => s.poll4.history.shift()],
  ['a consumed message delivered again', s => s.poll4.history.splice(1, 0, structuredClone(s.poll2.history[0]))],
  ['unconsumed messages out of append order', s => s.poll4.history.push(s.poll4.history.splice(1, 1)[0])],
  ['the continuation as the same run', s => s.continued_run_id = s.run_id],
  ['an append to a closed instance accepted', s => s.appends.closed.status = 202],
  ['a closed duplicate answered as a duplicate', s => Object.assign(s.appends.closed_duplicate.response, {reason: null, outcome: 'duplicate'})],
  ['SignalApplied for a consumed stream message', s => s.first_run_events.splice(7, 0, 'SignalApplied')],
  ['unconsumed signals not rejected', s => s.first_run_events = s.first_run_events.filter(type => type !== 'SignalRejected')],
  ['a continued run without its final cursor', s => s.continued_run_events.pop()],
  ['diagnostics with payloads', s => { stream(s, 'list_final', 'orders').payload = [{n: 3}]; }],
]) {
  test(`refuses ${label}`, () => {
    const observation = model();
    mutate(observation.message_streams);
    assert.throws(() => check(observation));
  });
}
