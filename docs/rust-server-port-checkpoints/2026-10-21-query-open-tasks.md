# October 21, queries during open workflow tasks

- **Found by.** The Sample App's update scenario (`scripts/sdk-updates.sh`),
  run unchanged against native. After nine cross-SDK update round trips, its
  snapshot query reached a Rust workflow that had an open workflow task.
  Native refused the query with `409 query_snapshot_busy`, a known gap.
- **Published PHP (`WorkflowQueryTaskBroker`).**
  - **Admission.** PHP admits the query and records the run's history
    sequence. If a workflow task holds a live lease at that moment, the
    same sequence becomes the query's history cutoff.
  - **Claim.** A worker cannot claim the query while any workflow task of
    the run holds a live lease. It also cannot while a due resume task
    (signal, update, activity, child or timer) is ready and the query
    cannot see its effect. Two resume tasks do not hold the claim: one
    created after a query with a cutoff was admitted, and a signal whose
    `SignalReceived` event the query already observed. A run's first
    workflow task is not a resume task.
  - **Poll status.** A poll that finds only held queries returns
    `workflow_task_pending` at once when a ready resume task holds them. It
    returns `workflow_task_leased` when its wait ends while a lease holds
    them.
  - **Delivery.** The claim delivers the history read at claim time, up to
    the cutoff (and never past the run's current history) or all of it
    without one. `history_cutoff_sequence` is `null` without a cutoff.
- **Correction.** The earlier known-gap entry said a query sent right after
  a signal waits on PHP. PHP records `SignalReceived` when the signal is
  accepted, and the query observes it, so that resume task does not hold
  the claim.
- **Native.** It follows these rules. Native's activity and ordinary timer
  resume tasks name their source without a `resume_source_kind`, so native
  recognizes them by `activity_execution_id` and `timer_id`. A run whose
  history is already larger than native's one bounded query page is still
  refused at admission with `413 query_snapshot_exceeds_budget`. A run that
  grows past it before the claim fails the query with that reason. A poll
  held by a lease re-checks with a read-only snapshot instead of the
  transition lock, and wakes when the task ends.
- **Verification.**
  - New tests cover:
    - a query admitted during a leased task: it waits, the poll reports
      `workflow_task_leased`, and after completion it reads history only to
      its cutoff;
    - a ready timer resume: `workflow_task_pending` at once, still held
      while that task is leased, then the full history at the claim;
    - a run's first task and an observed signal, neither of which holds the
      claim.
  - The existing query lease and replay tests pass unchanged. The two
    `query_snapshot_busy` assertions they made were removed.
