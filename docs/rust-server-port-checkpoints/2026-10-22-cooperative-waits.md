# October 22, cancellation at signal and condition waits

- **Why.** This is the first slice of the child cancellation cascade the
  Sample App's children scenario exercises (the cascade spec is in the
  port's gap list under child cancellation policies). Its children park in a
  condition wait (PHP and Python) or a signal wait (Rust), take the
  cooperative cancellation there, and clean up with a side effect and a
  timer before closing. Native refused each step: the request while a wait
  was open, the delivery at a wait boundary, and the side effect command.
- **Source.**
  - **Request.** A root cancellation request is admitted while the run
    waits on a signal or condition, as published PHP admits it whatever the
    run waits on. Native still refuses runs with non-abandoned children or
    open activities.
  - **Delivery.** `deliver-cancellation` accepts `call_kind` `condition` and
    `signal`, with PHP's `validateCallBoundary` rules. At an authored
    sequence, the step must be that kind of wait (a timed wait's timeout
    timer belongs to it), or the delivery is refused with
    `cancellation_delivery_shape_mismatch`. A wait resolved before the
    request is refused with `cancellation_delivery_not_eligible`. As in
    `cancelOpenWaitAtSequence`, a pending timeout timer at the wait is
    cancelled.
  - **Side effects.** `record_side_effect` is implemented and advertised:
    one authored sequence and `SideEffectRecorded {sequence, result, task}`.
    It is also allowed during cancellation cleanup. The next authored
    sequence counts it.
  - **Principal.** PHP records `CooperativeCancellationDelivered`, the
    cancellation's `TimerCancelled` and the cooperative `WorkflowCancelled`
    with the request's id, not its command. They now carry no command
    snapshot, so their principal is null, as the Sample App asserts.
- **Tests.** The cooperative update tests had asserted the old open-wait
  refusal before they requested cancellation; that check is gone. A new
  test parks a run in a signal wait, requests cancellation, checks that a
  condition delivery at the signal wait is a shape mismatch, delivers at
  the signal, cleans up with a side effect, and checks the cancelled run's
  history and null principals.
