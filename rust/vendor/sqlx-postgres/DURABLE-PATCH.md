# SQLx PostgreSQL driver patch

This directory contains the official `sqlx-postgres` 0.9.0 crate with one
backported driver correction and one native tagging patch. Its published
archive has SHA-256
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
failed `BEGIN` queues a `ROLLBACK`. All other upstream source is retained,
apart from the tagging patch below.

`qualification_a_begin_cancelled_in_flight_leaves_no_transaction_behind` in
`rust/src/runtime/postgres.rs` cancels a pooled `BEGIN` while its round trip
is held open, then checks that the backend is `idle`, and that the
scheduler's order on that same connection succeeds: a pooled probe, native's
read snapshot `BEGIN`, then a pooled statement. It fails with the published
driver and passes with this correction.

## Protocol failures during connection setup are tagged

`ConnectOptions::connect` (`src/options/connect.rs`) prefixes a
`sqlx::Error::Protocol` raised while the connection is being set up with
`CONNECTION_SETUP_PROTOCOL_PREFIX` (`"connection setup: "`, exported from
`src/lib.rs`). Every pool connection and every direct `connect_with` goes
through this one entry point. Other error variants, and protocol failures
on an established connection, are unchanged.

Upstream raises the same `Protocol` variant whether a proxy or a starting
server answers the handshake with bytes the driver cannot parse, or whether a
query hits a client-side encoding limit. The first is a passing backend
condition during a failover; the second is a request fault. Native Server's
shared transient check (`worker_incarnations::transient_reason`) reads the
prefix, so only setup-phase protocol failures become a retryable 503.

`worker_incarnations::a_garbled_{postgres,mysql}_handshake_is_transient`
connects to a local listener that answers the handshake with invalid bytes and
checks the tagged error and its classification.
`only_connection_setup_protocol_failures_are_transient` checks that the
exported prefix matches the server's and that untagged protocol failures stay
non-transient.

Remove this directory and its `[patch.crates-io]` entry only once a
published `sqlx-postgres` release contains the BEGIN fix and lets a caller
tell a protocol failure during connection setup from one on an established
connection. Otherwise `transient_reason` must get that distinction another
way first. The server keeps its own copy of the prefix, so a release build
would still compile without the tag, and garbled handshakes would silently
become non-retryable again; only the tests above would catch it.
