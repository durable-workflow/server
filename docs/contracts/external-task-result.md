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
- `fixtures.cancellation`
- `fixtures.handler_crash`
- `fixtures.decode_failure`
- `fixtures.unsupported_payload_codec`
- `fixtures.unsupported_payload_reference`

Each fixture is published as a consumable artifact object, not as a repository
path. Carriers can read the `artifact`, `media_type`, `schema`, `version`,
`sha256`, and embedded `example` fields from cluster info and validate their
own parser behavior without cloning this repository or scraping prose.

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

The stable fixture artifacts are:

- `durable-workflow.v2.external-task-result.success.v1`
- `durable-workflow.v2.external-task-result.failure.v1`
- `durable-workflow.v2.external-task-result.malformed-output.v1`
- `durable-workflow.v2.external-task-result.cancellation.v1`
- `durable-workflow.v2.external-task-result.handler-crash.v1`
- `durable-workflow.v2.external-task-result.decode-failure.v1`
- `durable-workflow.v2.external-task-result.unsupported-payload-codec.v1`
- `durable-workflow.v2.external-task-result.unsupported-payload-reference.v1`
