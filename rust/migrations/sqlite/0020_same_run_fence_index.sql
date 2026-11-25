-- Same-run fence probes read only a run's leased workflow tasks (Server #1327).
--
-- Every workflow task candidate checks that no other workflow task of its run
-- holds a live lease (Server #1282). Through the run index alone each probe
-- read every task of the run. The index is additive and rewrites no row.
CREATE INDEX dw_workflow_tasks_run_fence ON workflow_tasks (workflow_run_id, task_type, status);
UPDATE dw_server_schema SET version=20 WHERE engine='rust-development';
