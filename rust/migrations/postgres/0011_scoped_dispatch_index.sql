DROP INDEX public.workflow_tasks_dispatch_order_index;
CREATE INDEX workflow_tasks_dispatch_order_index ON public.workflow_tasks (queue,status,priority,available_at,id);
CREATE INDEX dw_workflow_tasks_scoped_dispatch ON public.workflow_tasks (namespace,queue,task_type,status,priority,available_at,id);
UPDATE public.dw_server_schema SET version=11 WHERE engine='rust-development';
