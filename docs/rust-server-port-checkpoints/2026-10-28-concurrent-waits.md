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
- **Native.** One history-derived view (`wait_history.rs`) replaces the
  single latest-wait lookup, folding the run's wait-related history as
  `SignalWaits::forRun` and `ConditionWaits::forRun` do. Signal
  reservation, delivery, resume application and the "already opened" check
  for a new wait all read it, so they cannot disagree. Wait opening no
  longer refuses. The wait projection is written as before and no longer
  read for these decisions. Two older tests used the old refusal as a
  convenient batch refusal and now use the pending-timer limit.
- **Cost.** Like Core, each decision reads the run's wait-related history:
  one query through the `workflow_run_id` index, limited to the wait,
  signal, condition, selection-cancellation and timer event types and to
  their sequence, type and payload columns, with no per-wait queries. A run
  with a long wait history pays for it on each signal and wait decision,
  as PHP does.
- **Second review** (Core 2.15.1 source; lab recordings against 2.11.5):
  - Over the worker protocol Core records no `ConditionWaitTimedOut`; only
    its in-process executor does. A fired condition timeout records
    `TimerFired`, and the wait stays open with `source_status:
    timeout_fired`: no signal advances or satisfies it, and the latest
    open condition wait skips it. Native does the same. Native refuses a
    signal task whose signal nothing can take (`409`), unless the signal
    names a wait the run has had, or a condition wait timed out after the
    signal arrived. A stale fired wait no longer excuses it.
  - Delivery counts a wait a received signal holds only when a signal or
    update is accepted, not after a task completes
    (`includeReceivedProjectedSignalWait`). The earlier native behavior
    delivered it after the completion too, which `main` already did with
    one wait. A signal accepted while a task is leased is therefore not
    delivered by that task's completion, as recorded on 2.11.5.
  - Applying a signal looks for the `SignalWaitOpened` that names its
    identity, whatever has happened to that wait since. A wait known only
    by a derived id (`signal:{sequence}:{name}`) is never named that way,
    so its reserved signal is delivered once and left received.
  - An id-less `SignalReceived` holds the oldest open wait of its name and
    an id-less `SignalApplied` takes the next open one (`consumeOpenWaitId`).
    A `SignalWaitCancelled` without an id cancels nothing. A condition
    closing without an id resolves `condition:{sequence}`.
  - A wait that took a pending signal's identity is open, not held, so a
    cancelled selection or wait cancellation closes it and the signal stays
    received, as in Core.
  - A signal stored without a wait id binds to no wait. It is delivered
    only through an open condition wait or its declared name, and it still
    counts as pending when a later signal of its name arrives.
  - `ConditionWaitSatisfied` is built from the wait and the resuming task
    (identity, key, fingerprint, occurrence, sequence, timeout timer and
    seconds, the task's signal fields, its parallel group). It no longer
    copies the opening event.
  - Acceptance counts pending signals with the same predicate as delivery
    and wait opening (received and not closed). Core counts accepted,
    unapplied signal commands; the two agree for every record native
    writes.
- **Final review** (Core 2.15.1 source; lab recordings against 2.11.5):
  - A completion offers the run's next message after the signal its task
    attempted (`createForRun($run, workflowSignalIdForTask($task), false)`,
    `signalFollows`), so a signal the task left received is not handed
    straight back to it. The waiting acknowledgement passes no attempted
    signal, as Core's does.
  - Delivery returns before reading the run's waits when no signal is
    received (`nextEligibleSignal`).
  - `SignalApplied` and `ConditionWaitSatisfied` carry the opening's
    parallel group (`payloadForPath(metadataPathFromPayload($opened))`, the
    existing port of Core's normalization). The opening is the first
    `SignalWaitOpened`/`ConditionWaitOpened` naming the wait's id; its
    sequence counts only as an integer.
  - Selection over waits: on 2.11.5 a worker may open waits as selection
    members, and the first member to resolve records `SelectionResolved`;
    the losing wait stays open and takes its own signal. Native applies a
    signal or condition to such a member and records `SelectionResolved`
    the same way, for waits a taken-over history opened. Native does not
    open waits as group members: that batch keeps its explicit
    `422 development_selection_shape_unqualified`, a recorded divergence
    the shared fixture therefore does not carry. A member of a nested
    selection, or one wider than a sequence, gets the same refusal when its
    signal applies.
  - A condition wait that consumes a signal leaves the signal's recorded
    identity as it was (`markConditionWaitSignalConsumed`).
  - The fold indexes waits by id, so it is linear in the history it reads.
- **Correction.** The first version of this checkpoint said 2.11.5 never
  delivers a buffered signal while another wait of its name is open. That
  holds only for an undeclared signal with no later wait of its name. A
  declared signal is delivered and rebound to the open wait, and a later
  wait takes the buffered identity; both are recorded above.
- **Observed differences outside this change**, recorded against 2.11.5 in
  a lab and left for their owners:
  - Native's task for a fired workflow timer omits `resume_source_kind` and
    `resume_source_id`; PHP sends `timer` and the timer id.
  - Native's signal task payload omits PHP's `condition_wait_id: null`.
  - Laravel model ids, including command ids and so buffered
    `signal-command:` identities, are lowercase in PHP and uppercase in
    native. The fixture compares labels, not ids.
