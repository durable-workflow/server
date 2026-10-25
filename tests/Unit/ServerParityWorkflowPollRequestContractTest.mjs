import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkWorkflowPollRequest} from '../../scripts/conformance/server-parity/workflow-poll-request-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/workflow-poll-request-replay.json', import.meta.url)));
// The observation published Server 2.11.1 returned for this fixture.
const recorded = JSON.parse(readFileSync(
  new URL('../Fixtures/ServerParityExpected/workflow-poll-request-observation.json', import.meta.url)));
const id = 'pr1-workflow-poll-request-replay';
const observation = (change = () => {}) => {
  const state = structuredClone(recorded);
  change(state);
  return {mode: 'http', workflow_poll_request: state};
};
const check = state => checkWorkflowPollRequest(fixture, state, id);

test('the published 2.11.1 recording satisfies the contract', () => {
  assert.deepStrictEqual(check(observation()), {scope: 'workflow_poll_request'});
});

test('embedded applications are explicitly inapplicable', () => {
  assert.deepStrictEqual(check({mode: 'embedded', workflow_poll_request:
    {applicable: false, reason: 'embedded_has_no_worker_protocol'}}), {scope: 'workflow_poll_request'});
});

for (const [label, change, message] of [
  // The divergences native 2.11.1-era code showed before this fixture.
  ['an empty poll id that leases on retry', state => {
    state.empty_replay = structuredClone(state.leased);
  }, /its id replays empty after work arrived/],
  ['an expired-cache refusal once nothing is ready', state => {
    state.after_drain = {...state.after_drain, status: 409, poll_status: null, reason: 'poll_cached_task_expired'};
  }, /with nothing ready the id answers empty/],
  ['a replay that keeps the pre-heartbeat lease', state => {
    state.heartbeat_replay.lease_expires_at = state.leased.lease_expires_at;
  }, /the replay shows the renewed lease/],
  ['a padded id treated as another id', state => {
    state.padded_replay = {...state.empty};
  }, /a padded id is the same id/],
  ['a completed delivery that replays the closed task', state => {
    state.after_completion = structuredClone(state.leased);
  }, /after_completion: a lease|leases the next task/],
  ['a lease without microseconds', state => {
    state.leased.lease_expires_at = '2026-10-24T08:56:03.268Z';
    state.leased_replay.lease_expires_at = '2026-10-24T08:56:03.268Z';
  }, /lease in UTC microseconds/],
]) {
  test(`the contract refuses ${label}`, () => {
    assert.throws(() => check(observation(change)), message);
  });
}
