# October 23, what a worker reads in history

- **Found by.** Comparing the message-stream fixture's worker polls against
  published Server 2.11.1.
- **The gaps.**
  - PHP's poll and history-page responses show a consumed signal's
    `workflow_sequence` on its `SignalReceived` event, read from the signal's
    record (`WorkflowTaskPoller::historyEventsWithSignalArgumentEnvelopes`).
    Native showed the event as recorded.
  - PHP's task snapshots in history keep every field that is set
    (`WorkflowHistoryEvent::taskSnapshot`). Native's left out
    `compatibility`, `last_dispatch_attempt_at`, `last_dispatched_at`,
    `last_dispatch_error`, `last_claim_failed_at` and `last_claim_error`.
- **What native does now.**
  - Worker polls and history pages add `workflow_sequence` to a consumed
    signal's `SignalReceived`, when the payload does not have one. The
    run's own history route still shows the events as recorded, as PHP's
    does.
  - Task snapshots keep those fields when they are set.
- **Which signals carry a sequence.** PHP's `applySignal` takes a sequence
  only from the signal's own wait. A signal the run's contract declares is
  applied without one even while a condition wait is open, and
  `markConditionWaitSignalConsumed` then finds it applied, so its record keeps
  no sequence. Only an undeclared signal takes the condition wait's sequence.
  Native had recorded the condition wait's sequence for declared signals too.
  That difference was invisible until worker polls showed the record, and the
  published-SDK signal and query cohorts then caught it: their worker history
  must equal the run's history prefix. Native now records a sequence only from
  the signal's own wait, or from a condition wait for an undeclared signal.
- **How it was checked.** A test runs on SQLite, PostgreSQL, MySQL and
  MariaDB:
  - a condition wait consumes an undeclared signal;
  - the next poll and history page show that wait's sequence, and the
    run's history does not;
  - the completing task's snapshot has its dispatch stamps.

  A second test applies a declared signal under a condition wait twice. The
  worker's history shows no sequence on either `SignalReceived`, and it fails
  without the record change.
- **Not changed yet.** Some events PHP records with a task snapshot have
  none in native, such as `ConditionWaitOpened` and `SignalWaitOpened`.
  That audit is a #325 follow-up.
- **Known difference now visible in snapshots.** PHP stamps a dispatch only
  on tasks a control-plane call dispatches, such as a signal's task. Tasks a
  workflow task completion creates, such as the next message task, carry
  none. Native stamps every new task, so those snapshots show stamps where
  PHP's do not. This is the cause of the known `tasks_dispatched_last_minute`
  difference too; fixing it is a #325 follow-up.
