# Child start turn

A workflow can start a child, get its committed identity in a fresh parent
turn while the child still runs, do other work, and later receive the child's
result (Server #966, the runtime side of sdk-php#185). No activity, timer,
signal or query is needed to get that turn. This is a native capability;
published PHP Server 2.x has no such mode. Workflow Core parity is
workflow#989.

## Command

`start_child_workflow` takes an optional `await`:

- `result` (the default, and the behavior when the field is absent): the
  parent's next turn comes when the child resolves, or from other work. The
  completion creates only the child's task.
- `started`: the completion that commits `ChildWorkflowScheduled` and
  `ChildRunStarted` also creates one new parent workflow task, with a fresh
  task id, lease and claim. Its poll carries both committed events, so the
  child call id, the child instance id and the initial child run id come from
  canonical history. The child keeps running, and its resolution gives the
  parent its usual later turn.

Any other value, or `await` on another command type, is refused with 422 and
the validation message `await must be result or started.` (or the field-scope
message) before anything is written.

## Turn rules

- One turn per completion, however many children in it ask for one.
- No turn when the same completion closes the parent (complete, fail or
  continue as new), and none when the parent already has a ready or leased
  workflow task, which delivers the same history.
- The task payload names the wait `workflow_wait_kind: "child_started"`, with
  `child_call_id`, `child_workflow_run_id`, `resume_source_kind:
  "child_workflow_run"`, `resume_source_id`, `workflow_sequence` and
  `workflow_event_type: "ChildRunStarted"` of the first such child.
- The completion stays under its immutable task receipt: a duplicate
  completion, redelivery or retry of the original task starts no second child
  and creates no second turn, and the original claim stays closed.
- A cancellation-scope checkpoint keeps the parent's claim, so it refuses a
  child start with `await: "started"` (`child_start_turn_not_at_checkpoint`)
  before any child starts.
- Parent-close policy (Abandon included), cancellation propagation, deadlines,
  retries, child continue-as-new (`child_call_id` stays; the initial run id is
  frozen in `ChildRunStarted`) and namespace isolation are unchanged.

## Advertisement

Native advertises `child_workflow_start_turn: true` in cluster info
`capabilities` and in worker `server_capabilities`. A runtime that does not
advertise it (native 3.0.x, PHP 2.x) ignores unknown command fields, so an SDK
must check the capability and refuse `await: "started"` itself before
submitting the child.

Tests (`tests/execution/child_start_turn.rs`,
`tests/execution/scope_checkpoints.rs`) cover:

- the flagged start, with its fresh claim and committed identity, the
  duplicate completion, and child continue-as-new resolving the original call;
- the default and explicit `result` with an unchanged task count;
- several children in one completion, and a closing completion;
- refusal of an unknown value and of the checkpoint case;
- the advertisement.

They run on SQLite, PostgreSQL and MySQL.
