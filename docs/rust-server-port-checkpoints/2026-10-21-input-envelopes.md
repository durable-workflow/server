# October 21, input envelope refusals

- **Found by.** While porting plain query input, the Waterline harness sent the
  same 15 malformed `input` values to start, signal, update and query on
  published PHP 2.5.14 and on native. In 4 of the 60 cases the responses
  matched.
- **PHP's rules.** These come from `RuntimeExternalPayloadTransport`,
  `AvroPayloadEnvelopeResolver`, `PayloadEnvelopeResolver` and the
  workflow package's Avro serializer:
  - **Transport.** Before validation, the transport refuses any input object
    with an `external_storage` key: `422 external_payload_unsupported`.
  - **Codec.** Any input object with a `codec` key must name `avro`, or have
    an empty codec. Otherwise PHP refuses it on `input.codec` with
    `unsupported_payload_codec: workflow payload codec "X" is not supported …`.
    For a codec that is not a string, X is the PHP type name, such as `int`.
  - **Envelope shape.** An exact envelope also needs a non-empty codec
    string ("The payload envelope codec must be a non-empty string.") and a
    string blob ("The payload envelope blob must be a string."). Laravel's
    middleware turns an empty string into null first.
  - **Signal and update decode their blob.** If decoding fails, PHP answers
    "The input envelope blob could not be decoded with codec "avro": …" with
    the serializer's diagnosis and remediation. A blob that decodes to a
    scalar gets "The input envelope blob must decode to an array." A
    non-empty map supplies its values as the arguments.
  - **Base64.** PHP's strict base64 skips ASCII whitespace. Padding must be
    absent or exactly what the final quantum needs, and unused trailing bits
    may be set.
  - **Envelope.** Every refusal is `422 validation_failed` with the
    operation's control-plane envelope and `workflow_id`.
- **Source.**
  - **Input checks.** `input_envelope` applies the transport, codec and
    shape rules on all four routes.
    - Signal and update refuse undecodable and scalar blobs with PHP's
      messages, and take a map's values.
    - Start parses non-envelope objects in request order, but keeps an object
      whose codec would be refused, so the codec check still sees it.
    - Start also refuses an undecodable blob with the signal message.
      Published PHP 2.5.14 answers that case with a 500 (server#601).
  - **Codec.** `codec::ingress_diagnosis` gives PHP's diagnosis for each
    decode failure: JSON-looking text, bad base64, missing magic, unknown
    fingerprint, trailing bytes, truncation, or a malformed datum. Native's
    Avro decoding now uses PHP's base64 rules everywhere.
  - **Envelopes.** Update refusals now carry the control-plane envelope, as
    signal and query refusals do. A command validation failure always
    includes `workflow_id`.
  - **Stored signals.** A signal already stored and applied during a worker's
    task completion keeps its `invalid_signal_arguments` refusal.
- **Remaining differences.**
  - **Start with an undecodable blob.** Native refuses it with 422; PHP 2.5.14
    answers 500 (server#601).
  - **Query with an undecodable blob.** Native refuses it at admission with
    `invalid_query_arguments`. PHP first checks for a query worker, so
    without one it answers `409 query_worker_unavailable`.
  - **External payload references.** `{codec, external_payload}` input on
    signal, update and query is still unsupported in native. PHP resolves it.
- **Verification.**
  - **Harness.** The 60 malformed-input cases now match PHP's status, reason
    and errors in 56 cases; the 4 left are the start and query differences
    above. For seven refusal cases covering signal, update, query and an
    unknown workflow, the bodies are identical, envelope included.
  - **Tests.** `input_envelopes` covers the refusals on start and signal, plus
    a map blob and an unpadded blob, on SQLite, PostgreSQL and MySQL. Unit
    tests cover the diagnoses and the base64 rules. The query and update
    tests now expect PHP's `validation_failed`. The full native suite passes
    on SQLite.
