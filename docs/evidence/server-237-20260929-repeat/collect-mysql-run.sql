SELECT 'run' AS section;
SELECT r.id, r.status, r.last_history_sequence, s.history_event_count,
       s.history_size_bytes, s.history_budget_pressure,
       s.continue_as_new_recommended, r.started_at, r.closed_at
FROM workflow_runs r
LEFT JOIN workflow_run_summaries s ON s.id = r.id
WHERE r.workflow_instance_id = @workflow_id;

SELECT 'history_integrity' AS section;
SELECT COUNT(*), MIN(e.sequence), MAX(e.sequence), COUNT(DISTINCT e.sequence)
FROM workflow_runs r JOIN workflow_history_events e ON e.workflow_run_id = r.id
WHERE r.workflow_instance_id = @workflow_id;

SELECT 'event_types' AS section;
SELECT e.event_type, COUNT(*)
FROM workflow_runs r JOIN workflow_history_events e ON e.workflow_run_id = r.id
WHERE r.workflow_instance_id = @workflow_id
GROUP BY e.event_type ORDER BY e.event_type;

SELECT 'tasks' AS section;
SELECT t.task_type, t.status, t.attempt_count, t.repair_count,
       ROUND(TIMESTAMPDIFF(MICROSECOND, t.created_at, t.updated_at) / 1000000, 3)
FROM workflow_runs r JOIN workflow_tasks t ON t.workflow_run_id = r.id
WHERE r.workflow_instance_id = @workflow_id
ORDER BY t.created_at, t.id;

SELECT 'task_latency_seconds_p50_p95_p99' AS section;
WITH durations AS (
    SELECT t.task_type,
           TIMESTAMPDIFF(MICROSECOND, t.created_at, t.updated_at) / 1000000 AS seconds
    FROM workflow_runs r JOIN workflow_tasks t ON t.workflow_run_id = r.id
    WHERE r.workflow_instance_id = @workflow_id AND t.status = 'completed'
), ranked AS (
    SELECT task_type, seconds,
           ROW_NUMBER() OVER (PARTITION BY task_type ORDER BY seconds) AS rn,
           COUNT(*) OVER (PARTITION BY task_type) AS n
    FROM durations
)
SELECT task_type, MAX(n),
       MAX(CASE WHEN rn = CEIL(n * 0.50) THEN seconds END),
       MAX(CASE WHEN rn = CEIL(n * 0.95) THEN seconds END),
       MAX(CASE WHEN rn = CEIL(n * 0.99) THEN seconds END)
FROM ranked GROUP BY task_type ORDER BY task_type;

SELECT 'open_tasks' AS section;
SELECT COUNT(*) FROM workflow_runs r JOIN workflow_tasks t ON t.workflow_run_id = r.id
WHERE r.workflow_instance_id = @workflow_id AND t.status IN ('ready', 'leased');

SELECT 'database_allocated_table_bytes' AS section;
SELECT SUM(data_length + index_length)
FROM information_schema.tables WHERE table_schema = DATABASE();

SELECT 'failed_jobs' AS section;
SELECT COUNT(*) FROM failed_jobs;
