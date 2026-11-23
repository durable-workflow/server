-- Run against the isolated benchmark database after all workload writers stop.
-- Set @capacity_schema to the fixture schema and export with mysql -BN.
-- Require enough space to keep both histories through the end of the run.
SET @capacity_history_complete =
    (SELECT COUNT(*) FROM performance_schema.events_statements_history_long)
        < @@performance_schema_events_statements_history_long_size
    AND (SELECT COUNT(*) FROM performance_schema.events_transactions_history_long)
        < @@performance_schema_events_transactions_history_long_size;

WITH executions AS (
    SELECT
        t.GTID AS gtid,
        s.CURRENT_SCHEMA AS fixture_schema,
        s.ROWS_EXAMINED AS rows_examined,
        s.ROWS_SENT AS rows_sent,
        s.ROWS_AFFECTED AS rows_affected,
        s.MYSQL_ERRNO AS mysql_errno,
        s.TIMER_WAIT AS elapsed_ps,
        s.LOCK_TIME AS lock_time_ps,
        ROW_NUMBER() OVER (PARTITION BY t.GTID ORDER BY s.EVENT_ID) AS position_in_transaction
    FROM performance_schema.events_transactions_history_long t
    JOIN performance_schema.events_statements_history_long s
      ON s.THREAD_ID = t.THREAD_ID
     AND s.NESTING_EVENT_ID = t.EVENT_ID
     AND s.NESTING_EVENT_TYPE = 'TRANSACTION'
    WHERE t.STATE = 'COMMITTED'
      AND t.GTID LIKE '%:%'
      AND s.EVENT_NAME = 'statement/com/Execute'
      AND s.CURRENT_SCHEMA = @capacity_schema
      AND @capacity_history_complete
)
SELECT
    gtid,
    MIN(fixture_schema),
    COUNT(*),
    SUM(rows_examined),
    SUM(rows_sent),
    SUM(rows_affected),
    SUM(mysql_errno <> 0),
    MAX(CASE WHEN position_in_transaction = 1 THEN rows_examined END),
    MAX(CASE WHEN position_in_transaction = 1 THEN rows_sent END),
    MAX(CASE WHEN position_in_transaction = 1 THEN rows_affected END),
    MAX(CASE WHEN position_in_transaction = 1 THEN elapsed_ps END),
    MAX(CASE WHEN position_in_transaction = 1 THEN lock_time_ps END)
FROM executions
GROUP BY gtid;
