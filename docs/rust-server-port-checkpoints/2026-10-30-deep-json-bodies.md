# October 30, request bodies nested as deep as PHP reads them

- **Before.** Native's handlers parsed request bodies with serde_json's
  default 128-level recursion limit. A body nested 129 to 511 levels deep
  passed native's pre-route body check, then was refused with a plain-text
  `400`. The pre-route check also admitted 512 levels, one more than PHP.
- **Contract** (published Server 2.11.7, recorded first). PHP's middleware
  `json_decode`s every body at its default depth of 512, which admits 511
  levels of nesting, with or without a scalar innermost. At 512 levels a
  body is refused `400 malformed_json` with `json_error` "Maximum stack depth
  exceeded". Below that, the route answers as usual.
- **Fixture.** `deep-json-bodies`, raw HTTP in a fresh namespace. A workflow
  start and a search attribute definition, each with a body nested 128,
  129, 511 and 512 levels, record the status and body: the start 201 and the
  definition `422 validation_failed` (its name is not a string) through 511
  levels, then `400 malformed_json` at 512.
- **Native** (`json_body.rs`).
  - `from_slice` reads a body with serde_json's recursion limit disabled
    only when the body nests at most 511 levels (`payload_limits::depth`),
    so recursion is always bounded.
  - A `Json` extractor replaces axum's for request bodies. It asks axum's own
    extractor first, so content-type and every rejection stay as before, and
    retries only a syntax error with `from_slice`.
  - The handlers that parse raw bytes (`request_json`, schedules, task
    repair and the bodies read as objects) use `from_slice` too.
  - The pre-route check now admits 511 levels, not 512.
  - Runtime threads, workers and blocking threads alike, get a 64 MiB stack
    (`main.rs`). Handlers walk a deep body recursively, and the Avro encoder
    (`apache-avro`) is the deepest user: a debug build needs about 80 KiB
    per level, so a 511-level start input overflowed both the default 2 MiB
    and a 16 MiB stack. The stack is reserved address space, not committed
    memory; resident memory does not grow with it.
- **Tests.** `json_body` unit tests at 128, 129, 510, 511 and 512 levels;
  `payload_limits` unit tests; `bodies_are_read_to_php_s_depth`, which
  starts workflows with 128-, 129- and 511-level bodies on a runtime with
  the server's thread stack and refuses 512; and the fixture.
- **Not changed.**
  - A body that is both deeper than 128 levels and invalid for its typed
    route can name a different serde error than at shallower depths; the
    status is unchanged.
  - **Deep payloads in the Avro codec.** A start encodes its input once; a
    signal encodes its arguments and decodes them again. In a debug build a
    511-level signal argument overflowed even the 64 MiB stack in the
    decoder, where a 300-level one passed. Release builds use much smaller
    frames, but this was not measured, so the fixture's second route is a
    definition whose deep name never reaches the codec. Bounding the codec's
    own recursion (`apache-avro`, vendored) is a separate change.
