# October 21, cancellation delivered to a parallel timer group

- **Found by.** The Sample App's timers scenario (`scripts/sdk-timers.sh`)
  against native, in its `concurrent-cancellation` case. Each SDK's workflow
  waits on three concurrent timers and then receives a cooperative
  cancellation request. All three SDKs called `deliver-cancellation` with
  `call_kind: parallel` and `sequence_span: 3`. Native refused with
  `422 cancellation_delivery_boundary_not_available`, the Rust worker
  exited, and the run never recorded its delivery.
- **PHP's behavior.** `CooperativeCancellationDelivery` accepts a
  `parallel` boundary whose span is at most a command batch. At the next
  sequence it records the delivery directly. Otherwise `parallelEligibility`
  requires every member to belong to the group at its index. A group whose
  members all resolved before the request is not eligible.
  `deliverPortableCancellation` then cancels each member's open wait and
  records `CooperativeCancellationDelivered` with `sequence_span`.
- **Native now.** Native applies the same checks to a group whose members
  are all timers:
  - It cancels each pending member timer (`TimerCancelled`).
  - It records the delivery with `sequence_span` and returns it in the
    receipt.
  - A repeated delivery returns the same receipt.
  - A wrong span answers `409 cancellation_delivery_shape_mismatch`.

  A group with an activity, child or wait member keeps its earlier refusal.
  The remaining boundaries are recorded as a known gap.
- **Verification.**
  - A new test in `tests/execution/parallel_cancellation.rs` schedules three
    parallel timers, requests cancellation and refuses a wrong span. It then
    delivers to the group, repeats the delivery, and checks the history:
    three `TimerCancelled`, one delivery with `sequence_span: 3`, then
    `WorkflowCancelled`.
  - The full suite passes on SQLite. The cooperative and cancellation tests
    (53) pass on PostgreSQL and MySQL.
