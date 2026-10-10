-- Native development bootstrap version 4 extends the immutable v3 snapshot.
-- Existing v3/PHP databases remain refused; this is not a takeover migration.
CREATE TABLE dw_worker_registration_incarnations (
    token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    namespace VARCHAR(255) NOT NULL,
    worker_id VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL,
    recovered_workflow_task_count BIGINT NULL,
    finished_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    KEY dw_worker_incarnation_authority (namespace,worker_id,status),
    KEY dw_worker_incarnation_retention (status,finished_at),
    CONSTRAINT dw_worker_incarnation_state CHECK (
        (status='active' AND finished_at IS NULL AND recovered_workflow_task_count IS NULL)
        OR (status='superseded' AND finished_at IS NOT NULL AND recovered_workflow_task_count IS NULL)
        OR (status='deregistered' AND finished_at IS NOT NULL AND recovered_workflow_task_count IS NOT NULL AND recovered_workflow_task_count>=0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
