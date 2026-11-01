# Rust Server development

This crate is an unpublished development foundation for [Server #325](https://github.com/durable-workflow/server/issues/325).
The published PHP image remains the default. The opt-in Rust HTTP runtime now
executes 29 default shared fixtures and a separate role-token profile through an unchanged published PHP SDK,
including bounded root cooperative cleanup at timer boundaries and portable
NUL cancellation diagnostics. The port log
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

The HTTP executable requires nonempty `DW_AUTH_TOKEN`. A development build
(no release version built in as `DW_SERVER_VERSION`) also requires
`DW_RUST_EXPERIMENTAL=1`; a release build from a `native-` tag does not. With no role tokens, the legacy credential keeps compatibility
access. Configuring any `DW_WORKER_TOKEN`, `DW_OPERATOR_TOKEN` or
`DW_ADMIN_TOKEN` disables that bypass: the legacy credential becomes admin,
and task polling/completion requires the worker role. Roles are exact membership;
admin does not imply worker. Registration and cluster discovery allow all
three roles. The [shared harness](../scripts/conformance/server-parity/README.md)
documents the separate profile and independent databases.
Principal/custom-provider, signature/no-auth, runtime-credential, historical
role-token alias and disabled-backward-compatibility configurations refuse
startup before storage opens while those profiles remain unqualified.
Unparseable auth environment values also refuse startup; they cannot silently
turn a configured role into an absent credential.

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
Registration, polling and cluster discovery advertise `complete_update` and
`fail_update` so workers can negotiate those already admitted commands before
completing an accepted update.
Updates require a quiescent committed wait; busy workflow/control queues are
refused explicitly. Each run is bounded to 64 updates, arguments to 64 KiB,
results to 256 KiB and completion waits to 30 seconds. A timed-out wait leaves
the accepted update durable. Completed request retries return the original
result and cannot change arguments or append history. Validators, failed update
tasks, concurrent queued routing and complete update compatibility remain
unqualified; shared database qualification for the representative update slice
is recorded in the port log.
Direct cancellation, ordinary workflow completion, workflow failure and supported
cooperative root cancellation now
[fail their still-accepted updates atomically](../docs/contracts/terminal-update-closure.md).
The original update and command identities, failure receipt and forward-only
message cursor survive cold retries. Previously completed updates remain intact,
and old update leases cannot apply after closure. Cooperative cleanup preserves
its original root identity and deadline. It closes accepted updates only when
that run closes, including maintenance expiry. Validated sequential children
with `abandon` operation and parent-close policies can receive their own
[independent cancellation request](../docs/contracts/child-independent-cancellation.md).
They retain their own root identity and resolve their parent atomically at
closure. Other child policies, active activities and open waits retain their
request guards. This does not widen update admission,
wider cooperative propagation or continuation transfer. For source controls,
set `DW_TEST_TERMINAL_UPDATES_REFERENCE` when running `terminal_updates::` or
`cooperative_updates::`.
The [PHP comparator](tests/reference/terminal_updates.php) executes the published
closer in its own migrated in-memory database using captured original inputs.
It requires `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` and checks the
resolved application configuration before providers boot.
The development schema is version 4, with separate registration-incarnation
authority and shutdown receipts. Fenced shutdown repairs original workflow tasks
and commits their accepted repair history with the receipt. Re-registering the
same worker ID instead releases its original workflow leases without adding
repair history, matching PHP. Lease release and token rotation share one physical
transaction. The separate source-reference profile checks these HTTP
lifecycles; activity replacement, sticky execution, transport faults and complete
shutdown budgets remain open.

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
A development build still requires `DW_RUST_EXPERIMENTAL=1`. `DB_URL` takes precedence over these
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
Admitted commands include `schedule_activity`, `start_timer`, `start_child_workflow`,
`open_condition_wait`, `open_signal_wait`, `record_version_marker`,
`complete_workflow`, `fail_workflow`, `continue_as_new`, `complete_update` and `fail_update`;
unsupported effects/options are refused before batch mutation. Published PHP
workers replay the persisted activity result and complete the workflow normally.
Discovery marks the runtime as development, advertises the admitted commands
and sets the major unimplemented worker capabilities to false. Accepted protocol
headers identify transport shapes, not a full protocol conformance claim.
Direct signal waits in default and named namespaces accept an optional
`timeout_seconds`: null/absence waits indefinitely, zero resolves immediately,
and a positive integer retains a durable timer and its original deadline.
Buffered input wins before scheduling; application cancels a pending timeout
with the original timer/wait metadata. The [timed-signal contract](../docs/contracts/timed-signal-waits.md)
describes resume identities and retained waits. Named namespaces admit
`open_signal_wait` only as the sole command in a completion. Installed-consumer
acceptance remains a separate gate.
Condition waits accept the same optional timeout in their existing default and
named namespaces. Each physical wait owns its timer identity and authored
deadline. Applying a signal satisfies the condition wait before cancelling its
pending timer; firing leaves the wait open until the worker applies the outcome.
Buffered input does not skip condition-timer creation. The
[condition contract](../docs/contracts/timed-condition-waits.md) records these
differences and physical reopen behavior.
For the focused source reference, set `DW_TEST_TIMED_SIGNAL_REFERENCE` to a
writable JSONL path when running `cargo test --test execution timed_signal_waits::`.
The [PHP comparator](tests/reference/timed_external_waits.php) compares those
captured waits with an explicitly selected published Workflow installation.
Set `DW_TEST_TIMED_CONDITION_REFERENCE` when running the corresponding
`timed_condition_waits::` source tests. Their snapshots retain real clock bounds
around each read; the comparator requires the published current-wait DTO and
liveness to agree at both bounds without changing product timestamps.
Activity/timer `all()/parallel()` barriers retain flat or nested group paths
through actual member completion, retry, failure, expiry and cancellation.
Each represented barrier must arrive complete within the existing 100-command
batch limit. Ready workflow wakeups can coalesce; later outcomes retain a
wakeup while an earlier snapshot is leased. All-groups do not create selection
winners. The [barrier contract](../docs/contracts/parallel-all-groups.md)
records sequence and clock provenance. Child/signal/condition groups and
installed-worker acceptance remain separate gates.
Set `DW_TEST_PARALLEL_ALL_REFERENCE` when running the `parallel_all::`
source tests. The [published PHP comparator](tests/reference/parallel_all.php)
checks liveness, task problems and every field of the bounded current-wait
members using original history and their retained metadata.
The [workflow failure command](../docs/contracts/workflow-failure-command.md)
closes a run and its originating task as failed with one durable terminal failure
and a fenced completion receipt. Cooperative cancellation retains precedence.
Failed sequential children retain their original call and propagate the terminal
failure ID to one parent resume, including when an earlier activity failed.
The parent can catch that outcome and start another sequential child. Existing
update-owned task guards and broader child policies remain separate limits.
Set `DW_TEST_WORKFLOW_FAILURE_REFERENCE` when running the `workflow_failures::`
source controls. The [pure PHP reference](tests/reference/workflow_failure.php)
compares failure payloads with the published bridge and FailureFactory; existing
history-budget, child and task helpers compare their captured projections.
These source comparisons do not qualify an installed worker or retired consumer recipe.
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
The separate native query-authority slice retains content-bound completion and
failure decisions for the original owner and attempt. Matching retries return
the committed decision with `recorded=false`, including after the original
deadline; conflicting operations or payloads remain refused. This does not
change the activity or workflow receipt contract.
Query poll-request receipt idempotency is explicitly advertised as false;
repeat polling by the active owner retains its existing attempt and snapshot.
Transient query entries use a native prefix in a separate `dw_query_cache` table, with
16 pending requests and 64 pending/results combined per namespace in the
development database. Arguments are capped at 64 KiB, each complete task and
snapshot at one MiB, result blobs at 256 KiB, and completion bodies at 512 KiB.
Admission snapshots UTC timestamps with six fractional digits. Query timing
uses the PHP configuration aliases: the default result duration is 45 seconds,
the configured 60-second lease is capped by the original result deadline, and
the default retention is 180 seconds, with a minimum of the configured result
duration plus lease duration plus 60 seconds. Policy changes and polls preserve
those original snapshots; zero expires immediately. Missing legacy timing
gets a fixed refusal without a fresh execution budget. Results discard replay
snapshots. Protocol 1.20 status reads observe the original claim without
renewing it; 1.19 receives an explicit unavailable response. Discovery keeps
`query_task_authority=false` until separate current-PHP and installed-consumer
qualification. These are development bounds, not qualified customer limits.
Queue-lock pressure, full query failures, worker compatibility, busy routing,
request-id receipts and cross-runtime adoption still need reviewed fixtures.
Poll IDs retain the original task/snapshot across process boundaries, with
expired receipts reclaimed. Worker/control-plane history is paginated, capped
at 1000 records and eight MiB of encoded payloads per page. Requests are capped
at three MiB and inline envelopes at two MiB. Full customer payload/resource
limits and external transport remain unqualified.

The development external-payload routes upload/fetch verified encoded bytes at
`/api/external-payloads/v1`, with authenticated namespace binding, local policy,
object/byte quota admission and an independent request size/timeout bound.
`PUT /api/namespaces/{namespace}/external-storage` is admin-only and currently
supports the local driver. Version-1 activity-result completion uploads require
the original task/attempt/owner and preserve a bounded allowance without renewing
the lease. Workflow/query/prepared completion contexts remain refused. Full transport discovery
stays unadvertised pending real workflow resolution/retention, cleanup/backup,
restart, all database families and S3 qualification. The actual byte probe is
`scripts/conformance/server-parity/external-payload-http.py`; run it only against
an isolated runtime configured with its disposable fixture token, a default
namespace, four MiB payload ceiling and one-object namespace quota. See the
[port log](../docs/rust-server-port.md) for its precise acceptance scope.
Workflow start, activity arguments, terminal workflow completion and activity completion accept
verified opaque external payloads. Retention commits with the admitted input or fenced result; cold
delivery, history and descriptions keep references for normal SDK hydration.
The installed-worker input recipe is
`scripts/conformance/server-parity/external-input-worker.php`. The explicit
result-upload/public task-completion recipe and separate cold SDK reader are in
`scripts/conformance/server-parity/external-result-client.php`. Its
`activity-author` and `activity-reader` phases use explicit activity-result HTTP
completion and a separate installed SDK workflow-history reader. These recipes
do not establish automatic SDK upload discovery. The separate raw HTTP recipe
`scripts/conformance/server-parity/activity-completion-upload-probe.php` covers
the activity completion-upload context, including real UTF-8 JSON. Memo/query
ingress, broader completion contexts and automatic external result policy remain
unqualified. Apache's schema-aware validation patch is recorded in
`vendor/apache-avro/PATCHES.md`.

Activity argument batches share transfer admission and one preparation deadline.
Verified snapshots remain alive until the whole transaction finishes, including
rollback after a later command. Temporary storage must accommodate the combined
encoded bytes for an admitted batch, within the existing 100-command bound.
`scripts/conformance/server-parity/external-arguments-worker.php` exercises an
explicit upload/scheduling author, an unchanged installed activity worker, and a
separate cold SDK workflow reader that completes the same original workflow.
Set `DW_PARITY_EXTERNAL_ACTIVITY_COUNT=2` for two distinct large arguments in
one completion batch. The reader joins results by authored activity sequence
and checks that a closed run delivers no further workflow task.

The native development bootstrap creates the complete SQLite schema from the
frozen published PHP image: 49 tables and 333 explicit indexes. Native completion
and poll receipts use separate relations. SQLx records a checksummed migration;
the ledger, schema and version-4 `dw_server_schema` marker commit atomically.
Concurrent fresh nodes serialize bootstrap. Startup checks the full catalog and
migration history read-only before enabling WAL or opening writable connections.
PHP/unknown databases, changed native catalogs/checksums and the old abbreviated
version-1/version-2/version-3 development schemas are refused without conversion. Keep
needed old development data separately; this unpublished slice supplies no
older-development converter. Use a new isolated file for version 4.

This refusal is not the upgrade mechanism. A stopped PHP database can be taken
over with the development `upgrade-takeover` command, following the
[takeover design](../docs/rust-server-upgrade-takeover.md):

1. Run PHP Server 2.5.14 or later, which refuses a Rust-owned database, and
   stop every PHP role.
   PHP Server keeps SQLite in WAL mode, which the upgrade commands refuse. With
   PHP stopped, run `upgrade-prepare-sqlite` on the source file (writable).
   It has SQLite checkpoint every committed WAL frame into the file and switch
   it to the rollback journal, refusing with `upgrade_source_in_use` while any
   other process has the file open. The rows are unchanged; the backup below
   is taken afterwards. A journal left by a crashed writer is rolled back the
   same way.
2. Run `upgrade-fingerprint` on the source: a SQLite file mounted read-only,
   or the database through a read-only account. Back the database up (a file
   copy, `pg_dump` or `mysqldump --single-transaction`), restore the backup
   separately, and fingerprint that too. The digests must be equal.
3. Run `upgrade-takeover` with `DW_UPGRADE_BACKUP_FINGERPRINT` set to that
   digest and the original queue settings (`DW_RUST_UPGRADE_QUEUE_*`, as for
   `upgrade-queue-inspect`). PostgreSQL uses the owner's `DB_*` settings.
   MySQL and MariaDB use an account that owns the database and has the
   `PROCESS` privilege, so the takeover can see every other session.

On SQLite and PostgreSQL, inspection, the fingerprint check, the conversion
of the recorded PHP release layout, the native relations, the migration ledger
and native's own startup verification all happen in one transaction. SQLite
holds the file
exclusively; PostgreSQL takes native's bootstrap lock and locks every table
before reading, and refuses with `upgrade_source_in_use` if a session still
holds one after five seconds. A refusal leaves the PHP database unchanged. An
interrupted takeover commits nothing: rerun it with the same digest. On
SQLite, the rerun lets SQLite roll back the journal the interrupted run left,
and the fingerprint check then proves the file is the backed-up one.

MySQL and MariaDB commit DDL implicitly, so their takeover runs in steps under
native's bootstrap lock. It refuses with `upgrade_source_in_use` while any
other session uses the database. Once the source is verified, it first writes
native's marker with a `rust-takeover:<layout>` row: PHP refuses the database
from then on, and native refuses it with `native_takeover_incomplete` until
the takeover finishes. It then checks the sessions and the fingerprint again,
and runs each conversion step, recording each in that row. An interrupted
takeover is rerun with the same digest and continues after the last recorded
step; a different digest is refused.

The command refuses:

- a digest that differs, so any write after the backup stops it;
- unrecognized queued jobs, external payloads and service calls;
- a layout other than the frozen one, PHP 2.8.0 or PHP 2.9.7 (with either
  Laravel MariaDB driver), or a database native already owns.

Nested payload columns and multi-node qualification remain open. **Never connect PHP to a Rust development database.**
Named namespace isolation and configured worker/operator/admin roles have
representative shared qualification. Principal/custom-provider credentials and
the full authorization contract remain open. Broader timer/signal semantics, failure/retry policy, cancellation and the rest of the API also
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
22 reviewed fixtures against separate PHP/Rust/embedded databases, then
kills the Rust process with a leased activity, a pending timer and an acknowledged
pending signal. The restart probe lets the actual lease expire, rejects the old
claim and completes through a new published PHP SDK worker. It checks original
run/history IDs, timer deadline, signal acknowledgement and wait fingerprint,
one cursor advancement and one committed outcome per workflow on each configured
database backend. That bounded kill check does not qualify all failure boundaries.
