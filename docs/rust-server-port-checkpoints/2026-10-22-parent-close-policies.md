# October 22, parent-close policies on every parent close

- **Found by.** The gap list. Native refused to complete, cancel or
  terminate a parent while a `request_cancel` or `terminate` child was open.
  It also closed a failed or timed-out parent without acting on its
  children. Published PHP applies the policies on every close.
- **Source.** Workflow 2.9.0's `ParentClosePolicyEnforcer`, called after a
  parent records its terminal event and closes its pending updates.
  - **Closes.** Completion, failure, timeout, cancellation and termination
    now all apply the policies. A cooperatively cancelled parent applies all
    three non-`abandon` policies, not only `request_cancellation`.
  - **Order.** Children are handled in link order (sequence, then id).
    Closed children are skipped.
  - **`request_cancel` and `terminate`.** The child instance's current run
    is closed as `WorkflowStub::attemptCancel` and `attemptTerminate` close
    it:
    - a command with source `php` and the PHP API caller context, instance
      scope, the run's next command sequence, and the reason
      "Parent workflow closed ({closed reason}); parent-close policy:
      {policy}." in its payload;
    - then the same close an HTTP cancel or terminate performs, including
      the child's own parent-close policies.
    - The parent's child call becomes `cancelled` or `terminated`. Like
      `markChildCallClosedByParentPolicy`, the parent, already closed, gets
      no resolution event or task.
  - **Parent event.** The parent records `ParentClosePolicyApplied` with
    the child's instance and run, the policy and the reason.
  - **`request_cancellation`.** Unchanged: it applies only under the
    parent's cooperative request.
- **Remaining gap.** Native records `ParentClosePolicyFailed` with its
  refusal and leaves the child open if that child cannot be closed yet. That
  happens when the child has its own open `request_cancellation` child, a
  live query, or more than 1,000 open activities or timers. Native writes
  nothing to the child in that case. Policies apply up to 32 levels deep.
  The completion, cancel and terminate guards still refuse while a
  `request_cancellation` child is open without a cooperative request.
- **Tests** (`parent_close_policies.rs`).
  - **Every close.** One test covers each way a parent closes:
    - completion with `request_cancel`, `terminate` and `abandon` children;
    - failure with `terminate`;
    - cancel with `terminate`;
    - terminate with `request_cancel`;
    - a run deadline with `terminate`, reached in the task an accepted
      update gives the parent.
    - For each case the test checks the parent's last event and its
      payload, the child's history, reason and message, the PHP-sourced
      command, the child call's status, the description, and the cancelled
      child task. An `abandon` child keeps running and its task.
  - **Setup.** Native admits a child start only as a task's sole command,
    so a worker can complete or fail a parent with an open child only on a
    run taken over from PHP. The test gives the parent that next task
    directly.
  - **Nested.** A parent's policy closes a child whose own `terminate`
    policy closes its grandchild, with each reason and event.
  - **Refused.** A child with an open `request_cancellation` grandchild
    stays unchanged. The parent still terminates and records
    `ParentClosePolicyFailed` with `termination_propagation_not_available`.
