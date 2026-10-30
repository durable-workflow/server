# October 30, repeats of a finished activity attempt's result

- **Before.** Native answered a repeat of the exact result that finished an
  activity attempt with its earlier idempotent receipt: `200`, `recorded:
  false`, `already_completed`. A different repeat already got published
  PHP's stale-attempt receipt.
- **Contract** (published Server 2.11.7, recorded first). After a completion
  or a non-retryable failure finishes the attempt, these each answer `409
  stale_attempt`, `recorded: false`, with nothing added to history:
  - the same result again;
  - a different result;
  - the other route;
  - the same result after the workflow consumed it.

  A repeated completion's receipt names the lease owner and the attempt,
  activity and task statuses. A repeated failure's receipt carries none of
  them.
- **SDK directions** (published versions the reference pins, read before the
  change):
  - PHP SDK 2.2.6 reads a `409 stale_attempt` completion receipt with its
    own owner and completed statuses as its committed completion
    (`isCommittedActivityCompletion`), and discards a refused failure
    (`discardClosedActivityAttempt`).
  - Rust SDK 3.4.1 treats `stale_attempt` as a final answer for a
    completion or failure; its test
    `managed_activity_accepts_authoritative_stale_outcome_after_unknown_completion`
    retries an unknown completion and accepts this receipt.
  - Python SDK 2.5.0 retries a lost POST in its transport, then logs a
    non-2xx answer and moves on; the outcome affects only its metrics.

  So the change cannot lose or repeat work for a published worker.
- **Fixture.** `activity-result-repeats`, raw worker HTTP on protocol 1.20,
  one case per route (`complete`, `fail`).
- **Native.** The activity complete and fail routes no longer return the
  idempotent receipt; an identical repeat reaches the stale-attempt receipt
  every other repeat already got. Workflow task completions keep their
  idempotent receipt, as PHP's do.
- **Tests.** `a_repeated_activity_result_is_stale_whether_it_completed_or_failed`
  (`activity_result_repeats.rs`); `worker_listing.rs` now expects the stale
  receipt for the replacement's repeated result.
