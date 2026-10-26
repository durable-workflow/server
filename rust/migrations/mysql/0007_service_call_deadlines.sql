-- Native development bootstrap version 7: the deadline of each admitted
-- Nexus service call whose operation set a deadline policy, resolved at
-- admission from the policy and accepted_at (published 2.11.1 keeps it in
-- workflow_service_calls.call_deadline_at; native keeps the frozen PHP tables
-- and its own relations). An open call past its deadline is timed out when
-- read; the deadline sweep reads (call_deadline_at, service_call_id).
CREATE TABLE dw_service_call_deadlines (
    service_call_id VARCHAR(26) NOT NULL PRIMARY KEY,
    namespace VARCHAR(128) NOT NULL,
    call_deadline_at DATETIME(6) NOT NULL,
    KEY dw_service_call_deadlines_due (call_deadline_at, service_call_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
UPDATE dw_server_schema SET version=7 WHERE engine='rust-development' AND version=6;
