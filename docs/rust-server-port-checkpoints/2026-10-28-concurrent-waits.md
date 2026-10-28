# October 28, several waits open at once

- **Before.** Native refused `409 pending_durable_operations` when a workflow
  task opened a signal or condition wait while another wait was open, and
  its wait logic followed only the latest opened wait. Published PHP has no
  such limit.
- **Contract** (published 2.11.5, Core 2.15.1; `DefaultWorkflowTaskBridge`,
  `SignalWaits`, `ConditionWaits`, `PendingMessageTask`, `WorkflowStub`):
  - One completion may open any number of signal and condition waits, each
    with its own sequence, wait id and timeout timer.
  - Waits are derived from history. A wait closes by its own identity: a
    signal wait through `SignalReceived`/`SignalApplied` or
    `SignalWaitCancelled` for its id, a condition wait through
    `ConditionWaitSatisfied`, `ConditionWaitTimedOut` or
    `ConditionWaitCancelled`. A cancelled selection closes the waits in its
    member range, and a fired signal timeout closes its wait.
  - An accepted signal is buffered (`signal-command:<command>`) when an
    earlier signal of its name is still pending. Otherwise it reserves the
    latest open wait of its name (`signalWaitIdForAcceptedCommand`).
  - A received signal creates a workflow task when it matches an open signal
    wait by id, or when any condition wait is open with its timeout not
    fired.
  - The resumed task applies the signal at its own wait's sequence and
    cancels that wait's timeout (`recordAppliedSignalForSignalResume`). It
    then satisfies the latest open condition wait and cancels its timer
    (`recordSatisfiedConditionWaitForSignalResume`), whatever the signal's
    own wait.
  - The run summary names the latest open wait. The observation lists every
    durable wait.
  - `created_task_ids` includes a timed wait's timeout task. A signal task
    carries `WorkflowTaskPayload::forSignal`: `workflow_wait_kind: signal`,
    `open_wait_id: signal-application:<signal>`, `resume_source_id` and
    `workflow_command_id`.
- **Shared fixture.** `concurrent-waits`, over raw worker HTTP in its own
  namespace:
  - The mixed run opens `first`, `second` (timed) and a timed condition wait
    in one completion. A `second` signal resolves its own wait and the
    condition wait. A `first` signal then resolves its own wait, and the run
    completes.
  - The same-name run opens two `dup` waits. The first signal reserves the
    later wait; the second is buffered.
  - Four recordings against published 2.11.5 gave the same observation.
- **Native.** `open_waits` replaces the single latest-wait lookup. Signal
  reservation, delivery and resume application use it, and wait opening no
  longer refuses. Two older tests used the old refusal as a convenient
  batch refusal and now use the pending-timer limit and record both waits.
- **Observed, not recorded.** On published 2.11.5, a signal buffered behind
  an earlier same-name signal creates no workflow task even after the first
  signal is applied while another wait of its name is still open. Its
  buffered id never matches an open wait. The fixture stops before that
  point rather than freeze it; native behaves the same way.
