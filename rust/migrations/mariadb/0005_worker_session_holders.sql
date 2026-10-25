-- Native development bootstrap version 5: the registration incarnation that
-- holds each worker session (Server #740). A same-ID re-registration rotates
-- the incarnation, which orphans the session until it is reacquired.
CREATE TABLE dw_worker_session_holders (
    namespace VARCHAR(128) NOT NULL,
    session_id VARCHAR(255) NOT NULL,
    incarnation CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (namespace, session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
