# October 22, completion with open work and deferred closes

- **Why.** Native refused a worker's `complete_workflow` with `409
  pending_durable_operations` while any activity, timer, child or wait was
  open. Published PHP has no such refusal. A workflow that races a timer
  against a signal and returns leaves the losing timer pending. Native
  would not let its worker close that run.
- **Source.**
  - Workflow 2.9.0: `DefaultWorkflowTaskBridge::applyWorkflowCompletion`,
    `DefaultActivityTaskBridge::attemptContinuationState`,
    `WorkflowRunRetentionCleanup::retentionHoldReason` and
    `ActivityAbandonment::hasTerminalHistory`.
  - Server 2.10.0: the deliverable-signal hold in `completeWorkflowTask`,
    `PendingMessageTask::hasDeliverableSignal`, and
    `ActivityTaskController`'s outcome responses.
- **Behavior.**
  - **Completion over open work.** A run completes over its open
    activities, timers and waits, and they stay as they are. An open child
    under `abandon` keeps running. Native still refuses a completion while
    a child under another parent-close policy is open, because it cannot
    apply those policies yet (the port's gap list).
  - **Deferred close (Server 2.10.0).** A signal can commit after the
    worker read history. If the open wait can take it and no cooperative
    cancellation is pending, `complete_workflow` and `continue_as_new` are
    not applied. The task's other commands are, the run waits, and the
    signal gets its own task. The signal must follow the one the task
    applied, if any. A received signal that the open wait cannot take does
    not hold the close. The run closes and rejects it.
  - **Late activity results.** For a run that completed or failed while
    the activity was open, a result or failure gets `409` with PHP's
    outcome body: `recorded: false`, `reason: run_closed`,
    `next_task_id: null`. A heartbeat already answered `can_continue:
    false` with `reason: run_closed`. Cancelled and terminated runs keep
    their own receipts.
  - **Retention.** A closed run's details are held only for an activity
    scheduled under the `abandon` cancellation policy whose last attempt
    has no terminal event, as in PHP. Native used to hold a run for any
    open activity. Activities left open by a completion would otherwise
    have kept the run forever.
- **Remaining gaps** (the port's gap list):
  - A task may not open a wait while another wait is open, or start a
    child while other work is outstanding.
  - Continue-as-new does not carry received signals or accepted updates.
  - Parent-close policies other than `abandon` on completion.
- **Tests.**
  - A run completes over an open, leased activity, a pending timer and an
    open wait. Nothing is cancelled. The activity's heartbeat, result and
    failure learn `run_closed` and add no history. The run is then pruned
    by retention.
  - A signal for the open wait, sent while the closing task is leased,
    defers the completion. The run waits, the signal's task is claimed,
    and the next completion closes the run.
  - A signal the open wait cannot take is rejected, and the run completes.
  - Unit test for the abandoned-activity rule. The terminal event must
    follow the last start and name its attempt. A never-started activity
    closes only by cancellation or timeout.
  - The external payload rollback test now fails its batch with a second
    open wait instead of a completion.
  - A parallel-group test no longer expects a completion to be refused
    while the group's other timer is pending. Its worker fails the task to
    wait, as before, and the barrier assertions are unchanged.
