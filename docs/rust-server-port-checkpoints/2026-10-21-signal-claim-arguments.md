# October 21, signal arguments in workflow task claims

- **Found by.** The Sample App's search attribute scenario against native.
  The Rust workflow was parked at a signal wait, its worker was killed, and
  the signal `["released"]` arrived while no worker was running. The
  replacement worker returned `signal: []`.
- **Cause.** Published PHP's `WorkflowTaskPoller::workflowTaskResumeContext`
  gives a signal task's claim `signal_arguments`, the signal record's
  argument envelope, along with `workflow_signal_id` and
  `workflow_command_id`. The Rust SDK resumes the wait with
  `signal_arguments`. Native sent `signal_name` without the arguments, so
  the SDK resumed the wait with none.
- **Native now.** The claim of a signal task carries `signal_arguments`,
  `workflow_signal_id` and `workflow_command_id`. The command ID comes from
  the signal record when an older native task payload lacks it.
- **Verification.** A new test in `tests/execution/signal_claims.rs` claims a
  signal task and checks the three fields against the signal request. The
  signal and claim tests pass on SQLite.
