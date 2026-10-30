# October 30, repair of a run without a recoverable task

- **Before.** Native's repair command (`POST /api/workflows/{id}/repair`)
  refused with `422 development_workflow_task_repair_unqualified` in two
  cases:
  - a run whose summary needed repair but had no task to recover;
  - a run with an expired activity-task lease.

  The repair pass created only a missing workflow task. It reported a
  missing activity, timer, update, signal or child task as a failure (207).
- **Contract** (published Server 2.11.7, `TaskRepair::repairRun`). Each
  case is recorded as `repair_dispatched` with a `RepairRequested` event
  naming the task. Recorded over HTTP with each task row deleted from, or
  its lease expired in, the server's own SQLite database, with the
  maintenance loop stopped:

  | Case | New or recovered task |
  | --- | --- |
  | Missing workflow task | ready workflow task, empty payload, `repair_count` 1 |
  | Missing activity task | ready activity task for the pending execution: `{activity_execution_id}` (plus the latest retry's fields), its `attempt_count`, `repair_count` 1 |
  | Missing timer task | ready timer task `{timer_id}`, available at the timer's fire time, `repair_count` 1 |
  | Expired workflow-task lease | the task back to ready, `repair_count` 1 |
  | Expired activity lease, attempts left | attempt expired, `ActivityRetryScheduled`, the retry task |
  | Expired activity lease, last attempt | attempt expired, `ActivityFailed`, the workflow task that sees it |

  The run is described by its wait again afterwards.
- **Native.**
  - `task_repair::create_missing_task` follows `createMissingTask`, and
    the command and the pass share it. It moves the run's open activity or
    timer wait onto the new task.
  - The command recovers an existing task through the pass's
    `recover_task_row`. A running activity's expired lease is settled in
    the command's own transaction (`settle_lost_lease`). The repaired task
    is the enforcer's next task.
  - Native's scheduler settles an expired activity lease within seconds,
    as PHP's repair loop does, so a later repair command finds nothing to
    do (`repair_not_needed`) on both servers.
- **No shared fixture.** A run without a task, or with an expired lease, is
  reached only by editing the server's database, which no HTTP probe can do
  on every backend. The repair-pass fixture avoids real candidates for the
  same reason. The six cases were run against both servers on SQLite with
  an exploration script; their task rows and repair events match. Native
  tests pin them on every backend.
- **Tests.** In `workflow_repair.rs`:
  - `repair_creates_a_missing_workflow_activity_or_timer_task_like_php`;
  - `repair_settles_an_expired_activity_lease_by_its_retry_policy_like_php`.
- **Not covered.** The missing task of an update, signal or child wait, or
  of a condition or signal wait's timeout timer. PHP builds those tasks'
  payloads from the wait (`forMissing…Task`), and native still refuses
  them.
