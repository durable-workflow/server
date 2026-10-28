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
    signal wait through `SignalApplied` or `SignalWaitCancelled` for its id,
    a condition wait through `ConditionWaitSatisfied`,
    `ConditionWaitTimedOut` or `ConditionWaitCancelled`. A `SignalReceived`
    for a wait holds it until the signal is applied. A cancelled selection
    closes the waits still open in its member range at that point of
    history, not one opened later or one a received signal holds. A fired
    signal timeout closes its wait. A wait recorded without an id is known
    as `signal:{sequence}:{name}` or `condition:{sequence}`.
  - An accepted signal is buffered (`signal-command:<command>`) when an
    earlier signal of its name is still pending. Otherwise it reserves the
    latest open wait of its name (`signalWaitIdForAcceptedCommand`).
  - A new signal wait takes the identity of the earliest pending signal of
    its name that no opened wait has taken yet, else a fresh one
    (`pendingSignalWaitIdForOpenSignalWait`). Two waits never share one.
  - A received signal creates a workflow task when it matches an open signal
    wait by id, when any condition wait is open with its timeout not fired,
    or when the run's durable contract declares its name
    (`UnprojectedSignalDelivery`).
  - The resumed task applies the signal at its own wait's sequence and
    cancels that wait's timeout (`recordAppliedSignalForSignalResume`). A
    buffered identity that names no opened wait is rebound to the latest
    open wait of the signal's name (`openWaitIdForName`), and the signal
    record keeps that identity. The task then satisfies the latest open
    condition wait whose timeout has not fired, and cancels its timer
    (`recordSatisfiedConditionWaitForSignalResume`, `latestOpenConditionWait`),
    whatever the signal's own wait. With no such condition wait, an
    undeclared signal without a wait stays received.
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
    later wait; the second is buffered. Nothing delivers the buffered
    signal: its identity names no open wait and `dup` is undeclared.
  - The declared run is the same with `dup` declared: the buffered signal is
    delivered and applied at the earlier wait's sequence and identity.
  - The reopen run is the same, but the resumed task opens a third `dup`
    wait. That wait takes the buffered identity and the signal resumes it.
  - Four recordings of the first two runs and six of all four against
    published 2.11.5 gave the same observation. Three native recordings
    pass it.
- **Native.** `open_waits` replaces the single latest-wait lookup. Signal
  reservation, delivery and resume application use it, and wait opening no
  longer refuses. Each signal and condition wait projection row records its
  opening event, so `open_waits` reads the run's open rows through the
  status index, with one query for the rarer closings (wait cancellation,
  condition timeout, cancelled selection). An imported run, a row without
  that record (a wait PHP opened) and a cancellation of a received wait are
  read from history, in order. Two older tests used the old refusal as a
  convenient batch refusal and now use the pending-timer limit.
- **Correction.** The first version of this checkpoint said 2.11.5 never
  delivers a buffered signal while another wait of its name is open. That
  holds only for an undeclared signal with no later wait of its name. A
  declared signal is delivered and rebound to the open wait, and a later
  wait takes the buffered identity; both are recorded above.
- **Observed differences outside this change**, recorded against 2.11.5 in
  a lab and left for their owners:
  - A signal accepted while a workflow task is leased reserves its open
    wait. PHP's completion (`createForRun` with
    `includeReceivedProjectedSignalWait: false`) does not count a received
    wait, so it creates no task for that signal. Native does; `main` already
    behaved this way with one wait.
  - Native's task for a fired workflow timer omits `resume_source_kind` and
    `resume_source_id`; PHP sends `timer` and the timer id.
  - Laravel model ids, including command ids and so buffered
    `signal-command:` identities, are lowercase in PHP and uppercase in
    native. The fixture compares labels, not ids.
