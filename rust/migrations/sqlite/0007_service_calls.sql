-- Native development bootstrap version 7: Nexus service calls.
--
-- dw_service_call_deadlines holds the deadline of each admitted call whose
-- operation set a deadline policy, resolved at admission from the policy and
-- accepted_at (published 2.11.1 keeps it in
-- workflow_service_calls.call_deadline_at; native keeps the frozen PHP tables
-- and its own relations). An open call past its deadline is timed out when
-- read; the deadline sweep reads (call_deadline_at, service_call_id).
CREATE TABLE dw_service_call_deadlines (
    service_call_id TEXT NOT NULL PRIMARY KEY,
    namespace TEXT NOT NULL,
    call_deadline_at TEXT NOT NULL
);
CREATE INDEX dw_service_call_deadlines_due ON dw_service_call_deadlines (call_deadline_at, service_call_id);
-- dw_service_admission_counters holds the shared boundary admission
-- counters PHP keeps in its cache: a per-minute rate count and a concurrency
-- lease count per boundary, each read as zero once expires_at has passed.
CREATE TABLE dw_service_admission_counters (
    cache_key TEXT NOT NULL PRIMARY KEY,
    amount INTEGER NOT NULL,
    expires_at TEXT NOT NULL
);
CREATE INDEX dw_service_admission_counters_expiry ON dw_service_admission_counters (expires_at);
UPDATE dw_server_schema SET version=7 WHERE engine='rust-development' AND version=6;
