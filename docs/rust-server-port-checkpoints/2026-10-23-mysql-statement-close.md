# October 23, MySQL statement closes and late timers

- **Found by.** Server #714. Twice on October 23, the hosted MariaDB 10.11
  `codec-deadline-health` cell recorded a native timer more than the 1 s
  maximum late. In one, `TimerFired` came 1.098 s after `fire_at`. MySQL 8.0
  and 8.4 cells fail the same way, less often.
- **Reproduction.** A disposable 4-vCPU VM ran the unchanged cell on main
  `f67f2816e`, with temporary timing in a local-only build:
  - MariaDB failed 2 of the first 8 cells, with timers 1.228 s and 1.213 s
    late;
  - PostgreSQL 17 and SQLite passed all 8 cells, at most 171 ms late.
- **Attribution.** Every late timer waited behind the global transition lock:
  - In the worst MariaDB cell, the tick's activity-deadline sweep waited
    543 ms behind a workflow completion that held the lock 690 ms. The sweep
    then held it 407 ms itself, and the timer fired 1.228 s late.
  - Ordinary transitions held the lock 300 ms to 1 s. With every statement
    logged by MariaDB, those transactions spent a median of 5 ms in the
    database (p90 9 ms) across their 30 to 48 statements.
  - Native was not CPU-starved. Wake-up probes on an async task and an OS
    thread rarely saw 50 ms delays, and giving native 2 CPUs left the holds
    unchanged.
  - Timestamps on each statement inside the transactions showed gaps of
    40 to 100 ms spread across ordinary statements, many near 58 ms. That is
    Nagle's algorithm waiting for a delayed acknowledgement.
- **Cause.** `COM_STMT_CLOSE` has no reply. The published `sqlx-mysql` 0.9.0
  flushes it in its own write: when its 100-entry statement cache evicts a
  statement, and after executing a statement it does not cache. Before the
  next command it also flushes anything still buffered. The next command is
  then a second small write while the close is still unacknowledged, so the
  kernel holds it about 40 ms. SQLx sets no `TCP_NODELAY`; PHP's mysqlnd does.
  PostgreSQL's driver sends its closes together with the next request.
- **Change.** In the vendored driver, `MySqlStream::queue_packet` buffers each
  statement close with sequence number 0. `wait_until_ready` no longer flushes
  a buffer that holds only replyless closes, so they leave in the same write as
  the next command. Packets, order and server behavior are unchanged. The
  patch record is `rust/vendor/sqlx-mysql/DURABLE-PATCH.md`.
- **Test.** `qualification_statement_close_leaves_with_the_next_command`
  records the client's writes through a proxy. It uses a one-entry statement
  cache and one uncached statement, so both close paths are exercised.
  - With the fix it passes on MySQL 8.0.46 and MariaDB 10.11 in 0.01 s.
  - With the published driver it fails on both: every close ends its own
    write, and the same 14 statements take 0.6 s.
  - The existing cancelled-boundary qualification still passes.
- **Hosted-cell A/B on the VM.** Interleaved cells with the spec and limits
  unchanged. Both builds were instrumented: one without the driver change, one
  with its first revision. That revision already buffered the closes, but
  `wait_until_ready` still flushed them before draining.

  | | Cells passed | Timer lateness | Holds of 300 ms or more |
  | --- | --- | --- | --- |
  | MariaDB without the fix | 3 of 6 | 190 to 1045 ms | 54 |
  | MariaDB with the fix | 6 of 6 | 49 to 289 ms | 0 |
  | MySQL 8.0 and 8.4 without the fix | 5 of 8 | 138 to 1099 ms | 77 |
  | MySQL 8.0 and 8.4 with the fix | 8 of 8 | 116 to 299 ms | 3 |

  Clean release builds of main and of this branch are compared across all five
  families in the PR's evidence comment.
