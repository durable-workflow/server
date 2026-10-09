# Rust Server storage and upgrade audit

This is a source-audit checkpoint for [#325](https://github.com/durable-workflow/server/issues/325),
not a qualified migration procedure. Rust takeover and stored-value conversion
remain unimplemented. The PHP ownership fence is implemented in source by
[#331](https://github.com/durable-workflow/server/pull/331); the frozen published
reference still lacks that fence. The reference is published Server `2.5.13`, source
`f061d64277f09b11dd9a0844b958bfab6c8e998e`, with Workflow `2.5.4`, source
`c39025af47e0307103e48d98ead71067ff243ef6`. The exact image and consumer tuple
is in [the reference manifest](../tests/Fixtures/ServerParity/php-baseline.json).

## Stored representation checkpoints

| Representation | Source evidence | Required proof before takeover |
| --- | --- | --- |
| Durable IDs, statuses, relationships, command sequences, task leases, timer deadlines and routing | Server and Workflow SQL migrations; Workflow v2 models and task executors | Read existing tables directly on SQLite, MariaDB/MySQL and PostgreSQL. Preserve acknowledged commands, original history, identities and deadline precision. Test actual pending execution and stale-attempt rejection after takeover. |
| Inline Avro values and portable memo envelopes | Workflow [`Avro.php`](https://github.com/durable-workflow/workflow/blob/c39025af47e0307103e48d98ead71067ff243ef6/src/Serializers/Avro.php), [`MemoPayload.php`](https://github.com/durable-workflow/workflow/blob/c39025af47e0307103e48d98ead71067ff243ef6/src/V2/Support/MemoPayload.php); [memo conversion](../app/Support/WorkflowMemoPayloadMigration.php) | Preserve the fixed Value schema, strict base64, single-object framing and existing payload bytes. Qualify int64, bytes, empty map/array distinction and malformed/trailing input using official Avro tooling. Do not assume JSON projections preserve the full logical value. |
| External payload references, bytes, registry rows, backup holds and reclamation state | [External payload contract](contracts/external-payload-storage.md); Workflow [`ExternalPayloadReference.php`](https://github.com/durable-workflow/workflow/blob/c39025af47e0307103e48d98ead71067ff243ef6/src/V2/Support/ExternalPayloadReference.php); Server registry and object-lock code | Back up both SQL state and external bytes. Preserve URI, codec, size and SHA-256, prove restored reads, and keep reclamation held through migration. A SQL-only backup is insufficient. |
| Runtime credentials | [`RuntimeCredential`](../app/Models/RuntimeCredential.php) stores SHA-256 token digests plus roles, tenant, claims, expiry and revocation | Existing credentials must retain their authority and namespace scope without token rotation. Test worker/operator authorization, expiry, revocation and denial; a copied digest alone is insufficient. |
| Observation-history page tokens | [`RunObservationHistoryToken`](../app/Support/RunObservationHistoryToken.php) encrypts JSON through Laravel `Crypt` | Test a token issued by PHP before takeover against Rust, including run binding, range checks and tampering. Preserve the required application-key material and qualify the encryption format; these tokens are not plain base64 JSON. |
| Ordinary history page tokens | [`HistoryPageToken`](../app/Support/HistoryPageToken.php) and [`WorkflowHistoryPageToken`](../app/Support/WorkflowHistoryPageToken.php) encode a decimal sequence as base64 | Qualify these separately from encrypted observation cursors. Preserve strict decoding and the published invalid-token behavior; one cursor decoder does not cover every history endpoint. |
| Poll/lease bindings and attempt fencing | [`PollRequestLeaseBinding`](../app/Support/PollRequestLeaseBinding.php) stores `_server_poll_request_id` inside the task payload, atomically with the lease; pollers use the package's authoritative attempt count | Preserve payload fields, registration/session fences, lease owner, attempt counters and expiry. Test an existing live claim, expired claim, retried poll and stale completion across takeover rather than inferring fencing from a copied task ID. |
| Laravel queued jobs | Laravel queue envelopes carry PHP-serialized command objects; [`ServiceModeBusDispatcher`](../app/Support/ServiceModeBusDispatcher.php) suppresses local workflow/activity dispatch in service mode, while [`ServiceModeTimerDispatcher`](../app/Support/ServiceModeTimerDispatcher.php) still queues timer wakeups | Inventory database and configured queue transport. Map recognized infrastructure wakeups to their authoritative durable task rows and prove recovery at the original deadline. Never clear queued data merely because Rust cannot deserialize it. Unknown jobs require an explicit safe refusal or a qualified conversion. |
| Internal async closures inside Avro bytes | Workflow [`InternalAsyncClosurePayload`](https://github.com/durable-workflow/workflow/blob/c39025af47e0307103e48d98ead71067ff243ef6/src/V2/Support/InternalAsyncClosurePayload.php) wraps a PHP-serialized `SerializableClosure` with a format tag and HMAC-SHA-256 using the application key; [`AsyncWorkflow`](https://github.com/durable-workflow/workflow/blob/c39025af47e0307103e48d98ead71067ff243ef6/src/V2/AsyncWorkflow.php) executes it in PHP | An Avro bytes branch does not make the enclosed closure executable in Rust. Determine whether each value is only transported or needs execution, and test the corresponding compatibility path. Preserve opaque bytes when appropriate; unsupported pending execution must fail preflight before mutation, without dropping acknowledged work. |
| Legacy v1 serialized values and projected history | Workflow [`AbstractSerializer`](https://github.com/durable-workflow/workflow/blob/c39025af47e0307103e48d98ead71067ff243ef6/src/Serializers/AbstractSerializer.php); [`LegacyV1Projection`](../app/Support/LegacyV1Projection.php) explicitly marks `php-serialize` values opaque | Preserve the existing opaque projection contract. Do not reinterpret imported legacy history as an executable portable workflow or silently replace values with JSON. Qualify any source-side conversion separately. |
| PHP application classes and local queue mode | [`config/server.php`](../config/server.php) documents `DW_MODE=embedded` local class execution; Workflow [`RuntimeObjectFactory`](https://github.com/durable-workflow/workflow/blob/c39025af47e0307103e48d98ead71067ff243ef6/src/V2/Support/RuntimeObjectFactory.php) uses Laravel constructor injection | Inventory local workflow/activity classes and extension configuration. A service-mode HTTP parity pass does not qualify this mode. Keep the embedded Laravel engine first class and provide a tested compatibility/migration path before declaring the Rust Server a replacement for such deployments. |
| PHP authentication providers and framework configuration | Server auth-composition contract, `ConfiguredAuthProvider`, `DW_*` configuration contract and Laravel bootstrap | Define and test the extension migration path. Preserve the public configuration names, aliases and behavior; do not silently ignore a configured provider or report readiness before verifying it. |

The schema inventory and these representation checks are separate obligations.
Matching table names or accepting an Avro envelope does not prove that pending
work can resume. Database-specific column types, collations, timestamps, JSON,
transaction isolation, locking and migration history still need a complete
mapping and executable tests.

## Backup-first, sequential upgrade gates

1. Run a read-only preflight against a consistent PHP reference installation.
   Inventory all configured write-capable roles, queue transports, stored
   representations, extension classes and external payload stores. Unsupported
   cases must identify the affected durable records and a concrete remediation.
2. Establish a verified consistent database backup and external-payload backup
   hold using the [existing safe procedure](self-hosted-backup-and-restore.md).
   Test restoration into an isolated installation before authorizing mutation.
3. Stop PHP HTTP, bootstrap/migration, queue worker, scheduler and other
   write-capable roles. Ordinary PHP/Rust/embedded development databases remain
   independent; only the dedicated upgrade test reuses a database sequentially.
4. Apply Rust's versioned migrations with a durable ownership/schema marker.
   The prerequisite PHP guard must refuse an upgraded database before startup,
   bootstrap, migration, scheduling or worker mutation. A readiness warning is
   not sufficient protection.
5. Interrupt at real migration boundaries on every supported database family.
   Resume idempotently or refuse safely; do not infer crash safety from a final
   successful migration alone.
6. Resume the original pending workflows, timers, activities and leases under
   Rust. Verify original history, deadlines, external bytes, credentials,
   acknowledged commands and stale-attempt fencing. Exercise real node kills.
7. Verify PHP refusal after the marker and document the one-way cutover.
   There is no mixed PHP/Rust fleet or PHP rollback after upgrade. Restoring a
   pre-upgrade backup is a separate recovery action with its own data boundary.

All gates above remain open. HTTP export/import remains a supported deployment
transfer surface, but it does not substitute for in-place upgrade qualification.
