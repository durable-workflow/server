# October 30, cooperative cancellation at parallel groups and selection handles

- **Before.** Native delivered a run's cooperative cancellation at a
  parallel group only when every member was a timer. It refused a group with
  an activity member, and any `selection_handle` boundary, with `422
  cancellation_delivery_boundary_not_available`. A recording of the new
  fixture against native from main showed that refusal at all three
  boundaries.
- **Contract** (published Server 2.11.7;
  `CooperativeCancellationDelivery::parallelEligibility` and
  `validateCallBoundary`, `WorkflowExecutor::deliverPortableCancellation`).
  - **Parallel group.** It takes one `parallel` delivery whose
    `sequence_span` names the group. Every member must belong to the group
    at its index, or the delivery is `409
    cancellation_delivery_shape_mismatch`. The group is not eligible when a
    member failed, was cancelled or timed out before the request, when its
    selection resolved before the request, or when every member resolved
    before the request.
  - **Selection handle.** It is delivered at the next sequence, naming the
    operation range it awaits (`operation_sequence`,
    `operation_sequence_span`). A range wider than one follows the parallel
    eligibility; a range of one is not eligible once it resolved before the
    request.
  - **Members.** Each member is cancelled by its kind, in sequence order:
    `ActivityCancelled` (the default `try_cancel`) and `TimerCancelled`. The
    delivery records `sequence_span`, `operation_sequence` and
    `operation_sequence_span` as given.
- **Fixture.** `parallel-cancellation`, raw worker HTTP on protocol 1.20
  with a cooperative worker. Three cases:
  - a parallel group of two activities;
  - a mixed group of an activity and a timer, first delivered with a span
    that does not name the group;
  - a selection group of an activity and a timer, delivered as a selection
    handle.

  Each case claims the first activity, and its heartbeat is told to cancel.
- **Native** (`cooperative.rs`).
  - The delivery accepts `selection_handle` with its operation range;
    `operation_sequence` is required for a handle and refused otherwise.
  - The timer-only member check became `parallel_members`, which reads
    timer and activity members under PHP's eligibility rules.
    `cancel_members` cancels each member by kind.
  - The root-membership check and the delivery replay check use the
    operation range.
  - A child, condition or signal member is still refused as unavailable,
    because native authors no such group.
- **Tests.** `activity_groups_and_selection_handles_take_one_delivery`
  (`parallel_cancellation.rs`) covers the three boundaries, the shape
  mismatch and the repeated receipt.
