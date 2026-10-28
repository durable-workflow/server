# October 28, parent-close cancellation origin

- **Before.**
  - Native applied the `request_cancellation` parent-close policy only when
    the parent closed under its own cooperative cancellation request.
  - Otherwise it refused the parent's completion
    (`409 pending_durable_operations`), HTTP cancel and terminate
    (`422 ..._propagation_not_available`), and a policy close of such a
    parent (`ParentClosePolicyFailed`).
  - A parent that failed or timed out closed and left the child open.
- **Contract** (published 2.11.5, Core 2.15.1; `ParentClosePolicyEnforcer`,
  `ParentCloseCancellation::ensureOrigin` and `::context`,
  `WorkflowStub::attemptRequestCancellationFromParent`):
  - Every close of the parent applies the policy, in link order. A child
    already given the policy is skipped.
  - **Origin.** For the first open child, a parent with no accepted request
    records one `ParentCloseCancellationRequested` origin after its latest
    terminal event. Its payload holds:
    - `parent_terminal_history_event_id` and `parent_terminal_event_type`;
    - a root `cancellation-context/v1` with source `parent_close_policy`,
      requester `{type: workflow, id: <instance>, label: Parent-close policy}`
      and the first child's policy reason;
    - `requested_at`, the terminal event's time;
    - `cleanup_deadline_at`, 600 seconds later;
    - a lineage of one.
  - **Children.** Each child takes a descendant `request_cancellation`
    command under that root, with the root's deadline, and records
    `CooperativeCancellationRequested`. The enforcer loads the child's
    instance and asks as the parent (`CommandContext::workflow`): source
    `workflow`, target scope `instance`, and a `workflow` context naming
    the parent instance and run, the child's sequence and its link. Native
    recorded a run-scoped PHP API command here before, also under a
    parent's own cooperative request. The cascade path
    (`ChildCancellation`) keeps the run-scoped PHP API command, as in PHP. Its next workflow task carries the
    request. The parent records `ParentClosePolicyApplied` with the child's
    request id and context.
  - **Existing request.** A parent with an accepted request keeps it as the
    root. If that request's context is unavailable, the child's request is
    refused: `ParentClosePolicyFailed`, `cancellation_parent_context_unavailable`.
- **Fixture.** `parent-close-cancellation` is raw worker HTTP on protocol
  1.20, in its own namespace. It records three cases against published
  2.11.5:
  - a parent completes in the batch that starts its child;
  - a parent fails in that batch;
  - a parent with two such children is terminated over HTTP.

  Ids are labels by first appearance. Instants are relations to the origin.
  Embedded applications have no worker protocol.
- **Native.**
  - `enforce_parent_close_policies` records the origin lazily, once per
    closed run (`ensure_parent_close_origin`).
  - The completion refusal and the shared `close_unavailable` rule are
    removed.
  - The cascade view already read the origin
    (`ParentCloseCancellation::context`).
- **Tests.** `parent_close_policies.rs` covers:
  - all five closes (complete, fail, terminate with two children, cancel,
    run timeout);
  - a grandchild given its closed child's own root;
  - a parent request without its context.

  The three new tests fail on the previous source.
- **Still open.** Children with more than 1,000 open activities or timers,
  or 32 levels deep, are still left open with `ParentClosePolicyFailed`.
  PHP records a rejected command on the child for a refused request, and
  native records only the refusal (root conflicts).
