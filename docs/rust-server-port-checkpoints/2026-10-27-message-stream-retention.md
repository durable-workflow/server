# October 27, message-stream retention

- **Before.** Native's retention pass held any run that had
  `workflow_messages` rows or whose instance had inbound message streams, with
  `message_stream_cleanup_unavailable`. Published PHP's
  `MessageStreamRetentionCleanup` was never ported. So a namespace that used
  message streams kept those runs' details forever, and takeover refused any
  PHP database with stream rows.
- **Change.** `prune_run` plans and applies the stream cleanup in the run's
  own prune transaction, under the transition lock. The plan follows PHP's
  `planForRun`, read before the run's summary goes:
  - **No inbound streams on the instance:** nothing to do.
  - **The instance's current run is terminal and no other run summary
    remains:** every item of the instance's streams is deleted, then the
    streams. Reported as `message_stream_items_deleted` and
    `message_streams_deleted`.
  - **Otherwise:** on each stream whose `cursor_checkpoint_run_id` is a
    retained later run (another run summary of the instance, or its current
    run), the items this run consumed lose their payloads. That covers items
    at or before the stream's cursor that are not released yet: `payload_blob`
    becomes `''` and `payload_released_at` is set. The item rows stay as
    identity tombstones. Reported as `message_stream_items_compacted`.
    `cleanup_blocked_*` markers naming this run are cleared.
  - The run's `workflow_messages` rows go with the run's other details
    (`messages_deleted`), as in PHP. Before, such a run was held.
  - The current run counts as retained only when its run row exists, as in
    PHP.
  - A run stays held with `external_payload_cleanup_unavailable` when an
    inbound item on its instance holds an external reference. Nothing writes
    one today; the hold keeps a later change from stranding stored objects.
- **Not ported.** PHP's `markBlocked`, which marks a stream when
  external-payload cleanup blocks a prune. Native never deletes external
  objects: a run holding an external reference stays held as
  `external_payload_cleanup_unavailable`, and native refuses external
  references as message input.
- **Tests** on SQLite, PostgreSQL, MySQL and MariaDB:
  - `retention_releases_consumed_messages_and_removes_the_last_runs_streams`:
    pruning a continued run compacts the two messages it consumed and keeps the
    third. Pruning the last run deletes the stream and its three items.
  - `retention_keeps_consumed_messages_no_retained_checkpoint_covers`: without
    a retained checkpoint, nothing is compacted and the stream stays.

## Takeover keeps message and workflow streams

- **Before.** Takeover refused any source with rows in
  `workflow_messages`, either kind of stream, or their items.
- **Change.** Stream rows carry over unchanged.
  - Each closed or errored durable stream gets its `dw_stream_reclaims`
    deadline: 2.11.3's `reclaim_at`, or `closed_at` plus `retention_seconds`
    (3600 unless set) for earlier layouts. Reported as
    `stream_reclaims_recorded`; MySQL and MariaDB do it in journal step 10.
  - Inspection reports `stream_reference_inspection`. A stream row holding an
    external payload reference refuses with
    `upgrade_source_external_payloads_unsupported`.
  - Only `workflow_messages` stays refused, now as
    `upgrade_source_workflow_messages_unsupported`.
- **Tests.**
  - `takeover_keeps_php_streams_and_records_their_reclaim_deadlines` (SQLite).
  - The 2.11.3 takeover test now copies a recorded deadline.
  - The refusal list covers a durable stream item holding an external
    reference.
  - Takeover qualification passes on PostgreSQL, MySQL and MariaDB,
    including a MySQL takeover killed after step 9.

## Review fixes before the PR

An independent review found no serious defects. Fixed:
- A resumed MySQL takeover decides step 10's deadline source by layout, not
  by whether `reclaim_at` still exists. A 2.11.3 takeover killed after that
  column's drop had already copied every deadline, so it adopts nothing more
  instead of adding `closed_at + retention` rows for streams PHP had emptied.
- The current run's missing row and the inbound-item hold above.
- The payload scan's comment no longer says stream item rows are refused.
- The retention test now puts the cursor exactly on the pruned run's last
  consumed message (so `<=` matters) and points the instance at a missing
  current run.
