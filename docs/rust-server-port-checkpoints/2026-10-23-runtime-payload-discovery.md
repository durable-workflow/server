# October 23, runtime external payload discovery

Before a request that carries a payload, the PHP, Python and Rust SDKs read
`GET /api/cluster/info`. Three parts of the answer decide what they do:

- the namespace's storage policy, `namespace.external_payload_storage`;
- the transport manifest under it;
- the ordinary request limit, `limits.max_payload_bytes`.

Native served the upload and fetch routes, but its cluster info carried none
of these. SDKs therefore treated native as having no external storage. A
payload over the namespace threshold went inline, and one over the request
limit failed in the SDK. The installed-consumer profile uploaded explicitly,
so it never exercised this path. Its notes said discovery "stays
unadvertised".

## What native now answers

- **`namespace`**, for the namespace named by `X-Namespace` (else the
  `namespace` query selector, else `default`), as PHP's
  `HealthController::namespacePolicy` builds it:
  - `name`, `exists`, `status`, `retention_mode` and `retention_days`;
  - `external_payload_storage`, with `configured`, `enabled`, `status`,
    `configuration_error` and `threshold_bytes`.

  A namespace that does not exist still answers 200, with `exists: false`, as
  in PHP. A disabled policy reports its own threshold. A missing or
  unconfigured one reports the request limit.
- **`limits`**, PHP's eight values, from the settings native enforces.
  `max_pending_activities` and `max_pending_children` are reported as PHP
  reports them; neither server enforces them.
- **`server_id`** (`DW_SERVER_ID`, else the host name) and
  **`default_namespace`**.
- **`runtime_external_payload_transport`**, the same transport manifest, in
  the worker `server_capabilities`. Like PHP, native puts it on every worker
  response.

The config audit now lists `DW_SERVER_ID`, `DW_MAX_PENDING_ACTIVITIES` and
`DW_MAX_PENDING_CHILDREN` as honored, since cluster info reports them.

## Takeover of a policy native cannot serve

Native stores and serves only the `local` driver. A PHP namespace with an
enabled `s3`, `gcs`, `azure`, `custom` or unknown driver used to keep its
policy through a takeover. Native then reported it as `driver_unavailable`
and refused every upload in it with 503, where PHP may have served them.

Inspection now reports `storage_inspection`, which counts such namespaces by
reason without naming them. Takeover refuses them with
`upgrade_source_external_payload_storage_unsupported` before any change, as
it refuses schedules native cannot run. A disabled policy, the `local` driver
and an unconfigured namespace do not block it. The inspection and refusal
tests use the frozen 2.9.7 layout and check that the source bytes are
unchanged.

## Where native's manifest differs, and why

Each manifest claim was checked against what native serves:

- **Typed outcomes.** Native returns all 14.
- **Upload response cache header.** `no-store, private`, as in PHP.
- **Fetch cache directives.** The same three as PHP, in a different order.
- **Roles and limits.** Native enforces the same roles, and the limits come
  from the same `DW_EXTERNAL_PAYLOAD_*` settings.
- **Completion budgets.** The same per-lease byte budget and 128 slots.

Three members differ, each a feature native does not serve:

| Member | Published 2.11.1 | Native | Reason |
| --- | --- | --- | --- |
| `upload.completion_context.prepared_schema` | `…payload-completion-context.v2` | `null` | No prepared local activities |
| `upload.completion_context.scope_schema` | `…payload-completion-context.v2` | `null` | No cancellation-scope checkpoints |
| `audit_events` | four `external_payload.*` events | `[]` | Native writes no audit log events yet |

SDKs send a completion context only when retrying an upload that was refused
for storage pressure with `draining`. Native never gives that refusal, so no
SDK sends native a context. The version-1 block describes the activity-result
context native parses.

## Evidence

- **Fixture first.** The reviewed `cluster-info` fixture
  (`tests/Fixtures/ServerParity/cluster-info.json`, probe and contract in
  `scripts/conformance/server-parity/cluster-info-*`) creates three fresh
  namespaces: an available `local` policy (threshold 16), none, and a
  disabled one (threshold 32). It then reads cluster info for those three and
  for a missing namespace.
  - The expected values were taken from published 2.11.1's live answers.
  - PHP must return its manifest exactly. Native must return it with the
    three reviewed differences, each pinned to a published member.
  - 20 contract unit tests pass, and 16 corruptions are refused, among them
    native claiming audit events or the v2 prepared context, and PHP dropping
    its audit events.
- **Live, local.** Each server was captured with `X-Namespace` reads on SQLite
  and checked by the same contract.
  - Published 2.11.1's answers pass.
  - Native before the change answered with no `namespace`, `limits` or
    capability.
  - Native after passes.
- **Installed SDK.** The external-consumer profile gains a seventh original,
  `external-discovery-client.php`. It runs the profile's locked PHP SDK 2.3.9:
  one public `startWorkflow` with an input over the 16-byte threshold.
  - Against published 2.11.1, the profile's PHP 2.6.2 and native, the SDK made
    the same three requests: cluster info, one upload, and a start whose input
    is `{codec, external_payload}`.
  - Each server stored exactly one object, and the four discovery reads match.
  - The checker refuses an inline start, a missing upload, a disabled policy
    reported available, and a missing capability.
- **Native test.** `cluster_info_discovers_runtime_payload_storage_like_php`
  covers the four namespaces, the SDK's manifest checks, the three reviewed
  differences, and the capability on a registration response.

## Still open

- **The rest of cluster info.** `capabilities`, `structural_limits`,
  `supported_sdk_versions`, `client_compatibility`, `discovery`, `topology`
  and the conformance manifests are still absent. No SDK changes behavior on
  them. The CLI treats an absent `client_compatibility` as compatible.
  Waterline reads `capabilities.workflow_activity_result_inspection`, which
  stays held until that read is qualified. `version` is still `0.0.0`.
- **Audit events.** Native writes none. Structured logging belongs to the ops
  gate.
- **Worker capabilities native does not advertise.** `update_validation_tasks`
  and `synchronous_update_validation`: the Python SDK refuses to start a
  worker whose workflows declare update validators. Also `query_task_timeouts`,
  so the CLI's query timeout falls back to 35 s, where PHP sets 45 s.
