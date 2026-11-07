CREATE INDEX dw_workflow_tasks_scoped_dispatch ON workflow_tasks (namespace,queue,task_type(16),status(16),priority,available_at,id);
