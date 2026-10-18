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
waits in default and named namespaces. Named namespaces accept only a single
`open_signal_wait` command with an absent or null timeout. Non-null signal
timeouts and command batches retain `development_namespace_command_unqualified`.
Signal receipt and application are distinct transitions. A buffered receipt
can precede the opening event; applying it later resolves that wait. These
projection writes do not qualify signal timeouts or the remaining wait families.

Ordinary condition waits use the existing authored `ConditionWaitOpened` identity
and condition key. Unlike a direct signal wait, receipt of external input leaves
the condition open. Its existing `ConditionWaitSatisfied` event resolves the
matching wait when the worker applies that input. A subsequent occurrence has
its own identity even when it uses the same condition key. Resolution and terminal
closure only update retained rows; they do not recreate a missing projection.
This component covers the already admitted default and named-namespace commands.
The [condition-timeout component](timed-condition-waits.md) additionally covers
immediate/durable timers and retained fired waits. Direct signal timeouts have
their [separate component contract](timed-signal-waits.md). Cancellation scopes
and installed-consumer acceptance remain separate gates.

Native completion, continuation and cancellation now write a terminal summary
after their final run and task mutations, in the same transaction. Counters
come from contiguous retained history read in bounded batches, using the stored
JSON order and PHP's canonical byte encoding. Failure counts deduplicate retained
failure IDs and supported typed failure events. Terminal rows clear active
wait/task fields and record closed liveness, duration and the selected/current
run relationship. Imported, pruned or incomplete histories are excluded from
this producer.
Observation reads never rebuild a missing summary.

The native producer and workflow-task delivery share the published history-budget
policy. Configuration is captured when the runtime opens. Each dimension has a
continue-as-new recommendation threshold and a warning threshold:

| Dimension | Recommendation setting | Warning setting |
| --- | --- | --- |
| Events | `DW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD` | `DW_V2_HISTORY_EVENT_WARNING_THRESHOLD` |
| Bytes | `DW_V2_CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD` | `DW_V2_HISTORY_SIZE_BYTES_WARNING_THRESHOLD` |
| Fan-out | `DW_V2_CONTINUE_AS_NEW_FAN_OUT_THRESHOLD` | `DW_V2_HISTORY_FAN_OUT_WARNING_THRESHOLD` |

Each setting accepts its existing `WORKFLOW_V2_` alias. The `DW_V2_` value takes
precedence, including a value that disables a dimension. A typed null falls
back to the legacy name. Values retain the package's integer-cast behavior and
negative values become zero. A zero recommendation threshold disables that
dimension entirely; a zero warning threshold disables only its warning. A
warning above its recommendation threshold clamps to that threshold. These
are advisory measurements and never force a workflow to continue as new.

Initial task delivery and subsequent task-history pages include complete event,
byte and fan-out counters, the recommendation boolean, pressure and ordered
pressure dimensions. Page size does not reduce these counters. A complete
stored summary supplies the counters; an absent or incomplete summary uses a
canonical scan in batches of sixteen events without persisting a projection.
An incomplete retained sequence refuses the native claim and rolls back its
lease, attempt and receipt. Observation reads retain their existing unavailable
behavior. Full published-HTTP comparison of corrupted/pruned histories remains
separate.

Workflow-task summaries now commit with starts, claims, lease renewal, task
failure and explicit repair. A claim changes stored liveness without appending
history. A summary persistence failure rolls back the owning transition,
including its lease and attempt counter. Summaries retain the run's source
timestamp, and active duration stays null until closure.

The active classifier covers native task-only histories, ordinary condition and
direct-signal waits, activity/timer waits, admitted sequential children and
accepted updates. A condition remains waiting after signal receipt and claim,
until the worker applies that input. Direct signal receipt makes its workflow
task ready. The stored summary retains the current external wait's identity and
source independently of task liveness. Each family's component qualification
remains distinct from full observation API acceptance.

The already admitted two-member mixed activity/timer selection uses its
individual waits. Activity liveness takes precedence while an authoritative
activity remains open, followed by timer liveness and the selected workflow
task. The first terminal member must have an immediately following
`SelectionResolved` that binds its physical event ID, event type, durable
operation identity and original selection metadata. A later loser cannot
author a second winner. The bounded history reader checks these relationships
across its sixteen-event pages. Open waits use the published rounded-millisecond
opening timestamp and original wait identity in the committing transaction.
Activity waits use their actual scheduled/started history clock. Activity start can
therefore move the activity behind an earlier timer. Missing rows stay absent.
Activity/timer [all-groups](parallel-all-groups.md), including nested barriers,
retain individual waits without a selection winner. Other grouped leaf families,
nested selection and remaining timeout-backed shapes stay unqualified.

Each summary refresh checks open wait projections against complete typed
history. A missing or inconsistent projection makes the summary unavailable
in that committing transition. Observation reads neither rebuild nor audit it.
Other timeout-backed waits, unsupported concurrent wait families,
compatibility/fleet classification and queue transport diagnostics remain open. These states
remove the earlier summary in the committing transaction and expose unavailable
measurements until their classifier is implemented. Independent wait projections
retain their existing qualification.

The execution controls can write synthetic reference input with
`DW_TEST_SUMMARY_REFERENCE=/path/to/evidence.jsonl`. Run
`scripts/conformance/server-parity/verify-native-history-budget.php` inside the
published PHP image, passing its Composer autoloader and that file. It compares
the actual installed `HistoryBudget::forRun` event, byte and fan-out counters
without querying a reference database. This checks stored counters, separately
from installed SDK/browser acceptance and shared differential qualification.

`DW_TEST_BUDGET_REFERENCE=/path/to/budget.jsonl` collects the separate configured
budget controls, including actual task payloads and stored summary fields.
Pass that file to the same PHP verifier to compare pressure, recommendation and
dimensions as well as counters. The fixture
`tests/Fixtures/HistoryBudget/pressure.json` defines reviewed policy boundaries;
`verify-history-pressure.php` runs them against the installed PHP package using
its Composer autoloader and the fixture path as arguments. The profile control
uses separate processes for startup settings, so tests never mutate another
runtime's process environment.

The same synthetic output includes all retained task metadata. Comparators
select ready/leased tasks when they require an open task, while repair and
replay diagnostics retain their original task evidence.
`scripts/conformance/server-parity/verify-native-task-summary.php` invokes the
installed PHP task-liveness classifier for ready and leased workflows, with
the reference clock fixed to each observation. It compares state and reason
without reference database queries. This is a classifier component check,
separate from full published-PHP observation and installed native consumers.

`scripts/conformance/server-parity/verify-native-external-summary.php` feeds the
actual stored history, original timestamps and task metadata to the installed
PHP condition/signal and liveness classifiers. It checks receipt and application
as separate states without issuing reference database queries.

`scripts/conformance/server-parity/verify-native-selection-summary.php` compares
actual selection histories, physical event identities and retained tasks with
the installed activity, timer, wait and liveness projectors. It checks liveness,
task-problem state and open-wait count, identity, order, kind and reason.
Ready/leased retry labels follow the published current-wait refinement.
This component comparison does not compare current-wait deadline refinement or
establish full published observation API equivalence.

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
