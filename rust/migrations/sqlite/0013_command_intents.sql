-- Native development bootstrap version 13: keyed command intents.
--
-- A signal or update admitted under a caller request_id records the
-- canonical intent digest of its command (Server #934): command type, name,
-- typed arguments and explicit run target, as Core's CommandIntent v1. A
-- retry under the same key replays the retained receipt only when its digest
-- matches, and is refused as a conflict otherwise. The row lives and is
-- pruned with its workflow_commands row; a command without a row predates
-- this version and its intent is unverified.
CREATE TABLE dw_command_intents (
    command_id TEXT NOT NULL PRIMARY KEY,
    namespace TEXT NOT NULL,
    intent_digest TEXT NOT NULL
);
CREATE INDEX dw_command_intents_namespace ON dw_command_intents (namespace);
UPDATE dw_server_schema SET version=13 WHERE engine='rust-development';
