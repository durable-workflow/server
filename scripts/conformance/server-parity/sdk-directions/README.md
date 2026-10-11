# Published SDK execution directions

This opt-in Server #325 profile defines all nine PHP/Python/Rust workflow and
activity directions using installed published SDKs. Each direction starts a new
run before worker registration, stops a workflow-only author after scheduling,
executes the original activity on its separate queue, then completes in a cold
workflow worker. Exact int64, Unicode and null values and full history prefixes
remain checked.

The separate manifest pins the PHP Server reference, embedded engine, installed
SDK sources and registry checksums. Existing fixtures and manifests remain
unchanged. Embedded runs the canonical PHP author and explicitly marks SDK
transport inapplicable.

Implementation and actual PHP/native/embedded qualification are pending. This
profile does not establish child directions, full language parity, performance,
migration or cutover.
