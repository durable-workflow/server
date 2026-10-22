# October 21, update request rules

- **Found by.** The Sample App's update scenario against native. After its
  update round trips and its snapshot query, the scenario sends an update
  that waits up to 45 seconds for completion. Native refused it with
  `422 unsupported_update_wait_timeout`, because it allowed waits of 0 to
  30 seconds.
- **Published PHP.** `WorkflowController::update` validates the trimmed
  request with Laravel's rules:
  - `request_id`: a string of at most 255 characters;
  - `wait_for`: `accepted` or `completed`;
  - `wait_timeout_seconds`: an integer (a canonical decimal string counts)
    of at least 1, with no upper bound.

  Failures are `422 validation_failed` with Laravel's messages.
- **Native now.** It applies the same rules and messages in place of its
  own `invalid_request_id`, `invalid_update_wait_policy` and
  `unsupported_update_wait_timeout` reasons. A completion wait holds no
  transaction or pooled connection, so a longer wait costs nothing more.
  A zero timeout, which native accepted, is now refused as in PHP.
- **Verification.** A differential probe sent eight requests to published
  PHP 2.5.14 and native: a zero, a non-numeric and a string timeout, two
  invalid `wait_for` values, a too-long and a non-string `request_id`, and
  a valid 45-second wait. The statuses, reasons and validation messages
  match. Both then answer an update the run does not declare with
  `404 unknown_update`; PHP's durable rejection body there is a separate
  known gap.
