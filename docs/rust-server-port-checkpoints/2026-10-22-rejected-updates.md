# October 22, durable rejected updates

- **Found by.** Comparing native's update admission with Workflow 2.9.0's
  `WorkflowStub::submitUpdate`. Native refused an unknown update, invalid
  arguments and a closed run without any record. Published PHP records
  each one as a rejected command and update, with an `UpdateRejected`
  history event on the run.
- **Source.** Native follows `rejectUpdateCommand` in PHP's order:
  - **A closed run.** `run_not_active` with outcome
    `rejected_not_active`, 409. The event is recorded on the closed run. A
    live run keeps native's cancellation-deadline fence.
  - **An undeclared update.** `unknown_update` with outcome
    `rejected_unknown_update`, 404.
  - **Arguments that break the declared contract.** `invalid_update_arguments`
    with outcome `rejected_invalid_arguments`, 422. The validation errors are
    PHP's messages:
    - "The {name} argument is required."
    - "The {name} argument cannot be null."
    - "The {name} argument must be of type {type}."
    - "Too many arguments were provided for update [{name}]."
  - **Records.** The rejected command takes the next command and message
    sequence and stores PHP's Avro payload (name, arguments,
    validation errors), its outcome, rejection reason and request id. The
    rejected update stores the same arguments and validation errors, with
    `rejected_at` as its `closed_at`. The `UpdateRejected` event carries the
    command, update, instance, run, name and arguments, plus the validation
    errors when there are any. PHP drops empty fields from this event.
  - **Response.** The update response shape, with `accepted: false`,
    `command_status` and `update_status` set to `rejected`, the outcome,
    `reason`, `rejection_reason` and `validation_errors`. The status follows
    `DefaultWorkflowControlPlane::updateStatus`. A repeated request id
    returns the original rejection without recording anything new.
  - **Summaries.** Run summary replay accepts `UpdateRejected`. A rejection
    opens no wait and changes no summary.
- **Unchanged.** A run-targeted request for a historical run is still
  refused before admission, as in Server 2.10.0's `withCurrentRunGuard`.
  Native still refuses, without a record:
  - declared update validators (`update_validation_not_implemented`);
  - parameter contracts it cannot check (`unsupported_update_contract`);
  - a reused request id that names another update
    (`unsupported_update_request_retargeting`). PHP returns the original
    receipt instead.

  PHP's structural limit and execution gate rejections are not implemented
  (#612).
- **Tests.** `rejected_updates` covers:
  - the unknown, invalid and closed-run responses;
  - their history events;
  - each argument message;
  - a repeated request id;
  - a later valid update.

  The existing receipt test now expects the two rejections it makes to
  appear in history and in the worker's task history. The full SQLite
  execution suite passes (379 tests). The update tests pass on PostgreSQL
  and MySQL (35 each).
