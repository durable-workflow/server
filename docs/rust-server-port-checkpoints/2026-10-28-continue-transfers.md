# October 28, continue-as-new and close over streams, service calls and live queries

- **Before.**
  - The port log said native refused continue-as-new
    (`409 continue_as_new_transfer_unqualified`) when the instance had
    durable or inbound streams, the run had service calls, or a query was
    live. Published PHP continues the run in every case.
  - Streams were already continued natively on main, so the log was stale
    for them. The refusal still applied to any service call naming the run
    as its caller or linked run, open or closed, and to any pending or
    leased query on the run.
  - Native also refused to cancel or terminate a continued or redriven run,
    and a run with a live query (`422 ..._propagation_not_available`).
    Under a parent-close policy it recorded `ParentClosePolicyFailed` for
    such a child. PHP closes all of them.
- **Contract** (published 2.11.5, Core 2.15.1; `DefaultWorkflowTaskBridge::applyContinueAsNew`,
  `MessageStreamCursor`, `ContinuedRunMessageHandoff`, `LinkedServiceCallCloser`,
  `ServiceCallWorkflowDelivery`, `WorkflowStub::attemptCancel`,
  `attemptTerminate`, `ParentClosePolicyEnforcer`, `WorkflowQueryTaskBroker`):
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
  - **Service calls.**
    - Core never changes a call whose caller is the continued run. The call
      keeps the predecessor as its caller and its status. Results are never
      delivered to it, because `ServiceCallWorkflowDelivery` skips a
      terminal caller run.
    - On `WorkflowContinuedAsNew`, `LinkedServiceCallCloser` moves only open
      (`pending`, `accepted`, `started`) calls with the `workflow_run`
      binding to the next run. Native resolves no call to that binding, and
      takeover refuses an open one.
    - A call linked to the run through an `activity_execution` binding
      (`owning_workflow_run_id`) stays with the predecessor in both.
  - **Live queries.**
    - A query admitted against a run stays bound to it, whether the run
      continues as new or is cancelled or terminated. Cancel, terminate and
      parent-close policies never consult query tasks.
    - The query task broker's claim path does not check run status, so the
      next query poll leases the task with that run's id. The worker answers
      it from that run's history, and the waiting client gets the answer and
      that run's id.
  - **Links on close.** PHP's close reads no incoming link to decide whether
    it may close. It follows `child_workflow` links only to resolve the
    parent, and never reads `continue_as_new`, `redrive` or imported untyped
    (`parent`, `child`) links.
  - **Parent resolution.** `createParentResumeTasks` resolves only an open
    parent run, and checks that before it reads the child call. A closed or
    replaced parent takes no resolution, and its call stays as the parent
    left it. That includes a child that continued as new after its parent
    closed, whose call still names its first run (`markChildCallContinued`).
  - **Parent-close policies.** A policy reaches the child instance's current
    run, whichever run it continued into. Once it closes that run, the
    enforcer marks the parent's open call for that sequence `cancelled` or
    `terminated` (`markChildCallClosedByParentPolicy`). An `abandon` call
    stays `started`.
- **Shared fixture.** `continue-transfers`, over raw worker HTTP on
  protocol 1.20 in its own namespace:
  - **Service-call run.** An accepted async call names the run as its
    caller, and another names it as its linked run through an
    `activity_execution` binding. The run's leased task continues as new.
    The completion is accepted with one created task, and both calls stay
    `accepted` with the predecessor. The predecessor is completed and no
    longer current. The successor's first task is ready at attempt 1.
  - **Query run.** A client query waits behind the run's leased task. The
    probe checks admission rather than sleeping: a query poll ends
    `workflow_task_leased` once the query is admitted, and `empty` before.
    The task then continues as new. The next query poll leases the query
    task for the predecessor, and the worker answers it. The client
    receives the answer with the predecessor's run id.
  - **Closed-query run.** The same, but the run is terminated over HTTP
    (200). The query is leased and answered for the terminated run.
  - **Policy run.** A parent starts a child under the `terminate`
    parent-close policy. The child continues as new, and the parent is
    terminated. The parent records `ParentClosePolicyApplied` for the
    child's run 2, which is terminated.
  - Contract unit tests carry twenty negative controls, including
    refused continuations and closes, moved calls, unadmitted, retargeted or
    failed queries, and a policy that fails or reaches the predecessor.
  - Three recordings against published 2.11.5, with its maintenance loop
    running, gave the same observation.
  - The held client request is shared with the worker poll fence probe
    (`held-request.php`).
- **Native.**
  - `continue_run` no longer counts service calls or scans live queries.
  - HTTP close and parent-close policies share one rule,
    `close_unavailable`. It reads no links and no queries; only a
    `request_cancellation` child still refuses.
  - A closing child returns before it validates its call when the parent run
    is closed or replaced. Native used to validate the call first, so a child
    that continued as new after its parent closed could never close
    (`409 child_call_mismatch`).
  - A parent-close policy marks the call itself, as Core's enforcer does.
    A child that closes under a closed parent no longer marks its call: Core
    never does (`dispatchParentResumeTasksForRun`). The test
    `cooperative_child_independent_closed_parent_is_not_resumed` now expects
    that abandoned call to stay `started`, not `cancelled`.
  - Execution tests:
    - `a_caller_run_continues_as_new_and_its_call_stays_with_the_predecessor`
      (it also terminates the successor)
    - `a_call_linked_through_an_activity_binding_stays_with_the_predecessor_run`
    - `continuation_leaves_pending_and_leased_queries_with_the_predecessor`
    - `a_live_query_survives_continuation_and_is_answered_from_the_predecessor`
    - `a_live_query_holds_no_close_and_is_answered_from_the_closed_run`
    - `a_continued_child_terminates_and_resolves_its_parent_like_php`
    - `a_redriven_run_cancels_and_terminates_like_php`
    - `a_policy_close_reaches_a_child_that_continued_as_new`
    - `a_policy_close_reaches_a_child_with_a_live_query`
    - `a_child_continued_after_its_parent_closed_still_closes` (terminate
      and complete)
    - `a_run_named_by_an_imported_untyped_link_still_closes`
  - The close tests fail on the previous rules: 422, 409
    `child_call_mismatch`, or `ParentClosePolicyFailed`.
- **Takeover.** Nothing new to carry. A PHP database may hold calls whose
  caller or activity-linked run is a continued run, and native leaves them
  as PHP does. Open `workflow_run`-bound linked calls are still refused at
  takeover.
