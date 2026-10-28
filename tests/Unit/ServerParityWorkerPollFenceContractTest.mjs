import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkWorkerPollFence} from '../../scripts/conformance/server-parity/worker-poll-fence-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/worker-poll-fence.json', import.meta.url)));
// The observation published Server 2.11.1 returned for this fixture, with the
// stale-owner claim steps published 2.11.5 returned.
const recorded = JSON.parse(readFileSync(
  new URL('../Fixtures/ServerParityExpected/worker-poll-fence-observation.json', import.meta.url)));
const id = 'pr1-worker-poll-fence';
const observation = (change = () => {}) => {
  const state = structuredClone(recorded);
  change(state);
  return {mode: 'http', worker_poll_fence: state};
};
const check = state => checkWorkerPollFence(fixture, state, id);
const leased = (attempt = 1) => ({status: 200, poll_status: 'leased', own_run: true, attempt, reason: null});
const empty = {status: 200, poll_status: 'empty', own_run: null, attempt: null, reason: null};

test('the published 2.11.1 recording satisfies the contract', () => {
  assert.deepStrictEqual(check(observation()), {scope: 'worker_poll_fence'});
});

test('embedded applications are explicitly inapplicable', () => {
  assert.deepStrictEqual(check({mode: 'embedded', worker_poll_fence:
    {applicable: false, reason: 'embedded_has_no_worker_protocol'}}), {scope: 'worker_poll_fence'});
});

for (const [label, change, message] of [
  // Divergences a server without the fence shows.
  ['a held workflow poll that leases after a re-registration', state => {
    state.workflow_after_replacement = leased();
    state.workflow_next_after_replacement = empty;
  }, /workflow: a same-ID re-registration ends the held poll as stale/],
  ['a held activity poll that leases after a re-registration', state => {
    state.activity_after_replacement = leased();
    state.activity_next_after_replacement = empty;
  }, /activity: a same-ID re-registration ends the held poll as stale/],
  ['a held poll that ignores a heartbeat', state => {
    state.workflow_after_heartbeat = leased();
    state.workflow_next_after_heartbeat = empty;
  }, /workflow: a heartbeat ends the held poll as stale/],
  ['a fence that ends a poll on a heartbeat from the same process', state => {
    state.process_after_heartbeat = {...empty, poll_status: 'stale_worker_registration'};
  }, /a heartbeat from the same process keeps the held poll/],
  ['a fence that ignores a new process', state => {
    state.process_after_replacement = leased();
  }, /a re-registration from a new process ends the held poll as stale/],
  ['a re-registration that keeps the old lease', state => {
    state.recovery_after_registration = empty;
    state.recovery_old_completion = {status: 200, reason: null};
  }, /hands it out again at the next attempt/],
  ['an old attempt that completes after the re-registration', state => {
    state.recovery_old_completion = {status: 200, reason: null};
  }, /the old attempt cannot complete it/],
  // Native query polls before this fixture: a heartbeat kept the poll, and
  // a re-registration answered stale instead of empty.
  ['a held query poll that ignores a heartbeat', state => {
    state.query_heartbeat_held = leased();
    state.query_heartbeat_next = empty;
  }, /query heartbeat: the held query poll ends empty/],
  ['a fenced query poll that answers stale', state => {
    state['query_replaced-id_held'] = {...empty, poll_status: 'stale_worker_registration'};
  }, /query replaced-id: the held query poll ends empty/],
  ['a query left unanswered after the fence', state => {
    state.query_replaced_client = 504;
  }, /query replaced: the query is answered/],
  ['a held poll refused when its registration is removed', state => {
    state.workflow_after_removal = {status: 409, poll_status: null, own_run: null, attempt: null, reason: 'worker_not_registered'};
  }, /a registration removed while a poll waits ends it as stale/],
  // Native before the workflow-task ownership guard checked the owner's
  // registration: a stale owner's claim was accepted.
  ['a stale owner whose completion is accepted', state => {
    state.stale_owner_complete = {...state.stale_owner_complete, status: 200, reason: null};
  }, /a stale owner's claim is refused on complete/],
  ['a stale owner whose heartbeat renews the lease', state => {
    state.stale_owner_heartbeat = {...state.stale_owner_heartbeat, status: 200, reason: null, error: null};
  }, /a stale owner's claim is refused on heartbeat/],
  ['a stale owner refusal without the claim', state => {
    state.stale_owner_history = {...state.stale_owner_history, own_task: false};
  }, /a stale owner's claim is refused on history/],
  ['a stale owner refusal in another shape', state => {
    state.stale_owner_fail = {...state.stale_owner_fail, keys: ['reason']};
  }, /a stale owner's claim is refused on fail/],
  ['a scope checkpoint applied for a stale owner', state => {
    state.stale_owner_scope_checkpoint = {...state.stale_owner_scope_checkpoint, status: 200, reason: null};
  }, /a stale owner's claim is refused on scope_checkpoint/],
  ["a stale owner's task left leased to it", state => {
    state.stale_owner_recovered = empty;
  }, /recovers the stale owner's task at the next attempt/],
  ['a stale answer that is not a 200', state => {
    state.activity_after_heartbeat = {...state.activity_after_heartbeat, status: 409};
  }, /activity: a heartbeat ends the held poll as stale/],
]) {
  test(`the contract refuses ${label}`, () => {
    assert.throws(() => check(observation(change)), message);
  });
}
