# Native upgrade takeover

`durable-workflow-server upgrade-takeover` moves an existing PHP Server 2.x
database to the native 3.x server, once and in place. It is backup-first and
one-way: it checks that the database still matches a backup you have
verified, and after it commits, PHP refuses the database. The command ships
in the native 3.0.0, 3.0.1 and 3.0.2 images.

> **Not yet qualified on a published image.** The command ships, and CI
> qualifies it on source builds of the release for the PHP layouts and
> database backends listed under [Status and scope](#status-and-scope). No
> published 3.0.x image has yet taken over a real PHP installation in an
> installed acceptance, and Security has not accepted the takeover boundary as
> a whole. Source CI does not substitute for published-artifact conformance,
> so this page is not a supported upgrade path yet.
>
> Wait for that qualification to be recorded here before you take over a live
> database. Until then, keep running PHP Server 2.11.8, or rehearse the
> commands only on a restored copy of your backup (see
> [Rehearsing on a restored copy](#rehearsing-on-a-restored-copy)).

The commands, their environment, the success report and every refusal are
documented below so a rehearsal can be run and reviewed, and so the installed
acceptance exercises exactly this handoff. The design follows under
[Design](#design).

## Status and scope

**What CI covers.** The *Shared Server fixtures* workflow runs a debug build of
the release source. It backs up a database written by a published PHP image,
takes over a restored copy, resumes the PHP-era work under native and checks
that PHP then refuses the database. It passed on the 3.0.0 source in
merge-group run
[41459209091](https://github.com/durable-workflow/server/actions/runs/41459209091)
and on the 3.0.2 source in run
[41562369513](https://github.com/durable-workflow/server/actions/runs/41562369513).
These are source builds, not the published images.

| Covered | How |
| --- | --- |
| Databases created by published PHP 2.8.0, 2.9.7, 2.11.1, 2.11.3, 2.11.7 and 2.11.8 | End to end on SQLite, two PostgreSQL images (one is PostgreSQL 17), two MySQL 8 images (one is 8.0) and MariaDB 10.11: inspect, fingerprint, back up and restore, take over, resume a timer, an activity, a signal and an expired lease, refuse the stale attempt, and PHP's refusal afterwards (`scripts/conformance/server-parity/upgrade-source-profile.sh`). |
| 2.11.4 and 2.11.5 | They add no migration, so their databases have the 2.11.3 layout that is tested above. |
| 2.9.7 databases later migrated by 2.11.1, 2.11.3 or 2.11.8 (the `-from-2.9.7` layouts) | Catalogs captured from real migrated databases, plus backend qualification tests on PostgreSQL, MySQL and MariaDB (`rust/src/runtime/{postgres,mysql}.rs`) and SQLite unit tests. No end-to-end cohort. |
| The 2.5.14 layout (`source_layout: "frozen"`) | Backend qualification tests and SQLite unit tests on a database built from that layout. No end-to-end cohort from a real 2.5.14 installation. |
| Sources PHP wrote through Laravel's `mariadb` connection (`+mariadb-driver` layouts) | Derived from their one known difference, the backup-hold owner column, and covered by a MariaDB backend qualification test. Not captured separately for 2.11.8. |
| Refusals and interruption | SQLite and PostgreSQL: a refusal or a kill before commit rolls the whole takeover back, and a rerun with the same digest gives the same result. MySQL and MariaDB: refusals before the marker leave the database unchanged, and a takeover killed before the marker row or after journal steps 1, 3, 8 and 9 resumes with the same digest to the same result. A refusal after the MySQL or MariaDB marker leaves the marker in place; see [Refusals](#refusals). |

Security reviewed the 2.11.8 layout change and its refusal boundary, and
accepted the exact head of
[#921](https://github.com/durable-workflow/server/pull/921#issuecomment-6561043224).
That acceptance covers that change, not the takeover as a whole.

**What is not qualified yet.**

- No installed acceptance has run `upgrade-takeover` from a *published* 3.0.x
  image against a real PHP installation, including the native start that
  follows it. The release smoke test only checks that the commands are present
  and refuse a missing source. There is no independent Security acceptance of
  the takeover as a whole.
- Database histories other than those above are accepted only when
  inspection matches a recorded layout exactly. Otherwise they are refused.
- Some state is always refused (see [Before you start](#before-you-start)):
  external payload references, workflow-to-workflow messages, pending
  service-call dispatches, open workflow-bound service calls and some
  schedules. The operator decided on November 1, 2026 to leave external
  payload takeover out of 3.0.x
  ([#325](https://github.com/durable-workflow/server/issues/325#issuecomment-6559827982)).

**The inspect commands always report `takeover_allowed: false`.** This is by
design. `upgrade-inspect`, `upgrade-queue-inspect`, `upgrade-payload-inspect`
and `upgrade-fingerprint` only read. They cannot prove that PHP is stopped,
that a backup restores or that nothing writes afterwards. So their
`takeover_allowed`, `backup_verified`, `php_quiescence_verified` and
`stored_representation_compatibility_verified` fields are constant `false`.
Only `upgrade-takeover` decides, inside its own exclusive transaction or, on
MySQL and MariaDB, its journal. Read `status` and `source_layout` instead.
Likewise, `qualified_for_php_takeover: false` in `/api/cluster/info` is a
constant. It describes the serving process, which never takes over a database
at startup, and does not predict what `upgrade-takeover` will do.

## Deprecation

Takeover ships in 3.0.0 so self-hosted installations can upgrade a PHP Server
database (layouts 2.5.14 through 2.11.8) in place. It gets no further
investment beyond what 3.0.0 ships and is planned for deprecation in the 3.1.x
line (operator decision, November 1, 2026, recorded on
[#325](https://github.com/durable-workflow/server/issues/325)). The release
that deprecates takeover will say so in its release notes. New installations
start on a fresh native database and never take over a PHP one.

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
  database (or file) to restore it into. On MySQL and MariaDB this backup is
  also the only way back from a refusal after the marker (see
  [Refusals](#refusals)).
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
- **PHP's serving settings**, to start native with afterwards: the tokens,
  the runtime-credentials switch and the application key (see
  [Environment](#environment)).
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

## Commands

The native image carries every command. Native releases publish to the PHP
image's repositories, `ghcr.io/durable-workflow/server` and
`durableworkflow/server`, as major version 3. Every command uses one exact
image: the multi-architecture index digest from that release's notes, never a
tag. A tag can move between steps; a digest cannot. Keep the digest with the
backup. A published image needs no opt-in. Only a development build without
a release version requires `DW_RUST_EXPERIMENTAL=1`.

### Environment

Define the environment once, in one bash session, and use it for every
command. `-e NAME` without a value passes `NAME` from the shell's environment,
so no credential appears on a command line. Docker leaves out a variable that
is unset in the shell, so leave unset (not empty) any setting PHP did not
use. Read each secret without echoing it, for example
`read -rs DB_PASSWORD; export DB_PASSWORD`.

```sh
export IMAGE=ghcr.io/durable-workflow/server@sha256:<index digest from the release notes>
docker pull "$IMAGE"

# The database, under the same DB_* names and values PHP used. Shown for
# PostgreSQL; MySQL is DB_CONNECTION=mysql (or mariadb) and DB_PORT=3306.
export DB_CONNECTION=pgsql DB_HOST=db DB_PORT=5432 DB_DATABASE=workflow DB_USERNAME=workflow
read -rs DB_PASSWORD; export DB_PASSWORD
db="-e DB_CONNECTION -e DB_HOST -e DB_PORT -e DB_DATABASE -e DB_USERNAME -e DB_PASSWORD"
# If PHP set them, export them too and append to db: -e DB_URL -e DB_SSLMODE -e DB_SSLROOTCERT -e MYSQL_ATTR_SSL_CA

# PHP's queue binding, for upgrade-queue-inspect and upgrade-takeover.
queues="-e DW_RUST_UPGRADE_QUEUE_CONNECTION=database \
  -e DW_RUST_UPGRADE_QUEUE_DEFAULT_CONNECTION=database \
  -e DW_RUST_UPGRADE_QUEUE_DEFAULT_QUEUE=default"

# Native's serving environment: exactly what PHP served with.
read -rs DW_AUTH_TOKEN; export DW_AUTH_TOKEN          # required
read -rs DW_WORKER_TOKEN; export DW_WORKER_TOKEN      # only if PHP set it
read -rs DW_OPERATOR_TOKEN; export DW_OPERATOR_TOKEN  # only if PHP set it
read -rs DW_ADMIN_TOKEN; export DW_ADMIN_TOKEN        # only if PHP set it
export DW_RUNTIME_CREDENTIALS_ENABLED=<PHP's value>   # only if PHP set it
read -rs DW_SERVER_KEY; export DW_SERVER_KEY          # PHP's DW_SERVER_KEY, else its APP_KEY
# A setting that is set but empty is not the same as unset: unset the empty ones.
for v in DW_WORKER_TOKEN DW_OPERATOR_TOKEN DW_ADMIN_TOKEN DW_RUNTIME_CREDENTIALS_ENABLED DW_SERVER_KEY; do
  [ -n "${!v-unset}" ] || unset "$v"
done
serve="$db -e DW_AUTH_TOKEN -e DW_WORKER_TOKEN -e DW_OPERATOR_TOKEN -e DW_ADMIN_TOKEN \
  -e DW_RUNTIME_CREDENTIALS_ENABLED -e DW_SERVER_KEY"
```

Add `--network <name>` to every command when the database is only reachable
on a Docker network.

What the serving settings preserve, as native reads them
(`rust/src/main.rs`, `rust/src/runtime/schedule_list.rs`):

- **Tokens.** Native reads each token under its `DW_*` name, else its legacy
  `WORKFLOW_SERVER_*` name (`WORKFLOW_SERVER_AUTH_TOKEN`,
  `WORKFLOW_SERVER_WORKER_TOKEN`, `WORKFLOW_SERVER_OPERATOR_TOKEN`,
  `WORKFLOW_SERVER_ADMIN_TOKEN`). If PHP used a legacy name, export its value
  under the `DW_*` name above. With the same tokens, workers and clients
  reconnect unchanged. `DW_AUTH_TOKEN` is required.
- **Runtime credentials.** Credentials PHP issued are stored hashed in the
  database and keep working only when `DW_RUNTIME_CREDENTIALS_ENABLED` (or
  the legacy `WORKFLOW_SERVER_RUNTIME_CREDENTIALS_ENABLED`) is on, as it was
  under PHP. Unset, native disables them, and workers that use one are
  refused.
- **Application key.** Schedule-list continuation tokens are signed with the
  application key. PHP's image takes it from `DW_SERVER_KEY`, else `APP_KEY`,
  and native reads the same two in the same order. Set `DW_SERVER_KEY` to the
  value PHP used under either name. If PHP had neither, it generated a
  container-local key, so its tokens did not survive a PHP restart either;
  native then signs with `DW_AUTH_TOKEN`, and only outstanding schedule-list
  page tokens become invalid.

Native audits its environment at startup and refuses to start on a PHP
setting it cannot honor. Pass other PHP settings only after checking them in the
[Server reference](server-reference.md).

### Rehearsing on a restored copy

A rehearsal is the same commands pointed at a restored copy of the backup: a
separate database (for example `DB_DATABASE=workflow_rehearsal`) or a copied
SQLite file. Its fingerprint equals the backup's, so the takeover accepts it,
and the live database is never touched. The native you start on the copy
resumes PHP-era work: timers fire and expired leases are redelivered. Run it
with no production workers connected and on a port nothing else uses, then
remove the copy.

### Step by step

1. **Stop PHP.** Stop every PHP role that can write: HTTP, queue workers, the
   scheduler and any bootstrap. Keep them stopped.
2. **Inspect.** Each command prints one JSON report and must say
   `"status":"inspected"` with a `source_layout` (one of `frozen`,
   `php-2.8.0`, `php-2.9.7`, `php-2.11.1`, `php-2.11.3`, `php-2.11.8` or a
   `-from-2.9.7` variant, with `+mariadb-driver` appended when PHP used
   Laravel's `mariadb` connection).

   ```sh
   docker run --rm $db "$IMAGE" upgrade-inspect
   docker run --rm $db $queues "$IMAGE" upgrade-queue-inspect
   docker run --rm $db "$IMAGE" upgrade-payload-inspect
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
   docker run --rm $db "$IMAGE" upgrade-fingerprint
   docker run --rm $db -e DB_DATABASE=workflow_restored "$IMAGE" upgrade-fingerprint
   ```
4. **Take over** with that digest and the owner credentials (override
   `DB_USERNAME` and `DB_PASSWORD` for this one command if PHP's role is not
   the owner). The takeover checks the digest again before it converts
   anything, so a write after the backup refuses it. What a refusal leaves
   behind depends on the backend: on SQLite and PostgreSQL the database is
   unchanged; on MySQL and MariaDB a write that lands between the takeover's
   first check and its marker is caught only by the second check, after the
   marker, and then needs the backup (see [Refusals](#refusals)).

   ```sh
   export DW_UPGRADE_BACKUP_FINGERPRINT=sha256:<digest>
   docker run --rm $db $queues -e DW_UPGRADE_BACKUP_FINGERPRINT "$IMAGE" upgrade-takeover
   ```
5. **Start native** on the taken-over database with the full serving
   environment and its own storage volume.

   ```sh
   docker run -d --name durable-workflow-native $serve \
     -v durable-workflow-native-storage:/app/storage \
     -p 127.0.0.1:8080:8080 "$IMAGE"
   ```

   Native writes new local external payload objects under `/app/storage`.
   A new named volume starts with the image's ownership (UID and GID 1000), so
   it needs no ownership change. Do not mount PHP's storage volume here: the
   takeover refused every external payload reference, so native needs none of
   its objects, and it may hold other application state. Keep it with the
   backup.

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
`/app/database/database.sqlite`. Mount the PHP installation's database volume
at the same path. The published PHP image (2.5.14 and later) runs as
`www-data` with UID and GID 1000, which is native's identity, so a database
volume PHP wrote needs no ownership change. Native writes only the database
file and, beside it in the same directory, its rollback journal and its WAL
and shared-memory files. If you ran PHP under another `APACHE_RUN_USER` or
from a host directory owned by another UID, give UID and GID 1000 exactly the
database file and its directory, after the backup and with PHP stopped (for
example `chown 1000:1000 <dir> <dir>/database.sqlite`). Ownership is not part
of the fingerprint. Do not change the ownership of PHP's storage volume, and
do not mount it into native.

PHP keeps SQLite in WAL mode, and the upgrade commands need the rollback
journal. With PHP stopped, run `upgrade-prepare-sqlite` once on a writable
mount. It checkpoints every committed WAL frame into the file, so the rows do
not change, and it refuses while another process holds the file. The
inspections and fingerprints then need a read-only mount (`:ro`). They refuse
a writable one with `upgrade_source_readonly_filesystem_required`. The
takeover needs it writable again.

```sh
vol="-v <php-database-volume>:/app/database"
db="-e DB_CONNECTION=sqlite -e DB_DATABASE=/app/database/database.sqlite"
docker run --rm $vol $db "$IMAGE" upgrade-prepare-sqlite
docker run --rm $vol:ro $db "$IMAGE" upgrade-inspect
docker run --rm $vol:ro $db $queues "$IMAGE" upgrade-queue-inspect
docker run --rm $vol:ro $db "$IMAGE" upgrade-payload-inspect
docker run --rm $vol:ro $db "$IMAGE" upgrade-fingerprint
```

The backup is a byte copy of the prepared file. Fingerprint the copy the same
way, from a read-only mount, and compare. Then take over, and start native
with the serving environment, redefined so that it carries the SQLite `db`
and the database volume:

```sh
export DW_UPGRADE_BACKUP_FINGERPRINT=sha256:<digest>
docker run --rm $vol $db $queues -e DW_UPGRADE_BACKUP_FINGERPRINT "$IMAGE" upgrade-takeover
serve="$db -e DW_AUTH_TOKEN -e DW_WORKER_TOKEN -e DW_OPERATOR_TOKEN -e DW_ADMIN_TOKEN \
  -e DW_RUNTIME_CREDENTIALS_ENABLED -e DW_SERVER_KEY"
docker run -d --name durable-workflow-native $serve $vol \
  -v durable-workflow-native-storage:/app/storage \
  -p 127.0.0.1:8080:8080 "$IMAGE"
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
`mariadb`. A resumed MySQL or MariaDB run adds `resumed_after_step` to
`converted` and counts only the steps it ran itself; its `table_rows` is
`null` when the fenced check had already completed in the earlier run.

If `converted` counts `external_payload_deletions_copied`, native retries
those 2.11.8 deletions against its own storage. The released objects are in
PHP's storage volume, not native's, so native finds them absent and treats
them as deleted; the objects stay in PHP's volume with the backup.

Then native must start and answer `GET /api/ready`, and your workers must
reconnect. Workflows that were running, timers and leased activities continue
under native, as the CI cohort checks. A lease that expired during the stop is
redelivered, and the stale attempt is refused.

## Refusals

A refusal prints one reason on stderr and exits non-zero. What it leaves
behind depends on the backend and, on MySQL and MariaDB, on whether the
marker was written.

**SQLite and PostgreSQL.** The takeover is one transaction (`BEGIN EXCLUSIVE`
on SQLite; native's bootstrap lock and `ACCESS EXCLUSIVE` on every table on
PostgreSQL). Every refusal and every interruption rolls it back, so the
database is exactly the PHP database you backed up. Fix the cause and rerun,
or restart PHP on it. A takeover killed on SQLite leaves a `-journal` file
beside the database; the next writable open (a rerun of the takeover) rolls
it back, and the read-only inspections refuse until then.

**MySQL and MariaDB.** DDL commits implicitly, so the takeover cannot be one
transaction (`rust/src/runtime/upgrade/takeover/mysql_journal.rs`):

1. Under native's bootstrap lock it checks the sessions, then reads the
   inspections and the fingerprint in one snapshot. **A refusal here leaves
   the database unchanged.**
2. Its first write creates native's `dw_server_schema` table and records one
   row, `rust-takeover:<layout>`, holding the backup digest. This is the
   marker. From here PHP refuses the database, and native refuses to start on
   it with `native_takeover_incomplete`.
3. It checks the sessions, the inspections and the fingerprint again with the
   marker set aside (the fenced step), because a session that wrote between
   the first snapshot and the marker would otherwise be missed. Then it runs
   the conversion steps, recording each in the marker row.

**A refusal at or after the marker leaves the marker in place.** Neither PHP
nor native opens the database until one of two things happens:

- **Resume:** rerun `upgrade-takeover` with the same
  `DW_UPGRADE_BACKUP_FINGERPRINT`. It continues after the last recorded step,
  and repeats the fenced check if that had not completed. This works only
  when the cause was transient (a session, a privilege, a timeout, a lost
  connection) and the source still matches the backup.
- **Restore:** restore the verified backup into a new database. Then either
  run PHP on it (rollback), or start again from step 2 against it. Keep the
  refused database until you have found the cause. Anything written to it
  after the backup is not in the backup.

To see whether a refused MySQL or MariaDB run wrote the marker, run
`SELECT engine, version FROM dw_server_schema;` on the database. No such
table: nothing changed. A `rust-takeover:<layout>` row: the marker is in
place, and `version` is the number of completed steps (0 means the fenced
check has not passed). The table without a row: a run was killed between the
two writes; rerunning with the same digest records the row and continues.

In the table below, *unchanged* means: on SQLite and PostgreSQL always, and
on MySQL and MariaDB when no marker exists. *After the marker* gives the
MySQL and MariaDB action when the marker exists.

#### Command, configuration and connection

| Reason | Meaning | What to do |
| --- | --- | --- |
| `upgrade_takeover_backend_unqualified` | `DB_CONNECTION` is not `sqlite`, `pgsql`, `mysql` or `mariadb`. | Fix `DB_CONNECTION`. Nothing was read. |
| `DW_UPGRADE_BACKUP_FINGERPRINT is required`, `original queue connection is required`, `original default queue connection is required`, `original default transport queue is required`, `DB_DATABASE is required`, `DB_USERNAME is required`, `invalid DB_URL` (process errors, no reason line) | A required variable is missing or malformed. | Set it from [Environment](#environment). Nothing was read. |
| `upgrade_backup_fingerprint_required` | `DW_UPGRADE_BACKUP_FINGERPRINT` is not `sha256:` plus 64 hex digits. | Pass the digest from step 3. Nothing was read. |
| `upgrade_source_queue_config_required` | A queue variable is empty or invalid. | Set the three queue variables to PHP's values. Unchanged. |
| `upgrade_source_file_required`, `upgrade_source_regular_file_required`, `upgrade_source_sqlite_journal_unqualified` | SQLite: `DB_DATABASE` is not a usable file path, not a regular file or not a SQLite file, or the file is still in WAL mode or has `-wal` or `-shm` files beside it. | Point `DB_DATABASE` at the database file; run `upgrade-prepare-sqlite` with PHP stopped. Unchanged. |
| `native_initialization_busy` | MySQL/MariaDB: another takeover, or a native node that is initializing, holds native's bootstrap lock on this database. | Stop the other takeover or native node, then rerun with the same digest. This run changed nothing; if an earlier run left a marker, the rerun resumes it. |
| `upgrade_inspection_timeout` | The command exceeded its time limit (one hour for the takeover, including connecting). | Run closer to the database or on a larger host. Unchanged. After the marker: rerun with the same digest. |
| `upgrade_takeover_unavailable` | Any failure that is not a refusal: the database is unreachable, a login is refused, a connection dropped or a statement failed. On SQLite, also another process holding the file. | Check connectivity, credentials and the database log, then rerun. Unchanged. After the marker: rerun with the same digest once the database is reachable; if it keeps failing at the same step, restore. |

#### The source is in use or not visible

| Reason | Meaning | What to do |
| --- | --- | --- |
| `upgrade_source_in_use` | PostgreSQL: another session still holds a table after the short lock wait. MySQL/MariaDB: another session is connected to the database. SQLite (`upgrade-prepare-sqlite`): another process holds the file. | Stop it, including pools, consoles and monitoring, then rerun. Unchanged. After the marker: stop the session and rerun with the same digest; the fenced check then proves whether it wrote, and refuses with a mismatch if it did (then restore). |
| `upgrade_source_sessions_unverifiable` | MySQL/MariaDB: the role lacks `PROCESS`, so other sessions are invisible. | Use a role with `PROCESS`. Unchanged. After the marker: grant it and rerun with the same digest. |
| `upgrade_source_row_security_unqualified` | PostgreSQL: row-level security is enabled on a table, so the role may not see every row. | Disable it, or take over with a role that sees every row. Unchanged. |
| `postgres_public_schema_required`, `existing_database_requires_qualified_takeover` | PostgreSQL: the role's current schema is not `public`, or relations exist outside `public`. | Take over a database whose PHP tables are all in `public`, with `public` as the role's schema. Unchanged. |
| `upgrade_source_ownership_unknown` | MySQL/MariaDB: the role cannot tell whether native's marker table exists. | Use a role with rights on the whole database. Unchanged. |

#### The source no longer matches the backup

| Reason | Meaning | What to do |
| --- | --- | --- |
| `upgrade_backup_fingerprint_mismatch` | The source differs from the backup: something wrote after it, or the wrong database is named. On MySQL/MariaDB also: a rerun passed a different digest than the run that wrote the marker. | Unchanged: find and stop the writer, take a new backup and verify it again. After the marker: if you changed the digest, rerun with the original one; otherwise the source changed after the backup, and only restoring works. |
| `upgrade_source_catalog_changed` | MySQL/MariaDB: the schema changed while it was being read. | Find what ran DDL. Unchanged: rerun once it has stopped, then back up again. After the marker: restore. |
| `upgrade_takeover_marker_unqualified` | MySQL/MariaDB: the marker table differs from native's. | Something else altered it. Restore. |
| `upgrade_takeover_journal_changed` | MySQL/MariaDB: the marker row was not at the step this run expected; another process changed it. | Stop whatever else touches the database. Restore. |

#### The source is not a qualified PHP layout

| Reason | Meaning | What to do |
| --- | --- | --- |
| `upgrade_source_catalog_unqualified` | The schema matches no recorded PHP layout: an unpublished release, a manual change or a history with no recipe. On MySQL/MariaDB after the marker, the layout differs from the one the marker records. | Do not edit the schema to fit. Keep running PHP and report the inspection's `catalog_sha256`. After the marker: the source changed after the first check; restore. |
| `upgrade_source_layout_unconverted` | The layout has no conversion recipe. | Keep running PHP and report it. Unchanged. |
| `upgrade_source_already_owned` | Native already owns the database. | Nothing to take over. Use `schema-upgrade` for later native releases. Unchanged. |

#### State native refuses

On MySQL and MariaDB each of these is checked before the marker, so unchanged
applies. If one appears after the marker, either the rerun's queue variables
differ from the first run's (rerun with the original values and the same
digest) or the source changed after the first check (restore).

| Reason | Meaning | What to do |
| --- | --- | --- |
| `upgrade_source_queue_binding_unqualified`, `upgrade_source_queue_representation_unqualified`, `upgrade_source_queue_task_missing`, `upgrade_source_queue_run_missing` | The queue variables do not match PHP's, a queued job is not in a form native recognizes, or it names a task or run that does not exist. | Set the three variables to PHP's values. If it still refuses, let PHP drain its queue, then back up again. |
| `upgrade_source_payload_identity_unqualified`, `upgrade_source_payload_rows_unqualified` | A payload row has no usable identity, or the payload scan did not see every row the table count reports. | Keep running PHP and report it; do not edit rows to fit. |
| `upgrade_source_payload_metadata_unqualified` | A stored payload is not a qualified inline Avro value or a qualified reference. | Find the column in `upgrade-payload-inspect`'s `payload_inspection.diagnostics`. Keep running PHP and report it. |
| `upgrade_source_inline_codec_unavailable` | The payload codec could not start. | Rerun; report it if it repeats. |
| `upgrade_source_external_payloads_unsupported`, `upgrade_source_external_payload_storage_unsupported`, `upgrade_source_external_payload_deletions_unsupported` | External payload references, a non-`local` storage policy, or a pending deletion native could never finish. Out of scope for 3.0.x. | Stay on PHP, or remove that state first. |
| `upgrade_source_workflow_messages_unsupported` | The source holds workflow-to-workflow messages, which native does not deliver yet. | Let them be consumed first. |
| `upgrade_source_service_call_dispatches_pending`, `upgrade_source_service_calls_workflow_bound_open` | Service-call work native cannot resume ([#794](https://github.com/durable-workflow/server/issues/794)). | Let PHP finish the dispatches and the linked runs, or cancel the calls, before the stop. |
| `upgrade_source_schedules_unsupported` | A live schedule native cannot run (see [Schedules](#schedules)). | Change or delete it under PHP first. |

#### Conversion and native verification

| Reason | Meaning | What to do |
| --- | --- | --- |
| `upgrade_source_incarnation_unqualified` | A PHP worker registration incarnation has a status native does not carry. On MySQL/MariaDB this check runs in step 3, **always after the marker**. | Unchanged on SQLite and PostgreSQL: keep running PHP and report it. MySQL/MariaDB: restore, keep running PHP and report it; resuming refuses again. |
| `native_schema_catalog_mismatch`, `native_migration_history_mismatch`, `unsupported_rust_schema`, `native_schema_upgrade_required`, `native_schema_newer_than_binary`, `native_schema_upgrade_incomplete` | The converted database failed native's own startup verification. This is a defect, not a property of your data. | Unchanged on SQLite and PostgreSQL. MySQL/MariaDB: restore. Report it with the image digest and `source_layout`. |

#### Inspection commands and native startup

| Reason | Where | What to do |
| --- | --- | --- |
| `upgrade_source_readonly_filesystem_required` | SQLite inspections and fingerprint: the file or its directory is on a writable mount. | Mount it `:ro`. |
| `upgrade_inline_codec_budget_unavailable` | `upgrade-payload-inspect` could not set its decoder budget. | Rerun; report it if it repeats. |
| `upgrade_inspection_unavailable`, `upgrade_fingerprint_unavailable`, `upgrade_prepare_unavailable` | Any failure that is not a refusal in an inspection, fingerprint or `upgrade-prepare-sqlite`. | Check connectivity and credentials, then rerun. These commands do not change the database (`upgrade-prepare-sqlite` only switches the journal mode). |
| `native_takeover_incomplete` | Native startup, MySQL/MariaDB: a takeover wrote the marker and has not finished. | Resume with `upgrade-takeover` and the same digest, or restore. |
| `existing_database_requires_qualified_takeover` | Native startup on a PHP database that was not taken over. | Take it over first, or point native at the right database. |

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

The rest of this page records how the takeover works and why. The commands
above are what a rehearsal, and the installed acceptance, run.

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
   digest. A write after the backup, by PHP or anything else, changes the
   digest and refuses the takeover. On SQLite and PostgreSQL the digest is
   checked inside the same exclusive transaction that writes the ownership
   marker, so the refusal leaves the database unchanged. On MySQL and MariaDB
   it is checked once before the marker and again after it (below), so a
   write that races in between is refused after the marker exists and needs
   the backup.
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
time zones, PHP's six overlap policies including buffering, jitter, run limits,
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
and takeover refuses with `upgrade_source_schedules_unsupported` before it
converts anything. Deleted schedules keep only their audit history and do not block
takeover.

### Other refused or carried state

Native serves external payload storage only through the `local` driver.
Every inspection also reports `storage_inspection`: the number of namespaces
and, by reason, how many have an enabled policy naming another driver
(`s3`, `gcs`, `azure`, `custom` or an unknown one), without naming them.
Native would refuse every upload in such a namespace with 503, so its
inspection is `unqualified` and takeover refuses with
`upgrade_source_external_payload_storage_unsupported` before it converts
anything. A
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
`upgrade_source_service_call_dispatches_pending` before it converts anything.
Let PHP
finish them before the stop. Completed dispatches do not block takeover.

The same report counts open service calls bound to a workflow run
(`workflow_bound_open`): calls a Nexus workflow-bound handler accepted that are
still `pending`, `accepted` or `started`. PHP closes such a call when its linked
run ends, and 2.11.5's maintenance pass repairs one it missed. Native serves no
workflow-bound handler yet (Server #794) and closes no linked call, so one
would stay open after takeover. Any makes the inspection `unqualified`, and
takeover refuses with `upgrade_source_service_calls_workflow_bound_open` before
it converts anything. Let the linked runs finish, or cancel the calls, before the stop.

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
interruption or refusal leaves the PHP database unchanged and nothing needs
a journal.
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
refused. The ledger and native's final marker `('rust-development',
<native schema version>, <checksum>)` commit together. The PHP fence refuses
the database from the first step, because the marker table exists.

A refusal from the second session and fingerprint check, or from any later
step, therefore leaves the marker in place: PHP refuses the database and
native reports `native_takeover_incomplete`. Only two exits exist: a rerun
with the same fingerprint, which works when the cause was transient and the
source still matches the backup, or restoring the backup. The second check
exists for exactly this case. A session that wrote between the first snapshot
and the marker is caught there, after the marker, rather than missed.

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
5. Record the native migration ledger and set native's final marker.

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
- interruption at named steps, then resumption to the same final state;
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
