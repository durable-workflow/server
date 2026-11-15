-- Original object authority survives namespace write-policy changes.
ALTER TABLE runtime_external_payloads ADD COLUMN storage_binding TEXT NULL;
ALTER TABLE workflow_namespaces ADD COLUMN external_payload_storage_generation INTEGER NOT NULL DEFAULT 0;
UPDATE dw_server_schema SET version=15 WHERE engine='rust-development';
