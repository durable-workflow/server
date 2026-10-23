# October 23, deregistration settles activity leases

- **Source.** Server 2.11.1 `WorkerRegistrationDeregistrar`,
  `FencedWorkerDeregistration`, `ActivityTaskLeaseRecovery::recoverForStoppedWorker`
  and `ActivityTimeoutEnforcer::revokeWorkerLease` (through
  `enforceExpiredAttempt` and `leaseLossMetadata`).
- **Published behavior.** Both deregistration routes,
  `DELETE /api/worker/registrations/{id}` and the fenced
  `POST /api/worker/registrations/{id}/deregister`, settle each activity
  task the departing worker still leases, in the same transaction:
  - The original attempt closes as expired, and its task is cancelled with
    `last_error` `worker_deregistered`.
  - The activity's retry policy then records `ActivityRetryScheduled` or a
    terminal `ActivityFailed`, as for an expired lease. `failure_reason` and
    `retry_reason` are `worker_deregistered`. The event also carries
    `lease_revoked_at` beside the original `lease_expires_at` and
    `lease_owner`.
  - A lease that had already expired says `lease_expired`, without
    `lease_revoked_at`.
  - An activity whose deadline also expired settles as that timeout.
  - A task the worker no longer holds is left alone.
  - The deregistration response is unchanged.
- **Evidence for PHP.** On a fresh 2.11.1 SQLite server, two activities were
  claimed by worker `w`, then `w` was deregistered. The activity without a
  policy recorded `ActivityFailed` with `failure_reason`
  `worker_deregistered` and `lease_revoked_at`. The one with two attempts
  recorded `ActivityRetryScheduled` with `retry_reason`
  `worker_deregistered`. The same script against native recorded the same
  events, reasons and lease fields.
- **Native before.** Native's deregistration released only workflow-task
  leases. A departed worker's activity leases stayed until they expired,
  five minutes by default.
- **Served.** Both native deregistration routes now settle the departing
  worker's activity leases with the lease enforcer, inside their own
  transaction, before workflow-task recovery.
- **Tests.** Native tests on SQLite, PostgreSQL and MySQL cover:
  - a plain deregistration that schedules a retry, which a new registration
    then claims as attempt two;
  - a fenced deregistration that fails an activity without retries.

  The existing deregistration tests still pass.
