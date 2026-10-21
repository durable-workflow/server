# Exact PHP source-catalog variant: Server 2.9.7

These deltas apply to the [PHP 2.8.0 layout](../php-2.8.0/README.md), not to
the frozen layout. They come from normal bootstrap of published Server 2.9.7,
tag `2.9.7`, image index
`sha256:30c34c5f3256a812fbccbe505a068309b58b0c14d01ffff0465d88604cb36d50`
(amd64 `sha256:40e969d0b6b240b835dcdb2fc531507266216ec34a83f59f561674f41e35f461`),
which pins Core (Workflow package) 2.7.2.

The only physical change from 2.8.0 is Core's nullable JSON column
`workflow_tasks.queue_delivery_reference`, added by its
`2026_10_16_000100_add_queue_delivery_reference_to_workflow_tasks` migration.
PHP records there the database queue job that delivers a task. Server's own
migrations are unchanged from 2.8.0.

Definitions were read from actual published-image databases: SQLite, MySQL
8.0, MariaDB 10.11 through the `mysql` connection, and PostgreSQL 17. MySQL
8.4 was checked against the same definitions. No application row values or
credentials are recorded.

[`../mariadb-driver.json`](../mariadb-driver.json) records the one difference
when PHP uses Laravel's separate `mariadb` connection instead of `mysql`:
`runtime_external_payload_backup_hold.owner` is `uuid` rather than `char(36)`.
Inspection applies it to every MariaDB release layout and reports, for
example, `php-2.9.7+mariadb-driver`.

Like the 2.8.0 variant, this recognizes physical layout for read-only
inventory only. It neither identifies the installed package nor qualifies
stored representations, backup, writer quiescence or takeover of that layout.
