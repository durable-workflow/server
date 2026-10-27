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
  - The run's `workflow_messages` rows go with the run's other details, as
    before.
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
