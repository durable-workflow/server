-- Native development bootstrap version 12: task queue activity dispatch pauses.
--
-- An operator can hold new activity dispatch on one task queue of one
-- namespace (Server #577). The row is admission state, not history: ready
-- activity tasks stay ready with no attempt used, their deadlines keep
-- running, and workflow, signal, query and update delivery is unchanged.
-- The queue is paused while resumed_at is null. Resume records who resumed
-- it and when, and keeps the row until the next pause replaces it.
CREATE TABLE dw_task_queue_pauses (
    namespace TEXT NOT NULL,
    task_queue TEXT NOT NULL,
    reason TEXT,
    paused_by TEXT,
    paused_at TEXT NOT NULL,
    resumed_by TEXT,
    resumed_at TEXT,
    PRIMARY KEY (namespace, task_queue)
);
UPDATE dw_server_schema SET version=12 WHERE engine='rust-development';
