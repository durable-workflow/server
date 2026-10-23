# October 23, workflow task command payloads must decode

- **The gap.** Native recorded a workflow task completion whose command
  payloads were not Avro values. A run could complete with a result no
  client can decode, or schedule an activity with arguments no worker can
  read. Published Server 2.11.1 refuses the whole completion first, with a
  Laravel validation failure naming the field. Its checks are
  `resolveWorkflowTaskCommandPayloadReferences` and then the command
  normalizer.
- **What native does now.** Before it applies any command, native checks
  each payload field PHP lists in `PAYLOAD_ENVELOPE_FIELDS`, in command and
  field order, and stops at the first invalid one:
  - A string must decode as one complete Avro value. Otherwise the refusal
    names `commands.N.field`.
  - In an envelope, the codec must be allowed, or the refusal names
    `.codec` with PHP's `unsupported_payload_codec` message.
  - An exact `{codec, blob}` envelope needs a non-empty codec and a string
    blob, and the blob must decode, or the refusal names `.blob`.
  - A `complete_workflow` result or `schedule_activity` arguments that are
    neither a string nor an envelope are refused as PHP refuses them.

  External references keep their own path. A refused completion records
  nothing, and the task stays leased.
- **How it was checked.** A probe sent 18 completions to native and the
  published image. They covered garbage, truncated and non-Avro blobs,
  other codecs, plain values, envelopes missing a blob, blobs that are not
  strings, empty codecs, and a bad second command. The statuses, reasons
  and field errors matched, with three exceptions that predate this
  change:
  - PHP accepts a bare string `complete_workflow` result; native refuses it
    with `unsupported_payload_codec`.
  - PHP accepts plain continue-as-new arguments; native refuses them the
    same way.
  - PHP reports a non-integer timer delay as a Laravel field error; native
    reports `invalid_timer_delay`.

  SDKs send envelopes, so the first two do not affect them.
  `completion_payloads_must_decode_like_php` passes on SQLite, PostgreSQL
  and MySQL.
