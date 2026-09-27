# In-flight activity restore boundary

This follow-up used the same published Server 2.4.18, MySQL 8.4, Redis 7.2,
SeaweedFS 4.47 and published Python SDK 2.0.0 tuple as the parent drill. The
source was its first restored SeaweedFS stack. All inputs and credentials were
synthetic. [inflight.py](inflight.py) started a workflow whose activity slept
for 180 seconds. The source [start output](source-start.json) and
[snapshot inventory](source-before.json) show one `ActivityStarted` and no
completion before the SQL snapshot. The [source result after the backup](source-after.json)
completed later, confirming that the activity was still active at the
consistency point.

The owner-scoped payload hold was acquired at 18:36:54 UTC and remained active
through the SQL and object copy. The 303,530-byte SQL file had SHA-256
`7d5e0702347829fcdb94a557896c18973544aef7d3461af92af7385bd0f23cec`.
Five object bodies totaling 54,784 encoded bytes were copied and read back.
The [object manifest](object-manifest.json) had SHA-256
`77f50a355577bbdf4c47c8b8868d8d91b7bcd93e1f6d7fd39b065926df6c9179`.
The hold was active at 18:37:19 UTC and released at 18:37:25 UTC. Dump and
import exited 0; the retained stderr files contain only MySQL's
password-on-command-line warning. SQL and object bodies remain protected local
drill artifacts and are not published.

## Published Compose recovery gap

The first fresh replacement used the unchanged published Compose file. Its
[pre-resume inventory](restore-before.json) was byte-identical to the source
snapshot. The activity attempt's lease expired at 18:41:37 UTC, but the task
remained leased. [Task state](task-before.tsv) and
[repair diagnostics](repair-before.json) show one eligible repair candidate.
The published SDK worker's `run_until` timed out after 300 seconds with the
workflow still waiting. A documented operator repair pass at 18:44:23 UTC
[selected and repaired one task](repair-pass.json). A newly registered worker
then [completed the workflow](restore-after.json) with the expected result
digest. Final history had two `ActivityStarted`, one `ActivityCompleted` and
one `WorkflowCompleted` event. Activity execution was retryable; workflow
completion was not duplicated.

## Candidate Compose correction

The same SQL file and five verified objects were restored again into another
fresh stack using the Compose change in [Server PR #241](https://github.com/durable-workflow/server/pull/241).
The [pre-start task state](fix-prestart-task.tsv) was still leased with its
expiry in the past. When the scheduler started, its unscoped repair pass
[selected and repaired the task](fix-scheduler-repair.jsonl) without an
operator repair call. The published SDK worker then
[completed it](fix-restore-after.json) with the expected output digest and
exactly one `WorkflowCompleted` event. `ActivityStarted` appeared twice, which
is the expected retry after restoring an in-flight lease. The restored
historical backup hold was released after validation.

This second pass verified the candidate Compose configuration against the
failed recovery path. The [published Server 2.4.19 recheck](../published-2419/README.md)
then verified the released digest and automatic recovery in a fresh restore.
