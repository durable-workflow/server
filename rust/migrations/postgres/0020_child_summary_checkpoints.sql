CREATE TABLE public.dw_child_summary_checkpoints (
    run_id VARCHAR(26) PRIMARY KEY REFERENCES public.workflow_runs(id) ON DELETE CASCADE,
    generation BIGINT NOT NULL,
    verified_generation BIGINT NOT NULL,
    history_sequence BIGINT NOT NULL,
    group_id VARCHAR(128) NOT NULL
);

CREATE FUNCTION public.dw_track_child_summary_change() RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    before_run VARCHAR(26);
    after_run VARCHAR(26);
BEGIN
    IF TG_OP <> 'INSERT' THEN
        IF TG_ARGV[0] = 'workflow_run_id' THEN
            before_run := OLD.workflow_run_id;
        ELSIF TG_ARGV[0] = 'parent_workflow_run_id' THEN
            before_run := OLD.parent_workflow_run_id;
        ELSIF TG_ARGV[0] = 'current_run_id' THEN
            before_run := OLD.current_run_id;
        ELSE
            before_run := OLD.id;
        END IF;
    END IF;
    IF TG_OP <> 'DELETE' THEN
        IF TG_ARGV[0] = 'workflow_run_id' THEN
            after_run := NEW.workflow_run_id;
        ELSIF TG_ARGV[0] = 'parent_workflow_run_id' THEN
            after_run := NEW.parent_workflow_run_id;
        ELSIF TG_ARGV[0] = 'current_run_id' THEN
            after_run := NEW.current_run_id;
        ELSE
            after_run := NEW.id;
        END IF;
    END IF;
    UPDATE public.dw_child_summary_checkpoints
    SET generation = generation + 1
    WHERE run_id IN (before_run, after_run);
    RETURN NULL;
END;
$$;

CREATE TRIGGER dw_child_summary_history AFTER INSERT OR UPDATE OR DELETE ON public.workflow_history_events
FOR EACH ROW EXECUTE FUNCTION public.dw_track_child_summary_change('workflow_run_id');

CREATE TRIGGER dw_child_summary_wait AFTER INSERT OR UPDATE OR DELETE ON public.workflow_run_waits
FOR EACH ROW EXECUTE FUNCTION public.dw_track_child_summary_change('workflow_run_id');

CREATE TRIGGER dw_child_summary_call AFTER INSERT OR UPDATE OR DELETE ON public.workflow_child_calls
FOR EACH ROW EXECUTE FUNCTION public.dw_track_child_summary_change('parent_workflow_run_id');

CREATE TRIGGER dw_child_summary_link AFTER INSERT OR UPDATE OR DELETE ON public.workflow_links
FOR EACH ROW EXECUTE FUNCTION public.dw_track_child_summary_change('parent_workflow_run_id');

CREATE TRIGGER dw_child_summary_task AFTER INSERT OR UPDATE OR DELETE ON public.workflow_tasks
FOR EACH ROW EXECUTE FUNCTION public.dw_track_child_summary_change('workflow_run_id');

CREATE TRIGGER dw_child_summary_failure AFTER INSERT OR UPDATE OR DELETE ON public.workflow_failures
FOR EACH ROW EXECUTE FUNCTION public.dw_track_child_summary_change('workflow_run_id');

CREATE TRIGGER dw_child_summary_signal AFTER INSERT OR UPDATE OR DELETE ON public.workflow_signal_records
FOR EACH ROW EXECUTE FUNCTION public.dw_track_child_summary_change('workflow_run_id');

CREATE TRIGGER dw_child_summary_run AFTER INSERT OR UPDATE OR DELETE ON public.workflow_runs
FOR EACH ROW EXECUTE FUNCTION public.dw_track_child_summary_change('id');

CREATE TRIGGER dw_child_summary_current AFTER INSERT OR UPDATE OR DELETE ON public.workflow_instances
FOR EACH ROW EXECUTE FUNCTION public.dw_track_child_summary_change('current_run_id');

CREATE TRIGGER dw_child_summary_summary AFTER INSERT OR UPDATE OR DELETE ON public.workflow_run_summaries
FOR EACH ROW EXECUTE FUNCTION public.dw_track_child_summary_change('id');

CREATE INDEX dw_child_calls_run_status_sequence ON public.workflow_child_calls(parent_workflow_run_id, status, sequence);

UPDATE public.dw_server_schema SET version=20 WHERE engine='rust-development';
