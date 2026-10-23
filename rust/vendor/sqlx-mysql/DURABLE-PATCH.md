# SQLx MySQL driver patches

This directory contains the official `sqlx-mysql` 0.9.0 crate with two scoped
driver corrections. Its published archive has SHA-256
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
`src/connection/mod.rs`, `src/connection/stream.rs` and
`src/connection/executor.rs`. The packaged license-reference files are replaced with
the full MIT and Apache license texts from the same upstream commit. This file
records the patch; no upstream release is represented as containing it.

Remove this override when a reviewed published driver provides equivalent
hard-close behavior and statement-close batching (or `TCP_NODELAY`), and passes
the same database and TLS qualification.
