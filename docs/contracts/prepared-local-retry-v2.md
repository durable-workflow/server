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
replays its stored decision before fresh retry calculation. The original total
deadline comes from the prepared execution and correlated start authority,
never a duplicate response's fresh `server_time` or a batch's recording clock.
The SDK must receive or recover that engine decision before sleeping or invoking
another callback.

The proposed `retry_disposition` object has closed state-dependent fields in
the companion. Scheduled decisions retain candidate, cap and checked availability.
Terminal cap/deadline decisions retain a diagnostic candidate but have no retry
availability, task, start, following attempt or retry event. Non-retryable and
exhausted outcomes select no delay. An already-due prepared engine timeout
retains its engine provenance rather than borrowing the caller's application
failure. An earlier valid failure whose selected availability reaches the total
deadline remains an application failure with terminal retry-delay disposition.
General accepted this mode-bound projection in
[the source decision](https://github.com/durable-workflow/.github/issues/219#issuecomment-6669526988).

The object is proposed engine-owned output shared by outcome history and the
matching receipt. It is not accepted worker input. Existing receipt identities,
claim-release behavior and created-task identity remain authoritative. The
companion closes this engine object, not the whole HTTP response. The controller's
history cursor, protocol envelope and exact refusal/status matrix still need a
separate source binding before adoption. No new top-level HTTP reason aliases
are selected here.

The data's named source controls cover strict override types, separate report
grammars, conjunctive mode gates, isolated authoritative identities, deadline
edges, terminal precedence, reply loss, receipt mismatch, rollback, cold replay,
checked backend timestamp arithmetic and unchanged version-1 behavior. They are
requirements for a future real writer/adapter. Their presence or JSON consistency
is not an executing pass. The portable integer bound is distinct from an actual
backend duration or timestamp bound. Neither the old 100-report batch limit nor
its 86,400-second executed-backoff ceiling becomes an implicit version-2 cap.

Workflow owns the complete normalized snapshot and existing timeout/range
binding. Server owns the companion validator, transaction, transport and storage
bindings. The exact output object, final normalized ranges
and HTTP bindings must be reconciled before these bytes are frozen or SDKs
adopt them. Native implementation, schema, installed/backend qualification and
release remain separate work.
