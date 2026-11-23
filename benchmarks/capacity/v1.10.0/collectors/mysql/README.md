# MySQL child-close measurements

This collector is for the native 1,000-child capacity cell described in [Server #1188](https://github.com/durable-workflow/server/issues/1188). It measures a synthetic run on an isolated MySQL instance. Use a fresh database for each binding and arm.

The native MySQL backend serializes changes with a process-local FIFO and an InnoDB lock on its shared schema transition row. This collector reports elapsed and lock time on that row's acquisition statement, plus SQL executions and rows examined, sent, and changed within each child-close transaction. The statement lock time is a MySQL metric. Also record the before-and-after `Innodb_row_lock_waits` and `Innodb_row_lock_time` counters as the database-wide wait evidence. The process-local FIFO is outside the database clock.

Use ROW binary logging with GTIDs and `binlog_row_image=MINIMAL`. Enable the Performance Schema transaction instrument, statement timing, and the current and long-history consumers for both. Size both histories to retain the full run and capture after writers stop. The statement query yields no samples if either history is full.

1. Export `child_close_statements.sql` with `mysql -BN` after setting `@capacity_schema` to the fixture database name. The export contains transaction IDs and numeric metrics.
2. Use the same MySQL version's `mysqlbinlog --base64-output=DECODE-ROWS --verbose` on the complete binary logs in order. Pipe directly into `child_close_evidence.py` with `--schema`, the `--status-column` ordinal from `information_schema.columns`, `--expected-closes 1000`, and `--statements` pointing to the TSV export. Keep decoded row data in the pipe. The resulting JSON lines contain only a close index and numeric measurements.
3. Match the expected result, exactly one parent resume, and the closed history separately before using the measurements. Record the final close-to-resume interval and total fan-in time with each binding.

The reducer uses the binlog to identify committed child closes and joins each one to its Performance Schema transaction by GTID. This preserves attribution when several workers finish concurrently and when the server recycles prepared statements. Double-counted, missing or partially collected child closes fail validation. Statement counts cover top-level binary-protocol executions. They exclude transaction commands, prepares, and trigger substatements.

[MySQL transaction events and GTID assignment](https://dev.mysql.com/doc/refman/8.4/en/performance-schema-events-transactions-current-table.html)
