# Worker command declarations

Workers can advertise `workflow_command_contracts`, keyed by workflow type,
during registration. Each entry describes that workflow's queries, signals and
updates, including any handler parameter contracts and update validators.

An explicitly supplied empty entry declares that the workflow has no commands.
The Server retains it when admitting a workflow from the selected worker cohort.
Bounded run observations then report an empty contract with
`source=durable_history` and `backfill_needed=false`.

Omitting a workflow's entry leaves its contract unavailable. A missing or null
top-level map preserves this legacy behavior. Null entries, malformed command
lists and unnamed handler contracts return HTTP 422 during registration.

The declaration must match the run's namespace, task queue, workflow type and
selected build cohort. A later registration applies to new admissions and
preserves the original run's durable snapshot. Existing runs with missing
metadata remain unavailable until an explicit supported recovery can establish
their original contract.

Workflow-bound Nexus starts, updates and queries use the same declarations as
the ordinary workflow routes. [Nexus workflow bindings](contracts/nexus.md#workflow-bindings)
describe queue selection, result observation and typed refusals.

An update's durable identity and completion record distinguish it from a second
accepted update. SDKs that reconstruct workflow state may invoke its recorded
handler again during replay, including replay for a query. Keep replayed
handlers deterministic and use durable workflow operations for external
effects. A callback entry count alone does not prove duplicate admission or
exactly-once external execution.

When a history-wait acknowledgment closes an expired run, Server returns the
normal timeout completion receipt: HTTP 409 `run_timed_out`, `outcome=completed`
and `recorded=false`, with the original task, run and attempt identities and
`run_status=failed`. Workers can recognize that matching terminal receipt and
continue processing other workflows.
