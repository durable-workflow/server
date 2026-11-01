# Native upgrade takeover

This note designs the one-way, backup-first takeover of an existing PHP Server
database by the native runtime. It is a design input for
[Server #325](https://github.com/durable-workflow/server/issues/325), not a
qualified procedure. The gates it must satisfy are in the
[storage and upgrade audit](rust-server-storage-audit.md). Today
`upgrade-inspect`, `upgrade-queue-inspect` and `upgrade-payload-inspect` only
read, and their `backup_verified`, `php_quiescence_verified`,
`stored_representation_compatibility_verified` and `takeover_allowed` flags are
always false. This note says what makes each one true.

## Shape

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

## Running it with the native image

The native image carries every command below. Native releases from 3.0.0
publish to the same repositories as PHP, `ghcr.io/durable-workflow/server`
and `durableworkflow/server` on Docker Hub, as major version 3; they move no
rolling tag such as `latest`. Release candidates (`X.Y.Z-rc.N`) are
published to GHCR only. The first two candidates were published as
`ghcr.io/durable-workflow/server-native`; the current one is `3.0.0-rc.2`,
index
`sha256:f54e89d775dbf7c6074f37836c6bb0b25f94869d23711b0706e9547c3edecb29`. It
reads the same `DB_*` settings as the PHP image. Every command needs
`DW_RUST_EXPERIMENTAL=1`: native is opt-in until it is declared qualified.

The inspection, the fingerprints and the takeover must all name the same
stopped source. Native refuses a fingerprint that does not match the source
in the takeover's own transaction, but only the operator can make sure the
backup restores. The backup check in step 3 is therefore not optional.
Shown here for PostgreSQL; MySQL is the same with `DB_CONNECTION=mysql`.
`-e DB_PASSWORD` and `-e DW_AUTH_TOKEN` pass those values from the shell's
environment, so export them first rather than writing a credential on the
command line or into shell history. Add `--network` when the
database is only reachable on a Docker network.

Every command uses one exact image, the multi-architecture index digest that
the release receipt records, never a tag. A tag can move between steps; a
digest cannot, so the image that inspected the source is the one that takes
it over and serves it. Keep the reference with the backup.

```sh
export IMAGE=ghcr.io/durable-workflow/server@sha256:<native index digest from the release receipt>
docker pull "$IMAGE"
```

1. Stop every PHP role that can write: HTTP, queue workers, the scheduler and
   any bootstrap. Keep them stopped until step 6.
2. Inspect the stopped source. Every report must say `inspected` and name a
   qualified `source_layout`.

   ```sh
   source="-e DW_RUST_EXPERIMENTAL=1 -e DB_CONNECTION=pgsql -e DB_HOST=db -e DB_PORT=5432 \
     -e DB_DATABASE=workflow -e DB_USERNAME=workflow -e DB_PASSWORD"
   queues="-e DW_RUST_UPGRADE_QUEUE_CONNECTION=database \
     -e DW_RUST_UPGRADE_QUEUE_DEFAULT_CONNECTION=database -e DW_RUST_UPGRADE_QUEUE_DEFAULT_QUEUE=default"
   docker run --rm $source $queues "$IMAGE" upgrade-inspect
   docker run --rm $source $queues "$IMAGE" upgrade-queue-inspect
   docker run --rm $source $queues "$IMAGE" upgrade-payload-inspect
   ```
3. Back up with the [existing procedure](self-hosted-backup-and-restore.md).
   Restore the backup into a separate database (here `workflow_restored`).
   Fingerprint both. The two digests must be equal: that proves the backup
   restores to exactly the stopped source.

   ```sh
   docker run --rm $source "$IMAGE" upgrade-fingerprint
   docker run --rm $source -e DB_DATABASE=workflow_restored "$IMAGE" upgrade-fingerprint
   ```
4. Take over the source with that digest. Native refuses unless the source
   still has it, so a write after the backup stops the takeover before any
   change.

   ```sh
   docker run --rm $source $queues -e DW_UPGRADE_BACKUP_FINGERPRINT=sha256:<digest> \
     "$IMAGE" upgrade-takeover
   ```
5. Start native on the taken-over database.

   ```sh
   docker run -d -p 8080:8080 $source -e DW_AUTH_TOKEN "$IMAGE"
   ```

   The image runs the repair pass itself (`DW_RUST_MAINTENANCE_LOOP=1`),
   because it has no separate scheduler container.
6. Do not restart PHP against the database: its ownership check refuses it.

The native image uses the PHP image's paths: SQLite at
`/app/database/database.sqlite` and local external payload objects under
`/app/storage/app/external-payloads/<namespace>`. PHP records each object's
absolute `file://` path in the database, so mount the PHP installation's
volumes at the same paths. The PHP image writes them as `www-data`; native
runs as UID and GID 1000. Once PHP is stopped (step 1), give both volumes to
1000 (for example `chown -R 1000:1000`): ownership is not part of the
fingerprint, and `upgrade-prepare-sqlite`, the takeover and the server all
write. Takeover still refuses a source that holds external payload references.

For SQLite, stop PHP first. Then switch the file from WAL to the rollback
journal with `upgrade-prepare-sqlite`, and mount it read-only for the
inspections (`-v <volume>:/app/database:ro`). The backup check in step 3 is a
byte copy of the prepared file, fingerprinted like the source.

Rollback is restoring the verified backup into a new database for a PHP
installation. There is no in-place rollback after step 4.

## Later native releases

Takeover runs once, from PHP. A database that native already owns moves to a
release with a newer native schema through `schema-upgrade` instead: stop
every native node, back up the database, run the new image once with
`schema-upgrade` and the same `DB_*` settings, then start the new release. A
node never upgrades at startup; it refuses an older schema with
`native_schema_upgrade_required` and a newer one with
`native_schema_newer_than_binary`. Restore the backup to go back. Details are
in [schema upgrades](../rust/README.md#native-schema-upgrades).

## Fingerprint

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

Native schedules are still a bounded subset of PHP's: one UTC interval of a
day or two seconds, the skip overlap policy, no jitter, at most one run, the
default namespace, and no memo, search attributes, note, buffered actions or
queue routing. Every inspection reports `schedule_inspection`: the number of
live (not deleted) schedules and, by reason, how many fall outside that
subset, without naming them. Such a schedule would stop firing or lose
fields under native, so its inspection is `unqualified` and takeover refuses
with `upgrade_source_schedules_unsupported` before any change. Deleted
schedules keep only their audit history and do not block takeover.

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

## Exclusive transaction per backend

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

## Marker and interruption

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

## SQLite sources in WAL mode

PHP Server keeps SQLite in WAL mode, and a read-only SQLite open can still
create WAL and shared-memory sidecars, so inspection, fingerprint and takeover
require the rollback journal. With PHP stopped, `upgrade-prepare-sqlite` opens
the file as its only connection and switches it to the rollback journal.
SQLite first checkpoints every committed WAL frame into the file, so the stored
rows are unchanged. The command refuses while another process holds the file,
and runs before the fingerprint and the backup.

## Steps

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

## Tests

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

## Open questions

- Whether the declared column types print identically after a restore into
  another minor server version. Until that is shown, the restore check uses
  the same server version as the source.
- How long the fingerprint takes on a large installation, measured on a
  disposable host before the stop window is documented.
