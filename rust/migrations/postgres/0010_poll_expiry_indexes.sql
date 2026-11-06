CREATE INDEX dw_query_cache_expiration ON dw_query_cache (expiration, "key");
CREATE INDEX dw_poll_receipts_expiration ON dw_poll_receipts (expires_at);
UPDATE dw_server_schema SET version=10 WHERE engine='rust-development';
