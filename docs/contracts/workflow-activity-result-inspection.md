# Inspecting one activity result

This is the existing PHP control-plane contract from [Server #439](https://github.com/durable-workflow/server/issues/439)
and [Waterline #180](https://github.com/durable-workflow/waterline/issues/180#issuecomment-6157586629).
The native implementation and shared consumer qualification belong to Server #325.

`GET /api/workflows/{workflowId}/runs/{runId}/activities/{activityId}/result`
uses control-plane version 2 and the resolved namespace. Operator and admin
credentials may read. Authentication and role checks precede namespace and
object lookup. The selected run must belong to the exact namespace and workflow,
and the activity must belong to that run. Missing and foreign identities return
404. Database collation must not make a different identifier equivalent.

The response contains `workflow_id`, `run_id`, `activity_id`, `state`,
`available` and `reason`. A retained activity history event also supplies its
original `history_sequence`. The endpoint selects only the requested activity's
latest canonical history snapshot. It does not hydrate the run history or any
other activity's arguments or results. The activity listing remains metadata-only.

| State | Meaning |
| --- | --- |
| `available` | A completed canonical activity event retains an Avro result envelope. |
| `pending` | The canonical activity is pending or running, including an admitted retry. |
| `failed` | The canonical activity failed or timed out. |
| `cancelled` | The canonical activity was cancelled. |
| `pruned` | The run records pruned details and only a matching activity row survives. |
| `unsupported` | Retained history cannot establish a supported canonical outcome. |
| `unavailable` | A completed canonical event has no usable retained result envelope. |

Only `available` includes `result_envelope`, with `available: true` and
`reason: null`. An Avro null or false is an available value. JSON null or an
absent result field is not an encoded null. Other states have `available: false`
and a diagnostic reason, with no result envelope. The canonical activity status
governs the state, rather than a failed attempt when a retry is pending.

The snapshot identity must match the request and any top-level activity identity.
For a completed event, its nested and top-level result values, when both are
present, must agree. Local activities and non-Avro codecs are explicitly
unsupported. A mutable activity result cannot replace missing history. Retained
history remains readable after the mutable activity row is removed or the parent
workflow closes. The parent workflow's output is a separate value.

Inline results use `{codec: "avro", blob: "..."}`. External results reuse the
existing authenticated opaque `external_payload` envelope. Selecting the envelope
does not fetch the object or prove its current provider availability. A client
hydrating it performs the existing size and checksum checks through the payload
transport. Missing or corrupt provider bytes remain explicit transport failures.
Provider locations and unrelated result references are not exposed.

The optional discovery boolean is
`capabilities.workflow_activity_result_inspection` on `/api/cluster/info`.
There is no new worker protocol floor. The native candidate must not advertise
this capability before its endpoint, authorization, namespace and payload cases
are qualified. Listing and Waterline browser acceptance are separate gates.

## Shared qualification

`tests/Fixtures/ActivityResultInspection/v1.json` defines the expected cases.
The separate published reference tuple is in the adjacent `profile.json`.
It does not replace any frozen Server parity or query-authority cohort.

Run the same genuine producer and normally installed PHP SDK reader against
independent published PHP and native databases. Include null, false, exact int64,
Unicode, a large external result, a genuine retry and a parent with a different
output. Preserve the original observation budgets, activity/run deadlines,
event sequences and decoded values. Compare history before and after reads.
Exercise default and named namespaces and actual role-scoped credentials.

Retained-history corruption, pruning, mutable-row disagreement and local-activity
snapshots are separate negative database controls, never genuine producer claims.
Embedded backend handlers exercise applicable canonical outcome semantics without
claiming that an HTTP endpoint exists in embedded mode. SQLite, MariaDB/MySQL and
PostgreSQL checks and actual installed SDK reads precede a parity claim. No image,
release, external storage migration or Waterline acceptance follows from source
tests alone.
