# Prepared local retry contract proposal

This is the reviewed semantic source baseline for the local part of organization
issue [#219](https://github.com/durable-workflow/.github/issues/219). General recorded
the [implementation and adoption order](https://github.com/durable-workflow/.github/issues/219#issuecomment-6671268805)
after the joint source comparison. The machine-readable
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
Core completed the [bounded joint source comparison](https://github.com/durable-workflow/server/pull/1034#issuecomment-6671101501).
These templates still require an actual future adapter.
They retain the distinct controller-missing, controller-stale and structured
engine refusals rather than accepting arbitrary `recorded:false` shapes.
No new top-level reason alias is selected.

The future handler uses two phases to select the pure report preflight.
Presence of `report.next_retry_delay_seconds`, including an invalid null value,
signals only untrusted feature intent. After existing auth, protocol and
runtime-role admission, validate that supplied field and its closed failed-report
shape before task, attempt, original-claim or registration reads. A passing
preflight grants no capability, stored mode or snapshot authority. Check override
value/type and failed-only placement first. An invalid or forbidden override
uses the proposed strict `invalid_activity_retry_delay` 422 reply. Other closed
report errors use the existing `invalid_local_activity_outcome` engine-refusal
409 reply, including a valid supplied override beside an invalid message or
unknown report member. Neither refusal requires a durable lookup in this phase.

Without that member, preserve the legacy lookup and validation order. After
scoped original-claim lookup actually establishes the conjunctive version-2
mode, run the same pure report validator before fresh registration/fence work,
failure-clock sampling or payload resolution. A malformed legacy report does
not acquire a new early refusal merely by sharing this endpoint. The guarantee of no task lookups therefore applies to refusals in the
new-member phase. A pure
validator invoked after mode selection has zero reads of its own, not a claim
that the request performed no earlier reads. No new request marker, capability
or global protocol version selects these phases.

Current Server only checks that the report is an array before lookup. These
phases are future handler requirements. Recheck admitted shape, original mode
and fences under the authoritative transaction. The pure validator cannot read
storage, fetch payloads, invoke a callback or sample the failure clock. Core's
entire current normalizer cannot be lifted into this phase: its completed
branch resolves payload envelopes.

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

The engine-interruption extension follows General's
[bounded source choice](https://github.com/durable-workflow/.github/issues/219#issuecomment-6674785967).
It appends 25 proposed recovery controls while preserving all 133 reviewed cases.
Only an engine recovery that proves loss or fencing of the original claim may
retain `failure_source: engine_interruption`. Its clock is the first locked
recovery observation that commits. An actually due activity timeout takes the
existing engine-timeout branch instead. Interruption uses the retained policy,
attempt budget, cap and original deadline, with no requested delay or timeout
kind. Valid normalized policy delays already fit their cap; a corrupt retained
cap/delay relationship refuses without a decision or mutation, while the
existing application-override cap cases retain their separate behavior. An
ordinary valid failed report remains an application failure; a worker
cannot select recovery provenance or supply engine decision or stop metadata.

Recovery retains its existing `recovered` receipt carrier, which differs from an
outcome's `recorded` receipt. The proposed version-2 recovery response adds the
retained `retry_disposition` to that closed carrier and its existing cursor and
protocol envelope. Its task identity is the recovering task, independently
bound to the original prepared graph. The existing `local_recovery.version: 1`
identity envelope does not grant local contract 2; that authority still comes
from the canonical original preparation, snapshot and retained claim. Version-1
recovery bytes are unchanged.

A matching committed recovery receipt returns its original sample and decision
before fresh fence or clock calculations. Later configuration for new authoring
may differ, while the original retained snapshot, request descriptor and Started
authority remain exact. A changed canonical retained graph refuses even when a
committed receipt exists; replay does not repair or replace that authority.
Rollback publishes no receipt,
decision, task or event. A terminal decision creates no following attempt; a
scheduled decision still requires independent retry preparation and callback
admission. Recovery and lease fencing do not prove physical callback stop or a
supervisor join. The immutable recovery receipt's `callback_stop_state` stays
`unknown`, including after a separate real stop acknowledgement updates other
state. These guards and the unadvertised installed role remain in force. The
extension requires cross-owner shape reconciliation before a shared freeze;
the appended data is not an executing recovery result.

Workflow owns the complete normalized snapshot and existing timeout/range
binding. Server owns the companion validator, transaction, transport and storage
bindings. The reviewed output object, normalized ranges and HTTP bindings are the source
baseline for the guarded implementation. SDK adoption requires concrete producer
capability and receipt evidence; a remote capability alone does not supply local
contract-2 authority. Core
[#1051](https://github.com/durable-workflow/workflow/pull/1051) owns its kernel and
optional installed bridge role. Server must bind that actual role and its
canonical original mode/snapshot to the retained claim under the existing outer
transaction. The current bound bridge does not expose that role. Native
implementation, schema, installed/backend qualification and release remain
separate work.

## Guarded Server adapter

The source adapter targets Core's optional interface and pure helpers at
`ce24207ef86856e64f97b939c9aeef9d1fb387c8`. The locked package and default
bridge remain unchanged. No retry capability is advertised by this adapter.
Policy-member or override presence selects only untrusted intent. A real bound
`PreparedLocalActivityRetryTaskBridge`, the original issued capability and
canonical contract/snapshot authority are required independently.

A successful version-2 preparation receives a distinct private
`durable-workflow.server.prepared-local-authority/v2` receipt. Its exact keys
are `schema`, `claim`, `activity_execution_id`, `activity_attempt_id`,
`worker_attempt_id`, `local_activity_contract_version`, `retry_policy_snapshot`,
`preparation_history_event_id` and `descriptor_fingerprint`. The contract value
comes from canonical Core preparation. The complete normalized snapshot and
unique correlated Started event must agree on every read. A legacy receipt is
never rewritten or promoted. The hosting task's later current claim cannot
replace the original callback's issued claim.

Recovery independently reads the original callback's retained authority before
entering Core. A duplicate binds the old attempt named by its exact recovery
identity even after a following attempt exists. Fresh recovery still needs the
recovering claim and existing registration fence. Post-write readback checks the
original graph within the same outer transaction, so a changed graph rolls the
operation back. Physical callback stop remains a separate authority.

Completed external payload uploads retain their existing completion-lease,
namespace, storage and reference checks. The original receipt and supervisor
control dispatch use the same proven mode as the outcome route. This adds no
fetch-free completed replay guarantee. Failed and timed-out pure report checks
perform no payload resolution.

Normal locked-package tests cover unavailable-role precedence and unchanged
legacy authority. The exact candidate writer/transaction/transport comparison
and all semantic cases remain required qualification work. PHP syntax or pure
intent controls alone do not qualify a prepared writer or enable a runtime role.
