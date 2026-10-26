# Exact PHP source-catalog variant: Server 2.11.1

These deltas apply to the [PHP 2.9.7 layout](../php-2.9.7/README.md). They come
from normal bootstrap of published Server 2.11.1, tag `2.11.1`, image
`sha256:bf8bed13f5f809ee3425233180a581353eac4683adbee5c4ecbf63bb18bbd102`
(amd64, the parity reference in
[`php-2.11.1.json`](../../../tests/Fixtures/ServerParityBaselines/php-2.11.1.json)),
which pins Core (Workflow package) 2.10.2.

The only physical change from 2.9.7 is Core's nullable trace carrier,
`observability_context`, on `workflow_runs`, `workflow_commands` and
`workflow_tasks`. Its migrations are
`2026_10_15_000100_add_observability_context_to_workflow_runs`,
`2026_10_15_000200_add_observability_context_to_workflow_commands` and
`2026_10_16_000100_add_observability_context_to_workflow_tasks`. Server's own
migrations are unchanged from 2.9.7.

A fresh 2.11.1 database runs Core's migrations in file order, so on
`workflow_tasks` the carrier comes before 2.9.7's `queue_delivery_reference`.
A database that 2.9.7 created and 2.11.1 migrated has the opposite order; that
is the [`php-2.11.1-from-2.9.7`](../php-2.11.1-from-2.9.7/README.md) variant.

Definitions were read from actual published-image databases: SQLite, MySQL
8.0, MariaDB 10.11 through the `mysql` connection, and PostgreSQL 17. No
application row values or credentials are recorded.

Takeover counts the carried values and drops the three columns, reporting
`observability_contexts_removed`. Native has no carrier; carriers are not
business state or history, and the verified pre-takeover backup keeps them
(decided on [#325](https://github.com/durable-workflow/server/issues/325#issuecomment-6306545520)).
