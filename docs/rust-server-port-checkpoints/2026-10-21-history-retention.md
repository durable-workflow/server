# October 21, history retention

- **Found by.** The published dw 2.3.0 CLI sweep. `system:retention-status`,
  `system:retention-pass` and `system:activity-timeout-status` received
  native's 501. Native's worker heartbeat also left out PHP's `retention`
  report. As a result, a bounded namespace never pruned its closed runs. The
  default namespace keeps 30 days.
- **Source.** Native serves admin-only `GET /api/system/retention`,
  `POST /api/system/retention/pass` and `GET /api/system/activity-timeouts`.
  They work in the default or a named namespace. They follow published PHP
  2.5.14's `SystemController`, `HistoryRetentionEnforcer` and
  `ActivityTimeoutScanner`:
  - **Policy.** It comes from the namespace's `retention_mode` and
    `retention_days`. Days that are not stored come from
    `DW_HISTORY_RETENTION_DAYS` (30 by default). The cutoff is that many days
    before now.
  - **Expired runs.** These are the namespace's closed, unarchived, completed
    or failed run summaries that closed before the cutoff, oldest first. The
    scan returns at most `min(100, limit)`.
  - **Pass.** It prunes the run ids it is given, or else the expired runs.
    - It skips a run with PHP's reasons: `namespace_retention_forever`,
      `run_not_found`, `run_not_terminal`, `run_archived` or
      `detached_activity_still_open`.
    - Otherwise, one transaction deletes the run's rows from PHP's twenty
      detail tables, in PHP's order, and sets the run's `details_pruned_at`.
      The run row itself stays.
    - The report has PHP's per-table counts. The pass answers 207 when any
      run failed.
  - **Heartbeat.** Each worker heartbeat runs PHP's inline pass: one run, at
    most once a minute per namespace. Its result is the heartbeat's
    `retention` report.
  - **Activity timeouts.** The scan reports open executions past a schedule,
    close, schedule-to-close or heartbeat deadline. As in PHP, it covers
    every namespace.
  - **Validation.** `limit`, `run_ids` and `run_ids.*` get Laravel's messages,
    including keyed items such as `run_ids.a`.
- **Differences from PHP.**
  - **Payload and stream cleanup.** Before pruning, PHP deletes the run's
    external payload objects and releases its message stream items. Native
    does not clean these up yet. It skips such a run with
    `external_payload_cleanup_unavailable` or
    `message_stream_cleanup_unavailable`, so it never deletes rows that still
    point at storage it would leave behind. Its report's three
    message-stream counts are therefore zero.
  - **Open activities.** PHP holds a run whose abandoned activity is still
    open. Native holds a run with any open activity. Native closes a run only
    after cancelling its open activities, and it refuses a completion while
    one is pending (`pending_durable_operations`). An open activity on a
    closed run is therefore one that PHP abandoned.
  - **Throttle scope.** PHP's inline throttle is a cache entry. Native's
    throttle is per process, so several native nodes can each run an inline
    pass in the same minute. A second prune of the same run deletes nothing
    more.
  - **Timeline counts.** Native writes no timeline entries, so
    `timeline_entries_deleted` is zero for runs native wrote.
- **Still open.**
  - `POST /api/system/activity-timeouts/pass`. Native enforces activity
    deadlines in its own loop.
  - External payload and message stream cleanup.
- **Verification.**
  - The `history_retention` integration tests pass on SQLite, PostgreSQL and
    MySQL.
  - The Waterline harness ran 31 requests against published PHP 2.5.14 and
    against native: registration, two runs, cancellation with an open
    activity, the status, explicit and empty passes, two heartbeats,
    validation failures and a missing namespace.
  - Every status matched. The bodies matched apart from identifiers, the
    cutoff time and `timeline_entries_deleted` (PHP 6 and 3, native 0).
  - The probe found that PHP 2.5.14 requires `capability_manifest` on
    registration at protocol 1.19, and native does not. This is recorded as a
    known gap.
