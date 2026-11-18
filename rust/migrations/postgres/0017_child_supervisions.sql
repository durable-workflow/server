-- Child supervision across continue-as-new (Server #1184, Core workflow#868).
--
-- A run that continues as new hands each child it still supervises to the
-- continued run. Each row is one transfer, with the columns of Core's
-- workflow_child_supervisions: the supervising run, the run it came from,
-- the original child link, the run that started the child and its sequence,
-- the child and the policy. The original link, call and policy are never
-- rewritten. Rows live and are pruned with the supervising run.
CREATE TABLE public.dw_child_supervisions (
    id character varying(26) NOT NULL,
    namespace text NOT NULL,
    supervising_workflow_run_id character varying(26) NOT NULL,
    transferred_from_workflow_run_id character varying(26) NOT NULL,
    workflow_link_id character varying(26) NOT NULL,
    parent_workflow_instance_id character varying(191) NOT NULL,
    parent_workflow_run_id character varying(26) NOT NULL,
    sequence integer,
    child_workflow_instance_id character varying(191) NOT NULL,
    child_workflow_run_id character varying(26) NOT NULL,
    parent_close_policy character varying(255) DEFAULT 'abandon'::character varying NOT NULL,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone,
    PRIMARY KEY (id)
);
CREATE UNIQUE INDEX dw_child_supervisions_run_link ON public.dw_child_supervisions USING btree (supervising_workflow_run_id, workflow_link_id);
CREATE INDEX dw_child_supervisions_link ON public.dw_child_supervisions USING btree (workflow_link_id);
CREATE INDEX dw_child_supervisions_child ON public.dw_child_supervisions USING btree (child_workflow_instance_id);
CREATE INDEX dw_child_supervisions_namespace ON public.dw_child_supervisions USING btree (namespace);
UPDATE public.dw_server_schema SET version=17 WHERE engine='rust-development';
