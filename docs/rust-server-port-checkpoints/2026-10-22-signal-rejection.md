# October 22, rejected signals at close

- **Gap.** Server 2.10.0 (Workflow 2.9.0) added `SignalRejected`. The
  Workflow package's `PendingSignalCloser` runs whenever a terminal event is
  recorded, and rejects each signal the run received but never applied.
  Native left those signals `received` after the run closed, with no event.
- **Source.** Recording a terminal event (`WorkflowCompleted`,
  `WorkflowContinuedAsNew`, `WorkflowFailed`, `WorkflowCancelled`,
  `WorkflowTerminated` or `WorkflowTimedOut`) now also rejects the run's
  received signals in PHP's order: command sequence (unsequenced last),
  receipt time, then id.
  - **Signal record.** It becomes `rejected` with outcome
    `rejected_not_active`, reason `run_closed_before_signal_applied`, and
    rejection and close times.
  - **Event.** One `SignalRejected` per signal, after the terminal event. It
    carries `workflow_command_id`, `signal_id`, `signal_name`,
    `signal_wait_id`, `command_sequence`, `rejection_reason` and
    `terminal_reason` (the run's closed reason), plus the command snapshot,
    as PHP records it with the signal's command.
  - **Command.** The signal command keeps its acceptance, as in PHP.
- **Remaining gap** (the port's gap list): 2.10.0's deferral of a
  completion while a deliverable signal waits, and continue-as-new carrying
  signals. Native still refuses continue-as-new while any signal is
  received, so its rejection never applies there yet.
- **Verification.** A new integration test sends two signals that no wait
  takes, terminates the run, and checks the two `SignalRejected` events
  after `WorkflowTerminated`, their payloads and command snapshots, and the
  exported signal records.
  - The buffered-condition test had encoded the 2.5.14 result, in which a
    signal the zero-timeout condition never took stayed `received` after the
    run closed. It now expects that signal rejected.
  - The signal and condition tests pass on SQLite, PostgreSQL and MySQL, and
    the rest of the SQLite suite passes.
