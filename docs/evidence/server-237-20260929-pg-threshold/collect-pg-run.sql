\set ON_ERROR_STOP on

SELECT 'run';
SELECT r.id, r.status, r.last_history_sequence, s.history_event_count,
       s.history_size_bytes, s.history_budget_pressure,
       s.continue_as_new_recommended, r.started_at, r.closed_at
FROM workflow_runs r
LEFT JOIN workflow_run_summaries s ON s.id = r.id
WHERE r.workflow_instance_id = :'workflow_id';

SELECT 'history_integrity';
SELECT COUNT(*), MIN(e.sequence), MAX(e.sequence), COUNT(DISTINCT e.sequence)
FROM workflow_runs r JOIN workflow_history_events e ON e.workflow_run_id = r.id
WHERE r.workflow_instance_id = :'workflow_id';

SELECT 'event_types';
SELECT e.event_type, COUNT(*)
FROM workflow_runs r JOIN workflow_history_events e ON e.workflow_run_id = r.id
WHERE r.workflow_instance_id = :'workflow_id'
GROUP BY e.event_type ORDER BY e.event_type;

SELECT 'tasks';
SELECT t.task_type, t.status, t.attempt_count, t.repair_count,
       ROUND(EXTRACT(EPOCH FROM (t.updated_at - t.created_at))::numeric, 3)
FROM workflow_runs r JOIN workflow_tasks t ON t.workflow_run_id = r.id
WHERE r.workflow_instance_id = :'workflow_id'
ORDER BY t.created_at, t.id;

SELECT 'task_latency_seconds_p50_p95_p99';
SELECT t.task_type, COUNT(*),
       percentile_cont(ARRAY[0.5, 0.95, 0.99]) WITHIN GROUP
           (ORDER BY EXTRACT(EPOCH FROM (t.updated_at - t.created_at))::double precision)
FROM workflow_runs r JOIN workflow_tasks t ON t.workflow_run_id = r.id
WHERE r.workflow_instance_id = :'workflow_id' AND t.status = 'completed'
GROUP BY t.task_type ORDER BY t.task_type;

SELECT 'open_tasks';
SELECT COUNT(*) FROM workflow_runs r JOIN workflow_tasks t ON t.workflow_run_id = r.id
WHERE r.workflow_instance_id = :'workflow_id' AND t.status IN ('ready', 'leased');

SELECT 'database_size_bytes';
SELECT pg_database_size(current_database());

SELECT 'relation_size_bytes';
SELECT relname, pg_total_relation_size(relid)
FROM pg_catalog.pg_statio_user_tables
WHERE relname IN ('workflow_history_events', 'workflow_run_timeline_entries',
                  'workflow_tasks', 'workflow_runs')
ORDER BY relname;
