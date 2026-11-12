-- Native schema 13 on MySQL only (server#1016): MySQL's json type stores
-- doubles in its own binary form and can round them. Store each JSON
-- document as exact utf8mb4_bin text instead, as MariaDB does, with an
-- enforced json_valid check. Each ALTER rebuilds one table; MySQL 8 DDL is
-- atomic per statement, so an interrupted upgrade resumes table by table.
-- MariaDB, PostgreSQL and SQLite are unchanged.
ALTER TABLE `activity_executions`
  MODIFY COLUMN `retry_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `parallel_group_path` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `activity_options` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_da40cddf645f92a0f5d3` CHECK (json_valid(`retry_policy`)),
  ADD CONSTRAINT `dw_json_text_b0ad277df9011ce8dcd8` CHECK (json_valid(`parallel_group_path`)),
  ADD CONSTRAINT `dw_json_text_5f011f3fc9ed1cbf3798` CHECK (json_valid(`activity_options`));
ALTER TABLE `dw_poll_receipts`
  MODIFY COLUMN `response` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  ADD CONSTRAINT `dw_json_text_b5a74d059c497aac89e1` CHECK (json_valid(`response`));
ALTER TABLE `dw_task_completions`
  MODIFY COLUMN `receipt` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  ADD CONSTRAINT `dw_json_text_49816cb4519d3d981518` CHECK (json_valid(`receipt`));
ALTER TABLE `runtime_credentials`
  MODIFY COLUMN `roles` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  MODIFY COLUMN `claims` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_075fecde0a757510fdf7` CHECK (json_valid(`roles`)),
  ADD CONSTRAINT `dw_json_text_5f7fdbad00226a90f331` CHECK (json_valid(`claims`));
ALTER TABLE `runtime_payload_completion_budgets`
  MODIFY COLUMN `context` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  MODIFY COLUMN `slots` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  MODIFY COLUMN `objects` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  ADD CONSTRAINT `dw_json_text_d1bf52994d698d7d04ed` CHECK (json_valid(`context`)),
  ADD CONSTRAINT `dw_json_text_4077efaae491546bebc7` CHECK (json_valid(`slots`)),
  ADD CONSTRAINT `dw_json_text_c185060f2dbee09c5a6a` CHECK (json_valid(`objects`));
ALTER TABLE `workflow_child_calls`
  MODIFY COLUMN `retry_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `timeout_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `arguments` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `metadata` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_55a3cac14b0a3feca840` CHECK (json_valid(`retry_policy`)),
  ADD CONSTRAINT `dw_json_text_40e45e4f94944c511ab5` CHECK (json_valid(`timeout_policy`)),
  ADD CONSTRAINT `dw_json_text_9c078ba0f6c256bb7d9c` CHECK (json_valid(`arguments`)),
  ADD CONSTRAINT `dw_json_text_016208f3b76c51671353` CHECK (json_valid(`metadata`));
ALTER TABLE `workflow_commands`
  MODIFY COLUMN `context` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_1365410084c0cd12ed37` CHECK (json_valid(`context`));
ALTER TABLE `workflow_durable_stream_items`
  MODIFY COLUMN `payload` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_9d624f24054e91d9250f` CHECK (json_valid(`payload`));
ALTER TABLE `workflow_durable_streams`
  MODIFY COLUMN `metadata` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_da0f067f234347705b1c` CHECK (json_valid(`metadata`));
ALTER TABLE `workflow_history_events`
  MODIFY COLUMN `payload` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_186a93a5a54b983f2444` CHECK (json_valid(`payload`));
ALTER TABLE `workflow_instances`
  MODIFY COLUMN `visibility_labels` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `memo` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_5dec5cdd6359edc700f2` CHECK (json_valid(`visibility_labels`)),
  ADD CONSTRAINT `dw_json_text_4d686a7e3879bc8d7c6c` CHECK (json_valid(`memo`));
ALTER TABLE `workflow_links`
  MODIFY COLUMN `parallel_group_path` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_c6fec96f4176ab012436` CHECK (json_valid(`parallel_group_path`));
ALTER TABLE `workflow_memos`
  MODIFY COLUMN `value` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  MODIFY COLUMN `portable_value` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_fcdcfa03c1ad3583628c` CHECK (json_valid(`value`)),
  ADD CONSTRAINT `dw_json_text_71688955589bdaf8f5c4` CHECK (json_valid(`portable_value`));
ALTER TABLE `workflow_messages`
  MODIFY COLUMN `metadata` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_b0a2ae51e86f4f80b9fb` CHECK (json_valid(`metadata`));
ALTER TABLE `workflow_namespaces`
  MODIFY COLUMN `external_payload_storage` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_4281ef3e52494643312f` CHECK (json_valid(`external_payload_storage`));
ALTER TABLE `workflow_run_lineage_entries`
  MODIFY COLUMN `payload` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_1874a82e6e4c5698bc68` CHECK (json_valid(`payload`));
ALTER TABLE `workflow_run_summaries`
  MODIFY COLUMN `visibility_labels` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_accf32ee2c1a832ab845` CHECK (json_valid(`visibility_labels`));
ALTER TABLE `workflow_run_timeline_entries`
  MODIFY COLUMN `payload` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_4a34bf0686f5464d6b56` CHECK (json_valid(`payload`));
ALTER TABLE `workflow_run_timer_entries`
  MODIFY COLUMN `payload` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_cb1a9145e9dc22308d3d` CHECK (json_valid(`payload`));
ALTER TABLE `workflow_run_waits`
  MODIFY COLUMN `payload` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_e331c14bcddf551ccfdd` CHECK (json_valid(`payload`));
ALTER TABLE `workflow_runs`
  MODIFY COLUMN `visibility_labels` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_0cabe9701a2ae7cb549e` CHECK (json_valid(`visibility_labels`));
ALTER TABLE `workflow_schedule_history_events`
  MODIFY COLUMN `payload` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_5c5faf594e8d31ebe991` CHECK (json_valid(`payload`));
ALTER TABLE `workflow_schedules`
  MODIFY COLUMN `spec` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `action` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `memo` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `search_attributes` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `visibility_labels` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `recent_actions` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `buffered_actions` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_d7aa273e112ccdb9bce4` CHECK (json_valid(`spec`)),
  ADD CONSTRAINT `dw_json_text_0779e5110faa8bb3a1ce` CHECK (json_valid(`action`)),
  ADD CONSTRAINT `dw_json_text_127f3a5484eae46b4296` CHECK (json_valid(`memo`)),
  ADD CONSTRAINT `dw_json_text_348ceaaa72578f94b30d` CHECK (json_valid(`search_attributes`)),
  ADD CONSTRAINT `dw_json_text_260b502951aabe6967a2` CHECK (json_valid(`visibility_labels`)),
  ADD CONSTRAINT `dw_json_text_023daf97483a6925003e` CHECK (json_valid(`recent_actions`)),
  ADD CONSTRAINT `dw_json_text_fcbf8efdd61dbed85da4` CHECK (json_valid(`buffered_actions`));
ALTER TABLE `workflow_search_attributes`
  MODIFY COLUMN `value_keyword_list` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'For type=keyword_list (ordered exact-match values)',
  ADD CONSTRAINT `dw_json_text_df0541f7f01fef92919f` CHECK (json_valid(`value_keyword_list`));
ALTER TABLE `workflow_service_calls`
  MODIFY COLUMN `deadline_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `idempotency_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `cancellation_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `retry_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `boundary_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `metadata` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `outcome_metadata` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `caller_principal_roles` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `caller_principal_claims` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_718533c1e82804b31504` CHECK (json_valid(`deadline_policy`)),
  ADD CONSTRAINT `dw_json_text_89af2bddb6216607276e` CHECK (json_valid(`idempotency_policy`)),
  ADD CONSTRAINT `dw_json_text_77a64a96c9d03d965701` CHECK (json_valid(`cancellation_policy`)),
  ADD CONSTRAINT `dw_json_text_89f01ea9712aa07575bf` CHECK (json_valid(`retry_policy`)),
  ADD CONSTRAINT `dw_json_text_e06e9f587b4efc8ce3e9` CHECK (json_valid(`boundary_policy`)),
  ADD CONSTRAINT `dw_json_text_4a6c5e40752d4ed0bdb4` CHECK (json_valid(`metadata`)),
  ADD CONSTRAINT `dw_json_text_b4ffcdd589152c314969` CHECK (json_valid(`outcome_metadata`)),
  ADD CONSTRAINT `dw_json_text_5a480bd9165f2eb68e43` CHECK (json_valid(`caller_principal_roles`)),
  ADD CONSTRAINT `dw_json_text_cb4fc45f46fe05d68f99` CHECK (json_valid(`caller_principal_claims`));
ALTER TABLE `workflow_service_endpoints`
  MODIFY COLUMN `boundary_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `metadata` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_6e65cde38938c5bf2752` CHECK (json_valid(`boundary_policy`)),
  ADD CONSTRAINT `dw_json_text_3c3c6c7139ec6ffc9fd5` CHECK (json_valid(`metadata`));
ALTER TABLE `workflow_service_operations`
  MODIFY COLUMN `handler_binding` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `deadline_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `idempotency_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `cancellation_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `retry_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `boundary_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `metadata` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_e844533982a5ba5f974a` CHECK (json_valid(`handler_binding`)),
  ADD CONSTRAINT `dw_json_text_aaf235f8970ff7618e0c` CHECK (json_valid(`deadline_policy`)),
  ADD CONSTRAINT `dw_json_text_e9b84c12c63f3f05d4f8` CHECK (json_valid(`idempotency_policy`)),
  ADD CONSTRAINT `dw_json_text_13e161b371e6bd18c390` CHECK (json_valid(`cancellation_policy`)),
  ADD CONSTRAINT `dw_json_text_2409229a6279aa76321a` CHECK (json_valid(`retry_policy`)),
  ADD CONSTRAINT `dw_json_text_05dd84f339d72c2ea677` CHECK (json_valid(`boundary_policy`)),
  ADD CONSTRAINT `dw_json_text_818561935c015a8f258c` CHECK (json_valid(`metadata`));
ALTER TABLE `workflow_services`
  MODIFY COLUMN `boundary_policy` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `metadata` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_1af5bae7acfe0b9d3f11` CHECK (json_valid(`boundary_policy`)),
  ADD CONSTRAINT `dw_json_text_4e612803d0ef9c0cfcaa` CHECK (json_valid(`metadata`));
ALTER TABLE `workflow_signal_records`
  MODIFY COLUMN `validation_errors` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_87990e1e6c171fd7edb3` CHECK (json_valid(`validation_errors`));
ALTER TABLE `workflow_tasks`
  MODIFY COLUMN `payload` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_edce43b6b7b815300bd1` CHECK (json_valid(`payload`));
ALTER TABLE `workflow_update_validation_tasks`
  MODIFY COLUMN `command_context` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `validation_errors` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_8648c83f3febc7faec99` CHECK (json_valid(`command_context`)),
  ADD CONSTRAINT `dw_json_text_fc8e8804602cda328168` CHECK (json_valid(`validation_errors`));
ALTER TABLE `workflow_updates`
  MODIFY COLUMN `validation_errors` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_efe19529950144852322` CHECK (json_valid(`validation_errors`));
ALTER TABLE `workflow_worker_build_id_rollouts`
  MODIFY COLUMN `workflow_types` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_19de9b23ee283257b769` CHECK (json_valid(`workflow_types`));
ALTER TABLE `workflow_worker_compatibility_heartbeats`
  MODIFY COLUMN `supported` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  ADD CONSTRAINT `dw_json_text_c9c8573bb83f23ce864d` CHECK (json_valid(`supported`));
ALTER TABLE `workflow_worker_registrations`
  MODIFY COLUMN `supported_workflow_types` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `workflow_definition_fingerprints` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `workflow_command_contracts` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `supported_activity_types` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `capabilities` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `capability_manifest` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  MODIFY COLUMN `process_metrics` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_9e5445d614888bb3661c` CHECK (json_valid(`supported_workflow_types`)),
  ADD CONSTRAINT `dw_json_text_4f49aa63cb080bc75d50` CHECK (json_valid(`workflow_definition_fingerprints`)),
  ADD CONSTRAINT `dw_json_text_5ff38312fcf7d855445d` CHECK (json_valid(`workflow_command_contracts`)),
  ADD CONSTRAINT `dw_json_text_4d23abbe7d6f10d67024` CHECK (json_valid(`supported_activity_types`)),
  ADD CONSTRAINT `dw_json_text_5db648194e26be45f445` CHECK (json_valid(`capabilities`)),
  ADD CONSTRAINT `dw_json_text_d1357dfe1fe52c7b2691` CHECK (json_valid(`capability_manifest`)),
  ADD CONSTRAINT `dw_json_text_34d29bf4c212ababa706` CHECK (json_valid(`process_metrics`));
ALTER TABLE `workflow_worker_sessions`
  MODIFY COLUMN `requirements` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  ADD CONSTRAINT `dw_json_text_177d4c6e72d9b9fdebda` CHECK (json_valid(`requirements`));
