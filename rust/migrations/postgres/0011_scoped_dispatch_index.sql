CREATE INDEX dw_workflow_tasks_scoped_dispatch ON public.workflow_tasks (namespace COLLATE "C",queue COLLATE "C",task_type COLLATE "C",status COLLATE "C",priority,available_at,id);
UPDATE public.dw_server_schema SET version=11 WHERE engine='rust-development';
