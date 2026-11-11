-- Native development bootstrap version 12: bound run update contracts.
--
-- A run whose WorkflowStarted captured no update contract binds the
-- completing worker's contract once, with its first accepted workflow task
-- completion (Server #962). The row is admission state, not history.
CREATE TABLE dw_run_command_contracts (
    workflow_run_id VARCHAR(191) NOT NULL PRIMARY KEY,
    namespace VARCHAR(191) NOT NULL,
    workflow_type VARCHAR(255) NOT NULL,
    contract LONGTEXT NOT NULL,
    worker_id VARCHAR(255) NULL,
    workflow_task_id VARCHAR(191) NOT NULL,
    bound_at DATETIME(6) NOT NULL,
    KEY dw_run_command_contracts_namespace (namespace)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
