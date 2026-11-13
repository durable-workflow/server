-- Native development bootstrap version 14: keyed command intents.
--
-- A signal or update admitted under a caller request_id records the
-- canonical intent digest of its command (Server #934): command type, name,
-- typed arguments and explicit run target, as Core's CommandIntent v1. A
-- retry under the same key replays the retained receipt only when its digest
-- matches, and is refused as a conflict otherwise. The row lives and is
-- pruned with its workflow_commands row; a command without a row predates
-- this version and its intent is unverified.
CREATE TABLE dw_command_intents (
    command_id VARCHAR(26) NOT NULL,
    namespace VARCHAR(191) NOT NULL,
    intent_digest VARCHAR(71) NOT NULL,
    PRIMARY KEY (command_id),
    KEY dw_command_intents_namespace (namespace)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
