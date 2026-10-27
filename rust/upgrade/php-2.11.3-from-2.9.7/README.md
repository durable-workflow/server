# Exact PHP source-catalog variant: Server 2.11.3 migrated from 2.9.7

These deltas apply to the [PHP 2.11.1 layout migrated from
2.9.7](../php-2.11.1-from-2.9.7/README.md). They make the same changes as the
fresh [PHP 2.11.3 delta](../php-2.11.3/README.md); only the parent differs.
2.9.7 had already added `workflow_tasks.queue_delivery_reference`, so its
task column order survives every later release.

The same delta was captured from two databases: one that published Server
2.9.7 created (image index
`sha256:30c34c5f3256a812fbccbe505a068309b58b0c14d01ffff0465d88604cb36d50`)
and published Server 2.11.3 (image index
`sha256:923a8924ecad13485562d7af30dc87d7fd6d9d7d4758ec8113bac0766fa8603e`)
then migrated, and one that 2.9.7 created, 2.11.1 (image
`sha256:bf8bed13f5f809ee3425233180a581353eac4683adbee5c4ecbf63bb18bbd102`)
migrated and 2.11.3 migrated again.

Definitions were read from actual published-image databases: SQLite, MySQL
8.0, MariaDB 10.11 through the `mysql` connection, and PostgreSQL 17. No
application row values or credentials are recorded. Takeover converts it as
it converts fresh 2.11.3.
