SET @workflow_id = 'history-qualification-rust-mixed4000-20260929';

SELECT 'runs';
SELECT id, run_number, status, last_history_sequence, closed_reason, started_at, closed_at
FROM workflow_runs WHERE workflow_instance_id = @workflow_id ORDER BY run_number;

SELECT 'history_sequence_integrity';
SELECT r.id, r.run_number, COUNT(*) AS event_count, MIN(e.sequence) AS first_sequence,
       MAX(e.sequence) AS last_sequence, COUNT(DISTINCT e.sequence) AS unique_sequences
FROM workflow_runs r JOIN workflow_history_events e ON e.workflow_run_id = r.id
WHERE r.workflow_instance_id = @workflow_id GROUP BY r.id, r.run_number ORDER BY r.run_number;

SELECT 'event_types';
SELECT e.event_type, COUNT(*) AS event_count
FROM workflow_runs r JOIN workflow_history_events e ON e.workflow_run_id = r.id
WHERE r.workflow_instance_id = @workflow_id GROUP BY e.event_type ORDER BY e.event_type;

SELECT 'task_states';
SELECT t.task_type, t.status, COUNT(*) AS task_count, MAX(t.attempt_count) AS max_attempts
FROM workflow_runs r JOIN workflow_tasks t ON t.workflow_run_id = r.id
WHERE r.workflow_instance_id = @workflow_id GROUP BY t.task_type, t.status
ORDER BY t.task_type, t.status;

SELECT 'pending_tasks';
SELECT COUNT(*) FROM workflow_runs r JOIN workflow_tasks t ON t.workflow_run_id = r.id
WHERE r.workflow_instance_id = @workflow_id AND t.status IN ('ready', 'leased');
