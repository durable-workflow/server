# October 23, message streams

- **The gap.** All three published SDKs declare `message-streams` from
  worker protocol 1.15. Native refused a worker that asked for them, did not
  serve the append and diagnostics routes, and ignored
  `message_stream_cursors` and `message_stream_waits` on a completion.
- **How PHP 2.11.1 does it.** Learned from its source, then observed live
  against the published image:
  - Each accepted message is delivered to the current run as the
    runtime-reserved signal `__durable_workflow_message_stream`, in the
    append's transaction. The signal skips the declared-signal and argument
    checks but not the pending-signal and payload ceilings.
  - SDK receives open a condition wait keyed by the stream cursor. A stream
    message wakes that wait.
  - When a signal wakes a condition wait, PHP consumes it without
    `SignalApplied`, unless the run's durable contract declares that signal.
    This holds for any undeclared signal, not only stream messages.
  - The stream cursor's `MessageCursorAdvanced` is recorded after the
    completion, with the completed task's snapshot.
  - On continue-as-new, the next run receives a payload-free cursor
    checkpoint for each acknowledged stream, then every unconsumed message
    again, by append order. The old run rejects the signals it had not
    consumed.
- **What native does now.**
  - `POST /api/workflows/{id}/message-streams/{stream}/messages`, with PHP's
    validation order, refusals and diagnostics of malformed input.
  - `GET /api/workflows/{id}/message-streams` and
    `GET /api/workflows/{id}/message-streams/{stream}`.
  - Completion cursors and waits: validated before anything changes,
    recorded in the completion's transaction, handed over on continue-as-new.
  - The worker capability is PHP's contract with `supported: true`, and
    cluster info publishes `message_streams_contract`.
  - Continue-as-new no longer refuses a run with message streams.
- **Native changes found on the way.**
  - A signal consumed by a condition wait no longer records `SignalApplied`
    unless it is declared, and keeps the wait's sequence.
  - The instance-level `MessageCursorAdvanced` carries the task snapshot, as
    PHP's does.
  - A completion reports the run's next message task in
    `created_task_ids`, as PHP does.
- **How it was checked.**
  - The `message-streams` fixture: 15 appends, 8 refused completions, the
    deliveries each worker read, both runs' full event sequences, and the
    diagnostics at each step.
  - Published PHP 2.11.1 and native pass it on SQLite. The probe's output
    is the same size on both, and both runs' event sequences are identical.
  - The contract test refuses 50 changed observations, among them a
    `SignalApplied` for a consumed message, a missing next message task, a
    redelivered consumed message and a missing cursor checkpoint.
- **Not done yet** (Server #325 follow-ups):
  - **External payload references.** Native refuses a reference as stream
    input with 501 `message_stream_external_payload_unavailable`. PHP keeps
    the reference and resolves it when history reaches a worker.
  - **Cleanup and upgrade.** Retention cleanup of streams
    (`MessageStreamRetentionCleanup`) is not ported. Takeover of PHP data
    with streams is still refused.
  - **Cluster `capabilities` map.** Native has none yet, so
    `capabilities.message_streams` is absent; that map is the cluster info
    work.
  - **History presentation, outside streams.** Task snapshots lack PHP's
    `last_dispatch_attempt_at` and `last_dispatched_at`. A consumed signal's
    `SignalReceived` lacks `workflow_sequence` in poll history.
  - **Condition fingerprint.** Native requires `condition_definition_fingerprint`
    on `open_condition_wait`; PHP accepts a wait without one.
