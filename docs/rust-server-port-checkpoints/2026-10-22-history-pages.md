# October 22, history pages for workflow tasks

- **Gap.** Native served a polled workflow task 100 history events unless
  the worker named a page size. Continuation pages were always 100 events,
  whatever the worker asked for. Native refused to start with
  `DW_HISTORY_PAGE_SIZE_DEFAULT` or `DW_HISTORY_PAGE_SIZE_MAX` changed, and
  it ignored `accept_history_encoding`. Current SDKs send a page size and
  follow tokens. A client that does neither got a cut-off history from
  native, where PHP sends all of it.
- **Source.** Server 2.10.0's `WorkerController` and `WorkflowTaskPoller`,
  and Workflow 2.9.0's `historyPayloadPaginated` and
  `HistoryPayloadCompression`. Server 2.5.14 pages the same way.
  - **Polls.** A poll returns the whole history, with a null page token,
    unless the worker sends `history_page_size` or an encoding native
    supports.
    - A requested size is capped by `DW_HISTORY_PAGE_SIZE_MAX`.
    - An encoding alone pages at `DW_HISTORY_PAGE_SIZE_DEFAULT`, because
      compression works on pages.
    - An explicit null size counts as no size, as in Laravel's `nullable`.
  - **Continuation pages.** `POST /api/worker/workflow-tasks/{id}/history`
    serves the requested size, else the default, capped by the maximum.
  - **Bounds.** Every page holds 1 to 1000 events, as the bridge clamps
    them. Native still ends a page early at 8 MiB of serialized events; the
    page token continues from there.
  - **Settings.** Both settings are honored as PHP's `(int)` casts, 500 and
    1000 when unset.
  - **Encoding.** `accept_history_encoding` is a comma-separated,
    case-insensitive list (`nullable|string|max:64`). gzip wins over
    deflate. A page of 50 or more events goes as one gzip member or raw
    deflate stream (zlib level 6) of its JSON array, base64-encoded in
    `history_events_compressed`. `history_events_encoding` names the
    encoding and `history_events` is empty. Smaller pages and other
    encodings stay plain. An invalid value gets Laravel's validation
    response.
  - **Replays.** Compression happens after the claim commits. A replayed
    poll receipt keeps the plain events and takes the replaying request's
    encoding.
- **Tests** (`history_pages.rs` and unit tests).
  - A poll without a size returns the whole history, past 120 received
    signals, also with explicit nulls.
  - A 50-event poll page continues at the default size and at a requested
    size. Invalid sizes are refused.
  - gzip and deflate polls decode to the same events a plain continuation
    serves, and a page under 50 events stays plain. Invalid encodings get
    Laravel's messages.
  - With a configured default of 30 and maximum of 40, a requested 100 gives
    40 and a continuation gives 30.
  - Unit tests cover the size rules, the encoding list, Laravel validation,
    gzip framing and CRC, and the settings' `(int)` reading.
