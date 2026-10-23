# October 23, workflow task completion rules

Before published Server 2.11.1 applies a workflow task completion, it checks
it in this order:

1. Laravel's request middleware trims every string and turns empty ones into
   null.
2. `WorkflowTaskCompletionValidation::rules` and the Workflow package's
   parallel metadata rules check the top-level fields and every `commands.*`
   field.
3. Digit strings in its integer fields become integers.
4. `validateWorkflowTaskCommandScopes` checks options used on the wrong
   command, and activity and child timeout envelopes.
5. The task ownership guard runs.
6. The payload pass canonicalizes each command's `payload_codec` and checks
   each payload.
7. The command normalizer checks field scopes and per-command values.

Native had no equivalent of steps 1 to 4 and few of PHP's messages for step 7.
A malformed completion got a native reason such as `invalid_request`,
`invalid_commands`, `invalid_timer_delay` or `unsupported_request_field`, or
a 409 about the attempt. PHP answers 422 `validation_failed` with field errors.
Native now runs the same stages with PHP's messages.

## What native now does

- **Trimming.** Strings are trimmed with Laravel's `Str::trim` set (PHP's
  trim characters, `\s` and `Str::INVISIBLE_CHARACTERS`), and empty strings
  become null. A non-retryable error type padded with em spaces therefore
  still matches, and a padded failure message is stored trimmed, as PHP
  stores it. The same pinned set replaces the Unicode whitespace predicate
  native used for control-plane bodies.
- **Transport rules.** Errors are listed as Laravel lists them:
  - commands are validated in chunks of 25, keeping each command's index;
  - within a chunk, rule by rule, each rule's expansions in data order;
  - the first error is the response message;
  - a wildcard attribute is named as written (`commands.0.type`), a named
    dotted one with spaces (`sticky cache.workflow id`).

  The rules include `sometimes`, `prohibited` (the five `principal_*`
  fields), `required_with` (cancellation cleanup, workflow streams, sticky
  cache) and `array:keys`. These checks come before ownership, so a non-owner
  gets the field error, as from PHP.
- **Digit strings.** After the rules pass, PHP's integer fields, the attempt,
  retry policy and worker session fields turn digit strings into integers.
  `"delay_seconds": "7"`, `"max_attempts": "2"`, `"version": "1"` and a wait
  timeout of `"1"` are accepted. `"+5"` and `5.0` pass Laravel's rule but stay
  non-integers, so the normalizer refuses them, as PHP's does.
- **Command scopes.** PHP's controller messages:
  - `retry_policy is only supported for schedule_activity and
    start_child_workflow commands.`;
  - the same for activity timeouts, worker sessions, child timeouts,
    cancellation policy, `non_retryable`, `exception` and failure identity;
  - the activity and child timeout envelopes, such as `heartbeat_timeout
    cannot exceed start_to_close_timeout.`
- **Normalizer checks.** Each command gets:
  - `FIELD_SCOPES` (`Workflow task command field [delay_seconds] is not valid
    on a record_version_marker command. …`);
  - the timer delay message;
  - `Open signal|condition wait timeout_seconds must be a non-negative integer
    when provided.`;
  - the version marker messages under `commands.N` (`change_id is required`,
    `version must be an integer`, …).

  A marker version outside its range is PHP's bridge refusal: 409 with
  `outcome: completed`, `recorded: false` and `reason: invalid_commands`.
- **Payload codecs.** Any command's `payload_codec` is canonicalized first,
  so an unknown codec is refused with PHP's `unsupported_payload_codec`
  message, on a marker too.
- **Bare payload strings.** A bare string `complete_workflow` result, or bare
  string activity, child or continue-as-new arguments, is the Avro blob
  itself, as PHP's normalizer reads it. An empty string is no payload.
- **Plain continue-as-new arguments.** An array or object without a codec is
  no arguments to PHP, so the next run keeps the current input. A number is
  refused with PHP's message.
- **An open run is waiting after its task.** PHP's executor leaves every run
  that a completed task did not close `waiting`, even when the task recorded
  only version markers. Native left such a run `pending`.

## Old expectations PHP contradicted

Eleven native tests asserted answers the published image does not give. Each
was compared live with 2.11.1 and corrected:

| Case | Old expectation | Published PHP |
| --- | --- | --- |
| Timer and wait delays `"1"`, `"7"` | refused | accepted |
| Marker `version: "1"`, `min_supported: "-1"` | refused | accepted |
| Retry policy `max_attempts: "2"` | refused | accepted |
| Child `cancellation_policy: 1` | one message | both the `string` and `in` messages |
| Partial `sticky_cache` | accepted | `required_with` errors |
| Marker version outside its range | 422 | 409 `invalid_commands` |
| Marker `payload_codec: newer-ignored-codec` | ignored | refused |
| Em-space padded non-retryable type | no match | trimmed, matches |
| Padded exception message | kept padded | trimmed |
| `heartbeat_timeout` over `start_to_close_timeout` | accepted | refused |
| Invalid delays and timeouts | native reasons | Laravel and normalizer messages |

## Differences that remain

- **Unknown and null command fields.** PHP ignores them. Native's per-command
  field lists refuse them with `unsupported_request_field`. Those lists also
  refuse fields for features native cannot run (worker sessions, local
  activity attempts, workflow streams), so relaxing them needs PHP's full
  normalizer read set first. That is the next slice.
- **Numbers too large for a timestamp.** PHP accepts `9223372036854775807` as
  a timer delay, retry backoff or wait timeout. For a timer it wraps the fire
  time into the past (year -292277022601), so the timer fires at once. Native
  refuses these as before.
- **Errors across commands.** PHP's normalizer collects errors from every
  command. Native reports the first command's normalizer errors.
- **An object `commands` value.** PHP answers 500. Native refuses it with
  `invalid_commands`.

## How it was checked

Probes sent the same completions to native and the published image:

- 34 malformed or edge-case completions: top-level fields, wrong types and
  bounds, nested wildcards, `prohibited`, `required_with`, `array:keys`, a
  30-command batch spanning two chunks, unknown and null fields;
- every input from the eleven tests above;
- timer delays, bare strings and plain continue-as-new arguments;
- version markers;
- trimming.

Statuses, reasons, messages and field errors match, apart from the
differences above. Error map key order differs, since native's JSON objects
sort their keys. `tests/execution/completion_rules.rs` covers the new
behavior on SQLite, PostgreSQL and MySQL.
