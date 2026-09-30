# Recover database storage capacity

Server reports recognized database capacity failures as HTTP 503 with
`reason: database_storage_exhausted`, `resource: database` and
`retryable: false`. The response includes a recovery action and preserves the
request's control-plane or worker protocol metadata. It contains no SQL,
payload bytes, database paths or credentials.

The diagnostic recognizes MySQL's full-table or full-tablespace error 1114,
SQLite's `SQLITE_FULL` error 13 and PostgreSQL's `disk_full` SQLSTATE 53100.
Database memory, connection and locking failures remain separate conditions.

## Safe recovery

1. Inspect the database's own logs and storage allocations. Check filesystem
   capacity, temporary storage and configured table or tablespace limits. A
   configured limit can prevent writes while the host still has free disk.
2. Restore capacity using the database's supported procedure. Preserve the
   database volume, durable tables, recovery logs and external payloads.
   Stop avoidable writers while repairing capacity.
3. Inspect the original workflow and task IDs before repeating a failed
   operation. Resume workers and check the original result and history.
   An HTTP error alone does not establish whether a durable operation committed.

For a bounded MySQL system tablespace, retain the same data volume and initial
file size. If disk capacity allows, increase the configured autoextension
maximum or add capacity through the supported InnoDB procedure. Restart the
database, verify discovery and inspect acknowledged runs before resuming work.
The [MySQL tablespace reference](https://dev.mysql.com/doc/refman/8.4/en/innodb-init-startup-configuration.html)
describes these configuration constraints. Use the
[backup and restore procedure](self-hosted-backup-and-restore.md) when recovery
requires restoring a backup.

## What readiness verifies

`GET /api/ready` checks backend availability, migrations, queue prerequisites,
namespace setup, cache and authentication configuration. Its database check
opens a connection. It does not insert workflow data to measure free capacity.
The database check reports `check_scope: connection_only` and
`write_capacity_verified: false` when the connection is available.

A readable database can pass this check while capacity blocks new writes.
Use request failures and the database's capacity monitoring alongside readiness
to determine whether durable work can progress.
