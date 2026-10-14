# Continue-as-new parity

The pending `continue-initial-memo-activity-replay` fixture defines a genuine
activity checkpoint, cold predecessor continuation and cold successor result.
It is outside default discovery until its published PHP and embedded baselines
and native implementation qualify. No capability is enabled by this fixture.

Four normally installed worker processes author the original activity,
complete it, replay the predecessor into continue-as-new and complete the
successor. The successor starts with the same logical workflow and namespace,
a distinct run ID, inherited typed memo and the original execution deadline.
Its run deadline follows the published reset from its own start. The completed
predecessor has `closed_reason=continued`, one continuation history event and
one canonical link. Resubmitting the original continuation completion must
preserve the same successor and both histories. Terminal HTTP observations
retain their published meanings and do not imply a stronger receipt contract.
Embedded mode redelivers the same completed framework task through the real
database queue on an isolated duplicate-delivery queue. It checks no new
canonical work or history and does not claim an HTTP completion receipt.
The published embedded start path records a successor `StartAccepted` before
`WorkflowStarted`, while the service bridge records only `WorkflowStarted`.
Both event orders are declared explicitly and their original prefixes stay
unchanged. The initial embedded observer incorrectly indexed the first event
as `WorkflowStarted`; that original observer failed. A new original using the
corrected observer passed without extending either observation budget.

The original 30-second phase budgets and 120-second overall observation budget
are fixed before admission. There is one activity invocation and completion.
Its full retry snapshot, original deadlines and typed int64/null/Unicode value
survive the cold boundary. Initial memo null deletion and empty-list behavior
follow the separately qualified initial JSON boundary.

This first case does not qualify pending signal/update/stream transfer,
continued children, cancellation races, external envelopes, namespace routing
or full lifecycle parity. Those remain required under #325. The native runtime
currently refuses continue-as-new; published baseline actors and contract
checks precede its implementation.

The first normal published PHP original completes the four phases with one
activity invocation, six predecessor events, two successor events and a 409
`run_closed` duplicate that leaves exactly two runs. The strict reader and
actual-record corruption controls pass. A stopped, read-only SQLite audit
confirms both completed runs, one continuation link, four completed first-
attempt tasks with zero repairs, and inherited memo sequence authority at 1.
Both exact stored run deadlines retain their microseconds, and the original
execution deadline remains byte-identical. The HTTP describe deadline uses
whole seconds; the reader checks its displayed precision and the SQL audit
checks the full stored precision.

The normal installed embedded 2.5.16 baseline also passes four cold processes,
one actual activity, six predecessor events and three successor events. The
real database queue redelivers the original completed task without another
continuation, history change or activity invocation. All 29 actual-record
corruption controls refuse. A stopped read-only SQLite audit confirms exactly
two completed runs, one canonical continuation link, four completed tasks at
attempt 1 with zero repairs, no remaining or failed queue jobs, and memo
inheritance at sequence 1. The original execution deadline stays byte-identical
and the successor run deadline resets from its own start with exact
microseconds. These are published PHP and embedded baselines; native
continue-as-new acceptance remains pending. Disposable runtime, database and
raw records are removed after the immediate audit.
