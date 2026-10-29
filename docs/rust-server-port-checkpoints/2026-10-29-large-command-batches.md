# October 29, completions with more than 100 commands, and groups over 100 members

- **Before.**
  - Native refused a workflow task completion with more than 100 commands
    with `422 invalid_commands`. The cap was in two places: the completion
    itself (`store.rs`) and the preparation of its external command
    payloads (`external_resolution.rs`).
  - The timer, activity and external-wait summaries gave up past 100 open
    waits (`timer_summary.rs`, `activity_summary.rs`,
    `external_wait_summary.rs`). A run with more than 100 was therefore
    described without its wait.
- **Contract** (published 2.11.5; `WorkerController::completeWorkflowTask`,
  Core 2.15.1 `WorkflowCommandNormalizer`, `StructuralLimits`):
  - `commands` must be a non-empty array, with no count limit of its own.
    `StructuralLimits::guardCommandBatchSize` is used only by the embedded
    executor.
  - Each command meets the run's structural limits instead: pending
    activities and timers, 2,000 each by default.
  - The run is described as waiting on its first open wait, however many
    are open.
- **Fixture.** `large-command-batches`, raw worker HTTP on protocol 1.20 in
  a fresh namespace. Each case completes a run's first workflow task with
  150 `start_timer` or 150 `schedule_activity` commands. It records:
  - the completion answer (200, run waiting, 150 created tasks);
  - the run's description (`wait_kind` timer or activity, with its reason);
  - the history counts by type, read in pages.

  A 1,001-command case is left out: published PHP answered
  `409 stale_worker_registration` for it on SQLite, so it gives no usable
  reference.
- **Native.**
  - Both command caps are removed; a completion must still be non-empty.
  - The three summaries no longer stop at 100 open waits.
- **Parallel groups.**
  - **Before.** Native capped a group's declared size at 100 members
    (`all_groups.rs`). It answered any invalid group metadata with
    `422 development_parallel_group_unqualified`.
  - **Contract.** `WorkflowCommandNormalizer::preflightParallelMetadata`
    runs in `WorkerController` after the transport rules and integer
    normalization, before the controller's scopes and any ownership guard.
    It casts digit strings in the parallel integer fields, refuses parallel
    fields on a non-leaf command, and checks each leaf's metadata
    (`optionalParallelMetadata`). It collects errors across the batch, with
    one of three messages. A group's declared size is bounded by
    `StructuralLimits::commandBatchSizeLimit` (1,000 by default).
  - **Fixture.** An all group of 150 activities, each with a one-entry
    `parallel_group_path`, is accepted like the plain batch. A group
    declaring 1,001 members (two commands) is refused
    `422 validation_failed`, with an error on each command, and records
    nothing.
  - **Native.** `completion_rules.rs` ports the preflight at the same point
    in `checked`. The batch-size limit is one shared constant
    (`memo_limits::COMMAND_BATCH_SIZE`), used by the group, cooperative
    cancellation and cancellation scope checks. A valid group that native
    does not run (for example a child group) is still refused later with
    `development_parallel_group_unqualified`.
- **Tests.** `large_command_batches.rs` (150 timers and 150 activities
  accepted, the run described as waiting on its wait; a 150-member group
  accepted and a 1,001-member group refused on both commands). Both fail on
  the previous source.
