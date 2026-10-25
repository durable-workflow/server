# Worker task delivery offers

Development candidate for [Server #526](https://github.com/durable-workflow/server/issues/526).
This contract is not available in a published Server image. SDK integration,
concurrent SQL/shared-cache qualification, real proxy/restart tests and published
acceptance remain required before delivery.

Workers advertising `task_delivery_offers` with protocol 1.20 may use
`POST /api/worker/{workflow,activity,query,update-validation}-tasks/offers`.
Send `X-Durable-Workflow-Task-Delivery: offer-accept.v1`, the current
`registration_token`, and a unique `poll_request_id`. Workflow requests retain
their ordinary `task_kinds`, history and routing fields. Progress transport
negotiation is independent and may also be used on these endpoints.

An old Server has no offer endpoint, so it rejects the request before claiming
work. A worker may choose ordinary polling before starting a logical poll on
an older Server. Once acceptance has an uncertain outcome, it must keep retrying
the same offer endpoint and identity. A response from a different, older node
does not prove that the accepting node failed to commit.

The first request returns an ordinary empty result or:

```json
{
  "task": null,
  "poll_status": "offered",
  "task_offer": {
    "schema": "durable-workflow.task-delivery.v1",
    "task_id": "opaque-task-id",
    "task_kind": "activity",
    "token": "opaque-sealed-token",
    "expires_at_ms": 1792908000000
  }
}
```

The sealed packet describes one observed task and its original attempt,
registration incarnation, routing fence, namespace, queue, endpoint, logical
poll and requested kinds. It starts no lease, activity attempt or history event.
It reserves no task: another worker may claim it immediately. Losing the offer
response requires no recovery job or expiry sweep.

The worker selects one offer and repeats the original request with
`task_offer_token`. Acceptance uses a zero-wait claim and revalidates the original
task authority and normal routing/session admission. The acceptance budget is
two seconds by default, controlled by `server.polling.task_offer_accept_seconds`
and clamped to one through ten seconds. Original scheduling, query deadlines,
lease lengths and business retry limits remain independent.

SQL task acceptance records a receipt in the task's existing row in the same
transaction as its normal claim. A late acceptance rolls back the claim,
activity attempt and history together. An indexed poll key and the registration
row lock prevent one multiplexed poll from claiming both task kinds. Query
acceptance uses its existing queue lock and authoritative cache task record;
its receipt stays in that record. Poll wait capacity and wake hints grant no
task authority.

Only the accepted task response authorizes execution. An offer never does.
If the response is lost, retry the exact selected token, registration, poll ID
and endpoint. An already-accepted live claim can replay after the offer deadline
without another attempt. A refused duplicate cannot revoke a peer's claim.
Expired unaccepted packets, replaced registrations and changed task identities
are refused. Query status remains a read and does not confirm delivery.

Mixed-node routing, concurrent alternative offers, SQL/cache failure points,
restart and worker replacement remain explicit qualification targets. The draft
does not establish full #526 acceptance from source tests alone.
