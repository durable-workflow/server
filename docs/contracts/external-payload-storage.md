# External Payload Storage

External payload storage lets a namespace keep large encoded workflow payloads
out of worker/history JSON by storing bytes through a configured driver and
passing a verified reference envelope instead.

## Driver policy

Namespace policy lives on `external_payload_storage`:

- `driver`: `local`, `s3`, `gcs`, `azure`, or `custom`.
- `enabled`: disables reference handling when false.
- `threshold_bytes`: encoded payloads larger than this value are externalized.
  When omitted, the server payload limit is used.
- `config`: driver-specific settings. Object-store and custom drivers use a
  Laravel filesystem `disk`, a bucket/container/name, and an optional
  `prefix`. Custom drivers also provide the URI `scheme` emitted in
  references, so operators can register a Flysystem adapter without forking the
  server.

`GET /api/cluster/info` exposes the active namespace discovery view at
`namespace.external_payload_storage`. That object is safe for worker discovery:
it includes the reference schema, driver name, enabled/configured status,
threshold, URI scheme, supported driver list, and whether driver config was
redacted, but it does not include the raw driver config.

Every external reference has schema
`durable-workflow.v2.external-payload-reference.v1` and carries `uri`,
`sha256`, `size_bytes`, and `codec`. The server verifies size and SHA-256
before accepting a referenced payload from a worker or control-plane caller.

## Runtime behavior

Workers and SDKs may send `{codec, external_storage}` wherever the worker
protocol accepts a payload envelope. The server fetches and verifies the bytes
before committing the workflow command or activity result. The same namespace
policy is bound into the workflow runtime: oversized encoded workflow inputs,
activity inputs, activity results, and workflow outputs are persisted as stored
external references before they enter history. Worker poll, history, query,
workflow describe, and standalone activity describe responses expose those
references as `{codec, external_storage}` when the namespace policy is enabled.
Workflow task completions preserve top-level `payload_codec` fields for command
fields that the workflow package declares as accepting payload envelopes. This
includes `complete_update.result`, so externally stored update results keep the
resolved codec alongside the inline bytes passed to the package normalizer.
`record_side_effect` result bytes may carry `payload_codec` or a codec-tagged
payload envelope, and history stores the side-effect result with that codec.

Small payloads remain inline as `{codec, blob}`. Existing inline history stays
readable; externalization is a transport shape for oversized payloads, not a
new payload codec.

## Failure modes

If a worker submits a reference and the payload is missing, has the wrong size,
or fails SHA-256 verification, the server rejects the completion with
`reason: external_payload_integrity_failed` and does not record a history
event.

If the configured storage backend is unavailable while the server is resolving
a worker-submitted reference, the server returns
`reason: external_payload_storage_unavailable`. The workflow or activity task
remains leased until the normal lease/retry path makes it available again.

If the backend is unavailable while the server is writing an oversized payload
for a workflow start, workflow-task completion, or activity completion, the
state transition is not committed. The caller receives a storage-unavailable
failure and can retry after the backend recovers; already-recorded history is
left unchanged.

If a worker receives an external reference for a provider it does not support,
it must fail the task as an unsupported payload reference instead of treating
the reference object as application data.
