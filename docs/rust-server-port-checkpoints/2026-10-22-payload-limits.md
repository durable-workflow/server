# October 22, request body admission

- **Gap.** Published PHP prepends `EnforcePayloadLimits` to every request,
  ahead of authentication and routing. Native checked malformed JSON only on
  the two system passes. Elsewhere it answered a plain-text 400 or 415 from
  its JSON extractor, after authentication. Its body cap was a fixed 3 MB.
- **Source.** A new outermost layer applies PHP's checks in PHP's order:
  - **Size.** A declared `Content-Length` or a body over
    `server.limits.max_payload_bytes` (`DW_MAX_PAYLOAD_BYTES` or
    `WORKFLOW_MAX_PAYLOAD_BYTES`, default 2 MiB) is refused with
    `413 payload_too_large` and the limit.
  - **Media type.** A POST, PUT, PATCH or DELETE with a body must use
    `application/json` or a `+json` type, or it is refused with
    `415 unsupported_media_type` and the accepted types.
  - **JSON.** A body PHP's `json_decode` would refuse is refused with
    `400 malformed_json` and PHP's `json_last_error_msg()` text: syntax,
    malformed UTF-8, control character, unpaired surrogate, or nesting deeper
    than 512 levels. A whitespace-only body is malformed, as in PHP.
  - **Envelope.** Worker-plane routes answer in the worker protocol envelope.
    Others use the control-plane envelope.
  - **Exception.** The external payload upload keeps its own transport rules.
  The layer reads each body once and bounds it, so the fixed 3 MB cap is
  removed.
- **Remaining gap.** A body nested 129 to 512 levels deep passes this check,
  as in PHP, but native's handlers then refuse it (the port's gap list).
- **Verification.**
  - Unit tests map serde_json's errors to PHP's messages, including both
    unpaired surrogate forms, a valid surrogate pair and the 512-level depth.
  - A new integration test checks unauthenticated malformed bodies, worker
    and control-plane envelopes, a missing or non-JSON media type, an
    oversized body and a `+json` type passing on to authentication.
  - The full SQLite suite passes.
