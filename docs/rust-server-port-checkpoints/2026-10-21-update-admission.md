# October 21, update admission during open tasks

- **Found by.** The Sample App's update scenario (`scripts/sdk-updates.sh`)
  against native. Its absent-worker phase sends an update while the run's
  workflow task is still open. Native refused it with `409
  update_snapshot_busy`.
- **Admission.** Published PHP admits an update whenever its run is active
  (`ExternalWorkflowUpdateAdmission::admit`). Native required a quiescent
  signal or condition wait, with no open workflow task and no pending
  signal or update. Native now admits the update like PHP.
  - `UpdateAccepted` records PHP's ordering fields. `ordering_state` is
    `queued` when an earlier accepted signal is not applied yet, and
    `queued_behind_command_id`, `_sequence` and `_type` name that signal
    (`UpdateCommandGate::blockingSignal`). Otherwise it is `ready`.
- **Delivery.** A ready update gets its own task at once when the run has no
  open workflow task. Otherwise the completion of the open task, or its
  waiting-for-history acknowledgement, delivers the run's next message
  (`PendingMessageTask::createForRun`). That is the earliest accepted
  update, unless an earlier signal is still unapplied; the signal then goes
  first. PHP instead merges an update into a ready task. That difference is
  recorded as a known gap.
- **Message order.** Native required each update to be exactly the next
  message number after the run's cursor. A rejected signal takes a message
  number but is never applied, so every later update was refused with
  `update_message_order_mismatch`. Native now applies PHP's rule: an update
  waits only for earlier unapplied signals, and the cursor only moves
  forward (`MessageStreamCursor::advanceCursor`).
- **Child resolution.** A child that finished while its parent had an open
  update task would have been refused with `409
  child_parent_task_conflict`. The resolution now gets its own resume task,
  as activity and timer resolutions do.
- **Waiting acknowledgements.** A waiting-for-history acknowledgement with a
  pending update now completes, and the update follows it. With a pending
  signal it is still refused: PHP delivers a declared signal without an open
  wait, but native does not, so no task would deliver it. That is recorded
  as a known gap.
- **Verification.**
  - New tests in `tests/execution/update_admission.rs` cover:
    - two updates admitted while the first task is ready and then leased,
      each delivered in order after that task completes;
    - an update queued behind an unapplied signal, with its ordering fields,
      applied after the signal;
    - an update after a rejected signal;
    - a waiting acknowledgement that delivers a pending update;
    - a child that finishes while its parent has an update task.
  - The full suite passes on SQLite. The update, signal, waiting and child
    tests (117) pass on PostgreSQL and MySQL.
