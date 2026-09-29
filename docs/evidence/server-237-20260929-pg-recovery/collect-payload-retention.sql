\set ON_ERROR_STOP on

SELECT 'signal_external_references';
WITH decoded AS (
    SELECT workflow_instance_id,
           convert_from(decode(split_part(arguments, ':', 3), 'base64'), 'UTF8')::jsonb
               -> 'external_storage' AS reference
    FROM workflow_signal_records
    WHERE workflow_instance_id IN (
        'history-qualification-signals-2-payload-retained-active',
        'history-qualification-signals-1-payload-prune-completed'
    )
)
SELECT workflow_instance_id, reference ->> 'sha256', reference ->> 'uri', COUNT(*)
FROM decoded
GROUP BY workflow_instance_id, reference ->> 'sha256', reference ->> 'uri'
ORDER BY workflow_instance_id, reference ->> 'sha256';

SELECT 'stored_objects';
SELECT sha256, storage_uri, size_bytes, upload_status
FROM runtime_external_payloads WHERE namespace = 'default'
ORDER BY sha256;

SELECT 'runs';
SELECT workflow_instance_id, id, status, last_history_sequence
FROM workflow_runs
WHERE workflow_instance_id IN (
    'history-qualification-signals-2-payload-retained-active',
    'history-qualification-signals-1-payload-prune-completed'
)
ORDER BY workflow_instance_id;

SELECT 'history_rows';
SELECT r.workflow_instance_id, COUNT(e.id)
FROM workflow_runs r LEFT JOIN workflow_history_events e ON e.workflow_run_id = r.id
WHERE r.workflow_instance_id IN (
    'history-qualification-signals-2-payload-retained-active',
    'history-qualification-signals-1-payload-prune-completed'
)
GROUP BY r.workflow_instance_id ORDER BY r.workflow_instance_id;
