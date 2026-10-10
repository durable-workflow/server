# Rust Server development

This crate is an unpublished development foundation for [Server #325](https://github.com/durable-workflow/server/issues/325).
The published PHP image remains the default. The opt-in Rust HTTP runtime now
executes 20 reviewed shared fixtures through an unchanged published PHP SDK,
including bounded root cooperative cleanup at timer boundaries. The port log
records the six-database matrix and real process-kill recovery evidence.
It is an incomplete development slice, with no qualified database takeover,
performance improvement or release. The codec module uses Apache's official
Avro library. Signals decode arguments and cooperative requests encode their
canonical context on bounded blocking workers; ordinary author payloads retain
their opaque envelopes. Complete
ingress validation and resource limits remain unqualified.

The toolchain is pinned in `rust-toolchain.toml`; dependency resolution is in
`Cargo.lock`. Compression and Avro schema-generation features are disabled.
Development/test debug information is disabled to bound artifact size. This
does not select release optimization or remove the later profiling requirement.

Run language tooling in a runtime container as the checkout's owner:

```sh
docker run --rm --user="$(id -u):$(id -g)" --cpus=2 --memory=2g --memory-swap=2g \
  -e CARGO_HOME=/tmp/cargo -e CARGO_BUILD_JOBS=2 \
  -v "$PWD:/source" -w /source/rust \
  rust@sha256:ba81bc3eaa4422af576c0262515d96b0111a628a6ccc2c86557cf55c9a4bbee0 \
  cargo test --locked
```

The pinned development image above is amd64. Supported release architectures,
CPU-heavy async deadlines, the complete database/fixture matrix and safe upgrade
remain acceptance gates in the [port log](../docs/rust-server-port.md).
The development update slice supports original declarations and primitive
positional contracts, immutable request-ID receipts, routed SDK update tasks,
normal lease/attempt and registration fences, and durable exact Avro results.
Updates require a quiescent committed wait; busy workflow/control queues are
refused explicitly. Each run is bounded to 64 updates, arguments to 64 KiB,
results to 256 KiB and completion waits to 30 seconds. A timed-out wait leaves
the accepted update durable. Completed request retries return the original
result and cannot change arguments or append history. Validators, failed update
tasks, concurrent queued routing and complete update compatibility remain
unqualified; shared database qualification for the representative update slice
is recorded in the port log.
The existing development schema remains version 3.

Kache remains an optional task-scoped experiment, with an explicit disk budget;
no global Cargo wrapper or cleanup daemon is installed.
The [first build observations](build-observations-2026-10-09.md) record clean,
no-op, comment-edit and cache results with their disk costs and limits.
The [HTTP slice observations](runtime-build-observations-2026-10-09.md) record
its larger native build, test and dependency costs.
The [PostgreSQL observations](postgres-build-observations-2026-10-09.md) include
typed storage and TLS dependencies, with separate clean/incremental disk costs.
The [PostgreSQL execution observations](postgres-execution-build-observations-2026-10-09.md)
record the shared runtime's current clean and incremental costs.
The [MySQL/MariaDB execution observations](mysql-execution-build-observations-2026-10-09.md)
include all three SQL drivers and their clean and incremental disk costs.

## PostgreSQL storage foundation

The unpublished binary also provides `schema-bootstrap` and `schema-check`
commands for PostgreSQL. The same backend now executes the limited native HTTP
workflow/activity slice. Set `DW_RUST_EXPERIMENTAL=1`, `DB_CONNECTION=pgsql`, `DB_DATABASE`,
`DB_USERNAME`, and the usual `DB_HOST`, `DB_PORT` and `DB_PASSWORD`. The database
must already exist. `DB_URL` takes precedence over the individual connection
fields and uses SQLx's PostgreSQL URL options.

`schema-bootstrap` admits only an empty `public` schema or this exact native
version. It refuses existing PHP tables before creating a writable pool. A
transaction-scoped advisory lock serializes fresh-node bootstrap; SQLx commits
the full schema, migration checksum and ownership marker together. The schema
comes from the frozen PHP baseline, including timestamp precision, JSON types,
defaults, constraints, indexes and sequence ownership. The compiled catalog is
checked with PostgreSQL's catalog functions. Corruption or unknown history is
refused, not repaired automatically. This development path fixes `search_path`
to `public` and UTC; custom schemas remain unqualified.

`schema-check` uses a read-only repeatable-read transaction and works with a
SELECT-only role. It creates no schema or migration ledger. Both commands close
probe connections explicitly on refusal. PostgreSQL can keep temporary WAL and
system bookkeeping as it normally does; refusal means no application schema,
rows, migration history or sequence positions are changed.

TLS uses SQLx/Rustls. `DB_SSLMODE` defaults to `prefer`, matching PHP's current
default; it can fall back to an unencrypted server. Use `verify-full` with
`DB_SSLROOTCERT` for a trusted CA and hostname verification, or configure the
equivalent options in `DB_URL`. The shared Action checks a real encrypted
connection and rejects an untrusted certificate and a wrong hostname.

The explicit backend checks require a disposable PostgreSQL role that can
create databases and roles. In the same build container setup, set
`DW_TEST_POSTGRES_URL` to that isolated admin database and run:

```sh
cargo test --locked --lib runtime::postgres::tests::qualification_ -- --ignored --nocapture
```

The ordinary `cargo test --all-targets` run reports these backend-dependent cases
as ignored; the shared Action runs them explicitly on both pinned PostgreSQL
images. They cover concurrent initialization, typed JSON/64-bit/microsecond
values, SELECT-only inspection, thirteen corrupt catalogs/history states and
real process kills at two acknowledged uncommitted migration boundaries.
The published PHP fixture separately creates pending work and a leased activity,
stops PHP, and verifies native refusal with unchanged row/sequence fingerprints.
Data in other user schemas is also refused rather than treated as an empty
public schema. Backup-first conversion and complete multi-node recovery remain
required under #325.

The execution state machine is shared with SQLite through typed SQLx adapters.
PostgreSQL retains JSONB and microsecond timestamps; SQLite retains its native
development text format. PostgreSQL serializes this first default-namespace
slice with a transaction-scoped advisory lock, distinct from the bootstrap lock.
An empty poll releases the transaction before waiting. This deliberately bounded
implementation does not establish throughput or complete multi-node safety.

Set `DW_EXECUTION_POSTGRES_URL` to an isolated create-database role and run
`cargo test --locked --test execution` to execute the common HTTP scenarios on
PostgreSQL. Each scenario has its own database; the existing PHP-file refusal
case remains SQLite-specific. With no such variable, the same suite uses SQLite.
The shared Action explicitly runs both pinned PostgreSQL versions, unchanged
PHP/Rust/embedded fixtures in three separate databases, and two native processes
sharing only their own database. Its restart probe kills one native process,
checks survivor readiness, starts a replacement, waits for the actual activity
lease to expire and resumes work through the published PHP SDK. This is a
bounded lease-recovery check, not full failure or performance qualification.

## MySQL and MariaDB development execution

The same limited HTTP state machine also has a typed SQLx MySQL adapter.
Use `DB_CONNECTION=mysql` (or `mariadb`), `DB_DATABASE`, `DB_USERNAME`, and the
usual `DB_HOST`, `DB_PORT` and `DB_PASSWORD`; the port defaults to 3306.
`DW_RUST_EXPERIMENTAL=1` remains required. `DB_URL` takes precedence over these
fields and uses SQLx's MySQL URL options. The database must already exist.

`schema-bootstrap` admits an empty database, this exact completed native
schema, or an unchanged native initialization with the compiled checksum.
It refuses published PHP data before writable initialization. MySQL and
MariaDB have separate physical catalogs captured from the frozen PHP release:
original columns, defaults, integer widths, JSON representation, timestamp
precision, indexes and constraints are preserved. An additional polling index
and separate native receipt tables do not replace PHP columns.

These engines implicitly commit DDL. A dedicated session holds a database
initialization lock with a 60-second wait limit; a `rust-initializing` marker
records the intended version and checksum before application DDL begins.
Interrupted initialization can resume only if every existing table matches
the compiled catalog, all application tables are empty, and the marker and
SQLx journal have the recognized version/checksum. The acknowledged journal
row is retained when finishing interrupted DDL. Changed catalogs, unknown
history and occupied partial schemas are refused. A marker alone without an
ownership row is also refused. Readiness requires the final
`rust-development` marker and successful journal.

`schema-check` checks the complete catalog and history in a read-only
transaction, including with a SELECT-only role. It creates no tables, journal
or ownership marker, and changes no application rows or next-ID counters.
This is development initialization, not PHP database conversion or backup.
**Never connect PHP to a Rust development database.**

UTC `TIMESTAMP(6)` values and typed JSON retain their PHP physical types;
MariaDB's JSON alias remains distinct from MySQL's native JSON representation.
An InnoDB lock on the ownership row serializes this first default-namespace
slice across native processes. Empty polls release transactions before waiting.
Full throughput, idle cost and multi-node failure behavior remain unqualified.

TLS uses SQLx/Rustls. `DB_SSLMODE` defaults to `preferred`, which can fall back
to an unencrypted connection. Use `verify_identity` with `DB_SSLROOTCERT` for
CA trust and hostname verification, or the equivalent `DB_URL` options.

Set `DW_TEST_MYSQL_URL` to a disposable create-database/create-user role and run
`cargo test --locked --lib runtime::mysql::tests::qualification_ -- --ignored`.
Set `DW_EXECUTION_MYSQL_URL` and run `cargo test --locked --test execution` for
the common HTTP cases. Select one execution backend per run; without a backend
variable the suite uses SQLite. The shared Action targets pinned MySQL 8.0,
the existing PHP MySQL matrix image, and MariaDB 10.11. It includes real
initialization kills, independent PHP/Rust/embedded fixtures, two native
processes, activity lease recovery, unchanged PHP data on refusal and TLS checks.
The [hosted qualification](https://github.com/durable-workflow/server/actions/runs/37953419450)
passes on all three images: seven storage cases and nine common HTTP cases per
backend, the three unchanged execution fixtures, actual node kill/replacement,
PHP-data refusal and trusted/untrusted/wrong-hostname TLS checks. The HTTP
suite's additional PHP-file refusal case remains SQLite-specific. These bounded
cases do not establish full parity or authorize database takeover.

The shared [codec values](../tests/Fixtures/ServerParity/Codec/v1.json) declare
logical expectations from the immutable schema: exact long boundaries, finite
doubles (including negative zero), Unicode, binary values, nested arrays and
string-keyed maps. The existing long-zero corpus supplies the reviewed golden
wire. Map order is compared semantically; it is not a byte identity.
`cargo run --locked --example codec-fixtures -- FIXTURE [OTHER_OBSERVATIONS]`
checks Rust round trips and optionally decodes the other binding's bytes.
`scripts/conformance/server-parity/codec.php` verifies those bytes with the
published Workflow codec and emits PHP bytes for Rust to decode. The ordinary
HTTP/embedded execution fixtures run separately; codec checks do not replace them.

The framing tests reject truncated headers/datums, unknown fingerprints, trailing
bytes and non-finite doubles. Strict ingress parity is still open: malformed
collection blocks, duplicate map keys, recursion/allocation limits and streaming
external payloads require the existing negative corpus before enabling HTTP
datum decoding or qualifying ingress.

## First HTTP execution slice

Build with `cargo build --locked` in the same container/mount setup. Run the
binary with `DW_RUST_EXPERIMENTAL=1`, a nonempty `DW_AUTH_TOKEN`,
`DB_CONNECTION=sqlite` and `DB_DATABASE` naming its own isolated file, or the
PostgreSQL or MySQL fields above naming its own isolated database.
`DW_BIND_ADDRESS` defaults to `127.0.0.1:8080`; set `0.0.0.0:8080` for a
development container network. Use the same UID and persistent database mount
across restarts. This opt-in does not authorize use as a production replacement.

The first slice uses Axum/Tokio for HTTP and SQLx for SQLite transactions, with
only the required features enabled and no build-time database or SQL macro
generation. Four connections and `BEGIN IMMEDIATE` serialize durable state
changes; SQLite runs on SQLx's connection workers. HTTP uses two Tokio executor
threads. WAL with full synchronous writes retains acknowledged state.
This is not qualification of throughput, CPU-heavy deadlines or multi-node HA.

Implemented paths cover health/schema readiness, limited capability discovery,
workflow start/describe/paginated history, worker registration/heartbeat/removal,
workflow/activity polling, workflow lease renewal and both completion paths.
Admitted commands are `schedule_activity`, `start_timer`, `open_condition_wait`,
`open_signal_wait` and `complete_workflow`;
unsupported effects/options are refused before batch mutation. Published PHP
workers replay the persisted activity result and complete the workflow normally.
Discovery marks the runtime as development, advertises the admitted commands
and sets the major unimplemented worker capabilities to false. Accepted protocol
headers identify transport shapes, not a full protocol conformance claim.
Queries admit a quiescent committed snapshot, including completed runs, through
the published worker query protocol. An active/ready workflow task returns
`query_snapshot_busy`; concurrent workflow/query routing remains unqualified.
Original query declarations and positional primitive argument contracts are
recorded at start. Default/variadic/class parameters remain unsupported.
Query arguments and results use the official codec on the same bounded blocking
worker as signals. Other missing paths return
`rust_capability_not_implemented`; no missing operation is presented as success.

Starts, ordered history, task claims and outcomes are transactional. Expired or
revoked owner/attempt fences cannot commit. Identical completion retries return
the existing receipt without another outcome; conflicting retries are refused.
Query completion follows the PHP broker's terminal lease refusal on repetition.
Query poll-request receipt idempotency is explicitly advertised as false;
repeat polling by the active owner retains its existing attempt and snapshot.
Transient query entries use a native prefix in a separate `dw_query_cache` table, with
16 pending requests and 64 pending/results combined per default-namespace
development database. Arguments are capped at 64 KiB, each complete task and
snapshot at one MiB, result blobs at 256 KiB, and completion bodies at 512 KiB.
The query deadline is 30 seconds, leases last at most ten seconds, and recent
results expire after 60 seconds. Admission/polling reclaim expired entries;
abandoned requests occupy a bounded slot until their deadline plus 60 seconds.
Results discard replay snapshots. These are development bounds, not qualified
customer limits. Full query failures, worker compatibility, busy routing and
request-id receipts still need reviewed fixtures.
Poll IDs retain the original task/snapshot across process boundaries, with
expired receipts reclaimed. Worker/control-plane history is paginated, capped
at 1000 records and eight MiB of encoded payloads per page. Requests are capped
at three MiB and inline envelopes at two MiB. Full customer payload/resource
limits and external transport remain unqualified.

The native development bootstrap creates the complete SQLite schema from the
frozen published PHP image: 49 tables and 333 explicit indexes. Native completion
and poll receipts use separate relations. SQLx records a checksummed migration;
the ledger, schema and version-3 `dw_server_schema` marker commit atomically.
Concurrent fresh nodes serialize bootstrap. Startup checks the full catalog and
migration history read-only before enabling WAL or opening writable connections.
PHP/unknown databases, changed native catalogs/checksums and the old abbreviated
version-1/version-2 development schemas are refused without conversion. Keep
needed old development data separately; this unpublished slice supplies no
older-development converter. Use a new isolated file for version 3.

This refusal is not the upgrade mechanism. Backup-first conversion of PHP's
stored representations and interrupted takeover remain required on all three
database families. The PHP ownership fence is merged in source, while the frozen
published PHP image still lacks it. **Never connect PHP to a Rust development database.**
The only enabled namespace/auth setup is `default` with the compatibility token;
scoped credentials, other namespaces and the full authorization contract remain
open. Broader timer/signal semantics, failure/retry policy, cancellation and the rest of the API also
remain open under #325. Existing databases and published PHP artifacts are
unchanged by this opt-in crate.

Read-only inspection of an existing WAL database can create temporary `-shm`
metadata and a zero-byte `-wal`, as described by [SQLite](https://sqlite.org/wal.html#read_only_databases).
The probe never enables WAL on a PHP/unknown database or writes its data;
existing database and nonempty journal bytes must remain unchanged. Probe
connections close explicitly even on refusal. Never use `immutable=1` to bypass
WAL visibility or manually delete a nonempty journal.

`cargo test --locked --all-targets` exercises file persistence, independent
connection-pool claims, stale fences, duplicate outcomes/polls, worker history
pagination, atomic unsupported-command refusal and read-only PHP refusal.
The shared Action builds once, cross-decodes the 15 codec cases, executes all
eight reviewed fixtures against separate PHP/Rust/embedded databases, then
kills the Rust process with a leased activity, a pending timer and an acknowledged
pending signal. The restart probe lets the actual lease expire, rejects the old
claim and completes through a new published PHP SDK worker. It checks original
run/history IDs, timer deadline, signal acknowledgement and wait fingerprint,
one cursor advancement and one committed outcome per workflow on each configured
database backend. That bounded kill check does not qualify all failure boundaries.
