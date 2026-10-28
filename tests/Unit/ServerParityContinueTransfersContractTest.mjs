import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkContinueTransfers} from '../../scripts/conformance/server-parity/continue-transfers-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/continue-transfers.json', import.meta.url), 'utf8'));
const expected = fixture.continue_transfers.expected;
const observation = () => structuredClone({mode: 'http', continue_transfers: {applicable: true, ...expected}});

test('the published answers pass', () => {
  assert.deepStrictEqual(checkContinueTransfers(fixture, observation()), {scope: 'continue_transfers', runs: 4});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkContinueTransfers(fixture,
    {mode: 'embedded', continue_transfers: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}), {scope: 'continue_transfers', runs: 4});
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
  assert.equal(expected.service_call.linked_after.linked_workflow_run_id, expected.service_call.runs[0].run_id);
  assert.equal(expected.live_query.admitted, 'workflow_task_leased');
});

test('the fixture closes a run with a live query and a continued child under a policy', () => {
  const closed = expected.closed_query;
  assert.deepStrictEqual([closed.admitted, closed.terminated, closed.answered, closed.client.status],
    ['workflow_task_leased', 200, 200, 200]);
  assert.equal(closed.query_task.run_id, closed.runs[0].run_id);
  assert.equal(closed.runs[0].status, 'terminated');
  const policy = expected.policy_child;
  assert.equal(policy.terminated, 200);
  assert.deepStrictEqual(policy.policy_events.map(event => [event.event_type, event.child_run_id]),
    [['ParentClosePolicyApplied', policy.child_runs[1].run_id]]);
  assert.deepStrictEqual(policy.child_runs.map(run => [run.run_number, run.status]), [[1, 'completed'], [2, 'terminated']]);
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
  ['a linked call moved to the successor', state => {
    state.service_call.linked_after.linked_workflow_run_id = state.service_call.runs[1].run_id;
  }],
  ['a query never admitted behind the lease', state => { state.live_query.admitted = 'empty'; }],
  ['a refused close with a live query', state => {
    state.closed_query.terminated = 422; state.closed_query.runs[0].status = 'pending';
  }],
  ['a query failed at close', state => { state.closed_query.client.status = 409; }],
  ['a closed run whose query is never leased', state => {
    state.closed_query.query_task.leased = false; state.closed_query.answered = null;
  }],
  ['a policy that cannot close a continued child', state => {
    state.policy_child.policy_events[0].event_type = 'ParentClosePolicyFailed';
    state.policy_child.policy_events[0].error = 'termination_propagation_not_available';
    state.policy_child.child_runs[1].status = 'pending';
  }],
  ['a policy applied to the child\'s predecessor', state => {
    state.policy_child.policy_events[0].child_run_id = state.policy_child.child_runs[0].run_id;
  }],
  ['an unexpected step', state => { state.service_call.extra = true; }],
  ['an unexpected case', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.continue_transfers);
  assert.throws(() => checkContinueTransfers(fixture, raw));
});
