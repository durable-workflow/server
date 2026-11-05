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

A definitive refusal has HTTP 409, `task: null`, and the matching typed reason.
Confirmed `task_offer_expired` permits a new logical poll.
`worker_registration_lost_authority` ends the old worker. Other refusals and
terminal outcomes require the existing typed poll contract; an unstructured
409 is not a definitive no-claim result. An older endpoint's 404 or 405 remains
uncertain once acceptance has begun.

## Remaining source and acceptance work

The opaque seal needs a reviewed standard authenticated-encryption API,
cluster-shared key configuration and derivation, nonce discipline, key IDs,
rotation/read-old lifetime and typed unavailable behavior. Laravel ciphertext
interoperability is not required. Existing page-token key fallback does not
settle this boundary. No hand-written cipher or plaintext HMAC replacement is
specified here.

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
