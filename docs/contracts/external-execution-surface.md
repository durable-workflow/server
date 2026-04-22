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
- `handler_mappings`: `worker_protocol.external_executor_config_contract`
- `bridge_adapters`
- `payload_external_storage`
- `admission_and_rollout_safety`

Handler mappings are config-first. The server reads the optional
`DW_EXTERNAL_EXECUTOR_CONFIG_PATH` JSON file using the shared
`durable-workflow.external-executor.config` schema published by `dw
schema:show external-executor-config`. `DW_EXTERNAL_EXECUTOR_CONFIG_OVERLAY`
selects a named overlay before validation. Cluster discovery reports only the
config basename and a path digest, plus mapping counts and named validation
errors such as `unknown_carrier`, `unknown_auth_ref`,
`unknown_handler`, `duplicate_mapping_name`, `invalid_queue_binding`,
`missing_handler_target`, and `unsupported_carrier_capability`.

Valid carriers include poll-based CLI or daemon handlers, HTTP handler
invocation, queue-backed workers, and serverless invocation. A carrier is valid
only when it preserves task identity, attempt, and idempotency key; emits the
declared input schema; accepts the declared result schema; maps transport
failures to structured failure or malformed-output outcomes; and resolves auth,
TLS, profile, and environment inputs deterministically.

Stable adjacent contract docs live in:

- `docs/contracts/bridge-adapters.md`
- `docs/contracts/external-task-input.md`
- `docs/contracts/external-task-result.md`
