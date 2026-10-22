# October 22, activity task lease

- **Found by.** The environment contract audit. Native leased every claimed
  activity task for 300 seconds and refused any other
  `DW_ACTIVITY_TASK_TIMEOUT`. Published PHP applies the setting.
- **Source.** Server 2.11's `ActivityTaskLeaseConfiguration::apply`:
  - **Validation.** The setting, or its legacy `ACTIVITY_TASK_TIMEOUT`,
    must pass `FILTER_VALIDATE_INT` from 1 to 86400. Surrounding whitespace
    and a sign are allowed; a leading zero, a boolean, null or an empty
    value is not. Otherwise startup stops with PHP's message,
    "DW_ACTIVITY_TASK_TIMEOUT must be an integer between 1 and 86400
    seconds." Unset means 300.
  - **Use.** The value sets an activity claim's lease and each heartbeat's
    renewal. A run with a cancellation request keeps native's shorter
    cancellation lease, as before.
  - **Metrics.** Operator metrics' matching-role `discovery_limits` report
    the configured activity and workflow task leases, as PHP's
    `MatchingRoleSnapshot` does. Native used to report a fixed 30-second
    workflow lease, although it leases for `DW_WORKFLOW_TASK_TIMEOUT`
    (default 60).
- **Tests.** The audit tests cover accepted values (`300`, ` 30 `, legacy
  `86400`) and PHP's refusals (`0`, `86401`, `030`, `true`, empty, `5m`).
  The activity, heartbeat and operator-metrics execution tests pass on
  SQLite (44 tests).
