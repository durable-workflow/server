-- Maintenance scans bounded by open work, not retained history (Server #1256).
--
-- The scheduler tick (250 ms), the repair pass (5 s) and retention (60 s)
-- select runs and activity executions by open status, and retention takes a
-- namespace's summaries in close order. Without these indexes each pass read
-- every retained run. Each index is additive; no row is rewritten.
CREATE INDEX dw_workflow_runs_status_deadline ON public.workflow_runs USING btree (status, cancellation_deadline_at);
CREATE INDEX dw_activity_executions_status ON public.activity_executions USING btree (status);
CREATE INDEX dw_workflow_run_summaries_retention ON public.workflow_run_summaries USING btree (namespace, closed_at, id);
UPDATE public.dw_server_schema SET version=18 WHERE engine='rust-development';
