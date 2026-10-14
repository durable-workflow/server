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
checks the full stored precision. This is a PHP baseline; embedded and native
acceptance remain pending. Temporary runtime and raw evidence are removed
after the immediate audit.
