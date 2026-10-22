# Activity and timer all-groups

This records the published `all()/parallel()` service contract for the native
implementation under Server #325. It adds no Workflow API, schema or frozen tuple.

A barrier may contain activities, timers, or nested barriers of those operations.
Published `AllCall::leafDescriptors` assigns every leaf a full
`parallel_group_path`; top-level parallel fields repeat the innermost entry.
Each entry keeps its canonical kind/id, positive authored base and size, and
zero-based leaf offset. Activity-only, timer-only and mixed IDs respectively use
`parallel-activities`, `parallel-timers` and `parallel-calls`. The default mode
is `all` and its normalized history omits that mode and selection-only fields.
The existing command-batch limit applies to the flattened leaves.

All new leaves are admitted atomically in authored order. Every represented group
must be complete, with each physical leaf occupying its original sequence.
Malformed paths, incomplete groups, conflicting identities and sequence mismatch
must preserve the original history and lease. Nested paths retain every ancestor;
normalization must not flatten away the worker's replay structure.

Each activity/timer retains its own durable identity, original arguments, attempt,
queue, deadlines and full group path throughout scheduling and resolution.
An all-group emits no `SelectionResolved`: its worker evaluates the barrier from
actual member outcomes. As in published PHP
(`ParallelChildGroup::shouldWakeParentOn*Closure`, and Server's
`ServiceModeParallelChildBarrierContractTest`), a member that closes
successfully resumes the worker only once every member of every enclosing group
has closed successfully. A member that fails or times out resumes it at once,
and the group's later successful closures do not resume it again. Ready wakeups
may coalesce, while a later completion must retain a wakeup when an earlier worker
snapshot is leased. A waiting acknowledgment must not discard that later work.
The workflow cannot complete while any durable member is still pending.

Activity retry keeps the original physical activity and path, with a new fenced
attempt/task and authored backoff. Retry alone does not resume the workflow.
Terminal activity failure and timeout are actual member outcomes, not selection
winners. Completion, retry, failure, timeout and cancellation retain their existing
receipt, fencing and transaction rules. Cold reopens and competing nodes must not
lose members or commit duplicate outcomes.

Observation retains all individual waits in published order. An authoritative
open activity takes liveness precedence over timers, followed by the selected
workflow task. Replay/lease problems retain their existing precedence. Reads do
not repair projections. Group metadata and fan-out counts come from typed history,
without creating a selection result. Projection/history failures roll back the
whole transition.

Published wait ordering compares rounded millisecond opening timestamps
(`Carbon::getTimestampMs`), then the original wait ID. Native microsecond ordering
is a contract disagreement when multiple members open in the same millisecond.
Correct the ordering key without changing any stored timestamp or identity.
An activity wait uses the actual canonical scheduled-event clock, or its latest
started-event clock after a claim. The earlier scheduling clock still owns its
authored deadlines and is not substituted for the history event's opening time.

Source qualification for this component covers activity/timer all-groups. Child,
signal and condition leaves, groups inside select, named activity/timer admission,
installed consumers and whole-source acceptance remain separate gates. They are
not silently reinterpreted as the supported shapes.

References: published Workflow `AllCall`, `ParallelChildGroup`,
`DefaultWorkflowTaskBridge::normalizeParallelCommandMetadata`,
`parallelCommandsMatchSequences`, `RunActivityView`, `RunTimerView`,
`RunWaitView` and `RunSummaryProjector`.
