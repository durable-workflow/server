# Signal another workflow from workflow code

Workflow code can signal another workflow in its namespace as a durable
workflow command (Server #1134, the native slice of .github#223). The signal
is recorded once in the sender's history, delivered in the same transaction
that records it, and its outcome is recorded beside it. Replay reads the
recorded outcome and never sends the signal again. No activity is needed.
This is a native capability: published PHP Server 2.x and embedded Workflow
2.33.0 have no such command (embedded child handles signal directly, outside
the parent's history).

## Command

```json
{
  "type": "signal_external_workflow",
  "workflow_id": "order-42",
  "run_id": null,
  "signal_name": "approve",
  "arguments": {"codec": "avro", "blob": "<Avro array of the arguments>"}
}
```

- `workflow_id` (required): the target workflow instance id, in the sender's
  namespace. A string of 1 to 191 characters.
- `run_id` (optional, string or null): signal only this run of the target.
  Without it the signal goes to the instance's current run.
- `signal_name` (required): not blank, at most 191 characters (the command
  field rule) and 255 bytes (the signal record's column).
- `arguments` (required): the payload envelope with the Avro codec, as for
  `start_child_workflow`. An SDK sends an encoded empty array for no
  arguments.

Any other field, a blank or over-long target or name, or arguments that are
not an Avro envelope are refused with 422 before anything is written, as for
every other command. Arguments that do not decode to a list (or a non-empty
map) of values refuse the completion the same way. The command takes the next authored workflow sequence, like an
activity, timer, child or side effect. It is not a cancellation scope member,
not a parallel group leaf, and is not accepted while the run is cleaning up
after a cancellation request.

## History

The completion that carries the command appends two events to the sender, at
the command's `sequence`:

1. `ExternalSignalRequested`: `sequence`, `target_workflow_instance_id`,
   `target_workflow_run_id` (the requested run, or null), `signal_name`,
   `arguments`, `payload_codec` (`avro`), `request_id` and the completing
   `task` snapshot.
2. Exactly one resolution:
   - `ExternalSignalDelivered`: `sequence`, `target_workflow_instance_id`,
     `target_workflow_run_id` (the run that received it), `signal_name`,
     `request_id`, `outcome: "delivered"`, `target_command_id`,
     `target_signal_id` and `retained` (true only when the command key was
     already delivered, see below).
   - `ExternalSignalFailed`: the same identity fields
     (`target_workflow_run_id` is null when no run was resolved), `outcome`,
     `reason`, `message`, and `target_command_id` when the target recorded a
     rejected command.

Outcomes and reasons:

| `outcome` | `reason` | When |
| --- | --- | --- |
| `delivered` | null | The target's run admitted the signal (`SignalReceived` in its history). |
| `target_not_found` | `instance_not_found` | No workflow instance with that id in the namespace. |
| `target_not_found` | `run_not_found` | `run_id` names no run of that instance. |
| `target_closed` | `run_not_active` | The target run has closed. The target records the rejected signal command, as for an HTTP signal. |
| `target_closed` | `historical_run` | `run_id` names a run the instance has since replaced (continue as new). |
| `rejected` | `unknown_signal`, `invalid_signal_arguments`, `structural_limit_exceeded` | The target's admission refused the signal, as for an HTTP signal. |
| `rejected` | `runtime_reserved_signal` | The name is the runtime's message-stream signal. |
| `rejected` | `self_target` | The target is the sender's own instance. |
| `rejected` | `request_id_conflict` | The command key was already used with a different intent. |

A failed outcome is a recorded result, not a workflow task failure: the
completion is accepted and workflow code decides what to do.

## Delivery on the target

A delivered signal is an ordinary signal on the target: the same accepted
command, signal record, `SignalReceived` event, wait reservation and message
task as an HTTP signal, so the target's history has the same shape whichever
way the signal arrived. The target command's `source` is `workflow` and its
context is Core's `CommandContext::workflow` shape: `caller.type: "workflow"`,
and `workflow.parent_instance_id`, `workflow.parent_run_id` and
`workflow.sequence` naming the sending run and its command sequence.

## Command key

Each delivery is keyed on the target instance (Server #934):
`request_id = "workflow-signal:<sender run id>:<sequence>"`, stored as the
signal command key token with its CommandIntent v1 digest (name, typed
arguments and run target). The completion is also kept under its immutable
task receipt, so a duplicate completion, redelivery or retry of the same task
writes nothing and sends nothing. If the key was already delivered with the
same intent, the outcome is `delivered` with `retained: true` and the
original command; a different intent is `rejected` / `request_id_conflict`.

## Turn

The completion that records a signal-external command also creates one new
workflow task for the sender, so workflow code waiting on the outcome resumes
without other work. As for a child start turn (`child-start-turn.md`): one turn
per completion however many commands ask for one, none when the same
completion closes the sender, and none when the sender already has a ready
replaying workflow task. Its payload names `workflow_wait_kind:
"external_signal"`, `resume_source_kind: "external_signal"`,
`workflow_sequence` and `workflow_event_type` of the first such command.

## Replay

An SDK matches the command to `ExternalSignalRequested` at the same sequence
and resolves it from the following `ExternalSignalDelivered` or
`ExternalSignalFailed`. A replayed command is never submitted again. A
different target, name or arguments at that sequence is a nondeterminism
error.

## Advertisement

Native advertises `signal_external_workflow: true` in cluster info
`capabilities` and in worker `server_capabilities`, and lists the command in
`supported_workflow_task_commands`. A server that does not advertise it
refuses the command with `unsupported_workflow_command` and fails the task,
so an SDK must check the capability and refuse in workflow code before
submitting.

## Tests

`tests/execution/signal_external.rs` covers a delivered signal (the sender's
events and turn, the target's ordinary `SignalReceived` with the workflow
source and context, the keyed target command, a duplicate completion that
sends nothing, and a fresh runtime reading the same history); missing,
closed, continued, unknown-run, unknown-signal and self targets in one batch
with one turn; a key already admitted with the same intent (`retained`) and
with another (`request_id_conflict`); and refused command shapes, a closing
completion without a turn, and the advertisement. They run on SQLite,
PostgreSQL, MySQL and MariaDB.

## Storage

No schema change. The command uses the existing history, command and signal
tables.
