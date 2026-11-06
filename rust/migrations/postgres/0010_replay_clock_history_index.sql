-- Native development bootstrap version 10: bounded replay-start authority lookup.
CREATE INDEX workflow_history_events_run_type_sequence_index ON public.workflow_history_events (workflow_run_id, event_type, sequence);
UPDATE public.dw_server_schema SET version=10 WHERE engine='rust-development' AND version=9;
