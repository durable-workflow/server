-- Native development bootstrap version 11: bound run update contracts.
--
-- A run whose WorkflowStarted captured no update contract binds the
-- completing worker's contract once, with its first accepted workflow task
-- completion (Server #962). The row is admission state, not history.
CREATE TABLE dw_run_command_contracts (
    workflow_run_id TEXT NOT NULL PRIMARY KEY,
    namespace TEXT NOT NULL,
    workflow_type TEXT NOT NULL,
    contract TEXT NOT NULL,
    worker_id TEXT,
    workflow_task_id TEXT NOT NULL,
    bound_at TEXT NOT NULL
);
CREATE INDEX dw_run_command_contracts_namespace ON dw_run_command_contracts (namespace);
UPDATE dw_server_schema SET version=11 WHERE engine='rust-development';
