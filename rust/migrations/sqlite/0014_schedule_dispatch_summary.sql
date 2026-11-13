-- Bounded atomic dispatch authority. Legacy schedules remain unknown.
ALTER TABLE workflow_schedules ADD COLUMN dispatch_summary TEXT NULL;
UPDATE dw_server_schema SET version=14 WHERE engine='rust-development';
