import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkActivityCancellationAcknowledgement} from '../../scripts/conformance/server-parity/activity-cancellation-ack-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/activity-cancellation-acknowledgement.json', import.meta.url)));
// The observation published Server 2.11.1 returned for this fixture.
const recorded = JSON.parse(readFileSync(
  new URL('../Fixtures/ServerParityExpected/activity-cancellation-acknowledgement-observation.json', import.meta.url)));
const id = 'pr1-activity-cancellation-acknowledgement';
const projection = {scope: 'activity_cancellation_acknowledgement', cleanup_timeout_seconds: 60};
const observation = (change = () => {}) => {
  const state = structuredClone(recorded);
  change(state);
  return {mode: 'http', activity_cancellation_acknowledgement: state};
};
const check = state => checkActivityCancellationAcknowledgement(fixture, state, id);

test('the published 2.11.1 recording satisfies the contract', () => {
  assert.deepStrictEqual(check(observation()), projection);
});

test('embedded applications are explicitly inapplicable', () => {
  assert.deepStrictEqual(check({mode: 'embedded', activity_cancellation_acknowledgement:
    {applicable: false, reason: 'embedded_has_no_worker_protocol'}}), projection);
});

// The correct retried-attempt outcome (Workflow #923 fixed): the delivery
// cancels the live task and its attempt acknowledges.
const fixedRetry = state => {
  const cancelled = state.stale_history.find(event => event.event_type === 'ActivityCancelled');
  cancelled.payload.task.id = state.stale_second.task_id;
  state.stale_second_attempt.status = 200;
  Object.assign(state.stale_second_attempt.response, {acknowledged: true, reason: null, history_event_id: '01m5pcebxrbwvbd8n7t8ezc0kz'});
};

test('the correct retried-attempt acknowledgement is accepted', () => {
  assert.deepStrictEqual(check(observation(fixedRetry)), projection);
});

for (const [label, change, message] of [
  ['a refused live attempt whose delivery cancelled its own task', state => {
    state.stale_history.find(event => event.event_type === 'ActivityCancelled').payload.task.id = state.stale_second.task_id;
  }, /the live attempt acknowledges/],
  ['the reference defect without its leased live task', state => {
    state.stale_second_status.response.task_status = 'cancelled';
  }, /reference defect: the live task stays leased/],
  ['an acknowledgement that does not advertise the capability', state => {
    state.acknowledged.acknowledgement_capability = false;
  }, /acknowledged answers in worker protocol 1.20 and advertises acknowledgement/],
  ['cluster info without the capability', state => {
    state.discovery.activity_cancellation_acknowledgement = 'absent';
  }, /cluster info advertises/],
  ['a refusal that records history', state => {
    state.history_after_refusals.push(structuredClone(state.history_before_ack.at(-1)));
  }, /refusals record nothing/],
  ['a status receipt before delivery', state => {
    state.status_before_delivery.response.cancellation_acknowledgement = {};
  }, /a pending request does not stop the attempt/],
  ['a receipt that already reads stopped', state => {
    state.status_before_ack.response.cancellation_acknowledgement.callback_state = 'stopped';
  }, /the receipt before acknowledgement/],
  ['a duplicate that records a second event', state => {
    state.duplicate.response.history_event_id = '01m5pce6htfpz4bjzf62naz6cz';
  }, /duplicate/],
  ['an acknowledgement event without its evidence source', state => {
    delete state.history_after_ack.at(-1).payload.evidence_source;
  }, /acknowledgement payload/],
  ['an acknowledgement snapshot that keeps a lease', state => {
    state.history_after_ack.at(-1).payload.task.lease_expires_at = '2026-10-24T07:24:08.525107Z';
  }, /without a lease/],
  ['a 1.19 claim that acknowledges', state => {
    state.legacy_ack = structuredClone(state.acknowledged);
  }, /a 1\.19 claim cannot acknowledge/],
  ['a 1.19 request refused by validation instead', state => {
    state.protocol_1_19 = structuredClone(state.missing_fields);
  }, /a 1\.19 request/],
  ['an immediate cancel with a receipt', state => {
    state.immediate_status.response.cancellation_acknowledgement = {};
  }, /immediate cancel status, no receipt/],
  ['a stale first attempt that acknowledges', state => {
    state.stale_first_attempt_own_task.status = 200;
  }, /stale_first_attempt_own_task/],
  ['a terminated run whose acknowledgement precedes its terminal event', state => {
    state.terminated_history = [...state.terminated_history.slice(0, -3), 'ActivityCancellationAcknowledged',
      'TerminateRequested', 'WorkflowTerminated'];
  }, /follows the terminal event/],
  ['a cleanup budget other than the requested one', state => {
    state.request.response.cancellation_request.cleanup_deadline_at = '2026-10-24T07:21:08.747589Z';
  }, /the cleanup budget runs from the request/],
  // The defect in Server #745's first native head: an activity delivery at
  // an activity that already completed was recorded instead of refused.
  ['a delivery recorded at an activity that resolved before the request', state => {
    state.boundary_resolved_activity = structuredClone(state.boundary_delivery);
    Object.assign(state.boundary_resolved_activity.response, {sequence: 1, call_kind: 'activity'});
  }, /an activity that resolved before the request is not eligible/],
  ['a resolved activity refused as a shape mismatch', state => {
    state.boundary_resolved_activity.response.reason = 'cancellation_delivery_shape_mismatch';
  }, /an activity that resolved before the request is not eligible/],
  ['an activity delivery accepted at a timer', state => {
    state.boundary_timer_as_activity.status = 200;
    state.boundary_timer_as_activity.response.delivered = true;
  }, /an activity delivery at a timer is a shape mismatch/],
  ['a boundary refusal that records history', state => {
    state.boundary_history_after_refusals.push(structuredClone(state.boundary_history_before.at(-1)));
  }, /boundary refusals record nothing/],
  ['a refusal that pins the delivery sequence', state => {
    state.boundary_pending.response.cancellation_request.delivery_sequence = 1;
  }, /the request is still undelivered after the refusals/],
  ['a timer delivery that leaves the timer pending', state => {
    state.boundary_history_after_delivery.splice(-2, 1);
  }, /delivery only appends|the timer is cancelled under the delivery/],
]) {
  test(`the contract refuses ${label}`, () => {
    assert.throws(() => check(observation(change)), message);
  });
}
