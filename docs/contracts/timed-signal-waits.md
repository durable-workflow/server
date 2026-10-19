# Timed signal waits

This specifies the existing portable `open_signal_wait` timeout contract for
native implementation. Implementation and qualification status belong in the
[port log](../rust-server-port.md).

`timeout_seconds` is optional. Absence or null opens an ordinary untimed wait.
Zero is an immediate timeout. A positive integer schedules a durable timer;
negative, non-integer and unrepresentable deadlines are refused atomically.
The wait and its timeout share one authored sequence and retain distinct
physical signal-wait and timer identities.

Opening records `SignalWaitOpened`. If an earlier matching input is buffered,
the wait reuses that input's signal-wait identity and creates no timeout timer,
including for zero. Otherwise `TimerScheduled` carries `timer_kind` equal to
`signal_timeout`, the original `signal_wait_id` and `signal_name`, sequence,
delay and planned fire time. Zero also records `TimerFired` and creates a ready
workflow task in the same transaction. A positive timeout creates a ready timer
task whose availability is the original planned fire time.

Firing preserves the original identities and records the actual firing time.
Its workflow resume payload uses `workflow_wait_kind=signal`, the signal-wait
identity as `open_wait_id`, `resume_source_kind=timer`, the timer identity as
`resume_source_id`, and the original timer, signal and workflow-sequence fields.
The typed fired event resolves a still-open signal wait as timed out. It does
not turn a previously received signal into a timeout or consume later input.

Receipt and application remain distinct. Receipt resolves the matching wait as
received. Worker application advances the message cursor and cancels a still
pending matching timeout before recording `SignalApplied`. An already fired
timer is not cancelled. Cancellation copies the original scheduled timer-kind,
wait and group metadata and original planned time, rather than inventing a new
deadline. It must not cancel an unrelated timer or alter received input bytes.

A signal received after a timeout has won remains buffered under its own wait
identity. Reopening a wait may consume that input; it cannot rewrite the expired
occurrence. Duplicate completions, stale workers, cold runtimes, competing timer
drivers and run cancellation preserve the original identities and one committed
outcome. A failed wait/timer/history projection write rolls back the owning
transition and leaves the original task usable.

The timeout is part of the signal wait's retained row, not an extra ordinary
timer wait. Before expiry its deadline and matching timer-task metadata remain
visible. A fired timeout closes that wait and identifies the workflow resume.
Missing projections remain unavailable and are never rebuilt by reads.

Condition waits are separate: a fired condition timeout remains open pending
worker evaluation. Their predicates, physical reopen history and original
deadline behavior require their own execution and projection controls.

The reference implementation is `DefaultWorkflowTaskBridge::applyOpenSignalWait`,
`RunTimerTask`, `TimerCancellation`, `WorkflowTaskPayload::forTimerResolution`
and `SignalWaits::forRun` in the published Workflow package. This work does not
change the package, native schema/catalog, frozen references or the admission
scope of other commands.
