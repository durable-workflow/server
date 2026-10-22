# October 22, continue-as-new carries pending messages

- **Found by.** The gap list's continue-as-new entry. Native refused
  continue-as-new (`continue_as_new_transfer_unqualified`) while a signal
  was received but not applied, or an update was accepted but not finished.
- **Source.** Workflow 2.9.0's `ContinuedRunMessageHandoff::closePredecessor`:
  - **Signals.** Received instance signals whose name the predecessor's
    start contract declares move to the continued run. The signal, and its
    own accepted instance command, point at the new run. The signal's wait
    becomes `signal-command:{command}` until a wait takes it.
    `SignalReceived` is recorded on the continued run with the arguments
    and codec.
  - **Updates.** Accepted instance updates not yet applied move the same
    way, and `UpdateAccepted` is recorded on the continued run without an
    ordering state.
  - **Order.** Command sequence (unsequenced last), then creation time,
    then id, after the continued run's `WorkflowStarted`.
  - **Leftovers.** The predecessor then closes. Signals without a declared
    handler are rejected (`run_closed_before_signal_applied`), and updates
    already applied but not completed are closed, as before.
  - **Summaries.** Run summary replay accepts an `UpdateAccepted` without
    an ordering state as ready.
- **Unchanged.** Open activities, timers, children, signal or condition
  waits, streams, service calls and live queries still refuse
  continue-as-new without a successor.
- **Tests.** `continue_messages` covers:
  - both events on the continued run, in order, with their fields;
  - no rejection on the predecessor;
  - the continued run's first task replaying them;
  - the next task delivering a moved message.

  The full SQLite execution suite passes (378). The continuation and update
  tests pass on PostgreSQL and MySQL (39 each).
