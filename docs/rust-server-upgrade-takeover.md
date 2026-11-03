# Native upgrade takeover

`durable-workflow-server upgrade-takeover` moves an existing PHP Server 2.x
database to the native 3.x server, once and in place. It is backup-first and
one-way: before it changes anything, it checks that the database still matches
a backup you have verified, and after it commits, PHP refuses the database.
It ships in every native 3.0.x image (3.0.0, 3.0.1, 3.0.2 and later). This
page is the operator procedure. The design follows under [Design](#design).

## Status and scope

**What is qualified.** The takeover is qualified by CI on the exact source of
each 3.0.x release. The *Shared Server fixtures* workflow runs a debug build of
that source. It backs up a database written by a published PHP image, takes
over a restored copy, resumes the PHP-era work under native and checks that
PHP then refuses the database. For 3.0.0 this passed in merge-group run
[41459209091](https://github.com/durable-workflow/server/actions/runs/41459209091),
and for 3.0.2 in run
[41562369513](https://github.com/durable-workflow/server/actions/runs/41562369513).

| Covered | How |
| --- | --- |
| Databases created by published PHP 2.8.0, 2.9.7, 2.11.1, 2.11.3, 2.11.7 and 2.11.8 | End to end on SQLite, two PostgreSQL images (one is PostgreSQL 17), two MySQL 8 images (one is 8.0) and MariaDB 10.11: inspect, fingerprint, back up and restore, take over, resume a timer, an activity, a signal and an expired lease, refuse the stale attempt, and PHP's refusal afterwards (`scripts/conformance/server-parity/upgrade-source-profile.sh`). |
| 2.11.4 and 2.11.5 | They add no migration, so their databases have the 2.11.3 layout that is tested above. |
| 2.9.7 databases later migrated by 2.11.1, 2.11.3 or 2.11.8 (the `-from-2.9.7` layouts) | Catalogs captured from real migrated databases, plus backend qualification tests on PostgreSQL, MySQL and MariaDB (`rust/src/runtime/{postgres,mysql}.rs`) and SQLite unit tests. No end-to-end cohort. |
| The 2.5.14 layout (`source_layout: "frozen"`) | Backend qualification tests and SQLite unit tests on a database built from that layout. No end-to-end cohort from a real 2.5.14 installation. |
| Sources PHP wrote through Laravel's `mariadb` connection (`+mariadb-driver` layouts) | Derived from their one known difference, the backup-hold owner column, and covered by a MariaDB backend qualification test. Not captured separately for 2.11.8. |
| Refusals and interruption | Each refusal leaves the database unchanged. A takeover killed before commit (SQLite, PostgreSQL) or after each journal step (MySQL, MariaDB) reruns or resumes to the same result. |

Security reviewed the 2.11.8 layout change and its refusal boundary, and
accepted the exact head of
[#921](https://github.com/durable-workflow/server/pull/921#issuecomment-6561043224).

**What is not qualified yet.**

- No installed acceptance has run `upgrade-takeover` from a *published* 3.0.x
  image against a real PHP installation. The release smoke test only checks
  that the commands are present and refuse a missing source. There is no
  independent Security acceptance of the takeover as a whole.
- Database histories other than those above are accepted only when
  inspection matches a recorded layout exactly. Otherwise they are refused
  before any change.
- Some state is always refused (see [Before you start](#before-you-start)):
  external payload references, workflow-to-workflow messages, pending
  service-call dispatches, open workflow-bound service calls and some
  schedules. The operator decided on November 1, 2026 to leave external
  payload takeover out of 3.0.x
  ([#325](https://github.com/durable-workflow/server/issues/325#issuecomment-6559827982)).

Rehearse the whole procedure on a restored copy of your backup before you run
it on the live database. A rehearsal is the same commands pointed at the copy.

**The inspect commands always report `takeover_allowed: false`.** This is by
design. `upgrade-inspect`, `upgrade-queue-inspect`, `upgrade-payload-inspect`
and `upgrade-fingerprint` only read. They cannot prove that PHP is stopped,
that a backup restores or that nothing writes afterwards. So their
`takeover_allowed`, `backup_verified`, `php_quiescence_verified` and
`stored_representation_compatibility_verified` fields are constant `false`.
Only `upgrade-takeover` decides, inside its own exclusive transaction. Read
`status` and `source_layout` instead. Likewise, `qualified_for_php_takeover:
false` in `/api/cluster/info` is a constant. It describes the serving process,
which never takes over a database at startup, and does not predict what
`upgrade-takeover` will do.

## Deprecation

Takeover ships in 3.0.0 so self-hosted installations can upgrade a PHP Server
database (layouts 2.5.14 through 2.11.8) in place. It gets no further
investment beyond what 3.0.0 ships and is planned for deprecation in the 3.1.x
line (operator decision, November 1, 2026, recorded on
[#325](https://github.com/durable-workflow/server/issues/325)). Upgrade from
PHP while running 3.0.x; the release that deprecates takeover will say so in
its release notes. New installations start on a fresh native database and
never take over a PHP one.

## Before you start

- **PHP Server 2.5.14 or later.** 2.5.14 is the first release with the
  ownership fence, so a PHP role that restarts later refuses the upgraded
  database. Earlier releases lack the fence. Inspection reads the schema, not
  the installed PHP version, so it cannot confirm the fence: make sure every
  PHP image that can reach the database is 2.5.14 or later.
- **A stop window.** Every PHP role that can write stays stopped from step 1
  to the end: HTTP, queue workers, the scheduler, and any bootstrap or
  migration job. Takeover reads every row twice. Plan the window from
  `elapsed_ms` in a fingerprint report. Fingerprint and takeover each have a
  one-hour limit, and the read-only inspections 30 seconds.
- **A backup you can restore**, using the
  [backup procedure](self-hosted-backup-and-restore.md), and a second
  database (or file) to restore it into.
- **Database rights.** The inspections and fingerprints can use a read-only
  role. On PostgreSQL the takeover needs the role that owns the tables, because
  it locks every table `ACCESS EXCLUSIVE` and runs DDL. On MySQL and MariaDB it
  needs DDL rights and the `PROCESS` privilege, so it can check that no other
  session uses the database.
- **PHP's queue settings.** The takeover binds each queued PHP job to its
  durable task, so it needs the values PHP ran with:

  | Variable | Value |
  | --- | --- |
  | `DW_RUST_UPGRADE_QUEUE_CONNECTION` | The Laravel queue connection whose `jobs` table is in this database, normally `database`. |
  | `DW_RUST_UPGRADE_QUEUE_DEFAULT_CONNECTION` | PHP's `QUEUE_CONNECTION`. |
  | `DW_RUST_UPGRADE_QUEUE_DEFAULT_QUEUE` | The default queue name on that connection, normally `default`. |

  `upgrade-queue-inspect` and `upgrade-takeover` require all three.
- **State native refuses.** Let PHP finish these, or remove them, before the
  stop. The inspection reports count them without naming rows:
  - any external payload reference (`payload_inspection`,
    `stream_reference_inspection`, or rows in `runtime_external_payloads`), or
    a namespace storage policy that names a driver other than `local`
    (`storage_inspection`);
  - workflow-to-workflow messages (`workflow_messages`);
  - a service-call dispatch that is still pending, or an open service call
    bound to a workflow run (`service_call_dispatch_inspection`);
  - a pending 2.11.8 external payload deletion native could never finish
    (`external_payload_deletion_inspection`);
  - a live schedule native cannot run (`schedule_inspection`, see
    [Schedules](#schedules)).

## Procedure

The native image carries every command. Native releases publish to the PHP
image's repositories, `ghcr.io/durable-workflow/server` and
`durableworkflow/server`, as major version 3. Use the newest 3.0.x release.
Every command uses one exact image: the multi-architecture index digest from
that release's notes, never a tag. A tag can move between steps; a digest
cannot. Keep the digest with the backup. A published image needs no opt-in.
Only a development build without a release version requires
`DW_RUST_EXPERIMENTAL=1`.

```sh
export IMAGE=ghcr.io/durable-workflow/server@sha256:<index digest from the release notes>
docker pull "$IMAGE"
```

The commands read the same `DB_*` settings as the PHP image. They are shown for
bash and PostgreSQL. MySQL is the same with `DB_CONNECTION=mysql` (or
`mariadb`). `-e DB_PASSWORD` and `-e DW_AUTH_TOKEN` pass values from the
shell's environment, so export them first (for example `read -rs DB_PASSWORD;
export DB_PASSWORD`) rather than writing a credential on a command line. Add
`--network` when the database is only reachable on a Docker network.

```sh
source="-e DB_CONNECTION=pgsql -e DB_HOST=db -e DB_PORT=5432 \
  -e DB_DATABASE=workflow -e DB_USERNAME=workflow -e DB_PASSWORD"
queues="-e DW_RUST_UPGRADE_QUEUE_CONNECTION=database \
  -e DW_RUST_UPGRADE_QUEUE_DEFAULT_CONNECTION=database \
  -e DW_RUST_UPGRADE_QUEUE_DEFAULT_QUEUE=default"
```

1. **Stop PHP.** Stop every PHP role that can write: HTTP, queue workers, the
   scheduler and any bootstrap. Keep them stopped.
2. **Inspect.** Each command prints one JSON report and must say
   `"status":"inspected"` with a `source_layout` (one of `frozen`,
   `php-2.8.0`, `php-2.9.7`, `php-2.11.1`, `php-2.11.3`, `php-2.11.8` or a
   `-from-2.9.7` variant, with `+mariadb-driver` appended when PHP used
   Laravel's `mariadb` connection).

   ```sh
   docker run --rm $source "$IMAGE" upgrade-inspect
   docker run --rm $source $queues "$IMAGE" upgrade-queue-inspect
   docker run --rm $source "$IMAGE" upgrade-payload-inspect
   ```

   `upgrade-queue-inspect` must also report
   `queue_inspection.status: "known_subset_bound"`. A report with
   `"status":"unqualified"` exits non-zero. Its stderr always reads
   `upgrade_source_payload_metadata_unqualified`, so find the actual cause in
   the report's `*_inspection` sections. A refusal before any report prints
   only a reason (see [Refusals](#refusals)).
3. **Back up and prove the backup.** Back up with the
   [existing procedure](self-hosted-backup-and-restore.md), restore it into a
   separate database (here `workflow_restored`), and fingerprint both. The
   two `fingerprint` values (`sha256:` and 64 hex digits) must be equal. That
   proves the backup restores to exactly the stopped source. Fingerprint
   with the same database server version as the source.

   ```sh
   docker run --rm $source "$IMAGE" upgrade-fingerprint
   docker run --rm $source -e DB_DATABASE=workflow_restored "$IMAGE" upgrade-fingerprint
   ```
4. **Take over** with that digest and the owner credentials. Native refuses
   unless the source still has the digest inside the takeover's own exclusive
   transaction. A write after the backup therefore stops the takeover before
   any change.

   ```sh
   docker run --rm $source $queues -e DW_UPGRADE_BACKUP_FINGERPRINT=sha256:<digest> \
     "$IMAGE" upgrade-takeover
   ```
5. **Start native** on the taken-over database. Use the same tokens as PHP
   (`DW_AUTH_TOKEN` and any `DW_WORKER_TOKEN`, `DW_OPERATOR_TOKEN` or
   `DW_ADMIN_TOKEN`, under their `DW_*` or legacy `WORKFLOW_SERVER_*` names),
   so workers reconnect unchanged. Runtime credentials PHP issued keep
   working when `DW_RUNTIME_CREDENTIALS_ENABLED` is on, as it was under PHP. Pass the same `DW_SERVER_KEY` (or `APP_KEY`) too, so schedule
   list continuation tokens stay valid.

   ```sh
   docker run -d -p 127.0.0.1:8080:8080 $source -e DW_AUTH_TOKEN "$IMAGE"
   ```

   This publishes the API on the host's loopback interface only. The API is
   plain HTTP with bearer credentials. For remote access, publish it only to
   the intended private interface or network and terminate HTTPS in the
   reverse proxy the [Server reference](server-reference.md) describes. Never
   expose the raw API port to an untrusted network. The image runs the
   maintenance loop itself (`DW_RUST_MAINTENANCE_LOOP=1`), so there is no
   separate worker, scheduler or Redis.
6. **Do not restart PHP** against the database. Its ownership check refuses
   it.

### SQLite

The native image uses the PHP image's paths: SQLite at
`/app/database/database.sqlite` and local external payload objects under
`/app/storage/app/external-payloads/<namespace>`. Mount the PHP installation's
volumes at the same paths. The PHP image writes them as `www-data`, and native
runs as UID and GID 1000. Once PHP is stopped, give both volumes to 1000 (for
example `chown -R 1000:1000`). Ownership is not part of the fingerprint.

PHP keeps SQLite in WAL mode, and the upgrade commands need the rollback
journal. With PHP stopped, run `upgrade-prepare-sqlite` once on a writable
mount. It checkpoints every committed WAL frame into the file, so the rows do
not change, and it refuses while another process holds the file. The
inspections and fingerprints then need a read-only mount (`:ro`). They refuse
a writable one with `upgrade_source_readonly_filesystem_required`. The
takeover needs it writable again.

```sh
db="-v <php-database-volume>:/app/database"
sqlite="-e DB_CONNECTION=sqlite -e DB_DATABASE=/app/database/database.sqlite"
docker run --rm $db $sqlite "$IMAGE" upgrade-prepare-sqlite
docker run --rm $db:ro $sqlite "$IMAGE" upgrade-inspect
docker run --rm $db:ro $sqlite $queues "$IMAGE" upgrade-queue-inspect
docker run --rm $db:ro $sqlite "$IMAGE" upgrade-payload-inspect
docker run --rm $db:ro $sqlite "$IMAGE" upgrade-fingerprint
```

The backup is a byte copy of the prepared file. Fingerprint the copy the same
way, from a read-only mount, and compare. Then take over:

```sh
docker run --rm $db $sqlite $queues -e DW_UPGRADE_BACKUP_FINGERPRINT=sha256:<digest> \
  "$IMAGE" upgrade-takeover
```

## Success

`upgrade-takeover` exits 0 and prints one report:

```json
{"schema":"durable-workflow.rust-upgrade-takeover.v1","status":"taken_over",
 "backend":"pgsql","source_layout":"php-2.11.8","fingerprint":"sha256:…",
 "backup_fingerprint_matched":true,"table_rows":{…},"converted":{…},
 "nested_payloads_verified":false,"other_root_payload_columns_verified":false,
 "stored_representation_compatibility_verified":false}
```

Check that `status` is `taken_over`, that `fingerprint` equals your backup's
digest and that `backup_fingerprint_matched` is `true`. `converted` counts what
was carried into native's own tables, such as worker registration
incarnations, service-call deadlines, session holders, queue delivery
references, trace carriers and 2.11.8 pending deletions. The three
`*_verified: false` fields are constants that record checks native cannot make
yet. They are not failures. MySQL and MariaDB report `backend` as `mysql` or
`mariadb`.

Then native must start and answer `GET /api/ready`, and your workers must
reconnect. Workflows that were running, timers and leased activities continue
under native, as the CI cohort checks. A lease that expired during the stop is
redelivered, and the stale attempt is refused.

## Refusals

A refusal prints one reason on stderr and exits non-zero. Before the marker,
nothing has changed. On SQLite and PostgreSQL the takeover is one transaction,
so every refusal or interruption leaves the PHP database exactly as it was. On
MySQL and MariaDB, see `native_takeover_incomplete` below.

| Reason | Meaning | What to do |
| --- | --- | --- |
| `upgrade_backup_fingerprint_required` | `DW_UPGRADE_BACKUP_FINGERPRINT` is missing or not `sha256:` plus 64 hex digits. | Pass the digest from step 3. |
| `upgrade_backup_fingerprint_mismatch` | The source no longer matches the backup: something wrote after it, or the wrong database is named. On MySQL, a rerun with a different digest than the interrupted run. | Find and stop the writer, then take a new backup and verify it again. On MySQL, rerun with the original digest. |
| `upgrade_source_in_use` | Another session holds a table (PostgreSQL) or uses the database (MySQL). | Stop it, including pools, consoles and monitoring, then rerun. |
| `upgrade_source_sessions_unverifiable` | MySQL/MariaDB: the role lacks `PROCESS`. | Use a role with `PROCESS`. |
| `upgrade_source_catalog_unqualified` | The schema matches no recorded PHP layout: an unpublished release, a manual change or a history with no recipe. | Do not edit the schema to fit. Keep running PHP and report the inspection's `catalog_sha256`. |
| `upgrade_source_already_owned` | Native already owns the database. | Nothing to take over. Use `schema-upgrade` for later native releases. |
| `upgrade_source_queue_config_required`, `upgrade_source_queue_binding_unqualified`, `upgrade_source_queue_*` | The queue variables are missing or do not match PHP's, or a queued job does not bind to its durable task. | Set the three variables to PHP's values. If it still refuses, let PHP drain its queue, then back up again. |
| `upgrade_source_external_payloads_unsupported`, `upgrade_source_external_payload_storage_unsupported`, `upgrade_source_external_payload_deletions_unsupported` | External payload references, a non-`local` storage policy, or a pending deletion native could never finish. Out of scope for 3.0.x. | Stay on PHP, or remove that state first. |
| `upgrade_source_workflow_messages_unsupported` | The source holds workflow-to-workflow messages, which native does not deliver yet. | Let them be consumed first. |
| `upgrade_source_service_call_dispatches_pending`, `upgrade_source_service_calls_workflow_bound_open` | Service-call work native cannot resume ([#794](https://github.com/durable-workflow/server/issues/794)). | Let PHP finish the dispatches and the linked runs, or cancel the calls, before the stop. |
| `upgrade_source_schedules_unsupported` | A live schedule native cannot run (see [Schedules](#schedules)). | Change or delete it under PHP first. |
| `upgrade_source_readonly_filesystem_required`, `upgrade_source_sqlite_journal_unqualified` | SQLite: inspection needs a read-only mount, or the file is still in WAL mode. | Mount it `:ro`, or run `upgrade-prepare-sqlite` first. |
| `upgrade_source_row_security_unqualified` | PostgreSQL row-level security hides rows from the inspecting role. | Inspect with a role that sees every row. |
| `upgrade_inspection_timeout` | A command exceeded its time limit. | Use a larger host or run closer to the database. The takeover inspects again within its own one-hour limit. |
| `native_takeover_incomplete` (at native startup) | MySQL/MariaDB only: a takeover stopped part way. PHP already refuses the database from the first step. | Rerun `upgrade-takeover` with the same digest. It resumes after the last recorded step. |
| `upgrade_takeover_backend_unqualified` | `DB_CONNECTION` is not `sqlite`, `pgsql`, `mysql` or `mariadb`. | Fix `DB_CONNECTION`. |

## Rollback

There is no PHP rollback after a successful takeover, and no mixed PHP and
native fleet. To go back, restore the verified backup into a new database and
run PHP 2.11.8 (or the PHP release you came from) against it. The data
boundary is that backup: anything native accepted after the takeover is not
in it.

## Later native releases

Takeover runs once, from PHP. A database that native already owns moves to a
release with a newer native schema through `schema-upgrade` instead: stop
every native node, back up the database, run the new image once with
`schema-upgrade` and the same `DB_*` settings, then start the new release. A
node never upgrades at startup; it refuses an older schema with
`native_schema_upgrade_required` and a newer one with
`native_schema_newer_than_binary`. Restore the backup to go back. Details are
in [schema upgrades](../rust/README.md#native-schema-upgrades).

## Design

The rest of this page records how the takeover works and why. The procedure
above is what an operator runs.

### Shape

Takeover is an explicit command, never a side effect of starting the server.
Native startup keeps refusing a PHP or unknown database.

1. The installation runs Server 2.5.14 or later, the first release with the
   ownership fence, so any PHP role that restarts later refuses the upgraded
   database. The operator then stops every PHP write-capable role: HTTP,
   bootstrap and migration, queue workers and the scheduler.
2. `upgrade-fingerprint` prints a digest of the database's complete logical
   contents (below).
3. The operator takes a backup with the
   [existing procedure](self-hosted-backup-and-restore.md), restores it into a
   separate database, and runs `upgrade-fingerprint` there. Equal digests show
   that the backup restores to exactly the stopped database.
4. `upgrade-takeover` runs with `DW_UPGRADE_BACKUP_FINGERPRINT` set to that
   digest. It refuses unless the live database still has that digest inside
   the same exclusive transaction that writes the ownership marker. A write
   after the backup, by PHP or anything else, changes the digest and stops the
   takeover before any mutation.
5. Native starts normally on the upgraded database. PHP refuses it from the
   marker onward ([PHP ownership checks](php-database-ownership.md)). Earlier
   PHP releases lack that check, which is why step 1 requires 2.5.14.

There is no PHP rollback after step 4. Recovery restores the verified backup
into a new database for a PHP installation, with the data boundary of that
backup.

### Fingerprint

The digest covers every relation in the database, not only the ones PHP
knows: the sorted relation names, each relation's column names and declared
types, and every row in primary-key order (all columns when there is no
primary key). Values are encoded canonically, so a dump and restore reproduces
the same bytes: integers in decimal, text and JSON as stored text, timestamps
at their stored precision, binary as hex, NULL distinct from empty. It is read
in one read-only snapshot (`READ_SNAPSHOT_BEGIN`). The command reports the
digest, the per-relation row counts and the elapsed time, never row contents.

Takeover reads every row once more, inside its exclusive transaction. That
cost is accepted for a one-time operation. Its duration is reported so an
operator can plan the stop window from the fingerprint run.

External payload objects are not in the database. Until a qualified object
check exists, takeover refuses when `upgrade-payload-inspect` reports any
external reference, and the operator's object manifest from the backup
procedure remains the record for those bytes.

### Schedules

Native runs PHP's schedules: cron expressions and intervals (with offsets),
time zones, every overlap policy including buffering, jitter, run limits,
memo, notes and namespaces (`rust/src/runtime/upgrade/schedule_scan.rs`).
Takeover still refuses a live schedule that carries search attributes or
visibility labels, routes to a Laravel queue (`connection` or `queue`), has a
status other than active or paused, or has a definition native's own schedule
creation refuses. Examples of the last are an action that names a PHP workflow
class instead of a workflow type, input that is not a payload envelope, and a
specification native cannot evaluate. Every inspection reports
`schedule_inspection`: the number of live (not deleted) schedules and, by
reason, how many native cannot run, without naming them. Such a schedule would
stop firing or lose fields under native, so its inspection is `unqualified`
and takeover refuses with `upgrade_source_schedules_unsupported` before any
change. Deleted schedules keep only their audit history and do not block
takeover.

### Other refused or carried state

Native serves external payload storage only through the `local` driver.
Every inspection also reports `storage_inspection`: the number of namespaces
and, by reason, how many have an enabled policy naming another driver
(`s3`, `gcs`, `azure`, `custom` or an unknown one), without naming them.
Native would refuse every upload in such a namespace with 503, so its
inspection is `unqualified` and takeover refuses with
`upgrade_source_external_payload_storage_unsupported` before any change. A
disabled policy does not block takeover.

Inbound message streams and durable workflow streams carry over unchanged:
native serves them from PHP's own tables. Each closed or errored durable
stream gets the reclaim deadline native's retention pass reads,
`dw_stream_reclaims`: PHP 2.11.3's recorded `reclaim_at`, or for earlier
releases its `closed_at` plus its retention (an hour unless set), as 2.11.3's
migration backfills it. Every inspection reports
`stream_reference_inspection`: the number of stream items, durable streams
and inbound messages holding an external payload reference. Native does not
resolve those, so any such row makes the inspection `unqualified` and takeover
refuses with `upgrade_source_external_payloads_unsupported`.
Workflow-to-workflow messages (`workflow_messages`) are not delivered by
native yet, so a source holding any refuses with
`upgrade_source_workflow_messages_unsupported`.

PHP 2.11.3 (Core 2.14) schedules a service call's handler in the
`workflow_service_call_dispatches` outbox and dispatches it in a later pass.
Native runs the handler when it accepts the call, so it cannot resume a
dispatch that is still scheduled or claimed. Every inspection reports
`service_call_dispatch_inspection`: the number of dispatches and of pending
ones, without naming calls. A pending dispatch makes the inspection
`unqualified`, and takeover refuses with
`upgrade_source_service_call_dispatches_pending` before any change. Let PHP
finish them before the stop. Completed dispatches do not block takeover.

The same report counts open service calls bound to a workflow run
(`workflow_bound_open`): calls a Nexus workflow-bound handler accepted that are
still `pending`, `accepted` or `started`. PHP closes such a call when its linked
run ends, and 2.11.5's maintenance pass repairs one it missed. Native serves no
workflow-bound handler yet (Server #794) and closes no linked call, so one
would stay open after takeover. Any makes the inspection `unqualified`, and
takeover refuses with `upgrade_source_service_calls_workflow_bound_open` before
any change. Let the linked runs finish, or cancel the calls, before the stop.

### Exclusive transaction per backend

The marker, the fingerprint check and the schema changes must not interleave
with any other writer.

| Backend | Exclusion | Atomicity |
| --- | --- | --- |
| SQLite | `BEGIN EXCLUSIVE` on the database file | Fingerprint, marker, native relations and conversions commit in one transaction. |
| PostgreSQL | Native's bootstrap advisory key, then `LOCK TABLE … IN ACCESS EXCLUSIVE MODE` on every table, with a short lock wait. The later DDL needs that mode anyway, so no lock is upgraded while another session waits. | DDL is transactional, so everything commits in one transaction. |
| MySQL and MariaDB | Native's bootstrap lock and a check that no other session uses the database (which needs the `PROCESS` privilege), repeated after the marker is written. `LOCK TABLES` cannot be used: under it MySQL refuses to create or drop any table it does not hold. | DDL commits implicitly, so the marker records a step journal (below). |

On SQLite and PostgreSQL, a PHP connection that slipped past the stop blocks on
these locks, and a write it committed earlier changes the fingerprint. Neither
leaves a mixed state.

### Marker and interruption

On SQLite and PostgreSQL the marker commits with everything else, so an
interruption leaves the database unchanged PHP and nothing needs a journal.
The operator reruns `upgrade-takeover` with the same fingerprint. A killed
SQLite takeover leaves a rollback journal; read-only inspection refuses it,
and the rerun lets SQLite roll it back before the fingerprint check.

MySQL and MariaDB commit DDL implicitly, so there native's marker table is
written first, with one row `('rust-takeover:<layout>', <completed steps>,
<fingerprint digest>)`. Native startup refuses it with
`native_takeover_incomplete`. The sessions and the fingerprint are checked
again with the marker set aside, then each step runs and records its
completion in that row; row changes commit with their step, and DDL steps
check what is left to do. Rerunning `upgrade-takeover` with the same
fingerprint resumes after the last recorded step; a different fingerprint is
refused. The ledger and native's final marker `('rust-development', 4,
<checksum>)` commit together. The PHP fence refuses the database from the
first step, because the marker table exists.

### SQLite sources in WAL mode

PHP Server keeps SQLite in WAL mode, and a read-only SQLite open can still
create WAL and shared-memory sidecars, so inspection, fingerprint and takeover
require the rollback journal. With PHP stopped, `upgrade-prepare-sqlite` opens
the file as its only connection and switches it to the rollback journal.
SQLite first checkpoints every committed WAL frame into the file, so the stored
rows are unchanged. The command refuses while another process holds the file,
and runs before the fingerprint and the backup.

### Steps

1. Refuse unless every inspection command reports `qualified` for this
   database and the source catalog matches a qualified published PHP release.
2. Check the fingerprint (above) and write the marker.
3. Create the native relations and indexes (`upgrade.rs`, `native_object`).
4. Convert or adopt stored representations the inspections qualified:
   poll-request bindings in task payloads, worker registrations (an
   incarnation per active registration), cached query state, and queued
   timer wakeups mapped to their durable task rows. Anything not qualified
   was already refused by step 1.
5. Record the native migration ledger and set the marker to `ready`.

Native's frozen physical layout predates the current PHP release, so step 3
alone does not yield a database native accepts. PHP 2.8.0 adds its own
`workflow_worker_registration_incarnations` table, a `registration_token`
column on `workflow_worker_registrations` and a `call_deadline_at` column on
`workflow_service_calls` (`rust/upgrade/php-2.8.0/`). Server 2.9.7, with Core
2.7.2, also adds `workflow_tasks.queue_delivery_reference`. Native keeps its
own layout, and step 4 converts each qualified PHP release's layout to it
with a recorded recipe:

- PHP's registration incarnations and tokens are copied into native's
  incarnation table, so a running SDK worker's fenced deregistration still
  matches. The PHP table and column are then removed.
- `queue_delivery_reference` is optional lookup metadata for PHP's database
  queue delivery, and PHP itself grants it no task authority. Native does not
  use PHP's queue, and the queue inspection has already bound every queued
  job to its durable task row, so the column is removed and the takeover
  report counts the references it held.
- Each service call's `call_deadline_at` is copied into native's
  `dw_service_call_deadlines` before the column is removed.
- Later releases add their own recipes: 2.11.1's trace carriers, and
  2.11.3's session holders, full failure messages, idempotency digest,
  dispatch outbox and stream reclaim deadlines. The layout READMEs under
  `rust/upgrade/` and the port checkpoints describe each one. Published
  2.11.4, 2.11.5 and 2.11.7 add no migration, so their databases have the
  2.11.3 layouts and the same recipe. 2.11.8 adds pending external payload
  deletions, which takeover carries over to native's own table without
  deleting any object, refusing one native could never finish (a saved policy native cannot
  execute, or an unknown failure).

A release whose layout has no recipe is refused by step 1.

### Tests

Each backend gets an upgrade fixture written by the last published PHP release
with running and waiting workflows, a pending timer, a leased activity, an
acknowledged signal and an expired lease. The tests check:

- refusal without a fingerprint, with a stale one, and after one extra row;
- interruption after each step, then resumption to the same final state;
- native completes the original work with its original histories, deadlines
  and identities, and refuses the stale attempt;
- PHP refuses the upgraded database at startup, bootstrap, migration, worker
  and scheduler entry points;
- a backup restored into a new database fingerprints equal to the stopped
  source.

### Open questions

- Whether the declared column types print identically after a restore into
  another minor server version. Until that is shown, the restore check uses
  the same server version as the source.
- How long the fingerprint takes on a large installation, measured on a
  disposable host before the stop window is documented.
