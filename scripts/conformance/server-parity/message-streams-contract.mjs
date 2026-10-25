import assert from 'node:assert/strict';

const TIME = /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/;
const MESSAGE = 'durable-workflow.v2.message-stream.message';
const CURSOR = 'durable-workflow.v2.message-stream.cursor';

// Laravel's 422 with one attribute and its one message.
function refused(read, field, message, label) {
  assert.equal(read.status, 422, `${label} status`);
  assert.deepStrictEqual(read.response, {message, reason: 'validation_failed', errors: {[field]: [message]},
    validation_errors: {[field]: [message]}}, `${label} body`);
}

// One stream's diagnostics: exact counts, the pending wait by run and a
// microsecond UTC time, and the last input.
function checkStream(entry, expected, runId, label) {
  const {pending_wait: wait, last_input_at: inputAt, ...rest} = entry;
  const {wait: expectedWait = null, input = null, ...counts} = expected;
  assert.deepStrictEqual(rest, {...counts, pending_count: counts.last_position - counts.cursor_position,
    duplicate_count: counts.duplicate_count ?? 0, malformed_count: counts.malformed_count ?? 0,
    last_input_outcome: input?.outcome ?? null, last_input_message_id: input?.message_id ?? null}, `${label} counts`);
  if (expectedWait === null) {
    assert.equal(wait, null, `${label} has no pending wait`);
  } else {
    assert.equal(wait?.run_id, runId, `${label} pending wait run`);
    assert.equal(wait?.after_position, expectedWait.after_position, `${label} pending wait position`);
    assert.match(wait?.since ?? '', TIME, `${label} pending wait time`);
  }
  if (input === null) {
    assert.equal(inputAt, null, `${label} has no input yet`);
  } else {
    assert.match(inputAt ?? '', TIME, `${label} last input time`);
  }
}

function checkList(read, workflowId, streams, runId, label) {
  assert.equal(read.status, 200, `${label} status`);
  assert.equal(read.response.workflow_id, workflowId, `${label} workflow`);
  assert.equal(read.response.count, Object.keys(streams).length, `${label} count`);
  assert.deepStrictEqual(read.response.streams.map(entry => entry.stream_name), Object.keys(streams), `${label} streams by name`);
  for (const entry of read.response.streams) {
    checkStream(entry, {stream_name: entry.stream_name, ...streams[entry.stream_name]}, runId, `${label} ${entry.stream_name}`);
  }
}

function accepted(read, workflowId, runId, stream, messageId, position, cursor, label) {
  assert.equal(read.status, 202, `${label} status`);
  assert.deepStrictEqual(read.response, {accepted: true, duplicate: false, workflow_id: workflowId, run_id: runId,
    stream_name: stream, message_id: messageId, position, cursor_position: cursor, outcome: 'accepted', reason: null}, `${label} body`);
}

function completed(read, runId, status, created, label) {
  assert.equal(read.status, 200, `${label} status`);
  const {task_id: taskId, created_task_ids: createdIds, ...rest} = read.response;
  assert.equal(typeof taskId, 'string', `${label} task`);
  assert.deepStrictEqual(rest, {workflow_task_attempt: 1, outcome: 'completed', recorded: true, run_id: runId,
    run_status: status, reason: null}, `${label} outcome`);
  assert.equal(createdIds.length, created, `${label} created tasks, the next message task included`);
}

// A message delivery or cursor checkpoint as the worker reads it.
function delivery(stream, messageId, position, payload) {
  return [{schema: MESSAGE, stream_name: stream, message_id: messageId, position, payload_envelope: ['blob', 'codec'], payload}];
}

