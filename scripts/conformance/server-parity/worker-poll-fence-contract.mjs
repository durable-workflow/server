import assert from 'node:assert/strict';

// Reviewed worker poll fences (`WorkerPollFence` in WorkflowTaskPoller and
// ActivityTaskPoller), recorded against published Server 2.11.1. A held poll
// belongs to the registration it started under. Each case has its own worker
// and queue:
// - work reaching a held poll under an unchanged registration is leased;
// - a worker heartbeat or a same-ID re-registration while it waits ends it
//   with 200, task null and poll_status stale_worker_registration, and the
//   worker's next poll leases that work;
// - a worker that reports its process keeps its held poll across a heartbeat
//   from the same process, and loses it to a re-registration from a new one;
// - a re-registration hands the worker's leased workflow task out again at
//   the next attempt, and the old attempt's completion is refused.
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
  equal([state.activity_control_scheduled, state.activity_heartbeat_scheduled, state.activity_replaced_scheduled],
    [200, 200, 200], 'each activity scheduled');
  equal(state.process_heartbeat, 200, 'process heartbeat accepted');
  equal(state.process_after_heartbeat, leased(), 'a heartbeat from the same process keeps the held poll');
  equal(state.process_after_replacement, stale, 'a re-registration from a new process ends the held poll as stale');
  equal(state.process_next_after_replacement, leased(), 'the new process leases the work');
  equal(state.recovery_leased, leased(), 'a workflow task leased before the re-registration');
  equal(state.recovery_after_registration, leased(2), 'the re-registration hands it out again at the next attempt');
  equal(state.recovery_old_completion, {status: 409, reason: 'workflow_task_attempt_mismatch'},
    'the old attempt cannot complete it');
  return projection;
}
