# PHP database ownership checks

PHP Server checks database ownership before releasing a connection, applying
connection pragmas or initialization SQL, and starting write-capable roles.
The standard entrypoint runs `server:assert-php-database --allow-unavailable`;
direct bootstrap, migration, wipe, worker and scheduler paths are also guarded.

The unprefixed `dw_server_schema` relation reserves the database for Rust.
PHP refuses its presence even when it is empty, malformed or has an unknown
engine/version. `php_database_refused` means restart Rust or restore the
complete pre-upgrade backup into a separate database. Preserve the marker.
Stop every PHP write-capable role before Rust takeover: these preflights cannot
make a concurrently mixed PHP/Rust fleet safe.

`php_database_ownership_unknown` means the check could not establish ownership.
Resolve its availability, permission or recovery diagnostic before restarting.
`--allow-unavailable` preserves the existing degraded startup behavior for
recognized temporary backend outages; it never bypasses a marker or ambiguous
ownership check. Every later connection is checked again.

## MySQL and MariaDB permissions

The runtime must be able to inspect the reserved marker name. A role with only
individual workflow-table grants can have the marker hidden from
`information_schema`; a denied direct probe must therefore fail closed, even
when the marker has not yet been created.

An administrator can prepare the following table-scoped grant for an existing
runtime account, replacing the example database, user and host:

```sql
GRANT SELECT, CREATE ON `server_database`.`dw_server_schema`
    TO 'server_user'@'server_host';
```

The table-scoped `CREATE` privilege permits granting access before the relation
exists. PHP does not create this table. This gives no grant on other tables;
normal bootstrap/migration permissions remain the operator's responsibility.
Database-wide privileges already covering the reserved name need no extra grant.
The process tests exercise an unmarked table-only role with this grant and its
subsequent refusal after an administrator creates the marker.

## SQLite crash recovery and disk space

Healthy database checks open SQLite read-only and include its visible WAL state.
An `immutable` snapshot would miss a marker still in WAL, so the probe removes
URI options that bypass normal SQLite locking and recovery visibility.

A hot rollback journal requires writes before SQLite can read schema. PHP first
copies the original database and journal into a private directory beside the
database and lets SQLite recover that copy. It checks the recovered schema,
SQLite `quick_check`, original file identities and hashes. An unmarked,
unchanged original can then recover through the ordinary configured connection.
A recovered marker refuses PHP while preserving the original database and
journal bytes, including a marker restored from an uncommitted drop.

Plan temporary free capacity equal to the database plus rollback-journal size,
with 64 MiB of additional headroom. There is one recovery copy per database and
runtime UID. Same-UID startup checks serialize within `DB_SQLITE_BUSY_TIMEOUT`.
The UID separation allows root startup and Apache's runtime user to retain
their own private permissions. Insufficient space refuses the check before
copying or changing the original. Healthy checks do not copy the database.

Copy files are removed after success or failure. A killed check can leave a
partial copy in `.dw-php-ownership-<identifier>-<uid>` until that UID's next
check, which discards it before reuse. Each directory has mode `0700` and an
empty lock file. Keep these disposable copies out of database backup selection.
For manual cleanup, stop all PHP roles first, remove only the `probe.sqlite*`
files inside these directories, and retain their lock files while the database
remains in use.

Custom-VFS recovery, a super-journal footer or simultaneous WAL sidecars fails
closed. Preserve the complete original database/journal cohort and its backup;
do not remove recovery journals to make startup succeed. The
[SQLite rollback-journal reference](https://www.sqlite.org/lockingv3.html#hot_journals)
explains recovery ordering. See the
[backup and restore procedure](self-hosted-backup-and-restore.md) for restoring
an installation.

The PHP fence is part of [Server #325](https://github.com/durable-workflow/server/issues/325).
Rust's backup-first conversion, interrupted migrations and production takeover
remain separate qualification gates.
