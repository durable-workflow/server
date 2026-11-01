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
    id TEXT NOT NULL PRIMARY KEY,
    namespace TEXT NOT NULL,
    storage_uri TEXT NOT NULL,
    storage_uri_sha256 TEXT NOT NULL,
    storage_policy TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    failure_code TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX dw_external_payload_deletions_uri ON dw_external_payload_deletions (namespace, storage_uri_sha256);
CREATE INDEX dw_external_payload_deletions_due ON dw_external_payload_deletions (namespace, updated_at, id);
UPDATE dw_server_schema SET version=8 WHERE engine='rust-development' AND version=7;
