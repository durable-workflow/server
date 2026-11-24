# Task delivery offers

This is the draft native contract for [Server #931](https://github.com/durable-workflow/server/issues/931)
and the delivery recovery in [#526](https://github.com/durable-workflow/server/issues/526).
It is not an implemented or published capability. The retained PHP draft is a
source reference, not a publication path. The native seal/key design and the
shared scenario executor still require review and implementation.

An offer observes a task without leasing or reserving it. Losing an offer
response starts no attempt or task-start history, and another worker can claim
the task immediately. Only an accepted task response authorizes execution.

## Negotiation

A worker advertising `task_delivery_offers` on protocol `1.20` may select this
mode only when registration returns the following `server_capabilities` entry:

```json
{
  "task_delivery": {
    "schema": "durable-workflow.task-delivery.v1",
    "supported": true,
    "minimum_protocol_version": "1.20",
    "worker_capability": "task_delivery_offers",
    "header": "X-Durable-Workflow-Task-Delivery",
    "version": "offer-accept.v1",
    "endpoint_suffix": "/offers",
    "acceptance_timeout_seconds": 2,
    "unaccepted_offer_reserves_task": false
  }
}
```

The descriptor is an object, not a Boolean. All fixed fields match exactly.
The acceptance timeout is an actual integer from 1 through 10, default 2.
The registration response also supplies the current registration credential as
32 lowercase hexadecimal characters. A missing or explicitly unsupported
descriptor can select legacy polling before a logical poll begins. A malformed
supported descriptor is an error. Protocol support alone does not supply this
capability.

The authenticated native routes use the worker's ordinary namespace and poll
capacity checks. Registration advertises the descriptor for an eligible worker
with an open issuance window and storage admission. Native Server refreshes
the 30-second issuance window every ten seconds while storage accepts new
work. A failed refresh suspends new offers until a window is committed.
Acceptance of an already issued packet uses its own signed scope and durable
receipt.

## Offer and acceptance

Use `POST /api/worker/{workflow,activity,query,update-validation}-tasks/offers`
with `X-Durable-Workflow-Task-Delivery: offer-accept.v1`, the current
`registration_token`, and the original nonempty `poll_request_id`. Preserve
the ordinary worker, queue, routing, history and normalized `task_kinds`
fields. Poll progress transport is negotiated independently.

An offer request returns an ordinary empty or terminal poll response, or:

```json
{
  "task": null,
  "poll_status": "offered",
  "task_offer": {
    "schema": "durable-workflow.task-delivery.v1",
    "task_id": "fixture-task-A",
    "task_kind": "activity",
    "token": "<opaque sealed packet>",
    "expires_at_ms": 2000000000000
  }
}
```

The task ID and token are nonempty strings. The opaque token is at most 8192
bytes. Expiry is a positive integer in epoch milliseconds. These example values
are synthetic; the token is not executable test input.

The worker retains its first selected packet and repeats the original request
with `task_offer_token` and `timeout_seconds: 0`. Client clones share ownership
of that pending delivery. Concurrent callers cannot replace the selected
packet or perform simultaneous acceptances for one logical poll.

The offered kind must be allowed by the original request. A workflow endpoint
can offer `workflow` or `update_validation` only when that kind appears in its
normalized requested set. Other endpoints allow only their own kind, with
`update-validation` represented as `update_validation` in the body. The accepted
response must match the selected task ID and kind. Deriving the kind only from
the workflow endpoint would reject valid multiplexed validation tasks.

## Authority and commit

The sealed packet binds namespace, queue, endpoint, logical poll, registration
incarnation, routing fence, normalized kind set, selected task and kind,
observed attempt and authoritative eligibility fingerprint. Its internal
candidate snapshot grants no authority over current storage.

Acceptance serializes one result per logical poll, including concurrent
alternative offers and multiplexed workflow/validation requests. Under the
normal transition locks, Server rechecks current registration and task
authority, eligibility and the short acceptance deadline. Receipt, lease,
attempt increment and task-start history commit as one unit. A refusal before
commit rolls the unit back. No transaction, lease or reservation spans the
offer response's network write.

Native queries and poll coordination use SQL-backed `dw_query_cache` within
the same backend transaction. The PHP reference's external-cache authority
split is not a requirement to add another native task authority. Existing
short-lived empty/pending poll results are not committed delivery receipts.
The implementation must define bounded receipt storage and cleanup while
retaining the accepted identity for the complete live claim, including renewal
and process restart.

The native producer seals the worker's routing fence, protocol, build and
history options with the observed task authority. The candidate fingerprint
covers the task and attempt state and the relevant run, activity, query or
validation facts. Workflow offers also bind the pending timeout-cleanup
decision before it can mutate the run. A multiplexed offer observes the kind
cursor without moving it. Only a committed lease turns the cursor. The native
acceptance checks the packet deadline again before committing a new claim.

## Uncertain outcomes

An accepted live claim can replay after the short offer deadline. Replay must
return the same task, owner and attempt under its current lease authority,
without another attempt or start event. Renewal does not discard the receipt.
A replaced registration, changed task/attempt or ended lease cannot regain
authority through an old packet.

Before declaring an expired offer unaccepted, Server serializes against any
in-flight acceptance and reads the authoritative receipt and task. A lost
accept response, ordinary empty response, backend error or response from an
older replica does not establish that no claim committed. The worker retains
the same packet, endpoint, registration and poll identity through uncertainty.
It must not fall back to a legacy claim.

A definitive refusal has HTTP 409, `task: null`, and one of the typed reasons
listed below. Confirmed `task_offer_expired` permits a new logical poll.
`worker_registration_lost_authority` ends the old worker. Other refusals and
terminal outcomes require the existing typed poll contract; an unstructured
409 is not a definitive no-claim result. An older endpoint's 404 or 405 remains
uncertain once acceptance has begun.

Native Server returns five definitive refusals. Each body contains exactly
`task`, `poll_status`, and `reason`, with no worker envelope. Each `poll_status`
and `reason` pair is listed below:

| `poll_status` | `reason` | Meaning |
| --- | --- | --- |
| `task_offer_expired` | `task_offer_expired` | The packet's deadline passed, no claim committed for the logical poll, and the selected task still has the observed attempt. |
| `task_offer_unclaimable` | `task_offer_unclaimable` | No claim committed for the logical poll, and the selected task can no longer be claimed at the sealed attempt and eligibility, for example because a peer claimed it first. |
| `task_offer_lease_ended` | `task_offer_lease_ended` | This packet's claim committed earlier, but its lease has ended and it grants no authority. |
| `task_offer_conflict` | `task_offer_conflict` | The logical poll is already bound to a different packet, kind set or registration, so this packet can never be accepted. |
| `stale_worker_registration` | `worker_registration_lost_authority` | The packet's registration incarnation was replaced or is no longer fresh. |

Server decides each answer under the transition lock after reading the
logical poll's receipt. A refused acceptance rolls back. The first four mean
that the selected packet does not and cannot authorize execution. The worker
releases only that packet, and its next logical poll uses a new identity.
`task_offer_lease_ended` and `task_offer_conflict` do not claim that nothing
committed for the logical poll, only that nothing live is owed to this
packet. Authority loss ends only the original registration incarnation.

Any other answer is not definitive, including a retryable 503 or a 409 in the
worker envelope. The worker protocol version stays in the response header. The
legacy poll routes refuse requests that carry a task delivery header or a
signed offer token.

## Remaining source and acceptance work

Security's [design disposition](https://github.com/durable-workflow/server/issues/931#issuecomment-6646286980)
accepts full-round XChaCha20-Poly1305 and conservative read-key retention as the
source direction, with the requirements below. Implementation acceptance stays
held until the exact dependency graph, parsers, durable key references,
migrations and controls are reviewed. Laravel ciphertext interoperability is
not required. No hand-written cipher, credential derivation, node-local key
generation or page-token fallback supplies this boundary.

### Seal and parser requirements

Use an exact-reviewed direct RustCrypto dependency with its supported
zeroization feature. Keys are 32 bytes. Obtain each fresh 24-byte nonce through
a fallible OS-RNG API; an RNG error emits no packet and returns a typed refusal.
Production accepts no caller-selected nonce. The random-nonce design does not
claim that the library guarantees uniqueness. Source controls include RNG
failure and reused test-nonce countercontrols. No cross-replica nonce allocator
is specified.

The internal frame is exactly
`dwo1.<key-id>.<base64url-no-pad(nonce || ciphertext-with-tag)>`. Require three
ASCII segments, the literal case-sensitive prefix, and a case-sensitive
1..32-byte key ID containing only letters, digits, underscore or hyphen.
Reject extra/empty segments, padding, whitespace/control characters,
standard-base64 substitutions and noncanonical base64url. Canonical re-encode
must reproduce the input.

Bound the outer token to 8192 bytes before decoding or allocating from its
length. The decoded frame contains 24 nonce bytes, at least 16 tag bytes and
at most 6000 plaintext bytes. Its decoded range is 40..6040 bytes. With a
32-byte key ID, the largest valid frame is 8092 outer bytes. Pin both limits
in controls rather than treating the outer limit as permission to decrypt an
oversized decoded frame.

Authenticate structurally encoded, domain-separated AAD containing at least
the literal prefix, sealed-context schema, `offer-accept.v1` and exact key ID.
Do not concatenate variable strings ambiguously. Only authenticated plaintext
reaches a canonical closed-schema parser. Duplicate, unknown, missing and
wrong-type fields refuse, with no alternate parser or unauthenticated recovery.
Errors expose no token, key, plaintext, key digest or authority value. Public
key IDs appear only in an explicitly reviewed diagnostic contract.

### Configuration and enforceable retention

The proposed dedicated setting is `DW_TASK_DELIVERY_SEAL_KEYS`, with no legacy
alias. Bound its original UTF-8 text to 4096 bytes before parsing. An absent
setting disables support. A supplied empty string, malformed UTF-8, oversized
value or invalid container refuses configuration; none selects a fallback.
The closed container is an object with exactly `schema` and `keys`, where
`schema` is `durable-workflow.task-delivery-seal-keys.v1` and `keys` is an
ordered array of 1..4 entries. Each entry has exactly `id` and `key_base64`.
Duplicate or unknown fields at either level, duplicate case-sensitive IDs,
wrong types and extra entries refuse. IDs use the frame's 1..32-byte ASCII
alphabet. Container JSON formatting is not itself credential text.

`key_base64` uses only canonical padded RFC 4648 standard base64, not the
frame's URL-safe alphabet. Exactly 32 decoded bytes require 44 ASCII bytes
ending with one `=`. Reject whitespace, omitted/extra padding, URL-safe
substitutions and nonzero unused bits. Decode and canonical standard-base64
re-encode equivalence is required. Validate every original `key_base64`
string under the shared static-credential fixture before any key is decoded
or selected, including read-only entries. Do not trim or normalize it and do
not scan decoded entropy as token text. Errors name only the setting and a
typed configuration reason. Zeroize decoded buffers and removable parser
and key-schedule copies, and provide no revealing serialization/debug path.

The first key may issue and all retained keys may read. Missing configuration
means unsupported/no issuance; malformed supplied configuration refuses. Stop
issuing under a retiring key before beginning its retirement interval. Retain
it for at least the maximum unaccepted-offer lifetime plus the explicitly
admitted clock/skew bound, and while any live, renewed, replayable or retained
receipt references it. Persist the nonsecret seal key ID with accepted claims
and receipts. Startup/configuration preflight must refuse support/issuance
when a required read key is absent, rather than silently stranding a claim or
selecting legacy polling.

The clock/skew bound, durable key-reference schema, bounded receipt retention
and retirement enforcement must be returned in the implementation packet.
They are not implemented by this document. Four occupied slots can block
rotation. Forced key removal and deliberate invalidation of live claims need
a separate product/operational policy; no automatic removal path is admitted.
The seal provides authentication/confidentiality, while the existing claim,
attempt, registration and receipt transaction supplies task authority.

### Native key-use records

The native implementation keeps versioned key-use records in the SQL
`dw_query_cache` table under `dw-native-offer-key:`. The exact key ID indexes
the maximum committed read horizon, a count of retained receipts, and a
domain-separated SHA-256 fingerprint of the installed key material. Retired
key records preserve the ID-to-material association across future boots.
Fingerprints stay internal.

An issuer first commits a 30-second issuance window against the database
clock. Its local monotonic deadline also limits that window. Its latest
possible expiry is the end of the window plus the ten-second maximum offer
lifetime. The database retains the key for at least another 1,000 milliseconds.
A replica admits a database-clock sample only when the sample takes at most
500 milliseconds and the database time is within 500 milliseconds of both
local wall-clock observations. Installed qualification must verify at most
1,000 milliseconds of disagreement among the application replicas and
database primaries, including failover.

Server startup checks all required read keys before starting the scheduler.
A required read key that is absent from the configured ring, or an absent
ring, does not stop the server. It starts and keeps serving the legacy routes,
but opens no issuance window, so registration advertises no offers. An offer
acceptance or replay whose packet names a read key that storage still
requires, but that this replica lacks, receives the retryable HTTP 503
reason `task_offer_read_key_unavailable`. That refusal is not definitive, and
the worker keeps the packet for a replica that holds the key. The durable
obligation is never evicted. A failed issuance-window refresh is logged at
warning level with a static reason and no key material.
Starting or renewing an issuance window also checks them in its transaction.
An accepted receipt increments its key's reference count in the same
transaction as the lease. Receipt review uses the database clock with the
clock-skew allowance and decrements the count only when it removes the
receipt. A retained receipt continues to require its key after the window
and unaccepted-offer lifetime have elapsed.

To rotate, load the new key as a read key on the serving replicas before
starting issuers with the new key first. Stop the old issuers, allow their
last committed windows to age out, and keep the old read key configured
until receipt review releases its final reference. Startup still rejects
reuse of an existing key ID for different material. A missing required read
key suspends issuance and the refusal above, rather than refusing startup.

The shared draft scenarios are in
[`offer-accept.v1.json`](../../tests/Fixtures/contracts/task-delivery-offers/offer-accept.v1.json).
They describe required observations, not successful executions. Source tests
must run them through the actual native claim/receipt boundaries on the
required backends. SDK-PHP #219 must bind selected kinds to the original
logical request. Normal source qualification and Security review precede
advertisement.

Installed acceptance then uses one separately admitted immutable native image,
SDK artifact and runner. It covers lost offer/accept responses, short proxy
budgets, abrupt restart, worker replacement and shared wait capacity, including
global/namespace bounds, exact-owner release and live waiters on other
replicas. The executable plan names its executor, deadline, capacity, resource
ownership, cleanup backstop and stop conditions before invocation. Source CI
does not qualify a published image or close the remaining #526 criteria.
