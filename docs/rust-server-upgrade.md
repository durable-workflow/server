# Native in-place upgrade preparation

[Server #325](https://github.com/durable-workflow/server/issues/325) requires a
backup-first, sequential upgrade of an existing PHP database. This document
describes the first executable preparation step; production takeover remains
unimplemented and unqualified.

Takeover of a PHP database is planned for deprecation in the 3.1.x line; see
[the takeover note](rust-server-upgrade-takeover.md#deprecation).

## Read-only source inspection contract

The native `upgrade-inspect` command inspects a configured existing PHP
database using a dedicated read-only transaction, without constructing a runtime
pool. It uses the same `DB_*` connection configuration as native schema checks
and supports SQLite, MySQL/MariaDB and PostgreSQL. It requires the existing
development opt-in. SQLite inspection never creates a missing database or runs
recovery pragmas. It requires a regular source file and refuses directories,
pipes and devices before reading a database header. Unix opens remain
nonblocking if a source path changes to a pipe during validation.

SQLite WAL inspection is initially refused before opening SQLite: a read-only
SQLite connection can still create shared-memory and WAL sidecars. This first
path accepts rollback-journal databases only and does not change their journal
mode. WAL inventory requires a separately qualified consistent snapshot path.
The CLI requires a kernel read-only mount for both the source file and its
resolved parent directory. A read-only file mount with a writable parent is
insufficient because sidecars are created beside the file. A writable mount
refuses with `upgrade_source_readonly_filesystem_required` before SQLite opens.
Stop writers before inspection; the mount check does not prove that another
process cannot write through a different mount or certify that writers stopped.

With the development binary and existing connection environment configured,
run `DW_RUST_EXPERIMENTAL=1 durable-workflow-server upgrade-inspect`
(a published image needs no `DW_RUST_EXPERIMENTAL`).
For SQLite, set `DB_CONNECTION=sqlite` and `DB_DATABASE` to the existing file
inside a read-only mount. Network databases use the existing `DB_HOST`,
`DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and TLS settings. Use a
SELECT-only inspection role. Success prints one JSON inventory; refusal exits
nonzero with a bounded reason, without printing a connection URL or password.
No successful result authorizes a subsequent write or upgrade command.

Inspection must refuse a native ownership marker, an unknown catalog, missing
permissions and unavailable storage before reporting durable-state counts.
The known PHP catalog is the existing compiled physical schema with only the
explicit native additions excluded. Database-generated next-ID counters remain
data; no other catalog differences are discarded. Schema equality identifies a
layout, not the installed PHP package version.
MySQL/MariaDB source catalog rows use exact table-name byte ordering so that
server metadata collations cannot change comparison order. All names and
complete definitions remain exact; business row/event order is not transformed.

The separate exact PHP 2.8.0 catalog variant records the published service-call
deadline and worker incarnation additions. It is compared as a complete
catalog after applying a reviewed, exact delta in memory; it does not change
native schema migrations or qualify execution against the newer source layout.

A successful report contains the backend, visible-catalog fingerprint, counts
for the known PHP tables, and explicit `takeover_allowed: false`,
`backup_verified: false`, `php_quiescence_verified: false`. It contains no
workflow payloads, credential digests, provider secrets or connection URL.
Successful inspection means only that this read-only inventory completed under
the configured role's visibility. It does not establish a verified backup,
complete stored-representation compatibility, global writer quiescence or
permission to apply migrations. Source inspection is a native operator tool;
it adds no HTTP or worker capability.

The whole inspection has a fixed 30-second observation budget. Timeout,
unknown schema or access failure produces an explicit refusal, with no source
DDL, ledger, marker, row, lease, sequence or provider mutation. Standalone
connections close on both success and refusal; no cancelled connection returns
to an application pool. Socket retirement has a separate one-second ceiling.
Inspection runs against the configured role's visible catalog. It cannot prove
absence of objects hidden by database privileges, nor infer an installed package
version from schema equality. PostgreSQL row-security policies require separate
qualification and refuse this first inventory path.

## Qualification and next migration gates

The separate `upgrade-payload-inspect` command now also checks recorded `avro`
frames in `workflow_runs.arguments`, `workflow_runs.output`,
`activity_executions.arguments` and `activity_executions.result`. It uses Apache
Avro's schema traversal without retaining decoded arrays or maps. It reads
32 rows per primary-key page, caps encoded frames at 64 KiB, and refuses unknown
or missing codecs, malformed/trailing frames, more than 64 traversal levels or
16,384 visits. Before any decoder is created, this standalone command sets and
verifies Apache's process-wide scalar allocation ceiling of 64 KiB; HTTP runtime
allocation policy is unchanged. Oversized stored values are masked in SQL
before transfer to the client.

The report distinguishes validated inline counts from unsupported values and
external-reference metadata. `inline_values_verified` applies only to these
enumerated root columns. `other_root_payload_columns_verified` stays false and
`unscanned_payload_codec_tables` names the tables whose codec-tagged payloads
are not scanned yet. Nested history, provider bytes, registry ownership and
complete representation remain unverified. Refusal prints the bounded report
and exits nonzero, preserving source data. Known valid frames retain exact
int64, null, Unicode and byte-string semantics; PHP-looking text in a byte
string is never deserialized as an object.

Preparation `c12fe089` passes 112 library tests, two actual CLI tests and 133
SQLite execution tests, with 18 existing library ignores. A malformed frame
declaring a 500 MiB scalar is refused under the actual CLI ceiling without
changing source bytes. These are constructed source controls; the earlier
published-PHP PostgreSQL observation left 22 inline values unvalidated and is
not retroactively qualified by this source change.

Source `03b3bd3c`, native tree `029ab981`, separately passes the actual stopped
PHP 2.8.5 image `f6a6507a` recipe on SQLite DELETE, MySQL 8.4 and PostgreSQL 17.
Each database has two original pending runs, three inline argument frames and
one queued timer bound to its original canonical task. All three inspector
commands pass, with the actual 64 KiB allocation ceiling. Source fingerprints
remain exact: SQLite database bytes and network logical rows/sequences, not
network physical files or backups. Denied readers, unknown catalogs, ownership
markers and PostgreSQL row security refuse where applicable. APIs and databases
exit 0 without OOM after the observation and owned cleanup.

These six raw-protocol originals contain int64, null and Unicode arguments;
their run outputs and activity results are absent. This does not add completed
result, nested history, provider-byte or actual SDK continuation qualification.
The 22 earlier inline values remain the earlier unvalidated observation.
The full hosted database-version matrix, complete representation and every
takeover gate remain open.

The published-source recipe retains its immutable PHP 2.8.0 default. A separate
observation may explicitly set `DW_PARITY_UPGRADE_PHP_IMAGE` to an official
`ghcr.io/durable-workflow/server@sha256:...` image; the exact selected digest is
recorded in its outcome. This does not change any frozen differential actor.

Catalog recognition does not decode stored values. The source audit identifies
these separate compatibility boundaries:

| Representation | Required takeover check |
| --- | --- |
| V2 arguments/results with a row codec, JSON task/history documents and external references | Validate the stored codec and exact typed values, preserving original bytes, IDs and deadlines. Do not infer a codec from table shape. |
| Laravel queue jobs | The V2 workflow/activity/timer jobs carry a task ID and claim canonical task state. Qualify replacement wakeups against those same tasks; never execute serialized PHP objects in Rust or discard unknown jobs. |
| PHP class names, legacy stored workflows and internal serialized closures | Class-name presence is not a portable type binding. Establish a tested conversion/read path or refuse unsupported active data before mutation. |
| Cache and Redis entries | Identify original query, poll and receipt state separately from reconstructable wakeups. A database-only snapshot does not prove these states survive. |

The queue observation comes from the published Core's `TaskDispatcher` and
`RunWorkflowTask`, `RunActivityTask` and `RunTimerTask` handlers. It does not
qualify migration of a queue payload, a closure, a legacy workflow or a live
receipt. Current native SQLite timestamp reads and claim ordering already
handle the supported PHP UTC SQL form and native UTC form; an actual sequential
takeover must still prove pending work retains its original microseconds.

The separate SQLite fixture reader
`scripts/conformance/server-parity/upgrade-source-sqlite-representations.php`
accepts only the source-author's two known fixture IDs through a read-only
`/state` mount. A stopped published PHP 2.8.0 sample preserves exact Avro int64,
null and Unicode arguments, JSON task/history documents, six-digit SQL times
and the full activity retry snapshot. Its queued `RunTimerTask` contains a
serialized Carbon object and references the original canonical timer task.
The reader observes that property without deserializing queue objects; its
pattern check is not a general PHP serialization validator or conversion path.
The sample's six mounted file-cache entries are opaque serialized strings.
The hosted recipe reports cache storage as unobserved when it is not mounted.
No query/poll/receipt semantics, Redis state, legacy closures, completed results
or external bytes are qualified. Exact database hashes before/after establish
read-only observation, with backup, representation compatibility and takeover
still explicitly false. The new hosted hook needs fresh combined qualification.

The native queue-command inspector uses the pinned `phpserz` token parser with
its optional Serde feature disabled. It reads at most 64 KiB, eight nested
levels, 256 tokens and 32 properties per object. The initial recognized subset
is the published V2 workflow/activity/timer job with its original task ID,
optional connection/queue and optional Carbon delay fields. Unknown classes,
custom serialization, references, arrays/chains, duplicate or hidden property
aliases, malformed lengths and trailing values are refused. Dates and timezone
fields remain original strings, not recalculated task deadlines.

The committed queue fixtures are produced by serializing the real classes in
the pinned published image without dispatching them. This independently tested
decoder grants no conversion, deletion, execution or takeover authority.
The read-only source-row binding below is a separate check; actual replacement
of PHP queue execution remains unqualified.

A separate genuine published PHP 2.8.2 source fixture now contains one queued
timer job, its original task/run, and a separate leased activity. Its outer
Laravel handler, command class and default transport policy are checked before
binding the exact canonical task ID, kind, run and namespace. Unknown or
duplicate envelope fields, unsupported policies and changed identities refuse.
The validator returns borrowed source rows, preserving their status, attempts,
leases and availability without any execution/deletion action.

The database job uses Laravel queue `default`, while its canonical timer task
uses SDK queue `upgrade-source`. Those are distinct routing domains. The
serialized Carbon wakeup and integer queue availability are rounded up to the
next second, while the canonical timer keeps its original six fractional
digits. A transport wakeup must never replace that deadline. The pure binding
validator checks the parent SDK queue for workflow/timer tasks and the
independent configured transport queue. It does not compare those two queues
for equality. Activity destinations may differ from their parent's queue.

The fixture is exported read-only after the sole source API exits. This proves
only the recorded source/decoder/binding component. It does not yet scan every
source row, establish full PHP/embedded data compatibility, validate all
timestamp semantics, restore a backup or continue the original workflows in
Rust. `upgrade-inspect` remains the earlier catalog/count inventory and its
takeover flags remain false.

The separate development command `upgrade-queue-inspect` adds database-queue
binding checks inside that same read-only snapshot and 30-second observation
budget. Set all three original transport settings explicitly:

```sh
DW_RUST_EXPERIMENTAL=1 \
DW_RUST_UPGRADE_QUEUE_CONNECTION=database \
DW_RUST_UPGRADE_QUEUE_DEFAULT_CONNECTION=database \
DW_RUST_UPGRADE_QUEUE_DEFAULT_QUEUE=default \
durable-workflow-server upgrade-queue-inspect
```

These settings describe the original Laravel connection and defaults, not a
new SDK destination. Ordinary `DB_*` settings still select the source database.
The command reads 32 jobs per primary-key page and limits each transferred
payload to 128 KiB before decoding. Every job must bind its existing canonical
task and run; unknown representations, missing/changed bindings and count
disagreements refuse without a partial success report. The output adds only
job-kind counts, not payloads or identities. No jobs are deleted or executed,
and all takeover, backup, quiescence and complete representation flags remain
false. An empty database queue does not establish the state of Redis, another
queue connection or another transport. The published-source recipe now requires
this separate component. Local source `5d54f25e` passes the actual recipe on
SQLite DELETE, MySQL 8.4 and PostgreSQL 17, with two original pending runs and
one timer job per database. Inspection preserves each source snapshot, and
denied access, unknown catalogs, ownership markers and PostgreSQL row security
remain explicit refusals where applicable. The full hosted database-version
matrix and subsequent migration gates remain pending.

The separate published PHP 2.8.0 profile prepares two genuine original runs:
one leased activity and one pending timer. It explicitly stops PHP before
inspection, compares source state and sequence positions before/after, and
checks denied access, changed catalog, ownership markers and PostgreSQL row
security. SQLite uses the explicitly selected DELETE journal profile. Its raw
public-protocol author does not establish SDK continuation or workload
completion. The independent SQLite WAL refusal remains a separate observation.
The CI profile adds an eight-minute phase and separate three-minute cleanup
ceiling and requires the preceding deployment checks to succeed. A failed
prerequisite skips inspection rather than counting a missing deployment as an
inspected source. Existing scenario, transaction and job budgets remain unchanged.

Use a separate cohort populated by the current published PHP artifact, record
its actual identity, and stop every PHP writer before refusal/byte-preservation
checks. Existing differential actors and frozen manifests remain unchanged.
Cover real pending workflow/activity/timer state, select-only inspection,
missing files, unknown schema, ownership markers and failures on all required
database families. An inventory report is not an executed upgrade.

The subsequent migration must validate stored PHP-specific representations and
queued work, verify an independently restored consistent database/external-byte
backup with reclamation held, establish writer quiescence, apply versioned
native changes, and record ownership before PHP can resume. Interruptions must
resume safely or refuse. Actual SDK workers must continue the same original
pending work with preserved histories, credentials, deadlines and external
bytes. The existing PHP guard must refuse all upgraded startup/bootstrap/
worker/scheduler paths. There is no mixed fleet or post-upgrade PHP rollback.
