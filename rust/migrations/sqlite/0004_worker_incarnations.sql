-- Native development bootstrap version 4 extends the immutable v3 snapshot.
-- Existing v3/PHP databases remain refused; this is not a takeover migration.
CREATE TABLE dw_worker_registration_incarnations (
    token TEXT PRIMARY KEY NOT NULL,
    namespace TEXT NOT NULL,
    worker_id TEXT NOT NULL,
    status TEXT NOT NULL,
    recovered_workflow_task_count INTEGER,
    finished_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CONSTRAINT dw_worker_incarnation_state CHECK (
        (status='active' AND finished_at IS NULL AND recovered_workflow_task_count IS NULL)
        OR (status='superseded' AND finished_at IS NOT NULL AND recovered_workflow_task_count IS NULL)
        OR (status='deregistered' AND finished_at IS NOT NULL AND recovered_workflow_task_count IS NOT NULL AND recovered_workflow_task_count>=0)
    )
);
CREATE INDEX dw_worker_incarnation_authority ON dw_worker_registration_incarnations(namespace,worker_id,status);
CREATE INDEX dw_worker_incarnation_retention ON dw_worker_registration_incarnations(status,finished_at);
UPDATE dw_server_schema SET version=4 WHERE engine='rust-development' AND version=3;
