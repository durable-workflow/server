ALTER TABLE workflow_tasks ADD COLUMN lost_lease_count INTEGER NOT NULL DEFAULT 0;
ALTER TABLE workflow_tasks ADD COLUMN last_lease_lost_at DATETIME;
UPDATE dw_server_schema SET version=18 WHERE engine='rust-development';
