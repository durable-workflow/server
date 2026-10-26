import assert from 'node:assert/strict';

// Published 2.11.1 stores stream times in whole seconds and answers them in
// UTC with six fractional digits.
const TIME = /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.000000Z$/;
const STEPS = ['applicable', 'workflow_id', 'run_id', 'list_empty', 'show_missing', 'items_missing', 'close_missing',
  'append_first', 'append_retry', 'show_after_appends', 'items_window', 'show_after_window', 'items_all',
  'show_after_catch_up', 'items_wait_empty', 'items_wait_returned_after_wait', 'append_full', 'show_after_full',
  'items_after_full', 'refusals', 'list_after_refusals', 'close', 'close_again', 'append_closed', 'items_closed_tail',
  'items_closed_head', 'append_other', 'close_errored', 'append_errored', 'items_errored', 'list_final'];

const notFound = {message: 'Stream not found.', reason: 'stream_not_found'};
const runNotFound = {message: 'Workflow run not found.', reason: 'instance_not_found'};

function exact(read, status, response, label) {
  assert.equal(read?.status, status, `${label} status`);
  assert.deepStrictEqual(read.response, response, `${label} body`);
}

// Laravel's 422 with one attribute and its one message.
function refused(read, field, message, label) {
  exact(read, 422, {message, reason: 'validation_failed', errors: {[field]: [message]},
    validation_errors: {[field]: [message]}}, label);
}

// One stream as formatStream answers it: exact counters and states, times in
// whole seconds that never run backwards.
function stream(actual, expected, label) {
  const {opened_at: opened, last_appended_at: appended, closed_at: closed, ...rest} = actual;
  const {closed: terminal = false, ...fields} = expected;
  assert.deepStrictEqual(rest, {error_reason: null, retention_seconds: null, ...fields}, `${label} stream`);
  assert.match(opened ?? '', TIME, `${label} opened_at`);
  assert.match(appended ?? '', TIME, `${label} last_appended_at`);
  assert.ok(appended >= opened, `${label} appended after opening`);
  if (terminal) {
    assert.match(closed ?? '', TIME, `${label} closed_at`);
    assert.ok(closed >= appended, `${label} closed after the last append`);
  } else {
    assert.equal(closed, null, `${label} is not closed`);
  }
  return {opened, appended, closed};
}

function streamRead(read, ids, expected, label) {
  assert.equal(read?.status, 200, `${label} status`);
  const {stream: body, ...rest} = read.response;
  assert.deepStrictEqual(rest, ids, `${label} identity`);
  return stream(body, expected, label);
}

function appended(read, ids, expected, offsets, accepted, deduped, label) {
  assert.equal(read?.status, 200, `${label} status`);
  const {stream: body, ...rest} = read.response;
  assert.deepStrictEqual(rest, {...ids, accepted_offsets: offsets, accepted, deduped}, `${label} receipt`);
  return stream(body, expected, label);
}

function item(offset, fields) {
  return {offset, idempotency_key: null, origin: 'workflow_command', origin_reference: null, item_type: null, content_type: null,
    payload: null, payload_reference: null, payload_codec: null, ...fields};
}

function items(read, ids, expected, rows, nextOffset, terminal, label) {
  assert.equal(read?.status, 200, `${label} status`);
  const {stream: body, items: actual, next_offset: next, terminal: done, ...rest} = read.response;
  assert.deepStrictEqual(rest, ids, `${label} identity`);
  stream(body, expected, label);
  assert.equal(next, nextOffset, `${label} next_offset`);
  assert.equal(done, terminal, `${label} terminal`);
  assert.equal(actual.length, rows.length, `${label} item count`);
  actual.forEach((row, index) => {
    const {emitted_at: emitted, ...fields} = row;
    assert.deepStrictEqual(fields, rows[index], `${label} item ${index}`);
    assert.match(emitted ?? '', TIME, `${label} item ${index} emitted_at`);
  });
}

