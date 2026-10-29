# October 29, child retry and timeout options

- **Before.** Native refused `retry_policy`, `execution_timeout_seconds` and
  `run_timeout_seconds` on `start_child_workflow` with `422
  unsupported_request_field`. Its child runs never had a budget or deadline.
- **Contract** (published Server 2.11.7, Workflow 2.15.7
  `ChildWorkflowRetryPolicy` and the bridge's `applyStartChildWorkflow` and
  `startChildRetryIfAvailable`):
  - **Recorded options.** The call, `ChildWorkflowScheduled`,
    `ChildRunStarted` and the child's `WorkflowStarted` record a retry
    snapshot (`snapshot_version`, `max_attempts` at least 1,
    `backoff_seconds`, distinct trimmed `non_retryable_error_types`) and a
    timeout snapshot. A policy with no known key records nothing. Each
    timeout sets its own deadline on the child run.
  - **Retry.** When a child run fails, or times out, and the call's policy
    has attempts left (one per link of the call), the child instance starts
    a new run under a new link. It keeps the call, the execution deadline
    and the arguments, and gets a new run deadline. Its task is available
    after the policy's backoff. The parent records `ChildRunStarted` with
    `retry_attempt`, `retry_of_child_workflow_run_id` and
    `retry_backoff_seconds`, without null fields, and keeps waiting.
  - **No retry.** The failure is non-retryable when the run's failure is
    marked so, or the policy names the type or class of the run's
    `WorkflowFailed` event or of its failure. The parent then sees the
    child fail, as it does when attempts run out.
  - **Unchanged.** The parent's child wait keeps naming the first run.
- **Fixture.** `child-retries`, raw worker HTTP on protocol 1.20 in a fresh
  namespace. Each case starts a parent with one child and plays the child's
  attempts:
  - a failure, then a completion, with a one-second backoff and both
    timeouts;
  - two failures with two attempts;
  - a failure of a non-retryable type;
  - a failure marked non-retryable;
  - an empty policy;
  - timeouts without a retry policy;
  - two completions after a one-second run deadline.

  It records the answers, the parent's child events, the child instance's
  runs and their started events, the parent's description while a retry is
  pending, and the parent's resumption. IDs are labels and deadlines are
  recorded by presence, with how a retry's deadlines relate to the first
  run's.
- **Native** (`child_retries.rs`, `children.rs`, `child_summary.rs`):
  - the three options are accepted and recorded as above;
  - `resolve_child` retries a failed child before the parent sees it;
  - the child summary follows a retried call to its latest run and link,
    so the parent stays described as waiting for the child;
  - a timed-out child's `ChildRunFailed` names `closed_reason: timed_out`,
    as PHP's does, while the call keeps `failed`. Native named `failed`
    for any timed-out child, retried or not; the fixture's first native
    recording found it.
- **Tests.** `child_retries.rs` (native), and the snapshot, backoff and
  non-retryable rules in `child_retries.rs` unit tests.
- **Bounds** (Security review). A child timeout or backoff entry too far
  away for a timestamp is refused at admission with `422
  invalid_child_timeout` or `invalid_child_retry_policy`, and every child
  deadline is computed with checked arithmetic, so an extreme value cannot
  overflow. PHP's Carbon wraps such a value instead; native refuses a timer
  delay or activity backoff the same way.
- **CI.** The first hosted run failed the MariaDB corpus: the retry read
  `MAX(run_number)`, which MySQL returns unsigned, and repeated a
  placeholder. Both are fixed. Locally, the fixture passes on MariaDB 10.11,
  PostgreSQL 17 and SQLite.
- **Not covered here.**
  - Cooperative cancellation delivered to a retried child: native's
    delivery expects one link per call and refuses it with `409
    cancellation_delivery_shape_mismatch`. Security's review confirmed this
    from the source. A fix needs a PHP recording first: #873.
  - Memos and search attributes inherited by child runs, retried or not:
    the [child-visibility checkpoint](2026-10-29-child-visibility.md).
