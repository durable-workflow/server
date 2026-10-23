import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkActivityLeaseRenewal} from '../../scripts/conformance/server-parity/activity-lease-renewal-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/activity-lease-renewal.json', import.meta.url)));
const definition = fixture.activity_lease_renewal;
const id = 'parity-v1-activity-lease-renewal';

// Shapes published Server 2.11.1 returned.
const claim = (suffix, n) => ({workflow_id: `${id}-${suffix}`, run_id: `run-${n}`, workflow_task_id: `wft-${n}`,
  task_id: `task-${n}`, activity_attempt_id: `ATTEMPT-${n}`, activity_execution_id: `exec-${n}`, lease_owner: `${id}-${suffix}-worker`});
const live = claim('live', 1);
const cancelled = claim('cancelled', 2);
const terminated = claim('terminated', 3);
const status = (claimed, fields = {}) => ({can_continue: true, cancel_requested: false, reason: null, heartbeat_recorded: false,
  workflow_instance_id: claimed.workflow_id, workflow_run_id: claimed.run_id, workflow_task_id: claimed.task_id,
  activity_execution_id: claimed.activity_execution_id, activity_attempt_id: claimed.activity_attempt_id, attempt_number: 1,
  run_status: 'waiting', run_closed_reason: null, run_closed_at: null, activity_status: 'running', attempt_status: 'running',
  task_status: 'leased', lease_owner: claimed.lease_owner, lease_expires_at: '2026-10-22T20:52:13.554963Z', last_heartbeat_at: null,
  heartbeat_deadline_at: '2026-10-22T20:48:11.939526Z', ...fields});
const reply = (code, response) => ({status: code, response: {...response, protocol_version: '1.20'}, lease_renewal_capability: true});
const renewed = (fields = {}) => reply(200, {...status(live), lease_renewed: true, server_time: '2026-10-22T20:47:13.592509Z',
  task_id: live.task_id, worker_session: null, ...fields});
const refusal = (code, task, attempt, error, reason, extra = {}) => reply(code, {task_id: task, activity_attempt_id: attempt, error, reason, ...extra});
const stopped = (claimed, runStatus, reason) => reply(409, {...status(claimed, {can_continue: false, cancel_requested: true, reason,
  run_status: runStatus, run_closed_reason: runStatus, run_closed_at: '2026-10-22T20:48:18.603147Z', activity_status: 'cancelled',
  attempt_status: 'cancelled', task_status: 'cancelled', lease_expires_at: null}), lease_renewed: false});
const history = ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled', 'ActivityStarted'];
const observation = () => structuredClone({mode: 'http', activity_lease_renewal: {applicable: true, namespace: `${id}-lease`, live,
  status_before: reply(200, {task_id: live.task_id, can_continue: true, lease_expires_at: '2026-10-22T20:52:11.939673Z',
    last_heartbeat_at: null, deadlines: {start_to_close: '2026-10-22T20:52:11.939526Z', heartbeat: '2026-10-22T20:48:11.939526Z'}}),
  history_before: [...history], renewed: renewed(),
  status_after: reply(200, {task_id: live.task_id, can_continue: true, lease_expires_at: '2026-10-22T20:52:13.554963Z', last_heartbeat_at: null}),
  history_after: [...history],
  renewed_on_protocol_1_19: renewed(), extra_field: renewed(),
  foreign_owner: refusal(409, live.task_id, live.activity_attempt_id, 'Activity attempt lease is owned by another worker.',
    'lease_owner_mismatch', {lease_owner: live.lease_owner}),
  unknown_attempt: refusal(404, live.task_id, 'no-such-attempt', 'Activity attempt not found.', 'attempt_not_found'),
  unknown_task: refusal(404, 'no-such-task', live.activity_attempt_id, 'Activity task not found.', 'task_not_found'),
  task_mismatch: refusal(409, live.workflow_task_id, live.activity_attempt_id, 'Activity attempt is not leased for this task.', 'task_mismatch'),
  missing_fields: reply(422, definition.validation.missing_fields),
  non_string_fields: reply(422, definition.validation.non_string_fields),
  heartbeat: reply(200, {task_id: live.task_id, heartbeat_recorded: true, last_heartbeat_at: '2026-10-22T20:47:14.723462Z'}),
  renewed_after_heartbeat: renewed({last_heartbeat_at: '2026-10-22T20:47:14.723462Z', heartbeat_deadline_at: '2026-10-22T20:48:14.723462Z'}),
  completed: reply(200, {outcome: 'completed', recorded: true}),
  renewed_after_completion: reply(409, {...status(live, {can_continue: false, reason: 'attempt_closed', activity_status: 'completed',
    attempt_status: 'completed', task_status: 'completed', lease_expires_at: null, last_heartbeat_at: '2026-10-22T20:47:14.723462Z',
    heartbeat_deadline_at: '2026-10-22T20:48:14.723462Z'}), lease_renewed: false}),
  cancelled_claim: cancelled, cancel: 200, renewed_after_cancel: stopped(cancelled, 'cancelled', 'run_cancelled'),
  terminated_claim: terminated, terminate: 200, renewed_after_terminate: stopped(terminated, 'terminated', 'run_terminated')}});

