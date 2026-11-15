-- Original object authority survives namespace write-policy changes.
ALTER TABLE runtime_external_payloads ADD COLUMN storage_binding TEXT NULL;
ALTER TABLE workflow_namespaces ADD COLUMN external_payload_storage_generation BIGINT NOT NULL DEFAULT 0;
