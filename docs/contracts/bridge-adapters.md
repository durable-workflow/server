# Bridge Adapter Outcome Contract

Bridge adapters are bounded ingress or handoff surfaces. They translate one
authenticated integration event into a workflow start, signal, update, or
bounded external-task handoff. They do not replay workflows, interpret event
history, or own workflow state transitions.

The authoritative machine-readable contract is published from
`GET /api/cluster/info` at `bridge_adapter_outcome_contract`:

- `schema: durable-workflow.v2.bridge-adapter-outcome.contract`
- `version: 1`
- `boundary`
- `patterns`
- `idempotency`
- `visibility`
- `outcomes`
- `rejection_reasons`
- `reference_journeys`

## Webhook Receiver

The first runtime bridge endpoint is:

```text
POST /api/bridge-adapters/webhook/{adapter}
```

The route is protected by the same control-plane version, auth, and namespace
middleware as other API routes. `{adapter}` is an operator-visible adapter key
made of letters, numbers, `.`, `_`, `:`, or `-`.

Every request must include:

```json
{
  "action": "start_workflow | signal_workflow | update_workflow",
  "idempotency_key": "provider-event-id",
  "target": {},
  "input": {},
  "correlation": {}
}
```

`input` and `correlation` are optional. The response never echoes raw provider
payloads, authorization material, signatures, tokens, or secrets.

## Reference Journey: Incident Event Signals A Workflow

Use this journey when an incident, alerting, or ticketing system needs to wake
an existing remediation workflow.

```json
{
  "action": "signal_workflow",
  "idempotency_key": "pagerduty-event-3003",
  "target": {
    "workflow_id": "wf-remediation-42",
    "signal_name": "incident_escalated"
  },
  "input": {
    "severity": "critical",
    "service": "checkout"
  },
  "correlation": {
    "provider": "pagerduty",
    "event_type": "incident.triggered"
  }
}
```

Expected outcomes:

- first delivery: HTTP 202, `outcome: accepted`
- redelivery with the same adapter, idempotency key, workflow id, and signal
  name: HTTP 200, `outcome: duplicate`,
  `control_plane_outcome: deduped_existing_command`
- missing workflow: HTTP 422, `outcome: rejected`, `reason: unknown_target`
- malformed target: HTTP 422, `outcome: rejected`, `reason: malformed_payload`

The accepted command context records `adapter`, `action`, `idempotency_key`,
`request_id`, and `signal_name`.

## Reference Journey: Commerce Event Starts A Workflow

Use this journey when a commerce or SaaS integration receives a provider event
that should start one durable workflow.

```json
{
  "action": "start_workflow",
  "idempotency_key": "stripe-event-1001",
  "target": {
    "workflow_type": "orders.fulfillment",
    "task_queue": "external-workflows",
    "business_key": "order-1001",
    "duplicate_policy": "use_existing"
  },
  "input": {
    "order_id": "order-1001"
  },
  "correlation": {
    "provider": "stripe",
    "event_type": "checkout.session.completed"
  }
}
```

Expected outcomes:

- first delivery: HTTP 202, `outcome: accepted`,
  `control_plane_outcome: started_new`
- redelivery while the workflow is still active: HTTP 200,
  `outcome: duplicate`, `reason: duplicate_start`,
  `control_plane_outcome: returned_existing_active`
- unconfigured workflow type: HTTP 422, `outcome: rejected`,
  `reason: unknown_target`
- unsupported action: HTTP 422, `outcome: rejected`,
  `reason: unsupported_action`

When no explicit `workflow_id` is supplied, the server derives a stable
`bridge-{adapter}-{hash}` workflow id from the adapter and idempotency key.

## Outcome Shape

All bridge responses include the contract identity:

```json
{
  "schema": "durable-workflow.v2.bridge-adapter-outcome.contract",
  "version": 1,
  "adapter": "stripe",
  "action": "start_workflow",
  "accepted": true,
  "outcome": "accepted",
  "idempotency_key": "stripe-event-1001",
  "target": {
    "workflow_type": "orders.fulfillment",
    "task_queue": "external-workflows",
    "business_key": "order-1001"
  },
  "workflow_id": "bridge-stripe-...",
  "run_id": "..."
}
```

`target` is a redacted, operator-safe target summary. It may include workflow
id, workflow type, signal name, update name, task queue, and business key. It
must not include raw provider payloads or credential material.
