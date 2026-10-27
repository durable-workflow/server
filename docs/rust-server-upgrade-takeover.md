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

PHP 2.11.3 (Core 2.14) schedules a service call's handler in the
`workflow_service_call_dispatches` outbox and dispatches it in a later pass.
Native runs the handler when it accepts the call, so it cannot resume a
dispatch that is still scheduled or claimed. Every inspection reports
`service_call_dispatch_inspection`: the number of dispatches and of pending
ones, without naming calls. A pending dispatch makes the inspection
`unqualified`, and takeover refuses with
`upgrade_source_service_call_dispatches_pending` before any change. Let PHP
finish them before the stop. Completed dispatches do not block takeover.

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
- Service calls are not part of native yet, so a source with service calls
  is refused before any change, and `call_deadline_at` is removed only from
  an empty table.

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
