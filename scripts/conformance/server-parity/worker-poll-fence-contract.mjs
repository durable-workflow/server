import assert from 'node:assert/strict';

// Reviewed worker poll fences (`WorkerPollFence` in WorkflowTaskPoller and
// ActivityTaskPoller), recorded against published Server 2.11.1 (the stale-owner
// claim against 2.11.5). A held poll
// belongs to the registration it started under. Each case has its own worker
// and queue:
// - work reaching a held poll under an unchanged registration is leased;
// - a worker heartbeat or a same-ID re-registration while it waits ends it
//   with 200, task null and poll_status stale_worker_registration, and the
//   worker's next poll leases that work;
// - a worker that reports its process keeps its held poll across a heartbeat
//   from the same process, and loses it to a re-registration from a new one;
// - a re-registration hands the worker's leased workflow task out again at
//   the next attempt, and the old attempt's completion is refused;
// - a held workflow poll whose registration is removed ends stale too;
// - a held query poll ends the same way but answers empty, with or without
//   a request ID, and the next poll leases the query, which is answered.
// - a workflow task claim whose owner has not reported for longer than the
//   stale window (WorkerController's ownership guard, recorded against
//   published 2.11.5) is refused on heartbeat, history, scope checkpoint,
//   fail and complete with 409 stale_worker_registration, the claim's task,
//   attempt, owner and lease; another worker's poll on its queue recovers it
//   at the next attempt, and the old claim's completion is then refused
//   lease_owner_mismatch. Whether the stale owner is fenced as superseded is
//   not recorded: published PHP fences it when a poll recovers the abandoned
//   lease, but not always under its maintenance loop (1 of 4 recordings).
// Registration changes are more than a second apart: PHP keeps registration
// times in whole seconds.
export function checkWorkerPollFence(fixture, observation, workflowId) {
  const state = observation.worker_poll_fence;
  const projection = {scope: 'worker_poll_fence'};
  assert.ok(state && typeof state === 'object', 'actual worker poll fence observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'},
      'embedded worker poll fences explicitly inapplicable');
    return projection;
  }
  const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
  equal([state.applicable, state.namespace], [true, `${workflowId}-wpf`.toLowerCase()], 'worker poll fence namespace');
  const leased = (attempt = 1) => ({status: 200, poll_status: 'leased', own_run: true, attempt, reason: null});
  const stale = {status: 200, poll_status: 'stale_worker_registration', own_run: null, attempt: null, reason: null};
  for (const kind of ['workflow', 'activity']) {
    equal(state[`${kind}_control`], leased(), `${kind}: work reaching a held poll under an unchanged registration is leased`);
    equal(state[`${kind}_heartbeat`], 200, `${kind}: worker heartbeat accepted`);
    equal(state[`${kind}_after_heartbeat`], stale, `${kind}: a heartbeat ends the held poll as stale`);
    equal(state[`${kind}_next_after_heartbeat`], leased(), `${kind}: the next poll after the heartbeat leases the work`);
    equal(state[`${kind}_after_replacement`], stale, `${kind}: a same-ID re-registration ends the held poll as stale`);
    equal(state[`${kind}_next_after_replacement`], leased(), `${kind}: the replacement leases the work`);
  }
  equal([state.workflow_removed, state.workflow_after_removal], [200, stale],
    'workflow: a registration removed while a poll waits ends it as stale, not refused');
  equal([state.activity_control_scheduled, state.activity_heartbeat_scheduled, state.activity_replaced_scheduled],
    [200, 200, 200], 'each activity scheduled');
  equal(state.process_heartbeat, 200, 'process heartbeat accepted');
  equal(state.process_after_heartbeat, leased(), 'a heartbeat from the same process keeps the held poll');
  equal(state.process_after_replacement, stale, 'a re-registration from a new process ends the held poll as stale');
  equal(state.process_next_after_replacement, leased(), 'the new process leases the work');
  const empty = {status: 200, poll_status: 'empty', own_run: null, attempt: null, reason: null};
  equal([state.query_control_held, state.query_control_answered, state.query_control_client], [leased(), 200, 200],
    'query: a query reaching a held poll under an unchanged registration is leased and answered');
  for (const change of ['heartbeat', 'replaced', 'heartbeat-id', 'replaced-id']) {
    if (change.startsWith('heartbeat')) equal(state[`query_${change}_heartbeat`], 200, `query ${change}: worker heartbeat accepted`);
    equal(state[`query_${change}_held`], empty, `query ${change}: the held query poll ends empty`);
    equal(state[`query_${change}_next`], leased(), `query ${change}: the next query poll leases the query`);
    equal([state[`query_${change}_answered`], state[`query_${change}_client`]], [200, 200], `query ${change}: the query is answered`);
  }
  equal(state.recovery_leased, leased(), 'a workflow task leased before the re-registration');
  equal(state.recovery_after_registration, leased(2), 'the re-registration hands it out again at the next attempt');
  equal(state.recovery_old_completion, {status: 409, reason: 'workflow_task_attempt_mismatch'},
    'the old attempt cannot complete it');
  equal(state.stale_owner_leased, leased(), 'the stale owner leases its workflow task');
  const staleOwner = {status: 409, reason: 'stale_worker_registration',
    error: 'Workflow task lease owner is no longer an active worker.', task_status: 'leased',
    keys: ['error', 'lease_expires_at', 'lease_owner', 'protocol_version', 'reason', 'task_id', 'task_status', 'workflow_task_attempt'],
    own_task: true, own_attempt: true, own_owner: true, lease_expires_at: 'present'};
  for (const route of ['heartbeat', 'history', 'scope_checkpoint', 'fail', 'complete']) {
    equal(state[`stale_owner_${route}`], staleOwner, `a stale owner's claim is refused on ${route}`);
  }
  equal(state.stale_owner_recovered, leased(2), "another worker's poll recovers the stale owner's task at the next attempt");
  equal(state.stale_owner_complete_after_recovery, {status: 409, reason: 'lease_owner_mismatch'},
    "the stale owner's old claim cannot complete the recovered task");
  return projection;
}
