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
- **Recovery.** `WorkflowTaskPoller::nextTask` first claims ready work. Only
  when a probe claims nothing does it call `recoverUnavailableLeases`, on
  every such probe, held polls included, and claim again if that recovered
  anything. The scan takes the polled queue's leased workflow tasks whose
  lease has expired or whose owner is not fresh (an SQL `whereNotIn` over the
  fresh owners), ordered by lease expiry, up to
  `server.polling.expired_workflow_task_recovery_scan_limit`
  (`DW_EXPIRED_WORKFLOW_TASK_RECOVERY_SCAN_LIMIT`, default 5).
  - An expired lease goes through `recoverExpiredTaskLease`, which fences no
    one.
  - An unexpired lease whose owner is missing or stale is abandoned
    (`recoverAbandonedTaskLease`). The task and owner are checked again under
    the row lock, an existing owner is fenced as superseded, and the lease is
    expired.
  - Both then `attemptRepair` with trigger `expired_workflow_task_lease`, the
    task, its lease owner and attempt.

  A superseded registration's worker heartbeat is refused
  `409 worker_registration_superseded` (since 2.11.1).
- **Draining workers count as stale (published behavior, kept).**
  `WorkerPollFence::isFresh` requires `status == active`. A worker whose
  build is draining (`draining`, from a build-id rollout) is therefore not
  fresh: its workflow task claims are refused `stale_worker_registration`,
  and another worker's poll recovers its unexpired leases and fences it as
  superseded. Native keeps this parity. Note for the PHP owner: if draining
  workers are meant to finish their in-flight workflow tasks, `isFresh` (or
  the guard and recovery) should accept `draining` too. Settle the contract
  first; native follows.
- **Fixture.** `worker-poll-fence` gains a stale-owner case. It leases a
  workflow task first, runs the other cases, and once `stale_wait_seconds`
  (34) have passed since the claim, sends heartbeat, history, scope checkpoint,
  fail and complete with it (all refused as above). It then has another
  worker's held polls (up to three, 4 s each) recover the queue (leased at
  attempt 2), and the old claim's completion gets `lease_owner_mismatch`.
  The wait overlaps the other cases; the probe took about 62 s against
  published 2.11.5, and its budget rises from 150 to 195 s.
  - Published PHP's poll-side recovery is best effort. In one full-corpus
    recording a zero-wait replacement poll recovered nothing, so the
    replacement now holds its polls and retries.
  - Whether recovery fences the stale owner (its later heartbeat refused
    `worker_registration_superseded`) is not recorded. PHP fences only an
    owner whose lease is still unexpired when recovery runs
    (`recoverAbandonedTaskLease`); an expired lease goes through
    `recoverExpiredTaskLease`, which fences no one. Because the probe's stale
    wait overlaps its other cases, the lease can already have expired by the
    time the replacement polls. Published PHP fenced the owner in 3 of 4
    recordings, and the fourth most likely took the expired-lease branch.
    The recordings did not keep the claim's expiry and the recovery time, so
    this is not attributed to a PHP defect (General's review on #829). Native
    takes the same two branches, and its execution tests cover both. Recorded against published 2.11.5 on SQLite with its
  maintenance loop running.
- **Native.**
  - `workflow_task_guard` checks the owner's registration last, with the
    configured stale window, on heartbeat, history, fail and complete or
    checkpoint.
  - A workflow probe that claims nothing, held probes included, recovers the
    queue's abandoned leases, then claims again if it recovered one.
    - It starts with one read outside the transition lock: the queue's lease
      owners joined to their registrations. A probe with no stale owner stops
      there.
    - Then, per stale owner, its leases by expiry, bounded by the scan limit
      (native now honors `DW_EXPIRED_WORKFLOW_TASK_RECOVERY_SCAN_LIMIT`).
    - Each still-unexpired lease is checked again under the transition lock
      (same owner, attempt and unexpired lease, owner still stale). Its owner
      is fenced as superseded and the task repaired with native's
      `repair_abandoned_task`: a repair command triggered by
      `expired_workflow_task_lease` with the lease owner and attempt, and the
      task's repair count.
    - Expired leases keep native's existing path: the poll's candidates lease
      them again, fencing no one.
  - The guard and the recovery share one freshness rule
    (`worker_poll_fence::owner_fresh`: a missing registration is not fresh).
  - A superseded registration's worker heartbeat is refused as PHP refuses
    it. Native used to accept it and keep the registration superseded.
  - Local activity checkpoints are unchanged: native refuses them before
    reading the task, as PHP without the prepared role does.
- **Review corrections (same day).** The first native version recovered before
  selecting candidates, scanned the first 100 leased tasks by id, ran only
  inside a claim, set only `lease_expires_at`, and fenced owners whose leases
  had already expired. Each is fixed above. Regression tests:
  `only_an_unexpired_abandoned_lease_fences_its_owner`,
  `ready_work_is_claimed_before_abandoned_leases_are_recovered`,
  `a_held_poll_recovers_a_lease_abandoned_while_it_waits`,
  `recovery_repairs_the_abandoned_task_as_php_does` and
  `recovery_finds_abandoned_leases_anywhere_and_honors_the_scan_limit`. All
  five fail on the earlier head.
- **Still open.** Cooperative cancellation delivery (`cooperative.rs`) judges
  the owner's freshness with a fixed 60 seconds rather than the configured
  stale window.
