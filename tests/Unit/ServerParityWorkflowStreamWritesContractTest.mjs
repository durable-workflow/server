import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkWorkflowStreamWrites} from '../../scripts/conformance/server-parity/workflow-stream-writes-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/workflow-stream-writes.json', import.meta.url)));
// The observation published Server 2.11.1 returned for this fixture.
const recorded = JSON.parse(readFileSync(
  new URL('../Fixtures/ServerParityExpected/workflow-stream-writes-observation.json', import.meta.url)));
const id = 'parity-v1-workflow-stream-writes';
const observation = (change = () => {}) => {
  const state = structuredClone(recorded);
  change(state);
  return {mode: 'http', workflow_stream_writes: state};
};
const check = state => checkWorkflowStreamWrites(fixture, state, id);

test('the published 2.11.1 recording satisfies the contract', () => {
  assert.deepStrictEqual(check(observation()), {scope: 'workflow_stream_writes'});
});

// The same run under Server #774: the over-cap batch is refused whole, so the
// tokens stream keeps its three items through every later step.
const three = stream => Object.assign(stream, {last_offset: 2, total_items: 3});
const wholeBatch = state => {
  state.append_full.response.pending_items = 0;
  three(state.show_after_full.response.stream).pending_items = 0;
  Object.assign(state.items_after_full.response, {items: [], next_offset: 3});
  three(state.items_after_full.response.stream);
  three(state.list_after_refusals.response.streams[0]);
  three(state.close.response.stream);
  three(state.close_again.response.stream);
  three(state.items_closed_tail.response.stream);
  Object.assign(state.items_closed_head.response, {items: [], next_offset: 4});
  three(state.items_closed_head.response.stream);
  three(state.list_final.response.streams.find(entry => entry.stream_name === 'tokens'));
};

test('a whole-batch capacity refusal (Server #774) satisfies the contract', () => {
  assert.deepStrictEqual(check(observation(wholeBatch)), {scope: 'workflow_stream_writes'});
});

test('embedded applications are explicitly inapplicable', () => {
  assert.deepStrictEqual(check({mode: 'embedded', workflow_stream_writes:
    {applicable: false, reason: 'embedded_has_no_http_workflow_streams'}}), {scope: 'workflow_stream_writes'});
  assert.throws(() => check({mode: 'embedded', workflow_stream_writes: {applicable: true}}), /embedded workflow streams/);
});

for (const [label, change, message] of [
  // Native answered 501 for both producer routes before this fixture.
  ['an unimplemented append route', state => {
    state.append_first = {status: 501, response: {reason: 'rust_capability_not_implemented'}};
  }, /append_first status/],
  ['offsets that start at 1', state => { state.append_first.response.accepted_offsets = [1, 2]; }, /append_first receipt/],
  ['a repeated idempotency key appended again', state => {
    state.append_retry.response.accepted_offsets = [2, 3];
    state.append_retry.response.accepted = 2;
    state.append_retry.response.deduped = 0;
  }, /append_retry receipt/],
  ['a partial window that clears pending items', state => {
    state.show_after_window.response.stream.pending_items = 0;
  }, /show_after_window stream/],
  ['a read at the head that leaves pending items', state => {
    state.items_all.response.stream.pending_items = 3;
  }, /items_all stream/],
  ['a whole refusal that reports the published pending count', state => {
    wholeBatch(state);
    state.append_full.response.pending_items = 2;
  }, /append_full body/],
  ['a whole refusal whose later reads still show the capped items', state => {
    wholeBatch(state);
    state.items_closed_head.response.next_offset = 5;
  }, /items_closed_head next_offset/],
  ['a cap answered as accepted', state => { state.append_full.status = 200; }, /append_full status/],
  ['a repeated close that replaces the first', state => {
    state.close_again.response.stream.error_reason = 'late error';
    state.close_again.response.stream.status = 'errored';
  }, /close_again stream/],
  ['a closed stream that accepts appends', state => {
    state.append_closed = {status: 200, response: {}};
  }, /append_closed status/],
  ['a closed tail that is not terminal', state => { state.items_closed_tail.response.terminal = false; }, /items_closed_tail terminal/],
  ['an errored stream without its reason', state => { state.close_errored.response.stream.error_reason = null; }, /close_errored stream/],
  ['the default retention missing', state => { state.close.response.stream.retention_seconds = null; }, /close stream/],
  ['an invalid name refused with the read reason', state => {
    state.refusals.bad_stream_append.response.reason = 'invalid_stream_name';
  }, /refusal bad_stream_append/],
  ['a string reference accepted', state => {
    state.refusals.unstored_reference = {status: 200, response: {}};
  }, /refusal unstored_reference/],
  // The runner parses records losslessly; JSON.parse here cannot hold the
  // int64 itself, so this checks any change to a stored payload value.
  ['a changed payload value', state => { state.items_all.response.items[0].payload.token = 'Hellp'; }, /items_all item 0/],
  ['microsecond times', state => {
    state.append_first.response.stream.opened_at = '2026-10-25T06:42:31.123456Z';
  }, /append_first opened_at/],
  ['a list out of name order', state => { state.list_final.response.streams.reverse(); }, /list_final is by name/],
  ['a missing step', state => { delete state.items_window; }, /steps/],
]) {
  test(`the contract refuses ${label}`, () => {
    assert.throws(() => check(observation(change)), message);
  });
}
