# Selected acceptance refusals

This source companion clarifies the selected-acceptance refusal paragraph in
[task delivery offers](task-delivery-offers.md). It records
[General's two-row decision](https://github.com/durable-workflow/server/issues/931#issuecomment-6659034006)
and [Security's source-design direction](https://github.com/durable-workflow/server/issues/931#issuecomment-6659043856).
The original 49-case fixture remains unchanged. No native implementation,
advertised capability, installed SDK behavior or runtime acceptance follows
from this document or its vectors.

## Closed response

Once a logical poll has selected an acceptance packet, only HTTP 409 with a
decoded JSON object containing exactly `task`, `poll_status` and `reason`
can establish either definitive refusal below. `task` must be literal null.
Both other values must be strings matching one complete row exactly.

| poll_status | reason | Client action |
| --- | --- | --- |
| `task_offer_expired` | `task_offer_expired` | Resolve this selected logical packet. Preserve the registration and unrelated pending packets. A new logical poll is permitted. |
| `stale_worker_registration` | `worker_registration_lost_authority` | End the original registration after Server has proved its execution authority lost. Do not end a replacement incarnation. |

There are no optional body fields. `task_offer`, even when null, `retryable`,
delay fields, task IDs, results and every other extra key make the response
uncertain. Wrong types, missing fields, an alternate HTTP status, cross-row
pairs, whitespace/case substitutions and available exception fields that
disagree with the body or HTTP status also remain uncertain. A typed exception
is not required when the response is available, but any available status or
reason must agree with it.

Neither row contains a task payload or authorizes payload observation,
hydration or execution. An uncertain response preserves the first selected
packet, endpoint, registration incarnation, logical poll and original kind
set, and preserves unrelated pending packets. It authorizes no new logical
poll, legacy claim or worker termination. Retry the same packet under the
existing uncertain-outcome rules.

Ordinary discovery before a packet is selected retains its separate typed poll
contract. Legacy `draining`, `stopped`, `stale`, heartbeat-stale or other
terminal labels do not become definitive acceptance refusals through this
clarification. A draining mode cannot erase a live committed claim.

## Server authority

The exact strings are meaningful only when produced by the required durable
transition. A client classifier cannot establish Server's storage proof.

Before emitting expiry, Server must serialize against every in-flight
acceptance of that logical poll, then read the authoritative receipt and task
under the normal transition authority. It must establish that the selected
packet has no committed accepted claim and cannot subsequently become
accepted. A lagging replica, missing observation, cache miss, backend error,
commit acknowledgement failure or ordinary empty response supplies no such
proof.

A committed receipt with current live execution authority wins over the short
offer deadline. Replay returns the same task, kind, owner and attempt without
another lease, attempt or task-start event. This remains true after renewal,
process restart or a lost acceptance response. Receipts must remain durable
for the complete live replayable claim. Premature receipt pruning is an
implementation invariant failure, never proof that an expired offer was
unaccepted.

Authority-loss refusal requires an authoritative read of the original
registration incarnation and its current task/lease/fence state proving it can
no longer execute. A replaced incarnation or an ended authority may supply
that proof. A worker ID, timestamp, generic stale label, freshness failure or
mode alone cannot. Reading a retained receipt does not revive an execution
fence or ended claim. Replacement and acceptance must serialize, so either
replacement prevents the original acceptance or acceptance commits once and
the later replacement revokes that original claim. Retrying the old packet
creates no further attempt or start event in either order.

An ended selected claim or a changed task attempt alone does not prove that
the original registration has lost all execution authority. When the
registration remains current for other work, forbid replay of the ended
claim and retain uncertainty without inventing another definitive pair.

The expiry row does not revoke the registration or any peer's claim. The
authority-loss row concerns the original incarnation and must preserve a
replacement's authority. The implementation must retain these distinctions
when an offer loses its task to another worker.

## Companion vectors

[`acceptance-refusals.v1.json`](../../tests/Fixtures/contracts/task-delivery-offers/acceptance-refusals.v1.json)
pins the original fixture and supplies explicit wire classifications plus
authority, replay and concurrency observations. Every wire case applies to a
selected acceptance. The source executor requirements cover all four kinds,
SDK handling before observers/hydration, current receipt/lease/registration
reads, unrelated pending packets and both race orders.

An SDK source consumer binds the exact companion commit, path, byte count,
SHA-256 and case IDs in its review record. The native executor must exercise
the actual transaction boundaries on required backends with independent
connections and commit/rollback/replacement barriers. Parsing this JSON or
running a scenario-only model cannot qualify those boundaries. Native
implementation, key/parser/schema work, capability advertisement, package,
installed tuple, runtime and release acceptance remain separate gates.
