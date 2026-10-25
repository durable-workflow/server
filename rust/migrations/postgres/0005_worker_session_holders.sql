-- Native development bootstrap version 5: the registration incarnation that
-- holds each worker session (Server #740). A same-ID re-registration rotates
-- the incarnation, which orphans the session until it is reacquired.
CREATE TABLE dw_worker_session_holders (
    namespace text NOT NULL,
    session_id text NOT NULL,
    incarnation text NOT NULL,
    updated_at timestamp(6) without time zone NOT NULL,
    PRIMARY KEY (namespace, session_id)
);
UPDATE dw_server_schema SET version=5 WHERE engine='rust-development' AND version=4;
