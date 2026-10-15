# Bounded workflow run observation

Server #325 carries the existing PHP observation API into the native runtime.
Waterline uses this read for its initial run page, before requesting full details
or an individual activity result. The PHP reference is
[`WorkflowObservationTest`](../../tests/Feature/WorkflowObservationTest.php),
with the published tuple in the
[browser consumer profile](../../scripts/conformance/activity-result-browser/profile.json).

The routes are `GET /api/workflows/{workflow_id}/observation` and
`GET /api/workflows/{workflow_id}/runs/{run_id}/observation`. They use control-plane
version 2 and the resolved namespace. Ordinary operator/admin read authorization
applies. The response operation is `observe_workflow` and `read_mode` is `bounded`.

## Identity and evidence

The instance route follows its stored current-run pointer only when that run
belongs to the same workflow and namespace. Otherwise it returns 404 with
`current_run_observation_unavailable`. The selected-run route binds the exact
workflow, run and namespace, returning `run_not_found` for an unavailable run.
Neither route repairs a pointer, rebuilds projections or issues commands.

The selected identity remains independent from the observed current identity.
An invalid current pointer makes `current_run_id`, `current_run_status` and
`is_current_run` null on a valid selected-run read. `current_run_state` is then
`unavailable`. A valid pointer supplies `observed` state, not proof of lineage.
`current_run_audit` and `history_audit` remain `not_evaluated`.

Top-level metadata omits arguments, output and full diagnostics. Command
declarations and the recorded definition fingerprint come from the retained
`WorkflowStarted` event. Missing or incomplete declarations must keep their
existing unavailable/backfill semantics, without making a read perform backfill.

Stored summaries retain their source and observation time. A missing summary
has `summary_state: unavailable` and null summary measurements. It is not zero
failures, an empty history or healthy liveness. The native runtime must not
manufacture successful projections to make a consumer comparison pass.

## Bounded related data

Initial observation reads at most 50 current waits, 50 parents, 50 children and
10 recent failures, with the existing counts and `has_more` fields. Related run
metadata is selected without loading child histories or application payloads.
Missing related metadata and supporting failure history remain explicit.
Projected failure handling is diagnostic and is not a full history audit.

Terminal runs have an available empty current-wait set. Pruned runs report
`pruned`. An active run without projected waits reports `unavailable`, with
`current_wait_projection_unavailable`; it must not claim there are no waits.
Existing task/activity metadata determines planned resume, deadline and retry
information for retained waits. Only a matching dependency can supply timing.

`search_attribute_keys` selects at most 20 distinct nonempty strings, each
within the existing 255-character server bound. No keys means no attribute read.
Unselected attributes are omitted. Retain typed selected values and validate
parameters before reading application context.

## History window

`history_page_size` is an integer from 1 to 1000, defaulting to 200.
`history_page_token` is an opaque cursor of at most 4096 characters. A cursor
belongs to one run and carries both the exclusive last sequence and original
inclusive ceiling. Later appends do not extend that window. Invalid cursors or
cursors for another run return validation failure.

The history response includes original event IDs, sequences, types, timestamps,
task/command identities and stored payload envelopes, in sequence order. It
does not decode application values or fetch external objects. It reports
`returned_count`, `limit`, `after_sequence`, `through_sequence`, first/last
sequences, `history_window_from_start`, `has_more`, `next_sequence` and
`next_page_token`. The final page has no next token. `details_state` explicitly
distinguishes retained and pruned details.

Failure references can select their retained supporting event within its own
bounded history window. A missing event remains unavailable. Cursor transport
is opaque; interoperability with previously issued PHP cursors remains an
explicit migration qualification requirement.

The native draft authenticates its versioned cursor with HMAC-SHA256 using the
existing `DW_AUTH_TOKEN` and a cursor-specific domain. The token binds namespace,
workflow, run and both sequence bounds. Native nodes sharing that configured key
can continue the same window after a process restart. Rotating the key invalidates
outstanding cursors; restart the observation from its first page. PHP cursor
compatibility is still unimplemented and remains a migration gate.

## Qualification

The draft produces activity and ordinary timer waits, plus ordinary signal
waits in the default namespace. Named-namespace `open_signal_wait` retains its
`development_namespace_command_unqualified` refusal until separately qualified.
Signal receipt and application are distinct transitions. A buffered receipt
can precede the opening event; applying it later resolves that wait. These
projection writes do not qualify signal timeouts or the remaining wait families.

Exercise current and selected runs, default and named namespaces, invalid
current pointers, absent summaries, typed selected context, growing histories,
pruned details, and bounded relationship/wait/failure collections. Separate
constructed stored-state controls from genuine workflow execution.

Normally installed SDK readers must observe genuine workflows on independent
published PHP and native databases, preserving history before and after reads.
Then run the unchanged Waterline browser recipe through the canonical run page.
SQLite, MariaDB/MySQL and PostgreSQL qualification is required. Source fixtures,
an endpoint implementation or an explicit unavailable response alone do not
establish complete operational parity, native browser acceptance or release
readiness.
