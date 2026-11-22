CREATE TABLE IF NOT EXISTS dw_child_summary_checkpoints (
    run_id VARCHAR(26) NOT NULL,
    generation BIGINT NOT NULL,
    verified_generation BIGINT NOT NULL,
    history_sequence BIGINT NOT NULL,
    group_id VARCHAR(128) NOT NULL,
    PRIMARY KEY (run_id),
    CONSTRAINT dw_child_summary_checkpoint_run FOREIGN KEY (run_id) REFERENCES workflow_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX dw_child_calls_run_status_sequence ON workflow_child_calls(parent_workflow_run_id,status,sequence);
