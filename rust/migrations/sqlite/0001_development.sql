-- Native development bootstrap of the existing logical table families.
-- PHP takeover is deliberately refused until backup/conversion gates exist.
CREATE TABLE dw_server_schema (engine TEXT NOT NULL, version INTEGER NOT NULL);
INSERT INTO dw_server_schema VALUES ('rust-development', 1);
CREATE TABLE workflow_instances (
    id TEXT PRIMARY KEY, namespace TEXT NOT NULL, workflow_type TEXT NOT NULL,
    current_run_id TEXT NOT NULL, created_at TEXT NOT NULL
);
CREATE TABLE workflow_runs (
    id TEXT PRIMARY KEY, workflow_instance_id TEXT NOT NULL REFERENCES workflow_instances(id),
    namespace TEXT NOT NULL, workflow_type TEXT NOT NULL, workflow_class TEXT NOT NULL,
    run_number INTEGER NOT NULL, status TEXT NOT NULL, closed_reason TEXT,
    payload_codec TEXT NOT NULL, arguments TEXT NOT NULL, output TEXT,
    queue TEXT NOT NULL, last_history_sequence INTEGER NOT NULL DEFAULT 0,
    last_command_sequence INTEGER NOT NULL DEFAULT 0,
    execution_timeout_seconds INTEGER NOT NULL, run_timeout_seconds INTEGER NOT NULL,
    execution_deadline_at TEXT NOT NULL, run_deadline_at TEXT NOT NULL,
    started_at TEXT NOT NULL, closed_at TEXT,
    UNIQUE(workflow_instance_id, run_number)
);
CREATE TABLE workflow_history_events (
    id TEXT PRIMARY KEY, workflow_run_id TEXT NOT NULL REFERENCES workflow_runs(id),
    sequence INTEGER NOT NULL, event_type TEXT NOT NULL, payload TEXT NOT NULL,
    workflow_task_id TEXT, workflow_command_id TEXT, recorded_at TEXT NOT NULL,
    UNIQUE(workflow_run_id, sequence)
);
CREATE TABLE workflow_tasks (
    id TEXT PRIMARY KEY, workflow_run_id TEXT NOT NULL REFERENCES workflow_runs(id),
    namespace TEXT NOT NULL, task_type TEXT NOT NULL, status TEXT NOT NULL,
    payload TEXT NOT NULL, queue TEXT NOT NULL, available_at TEXT NOT NULL,
    lease_owner TEXT, lease_expires_at TEXT, attempt_count INTEGER NOT NULL DEFAULT 0,
    receipt TEXT
);
CREATE INDEX workflow_tasks_poll ON workflow_tasks(namespace, queue, task_type, status, available_at);
CREATE TABLE activity_executions (
    id TEXT PRIMARY KEY, workflow_run_id TEXT NOT NULL REFERENCES workflow_runs(id),
    sequence INTEGER NOT NULL, activity_type TEXT NOT NULL, activity_class TEXT NOT NULL,
    status TEXT NOT NULL, payload_codec TEXT NOT NULL, arguments TEXT NOT NULL,
    result TEXT, queue TEXT NOT NULL, retry_policy TEXT,
    UNIQUE(workflow_run_id, sequence)
);
CREATE TABLE activity_attempts (
    id TEXT PRIMARY KEY, activity_execution_id TEXT NOT NULL REFERENCES activity_executions(id),
    workflow_run_id TEXT NOT NULL REFERENCES workflow_runs(id), workflow_task_id TEXT NOT NULL,
    attempt_number INTEGER NOT NULL, status TEXT NOT NULL, lease_owner TEXT NOT NULL,
    lease_expires_at TEXT NOT NULL, started_at TEXT NOT NULL, closed_at TEXT,
    UNIQUE(activity_execution_id, attempt_number)
);
CREATE TABLE worker_registrations (
    namespace TEXT NOT NULL, worker_id TEXT NOT NULL, task_queue TEXT NOT NULL,
    definition TEXT NOT NULL, last_heartbeat_at TEXT NOT NULL,
    PRIMARY KEY(namespace, worker_id)
);
