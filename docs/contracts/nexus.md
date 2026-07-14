# Nexus Contract

Nexus is the durable cross-namespace call surface: a workflow in namespace
A invokes a service or workflow registered in namespace B and gets the
same durability, retry, and observability semantics as a normal activity
call, while namespace security boundaries are still enforced server-side.

This contract names the wire surface and the durable-record shape every
SDK must target to implement Nexus calls and Nexus services. It does not
introduce a new primitive — it is the parity-named view of the existing
cross-namespace service catalog and service-call lifecycle. Where this
contract uses a name from the underlying service-execution contract, the
underlying contract remains authoritative for shape evolution.

The authoritative machine-readable contract is published from
`GET /api/cluster/info` at `nexus_contract`:

- `schema: durable-workflow.v2.nexus.contract`
- `version: 2`
- `result_schema: durable-workflow.v2.nexus-runtime.result`
- `parity_target` — the parity name and the per-pair-integration
  guarantee Nexus replaces.
- `underlying_execution_contract` — the schema string of the contract
  (`durable-workflow.v2.service-execution.contract`) that owns the
  lifecycle / outcome / handler-binding shape.
- `addressing` — the field set callers use to identify a Nexus
  operation, the caller workflow run, and the durable call id.
- `wire_surface` — every HTTP route an SDK must call (registration,
  invocation, describe, cancel, caller-side history index).
- `operation_modes`, `lifecycle_statuses`, `outcomes`,
  `handler_binding_kinds` — re-exports of the underlying
  service-execution contract's enumerations, kept in lock-step.
- `retry_durability` — the durability and retry contract a Nexus call
  inherits from the durable record.
- `namespace_acl_enforcement` — the points where the boundary gate
  enforces target-namespace ACLs.
- `multi_namespace_caller_pattern` — the contract that one Nexus
  service in namespace B serves callers from any A1..An without
  per-caller registration.
- `caller_history_surface` — the per-workflow caller-indexed view of
  outbound Nexus calls.
- `artifact_policy`, `required_matrix`, `required_scenarios`,
  `scenario_evidence_requirements`, and `coverage_gate` — the
  published-artifact conformance cells and pass evidence a host runner
  must exercise before Nexus can count as passing product evidence.
- `host_runner_contract` — the source-free handoff at
  `scripts/conformance/nexus-published-artifacts.sh`. If a host reaches
  this handoff but leaves required cells uncovered, the handoff records
  focused `not_covered` findings with `runnerBlocked=false` rather than
  another runner-blocked ledger row.
- `result_gate` and `finding_policy` — the pass requirements and
  root-cause routing for failures, unsupported cells, and coverage gaps.
  The gate rejects `pass` when any required artifact version is missing,
  rolling, or placeholder-like (`latest`, `current`, `head`, `1.x`, and
  similar), when any required artifact source is missing, source-like, or on
  the wrong published channel for the artifact (`durableworkflow/server` image,
  `durable-workflow/cli` release asset, Packagist Workflow/Waterline package,
  or PyPI `durable-workflow` package), when `artifact_source_verification`
  does not prove that each exact source resolved to a downloadable public
  asset, package, or image manifest,
  when pass scenarios omit their advertised scenario-specific evidence,
  when the shared-service tenant invocation cells omit the concrete
  request, response, service-call record, or caller-history evidence used
  to prove that `tenant-a` and `tenant-b` reached the same shared
  `Greeter.greet` endpoint,
  when PHP/Python service-call matrix cells omit caller workflow id/run id,
  caller and service SDK language, operation name, request payload,
  response-or-failure surface, durable service-call id, artifact tuple, or
  published `durable-workflow/sdk` PHP caller-process evidence plus
  Workflow PHP and Python service-worker execution evidence,
  when replay evidence omits call ids, caller-history rows, target
  service logs, restart timing, a single-invocation count, or a
  duplicate-call assertion, when cancellation evidence omits
  caller-history rows, target service logs, cancellation timing, the
  measured propagation duration, documented-window confirmation, or the
  typed cancellation observed by the service,
  when adversarial refusal cells omit request, response, no-dispatch
  evidence, caller-history query evidence, successful caller-history query
  status, or proven caller-history state, when retry-attempt visibility or
  authorization non-disclosure evidence contradicts the Nexus contract, when the
  source-free evidence field is omitted, or when the evidence reports local
  product source checkouts as artifacts under test.
- `sdk_implementation_notes` — the rules SDKs must follow when wiring
  Nexus calls and Nexus services into workflow code.
- `out_of_scope` — the surfaces explicitly outside the Nexus contract.

## Why Nexus

