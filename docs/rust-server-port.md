# Rust Server port

[Server #325](https://github.com/durable-workflow/server/issues/325) owns the
rewrite and its acceptance evidence. PHP remains the default published runtime.
The unpublished Rust crate has an Avro foundation and an opt-in SQLite/PostgreSQL HTTP
execution slice. Full runtime parity, migration, performance improvement and
cutover remain unqualified.

## Work order and current status

The next slice brings actual MariaDB/MySQL execution to the same state machine
and differential fixtures. Its DDL cannot use PostgreSQL's atomic bootstrap:
native initialization needs an explicit ownership intent, version/checksum
validation and tested safe interruption handling. PHP and unknown databases
remain refused before mutation until backup-first takeover is qualified.

The current execution slice shares the native workflow/activity state machine
between SQLite and PostgreSQL, with typed database adapters and explicit
transaction locking. Qualification runs the unchanged execution fixtures
and real lease interruption on PostgreSQL, with PHP and embedded targets in
independent databases. Existing PHP data remains refused until backup-first
takeover is implemented and qualified.

The PostgreSQL storage foundation prepares the real timestamp/JSON schema,
transactional migrations and ownership checks against an independently
bootstrapped published PHP database. Fresh native bootstrap keeps the full
existing physical schema; it does not authorize adoption of PHP data. Actual
MariaDB/MySQL execution and backup-first database takeover remain required next steps.

1. Inventory the current contract, schema and operational surfaces. Establish
   shared reviewed fixtures and a recorder usable against any isolated Server
   URL and a real embedded Laravel engine. Freeze published PHP/consumer artifacts
   and measure PHP before implementing Rust.
2. Add a Rust runtime in this repository through small vertical slices. Run the
   differential fixtures for each change and settle disagreements against the
   published contract before changing either implementation.
3. Complete the capability inventory, published SDK and consumer directions,
   three-database conformance, multi-node interruption and performance gates.
4. Qualify backup-first sequential takeover, interrupted migrations, PHP refusal
   of upgraded databases, operator procedures and the major-release decision.
5. Publish exact artifacts through the existing release process and verify
   downstream consumers. Cloud adoption belongs to its separate owner.

The first slice covers only Avro echo (including an exact large int64) and one external activity, completed by
the published PHP SDK and the embedded engine. Its fixtures require exact event
order, stable public workflow identity, retained run/operation relationships,
decoded input/result values and one committed completion. Passing these cases
does not prove the remaining acceptance criteria.

## Contract and verification inventory

| Surface | Current authority and reusable checks | Port status |
| --- | --- | --- |
| Control plane, namespaces, authorization, errors, visibility | `resources/platform-protocol-specs/control-plane-api.openapi.yaml`, `docs/contracts/auth-composition.md`, `routes/api.php`, Feature control-plane/auth/namespace tests; namespace and principal-attribution published runners | Pending |
| Worker registration, sessions, leases, fencing, retries, timeouts, heartbeats, routing, affinity, backpressure, versioning | Worker OpenAPI and stream AsyncAPI in `resources/platform-protocol-specs/`; Feature worker/activity/prepared-local/cancellation tests; activity, heartbeat and worker-versioning published runners | Pending |
| Avro values, external task inputs/results, payload storage and reclamation | `docs/contracts/external-task-{input,result}.md`, `external-payload-storage.md`, external payload OpenAPI; `regression-corpus-policy.json`, `tests/Fixtures/CodecRegression/`, payload Feature tests | PHP/embedded echo slice; Rust codec foundation in #329, full ingress and external payloads pending |
| Workflow lifecycle, duplicate commands, typed history, continuation, child workflows, cancellation, cleanup replay | Workflow lifecycle, migration, child-workflow and replay runners; Feature cooperative cancellation, history, migration and repeated-signal tests | Echo/activity slice only |
| Timers, schedules, signals, queries, updates, search attributes, memo, sagas | Corresponding runners in `scripts/conformance/`, manifests in `static/platform-conformance/`; Sample App polyglot experiments | Pending |
| Local activities, cancellation scopes, worker sessions, streams, service catalog/Nexus, bridge adapters, standalone activities, debugging and repair | `routes/api.php`, `docs/contracts/`, worker/control-plane specifications, corresponding Feature tests and Nexus runner | Pending; not omitted from parity |
| Actual PHP/Python/Rust directions and existing CLI/Waterline/Sample App | Organization [conformance runbook](https://github.com/durable-workflow/.github/blob/main/conformance/README.md) and its SDK coverage inventory; Sample App activity, child, timer, saga, update, namespace and search experiments | PHP echo/activity only |
| SQL schema and stored representations | `database/migrations/`, Workflow package `src/migrations/`, model casts, `Workflow\\Serializers\\Serializer`, queue job payloads, exported history and credential digests | [Representation checkpoints](rust-server-storage-audit.md) recorded; complete mapping and executable migration tests pending |
| Images, architecture, bootstrap, configuration, readiness, metrics and graceful shutdown | `Dockerfile`, `docker/`, `config/`, `docker-compose*.yml`, `k8s/helm/`, `docs/server-reference.md`, small-cluster/multi-region validation docs | Pending |
| Backup and in-place upgrade | `docs/self-hosted-backup-and-restore.md`, external payload backup holds; last PHP image writes, all PHP roles stop, Rust takes over same DB sequentially | Pending on SQLite, MariaDB/MySQL, PostgreSQL |
| Throughput, memory, CPU, latency, backlog/drain, idle mixed long polls | `benchmarks/capacity/v1/` and `scripts/benchmark/`; repeated runs and drift checks using frozen artifacts | [Diagnostic PHP reference recorded](../benchmarks/server-port/v1/php-observations-2026-10-09.md); matched comparison and full qualification pending |

Ordinary PHP, Rust and embedded runs use independent databases. Never connect
different engines to one database concurrently. SQLite multi-node qualification
must state its shared-file topology and locking limits; existing single-node
support is not evidence of multi-node safety.

## Decisions

- Shared fixtures are expectations reviewed against public contracts, not PHP
  recordings promoted to expected behavior. The recorder keeps raw observations;
  the comparator checks the declared fixture projection. Coverage is explicit.
- The HTTP adapter uses the published PHP SDK and its official Avro codec. The
  embedded adapter executes real Workflow jobs and persisted history. Neither
  counts an accepted start as completion or substitutes mocked execution.
- Generated run/activity/attempt identifiers may differ between independent
  databases. Compare their relationships with bijective aliases; retain actual
  IDs in raw evidence. Preserve workflow IDs, event order, command sequences,
  attempt counts and decoded values. Wall-clock observations remain raw.
- The first Rust foundation uses Apache's official Avro single-object
  reader/writer with the immutable schema. The first HTTP/storage slice uses
  Axum/Tokio and SQLx with selected features and native transactions. Avoid
  unnecessary dependencies and code generation. Record
  clean and incremental compile time plus target-directory size for the first
  runtime slice. Use one task-owned Cargo target directory, low debug information
  for development, bounded build concurrency and cleanup after qualification.
- Evaluate [Kache](https://github.com/kunobi-ninja/kache) in a task-local runtime
  before choosing the Rust build setup. Use an explicit cache budget and local
  storage; measure physical disk blocks across cache and targets together. Keep
  cache and build outputs under one container mount so cross-mount copying does
  not erase sharing. Use a per-run compiler wrapper and scoped cleanup.
  The [first real crate measurements](../rust/build-observations-2026-10-09.md)
  observed native clean builds around 22 seconds and 182 MiB targets. Kache
  restored a fresh target in 3.5 seconds but added cold-build time and retained
  disk; native no-op/comment-edit builds were faster in-place. Keep it optional.
  Full-server build cost and any disk-saving claim remain pending.
- Tokio tasks are [cooperatively scheduled](https://docs.rs/tokio/latest/tokio/task/coop/index.html).
  Potentially expensive replay, decoding and synchronous database operations
  need bounded work away from the async request executor. `spawn_blocking` needs
  an explicit CPU concurrency limit, and a started blocking job cannot be
  aborted. Preserve durable fencing and cancellation checks around that work;
  qualify timer, lease and cancellation deadlines under CPU-heavy load.

## Evidence and next action

The initial source and published artifact tuple is in
[`../tests/Fixtures/ServerParity/php-baseline.json`](../tests/Fixtures/ServerParity/php-baseline.json).
It freezes inputs, not a passing performance result. Fixture and runner commits,
hardware, resource limits and actual measurement commands belong in each retained
result. Do not compare performance across concurrent unrelated host workloads.

The first HTTP and embedded recordings passed on the frozen amd64 image and
Workflow package, with an independent SQLite database for each. Hosted
[fixture execution](https://github.com/durable-workflow/server/actions/runs/37889894631)
retains both raw records and the comparison for 90 days. It executes both
workflows to completion, including the activity, with the published PHP SDK.
[PR #326](https://github.com/durable-workflow/server/pull/326) owns source checks
and review. The linked final run covers all three cases, including the large
int64 and explicit decoded type trees.
This is bounded correctness evidence, not performance qualification
or a three-database/three-runtime pass.

The fixture foundation is merged in #326. All three cases and 25 comparator
checks passed at its exact head, together with the full PHP feature suite.

The [development reference profile](../benchmarks/server-port/v1/README.md)
pins the current PHP/SDK tuple and uses existing standard-workflow and mixed
idle-poll commands. Its preparation is merged in [#327](https://github.com/durable-workflow/server/pull/327).
The [diagnostic observations](../benchmarks/server-port/v1/php-observations-2026-10-09.md)
are complete: three repetitions at 0.25 and 0.5 offered starts/s completed all
workflows in-window; at 1/s, only 48–51 of 120 completed in-window before the
remainder drained. The high-rate retry had one readiness 503, and the original
second run's configured hourly worker exit invalidated its resource comparison.
All outcomes and failures are retained. Following drift windows passed; sampling
gaps, missing complete client resource costs and changed background load still
prevent a PHP/Rust improvement claim. The development host's kernel,
runtime and SATA storage differ from the standard capacity topology, so that
profile preserves the full capacity gate and makes no maximum-capacity claim.

Mixed idle observations completed three repetitions each at 6, 12 and 24 polls.
Six polls returned six empty outcomes; twelve returned eight empty outcomes and
four explicit capacity rejections; twenty-four returned eight empty outcomes and
sixteen rejections. All registrations were removed, with no claimed tasks,
runtime restart or OOM. Query waits were clamped to about five seconds, while
workflow/activity waits lasted about ten seconds. Health/readiness probes passed
throughout the six- and twelve-poll runs. All three twenty-four-poll registration
bursts caused at least one two-second API probe timeout, with an Apache
`MaxRequestWorkers` warning observed. These failures remain part of the reference
evidence; API headroom at that burst size is not qualified. Shared-host background
work changed between measurements, so a PHP/Rust performance gain still requires
matched conditions and drift controls.

The unpublished [Rust foundation](../rust/README.md) in
[#329](https://github.com/durable-workflow/server/pull/329) implements the Value
adapter and complete-frame check, retaining exact int64, bytes/text, array/map
and finite-double distinctions. Its first tests exposed Apache's streaming EOF
null sentinel; the frame adapter now rejects incomplete datums. Fifteen reviewed
logical values passed local Rust/PHP cross-decoding in both directions, including
the existing golden long-zero wire. These are codec checks, not execution of a
durable workflow on Rust. Malformed collection blocks, duplicate keys, resource
limits and external payload ingress remain explicit gates before HTTP integration.

[#330](https://github.com/durable-workflow/server/pull/330) adds actual HTTP
execution: published SDK workers lease tasks, commit activity outcomes, replay
persisted history and complete workflows against an independent native SQLite
database. The unchanged three fixtures first passed locally against all three
targets, including exact large-int64 and activity relationships. Transactional
tests cover persistence, independent connection-pool claims, stale fences,
duplicate completion/poll receipts, pagination and unsupported-batch refusal.
An actual SIGKILL with an outstanding activity passed locally through
the normal SDK loop after restart: the original run/history survived, the old
expired claim was refused, and attempt two committed one outcome. These checks establish the first bounded
slice, not full protocol, multi-node, timeout/retry or database parity.

HTTP passes opaque Avro envelopes without invoking the incomplete decoder.
Discovery/readiness explicitly identify development status, and missing
commands/effects are refused. A read-only preflight refuses existing PHP data;
native fresh-database bootstrap does not substitute for sequential backup-first
takeover. Published PHP still has no startup refusal guard, so PHP must never
connect to this development database. MySQL/PostgreSQL, scoped auth/namespaces, external payloads,
timers/retries/cancellation, complete capability/consumer matrices and performance
remain required.

The [native build observations](../rust/runtime-build-observations-2026-10-09.md)
record a clean tests/binary build at 54.65 seconds and 521.4 MiB of targets;
downloads add 202.6 MiB. Clippy and incremental outputs bring the observed
combined state to about 868 MiB. Build-only no-op/comment edits took 0.159/1.513
seconds. These bounded measurements exclude downloads/startup and make no
full-server/cache-saving claim.

#330 is merged. Its exact head passed the three-runtime comparison, seven
SQLite transaction/HTTP tests, four codec tests, two-way codec values and an
actual SIGKILL/replacement-container recovery check in the shared Action. The
complete PHP feature/source suite and repository/boundary checks also passed.

[The PHP fence in #331](https://github.com/durable-workflow/server/pull/331) establishes PHP refusal before a Rust-marked database can be
mutated. The reserved, unprefixed `dw_server_schema` relation is the one-way
ownership fence: its existence refuses PHP even if its rows are empty, malformed
or from an unknown future version. Removing the marker is not a rollback path.
Stop every PHP write-capable role before Rust takeover; a connection preflight
does not make a concurrently mixed fleet safe.

The fence guards Laravel's official SQLite, MySQL/MariaDB and
PostgreSQL connectors before connection setup can execute durable writes. A
SQLite file must first be opened read-only, including visible WAL state; an
immutable snapshot is insufficient. Probe failures must not be mistaken for
marker absence. The normal container entrypoint checks ownership before
starting its role, while connector checks protect direct bootstrap, migration,
worker and scheduler paths and reconnects. Unmarked PHP databases must remain
usable, and unavailable-backend diagnostics must remain truthful.

Acceptance uses real PHP processes and physical database state: refuse startup
and write-capable commands against marked SQLite/MySQL/PostgreSQL databases,
preserve sentinel data and schema, reject empty/future markers, observe a marker
still in SQLite's WAL, and continue ordinary bootstrap against unmarked data.
This protection does not claim backup-first conversion, interrupted migration,
full-schema compatibility or a qualified takeover.

The process fixtures cover physical startup/bootstrap/migration/wipe/worker/
scheduler refusal, malformed/future markers, reconnects, unmarked bootstrap,
URI/WAL visibility, nested named-connection wipes, deferred initialization SQL,
hidden-marker permissions and silent PDO modes. MySQL and PostgreSQL are exercised
in the feature Action. The additional local MariaDB run uses an actual MariaDB
11.4.13 deployment. Existing bootstrap, SQLite lock-pressure, unavailable-backend
and growth-inventory contracts also run; exact-head evidence is linked from #331.

The real SIGKILL fixture initially exposed a recovery regression: SQLite must
repair a hot rollback journal before reading schema. The fence now recovers a
private copy with SQLite, checks committed ownership and unchanged input, and
only then allows ordinary PHP recovery. Fault fixtures verify acknowledged PHP
data, a marker restored from an uncommitted drop without changing the original,
concurrent startup, an interrupted probe, a replaced database, actual low-space
refusal and protection of external super-journal filenames. Root startup and
Apache's runtime UID have separate private copies. The
[operator guide](php-database-ownership.md) records temporary disk requirements,
cleanup and the explicit reserved-name grant needed by table-only MySQL/MariaDB
roles. This does not claim migration or production takeover qualification.
Cache-only restart and scheduler-cache maintenance remain usable during a
database outage; early command preflight targets write-capable roles rather than
every command sharing their prefix.

The next foundation aligns fresh native SQLite databases with the complete
schema created by the frozen published PHP image: 49 tables and 333 explicit
indexes, including credentials, payload holds, timers, cancellation and legacy
infrastructure. SQLx records and validates native migration checksums. Worker
registration uses the existing `workflow_worker_registrations` relation;
execution timeout stays on the workflow instance. Native completion/poll receipts
use separate relations rather than replacing PHP-owned columns.

This is a fresh-database development bootstrap, not permission to adopt PHP
data. PHP databases and the earlier abbreviated native development schema remain
refused before writable connection setup. No observable protocol capability is
added. Qualification must compare the actual published PHP catalog with an
independent native database, preserve the existing execution/restart fixtures,
and test interrupted/concurrent bootstrap and migration-history mismatches.
The local implementation passes the existing seven execution tests, four codec
tests and five new schema cases. The latter check three concurrent fresh nodes,
real SIGKILLs after migration-ledger creation and before the outer commit,
read-only refusal of old development data and nine catalog/history corruptions.
Restart after either acknowledged interruption creates one complete migration
and passes SQLite integrity checking. The actual published/native catalogs
match. The [shared hosted run](https://github.com/durable-workflow/server/actions/runs/37929268377)
passes the unchanged three-runtime fixtures, two-way codec values, native
schema interruption and actual activity-lease kill/restart. Its catalog check
retains the original database and existing journal hashes. An earlier inspection
mount prevented SQLite's temporary WAL metadata access; the corrected fixture
uses `mode=ro` on stopped engines and a writable disposable metadata directory,
without an immutable snapshot that could conceal WAL state.
The final fixture additionally prepares pending work in the published PHP
database and requires native refusal with unchanged database/journal hashes.
Its final-head evidence belongs to [#332](https://github.com/durable-workflow/server/pull/332).
SQLite's [read-only WAL contract](https://sqlite.org/wal.html#read_only_databases)
permits creating shared-memory metadata and an empty WAL when those files are
absent. The refusal fixture exposed this behavior on the real published PHP
database. Native startup now awaits probe closure on success and refusal. The
receipt treats an absent and exactly zero-byte WAL as equivalent; any nonempty
WAL, rollback journal and database must retain its exact hash. Unit cases cover
both a closed WAL database and committed work still present in a nonempty WAL.
The final local suite passes 16 cases plus the two invoked fault subprocesses;
formatting, warning-free Clippy and the 72 Docker-isolation policy cases pass.
The [build observations](../rust/schema-build-observations-2026-10-09.md)
record 64.445 seconds for a clean tests/binary build, 541.9 MiB of targets plus
177.6 MiB of downloads, and 0.137/0.823-second no-op/comment builds. These are
bounded development measurements, not a full-server or improvement claim.

[The PostgreSQL foundation in #333](https://github.com/durable-workflow/server/pull/333)
adds native schema bootstrap and read-only inspection commands. Its migration
uses the actual frozen PHP PostgreSQL schema: 49 tables, 382 indexes including
primary/unique constraints, 92 constraints and 17 owned sequences. Unlike
SQLite, timestamps retain six-digit precision and payload metadata uses native
JSON columns. Native receipts and SQLx history occupy separate relations.
Startup fixes the development schema to `public`/UTC and refuses PHP data in
any user schema, corrupt catalogs or unknown history before writable access.
A transaction-scoped advisory lock serializes initialization; the outer
transaction commits DDL, ledger and ownership together. PostgreSQL execution
is not enabled by these commands.

The real local PostgreSQL 17 checks pass six backend cases: concurrent fresh
nodes, exact typed JSON/int64/microsecond values, SELECT-only inspection,
existing/custom-schema data refusal, thirteen corrupted catalog/history states,
and SIGKILLs after ledger creation and before commit. A restarted initializer
sees no partial schema and creates one valid migration. The actual published
PHP database also contains a leased activity; after stopping PHP, native
bootstrap refuses it and all row/sequence fingerprints remain unchanged.
TLS uses SQLx/Rustls, with a trusted CA and hostname verification; actual
untrusted certificates and wrong hostnames are refused. Hosted qualification
targets the pinned PostgreSQL 16 and 17 images, alongside the unchanged
PHP/Rust/embedded SQLite fixtures and real activity restart.

The first catalog-formatting helper rounded an int64 sequence maximum. Capture
now uses PHP's exact 64-bit values. A PostgreSQL UNION also inferred the fixed
`name` type and truncated composed catalog keys; the query explicitly selects
`text`, and a regression case verifies distinct long constraint identities and
the exact sequence maximum. These corrections strengthen the schema checks;
they do not change the reviewed execution fixtures.

The [PostgreSQL build observations](../rust/postgres-build-observations-2026-10-09.md)
record 86.755 seconds for clean default tests/binary, 747.1 MiB of targets plus
306.3 MiB of Cargo state, and 0.147/1.614-second no-op/comment builds. Additional
checking/incremental outputs reach 921.3 MiB of targets. Inputs and exclusions
are explicit; no full-server or causal performance gain is claimed.

[PostgreSQL execution in #334](https://github.com/durable-workflow/server/pull/334)
uses one typed execution state machine for both native backends. JSONB,
microsecond timestamps and PHP's integer widths retain their physical types.
Decode failures return bounded errors rather than panicking. SQLite keeps its
existing native text layout. SQLite history limits now count UTF-8 bytes rather
than characters; PostgreSQL bounds its JSON serialization before hydration.
No migration or schema version change is needed for this execution slice.

The common HTTP cases pass on both backends, including concurrent starts and
completions, independent claims, revoked/expired fences, exact large integers,
persistent receipts, poll retries, pagination, atomic unsupported-command
refusal, timestamp precision and oversized Unicode history. PostgreSQL uses
a transaction-scoped advisory lock for this first default-namespace slice,
separate from initialization. Empty polls release the transaction before waiting;
serialization and full CPU/throughput/idle cost remain unqualified.

The [shared hosted run](https://github.com/durable-workflow/server/actions/runs/37943502489)
passes on the pinned PostgreSQL 16 and 17 images. Each executes the unchanged
echo, int64 and activity fixtures against independent published PHP, Rust and
embedded databases. Two native processes share only their own database. The
probe prepares a leased activity, SIGKILLs one process, verifies survivor
readiness, starts a replacement, lets the persisted lease expire and finishes
through the published PHP SDK. It retains original run/history relationships,
rejects the old claim and records one activity/workflow outcome. This bounded
case does not establish all failure boundaries, routing or full multi-node HA.
The existing schema, interruption, read-only PHP refusal, TLS and SQLite/codec
checks remain in the Action. Existing PHP data is still refused, not converted.

The [execution build observations](../rust/postgres-execution-build-observations-2026-10-09.md)
record 83.054 seconds for clean default tests/binary, 787.0 MiB of targets plus
306.4 MiB of Cargo state, and 0.145/1.911-second no-op/comment builds. Retained
incremental outputs total 825.5 MiB. These bounded development samples do not
qualify the complete server or establish a causal speedup.

The [MariaDB/MySQL execution slice in #335](https://github.com/durable-workflow/server/pull/335)
adds the third typed adapter to the same state machine. Separate physical
catalogs retain the frozen PHP MySQL and MariaDB schemas. A bounded session
lock and checksummed initialization marker account for implicitly committed
DDL; only unchanged, empty native partial initialization can resume. Existing
PHP databases, changed catalogs and unknown migration history are refused.
The Action adds MySQL 8.0, the existing PHP MySQL image and MariaDB 10.11,
with the same three-runtime fixtures, native activity restart and real schema
interruption boundaries. Implementation and local checks are in progress;
these new hosted jobs have not yet established a successful qualification.

Next: finish MariaDB/MySQL execution and interruption qualification, then
qualify backup-first sequential takeover with
stored-value and migration-interruption fixtures. The capability, consumer,
performance and operational inventory remains open under #325.
No separate defect issues have been filed yet.