test('lease renewal accepts the published shapes', () => {
  assert.deepStrictEqual(checkActivityLeaseRenewal(fixture, observation(), id), {scope: 'activity_lease_renewal', lease_seconds: 300});
});

test('lease renewal accepts a native lease exactly 300 s after its millisecond renewal', () => {
  const state = observation();
  const renewal = state.activity_lease_renewal.renewed.response;
  renewal.server_time = '2026-10-22T20:47:13.592000Z';
  renewal.lease_expires_at = '2026-10-22T20:52:13.592000Z';
  state.activity_lease_renewal.status_after.response.lease_expires_at = '2026-10-22T20:52:13.592Z';
  assert.doesNotThrow(() => checkActivityLeaseRenewal(fixture, state, id));
});

test('embedded lease renewal is explicitly inapplicable', () => {
  const projection = checkActivityLeaseRenewal(fixture,
    {mode: 'embedded', activity_lease_renewal: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}, id);
  assert.deepStrictEqual(projection, {scope: 'activity_lease_renewal', lease_seconds: 300});
  assert.throws(() => checkActivityLeaseRenewal(fixture, {mode: 'embedded', activity_lease_renewal: {applicable: true}}, id));
});

for (const [label, mutate] of [
  ['an unadvertised capability', state => { state.renewed.lease_renewal_capability = null; }],
  ['an older protocol version', state => { state.foreign_owner.response.protocol_version = '1.19'; }],
  ['a refused live renewal', state => { state.renewed.status = 409; }],
  ['a recorded heartbeat', state => { state.renewed.response.heartbeat_recorded = true; }],
  ['a missing server time', state => { delete state.renewed.response.server_time; }],
  ['a millisecond lease', state => { state.renewed.response.lease_expires_at = '2026-10-22T20:52:13.554Z'; }],
  ['a lease shorter than the window', state => { state.renewed.response.lease_expires_at = '2026-10-22T20:51:13.554963Z'; }],
  ['a lease longer than the window', state => { state.renewed.response.lease_expires_at = '2026-10-22T20:52:14.554963Z'; }],
  ['a moved progress deadline', state => { state.renewed.response.heartbeat_deadline_at = '2026-10-22T20:48:13.592509Z'; }],
  ['added history', state => { state.history_after.push('ActivityHeartbeatRecorded'); }],
  ['an unrenewed status read', state => { state.status_after.response.lease_expires_at = '2026-10-22T20:52:11.939673Z'; }],
  ['a session object', state => { state.renewed.response.worker_session = {}; }],
  ['a refused 1.19 request', state => { state.renewed_on_protocol_1_19.status = 422; }],
  ['a refused unruled field', state => { state.extra_field.status = 422; }],
  ['a foreign owner accepted', state => { state.foreign_owner.status = 200; }],
  ['a foreign owner without its owner', state => { delete state.foreign_owner.response.lease_owner; }],
  ['an unknown attempt as 409', state => { state.unknown_attempt.status = 409; }],
  ['an unknown task message', state => { state.unknown_task.response.error = 'Task not found.'; }],
  ['a task mismatch as 404', state => { state.task_mismatch.status = 404; }],
  ['a generic validation refusal', state => { state.missing_fields.response = {reason: 'invalid_request', protocol_version: '1.20'}; }],
  ['a lost heartbeat', state => { state.renewed_after_heartbeat.response.last_heartbeat_at = null; }],
  ['a heartbeat deadline renewed', state => { state.renewed_after_heartbeat.response.heartbeat_deadline_at = '2026-10-22T20:48:15.000000Z'; }],
  ['a renewed closed attempt', state => { state.renewed_after_completion.status = 200; }],
  ['a closed attempt keeping its lease', state => { state.renewed_after_completion.response.lease_expires_at = '2026-10-22T20:52:14.958720Z'; }],
  ['a closed attempt with a server time', state => { state.renewed_after_completion.response.server_time = '2026-10-22T20:47:14.987586Z'; }],
  ['a closed attempt with a task id', state => { state.renewed_after_completion.response.task_id = live.task_id; }],
  ['a cancellation not requested', state => { state.renewed_after_cancel.response.cancel_requested = false; }],
  ['a cancelled attempt keeping its lease', state => { state.renewed_after_cancel.response.lease_expires_at = '2026-10-22T20:52:16.801401Z'; }],
  ['a terminated run as cancelled', state => { state.renewed_after_terminate.response.reason = 'run_cancelled'; }],
  ['a millisecond close time', state => { state.renewed_after_terminate.response.run_closed_at = '2026-10-22T20:48:18.603Z'; }],
]) {
  test(`lease renewal rejects ${label}`, () => {
    const value = observation();
    mutate(value.activity_lease_renewal);
    assert.throws(() => checkActivityLeaseRenewal(fixture, value, id));
  });
}
