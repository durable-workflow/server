\set ON_ERROR_STOP on

SELECT 'runs';
SELECT id, run_number, status, closed_reason, last_history_sequence,
       started_at, closed_at
FROM workflow_runs WHERE workflow_instance_id = :'workflow_id'
ORDER BY run_number;

SELECT 'history_sequence_integrity';
SELECT r.id, r.run_number, COUNT(*), MIN(e.sequence), MAX(e.sequence),
       COUNT(DISTINCT e.sequence)
FROM workflow_runs r JOIN workflow_history_events e ON e.workflow_run_id = r.id
WHERE r.workflow_instance_id = :'workflow_id'
GROUP BY r.id, r.run_number ORDER BY r.run_number;

SELECT 'event_types';
SELECT e.event_type, COUNT(*)
FROM workflow_runs r JOIN workflow_history_events e ON e.workflow_run_id = r.id
WHERE r.workflow_instance_id = :'workflow_id'
GROUP BY e.event_type ORDER BY e.event_type;

SELECT 'task_states';
SELECT t.task_type, t.status, COUNT(*), MAX(t.attempt_count), MAX(t.repair_count)
FROM workflow_runs r JOIN workflow_tasks t ON t.workflow_run_id = r.id
WHERE r.workflow_instance_id = :'workflow_id'
GROUP BY t.task_type, t.status ORDER BY t.task_type, t.status;

SELECT 'pending_tasks';
SELECT COUNT(*) FROM workflow_runs r JOIN workflow_tasks t ON t.workflow_run_id = r.id
WHERE r.workflow_instance_id = :'workflow_id' AND t.status IN ('ready', 'leased');

SELECT 'signal_records';
SELECT status, COUNT(*) FROM workflow_signal_records
WHERE workflow_instance_id = :'workflow_id' GROUP BY status ORDER BY status;
