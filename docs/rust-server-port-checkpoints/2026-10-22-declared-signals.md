# October 22, declared signals without an open wait

- **Gap.** Native delivered a received signal only to an open signal or
  condition wait. Published PHP delivers more for a run whose workflow class
  the server cannot load, which is every external-worker run. There it
  delivers any received signal that the run's durable command contract
  declares, wait or no wait. Native also refused a waiting-for-history
  acknowledgement while a signal was pending, and for a signal task.
- **Source.** Workflow 2.9.0's `PendingMessageTask`, the bridge's
  `recordAppliedSignalForSignalResume`, and Server 2.10.0's
  `acknowledgeWorkflowTaskWaitingForHistory`. Every native run is an
  external-worker run, so the declared path always applies.
  - **Eligibility** (`signalCanAdvanceRun`). A received signal can advance
    the run in three cases:
    - an open condition wait whose timeout has not fired;
    - an open signal wait that takes that signal;
    - the run's durable-history contract declares its name.

    Signals are considered in PHP's order: command sequence (unsequenced
    last), receipt time, then id.
  - **Delivery.** With no open workflow task, such a signal gets its own
    task (`createForRun`). An earlier unapplied signal still holds back a
    later update.
  - **Holding a close.** A worker's completion or continue-as-new waits while
    such a signal follows the task's own signal (`hasDeliverableSignal`). On a
    run with a durable contract, every admitted signal is declared, so any
    pending signal holds the close. A continue-as-new therefore carries a
    declared signal only when the hold does not apply, for example under a
    cancellation request.
  - **Application.** A signal with no wait that takes it is applied without
    a workflow sequence. The command and signal record are marked applied,
    the message cursor advances, and `SignalApplied` has no `sequence`.
    Waits and timers are untouched.
  - **Acknowledgements.** A waiting-for-history acknowledgement of a signal
    task applies its signal, as PHP completes that task with no commands. The
    SDKs send this acknowledgement when a task yields no new commands. A
    pending signal no longer refuses an acknowledgement: it gets its task
    afterwards if it can advance the run. An update task's acknowledgement
    still refuses, as before.
- **Tests.**
  - New `declared_signals.rs`:
    - a declared signal reaching a run that waits on a timer gets its own
      task, and is applied without a sequence through the SDKs'
      acknowledgement;
    - no task for a signal of a run without a durable contract;
    - an undeclared signal is still refused;
    - a declared signal received during the closing task holds the
      completion, then is applied, and the run completes without rejecting
      it.
  - Existing tests now follow PHP:
    - the waiting-history test delivers the signal after the
      acknowledgement;
    - the continue-as-new handoff test first delivers the declared signal,
      then carries only the update;
    - the zero-timeout buffered condition delivers its signal instead of
      rejecting it at close;
    - the deferral, signal-wait and timer summary-authority tests use runs
      started before any contract, where a signal the open wait cannot take
      still has no task.
- **Results.**
  - Full SQLite suite: 383 passed. Its one failure, the timer summary test,
    passed in the rerun below after the change above.
  - Declared-signal, waiting, signal, condition, continue-as-new and
    update subsets: PostgreSQL 104, MySQL 104.
  - Run observation, timer and signal-wait tests: 23 on each database.
