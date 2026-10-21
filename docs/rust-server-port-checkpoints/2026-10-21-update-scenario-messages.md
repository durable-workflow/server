# October 21, signal values and retried message tasks

- **Found by.** The Sample App's update scenario (`scripts/sdk-updates.sh`)
  against native, in two runs:
  - In one run, the Rust workflow failed the workflow task for its
    `snapshot` update. Native refused the failure report with `422
    development_workflow_task_retry_messages_unqualified`, the Rust SDK
    stopped its worker, and the accepted update was never applied.
  - In another run, the Rust SDK refused the scenario's snapshot query with
    "cannot decode committed workflow signals: conflicting committed signal
    identity".

  Both handlers read the run's committed signals.
- **Signal values.** Published PHP's `signalValueFromRecord` applies a
  signal with no arguments as `true`, one argument as itself and several as
  their list, and records that value in `SignalApplied`. Native recorded
  null for a signal without arguments. The Rust SDK compares each applied
  value with the received arguments, so a history holding the scenario's
  argument-less signal was refused. Native now records `true`.
- **Retried message tasks.** Published PHP answers a reported workflow task
  failure by marking the task failed. Unless the failure blocks replay, it
  creates a ready retry task with the same payload, including any update or
  signal the task carries. Native refused such a report whenever the run
  had pending messages. It now retries the task as PHP does. The
  waiting-for-history acknowledgement with pending messages keeps its
  refusal.
- **Verification.**
  - New tests cover:
    - the applied values of signals with zero, one and two arguments;
    - a failed update task, retried with its update and then completed once;
    - a failed signal task, retried with its signal.
  - The signal and retry suites pass on SQLite.
