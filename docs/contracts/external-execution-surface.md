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
- `auth_profile_tls_composition`: `auth_composition_contract`
- `handler_mappings`: `worker_protocol.external_executor_config_contract`
- `invocable_http_carrier`: `worker_protocol.invocable_carrier_contract`
- `bridge_adapters`: `bridge_adapter_outcome_contract`
- `runtime_external_payload_transport`: `namespace.external_payload_storage`
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

The first concrete invocable carrier is `invocable_http`, published at
`worker_protocol.invocable_carrier_contract`. It is activity-task only. Its
config target must declare an absolute HTTPS URL, may use HTTP only for
loopback development targets, must not embed URL credentials, may declare
`method: POST`, may declare a bounded `timeout_seconds` value, and may declare
a bounded transport-only `retry_policy`. Non-loopback mappings must resolve an
`auth_ref`; unauthenticated invocable HTTP is limited to loopback development
targets. Carrier retries are for transient HTTP delivery before result reporting;
durable activity retry remains the
server/runtime authority once a handler result is reported. The server validates
malformed invocable carrier config fail-closed through
`invalid_carrier_target`, `missing_invocable_auth_ref`, and
`invalid_invocable_carrier_scope` before exposing mappings on activity poll
responses. Actual dispatch still belongs to a carrier implementation; this
contract freezes the request, response, auth, failure, and rollout boundary.

Stable adjacent contract docs live in:

- `docs/contracts/bridge-adapters.md`
- `docs/contracts/external-task-input.md`
- `docs/contracts/external-task-result.md`

## Candidate cooperative activity stop receipts

The cooperative cancellation draft adds remote callback-stop receipts at worker
protocol 1.20. The default remains 1.19 and the candidate contract is not frozen.
The installed Native runtime must provide the acknowledgment primitive. Discovery
advertises `server_capabilities.activity_cancellation_acknowledgement` only when
both conditions hold.

`POST /api/worker/activity-tasks/{taskId}/status` remains read-only. After canonical
cooperative cancellation it reports the original request, root identity, immutable
cleanup deadline and cancellation history event. `callback_state: unknown` means
that the worker has not reported an actual stop. A cancelled durable row or
expired lease does not make that state `stopped`.

The original cooperative worker may post `activity_attempt_id`, `lease_owner`
and `request_id` to `/acknowledge-cancellation` after stopping and joining the
remote callback. The Server checks the namespace, task, original claim capability,
attempt and canonical cancellation snapshot in a fenced transaction. A later
registration or request protocol cannot upgrade an old claim. Duplicates return
the original receipt event and late receipt remains explicitly late.

This report grants no renewed lease, application heartbeat, result authority or
cleanup budget. It describes the worker's stopped callback, not reversal of
external effects. Local activities require separate workflow claim authority.

## Candidate prepared local cancellation policies

An explicit prepared local Activity policy requires candidate protocol 1.20,
the installed bridge's `supportedLocalActivityCancellationPolicies()` list,
and `prepared_local_activity_cancellation_policies` on the original issued
workflow claim. Discovery reports the actual supported policy list in
`server_capabilities.prepared_local_activity_cancellation_policies`. Older
custom prepared bridges do not acquire this support from their optional role.
The list is empty at default protocol 1.19.

`try_cancel` fences publication and releases the durable await without claiming
the callback has stopped. `wait_cancellation_completed` parks delivery until an
original-owner stopped-and-joined receipt or canonical callback outcome exists.
Both retain the original root request and immutable cleanup deadline. Omission
preserves historical TryCancel. Explicit null and local `abandon` are refused.
Independent work should use a remote Activity with a qualified bounded lifetime.

The prepare, recover and atomic group endpoints reject unsupported policies
before resolving payloads or recording any sibling. Refusals identify the
requested policy, installed supported list, original worker claim, missing
capability and remediation. Re-registering a worker cannot upgrade its existing
claim. Replacement polling checks the explicit policy in canonical Scheduled
history before probing and again under the run lock. An incompatible worker
cannot reinterpret that policy through replay.

SDK policy authoring, replay and physical callback supervision need separate
qualification. The installed backend's policy list does not establish those
SDK capabilities or change the default published protocol.
Receipt writes are admitted during storage draining and refused when storage is
fenced. SDK stop/join emission, local receipts and activity waiting policies still
need connected qualification before this candidate is published.
