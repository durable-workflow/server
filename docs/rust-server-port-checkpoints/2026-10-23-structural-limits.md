# October 23, structural limits on signals, updates and activity results

- **Source.** Workflow 2.9 `StructuralLimits`, `StructuralLimitExceededException`,
  `WorkflowStub` (signal and update intake, `rejectOversizedSignal`,
  `rejectUpdateCommand`), Server 2.11.1 `ExternalWorkflowUpdateAdmission`
  and `ActivityOutcomeRecorder`.
- **Settings.** `DW_V2_LIMIT_PENDING_SIGNALS` (default 5000),
  `DW_V2_LIMIT_PENDING_UPDATES` (500) and `DW_V2_LIMIT_PAYLOAD_SIZE_BYTES`
  (2097152), with their `WORKFLOW_V2_LIMIT_*` names. They are read with the
  same casts as the memo limit. A value of zero or less disables that check.
  Native used to refuse to start when one was changed. It now honors all
  three, and `structural_limits.configured` in the operator metrics reports
  them, and the configured memo limit, as PHP's snapshot does.
- **Published behavior.** Observed on a fresh 2.11.1 SQLite server with
  limits of 2 signals, 1 update and 300 bytes:
  - **Signals.** After validation, a signal is refused when the run already
    holds that many received signals, then when its serialized arguments
    are longer than the payload limit. Either answers 409 with
    `reason`/`rejection_reason` `structural_limit_exceeded`, `outcome` null
    and `validation_errors` `[]`. A rejected command and signal row are
    recorded, with no history event. An oversized signal keeps no
    arguments. Its command carries the limit's kind, value and configured
    value, and its signal row carries the limit's message.
  - **Updates.** After validation, an update is refused when the run
    already holds that many accepted updates, then when its serialized
    arguments are too long. Either is a durable rejection: a rejected
    command and update row with no arguments, and an `UpdateRejected` event
    with the limit's message as `validation_errors.arguments`. The 409
    answer has `outcome` null and also carries `structural_limit_kind`,
    `structural_limit_value` and `structural_limit_configured`. Retrying
    the same `request_id` returns that answer again.
  - **Activity results.** A completed result longer than the payload limit
    fails the activity instead, with no retry: `ActivityFailed` with
    category `structural_limit`, `non_retryable` true,
    `Workflow\V2\Exceptions\StructuralLimitExceededException`, the limit's
    message and fields, and a `structural_limit` failure row. The worker
    still receives `outcome` `completed` and `recorded` true.
- **Evidence.** The same script ran against the published image and this
  branch, with the same limits. Signals, updates (including the retried
  request) and the activity result produced the same statuses, outcomes,
  reasons, messages, limit fields, rows and history events. The measured
  payload was 556 bytes on both.
- **Native before.** Native applied no pending-signal or pending-update
  limit. It refused update arguments over 64 KiB with a 413
  `update_arguments_exceed_budget` that left no record. PHP has no such
  budget: the payload limit rejects them durably. That native budget is
  removed.
- **Tests.** `structural_limits` covers the three cases on SQLite,
  PostgreSQL and MySQL. Unit tests cover the setting casts and messages.
- **Remaining differences.**
  - PHP applies the pending-activity, pending-child and pending-timer limits
    only when its own PHP workflows run, not to external workers' commands.
    Native's handling of those settings and of the command batch size is
    unchanged.
  - Native still caps a run at 64 updates over its lifetime, with a 503
    `update_inventory_exceeds_budget`. PHP has no lifetime cap.
  - PHP's update answer names the authenticated `principal`, and its
    `UpdateAccepted` event omits a null `request_id`. Native's answer has no
    `principal`, and its event keeps `request_id` null. Both predate this
    change.
