# October 23, command batches and open work

Live probes sent the same workflow task completions to published Server
2.11.1 and to native. Native refused several batches that PHP applies:

| Completion | Published PHP | Native before |
| --- | --- | --- |
| `complete_workflow` before a `start_timer` | timer scheduled, then the run completes | 422 `unsupported_workflow_command` |
| two children, or a child with a timer and an activity | all started, run waiting | 422 `unsupported_workflow_command`, or 409 `pending_durable_operations` with other work open |
| an activity, `continue_as_new` and a timer | activity and timer recorded on the old run, then it continues | 422 `unsupported_workflow_command` |
| `continue_as_new` with an open timer, activity, child or signal wait | the run continues; the open work stays with it | 409 `continue_as_new_transfer_unqualified` |
| `continue_as_new` from a child run | the child continues; its parent follows the new run | 409 `continue_as_new_transfer_unqualified` |
| `complete_update` with other commands | all applied | 422 `invalid_update_task_commands` |
| an update task completed without its update | the update stays accepted for a later task | 409 `update_task_mismatch` |
| two terminal commands | 409 `invalid_commands`, nothing recorded | 422 `invalid_commands` |

## Source

Workflow 2.11's `DefaultWorkflowTaskBridge`:

- **`parseCommands`.** A batch may hold one terminal command
  (`complete_workflow`, `fail_workflow`, `continue_as_new`) anywhere. Every
  other command applies first, in order, then the terminal one. A second
  terminal command makes the batch `invalid_commands`.
- **`validateUpdateCommands`.** Each `complete_update` or `fail_update` must:
  - name a different update accepted on the run;
  - name the task's own update when the task carries one;
  - have no earlier signal still to apply.

  Otherwise the batch is `invalid_commands`. Nothing requires an update
  task to complete its update.
- **`applyContinueAsNew`.** It closes the run as `continued` whatever is
  open. Activities, timers, children and waits stay with the predecessor, and
  no parent-close policy applies. For a child run:
  - each parent link gets a new `child_workflow` link to the continued run;
  - the parent's open child call follows the new run
    (`markChildCallContinued`), counting the continuation;
  - an open parent records `ChildRunStarted` for the new run, with the
    original `child_call_id` and the new link.

  The continued run's `WorkflowStarted` carries `child_call_id` and the
  parent fields, all null for a run without a parent. A child's result is
  recorded on its parent with the parent's latest link for that call.

## What native now does

- A batch's terminal command applies after every other command, wherever
  it sits. Two terminal commands get PHP's 409 `invalid_commands` body,
  after the ownership checks.
- A task may start any number of children, alongside activities, timers and
  waits. A child's result gets its own parent resume task even when the
  parent already has one, as activity and timer results do.
- Update commands follow `validateUpdateCommands`, with PHP's
  `invalid_commands` refusal.
- Continue-as-new leaves open activities, timers, children and waits with
  the closed predecessor. A continued child stays its parent's child, as
  above.

## Completion responses

PHP's successful completion response lists `created_task_ids`: the tasks the
batch's own commands created, in the order applied. That is each activity's
task, each timer's task, each child's first workflow task, then the continued
run's first task. A resume task that a child's completion creates for its
parent is not listed. The response also carries `protocol_version` and
`server_capabilities`, as every PHP worker response does. Native's response
had none of these three fields, and now has all of them.

The same probe found the worker envelope missing from workflow task
failures, workflow task heartbeats and activity results and failures. Native
now adds it to each of them. A workflow task heartbeat also reports PHP's
`run_status`, `task_status` and `reason`. Timestamps differ in precision:
native's lease expiry has milliseconds, PHP's microseconds.

## Differences that remain

- **Resume tasks.** PHP folds a child's result into the parent's ready or
  leased workflow task. Native creates a task for each result, as it already
  does for activities and timers.
- **Continue-as-new transfers.** Native still refuses continue-as-new
  (`continue_as_new_transfer_unqualified`) while the instance has durable or
  inbound streams, the run has service calls, or a query is live. It refuses
  a new workflow type or queue (`continue_as_new_routing_unqualified`).
- **Open waits.** A task that opens a signal or condition wait while
  another is open is still refused with `409 pending_durable_operations`.

## Tests

`tests/execution/command_batches.rs` checks each PHP answer above on SQLite,
PostgreSQL and MySQL: terminal order, two terminal commands, mixed children,
continue-as-new over open work, a continued child's parent records, and an
update completed alongside other commands after its update task left it open.
It also checks `created_task_ids` and `protocol_version` on the responses.
Two older tests asserted refusals that PHP does not give:

- `updates_preserve_original_receipts_and_fence_recovered_tasks_across_pools`
  now expects PHP's `invalid_commands` for another update in an update task.
  It no longer refuses an update task completed with `complete_workflow`.
- `continuation_inherits_committed_upserts_before_successor_commands` no
  longer refuses a continuation placed before the memo upsert.
