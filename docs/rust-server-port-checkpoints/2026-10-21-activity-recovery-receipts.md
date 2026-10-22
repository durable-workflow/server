# October 21, activity deadlines and late results

- **Found by.** Running the Sample App's activity recovery scenario
  (`scripts/sdk-activity-recovery.sh`) against native, unchanged except that
  its server, and its separate PHP `activity:timeout-enforce` loop, were
  native processes. The scenario failed four times in turn, once per gap below.
- **Deadlines on claims and status.** Published PHP returns the activity
  execution's deadlines on an activity claim and on the attempt status read:
  `schedule_to_start`, `start_to_close`, `schedule_to_close` and `heartbeat`,
  each in UTC with microseconds, leaving out the ones that are not set. PHP
  leaves the claim field out when there are none and reports `null` on
  status. Native returned `null` on status and had no claim field, so the
  scenario could not find the original total deadline.
- **Late results are judged by their attempt first.** PHP checks the
  reporting attempt before the task's lease or recorded outcome. A result
  from an attempt that is no longer current gets `409 stale_attempt`, even
  after another attempt finished the task, or after the attempt's own
  failure closed its task. The completion receipt carries the attempt
  owner, `activity_status`, `attempt_status` and `task_status`; the failure
  receipt does not. Native compared the task's recorded receipt first and
  answered `conflicting_completion`, or `task_not_leased` for a failure. An
  identical repeat of the result that finished the task keeps native's
  idempotent receipt.
- **Results after a timeout.** A timeout closes the attempt and cancels its
  task. Native answered a later completion or failure for that task with a
  bare `409 stale_attempt`, without the task, attempt and status fields. The
  PHP SDK could not recognize that refusal, tried to fail the task, received
  the same refusal and stopped its worker. Every later case of the scenario
  then had no PHP activity worker. Native now answers with PHP's full stale
  attempt receipt, which the PHP SDK discards. Heartbeats keep their answer.
- **Verification.**
  - Tests cover the deadlines map on a claim and a status read with and
    without deadlines, a late result from a replaced attempt and from a
    failed and retried attempt, and late results after each authored
    timeout. They pass on SQLite, MySQL and PostgreSQL.
  - On a disposable host, the scenario's five PHP-to-PHP cases and two
    PHP-to-Python cases then passed. The next case stopped on a separate
    gap: native refuses the Python SDK's activity failure reports, which
    carry `class` and `stack_trace`.
