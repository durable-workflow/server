# SQLx PostgreSQL driver patch

This directory contains the official `sqlx-postgres` 0.9.0 crate with one
backported driver correction. Its published archive has SHA-256
`87a2bdd6e83f6b3ea525ca9fee568030508b58355a43d0b2c1674d5f79dcd65e` (the
`Cargo.lock` checksum it replaces) and VCS commit
`003b698e99e024f3621b8043a2426fde5b741171`.

## A transaction cancelled during BEGIN is rolled back

`PgTransactionManager::begin` raised `transaction_depth` only after the
`BEGIN` round trip. `start_rollback`, which both its own guard and
`Transaction`'s drop call, does nothing while the depth is zero. A future
dropped during that round trip therefore left the server inside a transaction
with no `ROLLBACK` queued, and the pool handed the connection to the next
borrower, whose statements ran inside it.

Native hit this in the shared fixtures (Server #673's run 40195442238,
`postgres-storage (existing-php-matrix, sdk-directions)`). A request dropped
mid-`BEGIN` left a pooled connection in a transaction. The scheduler's pooled
timer probe then ran inside it, and native's read snapshot
(`BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY`) failed with 25001 ("SET
TRANSACTION ISOLATION LEVEL must be called before any query"), aborting the
transaction. Every later statement on that connection failed with 25P02, so
the node reported its storage unavailable for the rest of the run.

`src/transaction.rs` takes upstream's fix unchanged: commit
[`f1e94ec34dd5`](https://github.com/launchbadge/sqlx/commit/f1e94ec34dd5)
("fix(postgres): roll back a transaction cancelled during BEGIN", launchbadge/sqlx
#4394, merged 2026-09-09, not yet in a release). The depth is claimed before
the round trip and unwound if the `BEGIN` did not take, so a cancelled or
failed `BEGIN` queues a `ROLLBACK`. All other upstream source is retained.

`qualification_a_begin_cancelled_in_flight_leaves_no_transaction_behind` in
`rust/src/runtime/postgres.rs` cancels a pooled `BEGIN` while its round trip
is held open, then checks that the backend is `idle`, and that the
scheduler's order on that same connection succeeds: a pooled probe, native's
read snapshot `BEGIN`, then a pooled statement. It fails with the published
driver and passes with this correction.

Remove this directory and its `[patch.crates-io]` entry once a published
`sqlx-postgres` release contains the fix.
