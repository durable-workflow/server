-- Native development bootstrap version 14: task queue activity dispatch pauses.
--
-- An operator can hold new activity dispatch on one task queue of one
-- namespace (Server #577). The row is admission state, not history: ready
-- activity tasks stay ready with no attempt used, their deadlines keep
-- running, and workflow, signal, query and update delivery is unchanged.
-- The queue is paused while resumed_at is null. Resume records who resumed
-- it and when, and keeps the row until the next pause replaces it.
-- The scope columns compare exact bytes, as the native poll paths do.
CREATE TABLE dw_task_queue_pauses (
    namespace VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    task_queue VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    reason TEXT NULL,
    paused_by TEXT NULL,
    paused_at DATETIME(6) NOT NULL,
    resumed_by TEXT NULL,
    resumed_at DATETIME(6) NULL,
    PRIMARY KEY (namespace, task_queue)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
