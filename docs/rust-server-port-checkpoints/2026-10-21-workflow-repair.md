# October 21, workflow repair

- **Found by.** The published dw 2.3.0 CLI sweep. `workflow:repair` on a run
  whose task was ready but overdue got native's
  `422 development_workflow_task_repair_unqualified`; published PHP 2.5.14
  answered `repair_dispatched`. Native repaired only a worker-reported
  version-range blockage.
- **Source.** `POST /api/workflows/{id}/repair` and its run-scoped route now
  follow PHP's `WorkflowStub::attemptRepair`:
  - **Closed run.** A repair of a closed run is a durable rejection: a
    rejected command (`rejected_not_active`, `run_not_active`) and `409`,
    with no history event.
  - **Version-range blockage.** A run with a worker-reported version-range
    blockage keeps native's existing qualified repair.
  - **Any other active run.** Native projects the run's summary inside the
    same transaction and decides from its liveness:
    - **`repair_needed`:** the run's recoverable task is repaired, chosen as
      PHP's `recoverableTask` chooses it.
      - A ready task due for redispatch counts one more repair; this uses
        PHP's dispatch-failure, claim-failure and overdue rules, with their
        backoff.
      - A workflow task whose lease expired returns to ready.
      - The task is then marked dispatched, as PHP's poll-mode
        `TaskDispatcher` does after commit, and the outcome is
        `repair_dispatched`.
    - **Otherwise:** the command records `repair_not_needed`.
  - **Record.** Both accepted outcomes record PHP's command payload
    (`liveness_state`, `wait_kind`, `task_id`, `task_type`) and a
    `RepairRequested` event, which has a task snapshot only when a task was
    dispatched.
  - **Response.** The body is PHP's, with `command_sequence`,
    `rejection_reason` and `validation_errors`, and without native's old
    `namespace` field.
- **Still refused.** These keep
  `development_workflow_task_repair_unqualified`:
  - **A run with no task to recover.** PHP creates the missing task
    (`TaskRepair::createMissingTask`).
  - **An activity task whose lease expired.** PHP also closes the expired
    attempt; native recovers that lease when a worker next claims it.
  - **A named namespace.** Repairs outside the default namespace keep their
    earlier refusal.
- **Tests changed.** Two version-range tests expected a second repair to be
  refused. As in PHP, it is now accepted as `repair_not_needed` and records
  a second command.
- **Verification.**
  - **Integration test.** `workflow_repair` covers an overdue ready task, a
    second repair, a healthy leased task, an expired workflow-task lease
    reclaimed by the same worker, and a completed run's rejection. It passes
    on SQLite, PostgreSQL and MySQL, and the full native suite passes on
    SQLite.
  - **Harness.** Three repairs were compared with published PHP 2.5.14: an
    overdue run, the same run again, and a completed run. The responses were
    identical apart from identifiers, control-plane envelope included, and
    so were the `RepairRequested` events.
  - **CLI.** dw 2.3.0's `workflow:repair` succeeds against both servers.
