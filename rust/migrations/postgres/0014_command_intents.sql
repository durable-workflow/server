-- Native development bootstrap version 14: keyed command intents.
--
-- A signal or update admitted under a caller request_id records the
-- canonical intent digest of its command (Server #934): command type, name,
-- typed arguments and explicit run target, as Core's CommandIntent v1. A
-- retry under the same key replays the retained receipt only when its digest
-- matches, and is refused as a conflict otherwise. The row lives and is
-- pruned with its workflow_commands row; a command without a row predates
-- this version and its intent is unverified.
CREATE TABLE public.dw_command_intents (
    command_id character varying(26) NOT NULL,
    namespace text NOT NULL,
    intent_digest character varying(71) NOT NULL,
    PRIMARY KEY (command_id)
);
CREATE INDEX dw_command_intents_namespace ON public.dw_command_intents USING btree (namespace);
UPDATE public.dw_server_schema SET version=14 WHERE engine='rust-development';
