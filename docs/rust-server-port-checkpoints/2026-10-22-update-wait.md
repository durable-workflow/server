# October 22, update completion wait

- **Found by.** The environment contract audit. A completion wait for an
  update without its own `wait_timeout_seconds` lasted 30 seconds natively
  and reported no timeout. Native also refused any
  `DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS`.
- **Source.** Workflow 2.9.0's `UpdateWaitPolicy` and
  `WorkflowStub::attemptUpdateWithArguments`:
  - **Default.** The setting, or its legacy
    `WORKFLOW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS`, is cast with
    `(int)` and raised to at least one second. Unset means 10.
  - **Wait.** A `wait_for=completed` request waits for its own timeout, or
    else that default.
  - **Report.** It reports the timeout it used as `wait_timeout_seconds`. A
    `wait_for=accepted` request reports none, even a requested one, as
    `submitUpdateWithArguments` does.
- **Tests.** `update_wait` covers both reports and a completion wait that
  returns when its update completes. The audit unit tests cover the setting's
  reading, including the legacy name, `0`, a non-number and `null`. The
  update tests pass on SQLite.
