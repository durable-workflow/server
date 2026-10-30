# October 29, cooperative cancellation of a retried child (#873)

- **Before.** After #869, a retried child call has a link per attempt, and
  the call names the latest attempt's run. Native's cooperative cancellation
  expected one link per call:
  - `child_link_policies` rejected the call's links, so a parent's
    cancellation request was refused (`422
    cooperative_cancellation_propagation_not_available`);
  - the delivery boundary also required one link and one `ChildRunStarted`
    naming the call's cancellation policy.

  Security's review of #869 found the gap.
- **Contract** (published Server 2.11.7). The request is admitted and
  delivered at the child boundary, and the parent completes as cancelled.
  - Under `abandon`, the retry keeps running.
  - Under `try_cancel`, the parent records `ChildCancellationRequested`
    naming the retry run, and that run, not the failed first run, records
    `CooperativeCancellationRequested`.
  - PHP's retry `ChildRunStarted` carries no `cancellation_policy`.
- **Fixture.** `retried-child-cancellation`, raw worker HTTP on protocol
  1.20 in a fresh namespace, with a cooperative parent worker and a separate
  child worker. One case per policy (`abandon`, `try_cancel`). It records
  the answers, the parent's child and cancellation events, the child's runs
  and the retry run's events.
- **Native.**
  - `child_link_policies` accepts a retried call's links: the first under
    the call's id, each retry under its own, and earlier attempts.
  - The delivery boundary reads every link of the call and picks the one
    for the call's current run. It accepts the call's scheduled event, and
    one started event per attempt; a retry's may omit the cancellation
    policy.
- **Tests.** `a_parents_cooperative_cancellation_reaches_a_retried_child`
  (`child_retries.rs`) covers both policies.
