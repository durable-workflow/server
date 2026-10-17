# Timed condition waits

This records the existing published service contract for native implementation.
The [port log](../rust-server-port.md) owns implementation and qualification.
It does not change the Workflow package, database catalog or frozen references.

`open_condition_wait.timeout_seconds` is optional. Absence/null is untimed,
zero fires immediately, and a positive integer creates a durable timer.
Invalid or unrepresentable values refuse the entire authored command batch.
The existing default and named namespace admission remains in effect.

Each physical `ConditionWaitOpened` has its own durable wait identity and
authored sequence. The optional logical occurrence identity, condition key,
definition fingerprint and timeout accompany its `condition_timeout` timer.
Timer scheduling, task creation and the original deadline commit with the wait.
Zero records scheduled and fired history plus a workflow resume in that same
transaction, without an intermediate pending timer task. Positive timers retain
their planned availability and are fired once under the durable transition lock.

The timer resume carries `workflow_wait_kind=condition`, the original physical
wait identity as `open_wait_id`, `resume_source_kind=timer`, the timer identity
as `resume_source_id`, and the original condition/timer/sequence metadata.
`TimerFired` leaves the condition wait open with source status `timeout_fired`.
The worker evaluates the condition outcome; the service does not invent an
embedded-only `ConditionWaitTimedOut` event from the timer alone. A worker may
advance or complete using the fired timeout. Pending input cannot advance that
already fired physical condition wait.

Before firing, applying a signal records its original bytes and cursor advance,
then `SignalApplied`, `ConditionWaitSatisfied` and cancellation of the matching
pending condition timer. Cancellation retains the original scheduled identity,
deadline, condition occurrence/key/fingerprint and timer-kind metadata. An
unrelated timer is unaffected. Receipt alone neither satisfies nor cancels a
condition wait. A physical reopen after a signal creates a new wait/sequence and
timer, and may retain the same logical occurrence identity. The prior timer's
recorded deadline and cancellation remain unchanged; the new physical timer has
its own authored deadline, as in the published service bridge.

Buffered input does not eliminate condition timeout scheduling: the worker's
condition predicate is evaluated on replay. Immediate timeout creation can
precede application of that input. Later input stays buffered for a later
advanceable wait. Duplicate completions, stale leases, cold restarts and competing
timer drivers preserve one committed transition and the original identities.
Projection/history/task failure rolls back the whole transition.

One retained condition row supplies its timer deadline and task metadata; no
extra ordinary timer wait is created. Before firing it describes condition or
timeout; after firing it remains visible as waiting to apply the timeout. A
missing or inconsistent projection cannot gain authority through a read.
Terminal workflow history closes the remaining open wait. The bounded summary
uses the latest physical condition wait and preserves replay/lease-problem
precedence. Signal timeouts retain their separate received/timed-out resolution
rules.

Reference sources are `DefaultWorkflowTaskBridge::applyOpenConditionWait`,
`recordSatisfiedConditionWaitForSignalResume`, `cancelOpenConditionTimer`,
`RunTimerTask`, `WorkflowTaskPayload::forTimerResolution`, `ConditionWaits`,
`RunWaitView` and `RunSummaryProjector` in the published Workflow package.
