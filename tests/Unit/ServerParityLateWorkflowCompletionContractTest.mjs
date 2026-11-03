import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkLateWorkflowCompletion} from '../../scripts/conformance/server-parity/late-workflow-completion-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/late-workflow-completion.json', import.meta.url)));
const worker = 'test-late-worker';
function model() {
  const protocol = {protocol_version: '1.20', server_capabilities: {supported_workflow_task_commands: []}};
  const claims = task => [1, 2].map(attempt => ({task_id: task, workflow_task_attempt: attempt, lease_owner: worker}));
  const refusal = response => ({status: 409, response: {...protocol, task_id: 'task-1', workflow_task_attempt: 1, ...response}});
  const mismatch = refusal({error: 'Workflow task lease attempt does not match the current claim.',
    reason: 'workflow_task_attempt_mismatch', current_attempt: 2});
  const notLeased = refusal({error: 'Workflow task is not currently leased.', reason: 'task_not_leased', task_status: 'completed'});
  const events = fixture.late_workflow_completion.expected_events.map(event_type => ({event_type}));
  return structuredClone({mode: 'http', late_workflow_completion: {applicable: true, implementation: null, worker_id: worker,
    task_queue: 'test-late', peer_workflow_id: 'test-late-peer', peer_run_id: 'peer-run',
    first_claims: claims('task-1'), while_leased: mismatch, heartbeat_while_leased: mismatch,
    scheduled: {recorded: true}, after_completion: notLeased, heartbeat_after_completion: notLeased,
    failure_after_completion: notLeased, history_while_open: events.slice(0, 3), last_claims: claims('task-2'),
    closed: {recorded: true}, after_close: {status: 409, response: {...protocol, task_id: 'task-2', workflow_task_attempt: 1,
      error: 'Workflow run is already closed.', reason: 'run_closed', stop_reason: 'run_completed', cancel_requested: false,
      can_continue: false, run_status: 'completed', run_closed_reason: 'completed',
      run_closed_at: '2026-10-20T17:33:21.305246Z', task_status: 'completed', lease_owner: worker, lease_expires_at: null}},
    history: events}});
}
// Native's bodies: the published ones plus the not-recorded receipt on each
// refused report (server#994). Renewal and attempt-mismatch bodies are unchanged.
function nativeModel() {
  const observation = model();
  const state = observation.late_workflow_completion;
  state.implementation = 'rust';
  for (const key of ['after_completion', 'failure_after_completion', 'after_close']) {
    Object.assign(state[key].response, {recorded: false, run_id: state.peer_run_id});
  }
  state.heartbeat_after_completion = structuredClone(model().late_workflow_completion.heartbeat_after_completion);
  return observation;
}
test('native lost-claim refusals with the not-recorded receipt pass', () =>
  assert.equal(checkLateWorkflowCompletion(fixture, nativeModel(), 'test').scope, 'late_workflow_completion'));
for (const [label, build, mutate] of [
  ['published PHP refusals carrying native receipt fields', model,
    s => Object.assign(s.after_completion.response, {recorded: false, run_id: s.peer_run_id})],
  ['published PHP closed-run refusal carrying recorded', model, s => s.after_close.response.recorded = false],
  ['native refusals without the receipt (3.0.4 bodies)', model, s => s.implementation = 'rust'],
  ['native completion refusal without run_id', nativeModel, s => delete s.after_completion.response.run_id],
  ['native failure refusal without recorded', nativeModel, s => delete s.failure_after_completion.response.recorded],
  ['native closed-run refusal without the receipt', nativeModel,
    s => { delete s.after_close.response.recorded; delete s.after_close.response.run_id; }],
  ['native receipt that claims a record', nativeModel, s => s.after_completion.response.recorded = true],
  ['native receipt naming another run', nativeModel, s => s.after_close.response.run_id = 'other-run'],
  ['native renewal refusal carrying the receipt', nativeModel,
    s => Object.assign(s.heartbeat_after_completion.response, {recorded: false, run_id: s.peer_run_id})],
  ['native attempt mismatch carrying the receipt', nativeModel,
    s => { Object.assign(s.while_leased.response, {recorded: false, run_id: s.peer_run_id});
      Object.assign(s.heartbeat_while_leased.response, {recorded: false, run_id: s.peer_run_id}); }],
  ['native without an authoritative run id', nativeModel, s => s.peer_run_id = ''],
  // Published bodies under an unrecognized name: only the implementation check refuses them.
  ['an unknown implementation with published bodies', model, s => s.implementation = 'php'],
  ['an unknown implementation with native bodies', nativeModel, s => s.implementation = 'native'],
  ['an implementation the recording did not name', model, s => delete s.implementation],
]) {
  test(`rejects ${label}`, () => {
    const observation = build();
    mutate(observation.late_workflow_completion);
    assert.throws(() => checkLateWorkflowCompletion(fixture, observation, 'test'));
  });
}
test('published lost-claim refusals pass', () =>
  assert.equal(checkLateWorkflowCompletion(fixture, model(), 'test').scope, 'late_workflow_completion'));
test('embedded explicitly declares the HTTP worker lifecycle inapplicable', () => checkLateWorkflowCompletion(fixture,
  {mode: 'embedded', late_workflow_completion: {applicable: false, reason: 'embedded_has_no_http_worker_registration_lifecycle'}}, 'test'));
for (const [label, mutate] of [
  ['late completion accepted while the new claim holds the lease', s => s.while_leased.status = 200],
  ['attempt mismatch without the current attempt', s => delete s.while_leased.response.current_attempt],
  ['renewal refused differently from completion', s => s.heartbeat_while_leased.response.reason = 'lease_owner_mismatch'],
  ['native conflicting completion', s => s.after_completion.response.reason = 'conflicting_completion'],
  ['refusal without the task status a worker reads', s => delete s.after_completion.response.task_status],
  ['bare renewal refusal', s => s.heartbeat_after_completion.response = {reason: 'task_not_leased', message: 'task_not_leased'}],
  ['late failure accepted', s => s.failure_after_completion.status = 200],
  ['refusal without the published error text', s => delete s.failure_after_completion.response.error],
  ['refusal without the worker protocol version', s => delete s.after_completion.response.protocol_version],
  ['late request recorded history', s => s.history_while_open.push({event_type: 'WorkflowTaskFailed'})],
  ['the released task is not claimed again', s => s.first_claims[1].task_id = 'other-task'],
  ['closed run reported as an open task', s => s.after_close.response.reason = 'task_not_leased'],
  ['closed run that can continue', s => s.after_close.response.can_continue = true],
  ['closed task keeps a lease expiry', s => s.after_close.response.lease_expires_at = '2026-10-20T17:34:21.000000Z'],
  ['closed task loses its owner', s => s.after_close.response.lease_owner = null],
  ['closure time missing', s => s.after_close.response.run_closed_at = null],
  ['second completion recorded', s => s.history.push({event_type: 'WorkflowCompleted'})],
]) {
  test(`rejects ${label}`, () => {
    const observation = model();
    mutate(observation.late_workflow_completion);
    assert.throws(() => checkLateWorkflowCompletion(fixture, observation, 'test'));
  });
}
