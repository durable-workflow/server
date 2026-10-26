import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkCancellationScopes} from '../../scripts/conformance/server-parity/cancellation-scopes-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/cancellation-scopes.json', import.meta.url)));
// The observation published Server 2.11.1 returned for this fixture.
const recorded = JSON.parse(readFileSync(
  new URL('../Fixtures/ServerParityExpected/cancellation-scopes-observation.json', import.meta.url)));
const id = 'pr1-cancellation-scopes';
const observation = (change = () => {}, base = recorded) => {
  const state = structuredClone(base);
  change(state);
  return {mode: 'http', cancellation_scopes: state};
};
const check = state => checkCancellationScopes(fixture, state, id);
// The intended answers: a root scope refused with the delivery route's 409.
const intended = JSON.parse(JSON.stringify(fixture.cancellation_scopes.expected).replaceAll('{id}', id));
const event = (history, type) => history.find(item => item.event_type === type);

test('the published 2.11.1 recording satisfies the contract', () => {
  assert.deepStrictEqual(check(observation()), {scope: 'cancellation_scopes'});
});

test('the intended root scope refusal is accepted too', () => {
  assert.deepStrictEqual(check(observation(() => {}, intended)), {scope: 'cancellation_scopes'});
  assert.equal(intended.prepare_root_scope.status, 409);
  assert.equal(intended.deliver_root_scope.response.reason, 'root_scope_requires_run_cancellation');
});

test('embedded applications are explicitly inapplicable', () => {
  assert.deepStrictEqual(check({mode: 'embedded', cancellation_scopes:
    {applicable: false, reason: 'embedded_has_no_worker_protocol'}}), {scope: 'cancellation_scopes'});
});

for (const [label, change, message] of [
  // Native answered 501 for the scope routes before this fixture.
  ['an unimplemented route', state => {
    state.open_s1 = {status: 501, response: {reason: 'rust_capability_not_implemented'}};
  }, /cancellation scopes open_s1/],
  ['only part of the published root scope defect', state => {
    state.prepare_root_scope = structuredClone(intended.prepare_root_scope);
  }, /cancellation scopes deliver_root_scope/],
  ['a root scope refused with another reason', state => {
    for (const step of ['prepare_root_scope', 'deliver_root_scope']) {
      state[step] = structuredClone(intended[step]);
      state[step].response.reason = 'cancellation_scope_not_recorded';
    }
  }, /cancellation scopes prepare_root_scope/],
  ['a replayed open that records a second scope', state => {
    state.open_s1_again.response.duplicate = false;
  }, /cancellation scopes open_s1_again/],
  ['a shielded scope requested with its parent', state => {
    const requested = state.history_after_request.filter(item => item.event_type === 'CancellationScopeRequested');
    requested.push({...structuredClone(requested[0]), payload: {...requested[0].payload, scope_id: '{R1.S3}'}});
    state.history_after_request = [...state.history_after_request.filter(item => item.event_type !== 'CancellationScopeRequested'),
      ...requested];
  }, /cancellation scopes history_after_request/],
  ['a run request that leaves the run-level task instead of the scope task', state => {
    state.claim_after_request.resume_source_kind = 'cancellation_request';
  }, /cancellation scopes claim_after_request/],
  ['a claim lease that is not the cleanup lease', state => {
    state.claim_after_request.lease_ten_seconds_from_poll = false;
  }, /cancellation scopes claim_after_request/],
  ['a delivery that skips the frozen descendant timer', state => {
    state.deliver_s1.response.timer_cancellations = [];
  }, /cancellation scopes deliver_s1/],
  ['a redelivery that records again', state => {
    state.history_after_redelivery.push(structuredClone(event(state.history_after_redelivery, 'CancellationScopeDelivered')));
  }, /cancellation scopes history_after_redelivery/],
  ['an activity fence recorded under the run request', state => {
    event(state.history_after_delivery, 'ActivityCancelled').workflow_command_id = '{R1.request}';
  }, /cancellation scopes history_after_delivery/],
  ['a scope fence acknowledged by the run request', state => {
    state.acknowledge_with_run_request = structuredClone(state.acknowledge_with_scope_request);
  }, /cancellation scopes acknowledge_with_run_request/],
  ['a new member admitted into a prepared scope', state => {
    state.complete_in_prepared_scope = structuredClone(state.complete_in_shielded_scope);
  }, /cancellation scopes complete_in_prepared_scope/],
  ['a shield that does not stop the prepared walk', state => {
    state.complete_in_shielded_scope = structuredClone(state.complete_in_prepared_descendant);
  }, /cancellation scopes complete_in_shielded_scope/],
  ['a parent delivered past its pending descendant', state => {
    state.r2_deliver_s1_pending = structuredClone(state.r2_deliver_s1);
  }, /cancellation scopes r2_deliver_s1_pending/],
  ['a root command at a reserved boundary', state => {
    state.r2_complete_at_reserved_boundary.status = 200;
  }, /cancellation scopes r2_complete_at_reserved_boundary/],
  ['a delivered boundary that does not consume its sequence', state => {
    state.r2_history.at(-1).payload.sequence = 5;
  }, /cancellation scopes r2_history/],
  ['a capability-less claim that opens a scope', state => {
    state.plain_claim_open = structuredClone(state.open_s1);
  }, /cancellation scopes plain_claim_open/],
  ['a missing step', state => {
    delete state.prepare_shielded_scope;
  }, /cancellation scopes prepare_shielded_scope/],
  ['an extra step', state => {
    state.extra = {status: 200};
  }, /cancellation scopes steps/],
]) {
  test(`the contract refuses ${label}`, () => {
    assert.throws(() => check(observation(change)), message);
  });
}
