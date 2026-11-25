# Worker replay entry clock

Each workflow task can carry `replay_clock`, the instant the run's replay
starts from (Server #1132, the Server slice of durable-workflow/.github#215).
SDKs use it as their replay-safe clock's value at ordinary workflow entry,
before any history event has been consumed. History insertion, dispatch and
lease times are not that instant.

## Shape

```json
"replay_clock": {
  "schema": "durable-workflow.worker-replay-clock/v1",
  "workflow_run_id": "<the task's run_id>",
  "started_at": "2026-11-12T10:00:00.123456Z",
  "source": "run_started_at"
}
```

- `started_at` is UTC with six fractional digits and `Z`. A whole-second
  anchor stays whole seconds, padded with zeros. Lost precision is not
  reconstructed.
- `source` is `run_started_at` or `redrive_replayed_started_at`.

The object is optional. Its presence on a task is the only availability
signal: there is no capability flag. When it is absent, the SDK reports its
clock as unavailable at entry. It must not substitute its own time.

## Selection

- An ordinary, continued or child run uses its own canonical start
  (`run_started_at`).
- A redrive successor uses the `replayed_started_at` its `WorkflowStarted`
  recorded (`redrive_replayed_started_at`). That value is the original run's
  start, carried across a chain of redrives. Native uses it only when all of
  these agree: the redrive command (type, run, source run, accepted
  `redriven` outcome), the redrive link (source run, successor run, resume
  sequence), the start event's command, and exactly one start for that
  command.
- The object is omitted in any of these cases: no single `WorkflowStarted`
  among the run's opening events, a start that names another run, an unknown
  recovery kind, missing or malformed redrive fields, a command or link that
  disagrees, an unmarked start for a run a redrive created, or a missing
  start time. A redrive successor's fresh start never stands in for its
  anchor.

## Query and update-validation tasks

Query tasks and update-validation tasks carry the same object, chosen by the
same rules, as the run's workflow tasks (.github#215). An SDK that rebuilds
the workflow body to answer a query or validate an update then gives an entry
read the value the workflow task gave it.

On these two task kinds the native Server always sends the key (worker
OpenAPI revision 70). The schema keeps it optional, because published PHP
Servers don't send it:

- an object: the same carrier the run's workflow tasks carry;
- `null`: this Server proved no start, so the run's workflow tasks omit the
  object too, and an entry read is unavailable in both places;
- absent: an older Server that never sends the object on these tasks. The SDK
  cannot know what the workflow task carried, so a rebuild that reads entry
  time must fail closed.

Workflow tasks keep omitting the object when it is not proved.

## Stability

The value depends only on stored rows. A later lease of the same run carries
the same value, and a cached poll answer keeps the object it was issued with.
Lookups use existing keys, so no schema change is needed.

The OpenAPI schema is `WorkerReplayClock` in
`resources/platform-protocol-specs/worker-protocol-api.openapi.yaml`
(revision 67; query and validation tasks since revision 70). Tests:
`tests/execution/replay_clock.rs`.