export function checkWorkflowStreamWrites(fixture, observation, workflowId) {
  const spec = fixture.workflow_stream_writes;
  const state = observation.workflow_stream_writes;
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_workflow_streams'},
      'embedded workflow streams are inapplicable');
    return {scope: 'workflow_stream_writes'};
  }
  assert.deepStrictEqual(Object.keys(state ?? {}), STEPS, 'workflow stream writes steps');
  assert.equal(state.applicable, true);
  const id = `${workflowId}-wsw`;
  assert.equal(state.workflow_id, id);
  assert.equal(typeof state.run_id, 'string');
  const ids = {workflow_id: id, workflow_run_id: state.run_id};
  const tokens = spec.stream;
  const audit = spec.other_stream;
  const batch = spec.batches;
  const label = name => `workflow stream writes ${name}`;

  exact(state.list_empty, 200, {...ids, count: 0, streams: []}, label('list_empty'));
  for (const name of ['show_missing', 'items_missing', 'close_missing']) exact(state[name], 404, notFound, label(name));

  // The first append opens the stream at offset 0; a repeated key returns the
  // existing item's offset and only new items move the counters.
  const open = {stream_name: tokens, status: 'open'};
  const first = appended(state.append_first, ids, {...open, last_offset: 1, total_items: 2, pending_items: 2},
    [0, 1], 2, 0, label('append_first'));
  appended(state.append_retry, ids, {...open, last_offset: 2, total_items: 3, pending_items: 3},
    [0, 2], 1, 1, label('append_retry'));
  const three = {...open, last_offset: 2, total_items: 3};
  streamRead(state.show_after_appends, ids, {...three, pending_items: 3}, label('show_after_appends'));

  const rows = [
    item(0, {...batch.first[0], idempotency_key: 'k1'}),
    item(1, {payload: batch.first[1].payload, payload_codec: 'avro', idempotency_key: 'k2'}),
    item(2, {payload: batch.retry[1].payload}),
  ];
  // A window that stops short of the head leaves pending_items alone; one
  // that reaches the head clears it, and a bounded wait past it returns empty.
  items(state.items_window, ids, {...three, pending_items: 3}, [rows[1]], 2, false, label('items_window'));
  streamRead(state.show_after_window, ids, {...three, pending_items: 3}, label('show_after_window'));
  items(state.items_all, ids, {...three, pending_items: 0}, rows, 3, false, label('items_all'));
  streamRead(state.show_after_catch_up, ids, {...three, pending_items: 0}, label('show_after_catch_up'));
  items(state.items_wait_empty, ids, {...three, pending_items: 0}, [], 3, false, label('items_wait_empty'));
  assert.equal(state.items_wait_returned_after_wait, true, label('a bounded wait on an open stream waits'));

  // Server #774 refuses the over-cap batch whole: nothing is written, so the
  // stream keeps its three items. Published 2.11.1 committed the items before
  // the cap and then refused the rest (a published defect, bounded here): its
  // answers are accepted as a set, and every later step follows the branch
  // the observation took.
  const whole = state.show_after_full?.response?.stream?.total_items === 3;
  exact(state.append_full, 429, {message: 'Stream pending-items cap reached; consumer is draining too slowly.',
    reason: 'stream_full', pending_items: whole ? 0 : spec.max_pending_items, max_pending_items: spec.max_pending_items},
    label('append_full'));
  const five = whole ? three : {...open, last_offset: 4, total_items: 5};
  const full = streamRead(state.show_after_full, ids, {...five, pending_items: whole ? 0 : 2}, label('show_after_full'));
  assert.ok(full.appended >= first.appended, label('the capped append kept last_appended_at in order'));
  items(state.items_after_full, ids, {...five, pending_items: 0},
    whole ? [] : [item(3, {...batch.over_cap[0]}), item(4, {...batch.over_cap[1]})], whole ? 3 : 5, false,
    label('items_after_full'));

  const r = state.refusals;
  const refusal = name => label(`refusal ${name}`);
  refused(r.no_items, 'items', 'The items field is required.', refusal('no_items'));
  refused(r.empty_items, 'items', 'The items field is required.', refusal('empty_items'));
  refused(r.too_many_items, 'items', 'The items field must not have more than 500 items.', refusal('too_many_items'));
  refused(r.item_not_object, 'items.0', 'The items.0 field must be an array.', refusal('item_not_object'));
  const codecMessage = `unsupported_payload_codec: workflow payload codec "${spec.bad_codec}" is not supported by Durable Workflow 2.0; `
    + 'use codec="avro" with the fixed Avro Value schema and single-object framing. JSON remains the HTTP document '
    + 'transport, not a workflow payload codec.';
  refused(r.bad_codec, 'items.0.payload_codec', codecMessage, refusal('bad_codec'));
  refused(r.long_idempotency_key, 'items.0.idempotency_key',
    'The items.0.idempotency_key field must not be greater than 191 characters.', refusal('long_idempotency_key'));
  refused(r.zero_max_pending, 'max_pending_items', 'The max pending items field must be at least 1.', refusal('zero_max_pending'));
  exact(r.bad_stream_append, 400, {message: 'invalid stream name', reason: 'invalid_request'}, refusal('bad_stream_append'));
  exact(r.unstored_reference, 422, {schema: 'durable-workflow.v2.runtime-external-payload-error.v1',
    reason: 'external_payload_unsupported',
    message: 'Payload reference fields require an opaque runtime external payload reference object.',
    retryable: false, status: 422}, refusal('unstored_reference'));
  for (const name of ['unknown_run_append', 'unknown_workflow_append', 'unknown_run_close']) {
    exact(r[name], 404, runNotFound, refusal(name));
  }
  for (const name of ['bad_stream_show', 'bad_stream_items', 'bad_stream_close']) {
    exact(r[name], 400, {message: 'invalid stream name', reason: 'invalid_stream_name'}, refusal(name));
  }
  refused(r.negative_from, 'from', 'The from field must be at least 0.', refusal('negative_from'));
  refused(r.too_many_read, 'max_items', 'The max items field must not be greater than 500.', refusal('too_many_read'));
  refused(r.long_wait, 'wait_seconds', 'The wait seconds field must not be greater than 60.', refusal('long_wait'));
  refused(r.long_error_reason, 'error_reason', 'The error reason field must not be greater than 191 characters.',
    refusal('long_error_reason'));
  refused(r.zero_retention, 'retention_seconds', 'The retention seconds field must be at least 1.', refusal('zero_retention'));
  assert.deepStrictEqual(Object.keys(r).sort(), ['bad_codec', 'bad_stream_append', 'bad_stream_close', 'bad_stream_items',
    'bad_stream_show', 'empty_items', 'item_not_object', 'long_error_reason', 'long_idempotency_key', 'long_wait',
    'negative_from', 'no_items', 'too_many_items', 'too_many_read', 'unknown_run_append', 'unknown_run_close',
    'unknown_workflow_append', 'unstored_reference', 'zero_max_pending', 'zero_retention'], label('refusal set'));

  // No refusal wrote anything: the list still shows one stream as it was.
  assert.equal(state.list_after_refusals?.status, 200, label('list_after_refusals status'));
  assert.deepStrictEqual({...state.list_after_refusals.response, streams: undefined}, {...ids, count: 1, streams: undefined},
    label('list_after_refusals identity'));
  stream(state.list_after_refusals.response.streams[0], {...five, pending_items: 0}, label('list_after_refusals stream'));

  // Close once; a terminal stream keeps its first close, error and retention.
  const closed = {...five, pending_items: 0, status: 'closed', retention_seconds: 3600, closed: true};
  const firstClose = streamRead(state.close, ids, closed, label('close'));
  const again = streamRead(state.close_again, ids, closed, label('close_again'));
  assert.equal(again.closed, firstClose.closed, label('a repeated close keeps closed_at'));
  exact(state.append_closed, 409, {message: 'Stream is closed.', reason: 'stream_closed'}, label('append_closed'));
  items(state.items_closed_tail, ids, closed, [], 5, true, label('items_closed_tail'));
  items(state.items_closed_head, ids, closed, whole ? [] : [item(4, {...batch.over_cap[1]})], whole ? 4 : 5, true,
    label('items_closed_head'));

  const other = {stream_name: audit, status: 'open', last_offset: 0, total_items: 1, pending_items: 1};
  appended(state.append_other, ids, other, [0], 1, 0, label('append_other'));
  const errored = {...other, status: 'errored', error_reason: spec.error_reason, retention_seconds: spec.retention_seconds, closed: true};
  streamRead(state.close_errored, ids, errored, label('close_errored'));
  exact(state.append_errored, 409, {message: 'Stream is errored.', reason: 'stream_errored'}, label('append_errored'));
  items(state.items_errored, ids, {...errored, pending_items: 0}, [item(0, {...batch.other[0]})], 1, true, label('items_errored'));

  assert.equal(state.list_final?.status, 200, label('list_final status'));
  const final = state.list_final.response;
  assert.equal(final.count, 2, label('list_final count'));
  assert.deepStrictEqual(final.streams.map(entry => entry.stream_name), [audit, tokens], label('list_final is by name'));
  stream(final.streams[0], {...errored, pending_items: 0}, label('list_final errored'));
  stream(final.streams[1], closed, label('list_final closed'));
  return {scope: 'workflow_stream_writes'};
}