The cross-namespace service-call layer
(`docs/architecture/workflow-service-calls-architecture.md` in the
workflow runtime) gives every cross-namespace invocation a durable
record, a lifecycle enum, an outcome enum, and a per-namespace boundary
policy. The Nexus contract is the parity-named view of that layer:

- A workflow author calling another namespace addresses a single triple
  (endpoint / service / operation) and gets back a durable
  service-call id. They do not write per-pair integration code, do not
  manage cross-cluster replication, and do not invent retry semantics.
- A namespace publishing a service registers it once. The same service
  serves callers from any number of caller namespaces; the boundary
  gate evaluates each call against the publishing namespace's policy.
- The wire surface is published in `cluster_info` so every SDK
  implements Nexus against the same contract. SDKs that target the
  Temporal Nexus shape map their Nexus operation onto this triple
  directly.

Nexus is durable cross-namespace calls within a durable-workflow
cluster. It is not a generalized service mesh, not a sidecar fabric,
and not an external-HTTP outbound surface — those belong to other
contracts.

## Addressing

Every Nexus call is addressed by:

| Field | Source | Purpose |
|---|---|---|
| `endpoint_name` | URL path | Logical endpoint within the target namespace. |
| `service_name` | URL path | Service exposed under the endpoint. |
| `operation_name` | URL path | Operation on the service. |
| `caller_namespace` | request body or principal-resolved namespace | Originating caller namespace recorded on the durable call row. |
| `caller_workflow_instance_id` | request body | Originating workflow instance, when the caller is a workflow. |
| `caller_workflow_run_id` | request body | Originating workflow run, when the caller is a workflow. |
| `idempotency_key` | request body | Caller-supplied de-duplication key; same key + same target collapses retries onto one durable row. |
| `service_call_id` | server-generated | Durable id of the call row; returned to the caller in the admission response. |

The target namespace is resolved from the catalog row, never from the
caller. A caller cannot bypass namespace ACLs by addressing a different
target.

## Wire surface

Nexus rides the existing service-catalog routes. SDKs implement Nexus
calls and Nexus services by calling these routes directly:

| Purpose | Route |
|---|---|
| Register a Nexus endpoint | `POST /api/service-endpoints` |
| Register a Nexus service under an endpoint | `POST /api/service-endpoints/{endpoint}/services` |
| Register an operation on a service | `POST /api/service-endpoints/{endpoint}/services/{service}/operations` |
| Invoke a Nexus operation | `POST /api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/execute` |
| Describe a single Nexus call | `GET /api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/service-calls/{serviceCallId}` |
| Cancel a Nexus call | `POST /api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/service-calls/{serviceCallId}/cancel` |
| List all Nexus calls a workflow has scheduled | `GET /api/workflows/{workflowId}/nexus-operations` |
| List Nexus calls scoped to a single run | `GET /api/workflows/{workflowId}/runs/{runId}/nexus-operations` |

The execute, describe, and cancel surfaces require the operator role.
Catalog mutations require admin. The caller-history surface requires
operator and is read-only.

## Durable record and retry semantics

Every accepted Nexus call writes one row in `workflow_service_calls`
keyed by the durable service-call id (a ULID). The row carries:

- the resolved handler binding (`workflow_run`, `workflow_update`,
  `workflow_signal`, `workflow_query`, `activity_execution`, or
  `invocable_carrier_request`) and the linked target reference;
- the operation mode (`sync`, `async`, or
  `sync_with_durable_reference`);
- the lifecycle status (`pending`, `accepted`, `started`, `completed`,
  `failed`, `cancelled`) and the boundary outcome (one of the eleven
  values published in the `outcomes` field);
- the deadline / idempotency / cancellation / retry policies effective
  at admission time, snapped onto the row so they are stable for the
  lifetime of the call;
- the per-attempt service-call retry record, including attempt number,
  observed outcome, failure type, and scheduled backoff before the next
  attempt when retry is allowed;
- the caller principal that admitted the call (subject, method, roles,
  tenant, claims), recorded server-side from the authenticated
  request.

The retry policy follows the activity-style retry contract: maximum
attempts, initial interval, backoff coefficient, maximum interval,
non-retryable failure classes. The recorded retry policy on the row is
authoritative; SDKs do not duplicate retries client-side when the
server has already accepted the call. Handler failures retry according
to that policy, and every attempt is appended to the service-call
metadata so the caller-history and detail routes expose the exact retry
shape. A caller worker that crashes mid-call resumes by replaying its
workflow code with the same idempotency key and recovers the same
durable service-call id.

## Namespace ACL enforcement

The boundary gate (`App\Support\ServiceCallBoundary`) admits or
rejects every call before the handler is dispatched. Enforcement is
server-side and not bypassable by the caller:

