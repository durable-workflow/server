import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkWorkerSessionActivities} from '../../scripts/conformance/server-parity/worker-session-activities-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/worker-session-activities.json', import.meta.url)));
// The observation published Server 2.11.1 returned for this fixture.
const recorded = JSON.parse(readFileSync(
  new URL('../Fixtures/ServerParityExpected/worker-session-activities-observation.json', import.meta.url)));
const id = 'pr1-worker-session-activities';
const observation = (change = () => {}) => {
  const state = structuredClone(recorded);
  change(state);
  return {mode: 'http', worker_session_activities: state};
};
const check = state => checkWorkerSessionActivities(fixture, state, id);

test('the published 2.11.1 recording satisfies the contract', () => {
  assert.deepStrictEqual(check(observation()), {scope: 'worker_session_activities'});
});

test('embedded applications are explicitly inapplicable', () => {
  assert.deepStrictEqual(check({mode: 'embedded', worker_session_activities:
    {applicable: false, reason: 'embedded_has_no_worker_protocol'}}), {scope: 'worker_session_activities'});
});

for (const [label, change, message] of [
  // Native before this fixture: worker_session refused as an unknown field,
  // sessions advertised as a bare false, and metrics fixed at zero.
  ['worker_session refused as an unknown command field', state => {
    state.complete_accepted = 422;
  }, /worker session activities complete_accepted/],
  ['a claim that ignores the session requirements', state => {
    state.poll_without_requirement.poll_status = 'leased';
  }, /worker session activities poll_without_requirement/],
  ['a task without its session', state => {
    state.poll_capable.worker_session = null;
    state.poll_capable.has_session_key = false;
  }, /worker session activities poll_capable/],
  ['a heartbeat that does not renew the session', state => {
    state.activity_heartbeat.worker_session = null;
  }, /worker session activities activity_heartbeat/],
  ['a heartbeat recorded after the session closed', state => {
    state.heartbeat_after_close.status = 200;
    state.heartbeat_after_close.heartbeat_recorded = true;
  }, /worker session activities heartbeat_after_close/],
  ['a session limit that is not enforced', state => {
    state.limit_one = ['leased', 'leased'];
  }, /worker session activities limit_one/],
  ['sessions advertised as a bare flag', state => {
    state.capabilities.worker_sessions = false;
    state.capabilities.worker_session_verbs = null;
  }, /worker session activities capabilities/],
  ['metrics fixed at zero', state => {
    state.operator_metrics.worker_sessions = {total: 0, active: 0, closed: 0, expired: 0, failed: 0, orphaned: 0};
  }, /worker session activities operator_metrics/],
]) {
  test(`the contract refuses ${label}`, () => {
    assert.throws(() => check(observation(change)), message);
  });
}
