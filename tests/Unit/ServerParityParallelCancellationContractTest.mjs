import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkParallelCancellation} from '../../scripts/conformance/server-parity/parallel-cancellation-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/parallel-cancellation.json', import.meta.url), 'utf8'));
const expected = fixture.parallel_cancellation.expected;
const observation = () => structuredClone({mode: 'http', parallel_cancellation: {applicable: true, ...expected}});
const cases = Object.keys(fixture.parallel_cancellation.cases).length;
const cancelled = name => expected[name].events.map(event => event.event_type).filter(type => /^(Activity|Timer)Cancelled$/.test(type));

test('the published answers pass', () => {
  assert.deepStrictEqual(checkParallelCancellation(fixture, observation()), {scope: 'parallel_cancellation', cases});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkParallelCancellation(fixture,
    {mode: 'embedded', parallel_cancellation: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'parallel_cancellation', cases});
});

test('each boundary is delivered once and cancels every member by its kind', () => {
  for (const name of Object.keys(expected)) {
    assert.deepStrictEqual([expected[name].delivery.status, expected[name].delivery.delivered,
      expected[name].completion.run_status, expected[name].activity_heartbeat.cancel_requested], [200, true, 'cancelled', true], name);
  }
  assert.deepStrictEqual(cancelled('activities'), ['ActivityCancelled', 'ActivityCancelled']);
  assert.deepStrictEqual(cancelled('mixed'), ['ActivityCancelled', 'TimerCancelled']);
  assert.deepStrictEqual(cancelled('selection'), ['ActivityCancelled', 'TimerCancelled']);
  assert.deepStrictEqual([expected.mixed.refused_delivery.status, expected.mixed.refused_delivery.reason],
    [409, 'cancellation_delivery_shape_mismatch']);
  assert.deepStrictEqual([expected.selection.delivery.call_kind, expected.selection.delivery.operation_sequence,
    expected.selection.delivery.operation_sequence_span], ['selection_handle', 1, 2]);
});

for (const [name, mutate] of [
  ['a refused group delivery', state => { state.activities.delivery = {status: 422, reason: 'cancellation_delivery_boundary_not_available'}; }],
  ['a member left open', state => { state.mixed.events = state.mixed.events.filter(event => event.event_type !== 'TimerCancelled'); }],
  ['a handle without its operation range', state => { state.selection.delivery.operation_sequence_span = 1; }],
  ['an unexpected case', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.parallel_cancellation);
  assert.throws(() => checkParallelCancellation(fixture, raw));
});
