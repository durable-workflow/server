# Runtime External Payload Transport

External payload storage keeps large encoded workflow payloads outside worker
and history JSON. The namespace runtime owns the backing storage provider,
credentials, integrity checks, retention, and deletion. Applications use their
normal runtime URL, namespace, and role credential; they do not configure or
parse the runtime's bucket, container, filesystem path, or provider URI.

## Discovery and reference shape

`GET /api/cluster/info` publishes the active namespace policy at
`namespace.external_payload_storage` and the same transport capability at
`worker_protocol.server_capabilities.runtime_external_payload_transport`.
Discovery includes the inline threshold, transport paths, maximum external
payload size, timeout budget, expiry policy, cache behavior, typed outcomes,
and direct-adapter policy. It intentionally omits backing-driver identity and
configuration.

The only remote reference schema is
`durable-workflow.v2.runtime-external-payload-reference.v1`:

```json
{
  "schema": "durable-workflow.v2.runtime-external-payload-reference.v1",
  "reference_id": "ep_01J...",
  "codec": "avro",
  "size_bytes": 4194304,
  "sha256": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
}
```

Payload envelope fields use `{codec, external_payload}`. Persisted fields named
`payload_reference` use the reference object directly. Provider-specific
`external_storage` envelopes and string provider URIs are not accepted on the
remote boundary.

## Upload and fetch

Upload encoded bytes with `POST /api/external-payloads/v1` using
`application/octet-stream` and these declared metadata headers:

- `X-Durable-Workflow-Payload-Codec`
- `X-Durable-Workflow-Payload-Size`
- `X-Durable-Workflow-Payload-SHA256`

The runtime rejects the request before committing a reference when the declared
size exceeds its configured maximum or the observed size or SHA-256 differs.
Successful content-addressed retries in the same namespace return the same
stable reference identity.

Fetch bytes with `GET /api/external-payloads/v1/{referenceId}` and send the same
metadata headers from the reference. The runtime binds the lookup to the
authenticated namespace, fetches the backing bytes, verifies size and SHA-256,
and returns `application/octet-stream` with the verified metadata headers.
SDKs verify the returned bytes again before Avro decode.

Both operations use bounded buffering up to `max_payload_bytes`. Discovery
publishes the request timeout. Fetch responses use private, short-lived,
immutable caching; SDK caches must be bounded and cannot delete runtime-owned
objects.

## State, expiry, and retention

An upload starts as unclaimed and expires after the advertised abandoned-upload
window. The first verified state-bearing request claims it. Claimed references
do not expire independently of retained workflow state. Workflow history,
schedules, updates, stream items, activity state, and results remain the
retention authority. SDKs have no delete endpoint, and namespace/run cleanup
does not remove a backing object while retained state in any namespace still
owns it.

The singleton maintenance runner executes
`external-payloads:cleanup --limit=100` on every maintenance pass. The Docker
topology runs a pass every 10 seconds and the default Kubernetes CronJob runs a
pass every minute when its selected Server image provides the reclamation
command. The Kubernetes runner capability-checks the command so a separately
qualified older onboarding image remains compatible. Each pass reclaims at most
100 expired references (operators may request up to the hard 1,000-reference
ceiling), so cleanup work is bounded and sustained backlog drains over repeated
passes. With no blocked storage operation, an expired reference at position *p*
in the oldest-first backlog is attempted within `ceil(p / batch-size)`
maintenance intervals. Cleanup locks the stable backing-object identity, then
re-checks expiry and retained ownership before it deletes bytes. References in
another namespace keep shared content-addressed bytes alive.

Storage locations are registered in a recoverable `writing` state before the
provider write begins. A successful write promotes the row to `ready`; a crash
or failed final registration leaves the location discoverable and eligible for
the same expiry cleanup instead of creating an untracked object.

Operators can inspect namespace-scoped backlog, cumulative deleted counts,
blocked outcomes, and storage-driver failures through
`GET /api/system/external-payload-cleanup` or the
`runtime_external_payload_cleanup` section of operator metrics. A blocked pass
can be retried with `POST /api/system/external-payload-cleanup/pass` or the
Artisan command. These diagnostics contain aggregate counts only; they do not
include provider credentials, provider locations, or reusable reference IDs.

The runtime records upload, fetch, claim, and rejection audit events without
logging provider locations, object-store credentials, bearer tokens, or the
reusable opaque reference identity. Audit correlation uses a one-way reference
identity digest.

## Typed outcomes and retryability

The transport returns a stable
`durable-workflow.v2.runtime-external-payload-error.v1` envelope. Outcomes are:

- `external_payload_not_found` (404, non-retryable)
- `external_payload_expired` (410, non-retryable)
- `external_payload_unauthorized` (401 or 403, non-retryable)
- `external_payload_unavailable` (503, retryable)
- `external_payload_oversized` (413, non-retryable)
- `external_payload_unsupported` (415 or 422, non-retryable)
- `external_payload_integrity_mismatch` (422, non-retryable)
- `external_payload_namespace_bytes_exhausted` (429, retryable)
- `external_payload_namespace_objects_exhausted` (429, retryable)
- `external_payload_namespace_quota_unavailable` (503, retryable)

Namespace quota rejections include `Retry-After` and
`retry_after_seconds`. Cumulative usage includes both in-progress and ready
objects. Duplicate registration of the same stable object identity does not
consume the byte or object budget again.

Reference validation and fetch happen before a workflow/activity completion or
control-plane mutation reaches its state transaction. A rejected reference
therefore records no partial command and cannot duplicate the intended side
effect. Authentication failures use the external-payload unauthorized outcome;
a valid credential querying another namespace receives the non-disclosing
not-found outcome.

Direct provider adapters may be implemented as an explicitly negotiated
self-hosted optimization. They are disabled by default, never required by the
standalone Server default or managed Cloud, and do not change the runtime wire
reference.
