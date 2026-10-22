# October 21, activity failure reports

- **Found by.** The Sample App's activity recovery scenario against native.
  The Python SDK (2.5.2) reported its activity's injected failure with
  `class` and `stack_trace`, and native refused the report with
  `unsupported_request_field`. The Python SDK sends both fields with every
  failure raised by activity code, so native refused ordinary Python
  activity failures.
- **Published PHP, from differential probes of 2.5.14.**
  - **Validation.** `ActivityTaskController::fail` validates
    `activity_attempt_id`, `lease_owner`, `failure` and the failure's
    `message`, `type`, `class`, `code`, `stack_trace`, `non_retryable`,
    `retryable`, `kind`, `timeout_type`, `cancelled`, `malformed_output`,
    `details`, `diagnostics` and `runtime_diagnostics`, with Laravel's
    messages. Other fields are ignored, and validation runs before the task
    lookup.
  - **`details`.** PHP resolves it as a command payload. An Avro envelope
    becomes its blob and codec; its codec must be allowed and its blob must
    decode, or the refusal is on `failure.details.codec` or
    `failure.details.blob`. Any other value is kept as given.
  - **Recorded failure.** `FailureFactory` normalizes the report:
    - the class, or `RuntimeException` when absent;
    - the type, or null;
    - the trimmed message;
    - the code when it is a JSON integer, otherwise 0;
    - `file`, `line`, `trace` and `properties` frames, and `details`
      (with `details_payload_codec`), when sent;
    - `non_retryable` only when it is a boolean;
    - `diagnostics` and `runtime_diagnostics` when they are arrays.

    The stack trace and the classification flags are not kept. History
    records the class as `exception_class` and the type as
    `exception_type`; its `exception` has no class, and presents string
    details as an Avro envelope.
  - **Non-retryable error types.** A retry policy's non-retryable error
    types match the reported class or type.
- **Native before.** It accepted only `type` (required), `message`, `code`
  and `non_retryable`, refused any other field in the failure or the body,
  recorded the type as both class and type, always recorded
  `non_retryable`, and matched non-retryable error types against the type
  only.
- **Native now.** It follows the rules above. The normalized failure is
  stored on the execution with the Avro codec, as before.
- **Verification.**
  - Differential probes sent the same reports to published PHP 2.5.14 and
    native: four failure shapes, six `details` shapes, and nine invalid
    bodies. The recorded failure events (exception, class, type, message,
    code, non-retryable flag and activity snapshot) are identical, and so
    are the validation messages.
  - Native's worker-plane error bodies still lack PHP's `protocol_version`
    field, and the unknown-task refusal lacks PHP's guard fields; both are
    recorded as known gaps.
  - New tests pin the recorded failures, the class-or-type policy match
    and the validation messages, using PHP's captured values.
