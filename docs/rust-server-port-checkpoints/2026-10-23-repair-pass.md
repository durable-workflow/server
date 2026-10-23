# October 23, task repair pass

- **Source.** Server 2.11.1 `SystemController::repairPass`,
  `TaskWatchdog::runPass`, `TaskRepairCandidates`, `TaskRepair`,
  `TaskRepairPolicy`, `RequestedTaskScope` and
  `ActivityTimeoutEnforcer::expiredExecutionIds`.
- **Served.** Native answers admin-only `POST /api/system/repair/pass` in the
  default or a named namespace. Like PHP's, the pass is server-wide.
  - The request takes `run_ids` (at most 100 strings of 1 to 128
    characters), `instance_id`, `connection`, `queue` (comma-separated
    values; `default` also matches a null or empty column) and
    `respect_throttle`. Strings are trimmed and empty ones read as null.
  - A pass that respects the throttle runs nothing within 5 seconds of the
    previous pass and reports `throttled: true`. Any other pass runs and
    restarts the throttle. The throttle is shared by the server's namespaces.
  - Selection is PHP's poll-mode selection. It takes up to 25 repairable
    tasks and up to 25 runs that need a task. Candidates come one from each
    connection, queue and compatibility scope in turn, oldest first.
    - A ready task is repairable once it is due and its dispatch failed, its
      claim failed, or its dispatch and availability are more than 3 seconds
      old. A leased task is repairable once its lease expired.
  - Each selected task is recovered under its run. A ready task counts one
    more repair. An expired lease returns its task to ready and closes the
    lease's activity attempt. A closed run's task is settled instead. The
    task is then marked dispatched.
  - A run whose projected summary still needs repair gets its recoverable
    task back. A replay-blocked task comes first. Otherwise the run gets a
    new workflow task.
  - An open run past its execution or run deadline, with no open workflow
    task, gets a task with reason `deadline_expired`; native closes the run
    when that task runs. An expired cancellation cleanup deadline is
    enforced under a control task.
  - Expired activity deadlines in the requested connections and queues are
    enforced as by the activity timeout pass.
- **Decisions.**
  - Two recoveries are not ported. They are reported as their candidate's
    failure, so the pass answers 207 rather than claiming a repair:
    - An expired lease on a running activity. PHP consumes the activity's
      retry policy. The failure is also kept as the task's last error, as
      PHP keeps a recovery exception.
    - A missing task for a run waiting on an activity, a timer, a timer-backed
      condition, an update, a signal or a child.
  - Native has no service calls and no command contracts to backfill, so
    those counts are always zero.
  - A closed run leaves an activity open only when it completed without
    waiting for it. Native treats such an open activity as abandoned, as
    `ActivityAbandonment::allows` would.
  - Native runs no repair loop. The pass runs only when requested.
- **Shared rule.** Repair status and the pass now share one candidate query.
  The overdue branch also requires availability before the redispatch
  cutoff. The cutoff binds with microseconds, as PHP 2.11 binds it. Native's
  per-task recheck takes availability after the last dispatch as the
  redispatch reference, as `dispatchOverdue` does.
- **Fixture.** `repair-pass` checks nine requests. Three passes are scoped to
  run ids and a queue that do not exist, so each selects nothing and echoes
  its trimmed filters, with empty strings read as null. Six invalid requests
  answer Laravel's 422. The published PHP answers were checked against a
  fresh 2.11.1 server. Native tests cover the recoveries on SQLite,
  PostgreSQL and MySQL:
  - the queue filter;
  - the throttle;
  - an overdue ready task and an expired workflow lease;
  - a lost workflow task;
  - a deadline task;
  - the 207 report for an expired running activity lease.