- The principal is resolved from the authenticated request, not from
  the request body.
- The caller namespace recorded on the row is the request namespace
  (or the request's explicit `caller_namespace` when the principal is
  authorized to set it). A caller cannot impersonate another namespace
  by sending a forged `caller_namespace`.
- The boundary policy evaluates endpoint-level, service-level, and
  operation-level rules. Forbidden, throttled, concurrency-limited,
  and circuit-open rejections are all distinct outcomes, recorded on
  the row, and reported back to the caller.
- An admission row is written for both accepted and rejected calls,
  so the audit trail is the same shape regardless of outcome.

The audit trail keeps the principal subject and method on every row;
operators querying for a caller's recent activity see the same row
shape regardless of outcome.

## Multi-namespace callers

A Nexus service in namespace B is registered once and is callable from
workflows in any number of caller namespaces A1..An. There is no
per-caller registration step:

- Each call records its own `caller_namespace`, `caller_workflow_run_id`,
  and `caller_principal_subject`.
- The boundary gate evaluates each caller independently against the
  target namespace's policy. A throttled caller does not block other
  callers; a forbidden caller does not block other callers.
- The catalog rows in B describe what is published, not who is allowed
  to call. Allowed callers are derived from the boundary policy at
  admission time.

This is what lets a single Nexus service in B serve fan-in traffic
from a fleet of caller namespaces without explicit routing tables.

## Caller-side observability

Every Nexus call is visible to the caller's workflow through the
caller-history surface:

```text
GET /api/workflows/{workflowId}/nexus-operations
GET /api/workflows/{workflowId}/runs/{runId}/nexus-operations
```

These return every `workflow_service_calls` row whose
`caller_workflow_instance_id` matches, ordered by `accepted_at`
descending. Each row carries the durable service-call id, the resolved
binding, the lifecycle status, the outcome, the linked target
reference, the retry policy, per-attempt retry records, the typed
service error metadata (`service_error_type`,
`caller_observed_error_type`, and `typed_error_message`) when a worker
rejects the operation, the caller principal that admitted it, and the
closure timestamps. Operators
debugging a failed run answer "what cross-namespace calls did this
workflow make and how did each attempt settle?" from this single surface
— without inspecting raw transport logs or the per-target catalog index.

The list is bounded by the `max_nexus_operations_per_caller` limit
(default 200) and accepts a `limit` query parameter that may not exceed
the configured maximum.

## Operation modes

Nexus inherits the three modes published by the underlying
service-execution contract:

- `sync` — the caller blocks until the call reaches a terminal state;
  the durable id is still recorded.
- `async` — the caller receives the durable id at admission and
  observes the terminal outcome later through the call id or the
  caller-history surface.
- `sync_with_durable_reference` — the caller blocks up to the deadline,
  but the durable id is committed early enough that an expired deadline
  leaves the caller with the id.

The contract is symmetric across modes: a sync caller has the same
durable record as an async caller. Sync mode is a delivery preference
on top of the durable record, not an alternative to it.

## SDK implementation notes

SDKs implementing Nexus calls and Nexus services MUST:

- Address operations through the endpoint / service / operation triple.
  The handler-binding kind is an internal resolution detail and MUST
  NOT be assumed by callers.
- Default to the `async` operation mode unless the caller explicitly
  opts into a sync mode.
- When the caller supplies an `idempotency_key` matching an existing
  in-flight or terminal call in the same target namespace + operation,
  return the existing call rather than admitting a new one.
- Treat the recorded retry policy on the call row as authoritative;
  do not duplicate retries client-side once the server has accepted
  the call.
- Surface the durable service-call id to user code so the caller
  workflow can record it in its own observability surface (logs,
  search attributes, memos).

## Out of scope

- Generalized service mesh / sidecar fabric / cross-cluster routing
  layer. Nexus is durable cross-namespace calls within a durable-workflow
  cluster.
- Arbitrary external HTTP egress. Outbound HTTP belongs in the
  invocable-carrier surface, which Nexus reuses internally only as one
  of its handler-binding kinds.

## Cross-references

- Underlying execution contract:
  `durable-workflow.v2.service-execution.contract`, exported from
  `GET /api/cluster/info` at `service_execution_contract`.
- Cross-namespace service-call lifecycle and outcome contract:
  `docs/architecture/workflow-service-calls-architecture.md` in the
  workflow runtime.
- Cross-namespace service policy:
  `docs/architecture/cross-namespace-service-policy.md` in the workflow
  runtime.
- Auth composition contract:
  `docs/contracts/auth-composition.md` for principal resolution.
- Parity tracker row that owns the Nexus capability:
  `docs/temporal-compatibility-parity.md`.
