CREATE INDEX dw_query_cache_expiration ON dw_query_cache (expiration, "key");
CREATE INDEX dw_poll_receipts_expiration ON dw_poll_receipts (rtrim(rtrim(CASE WHEN length(replace(replace(replace(expires_at,'T',' '),'Z',''),'+00:00',''))=19 THEN replace(replace(replace(expires_at,'T',' '),'Z',''),'+00:00','')||'.0' ELSE replace(replace(replace(expires_at,'T',' '),'Z',''),'+00:00','') END,'0'),'.'));
UPDATE dw_server_schema SET version=10 WHERE engine='rust-development';
