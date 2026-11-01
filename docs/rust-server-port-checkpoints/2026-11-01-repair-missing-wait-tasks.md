# November 1, repair of the remaining missing wait tasks

- **Before.** Native's repair command refused with
  `422 development_workflow_task_repair_unqualified` when the missing task
  belonged to an accepted update, a resolved child, or a condition or signal
  wait's timeout timer. The repair pass reported those runs as failures
  (207). A run with a received signal and no open task had no summary, so
  the command answered `repair_not_needed`, and the pass never selected it.
- **Contract** (published Server 2.11.7, `TaskRepair::createMissingTask`
  with `WorkflowTaskPayload::forMissingWorkflowTask`). Recorded over HTTP
  with the run's open task row deleted from the server's own SQLite
  database and the maintenance loop stopped. Every case answers
  `repair_dispatched`, with a `RepairRequested` event (`liveness_state`
  `repair_needed`) naming a ready task with `repair_count` 1:

  | Case | Summary wait kind | Task created |
  | --- | --- | --- |
  | Received signal | `signal` | workflow task, `forSignal`: `{workflow_wait_kind, open_wait_id: "signal-application:<signal>", resume_source_kind: workflow_signal, resume_source_id, workflow_signal_id, signal_name, signal_wait_id, workflow_command_id}` |
  | Accepted update | `update` | workflow task, `forUpdate`: `{workflow_wait_kind, open_wait_id: "update:<update>", resume_source_kind: workflow_update, resume_source_id, workflow_update_id, workflow_command_id}` |
  | Resolved child | `child` | workflow task `{workflow_wait_kind, open_wait_id: "child:<call>", resume_source_kind: child_workflow_run, resume_source_id, child_call_id, child_workflow_run_id}` |
  | Pending signal timeout | `signal` | timer task `{timer_id, signal_wait_id, signal_name}`, available at the deadline (now when it has passed) |
  | Pending condition timeout | `condition` | timer task `{timer_id, condition_wait_id, condition_key}` (and the definition fingerprint when there is one) |
  | Fired signal timeout | `signal` | workflow task `{workflow_wait_kind, open_wait_id: <wait>, resume_source_kind: timer, resume_source_id, timer_id, signal_wait_id, signal_name, workflow_sequence}`, available when the timer fired |
  | Fired condition timeout | `condition` | the same with `condition_wait_id` and `condition_key` |

  PHP projects a received signal without an open task as a `signal` wait
  ("Waiting to apply signal …", `open_wait_id`
  `signal-application:<signal>`, resume source `workflow_signal`) with
  liveness `repair_needed` and a task problem. The run is described as it
  was before the task was lost.
- **Native.**
  - `task_repair::create_missing_task` follows each branch, and the command
    and the pass share it. It no longer refuses any case, so the
    `development_workflow_task_repair_unqualified` refusal is gone.
  - An accepted update's open wait, and a pending timeout's wait, are moved
    onto the new task, as PHP's projection reads them.
  - The pending timeout's timer task carries the wait's fields as native
    schedules them, so a wait opened with a condition occurrence id keeps
    it when the timer fires. PHP's repaired timer task omits that id; PHP
    reads it from the wait instead.
  - `active_summary` projects the received-signal case as PHP does. Native
    still leaves it unprojected beside an open activity, an accepted update
    or a blocked replay, where PHP's other classifiers take precedence.
  - Native's own accepted-update task carries only
    `{resume_source_kind, workflow_update_id, workflow_command_id}`; the
    repaired task carries `forUpdate`'s full payload, as PHP's does.
- **No shared fixture.** A run without its task is reached only by editing
  the server's database, as for the October 30 cases. The seven cases were
  run against both servers on SQLite with an exploration script; the
  created tasks' types and payload fields, and the descriptions afterwards,
  match.
- **Tests.** `repair_creates_a_missing_signal_update_child_or_timeout_task_like_php`
  in `workflow_repair.rs`, on SQLite, PostgreSQL and MySQL.
