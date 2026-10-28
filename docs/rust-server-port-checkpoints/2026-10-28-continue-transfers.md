# October 28, continue-as-new over streams, service calls and live queries

- **Before.** The port log said native refused continue-as-new
  (`409 continue_as_new_transfer_unqualified`) when the instance had durable
  or inbound streams, the run had service calls, or a query was live.
  Published PHP continues the run in every case. Streams were already
  continued natively on main, so the log was stale for them. The refusal
  still applied to any service call naming the run as its caller or linked
  run, open or closed, and to any pending or leased query on the run.
- **Contract** (published 2.11.5, Core 2.15.1; `DefaultWorkflowTaskBridge::applyContinueAsNew`,
  `MessageStreamCursor`, `ContinuedRunMessageHandoff`,
  `LinkedServiceCallCloser`, `ServiceCallWorkflowDelivery`,
  `WorkflowQueryTaskBroker`):
  - Continue-as-new creates the successor run, moves the instance's current
    run, transfers message stream cursors, links the runs, records
    `WorkflowStarted` with `continued_from_run_id`, hands unconsumed
    messages to the successor and closes the predecessor. Nothing else it
    has open is checked or moved.
  - **Streams.** Message stream cursors and unconsumed messages pass to the
    next run. Durable output streams are rows of their run and stay with the
    predecessor. The `message-streams` fixture already records the inbound
    case. Native execution tests in `message_streams.rs` and
    `waterline_reads.rs` pin both.
  - **Service calls.** Core never changes a call whose caller is the
    continued run. The call keeps the predecessor as its caller and its
    status. Results are never delivered to it, because
    `ServiceCallWorkflowDelivery` skips a terminal caller run. On
    `WorkflowContinuedAsNew`, `LinkedServiceCallCloser` moves only open
    (`pending`, `accepted`, `started`) calls bound to the run with the
    `workflow_run` binding to the next run. Native binds no call to a
    workflow run (Server #794), and takeover refuses an open one, so no
    linked call needs to follow.
  - **Live queries.** A query admitted against the predecessor stays bound
    to it. The query task broker's claim path does not check run status, so
    the next query poll leases the task with the predecessor's run id. The
    worker answers it from that run's history, and the waiting client gets
    the answer and the predecessor's run id. Native claims and answers
    queries the same way.
- **Shared fixture.** `continue-transfers`, over raw worker HTTP on
  protocol 1.20 in its own namespace:
  - In the service-call run, an accepted async call names the run as its
    caller, and the run's leased task continues as new. The completion is
    accepted with one created task. The call stays `accepted` with the
    predecessor as its caller. The predecessor is completed and no longer
    current. The successor's first task is ready at attempt 1.
  - In the query run, a client query waits behind the run's leased task.
    That task continues as new. The next query poll leases the query task
    for the predecessor, and the worker answers it. The client receives the
    answer with the predecessor's run id.
  - Contract unit tests carry twelve negative controls: refused
    continuations, a moved or cancelled call, no successor, a predecessor
    still current, a retargeted, unleased or failed query, a lost answer, a
    changed attempt and an extra step.
  - Three recordings against published 2.11.5, with its maintenance loop
    running, gave the same observation.
- **Native.** `continue_run` no longer counts service calls or scans live
  queries. Execution tests:
  - `a_caller_run_continues_as_new_and_its_call_stays_with_the_predecessor`
  - `continuation_leaves_pending_and_leased_queries_with_the_predecessor`
    (the stored query rows are unchanged)
  - `a_live_query_survives_continuation_and_is_answered_from_the_predecessor`,
    which replaces the old refusal and deadline test.
- **Closing a continued run.** The native image corpus found that native
  refused to cancel or terminate any continued run
  (`422 termination_propagation_not_available`). It counted the
  `continue_as_new` link from the predecessor as an unsupported incoming
  link. PHP's close resolves only `child_workflow` parents (`WorkflowStub`),
  and the fixture terminates each successor with 200. Native now ignores
  that link on close.
  `a_continued_child_terminates_and_resolves_its_parent_like_php` covers a
  continued child: its parent records `ChildRunTerminated` for run 2 and
  resumes. A `redrive` link still makes native refuse; PHP ignores it too,
  but no fixture records that yet.
- **Takeover.** Nothing new to carry. A PHP database may hold calls whose
  caller is a continued run, and native leaves them as PHP does. Open
  `workflow_run`-bound linked calls are still refused at takeover.