// Reviewed message streams (published Server 2.11.1).
export function checkMessageStreams(fixture, observation, workflowId) {
  const definition = fixture.message_streams;
  const state = observation.message_streams;
  // The same for every target: embedded applications have no HTTP message streams.
  const projection = {scope: 'message_streams', first_run_events: definition.first_run_events.length,
    continued_run_events: definition.continued_run_events.length};
  assert.ok(state && typeof state === 'object', 'actual message stream observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_message_streams'},
      'embedded message streams explicitly inapplicable');
    return projection;
  }
  const id = `${workflowId}-ms`;
  const {stream, other_stream: other, refusals: text, messages} = definition;
  const run = state.run_id;
  assert.equal(state.workflow_id, id, 'stream workflow');
  assert.deepStrictEqual(state.cluster_contract, definition.manifest, 'cluster info publishes the contract');
  assert.deepStrictEqual(state.worker_capability, {...definition.manifest, supported: true}, 'workers see the supported contract');

  assert.deepStrictEqual(state.list_before, {status: 200, response: {workflow_id: id, count: 0, streams: []}}, 'no streams yet');
  assert.deepStrictEqual(state.show_before, {status: 404, response: {workflow_id: id, stream_name: stream, reason: 'message_stream_not_found'}},
    'an unknown stream');
  refused(state.show_bad_name, 'stream_name', text.stream_name, 'a stream name outside the pattern');
  assert.deepStrictEqual(state.list_unknown, {status: 404, response: {message: 'Workflow not found.', reason: 'instance_not_found'}},
    'an unknown workflow');
  assert.deepStrictEqual(state.poll1.history, [], 'nothing delivered before the first append');

  const first = state.complete1_refusals;
  refused(first.wait_ahead, 'message_stream_waits.0.after_position', text.wait_after_cursor, 'a wait ahead of the cursor');
  refused(first.cursor_ahead, 'message_stream_cursors.0.through_position', text.cursor_beyond, 'a cursor past the last message');
  refused(first.bad_name, 'message_stream_waits.0.stream_name', text.metadata_stream_name, 'a wait stream name outside the pattern');
  refused(first.repeated_wait, 'message_stream_waits.1.stream_name', text.duplicate_wait, 'two waits on one stream');
  assert.equal(first.old_protocol.status, 409, 'stream metadata before protocol 1.15');
  const {task_id: oldTask, ...oldProtocol} = first.old_protocol.response;
  assert.equal(typeof oldTask, 'string', 'the refused task');
  assert.deepStrictEqual(oldProtocol, {workflow_task_attempt: 1, outcome: 'rejected', reason: 'message_streams_unavailable',
    requested_version: '1.14', minimum_protocol_version: '1.15', remediation: text.old_protocol_remediation}, 'the protocol refusal');
  completed(state.complete1, run, 'waiting', 0, 'the first completion with a wait');
  checkList(state.list_waiting, id, {[stream]: {last_position: 0, cursor_position: 0, wait: {after_position: 0}}}, run, 'the waiting stream');

  const appends = state.appends;
  accepted(appends.m1, id, run, stream, 'm1', 1, 0, 'the first message');
  assert.deepStrictEqual(appends.m1_duplicate, {status: 200, response: {accepted: true, duplicate: true, workflow_id: id, stream_name: stream,
    message_id: 'm1', position: 1, cursor_position: 0, outcome: 'duplicate', reason: null}}, 'the same message again');
  assert.deepStrictEqual(appends.m1_conflict, {status: 409, response: {accepted: false, duplicate: false, workflow_id: id, stream_name: stream,
    message_id: 'm1', position: 1, cursor_position: 0, outcome: 'rejected', reason: 'message_identity_conflict'}}, 'the id with another payload');
  assert.deepStrictEqual(appends.m2_sdk.response, {accepted: true, duplicate: false, workflow_id: id, run_id: run, stream_name: stream,
    message_id: 'm2', position: 2, cursor_position: 0, outcome: 'accepted', reason: null}, 'the published SDK append');
  refused(appends.no_id, 'message_id', text.message_id_required, 'no message id');
  refused(appends.bad_id, 'message_id', text.message_id_format, 'a message id outside the pattern');
  refused(appends.long_id, 'message_id', text.message_id_max, 'a message id too long');
  refused(appends.bad_input, 'input', text.input_array, 'input that is not a list');
  refused(appends.bad_stream, 'stream_name', text.stream_name, 'an append to a stream name outside the pattern');
  assert.deepStrictEqual(appends.unknown_workflow, {status: 404, response: {accepted: false, workflow_id: `${id}-missing`,
    stream_name: stream, outcome: 'rejected', reason: 'instance_not_found'}}, 'an append to an unknown workflow');
  accepted(appends.other_stream, id, run, other, 'a1', 1, 0, 'a message without input on another stream');
  assert.equal(appends.reserved_signal.status, 422, 'the runtime signal is not a public signal');
  assert.equal(appends.reserved_signal.response.reason, 'runtime_reserved_signal', 'the reserved signal refusal');
  // Four refused appends to the stream are malformed; the last kept the
  // valid id m3. The refused stream name counts nowhere.
  const appended = {
    [other]: {last_position: 1, cursor_position: 0, input: {outcome: 'accepted', message_id: 'a1'}},
    [stream]: {last_position: 2, cursor_position: 0, duplicate_count: 2, malformed_count: 4, input: {outcome: 'malformed', message_id: 'm3'}},
  };
  checkList(state.list_after_appends, id, appended, run, 'after the appends');
  assert.equal(state.show_after_appends.status, 200, 'one stream');
  assert.deepStrictEqual(state.show_after_appends.response, {workflow_id: id,
    stream: state.list_after_appends.response.streams.find(entry => entry.stream_name === stream)}, 'one stream as listed');

  assert.equal(state.poll2.run_id, run, 'the woken run');
  assert.deepStrictEqual(state.poll2.history, [
    {event_type: 'SignalReceived', deliveries: delivery(stream, 'm1', 1, messages.m1)},
    {event_type: 'SignalReceived', deliveries: delivery(stream, 'm2', 2, messages.m2)},
    {event_type: 'SignalReceived', deliveries: delivery(other, 'a1', 1, [])},
  ], 'each message is one runtime signal, in append order');
  const second = state.complete2_refusals;
  refused(second.beyond, 'message_stream_cursors.0.through_position', text.cursor_beyond, 'a cursor past the last message');
  refused(second.negative, 'message_stream_cursors.0.through_position', text.negative_cursor, 'a negative cursor');
  refused(second.wait_behind, 'message_stream_waits.0.after_position', text.wait_after_cursor, 'a wait behind the new cursor');
  // Two messages are still unconsumed, so the next message task follows.
  completed(state.complete2, run, 'waiting', 1, 'the first acknowledgement');
  assert.deepStrictEqual(state.history_after2.filter(event => event.event_type === 'MessageCursorAdvanced'), [
    {event_type: 'MessageCursorAdvanced', stream_key: 'instance', previous_position: 0, new_position: 1, task_status: 'leased'},
    {event_type: 'MessageCursorAdvanced', stream_key: stream, previous_position: 0, new_position: 1, task_status: 'completed'},
  ], 'the instance cursor while the task runs, then the stream cursor once it completed');
  checkList(state.list_after2, id, {...appended, [stream]: {...appended[stream], cursor_position: 1}}, run, 'after the acknowledgement');

  accepted(appends.m3, id, run, stream, 'm3', 3, 1, 'the third message');
  assert.equal(state.poll3.run_id, run, 'the same run again');
  assert.deepStrictEqual(state.poll3.history.filter(event => event.event_type === 'SignalReceived').at(-1),
    {event_type: 'SignalReceived', deliveries: delivery(stream, 'm3', 3, messages.m3)}, 'the third message delivered');
  completed(state.complete3, run, 'completed', 1, 'continue as new');
  checkList(state.list_after_continue, id, {...appended, [stream]: {...appended[stream], last_position: 3, cursor_position: 2,
    input: {outcome: 'accepted', message_id: 'm3'}}}, run, 'after continue as new');

  const continued = state.continued_run_id;
  assert.equal(state.poll4.run_id, continued, 'the continued run');
  assert.notEqual(continued, run, 'a new run');
  assert.deepStrictEqual(state.poll4.history, [
    {event_type: 'SignalReceived', deliveries: [{schema: CURSOR, stream_name: stream, through_position: 2}]},
    {event_type: 'SignalReceived', deliveries: delivery(other, 'a1', 1, [])},
    {event_type: 'SignalReceived', deliveries: delivery(stream, 'm3', 3, messages.m3)},
  ], 'the continued run receives the cursor, then every unconsumed message by append order');
  completed(state.complete4, continued, 'completed', 0, 'the continued run completes');
  checkList(state.list_final, id, {...appended, [stream]: {...appended[stream], last_position: 3, cursor_position: 3,
    input: {outcome: 'accepted', message_id: 'm3'}}}, run, 'the final streams');
  for (const [name, messageId] of [['closed', 'm4'], ['closed_duplicate', 'm1']]) {
    assert.deepStrictEqual(appends[name], {status: 409, response: {accepted: false, duplicate: false, workflow_id: id, stream_name: stream,
      message_id: messageId, position: null, outcome: 'rejected', reason: 'run_not_active'}}, `${name}: the instance has no open run`);
  }

  assert.deepStrictEqual(state.first_run_events, definition.first_run_events,
    'the first run: deliveries consumed by the condition waits without SignalApplied, the rest rejected at the close');
  assert.deepStrictEqual(state.continued_run_events, definition.continued_run_events, 'the continued run');
  assert.deepStrictEqual(state.first_run_streams.filter(event => event.event_type === 'MessageCursorAdvanced').map(event => event.stream_key),
    ['instance', stream, 'instance', stream], 'first run cursors');
  assert.ok(!JSON.stringify(state.list_final).includes('"n":'), 'diagnostics never carry payloads');
  for (const event of [...state.first_run_streams, ...state.continued_run_streams]) {
    if (event.event_type === 'SignalReceived') {
      assert.equal(event.deliveries.length, 1, 'one delivery per runtime signal');
    }
  }

  return projection;
}
