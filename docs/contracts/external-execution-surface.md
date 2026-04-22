# Activity-Grade External Execution Surface

The external execution surface is the carrier-neutral product contract for
durable, bounded work that can run outside a full workflow runtime. It exists
for operator, platform, and integration automation first. Scripted or
agent-driven handlers can use the same surface, but they do not define it.

The authoritative machine-readable contract is published from
`GET /api/cluster/info` at
`worker_protocol.external_execution_surface_contract`:

- `schema: durable-workflow.v2.external-execution-surface.contract`
- `version: 1`
- `product_boundary.name: activity_grade_external_execution`
- `runtime_boundary.external_handlers_may`
- `runtime_boundary.external_handlers_must_not`
- `contract_seams`
- `carrier_neutrality`

External handlers may execute one leased workflow or activity task, heartbeat
lease progress, and return the declared success or failure envelope. Bridge
adapters may start, signal, update, or hand off bounded work when their route,
auth, duplicate, malformed-payload, and unsupported-routing outcomes are
explicit.

External handlers must not interpret workflow replay semantics, own
ContinueAsNew behavior, apply signal/update/query ordering rules outside the
runtime contract, mutate event history directly, or act as unbounded workflow
runtimes.

Version 1 names these contract seams:

- `input_envelope`: `worker_protocol.external_task_input_contract`
- `result_envelope`: `worker_protocol.external_task_result_contract`
- `auth_profile_tls_composition`
- `handler_mappings`
- `bridge_adapters`
- `payload_external_storage`
- `admission_and_rollout_safety`

Valid carriers include poll-based CLI or daemon handlers, HTTP handler
invocation, queue-backed workers, and serverless invocation. A carrier is valid
only when it preserves task identity, attempt, and idempotency key; emits the
declared input schema; accepts the declared result schema; maps transport
failures to structured failure or malformed-output outcomes; and resolves auth,
TLS, profile, and environment inputs deterministically.

Stable adjacent contract docs live in:

- `docs/contracts/external-task-input.md`
- `docs/contracts/external-task-result.md`
