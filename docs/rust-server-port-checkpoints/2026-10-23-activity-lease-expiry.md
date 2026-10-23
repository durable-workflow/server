# October 23, activity lease expiry

- **Source.** Server 2.11.1 `ActivityTaskPoller::claimReadyTask` and
  `ActivityTaskClaimer`, `TaskRepair::recoverLeasedTask`,
  `ActivityTimeoutEnforcer::enforceLeaseExpiry` (through
  `enforceLockedTimeout`, `leaseLossMetadata`, `closeTimedOutAttempt`,
  `settleTimeout`, `scheduleRetry` and `recordTerminalTimeout`) and
  `ActivityRetryPolicy`.
- **Published behavior.** PHP claims only ready activity tasks, so a worker
  never receives a task again after its lease expires. PHP's maintenance runs
  the repair pass every second, and the pass settles each expired activity
  lease:
  - The original attempt closes as expired, and its task is cancelled with
    `last_error` `lease_expired`.
  - When the activity's retry policy has an attempt left, PHP records
    `ActivityRetryScheduled`. It carries `retry_reason` and `failure_reason`
    `lease_expired`, the lease's owner and expiry, and no `timeout_kind`.
    The retry task waits out the policy's backoff.
  - Otherwise PHP records a terminal `ActivityFailed` with
    `Workflow\V2\Exceptions\ActivityLeaseExpiredException`, failure category
    `internal` and propagation `activity`. Its `non_retryable` is true when
    the policy lists that exception. The workflow then runs again to see the
    failure.
  - An activity whose own deadline also expired is left to the timeout sweep.
  - Without a retry policy an activity has one attempt.
- **Evidence for PHP.** A fresh 2.11.1 server on SQLite, with its
  one-second repair loop, claimed two activities and had their leases
  expired in the database. The activity without a policy recorded
  `ActivityFailed` as above. The one with two attempts recorded
  `ActivityRetryScheduled` with `retry_reason` `lease_expired`, then attempt
  two started on a new task.
- **Native before.** Native's claim query took a leased activity task whose
  lease had expired and ran it again as the next attempt. That skipped the
  retry policy, its backoff and the history events above. An activity
  without retries ran twice.
- **Served.** Native's claim query and its cross-kind poll take only ready
  activity tasks. Native's scheduler settles expired activity leases on each
  tick, after its deadline sweep, exactly as PHP's enforcer does. The repair
  pass now settles an expired running-activity lease the same way; it no
  longer reports it as unported. The same check against native recorded the
  same events, classes, reasons, lease fields and attempt numbers as PHP.
  - Workflow task leases are unchanged: PHP's workflow poller also takes a
    workflow task whose lease expired.
- **Decisions.**
  - PHP's lease events also embed the closed task's snapshot. Native's
    activity events do not carry task snapshots yet; this is the same known
    payload difference as its timeout events.
  - A run that already closed is skipped as `run_already_terminal`. Native
    never delivers an activity task of a closed run, so it does not continue
    an abandoned activity there.
- **Restart probe contract.** `restart.php` kills a native process while it
  holds an activity lease, waits out the real five-minute lease and finishes
  with a new worker. It expected the recovered worker to run attempt two of
  an activity without a retry policy, with no event in between. That
  expectation came from native's re-claim, not from PHP: published PHP fails
  that activity instead. The probe now declares two attempts for the
  activity in `current-activity-restart.json` (`activity_retry_policy`) and
  expects `ActivityRetryScheduled` with `retry_reason` `lease_expired`
  between the two `ActivityStarted` events.
- **Tests.** Native tests on SQLite, PostgreSQL and MySQL cover a retry,
  a terminal failure, a non-retryable lease exception, a poll racing the
  repair loop, the repair pass and an external result refused after the
  lease expired.
