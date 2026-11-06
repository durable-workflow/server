-- Native development bootstrap version 9: authoritative task ready origins.
-- Existing rows remain unknown; creation age is not a readiness backfill.
ALTER TABLE public.workflow_tasks ADD COLUMN ready_origin_at TIMESTAMP(6) WITHOUT TIME ZONE NULL;
UPDATE public.dw_server_schema SET version=9 WHERE engine='rust-development' AND version=8;
