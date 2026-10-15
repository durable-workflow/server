# Native run activity metadata listing

The development route is `GET /api/workflows/{workflowId}/runs/{runId}/activities`.
It follows the existing PHP metadata-only listing. PHP remains the published
runtime; this route does not qualify the full activity family or native release.

Authentication, operator/admin authorization, control-plane version and namespace
checks precede the read. The stored namespace, workflow ID and run ID must match
literally, including on databases whose default collation ignores case. A missing
or foreign run returns 404. Reading a closed run does not reopen it or append history.

The response contains `workflow_id`, `run_id` and `activities`. Activity fields
include durable identity/sequence/type/class, history authority and event kinds,
status, retry policy, routing, local/reused/parallel metadata, heartbeat progress,
timestamps and the separate attempt list. It omits arguments, results and
exceptions. The read does not decode Avro or fetch external objects.

Typed activity history takes precedence over a mutable activity row. Attempt
history takes precedence over mutable attempt fields, including a terminal
`lease_expires_at: null`. Open rows without history have
`history_authority: mutable_open_fallback` and `diagnostic_only: true`.
Terminal rows without history report `status: unsupported`, preserve `row_status`
and explain `terminal_activity_row_without_typed_history`.

Attempt flags retain the released PHP projection semantics. In particular,
`can_continue` in a listing is a recorded-state comparison. It grants no worker
authority and does not replace the non-renewing activity status endpoint or
atomic completion, failure and heartbeat fences.

The native read limits each history/activity/attempt collection to 10,000 rows
and activity history JSON to 8 MiB. SQL masks over-budget history payloads before
driver allocation; the API refuses the whole read with HTTP 413 and reason
`activity_metadata_exceeds_read_budget`. It never returns a truncated activity
list. Unrelated event payloads and mutable argument/result/exception columns are
not selected. Projection work uses the existing bounded blocking-worker permit.

The [projection corpus](../../tests/Fixtures/ActivityMetadataProjection/README.md)
records the complete output of 31 constructed loaded-model cases through exact
released PHP projector bytes. It includes PHP timestamp rounding, retry attempts,
terminal lease clearing, diagnostic fallback and external metadata without
provider access. These cases do not claim genuine history production. Native
HTTP tests separately exercise execution and constructed damaged/pruned-reader
controls. The installed SDK consumer additionally compares eight metadata states
from genuine retry/heartbeat/completion workflows against separate published PHP
and native databases. Its initial SQLite, PostgreSQL 17 and MySQL 8.0 comparisons
pass in two namespaces. Remaining database configurations, browser acceptance and
capability promotion remain separate gates.
