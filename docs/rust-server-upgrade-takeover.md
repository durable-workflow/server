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

## Exclusive transaction per backend

The marker, the fingerprint check and the schema changes must not interleave
with any other writer.

| Backend | Exclusion | Atomicity |
| --- | --- | --- |
| SQLite | `BEGIN EXCLUSIVE` on the database file | Fingerprint, marker, native relations and conversions commit in one transaction. |
| PostgreSQL | `LOCK TABLE … IN EXCLUSIVE MODE` on every relation, then the native advisory key | DDL is transactional, so everything commits in one transaction. |
| MySQL and MariaDB | To be qualified: `LOCK TABLES … WRITE` on every relation, or a check that no other session uses the database, repeated after the marker is written | DDL commits implicitly, so the marker records a step journal (below). |

On SQLite and PostgreSQL, a PHP connection that slipped past the stop blocks on
these locks, and a write it committed earlier changes the fingerprint. Neither
leaves a mixed state.

## Marker and interruption

The marker is written first in each takeover transaction, with state
`takeover`, the fingerprint and the step list. Native startup refuses state
`takeover`. Each step is idempotent and records its completion in the marker.
Rerunning `upgrade-takeover` with the same fingerprint resumes after the last
recorded step; a different fingerprint is refused. The final step sets state
`ready` and the native schema version.

On SQLite and PostgreSQL an interruption rolls the whole transaction back, so
the database is either unchanged PHP or fully native. On MySQL and MariaDB the
step journal makes each committed DDL step visible, and resumption continues
from it. The PHP fence refuses the database from the first step, because the
marker exists.

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

- Whether MySQL and MariaDB can create the native relations while
  `LOCK TABLES` holds every existing one, or need the session check instead.
- Whether the declared column types print identically after a restore into
  another minor server version. Until that is shown, the restore check uses
  the same server version as the source.
- How long the fingerprint takes on a large installation, measured on a
  disposable host before the stop window is documented.
