# Prepared local retry contract proposal

This is an unfrozen source proposal for the local part of organization issue
[#219](https://github.com/durable-workflow/.github/issues/219). The machine-readable
companion is [prepared-local.v2.json](../../tests/Fixtures/contracts/activity-retry-policy/prepared-local.v2.json).
It adds no runtime validator, producer, advertisement or executing test result.
The existing 54 canonical policy cases and 37 remote adapter cases are unchanged.

Local version 2 requires the existing prepared per-attempt outcome authority,
the literal `activity_retry_delay_override_v1` capability on the original claim,
local contract 2 and retained snapshot 2 together. Current native Server lacks
the prepared role and truthfully refuses its routes. Its unprepared
`record_local_activity` batch remains version 1, including its zero-tail and
backdated history interpretation. A worker cannot run subsequent callbacks and
later use that batch to claim an engine-authorized version-2 decision.

The prepared report has its own closed grammar. Only a failed report may add
`next_retry_delay_seconds`, as an actual nonnegative JSON integer within the
portable bound. Absence and zero differ. Completed and timed-out reports retain
their existing fields. Workers cannot supply a disposition, selected backoff,
wall-clock failure time, deadline, policy snapshot or engine timeout category.

The engine uses one UTC sample under the canonical locks for fresh temporal
fences and retry selection. The first transaction that commits retains that
sample and decision. Rollback publishes neither. A matching outcome receipt
replays its stored failed/timeout retry decision before fresh calculation. The original total
deadline comes from the prepared execution and correlated start authority,
never a duplicate response's fresh `server_time` or a batch's recording clock.
The SDK must receive or recover that engine decision before sleeping or invoking
another callback.

The proposed `retry_disposition` object has closed state-dependent fields in
the companion. Scheduled decisions retain candidate, cap and checked availability.
Terminal cap/deadline decisions retain a diagnostic candidate but have no retry
availability, task, start, following attempt or retry event. Non-retryable and
exhausted outcomes select no delay. An already-due overall schedule-to-close
timeout retains its engine
provenance rather than borrowing the caller's application failure. An actual
start-to-close or heartbeat timeout before that total deadline may instead
retry under the engine policy when its attempt budget remains. The disposition
identifies the failure source, actual timeout kind and selected delay source;
a preempted application override cannot become the timeout retry delay. An
earlier valid failure whose selected availability reaches the total
deadline remains an application failure with terminal retry-delay disposition.
General accepted this mode-bound projection in
[the source decision](https://github.com/durable-workflow/.github/issues/219#issuecomment-6669526988).

The object is proposed engine-owned output shared by outcome history and the
matching receipt. It is not accepted worker input. Existing receipt identities,
claim-release behavior and created-task identity remain authoritative. The
companion now also proposes complete HTTP response families, the fixed
zero-history cursor, pinned protocol envelope and finite status/refusal cases.
Those templates still need joint source review and an actual future adapter.
They retain the distinct controller-missing, controller-stale and structured
engine refusals rather than accepting arbitrary `recorded:false` shapes.
No new top-level reason alias is selected.

The future handler must run a pure report shape/type/override preflight before
its task, attempt, original-claim or registration lookup. Current Server checks
only that the report is an array before those lookups, so Core's later strict
normalizer alone does not establish this ordering. Recheck admitted shape and
fences under the authoritative transaction. The pure preflight cannot read
storage, fetch payloads, invoke a callback or sample the failure clock.
Core's entire current normalizer cannot be lifted into this phase: its
completed branch resolves payload envelopes.

The retained fetch-free retry-decision recovery applies to failed and timed-out
reports with pure normalized fingerprints. Completed external-reference
normalization retains its existing separately scoped payload contract. This
feature introduces no fetch-free completed replay guarantee or new retained
external-artifact identity. Missing resolution or an uncertain response never
grants permission to repeat a callback.

The data's named source controls cover strict override types, separate report
grammars, conjunctive mode gates, isolated authoritative identities, deadline
edges, terminal precedence, reply loss, receipt mismatch, rollback, cold replay,
checked backend timestamp arithmetic and unchanged version-1 behavior. They are
requirements for a future real writer/adapter. Their presence or JSON consistency
is not an executing pass. The companion also binds Core's proposed ten-key
stored snapshot and explicit
new-route attempt/list/type/timeout bounds. These proposed bounds are separate
from current version-1 facts. The portable integer bound is distinct from an
actual backend duration or timestamp bound. Neither the old 100-report batch
limit nor
its 86,400-second executed-backoff ceiling becomes an implicit version-2 cap.

Workflow owns the complete normalized snapshot and existing timeout/range
binding. Server owns the companion validator, transaction, transport and storage
bindings. The exact output object, final normalized ranges
and HTTP bindings must be reconciled before these bytes are frozen or SDKs
adopt them. Native implementation, schema, installed/backend qualification and
release remain separate work.
