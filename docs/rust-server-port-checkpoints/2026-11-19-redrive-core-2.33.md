# November 19, failed-run redrive follows Workflow 2.33.0

Server #980. Native redrive and describe's `can_redrive` /
`redrive_blocked_reason` now decide a failed run by embedded Workflow
2.33.0's rules (`FailedRunRedrivePlan`, `ActivityRetryHistory` and
`FailedRunRedriveEligibility`, tag 2.33.0, commit `83c881d8`). The plan lives
in `rust/src/runtime/redrive_plan.rs`; `redrive.rs` keeps the eligibility
order and the successor writer.

## Already served before this change

Released in 3.10.0 and 3.11.0, unchanged here:

- start-time and inherited memos and search attributes carry to the
  successor, and a value workflow code upserted must come from a reused
  upsert step (`unsupported_visibility_metadata`);
- describe reports the same decision redrive applies, `run_not_failed` and
  `already_redriven` included;
- side effects, version markers and fired plain timers before the failed
  step are reused with `reused_from_run_id` and the first `reused_recorded_at`.

## What changed

Histories native refused with `unsupported_history` or `unexpected_start`
are now read as Core reads them:

- **Retried activities** (Core workflow#1131). `ActivityRetryScheduled` is
  part of an activity step. A retried step is accepted when its attempts form
  Core's lineage: one execution, attempts numbered from 1, each on its own
  task, each retry naming the attempt and task it follows and the task the
  next attempt runs on, `max_attempts` above the attempts made, and any
  `activity_attempt` snapshot agreeing. Otherwise the run refuses as
  `invalid_activity_retry_history`. A completed retried activity is reused; a
  failed one is the resumed step. Native's own retry history satisfies the
  lineage, so a run whose failed activity exhausted its retries can now be
  redriven. This is the common case the earlier rules refused.
- **Start adopted under `use-existing`**. The accepted `StartAccepted`
  receipt native records on an active run is not a second start when its
  command snapshot, run, instance, class, type and command id agree
  (`isActiveStartAdoption`). Anything else stays `unexpected_start`.
- **Message cursor bookkeeping**. `MessageCursorAdvanced` is not a step.
- **Signal waits, updates and child calls**. They are validated with Core's
  reasons: `unsupported_signal_wait`, `incomplete_signal_step`,
  `mismatched_signal_receipt`, `external_signal_payload_not_supported`,
  `unsupported_pending_signal`, `mismatched_update_history`,
  `unsupported_pending_update`, `incomplete_update_step`,
  `external_update_payload_not_supported`, `unavailable_update_payload`,
  `unsupported_child_outcome`, `unsupported_open_child`,
  `unsupported_child_workflow`, `unavailable_child_result` and
  `external_child_result_not_supported`. Reuse provenance follows Core: an
  event reused once names the immediate redrive source and an earlier run of
  the instance. A valid applied signal wait, applied update or completed child
  call is a reused step that no service SDK replays yet, so a worker-sourced
  run refuses as `service_redrive_history_not_qualified` before anything is
  written, as Core refuses it in service mode. A rejected update, or an
  accepted update the run's failure closed before it ran, is not a step and
  does not block redrive.
- **Failure kinds**. `failed_step_kind` may be `activity`, `workflow_code` or
  `structural_limit`, as Core records it. For the last two, the boundary is
  the step workflow code never took, so the steps before it are reused and no
  activity may sit at or after it (`invalid_failed_step`). A
  `workflow_code` failure with an unchanged definition refuses as
  `workflow_code_failure_requires_corrected_definition`.
- **Activity results**. A reused completion needs its `result` and a
  `payload_codec` (`missing_activity_result`), as Core requires. An inline
  string result is accepted as Core accepts it. An Avro result that does not
  decode refuses as `unavailable_activity_result`.

The successor writer is unchanged: it records only activities, markers and
timers, which are the only reused steps a native redrive can accept.

## Intentional differences from Workflow 2.33.0

Registered as `redrive-core-2.33-rules` in
`scripts/conformance/server-parity/native-divergences.json`:

- Core's `allow_definition_change` is not a redrive request field. In Core
  it changes a definition only for an embedded class; for a service run it
  answers `definition_change_requires_replay_check`. Native has no embedded
  class, so it gives Core's answer without the option:
  `workflow_definition_unavailable_or_changed`.
- A run without a worker-sourced fingerprint (an imported embedded run whose
  class equals its type) is replayed by a service worker on native too, so
  reused signal, update, child and upsert steps refuse as
  `service_redrive_history_not_qualified` instead of being copied.
- Only the Avro codec is decoded to check reused payloads. A payload in
  another codec is replayed by the SDK that wrote it and is not checked here.
- Native message streams keep their instance-scoped cursor
  (`workflow_inbound_streams`), so there are no per-run `workflow_messages`
  rows to move to the successor (Core #1013).

No schema change. Protocol, control-plane and response shapes are unchanged.

## Verified

- `cargo test --lib redrive`: the plan's unit tests, including two parity
  tests over Core 2.33.0's replay-regression corpus, copied verbatim with
  each file's SHA-256 in `rust/tests/fixtures/redrive-core-2.33.0.json`:
  - every `redrive_plan` scenario (`redrive-retried-failed-activity`,
    `redrive-retried-completed-activity`,
    `redrive-retried-activity-after-completed-child`,
    `redrive-native-active-start-adoption`) reaches Core's plan, resume
    step, reused step types and completion count, and each of its 28 recorded
    refusal mutations refuses with Core's reason. The reused child call is
    reported as not service-qualified, so a worker-sourced run with it refuses
    as Core's service mode does;
  - the successor histories Core recorded for
    `redrive-reused-signal-wait-clock`, `redrive-reused-child-workflow`,
    `redrive-reused-markers-clock` and `redrive-reused-timer-clock`, failed
    again, are the source of a second redrive under Core's provenance rules.
- `cargo test --test execution redrive`: on SQLite, PostgreSQL 17, MySQL 8.4
  and MariaDB 10.11, with three new cases over histories native records
  itself: retried activities, an applied signal wait and an applied update,
  and an adopted duplicate start.
