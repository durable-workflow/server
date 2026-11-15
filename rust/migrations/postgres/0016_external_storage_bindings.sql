-- Original object authority survives namespace write-policy changes.
ALTER TABLE public.runtime_external_payloads ADD COLUMN storage_binding TEXT NULL;
ALTER TABLE public.workflow_namespaces ADD COLUMN external_payload_storage_generation BIGINT NOT NULL DEFAULT 0;
UPDATE public.dw_server_schema SET version=16 WHERE engine='rust-development';
