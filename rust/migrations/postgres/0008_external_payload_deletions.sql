-- Native development bootstrap version 8: committed external payload deletion.
--
-- dw_external_payload_deletions holds each provider deletion that a run,
-- stream or namespace prune released, recorded in the transaction that
-- releases the payload references (published Server 2.11.8 keeps the same
-- intention in runtime_external_payload_deletions). The provider object is
-- deleted only after that transaction commits; a provider failure leaves the
-- row for a later cleanup or retention pass. storage_policy is the
-- namespace storage policy at release, so a deleted namespace can still be
-- reclaimed. A new registration of the object fences the deletion.
CREATE TABLE dw_external_payload_deletions (
    id text NOT NULL PRIMARY KEY,
    namespace text NOT NULL,
    storage_uri text NOT NULL,
    storage_uri_sha256 text NOT NULL,
    storage_policy text NOT NULL,
    attempts bigint DEFAULT 0 NOT NULL,
    failure_code text,
    created_at timestamp(6) without time zone NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL
);
CREATE UNIQUE INDEX dw_external_payload_deletions_uri ON dw_external_payload_deletions (namespace, storage_uri_sha256);
CREATE INDEX dw_external_payload_deletions_due ON dw_external_payload_deletions (namespace, updated_at, id);
UPDATE dw_server_schema SET version=8 WHERE engine='rust-development' AND version=7;
