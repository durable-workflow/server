ALTER TABLE public.workflow_tasks
    ADD COLUMN lost_lease_count BIGINT NOT NULL DEFAULT 0,
    ADD COLUMN last_lease_lost_at TIMESTAMP(6) WITHOUT TIME ZONE;
UPDATE public.dw_server_schema SET version=19 WHERE engine='rust-development';
