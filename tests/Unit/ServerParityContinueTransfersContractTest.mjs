import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkContinueTransfers} from '../../scripts/conformance/server-parity/continue-transfers-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/continue-transfers.json', import.meta.url), 'utf8'));
const expected = fixture.continue_transfers.expected;
const observation = () => structuredClone({mode: 'http', continue_transfers: {applicable: true, ...expected}});

test('the published answers pass', () => {
  assert.deepStrictEqual(checkContinueTransfers(fixture, observation()), {scope: 'continue_transfers', runs: 2});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkContinueTransfers(fixture,
    {mode: 'embedded', continue_transfers: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}), {scope: 'continue_transfers', runs: 2});
  assert.throws(() => checkContinueTransfers(fixture, {mode: 'embedded', continue_transfers: {applicable: true}}));
});

test('the fixture continues both runs and leaves the call and the query with the predecessor', () => {
  for (const run of ['service_call', 'live_query']) {
    assert.deepStrictEqual([expected[run].continued.status, expected[run].continued.outcome], [200, 'completed']);
    assert.deepStrictEqual(expected[run].runs.map(entry => [entry.run_number, entry.is_current_run]), [[1, false], [2, true]]);
  }
  assert.equal(expected.service_call.call_after.caller_workflow_run_id, expected.service_call.runs[0].run_id);
  assert.equal(expected.service_call.call_after.call_status, 'accepted');
  assert.equal(expected.live_query.query_task.run_id, expected.live_query.runs[0].run_id);
  assert.equal(expected.live_query.client.status, 200);
});

for (const [name, mutate] of [
  ['a refused service-call continuation', state => {
    state.service_call.continued = {status: 409, outcome: null, reason: 'continue_as_new_transfer_unqualified', run_status: null, created_tasks: 0};
  }],
  ['a refused live-query continuation', state => {
    state.live_query.continued = {status: 409, outcome: null, reason: 'continue_as_new_transfer_unqualified', run_status: null, created_tasks: 0};
  }],
  ['a call moved to the successor', state => { state.service_call.call_after.caller_workflow_run_id = state.service_call.runs[1].run_id; }],
  ['a call cancelled at continuation', state => { state.service_call.call_after.call_status = 'cancelled'; }],
  ['no successor run', state => { state.service_call.runs.pop(); }],
  ['the predecessor still current', state => { state.service_call.runs[0].is_current_run = true; }],
  ['a query retargeted to the successor', state => { state.live_query.query_task.run_id = state.live_query.runs[1].run_id; }],
  ['a query never leased', state => { state.live_query.query_task.leased = false; state.live_query.answered = null; }],
  ['a query failed at continuation', state => { state.live_query.client.status = 409; }],
  ['a query answer lost', state => { state.live_query.client.result_envelope = null; }],
  ['a successor task at another attempt', state => { state.live_query.successor.workflow_task_attempt = 2; }],
  ['an unexpected step', state => { state.service_call.extra = true; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.continue_transfers);
  assert.throws(() => checkContinueTransfers(fixture, raw));
});
