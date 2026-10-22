# October 22, child cancellation cascade

- **Why.** The third slice of the child cancellation cascade, after
  cancellation at waits and the child policy contract. In the Sample App's
  children scenario, a parent waiting on its child takes the cooperative
  cancellation with `cancellation_policy: wait_cancellation_completed`. The
  child must cancel under the same root request and budget before the
  parent closes.
- **Source** (Workflow 2.9.0 `ChildCancellation`,
  `WorkflowStub::attemptRequestCancellationFromParent`,
  `ParentClosePolicyEnforcer`):
  - **Request.** A root request is admitted on a run with any consistent
    child link, whatever its policies.
  - **Delivery at a child.** The boundary accepts the child's recorded
    policies. For `try_cancel` and `wait_cancellation_completed`, the
    delivery asks the child to cancel, unless that is already recorded or
    the child has closed, and records `ChildCancellationRequested` on the
    parent. Once the child has closed, the next delivery records
    `ChildCancellationResolved`. Both events carry PHP's fields and are
    recorded with the request's id, so they have no command snapshot.
  - **Child request.** The child gets a descendant cancellation context:
    the root's request, reason, requester, source, request time and
    deadline, with itself added to the lineage. It also gets a
    `request_cancellation` command with PHP's default context (no
    principal), the run's request columns (its own request time, the
    root's deadline), `CooperativeCancellationRequested`, and a workflow
    task if none is open. A child already holding a request under the same
    root keeps it; one under another root is a `cancellation_root_conflict`
    rejection.
  - **Waiting.** Under `wait_cancellation_completed`, while the child is
    open, the delivery records no `CooperativeCancellationDelivered`. The
    parent waits and its claim is released, and the worker gets `200` with
    `delivered: false`, `reason: cancellation_waiting_for_child` and
    `claim_released: true`. The child's close records `ChildRunCancelled`
    and resumes the parent, as native already did.
  - **Parent close.** When a cooperatively cancelled parent closes with a
    child still open under `request_cancellation`, the child is asked to
    cancel under the parent's request, once. The parent records
    `ParentClosePolicyApplied` or `ParentClosePolicyFailed`.
  - **Envelope.** Every `deliver-cancellation` answer now carries the worker
    protocol envelope (`protocol_version`, `server_capabilities`), as PHP's
    `WorkerProtocol::json` adds.
- **Remaining gaps** (the port's gap list):
  - The `cancellation_cascade` view in run debug.
  - `request_cancel` and `terminate` parent-close policies.
  - Parent-close enforcement for parents that time out or fail.
  - The rejected command row PHP writes for a root conflict.
- **Tests.**
  - A parent waits on a child parked in a signal wait. The test checks the
    waiting answer, the parent's marker, the child's descendant context
    (null principal, root's time and deadline, lineage of two), the child's
    own cancellation, the parent's resumed delivery and resolution, and the
    order of the parent's events.
  - A `try_cancel` parent closes first and records
    `ParentClosePolicyApplied` for its child's same-root request.
  - The child policy test now expects a propagating parent's request to be
    admitted.
