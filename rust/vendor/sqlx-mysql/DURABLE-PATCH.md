# SQLx MySQL driver patches

This directory contains the official `sqlx-mysql` 0.9.0 crate with two scoped
driver corrections, one native tagging patch and one session-state report. Its published archive has
SHA-256
`90b8020fe17c5f2c245bfa2505d7ef59c5604839527c740266ad2214acebea27` and VCS
commit `003b698e99e024f3621b8043a2426fde5b741171`.

`MySqlConnection::close_hard` drops the owned connection instead of awaiting
TLS shutdown. SQLx defines this operation as immediate closure without graceful
shutdown. Awaiting shutdown can retain a pool slot after a cancelled operation
and a timed-out reset when the peer's TLS reply is unavailable. Graceful close,
Rustls and certificate/hostname validation are unchanged.

The real regression in `rust/src/runtime/mysql_pool_tests.rs` preserves its
five-second reset and seven-second disposal budgets. It verifies fresh reads
after a cancelled BEGIN, a committed row after a lost COMMIT reply, disposal of
a stalled connection, replacement identity and preserved committed data.
The original and flush-before-gate TLS controls fail with the published driver;
both pass with this correction. Qualification and original evidence are in
[Server #429](https://github.com/durable-workflow/server/pull/429#issuecomment-6135933370).

## Statement closes leave with the next command

`COM_STMT_CLOSE` has no server reply. The published driver flushes it on its
own write: when its statement cache evicts a statement, and right after
executing a statement it does not cache. The next command is then a second
small write while the close is still unacknowledged, so Nagle's algorithm
holds it until the server's delayed acknowledgement, about 40 ms. SQLx does not
set `TCP_NODELAY`; PHP's mysqlnd and libmysqlclient do.

Inside the native transition lock, a transaction issues 30 to 48 statements,
so these stalls added up to holds of 0.3 to 1 s under load. A due timer
waiting behind them fired more than the 1 s maximum late (Server #714).

`MySqlStream::queue_packet` now buffers each close with the reset sequence
number that `send_packet` would use. On the path before the next command
(`wait_until_ready`), a buffer that holds only such closes is not flushed, so
they leave in the same write as that command. The server receives the same
packets in the same order. A connection dropped before its next command leaves
the close unsent; the server releases that connection's prepared statements
when it closes.

The public lifecycle is unchanged. `Connection::flush` sends every buffered
byte, queued closes included, and leaves `should_flush` false.
`clear_cached_statements` sends its closes before it returns. Neither needs a
later command.

Three tests in `rust/src/runtime/mysql_pool_tests.rs` record the TCP read
chunks a proxy receives from the client:
- `qualification_statement_close_leaves_with_the_next_command` fails with the
  published driver, where each close arrives as a chunk of its own, and passes
  with this correction;
- `qualification_explicit_flush_sends_queued_statement_closes` and
  `qualification_clearing_the_statement_cache_closes_them_now` prove the two
  lifecycle calls without a later command. Where the performance schema is on,
  the second also sees the server's statements for the connection drop from
  three to none.

All upstream source is retained. The source-code changes are in
`src/connection/mod.rs`, `src/connection/stream.rs`, `src/connection/tls.rs`,
`src/connection/executor.rs`, `src/options/connect.rs` and `src/lib.rs`.
The packaged license-reference files are replaced with the full MIT and
Apache license texts from the same upstream commit. This file records the
patch; no upstream release is represented as containing it.

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

## A returned session reports whether it is known to be idle

Native Server's pool `after_release` hook resets a returned session with
`ROLLBACK`, and the connection keeps its pool slot until the hook returns.
Resetting every return cost a round trip per checkout; during a database
stall those round trips kept slots busy for the whole stall (Server #1204).

`MySqlConnection::read_pending_replies` (`src/connection/mod.rs`) reads the
replies of commands already sent, without sending anything, and records the
server status they end with. A result stream dropped after its first row
(`fetch_one`, `fetch_optional`) leaves the rest of its reply unread although
the server sent it with the row. `MySqlStream::wait_until_ready`
(`src/connection/stream.rs`) keeps the status of the last terminal packet it
drains for this (`drained_status`; `src/connection/tls.rs` carries the field
through the TLS upgrade). `ping` now records the status of its OK packet.
`MySqlConnection::is_idle_outside_transaction` reports a session as known
idle only when no reply is pending or unsent, SQLx has no open transaction
and the last recorded server status has no transaction open. The hook skips
the reset only then; every other state is still rolled back.

`runtime::mysql::pool_tests::qualification_pool_return_resets_only_sessions_not_known_idle`
counts the `ROLLBACK` commands a recording proxy receives: none after an
autocommit read or an acknowledged commit, one for a plain-SQL transaction,
which ends, and a reset for a dropped SQLx transaction.

Remove this override only when a reviewed published driver provides
equivalent hard-close behavior and statement-close batching (or
`TCP_NODELAY`), passes the same database and TLS qualification, and lets a
caller tell a protocol failure during connection setup from one on an
established connection, and lets a caller tell that a returned session is
idle outside a transaction (or the server resets every returned session
again). Otherwise `transient_reason` must get that
distinction another way first. The server keeps its own copy of the prefix,
so a release build would still compile without the tag, and garbled
handshakes would silently become non-retryable again; only the tests
above would catch it.
