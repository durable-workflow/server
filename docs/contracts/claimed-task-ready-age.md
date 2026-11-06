# Claimed task ready age

This source proposal implements the accepted metric boundary in Server
[#1030](https://github.com/durable-workflow/server/issues/1030). It is a native
producer change. It does not change frozen images, select a runtime experiment
or publish a producer. SDK observation remains a separate consumer action.

The accepted shared definitions are pinned to central commit
`7a91906ece5cd599175a2e08ebcc9c8bc667a6f6`,
[`sdk-metrics/README.md`](https://github.com/durable-workflow/.github/blob/7a91906ece5cd599175a2e08ebcc9c8bc667a6f6/sdk-metrics/README.md)
and [`contract.json`](https://github.com/durable-workflow/.github/blob/7a91906ece5cd599175a2e08ebcc9c8bc667a6f6/sdk-metrics/contract.json).
The production writer inventory below was reconciled against Server main
`fadf8729265e077f280c8f71a87c06d8f73a682e`.

## Field and clock

The optional capability `server_capabilities.claimed_task_ready_age` is an
actual Boolean. Only literal `true` declares support. A successful workflow or
activity poll may supply optional `task.ready_age_ms`, an actual non-null JSON
integer from zero through `9007199254740991`. The field is not required, so an
old cached claim or an unknown legacy origin can remain unchanged.

The value measures the claimed task attempt's known eligibility instant to the
same lease timestamp committed by that claim. Check the signed duration before
flooring nonnegative duration to milliseconds. Preserve a real zero, including
a positive duration shorter than one millisecond. Negative, invalid, unknown or
unrepresentable input omits the field. It never becomes a clamped value or a
synthetic zero. Activity-start recording, response serialization and worker
clocks cannot replace the committed lease sample.

No sample belongs to a query, update validator, timer task, empty poll, rejected
claim, stale answer or rolled-back transaction. A successful lease that the SDK
does not dispatch does not create an SDK dispatch observation by itself.

## Canonical origin

Add nullable `workflow_tasks.ready_origin_at`, using each backend's existing
UTC timestamp representation, in the next native additive schema revision.
Existing rows start null. There is no creation-time or availability backfill for
an unknown earlier ready transition. Historical migrations and catalogs retain
their exact bytes. A source migration/catalog candidate is qualified by the
existing fresh-schema, upgrade and backend checks before integration. PostgreSQL gives this new column a name-based catalog identity because supported PHP takeovers can retain dropped tail-column positions. Existing catalog keys remain unchanged.

For a ready row with a known origin, effective eligibility is the later of that
origin and a valid later `available_at`. Authored delay and retry backoff are
therefore excluded. A direct reclaim of an expired workflow lease instead uses
the old canonical lease expiry, respecting any later availability, before its
lease columns are overwritten. An absent legacy origin cannot erase that
separately provable expired-lease boundary.

Routine new ready transitions must write their origin in the same transaction.
An already-ready dispatch bookkeeping update preserves it. Worker registration,
compatibility, fairness and session-capacity selection do not create a new task
readiness episode. A future task/run pause that removes eligibility would need
its own authoritative resume transition before that feature could participate;
this proposal adds no pause semantics.

| Production boundary | Origin treatment |
| --- | --- |
| `store::create_task_dispatched` and `create_task` | New workflow/activity admission sample. Helper callers include start, continuation, redrive, child, update and cooperative resume paths. |
| `store::fail_workflow_task` direct `INSERT ... SELECT` | New row's admission sample, independently of the copied attempt count and the failed row's origin. |
| `activity_failures::fail_activity` and `activity_deadlines::time_out_activity` | Exact new retry availability, committed with the retry task/history. |
| `children::start_child_retry` | Its post-create availability override also sets the new workflow task origin. |
| `store::register` workflow lease release | Canonical release sample, respecting later availability; never old creation or previous lease start. |
| `worker_management::release_replaced_activity_leases` | Canonical activity lease-release sample under the same replacement transaction. |
| `worker_incarnations::repair_abandoned_task` | Canonical fenced repair-ready sample. A closed/historical branch that merely expires a lease invents no ready transition. |
| `store::deregister` and direct expired workflow reclaim | Retain the canonical expiry before claim overwrites it; the new lease samples that new episode. |
| `repair_pass::recover_task_row`, already ready | Preserve origin while updating dispatch/repair bookkeeping. |
| `repair_pass::recover_task_row`, leased or replay-blocked failed to ready | New canonical repair-ready sample. Running activity lease loss still goes through its existing timeout/retry owner. |
| `task_repair::repair_replay_blocked` | New canonical failed-to-ready sample; its already-ready dispatch stamp preserves origin. |
| `task_repair::create_missing_task` and `create_missing_timeout_task` | New workflow resume admission, or separately provable reconstructed activity availability. Timer rows themselves are excluded. |
| `wait_timeouts::schedule_wait_timeout`, `store::apply_non_terminal`, `store::fire_due_timers` | Timer availability/internal leases produce no metric; the resulting workflow resume has its own ordinary admission. |
| `workflow_import::import_rows` | Historical imported ready/lease state has unknown origin unless a separately versioned verified origin exists. Parsing old availability/creation timestamps does not establish lineage. |
| Completion, cancellation, failure and closed-wait cleanup | End eligibility. They cannot rewrite a prior returned claim's sample. |

## Claim and receipt

`store::claim_in` is the common successful workflow/activity claim point. Read
origin, availability and any old expiry before changing owner, attempt or lease
columns. Derive the optional integer from the exact committed `leased_at`
sample and return it only with that successful claim. Persisting the task and
remembering its poll answer remain under the existing transaction boundary.

Activity replay uses `dw_poll_receipts.response`. Workflow replay uses the
durable workflow poll answer in `dw_query_cache`; its deliverability check may
refresh the live lease expiry but must retain the original `ready_age_ms`.
Both contain the serialized original task, so replay and cold restart must
reuse that stored integer rather than measure again. An old answer without the
field remains without it. Renewal of the same lease does not reset the sample.
A genuinely new lease gets its own origin and lease sample.

The worker OpenAPI document evolves from revision 56 for the optional bounded
integer on workflow/activity claims and the optional Boolean server capability.
The worker protocol remains 1.20. Query and update-validation task shapes do
not gain the field. Native discovery and worker envelopes use the same central
capability map. PHP and frozen actors acquire no producer or advertisement from
the schema description. Capability advertisement is integrated only after the
complete producer/backend fixtures pass normal source qualification.

## Qualification

Pure arithmetic controls distinguish negative sub-millisecond deltas from zero,
and cover zero, 999/1000 microseconds, portable integer boundaries and overflow.
Backend controls exercise each writer above, delayed availability, retry,
replacement/release, direct expiry, repair, unknown import/legacy data, receipt
replay, renewal, cold restart, a genuine new lease and rollback. They compare
the response with the canonical stored origin and lease sample. SQLite, both
declared MySQL versions, MariaDB and both PostgreSQL versions retain their
existing backend suite selection.

These are required controls, not executing results supplied by this document.
The bounded implementation, current-head checks and actual backend evidence
follow on the same draft. Immutable producer/SDK/runner selection and installed
acceptance remain with #325 and the consumer owner records.
