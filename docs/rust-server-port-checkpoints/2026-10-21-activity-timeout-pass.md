# October 21, activity timeout pass

- **Found by.** Published dw 2.3.0's `system:activity-timeout-pass` command.
  Its route, `POST /api/system/activity-timeouts/pass`, was still answered by
  native's 501 after the retention slice.
- **Source.** Native serves the admin-only pass in the default or a named
  namespace. It follows published PHP 2.5.14's `activityTimeoutEnforcePass`
  and `ActivityTimeoutGuard`:
  - **Which executions.** The pass enforces the `execution_ids` it is given.
    Without them, it takes the expired executions that
    `GET /api/system/activity-timeouts` lists. That scan covers every
    namespace, as in PHP.
  - **Enforcement.** For each execution, native runs the same code its own
    deadline loop uses. It either schedules a retry or records the timeout,
    and reports `enforced` with `has_retry`.
  - **Skip reasons.** Native leaves an execution with PHP's reasons and in
    PHP's order:
    - `execution_not_found`;
    - `execution_already_terminal`;
    - for a running execution: `current_attempt_changed`,
      `current_attempt_not_found` or `current_attempt_not_running`;
    - `no_deadline_expired`;
    - `run_already_terminal`.
  - **Validation and status.** `limit`, `execution_ids` (at most 100) and
    `execution_ids.*` get Laravel's messages. The pass answers 207 when an
    execution failed.
  - **Bodies.** Both system passes read an empty body as empty input and
    refuse malformed JSON with PHP's `400 malformed_json`. Other native
    routes still answer malformed JSON with a plain-text 400. That is
    recorded as a known gap.
  - **Loop.** Native's deadline loop now enforces each due execution through
    this per-execution path. The order of its checks changed to PHP's. What
    each check writes did not change.
- **Differences from PHP.**
  - When enforcement throws, PHP records the exception message as the
    reason. It counts the execution as an error only when that message
    contains "Exception"; otherwise it counts it as skipped. Native reports
    every failure as `error` with its reason.
  - Native accepts an attempt that its lease expiry marked `expired` as
    still current. PHP has no such attempt status.
- **Verification.**
  - The `activity_timeout_pass` integration test checks four cases on SQLite,
    PostgreSQL and MySQL: an unknown execution, one whose deadline has not
    passed, one the loop already timed out, and an expired execution on a
    closed run. A unit test checks the report counts. The existing activity
    deadline tests pass on all three databases.
  - In the Waterline harness, the same nine requests went to published PHP
    2.5.14 and to native: an execution that is not due and an unknown one,
    the status, empty and body-less passes, three validation failures, and a
    pass after cancellation. The outputs were identical apart from
    identifiers. A malformed body also gets 400 from both.
  - Published dw 2.3.0's `system:retention-status`, `system:retention-pass`,
    `system:activity-timeout-status` and `system:activity-timeout-pass`
    print the same JSON from both servers.
