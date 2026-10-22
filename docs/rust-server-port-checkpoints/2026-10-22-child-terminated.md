# October 22, terminating a child run

- **Found by.** The gap list, while preparing parent-close policies.
  Native refused to terminate a run that is a child
  (`422 termination_propagation_not_available`), because the parent-side
  projections for `ChildRunTerminated` were missing. PHP's `request_cancel`
  and `terminate` parent-close policies close children this way.
- **Source.** Workflow 2.9.0's `recordChildResolution` and
  `ChildRunHistory`:
  - **Parent event.** Terminating a child records `ChildRunTerminated` on
    its open parent, like `ChildRunCancelled`. It carries the child's
    identity, `child_status` and `closed_reason` `terminated`, and the
    failure id, category (`terminated`), exception class and message. The
    parent gets one resume task (`workflow_event_type`
    `ChildRunTerminated`).
  - **Child call.** The call is marked `terminated`, and every open-child
    check now treats `terminated` as closed.
  - **Wait.** The parent's child wait ends as `cancelled` with source status
    `terminated` and the summary "Child workflow {type} terminated.", as
    `waitSnapshotForSequence` and `RunWaitView` report.
  - **Summary replay.** Run summary replay accepts `ChildRunTerminated` as
    a terminated child.
  - **Refusal kept.** Terminating a run that continued another as new still
    refuses (`termination_propagation_not_available`), the same as
    cancellation does.
- **Tests.** The direct-child test now runs cancel and terminate, each with
  and without a pending timer. Each case covers:
  - the child's history and failure row;
  - the parent's resolution event and fields;
  - the terminated wait and call projections;
  - a duplicate request refused as `run_not_active`;
  - one parent resumption after a cold reopen.
