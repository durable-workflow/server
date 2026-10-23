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
- **How it was checked.** A test runs on SQLite, PostgreSQL, MySQL and
  MariaDB:
  - a condition wait consumes an undeclared signal;
  - the next poll and history page show that wait's sequence, and the
    run's history does not;
  - the completing task's snapshot has its dispatch stamps.
- **Not changed yet.** Some events PHP records with a task snapshot have
  none in native, such as `ConditionWaitOpened` and `SignalWaitOpened`.
  That audit is a #325 follow-up.
