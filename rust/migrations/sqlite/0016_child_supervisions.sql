-- Child supervision across continue-as-new (Server #1184, Core workflow#868).
--
-- A run that continues as new hands each child it still supervises to the
-- continued run. Each row is one transfer, with the columns of Core's
-- workflow_child_supervisions: the supervising run, the run it came from,
-- the original child link, the run that started the child and its sequence,
-- the child and the policy. The original link, call and policy are never
-- rewritten. Rows live and are pruned with the supervising run.
CREATE TABLE dw_child_supervisions (
    id TEXT NOT NULL PRIMARY KEY,
    namespace TEXT NOT NULL,
    supervising_workflow_run_id TEXT NOT NULL,
    transferred_from_workflow_run_id TEXT NOT NULL,
    workflow_link_id TEXT NOT NULL,
    parent_workflow_instance_id TEXT NOT NULL,
    parent_workflow_run_id TEXT NOT NULL,
    sequence INTEGER NULL,
    child_workflow_instance_id TEXT NOT NULL,
    child_workflow_run_id TEXT NOT NULL,
    parent_close_policy TEXT NOT NULL DEFAULT 'abandon',
    created_at datetime NULL,
    updated_at datetime NULL
);
CREATE UNIQUE INDEX dw_child_supervisions_run_link ON dw_child_supervisions (supervising_workflow_run_id, workflow_link_id);
CREATE INDEX dw_child_supervisions_link ON dw_child_supervisions (workflow_link_id);
CREATE INDEX dw_child_supervisions_child ON dw_child_supervisions (child_workflow_instance_id);
CREATE INDEX dw_child_supervisions_namespace ON dw_child_supervisions (namespace);
UPDATE dw_server_schema SET version=16 WHERE engine='rust-development';
