# October 22, child policy contract

- **Why.** The second slice of the child cancellation cascade. Published SDK
  workers start children with `parent_close_policy: request_cancellation`
  and `cancellation_policy: wait_cancellation_completed` (PHP SDK 3.1.0,
  Python SDK 2.7.0, Rust SDK 4.1.0 in the Sample App). Native refused both
  with `422 unsupported_child_options`, and the PHP worker stopped. PHP SDK
  replay compares the policies recorded in `ChildWorkflowScheduled` and
  `ChildRunStarted` with its command, so they must be stored as sent.
- **Contract** (Workflow 2.9.0 and Server 2.10.0):
  - **Request rules.** Laravel trims the request and turns blank strings
    into null, so a blank policy is the default `abandon`. A non-string
    policy, or a cancellation policy other than `try_cancel`,
    `wait_cancellation_completed` or `abandon`, is refused with Laravel's
    `422 validation_failed`.
  - **Guard.** A `request_cancellation` parent-close policy or a
    propagating cancellation policy needs a cooperative worker's claim and a
    protocol 1.20 request. Otherwise the completion is refused with
    `409 child_cancellation_policy_not_supported` and the missing
    `unavailable` parts (`request_protocol`, `worker_claim_capability`).
  - **Normalizer.** A parent-close policy other than `abandon`,
    `request_cancel`, `request_cancellation` or `terminate` is refused with
    PHP's validation message.
- **Persistence.** The child call records the parent-close policy,
  `cancellation_propagation` and the cancellation policy in its metadata;
  the link records the parent-close policy; and the parent's
  `ChildWorkflowScheduled` and `ChildRunStarted` carry both policies.
- **Remaining gap** (the port's gap list): native does not act on the
  policies yet. A parent with a propagating child keeps the explicit
  cooperative cancellation refusal, and parent-close enforcement waits for
  the cascade slice.
- **Tests.** The sequential children test listed `terminate` and
  `try_cancel` as refused; it now expects `try_cancel` from a
  non-cooperative claim to get the 409. New tests check the validation
  messages, padded values, the recorded policies, the kept cancellation
  refusal, and the guard's `unavailable` list.
