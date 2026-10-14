# Query task deadlines and authority

This describes the source change for #433. Published-package and SDK execution
qualification is required before adopting this contract.

Every newly admitted query snapshots `query_admitted_at` and
`query_deadline_at`. The original result duration starts at queue admission.
Both timestamps use UTC with six fractional digits and `Z`. Configuration
changes, queueing, redelivery and repeated polls cannot extend the deadline.
A zero duration expires immediately. The SDK HTTP timeout is a separate bound.

Delivered queries also carry the existing task/attempt/owner identity,
`lease_expires_at` and a fresh `server_time`. The lease is capped at the original
query deadline. An SDK must account for transport and loading time using a
monotonic local budget. Neither an old timestamp nor a successful observation
grants future execution or commit authority.

## Observe without renewal

When discovery advertises `query_task_authority=true`, protocol 1.20 workers can
read `GET /api/worker/query-tasks/{queryTaskId}/status` with `lease_owner` and
`query_task_attempt` query parameters. The response includes `query_task_status`,
`can_continue`, a named `reason`, the original timestamps, lease expiry and
fresh server time. It does not renew the claim, worker registration or receipt.
Protocol 1.19 receives `422/query_task_status_unavailable`. New deliveries still
include their actual timing fields on 1.19. Older Servers may provide neither
the timing nor the status capability. Workers must not invent that authority.

At `now >= query_deadline_at` or `now >= lease_expires_at`, a new outcome is
refused. Completion and failure recheck current authority under the query queue
lock after external result preparation. Worker registration replacement cannot
upgrade an existing query claim. SDKs must stop loading and suppress handler
execution after authority ends. The final Server fence still applies.

A poll waiting on one registration cannot acquire authority from a replacement
with the same worker ID. Cached deliveries also keep the original incarnation.
Recovery may assign a new attempt on the same query while its original budget
remains. It preserves admission, deadline and receipt expiry, and does not fence
the replacement registration.

## Lost acknowledgments and bounded receipts

Authentication, worker role and namespace isolation precede receipt lookup.
The identical committed operation for the original owner and attempt returns
its original decision with `recorded=false`, before fresh deadline, lease or
registration checks. It does not fetch or claim the external result again,
write another outcome or send another wake. A different operation or payload
returns `409/query_completion_conflict`. Object key order is insignificant.
Typed values and list order remain significant.

If a database wait outlives the query queue lock, a first claim or outcome is
refused. `503/query_task_queue_unavailable` on an outcome includes its original
claim identity, `outcome=deferred`, `recorded=false` and a one-second retry hint.
Any retry uses the same operation and remaining original budget. It grants no
new deadline. A committed receipt can still reconcile without that lock.
Result waiting observes the original expiry even when cache bookkeeping cannot
acquire the lock. It does not replace the caller's canonical timeout with a
generic transport error or admit another query.

Receipt expiry is fixed at admission, preserving the configured task TTL and
at least 60 seconds after the original result deadline. Reads and duplicates
do not extend it. Missing/expired cache state returns explicit absence and
cannot reapply an outcome.

An upgraded pending or leased cache entry without verifiable original timing
fails the same query with `409/query_deadline_unavailable`. Its original caller
receives the typed failure and original query identity. No fresh result budget
is created. If this legacy entry also lacks a retention snapshot, the Server
keeps only a fixed 60-second failure tombstone from detection so the original
waiter can observe the refusal. This tombstone cannot accept an outcome or act
as a successful completion receipt. Existing committed receipts retain their
own fixed expiry.

Retrying the failed business query is an explicit caller operation with a new
query identity. A new query must not conceal the first failure.
