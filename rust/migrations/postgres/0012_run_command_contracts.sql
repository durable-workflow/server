-- Native development bootstrap version 12: bound run update contracts.
--
-- A run whose WorkflowStarted captured no update contract binds the
-- completing worker's contract once, with its first accepted workflow task
-- completion (Server #962). The row is admission state, not history.
CREATE TABLE public.dw_run_command_contracts (
    workflow_run_id text NOT NULL PRIMARY KEY,
    namespace text NOT NULL,
    workflow_type text NOT NULL,
    contract text NOT NULL,
    worker_id text,
    workflow_task_id text NOT NULL,
    bound_at timestamp(6) without time zone NOT NULL
);
CREATE INDEX dw_run_command_contracts_namespace ON public.dw_run_command_contracts (namespace);
UPDATE public.dw_server_schema SET version=12 WHERE engine='rust-development';
