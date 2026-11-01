# November 1, history export waits and timeline

- **Before.** Native's history export bundle had empty `waits` and
  `timeline` sections and named both sources `native_unavailable` in
  `selected_run`. Published PHP fills them from `workflow_run_waits` and
  `workflow_run_timeline_entries`.
- **Contract** (published Server 2.11.7, Workflow 2.15.7, recorded first).
  - `waits` is `RunWaitProjector::snapshotForRun`. It returns the stored wait
    rows in display order (open, resolved, cancelled; then position and wait
    id) as `WorkflowRunWait::toWaitPayload` builds them: the row's
    `RunWaitView` entry, overlaid with its columns. Each kind adds its own
    fields:
    - activity and timer waits: `diagnostic_only`, `row_status` (a timer's
      own status) and the parallel group;
    - signal waits: `signal_wait_id`, `signal_id`, `timer_id`,
      `timeout_seconds` and `timeout_fired_at`;
    - condition waits: the key, definition fingerprint, timeout and when the
      timeout fired;
    - child waits: the call id, the child run and the parallel group;
    - update waits: `update_id` and `ordering_state`.
    Signal, condition and update waits carry no history authority. A
    wait's task status is its task's current status.
  - `timeline` is `HistoryTimeline::fromHistory`. It has one point per
    history event in sequence order, with PHP's kind, source kind and id,
    summary text, and the command, task, activity, timer, child and failure
    the point names. A point's task is the event's own `task` snapshot when
    it has one, otherwise the task row. PHP serves its stored projection
    (`workflow_run_timeline_entries`) and rebuilds it from history when it
    is missing (`workflow_run_timeline_entries_rebuilt`).
- **Fixture.** `history-export-waits`, over raw worker HTTP in a fresh
  namespace. It covers seven shapes, each exported while the run waits and
  again once it is closed: an activity that completes, an activity that
  fails and fails the run, a timer, a signal wait, a condition wait whose
  timeout fires, a child workflow and an update. Identities are labels in
  sorted key order and instants are masked. A timeline command's caller,
  principal and request context is dropped, because native records none
  (October 21, history export). Two PHP recordings matched exactly.
- **Native.**
  - `export_waits.rs` reads the run's `workflow_run_waits` rows, which
    native keeps in the transactions that author each wait's history. It
    derives each kind's `RunWaitView` fields from the run's history, the
    export's rebuilt timers and its update and signal rows. Native stores no
    wait payload. The source is `workflow_run_waits`.
  - `export_timeline.rs` ports `HistoryTimeline::mapEvent` over the export's
    own rows. Timer metadata uses the export's rebuilt timers, because
    native keeps no timer rows. Failure metadata uses the export's failure
    snapshots. A command payload previews through the description
    projection (`previews::project`). The source is
    `workflow_run_timeline_entries_rebuilt`; the fixture accepts either PHP
    name for a complete timeline.
  - `UpdateApplied` and `UpdateCompleted` now carry their workflow task's
    snapshot, as PHP records them with the task model (`store.rs`
    `TASK_SNAPSHOT_EVENTS`).
  - A failure snapshot now resolves its recorded class as
    `FailureFactory::replayResolution` does for PHP's built-in throwables:
    `exception_resolved_class` is the class and
    `exception_resolution_source` is `recorded_class`. Any other class is
    `unresolved`, because native knows no application or vendor class.
- **Tests.** `history_export` execution tests cover the activity wait and
  timeline points of a completed run, and a signal wait opened, resumed and
  ended by a failed run. `export_timeline` unit tests cover summaries,
  truncation and parallel group metadata. The fixture passes on published
  2.11.7 and native.
- **Not changed.**
  - A timeline command's caller, principal and request context stays null,
    as in the export's command section.
  - Exception classes other than PHP's built-in throwables resolve as
    `unresolved`. PHP resolves any class loaded in its process.
