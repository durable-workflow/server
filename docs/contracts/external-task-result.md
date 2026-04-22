# External Task Result Envelope

The external task result envelope is the carrier-neutral JSON shape for handler
success and failure. It is not an exit-code convention, a stderr parser, or one
runtime language's exception shape.

The authoritative machine-readable contract is published from
`GET /api/cluster/info` at
`worker_protocol.external_task_result_contract`:

- `schema: durable-workflow.v2.external-task-result.contract`
- `version: 1`
- `envelopes.success`
- `envelopes.failure`
- `envelopes.malformed_output`
- `fixtures.success`
- `fixtures.failure`
- `fixtures.malformed_output`

Version 1 defines named machine outcomes. A carrier can decide whether work
succeeded, whether a failure is retryable, whether the outcome is timeout or
cancellation related, and whether handler output was malformed without scraping
human prose.

Exit codes are transport signals only. Exit code 0 is accepted as success only
when the carrier also receives a valid success envelope. Non-zero exit codes
must be mapped to a valid failure envelope or to `malformed_output`; they do not
replace the JSON contract.

Stderr is logs-only and has no machine meaning. Carriers may attach stderr
snippets as diagnostic metadata, but retryability, timeout type, cancellation,
failure kind, and malformed-output state must come from the envelope or carrier
policy.

Payloads are always codec-tagged when present. A handler that cannot decode an
input payload codec must fail with `failure.kind: unsupported_payload` and
`failure.classification: unsupported_payload_codec`. A handler that cannot
resolve an external-storage payload reference must fail with
`failure.classification: unsupported_payload_reference`.

Stable fixtures live in:

- `tests/Fixtures/contracts/external-task-result/success.v1.json`
- `tests/Fixtures/contracts/external-task-result/failure.v1.json`
- `tests/Fixtures/contracts/external-task-result/malformed-output.v1.json`
