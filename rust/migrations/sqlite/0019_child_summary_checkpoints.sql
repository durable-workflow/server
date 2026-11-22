CREATE TABLE dw_child_summary_checkpoints (
    run_id VARCHAR(26) NOT NULL PRIMARY KEY,
    generation INTEGER NOT NULL,
    verified_generation INTEGER NOT NULL,
    history_sequence INTEGER NOT NULL,
    group_id VARCHAR(128) NOT NULL,
    FOREIGN KEY (run_id) REFERENCES workflow_runs(id) ON DELETE CASCADE
);

CREATE TRIGGER dw_child_summary_history_insert AFTER INSERT ON workflow_history_events
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = NEW.workflow_run_id;
END;

CREATE TRIGGER dw_child_summary_history_update AFTER UPDATE ON workflow_history_events
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id IN (OLD.workflow_run_id, NEW.workflow_run_id);
END;

CREATE TRIGGER dw_child_summary_history_delete AFTER DELETE ON workflow_history_events
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = OLD.workflow_run_id;
END;

CREATE TRIGGER dw_child_summary_wait_insert AFTER INSERT ON workflow_run_waits
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = NEW.workflow_run_id;
END;

CREATE TRIGGER dw_child_summary_wait_update AFTER UPDATE ON workflow_run_waits
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id IN (OLD.workflow_run_id, NEW.workflow_run_id);
END;

CREATE TRIGGER dw_child_summary_wait_delete AFTER DELETE ON workflow_run_waits
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = OLD.workflow_run_id;
END;

CREATE TRIGGER dw_child_summary_call_insert AFTER INSERT ON workflow_child_calls
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = NEW.parent_workflow_run_id;
END;

CREATE TRIGGER dw_child_summary_call_update AFTER UPDATE ON workflow_child_calls
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id IN (OLD.parent_workflow_run_id, NEW.parent_workflow_run_id);
END;

CREATE TRIGGER dw_child_summary_call_delete AFTER DELETE ON workflow_child_calls
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = OLD.parent_workflow_run_id;
END;

CREATE TRIGGER dw_child_summary_link_insert AFTER INSERT ON workflow_links
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = NEW.parent_workflow_run_id;
END;

CREATE TRIGGER dw_child_summary_link_update AFTER UPDATE ON workflow_links
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id IN (OLD.parent_workflow_run_id, NEW.parent_workflow_run_id);
END;

CREATE TRIGGER dw_child_summary_link_delete AFTER DELETE ON workflow_links
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = OLD.parent_workflow_run_id;
END;

CREATE TRIGGER dw_child_summary_task_insert AFTER INSERT ON workflow_tasks
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = NEW.workflow_run_id;
END;

CREATE TRIGGER dw_child_summary_task_update AFTER UPDATE ON workflow_tasks
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id IN (OLD.workflow_run_id, NEW.workflow_run_id);
END;

CREATE TRIGGER dw_child_summary_task_delete AFTER DELETE ON workflow_tasks
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = OLD.workflow_run_id;
END;

CREATE TRIGGER dw_child_summary_failure_insert AFTER INSERT ON workflow_failures
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = NEW.workflow_run_id;
END;

CREATE TRIGGER dw_child_summary_failure_update AFTER UPDATE ON workflow_failures
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id IN (OLD.workflow_run_id, NEW.workflow_run_id);
END;

CREATE TRIGGER dw_child_summary_failure_delete AFTER DELETE ON workflow_failures
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = OLD.workflow_run_id;
END;

CREATE TRIGGER dw_child_summary_signal_insert AFTER INSERT ON workflow_signal_records
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = NEW.workflow_run_id;
END;

CREATE TRIGGER dw_child_summary_signal_update AFTER UPDATE ON workflow_signal_records
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id IN (OLD.workflow_run_id, NEW.workflow_run_id);
END;

CREATE TRIGGER dw_child_summary_signal_delete AFTER DELETE ON workflow_signal_records
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = OLD.workflow_run_id;
END;

CREATE TRIGGER dw_child_summary_run_insert AFTER INSERT ON workflow_runs
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = NEW.id;
END;

CREATE TRIGGER dw_child_summary_run_update AFTER UPDATE ON workflow_runs
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id IN (OLD.id, NEW.id);
END;

CREATE TRIGGER dw_child_summary_run_delete AFTER DELETE ON workflow_runs
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = OLD.id;
END;

CREATE TRIGGER dw_child_summary_current_insert AFTER INSERT ON workflow_instances
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = NEW.current_run_id;
END;

CREATE TRIGGER dw_child_summary_current_update AFTER UPDATE ON workflow_instances
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id IN (OLD.current_run_id, NEW.current_run_id);
END;

CREATE TRIGGER dw_child_summary_current_delete AFTER DELETE ON workflow_instances
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = OLD.current_run_id;
END;

CREATE TRIGGER dw_child_summary_summary_insert AFTER INSERT ON workflow_run_summaries
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = NEW.id;
END;

CREATE TRIGGER dw_child_summary_summary_update AFTER UPDATE ON workflow_run_summaries
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id IN (OLD.id, NEW.id);
END;

CREATE TRIGGER dw_child_summary_summary_delete AFTER DELETE ON workflow_run_summaries
BEGIN
    UPDATE dw_child_summary_checkpoints SET generation = generation + 1
    WHERE run_id = OLD.id;
END;

CREATE INDEX dw_child_calls_run_status_sequence ON workflow_child_calls(parent_workflow_run_id, status, sequence);

UPDATE dw_server_schema SET version=19 WHERE engine='rust-development';
