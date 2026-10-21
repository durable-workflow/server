# October 21, plain query input

- **Found by.** The published dw 2.3.0 CLI sweep. `workflow:query ID state
  --input '[1]'` was refused by native but accepted by published PHP 2.5.14.
  Native's query route took only an Avro envelope as `input`.
- **Source.** Query input now resolves like PHP's
  `AvroPayloadEnvelopeResolver`, and like native's start, signal and update:
  - an object whose keys are exactly an envelope's is used as it is;
  - any other list or object is its values, encoded with Avro;
  - absent or null input is the official empty arguments.

  For example, `{"codec":"avro"}` is one plain argument, as in PHP. Like
  Laravel's middleware, the query body is trimmed first, so an envelope
  codec of `" avro "` is accepted, as it already was on start, signal and
  update.
- **Verification.**
  - The `query_arguments` integration test covers several cases. A plain list,
    a plain object and an envelope with a padded codec each reach the worker
    as `[7]` for a query that takes one argument. A plain list is refused with `invalid_query_arguments` for a
    query that takes none.
  - In the Waterline harness, dw 2.3.0's `workflow:query` with `[1]` and
    `{"a":1}` received the same `409 query_worker_unavailable` from
    published PHP 2.5.14 and from native.
  - The same probe found that native and PHP refuse a malformed envelope
    differently, on start, signal, update and query alike. That gap is
    recorded in the port log.
