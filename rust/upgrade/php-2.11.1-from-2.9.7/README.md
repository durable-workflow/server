# Exact PHP source-catalog variant: Server 2.11.1 migrated from 2.9.7

These deltas apply to the fresh [PHP 2.11.1 layout](../php-2.11.1/README.md).
They come from a database that published Server 2.9.7 bootstrapped (image index
`sha256:30c34c5f3256a812fbccbe505a068309b58b0c14d01ffff0465d88604cb36d50`) and
published Server 2.11.1 then migrated (image
`sha256:bf8bed13f5f809ee3425233180a581353eac4683adbee5c4ecbf63bb18bbd102`).
The 2.11.1 bootstrap ran exactly Core's three trace-carrier migrations.

The only difference from a fresh 2.11.1 database is column order on
`workflow_tasks`. 2.9.7 had already added `queue_delivery_reference`, so the
later `observability_context` follows it. Any database that reached 2.9.x before
2.11.1 has this order; one created at 2.8.x or earlier and migrated straight
to 2.11.1 has the fresh order.

Definitions were read from actual published-image databases: SQLite, MySQL
8.0, MariaDB 10.11 through the `mysql` connection, and PostgreSQL 17. No
application row values or credentials are recorded. Takeover converts it as
it converts fresh 2.11.1.
