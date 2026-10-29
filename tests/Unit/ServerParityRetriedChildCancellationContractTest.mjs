import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkRetriedChildCancellation} from '../../scripts/conformance/server-parity/retried-child-cancellation-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/retried-child-cancellation.json', import.meta.url), 'utf8'));
const expected = fixture.retried_child_cancellation.expected;
const observation = () => structuredClone({mode: 'http', retried_child_cancellation: {applicable: true, ...expected}});
const cases = Object.keys(fixture.retried_child_cancellation.cases).length;
const kinds = name => expected[name].parent_events.map(event => event.event_type);

test('the published answers pass', () => {
  assert.deepStrictEqual(checkRetriedChildCancellation(fixture, observation()), {scope: 'retried_child_cancellation', cases});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkRetriedChildCancellation(fixture,
    {mode: 'embedded', retried_child_cancellation: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'retried_child_cancellation', cases});
});

test('the cancellation is admitted, delivered at the retried child and closes the parent', () => {
  for (const name of Object.keys(expected)) {
    assert.deepStrictEqual([expected[name].request.status, expected[name].delivery.status,
      expected[name].parent_completion.run_status], [202, 200, 'cancelled'], name);
  }
  assert.ok(!kinds('abandoning').includes('ChildCancellationRequested'));
  assert.ok(kinds('try_cancelling').includes('ChildCancellationRequested'));
  const requested = expected.try_cancelling.parent_events.find(event => event.event_type === 'ChildCancellationRequested');
  assert.equal(requested.child_workflow_run_id, expected.try_cancelling.child_runs[1].run_id, 'the retry run is asked');
  assert.deepStrictEqual(expected.try_cancelling.retry_events, ['WorkflowStarted', 'CooperativeCancellationRequested']);
});

for (const [name, mutate] of [
  ['a refused request', state => { state.abandoning.request = {status: 422, reason: 'cooperative_cancellation_propagation_not_available', duplicate: null}; }],
  ['a cancellation sent to the failed first run', state => {
    state.try_cancelling.parent_events.find(event => event.event_type === 'ChildCancellationRequested').child_workflow_run_id =
      state.try_cancelling.child_runs[0].run_id; }],
  ['an unexpected case', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.retried_child_cancellation);
  assert.throws(() => checkRetriedChildCancellation(fixture, raw));
});
