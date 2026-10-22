# October 22, start definition fingerprints

- **Found by.** The third disposable VM run of the Sample App against
  native (`dw-rs325-20261022T032200`). The timers scenario's Python class
  upgrade phase failed with "Python class upgrade requires one original
  worker-sourced definition fingerprint". It reads
  `workflow_definition_fingerprint` and
  `workflow_definition_fingerprint_source: "worker"` from the run's
  `WorkflowStarted` event.
- **Cause.** Published PHP's `WorkflowStartVersionPin` records the
  definition the start's worker cohort agrees on. This rule is the same in
  Server 2.5.14 and 2.11.0. Native recorded no fingerprint.
- **Source.** A new start helper applies PHP's rule to the queue's live
  registrations: heartbeated within the stale window or never, and not
  draining or deregistered.
  - **Cohort.** The single build id among the workers for the type, else
    only unversioned workers for the type. Failing both, the same test over
    all the queue's workers. Native has no build-id rollouts, so PHP's
    promoted-rollout cohort never applies.
  - **Agreement.** Every cohort worker for the type must have heartbeated
    and advertise the same non-empty fingerprint of at most 255 characters,
    compared trimmed. Otherwise no fingerprint is recorded.
  - **Payload.** `WorkflowStarted` carries the fingerprint with source
    `worker` only when one is recorded, as PHP filters out null fields.
    Child and continue-as-new runs record none, as in PHP.
- **Effect.** Native's query routing already follows a recorded
  fingerprint, as PHP does, so queries for such a run now need a worker
  that advertises it. The upgrade phase's later steps use behavior native
  already has: the replacement worker's replay-blocked failure, the debug
  view's latest failure, and repair of the same task.
- **Remaining gaps** (the port's gap list): 2.10.0's definition routing for
  workflow tasks, and PHP's start build-id pin.
- **Verification.**
  - Unit tests cover agreement, disagreement, a missing advertisement, a
    worker that never heartbeated, workers without the type, and the
    build-id cohort choices.
  - A new integration test registers workers with fingerprints and checks
    the started event as workers join, leave and stop advertising.
