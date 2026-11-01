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
    id VARCHAR(26) NOT NULL PRIMARY KEY,
    namespace VARCHAR(191) NOT NULL,
    storage_uri TEXT NOT NULL,
    storage_uri_sha256 VARCHAR(64) NOT NULL,
    storage_policy LONGTEXT NOT NULL,
    attempts BIGINT NOT NULL DEFAULT 0,
    failure_code VARCHAR(64) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY dw_external_payload_deletions_uri (namespace, storage_uri_sha256),
    KEY dw_external_payload_deletions_due (namespace, updated_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
UPDATE dw_server_schema SET version=8 WHERE engine='rust-development' AND version=7;
