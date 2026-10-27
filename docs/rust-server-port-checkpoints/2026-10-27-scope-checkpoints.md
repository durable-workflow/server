# October 27, native serves cancellation scope prefix checkpoints

- **The route.** `POST /api/worker/workflow-tasks/{taskId}/cancellation-scopes/checkpoint`
  was the last published PHP route native answered
  `501 rust_capability_not_implemented`. Native now answers all 175 API
  route methods in the published route table.
- **Why it blocked the release candidate**
  ([#325 comment 6449500902](https://github.com/durable-workflow/server/issues/325#issuecomment-6449500902)).
  - The published PHP SDK (3.6.1) calls it under cooperative cancellation,
    which native advertises (`cooperative_cancellation: true`). It does so
    when a turn recorded commands (a side effect, a version marker, a memo
    or search-attribute upsert) before it opens or delivers a scope.
  - The SDK turns any non-transient refusal into `WorkflowClaimAborted`
    and fails the task. Replay is deterministic, so the workflow is stuck.
  - A typed 409 refusal could not be made consistent. In Core, open,
    prepare, deliver and checkpoint sit on one `CancellationScopeTaskBridge`,
    and no capability flag tells an SDK to skip the checkpoint.
  - The Python SDK (2.12.0), the Rust SDK (4.10.1) and the CLI (2.3.1) never
    call it.
- **Contract.** Server's `checkpointCancellationScopePrefix` and
  `mutateWorkflowTaskCommands`, over Core 2.15's
  `DefaultWorkflowTaskBridge::checkpointCancellationScopePrefix`
  (`checkpointCommands`). In order:
  - protocol 1.20, or `409 cancellation_scope_checkpoint_requires_protocol_1_20`;
  - `checkpoint_id` (string, at most 255), `start_sequence` (integer, at
    least 1), and the prohibited `message_stream_cursors`,
    `message_stream_waits` and `sticky_cache`, with Laravel's messages;
  - a completion's own validation and guards: ownership, scope admission,
    worker sessions, child cancellation policies, local activities;
  - the claim must be the cooperative claim Server issued, or
    `409 active_claim_cancellation_not_supported` (no refresh token);
  - Core's refusals: the checkpoint arguments; a terminal command or a
    turn-closing one (`record_local_activity`, `open_condition_wait`,
    `open_signal_wait`, `cancel_selection_operation`); the claim; a closed
    run or passed run deadline; the retained receipt; a root cancellation
    request not yet delivered, or a prefix inside the delivered boundary,
    or a passed cleanup deadline; the authored sequence; scope membership;
    update commands; parallel sequences; the history meter.
  - An accepted prefix applies its commands as a completion does, metered.
    The task stays leased with its lease unchanged, and no history event of
    its own is written. The task payload keeps one receipt,
    `portable_scope_checkpoint`: checkpoint id, task, run, attempt, lease
    owner and expiry, start and next sequence, fingerprint, created task
    ids, and record time.
  - An identical retry answers the receipt with `duplicate: true`. The same
    id with anything else is `cancellation_scope_checkpoint_mismatch`.
    A new receipt replaces the last one.
  - Answers are 200, 404 for an unknown task, or 409, in the worker
    envelope with `history_refresh_page_token`.
- **Native.**
  - `scope_checkpoint.rs` holds the route's rules and Core's checks.
  - `complete_workflow`'s non-terminal command arms move unchanged into
    `apply_non_terminal`, which the completion and the checkpoint share.
  - The completion's HTTP checks are shared too (`completion_checks`).
- **Divergence: the fingerprint value.** Core hashes its own normalized
  command arrays (Server's and Core's normalizers). Native hashes the
  validated request commands. The two servers therefore give different
  values for the same prefix. The value identifies a retry on the server
  that issued it; no client computes or compares it. The fixture names each
  fingerprint and compares only its reuse.
- **Not ported:** Server's `persistTypedSearchAttributeHistoryIdentity`
  reads the receipt's `next_sequence` to check that Core wrote typed
  search-attribute history. Native writes that history itself.
- **Shared fixture.** The `cancellation-scopes` probe adds run R5, under
  `scope_checkpoints`.
  - It covers the gates, validation and Core's refusals.
  - It commits a prefix, retries it, sends a mismatch and a stale sequence,
    then replaces the receipt with a second prefix (a timer and its task).
  - The claim then opens its scope and completes.
  - R3's plain claim and R4's protocol 1.19 claim are refused.
  - Every new identity and fingerprint is named, and the key sorts last, so
    no earlier label changes.
  - The expectations are published 2.11.5's answers, where every existing
    step still matched.
- **Checked.**
  - Native `cargo test` on SQLite: the full library and execution suites
    after the refactor (338 and 562 tests), and the four new execution
    tests `scope_checkpoints::*`. These cover success and retry, mismatch,
    sequence, refused commands, protocol, validation, owner and claim gates,
    a timer with its task, a prefix after a delivered root cancellation, and
    the claim's later scope opening and completion. Route coverage
    expects no unserved route.
  - The whole execution suite, the new tests included, passes on
    PostgreSQL 17 and MySQL 8.0 (566 each; CI's pinned images, the test
    binary run in CI's Rust image on the same network). On SQLite one
    timing test, `updates_have_no_lifetime_cap_and_preserve_accepted_work_after_wait_timeout`
    (a one-second update wait), failed once while a release image build
    saturated the host, and passed 3 of 3 alone.
  - The contract unit test (27 cases).
  - `cancellation-scopes` recorded on SQLite against published 2.11.5
    (`sha256:bcc67285…`), a native image built from this branch, and
    embedded 2.11.5. All three pass, and `compare` reports
    `{"outcome":"pass","recordings":3}`.
