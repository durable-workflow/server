# October 28, rejected requests on a refusing child

- **Before.** A parent's request could be refused by its child, either
  through the parent-close policy or the cooperative cascade. Native then
  recorded only the refusal on the parent. A policy failure carried
  `request_id: null`. The child's command log had no entry for the refusal.
- **Contract** (published 2.11.5, Core 2.15.1;
  `WorkflowStub::recordCancellationRequest`, `rejectCommand`,
  `ParentClosePolicyEnforcer`, `CommandResult::payloadValues`):
  - **Accepted request.** A child that already holds an accepted request
    answers first, before its status is read:
    - under the same root, the parent's request is that request;
    - under another root, it refuses with `cancellation_root_conflict`.
  - **Inactive child.** A child with no request that is no longer active
    refuses with `run_not_active`.
  - **Rejected command.** A refusal is a rejected `request_cancellation`
    command on the child, at its next command sequence:
    - the requester's source, scope and context: the policy's workflow
      context at instance scope, or the cascade's PHP API context on the
      run;
    - `rejection_reason`, no outcome, and `rejected_at`;
    - for a root conflict, a payload of the existing request id and root,
      the child's own cleanup deadline, and the incoming root and deadline.
  - **Policy failure.** The parent's `ParentClosePolicyFailed` names the
    rejected command as `request_id` and its payload values as
    `request_diagnostics`.
  - The run description lists the rejected command, with its reason.
- **Fixture.** `parent-close-cancellation` gains a `conflict` case. The
  child receives its own HTTP request first, then its parent is terminated.
  The fixture records the parent's policy failure and the child's described
  command list.
- **Native.** `request_child_cancellation_from_parent` checks an existing
  request before the run's status, and records the rejected command
  (`record_rejected_request`). It returns the command id and any
  diagnostics. The policy path records them on its failure.
- **Test.** `a_root_conflict_records_the_rejected_request_on_the_child`
  fails on the previous source.
