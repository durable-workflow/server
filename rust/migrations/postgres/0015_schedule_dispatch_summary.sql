-- Bounded atomic dispatch authority. Legacy schedules remain unknown.
ALTER TABLE public.workflow_schedules ADD COLUMN dispatch_summary TEXT NULL;
UPDATE public.dw_server_schema SET version=15 WHERE engine='rust-development';
