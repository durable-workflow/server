# External Task Input Envelope

The external task input envelope is the carrier-neutral JSON shape for one
leased external task. It is not tied to CLI stdin, HTTP request bodies, or one
runtime language.

The authoritative machine-readable contract is published from
`GET /api/cluster/info` at
`worker_protocol.external_task_input_contract`:

- `schema: durable-workflow.v2.external-task-input.contract`
- `version: 1`
- `scope.activity_grade_external_execution`
- `scope.worker_protocol_runtime`
- `envelopes.workflow_task`
- `envelopes.activity_task`
- `fixtures.workflow_task`
- `fixtures.activity_task`

The scope split is intentional. `activity_task` is the activity-grade external
execution input that external carriers execute as bounded handler work and pair
with the external task result envelope. `workflow_task` remains published in the
same manifest for SDK/runtime compatibility and drift tests, but it belongs to
the worker-protocol runtime scope. A carrier must not treat `workflow_task` as a
generic external handler input unless that carrier is a workflow runtime that
owns replay, history interpretation, ContinueAsNew, and command ordering.

Each fixture is published as a consumable artifact object, not as a repository
path. Carriers can read the `artifact`, `media_type`, `schema`, `version`,
`sha256`, and embedded `example` fields directly from cluster info.

Version 1 exposes durable facts only: task identity, task kind, attempt number,
task queue, handler name, workflow/run identity, lease owner and expiry,
payload metadata, deadlines where relevant, headers, and an idempotency key.
Activity-task inputs also expose configured external-executor mappings when the
server resolves one. Workflow-task inputs include history paging metadata and the
stable resume context fields already returned by the worker protocol.

Unknown fields are additive. Handlers must ignore unknown optional fields, but
they must fail closed when the contract advertises a required field for a schema
version they do not support. Adding required fields or renaming/removing fields
requires a major contract version.

Payloads are always codec-tagged. A handler that cannot decode `payload.codec`
must fail the task with `unsupported_payload_codec`. A handler that receives an
external-storage payload reference for a provider it does not support must fail
the task with `unsupported_payload_reference`; it must not silently treat the
reference as an inline payload.

The stable fixture artifacts are:

- `durable-workflow.v2.external-task-input.workflow-task.v1`
- `durable-workflow.v2.external-task-input.activity-task.v1`
