-- Native development bootstrap version 6: the reclaim deadline of each closed
-- or errored durable workflow stream (Server #774), closed_at plus its
-- retention. The stream retention pass reads a namespace's due streams in
-- deadline order from (namespace, reclaim_at, stream_id). A stream leaves the
-- table once its items are reclaimed; its row stays until its run is pruned.
CREATE TABLE dw_stream_reclaims (
    stream_id bigint NOT NULL PRIMARY KEY,
    namespace text NOT NULL,
    workflow_run_id text NOT NULL,
    reclaim_at timestamp(6) without time zone NOT NULL
);
CREATE INDEX dw_stream_reclaims_due ON dw_stream_reclaims (namespace, reclaim_at, stream_id);
UPDATE dw_server_schema SET version=6 WHERE engine='rust-development' AND version=5;
