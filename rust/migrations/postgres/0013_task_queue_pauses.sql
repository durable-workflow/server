-- Native development bootstrap version 13: task queue activity dispatch pauses.
--
-- An operator can hold new activity dispatch on one task queue of one
-- namespace (Server #577). The row is admission state, not history: ready
-- activity tasks stay ready with no attempt used, their deadlines keep
-- running, and workflow, signal, query and update delivery is unchanged.
-- The queue is paused while resumed_at is null. Resume records who resumed
-- it and when, and keeps the row until the next pause replaces it.
CREATE TABLE public.dw_task_queue_pauses (
    namespace text NOT NULL,
    task_queue text NOT NULL,
    reason text,
    paused_by text,
    paused_at timestamp(6) without time zone NOT NULL,
    resumed_by text,
    resumed_at timestamp(6) without time zone,
    PRIMARY KEY (namespace, task_queue)
);
UPDATE public.dw_server_schema SET version=13 WHERE engine='rust-development';
