-- Child supervision across continue-as-new (Server #1184, Core workflow#868).
--
-- A run that continues as new hands each child it still supervises to the
-- continued run. Each row is one transfer, with the columns of Core's
-- workflow_child_supervisions: the supervising run, the run it came from,
-- the original child link, the run that started the child and its sequence,
-- the child and the policy. The original link, call and policy are never
-- rewritten. Rows live and are pruned with the supervising run.
CREATE TABLE dw_child_supervisions (
    id VARCHAR(26) NOT NULL,
    namespace VARCHAR(191) NOT NULL,
    supervising_workflow_run_id VARCHAR(26) NOT NULL,
    transferred_from_workflow_run_id VARCHAR(26) NOT NULL,
    workflow_link_id VARCHAR(26) NOT NULL,
    parent_workflow_instance_id VARCHAR(191) NOT NULL,
    parent_workflow_run_id VARCHAR(26) NOT NULL,
    sequence INT UNSIGNED NULL,
    child_workflow_instance_id VARCHAR(191) NOT NULL,
    child_workflow_run_id VARCHAR(26) NOT NULL,
    parent_close_policy VARCHAR(255) NOT NULL DEFAULT 'abandon',
    created_at TIMESTAMP(6) NULL DEFAULT NULL,
    updated_at TIMESTAMP(6) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY dw_child_supervisions_run_link (supervising_workflow_run_id, workflow_link_id),
    KEY dw_child_supervisions_link (workflow_link_id),
    KEY dw_child_supervisions_child (child_workflow_instance_id),
    KEY dw_child_supervisions_namespace (namespace)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
