# October 23, synchronous update validation

- **The gap.** The Python SDK lets a workflow declare update validators. A
  worker with validators registers only when the server advertises
  `update_validation_tasks` and its `synchronous_update_validation` contract.
  Native advertised neither, and refused a validated update with 422
  `update_validation_not_implemented`. So a Python worker with validators
  could not run against native.
- **How PHP 2.11.1 does it.** Learned from `ValidatedExternalWorkflowUpdateAdmission`
  and `WorkflowUpdateValidationTaskBroker`, then observed live against the
  published image.
  - A worker declares `update_validators` per workflow type and advertises
    the `update_validation_tasks` capability. The run's `WorkflowStarted`
    records `declared_update_validators` when the worker's contract has that
    field.
  - For a live run with a complete durable contract and a declared update,
    admission checks, in order:
    1. a recorded receipt for the `request_id`;
    2. whether the contract states validators at all, else 409
       `update_validator_contract_missing`, for any remote update;
    3. the update's arguments;
    4. a `request_id`, which a validated update requires.

    None of these refusals is recorded.
  - The validation task is keyed by run, update name and `request_id`, and
    holds a hash of the arguments. Different arguments under the same key
    conflict with 409. A task that has finished replays its result. The
    queue's fresh workers must include one that is capable and matches the
    run, else 409 with a routing reason.
  - The caller waits up to `DW_UPDATE_VALIDATION_TIMEOUT` seconds (10). A
    worker claims the task through `POST /api/worker/update-validation-tasks/poll`
    or the workflow poll with `task_kinds`. The claim lasts
    `DW_UPDATE_VALIDATION_LEASE_TIMEOUT` seconds (5). The task carries the
    run's replay history and history export.
  - Approval admits the update with its id equal to the task id. A validator
    rejection records a rejected command and update under the task id, with
    `UpdateRejected`, and answers 422. Any other worker failure answers 409
    and records nothing. Without an answer, the task times out with 504 and
    a reason: not claimed, worker lost, or execution timeout.
  - Approve and reject are fenced, in order: task found, not finished,
    leased, owner, attempt, lease unexpired, worker fresh. An expired lease
    can be claimed again with the next attempt.
  - The multiplexed poll keeps a cursor per queue. It tries the kind the
    cursor names first, and turns the cursor to the other kind after each
    lease. Every workflow task names `task_kind: "workflow"`.
- **What native does now.**
  - Admission follows the same order, with the same bodies, and waits
    without holding a transaction. Approval re-enters admission with the
    task id, after checking that the run is still current and the approval
    still stands.
  - The validation task poll, approve and reject routes, with Laravel's
    request rules.
  - The workflow poll validates `task_kinds`. With validation tasks
    requested, it refuses a worker without the capability, answers
    `no_workflow_capability` for a worker without workflow types, and
    alternates kinds by the `workflow_task_poll_cursors` row.
  - The capability, its contract and both timeouts (`DW_UPDATE_VALIDATION_*`,
    now honored settings) are advertised.
  - A validated task's payload carries the unsigned history export, as
    `HistoryExport::forRun` builds it.
- **Native changes found on the way.**
  - An accepted update's receipt no longer carries `accepted: true`. PHP's
    result mapper sends `accepted` only when it is false.
  - A worker contract without `update_validators` now makes a remote update
    refuse with 409, as on PHP. Current PHP, Python and Rust SDK workers all
    send the field.
- **How it was checked.**
  - The `update-validation` fixture records 49 steps: refusals before
    validation, a rejection through the multiplexed poll, an approval through
    the dedicated poll with its fences, retries, three timeouts run side by
    side, lease expiry and re-claim, a worker failure, multiplexed ordering
    and every routing refusal.
  - Two published PHP runs normalize identically. Native matches all 49
    steps on SQLite. The contract test refuses 19 changed observations.
  - Native integration tests cover the same paths on SQLite, PostgreSQL and
    MySQL.
- **Not done yet** (Server #325 follow-ups):
  - **Poll request ids.** PHP replays a leased validation task for a retried
    `poll_request_id` and binds the request id to its task kinds. Native
    does neither, so a retried multiplexed poll waits for the lease to
    expire before the task is offered again.
  - **Cluster info.** PHP's `workflow_update_runtime_contract` manifest and
    its `service_mode_update_validation` block are not published yet; that
    belongs to the cluster info work.
  - **Takeover.** PHP data with validation tasks is still refused.
