-- Maintenance scans bounded by open work, not retained history (Server #1256).
--
-- The scheduler tick (250 ms), the repair pass (5 s) and retention (60 s)
-- select runs and activity executions by open status, and retention takes a
-- namespace's summaries in close order. Without these indexes each pass read
-- every retained run. Each index is additive; no row is rewritten.
CREATE INDEX dw_workflow_runs_status_deadline ON workflow_runs (status,cancellation_deadline_at);
CREATE INDEX dw_activity_executions_status ON activity_executions (status);
CREATE INDEX dw_workflow_run_summaries_retention ON workflow_run_summaries (namespace,closed_at,id);
