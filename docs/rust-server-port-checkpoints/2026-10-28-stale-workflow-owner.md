# October 28, a stale owner's workflow task claim

- **Contract.** Published PHP's workflow-task ownership guard
  (`WorkerController::guardWorkflowTaskOwnership`, identical in 2.11.1,
  2.11.5 and current main) ends with the owner's registration. A claim that
  is otherwise valid is refused when the lease owner's registration is
  missing or not `WorkerPollFence::isFresh` (active, and heartbeated within
  `DW_WORKER_STALE_AFTER_SECONDS`, by default max(heartbeat interval × 3, 30)
  seconds):
  - `409` `{task_id, workflow_task_attempt, error: "Workflow task lease owner
    is no longer an active worker.", reason: stale_worker_registration,
    task_status: leased, lease_owner, lease_expires_at}`;
  - on every route that calls the guard: workflow task heartbeat, history,
    fail, complete, and the local-activity, local-activity group and
    cancellation-scope prefix checkpoints.

  Only registration and worker heartbeat refresh `last_heartbeat_at`; polls
  do not.
- **Recovery.** `WorkflowTaskPoller` recovers, before a workflow poll leases,
  the queue's leased tasks whose owner is missing or stale
  (`recoverAbandonedTaskLease`): it fences an existing owner as superseded
  and expires the lease, and the poll leases the task at the next attempt.
  A superseded registration's worker heartbeat is refused
  `409 worker_registration_superseded` (since 2.11.1).
- **Fixture.** `worker-poll-fence` gains a stale-owner case. It leases a
  workflow task first, runs the other cases, and once `stale_wait_seconds`
  (34) have passed since the claim, sends heartbeat, history, scope checkpoint,
  fail and complete with it (all refused as above). It then has another
  worker poll the queue (recovered at attempt 2), completes with the old
  claim (`lease_owner_mismatch`) and heartbeats the old owner
  (`worker_registration_superseded`). The wait overlaps the other cases; the
  probe took about 62 s against published 2.11.5. The probe budget rises from
  150 to 180 s. Recorded against published 2.11.5 on SQLite with its
  maintenance loop running.
- **Native.**
  - `workflow_task_guard` checks the owner's registration last, with the
    configured stale window, on heartbeat, history, fail and complete or
    checkpoint.
  - A workflow poll expires stale owners' leases on its queue, fencing an
    existing owner as superseded, before selecting candidates; native's
    expired-lease path then leases the task at the next attempt.
  - A superseded registration's worker heartbeat is refused as PHP refuses
    it. Native used to accept it and keep the registration superseded.
  - Local activity checkpoints are unchanged: native refuses them before
    reading the task, as PHP without the prepared role does.
- **Still open.** Cooperative cancellation delivery (`cooperative.rs`) judges
  the owner's freshness with a fixed 60 seconds rather than the configured
  stale window.
