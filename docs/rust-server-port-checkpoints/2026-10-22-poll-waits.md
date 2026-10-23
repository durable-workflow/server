# October 22, worker poll waits and operation names

- **Gap.** A workflow or activity poll without `timeout_seconds` waited 5
  seconds; PHP waits `DW_WORKER_POLL_TIMEOUT` seconds, 30 by default. A
  query-task poll without one also waited 5 seconds where PHP starts from
  the same setting. Native refused an explicit `null` wait, which PHP's
  `nullable` rule accepts.
- **Source.** Server 2.10.0's `LongPoller::until`,
  `WorkflowQueryTaskBroker::queryPollTimeoutSeconds` and
  `WorkflowController::validateOperationName`.
- **Poll waits.**
  - A workflow or activity poll without a wait, or with `null`, waits
    `max(0, DW_WORKER_POLL_TIMEOUT)` seconds. The setting is not capped.
  - A query-task poll waits the requested time or that setting, capped by
    `DW_QUERY_TASK_POLL_TIMEOUT` (default 5); zero answers at once.
  - A requested wait passes Laravel's `integer` rule (so `"5"` is five
    seconds) and must lie from 0 to 60.
- **Operation names.**
  - Signal, update and query requests check the name first, after the
    payload transport check, as PHP's controller does. Empty names, names
    over 256 bytes and names with ASCII control characters get PHP's
    `validation_failed` errors and messages.
  - PHP's signal and update name columns hold 255 bytes. Native keeps its
    storage guard and refuses a 256-byte or all-space name with
    `invalid_signal_name` or `invalid_update_name`. Query names are not
    stored in those columns, so a 256-byte query name is accepted.
- **Fixed along the way.** A query poll from a worker registered without
  `capabilities` answered 503 `storage_unavailable`, because the claim read
  the missing list as required. It now polls empty, as PHP's `stringArray`
  does.
- **Tests** (`poll_waits.rs`).
  - Workflow, activity and query polls without a wait, or with `null`, take
    the configured second.
  - A zero setting and a requested `"0"` answer at once.
  - Requested waits of 61, -1, `"soon"` and 1.5 are refused.
  - A task started during a default wait is claimed.
  - A 255-byte signal name is accepted; 256-byte signal and update names
    are refused with the storage reasons. Too-long names and names with
    control characters get PHP's messages for each kind.
  - Full SQLite run (all targets): 228 unit, 397 execution and 2 upgrade
    tests passed. Poll, signal, update and query tests: PostgreSQL 122,
    MySQL 122.
