-- Native development bootstrap version 6: the reclaim deadline of each closed
-- or errored durable workflow stream (Server #774), closed_at plus its
-- retention. The stream retention pass reads a namespace's due streams in
-- deadline order from (namespace, reclaim_at, stream_id). A stream leaves the
-- table once its items are reclaimed; its row stays until its run is pruned.
CREATE TABLE dw_stream_reclaims (
    stream_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    namespace VARCHAR(128) NOT NULL,
    workflow_run_id VARCHAR(26) NOT NULL,
    reclaim_at DATETIME(6) NOT NULL,
    KEY dw_stream_reclaims_due (namespace, reclaim_at, stream_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
UPDATE dw_server_schema SET version=6 WHERE engine='rust-development' AND version=5;
