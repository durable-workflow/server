<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for published-artifact v1-to-v2 migration
 * conformance.
 *
 * Storage-connection smoke is useful guardrail evidence, but the migration
 * category only passes when a real v1 install follows the public guide into a
 * working v2 install with preserved state and loud skew refusal.
 */
final class MigrationRuntimeContract
{
    public const SCHEMA = 'durable-workflow.v2.migration-runtime.contract';

    public const VERSION = 2;

    public const RESULT_SCHEMA = 'durable-workflow.v2.migration-runtime.result';

    public const RESULT_VERSION = 2;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'result_schema' => self::RESULT_SCHEMA,
            'result_version' => self::RESULT_VERSION,
            'fixture_category' => 'migration_runtime_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'migration_runtime_contract',
                'suite_schema' => PlatformConformanceSuite::SCHEMA,
                'suite_version' => PlatformConformanceSuite::VERSION,
                'public_path' => 'https://durable-workflow.github.io/platform-conformance/migration-runtime-scenarios.json',
                'source_path' => 'static/platform-conformance/migration-runtime-scenarios.json',
            ],
            'artifact_policy' => [
                'version_source' => 'latest_complete_published_artifact_set_at_run_time',
                'version_requirement' => 'concrete_published_versions_with_downloadable_assets_pinned_at_run_time',
                'placeholder_versions_rejected' => true,
                'release_records_without_assets_are_rejected' => true,
                'placeholder_version_examples' => [
                    'latest',
                    'current',
                    'head',
                    'unresolved',
                    'placeholder',
                    '<latest>',
                    '1.x',
                    '2.0.0-alpha.<latest>',
                    '${VERSION}',
                    '{{ version }}',
                ],
                'install_channels' => [
                    'server-v1' => 'latest supported v1 server runtime pinned from a public channel; use the standalone durableworkflow/server v1 image when published, otherwise use the embedded runtime from the selected latest v1 workflow package release',
                    'server-v2' => 'Docker image durableworkflow/server:<exact patch version or digest with DW_SERVER_VERSION>',
                    'cli-v1' => 'latest supported v1 dw CLI release asset or install path after downloadability check',
                    'cli-v2' => 'official dw GitHub release install.sh asset for the target v2 train after downloadability check',
                    'workflow-php-v1' => 'Composer package durable-workflow/workflow at the latest supported v1 release; allow the laravel-workflow/laravel-workflow legacy alias only when it resolves to the same or a newer supported release',
                    'workflow-php-v2' => 'Composer package durable-workflow/workflow:2.0.0-alpha.<exact>',
                    'sdk-python' => 'PyPI package durable-workflow==<exact>',
                    'waterline-v1' => 'published Waterline package at the latest supported v1 release',
                    'waterline-v2' => 'published Waterline package matching the target v2 release set',
                    'sample-app-v1' => 'published v1-compatible sample-app tag or commit used to seed realistic migration state',
                ],
                'release_artifact_aliases' => [
                    'cli-v2' => ['cli'],
                    'workflow-php-v1' => ['workflow-v1'],
                    'workflow-php-v2' => ['workflow', 'workflow-php'],
                    'waterline-v2' => ['waterline'],
                ],
                'forbidden_sources' => [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                    'release_tag_without_required_assets',
                    'rolling_server_image_tag',
                    'not_exercised',
                    'unverified_artifact_source',
                ],
                'required_run_record_fields' => [
                    'published_artifact_versions',
                    'resolved_artifact_versions',
                    'artifact_sources',
                    'started_at',
                    'finished_at',
                    'generated_at',
                    'outcome',
                    'runner_blocked',
                    'scenario_results',
                    'findings',
                    'finding_links',
                    'source_capabilities',
                    'migration_plan',
                    'preupgrade_state_snapshot',
                    'postupgrade_state_snapshot',
                    'history_dumps',
                    'activity_attempts',
                    'schedule_ticks',
                    'worker_registration_observations',
                    'cli_observations',
                    'waterline_observations',
                    'rollback_observations',
                    'version_skew_observations',
                    'storage_connection_smoke',
                ],
            ],
            'scenario_statuses' => [
                'pass',
                'fail',
                'unsupported',
                'not_applicable',
                'not_covered',
                'runner_blocked',
            ],
            'source_capability_policy' => [
                'inventory_field' => 'source_capabilities',
                'inventory_schema' => 'durable-workflow.v2.migration-runtime.source-capabilities',
                'allowed_statuses' => [
                    'supported',
                    'unsupported',
                ],
                'required_capabilities' => [
                    'completed_history' => [
                        'continuity' => 'required',
                        'state_kind' => 'completed_history',
                    ],
                    'in_flight_workflow' => [
                        'continuity' => 'required',
                        'state_kind' => 'in_flight_workflow',
                    ],
                    'retrying_activity' => [
                        'continuity' => 'required',
                        'state_kind' => 'retrying_activity',
                    ],
                    'queue_state' => [
                        'continuity' => 'required',
                        'state_kind' => 'queue_state',
                    ],
                    'schedule' => [
                        'continuity' => 'when_source_supported',
                        'state_kind' => 'schedule',
                        'absent_reason_code' => 'v1_embedded_runtime_no_durable_schedule_surface',
                    ],
                    'worker_registration' => [
                        'continuity' => 'when_source_supported',
                        'state_kind' => 'worker_registration',
                        'absent_reason_code' => 'v1_embedded_runtime_no_worker_registration_projection',
                    ],
                    'standalone_server_api' => [
                        'continuity' => 'not_a_durable_state_cell',
                        'absent_reason_code' => 'v1_embedded_runtime_no_standalone_server_api',
                    ],
                    'standalone_cli_server_surface' => [
                        'continuity' => 'not_a_durable_state_cell',
                        'absent_reason_code' => 'v1_embedded_runtime_no_standalone_cli_server_surface',
                    ],
                    'remote_worker_endpoint' => [
                        'continuity' => 'not_a_durable_state_cell',
                        'absent_reason_code' => 'v1_embedded_runtime_no_remote_worker_endpoint',
                    ],
                ],
                'embedded_v1_profile' => [
                    'runtime_topology' => 'embedded_laravel',
                    'detection_source' => 'server-v1 published artifact runtime metadata',
                    'supported_capabilities' => [
                        'completed_history',
                        'in_flight_workflow',
                        'retrying_activity',
                        'queue_state',
                    ],
                    'unsupported_capabilities' => [
                        'schedule',
                        'worker_registration',
                        'standalone_server_api',
                        'standalone_cli_server_surface',
                        'remote_worker_endpoint',
                    ],
                ],
                'not_applicable_scenarios' => [
                    'schedule_cross_upgrade_cadence_preserved' => 'schedule',
                    'worker_registration_projection_preserved' => 'worker_registration',
                ],
                'not_applicable_requires' => [
                    'status_not_applicable',
                    'source_capability_unsupported',
                    'stable_reason_code',
                    'no_durable_state_mutation_attempted',
                ],
            ],
            'topology' => [
                'namespace' => 'migration-conformance',
                'storage' => 'persistent volume reused across v1 and v2 upgrade phases',
                'task_queues' => [
                    'workflow-php-v1' => 'migration-v1',
                    'workflow-php-v2' => 'migration-v2',
                    'sdk-python' => 'migration-python',
                ],
                'required_preupgrade_state' => [
                    'completed_workflow',
                    'running_workflow_waiting_on_signal',
                    'workflow_with_activity',
                    'workflow_mid_activity_retry',
                    'queued_workflow_or_activity_task',
                    'queryable_history',
                    'waterline_projection',
                ],
                'required_postupgrade_v2_control_plane_state' => [
                    'active_schedule',
                    'registered_worker',
                ],
                'operator_visibility_paths' => [
                    'dw workflow:describe <workflow-id>',
                    'dw workflow:show-run <workflow-id> <run-id>',
                    'dw workflow:history <workflow-id> <run-id>',
                    'dw workflow:history-export <workflow-id> <run-id>',
                    'dw schedule:list',
                    'dw worker:list --task-queue=<unique-task-queue> --output=json',
                    'dw worker:describe <worker-id> --output=json',
                    'GET /api/workflows/{workflowId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}/history',
                    'GET /api/schedules',
                    'GET /api/workers/{workerId}',
                    'GET /waterline/api/instances/{instanceId}',
                    'GET /waterline/api/instances/{instanceId}/runs/{runId}',
                ],
            ],
            'required_matrix' => [
                'source_release_set' => [
                    'server-v1',
                    'cli-v1',
                    'workflow-php-v1',
                    'waterline-v1',
                    'sample-app-v1',
                ],
                'target_release_set' => [
                    'server-v2',
                    'cli-v2',
                    'workflow-php-v2',
                    'sdk-python',
                    'waterline-v2',
                ],
                'client_paths' => [
                    'cli-v1',
                    'cli-v2',
                    'sdk-php',
                    'sdk-python',
                ],
                'operator_visibility_paths' => [
                    'dw workflow:describe <workflow-id>',
                    'dw workflow:show-run <workflow-id> <run-id>',
                    'dw workflow:history <workflow-id> <run-id>',
                    'dw workflow:history-export <workflow-id> <run-id>',
                    'dw schedule:list',
                    'dw worker:list --task-queue=<unique-task-queue> --output=json',
                    'dw worker:describe <worker-id> --output=json',
                    'GET /api/workflows/{workflowId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}/history',
                    'GET /api/schedules',
                    'GET /api/workers/{workerId}',
                    'GET /waterline/api/instances/{instanceId}',
                    'GET /waterline/api/instances/{instanceId}/runs/{runId}',
                ],
                'state_kinds' => [
                    'completed_history',
                    'in_flight_workflow',
                    'retrying_activity',
                    'queue_state',
                    'schedule',
                    'worker_registration',
                ],
                'skew_cells' => [
                    [
                        'server' => 'server-v1',
                        'client' => 'cli-v2',
                        'scenario' => 'version_skew_refusal',
                        'requires_source_capabilities' => [
                            'standalone_server_api',
                        ],
                    ],
                    [
                        'server' => 'server-v2',
                        'client' => 'cli-v1',
                        'scenario' => 'version_skew_refusal',
                        'requires_source_capabilities' => [
                            'standalone_cli_server_surface',
                        ],
                    ],
                    [
                        'server' => 'server-v1',
                        'worker' => 'workflow-php-v2',
                        'scenario' => 'version_skew_refusal',
                        'requires_source_capabilities' => [
                            'standalone_server_api',
                            'remote_worker_endpoint',
                        ],
                    ],
                    [
                        'server' => 'server-v2',
                        'worker' => 'workflow-php-v1',
                        'scenario' => 'version_skew_refusal',
                        'requires_source_capabilities' => [
                            'remote_worker_endpoint',
                        ],
                    ],
                ],
            ],
            'required_scenarios' => [
                'published_artifact_install_only',
                'latest_supported_v1_state_setup',
                'documented_migration_steps_execute',
                'completed_history_preservation_and_replay',
                'in_flight_workflow_progress_preserved',
                'mid_activity_retry_preserved',
                'queue_state_preserved',
                'schedule_cross_upgrade_cadence_preserved',
                'worker_registration_projection_preserved',
                'waterline_operator_visibility_preserved',
                'cli_access_to_preupgrade_state',
                'new_v2_workflow_start_after_upgrade',
                'new_v2_schedule_after_upgrade',
                'new_v2_worker_registration_after_upgrade',
                'rollback_contract_verified',
                'version_skew_refusal',
            ],
            'scenario_requirements' => self::scenarioRequirements(),
            'advisory_evidence' => [
                'storage_connection_smoke' => [
                    'status' => 'required_context_not_passing_by_itself',
                    'outcome_when_only_evidence' => 'non_passing',
                    'covered_assertions' => [
                        'workflows.storage.connection defaults to null for backward compatibility',
                        'DW_STORAGE_CONNECTION configures the storage connection',
                        'representative v1, v2, and hardcoded-usage models resolve to the configured connection',
                        'package migrations use Workflow\Support\WorkflowMigration',
                        'Laravel migrator creates representative workflow tables on the dedicated workflow storage connection',
                    ],
                ],
            ],
            'coverage_gate' => [
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'all_required_artifacts_resolved_from_published_channels',
                    'latest_supported_v1_state_seeded_with_realistic_workflows',
                    'public_migration_guide_steps_executed_verbatim',
                    'completed_history_preservation_and_replay_reported',
                    'in_flight_progress_mid_activity_retry_schedule_and_worker_cells_reported',
                    'source_capability_inventory_recorded_before_continuity_evaluation',
                    'v1_durable_queue_state_preservation_reported',
                    'source_absent_control_plane_cells_are_explicitly_not_applicable',
                    'cli_and_waterline_preupgrade_state_visibility_reported',
                    'new_v2_workflow_start_reported',
                    'new_v2_schedule_and_worker_registration_reported',
                    'rollback_or_documented_no_rollback_reported',
                    'rollback_public_operator_signal_recorded',
                    'cli_and_worker_skew_request_response_evidence_recorded',
                    'version_skew_refusal_reported',
                    'storage_connection_smoke_is_recorded_but_not_counted_as_complete',
                    'run_timestamps_outcome_and_findings_are_recorded',
                    'declared_outcome_matches_evaluated_status',
                    'artifact_source_recorded_for_each_install_channel',
                    'artifact_prerequisite_failures_are_linked_when_artifacts_are_missing',
                    'realistic_preupgrade_state_cells_are_recorded',
                    'migration_guide_command_timings_are_recorded',
                    'local_product_source_checkouts_used_explicitly_false',
                    'runner_blocked_false_for_product_evidence',
                    'findings_linked_for_non_pass_scenarios',
                ],
                'uncovered_required_scenario_outcome' => 'non_passing',
                'smoke_subset_outcome' => 'non_passing',
                'storage_connection_smoke_only_outcome' => 'non_passing',
                'unsupported_public_surface_outcome' => 'non_passing_with_root_cause_finding',
                'source_absent_control_plane_cell_outcome' => 'passing_not_applicable_with_stable_reason',
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
            ],
            'host_runner_contract' => [
                'status' => 'required_for_passing_migration_conformance',
                'result_schema' => self::RESULT_SCHEMA,
                'must_execute_against_published_artifacts' => true,
                'must_record_runner_blocked_false_for_product_evidence' => true,
                'must_start_from_latest_supported_v1_release' => true,
                'must_seed_realistic_v1_state' => true,
                'must_follow_public_migration_guide_verbatim' => true,
                'must_emit_result_for_every_required_scenario' => true,
                'storage_connection_smoke_only_outcome' => 'non_passing',
                'unexecuted_required_scenario_status' => 'not_covered',
                'coverage_gap_finding_type' => 'conformance_runner_coverage_gap',
                'coverage_gap_owner' => 'conformance_harness',
                'runner_path' => 'scripts/conformance/migration-published-artifacts.sh',
                'runner_command' => 'scripts/conformance/migration-published-artifacts.sh --result-dir <result-dir>',
                'expected_output_files' => [
                    'migration-published-artifacts.json',
                    'migration-conformance-result.json',
                    'migration-conformance-record.json',
                ],
                'evidence_inputs' => [
                    'DW_MIGRATION_EVIDENCE_JSON' => 'Optional full-result, runbook-shaped, sectioned runbookCommandOutputs, or scenario-shard JSON captured by the host runner after executing the public migration guide against published artifacts. May include source_capabilities; embedded v1 runtime metadata selects the published embedded capability profile automatically.',
                    'DW_MIGRATION_EVIDENCE_DIR' => 'Optional directory of JSON evidence shards; files are merged in lexical order so the host runner can collect required migration scopes independently.',
                    'DW_MIGRATION_FOUNDATION_PLAN_FILE' => 'Optional JSON host command plan for ordered v1 setup, public-guide upgrade, preservation snapshots, queued-task continuity, a focused post-upgrade public-CLI v2 schedule create/CLI describe/operator API describe/trigger/history/run cell on an isolated public server artifact, and the v2 worker registration, typed projection, and poll cell.',
                    'DW_MIGRATION_FOUNDATION_PLAN_JSON' => 'Optional inline JSON foundation plan or path to a JSON plan.',
                    'DW_MIGRATION_RUN_FOUNDATION_PLAN' => 'Set to 0/false/no to disable automatic foundation-plan execution, or 1/true/force to require a supplied plan.',
                    'DW_MIGRATION_STORAGE_SMOKE_JSON' => 'Optional storage-connection smoke JSON to attach as context. Focused foundation runs may include migration_plan, latest_supported_v1_state_setup, preupgrade_state_snapshot, and postupgrade_state_snapshot evidence in this file.',
                    'DW_MIGRATION_RUN_PUBLIC_GUIDE_AUDIT' => 'Set to 0/false/no to disable the automatic live public migration-guide audit, or 1/true/force to run it even without storage-smoke evidence.',
                    'DW_MIGRATION_GUIDE_URL' => 'Optional public migration guide URL for the automatic guide-audit shard. Defaults to the versioned 2.0 migration guide.',
                    'DW_MIGRATION_GUIDE_AUDIT_TEXT' => 'Optional inline public-guide text fixture for deterministic guide-audit runs.',
                    'DW_MIGRATION_GUIDE_AUDIT_FILE' => 'Optional public-guide text or HTML fixture path for deterministic guide-audit runs.',
                    'DW_MIGRATION_RESOLVE_PUBLIC_ARTIFACTS' => 'Set to 0/false/no to disable default public-channel resolution for missing latest supported v1 artifact pins.',
                    'DW_MIGRATION_PUBLIC_ARTIFACTS_JSON' => 'Optional JSON cache/fixture for public artifact resolution. Supports artifact_versions and artifact_sources maps.',
                ],
                'required_execution_scopes' => [
                    'published-artifact-install',
                    'latest-supported-v1-state',
                    'public-guide-upgrade',
                    'completed-history-replay',
                    'in-flight-progress',
                    'mid-activity-retry',
                    'queue-state',
                    'schedule-cadence',
                    'worker-registration-projection',
                    'waterline-operator-visibility',
                    'cli-access-to-preupgrade-state',
                    'new-v2-start',
                    'new-v2-schedule',
                    'new-v2-worker-registration',
                    'rollback-contract',
                    'version-skew-refusal',
                    'storage-connection-smoke',
                ],
                'merge_policy' => [
                    'input_scopes' => [
                        'published-artifact-install',
                        'latest-supported-v1-state',
                        'public-guide-upgrade',
                        'completed-history-replay',
                        'in-flight-progress',
                        'mid-activity-retry',
                        'queue-state',
                        'schedule-cadence',
                        'worker-registration-projection',
                        'waterline-operator-visibility',
                        'cli-access-to-preupgrade-state',
                        'new-v2-start',
                        'new-v2-schedule',
                        'new-v2-worker-registration',
                        'rollback-contract',
                        'version-skew-refusal',
                        'storage-connection-smoke',
                    ],
                    'requires_required_scenarios' => 'migration_runtime_contract.required_scenarios',
                    'requires_sections' => [
                        'published_artifact_install',
                        'migration_plan',
                        'preupgrade_state_snapshot',
                        'postupgrade_state_snapshot',
                        'history_dumps',
                        'activity_attempts',
                        'schedule_ticks',
                        'worker_registration_observations',
                        'cli_observations',
                        'waterline_observations',
                        'rollback_observations',
                        'version_skew_observations',
                        'storage_connection_smoke',
                    ],
                ],
                'routing_policy' => [
                    'artifact_prerequisite_failure' => [
                        'scenario_status' => 'fail',
                        'finding_type' => 'missing_or_invalid_published_migration_artifact',
                        'owner' => 'artifact_surface_owner',
                    ],
                    'missing_required_scenario' => [
                        'scenario_status' => 'not_covered',
                        'finding_type' => 'conformance_runner_coverage_gap',
                        'owner' => 'conformance_harness',
                    ],
                    'host_environment_failure' => [
                        'scenario_status' => 'runner_blocked',
                        'finding_type' => 'runner_gap',
                        'owner' => 'conformance_harness',
                    ],
                    'product_behavior_failure' => [
                        'scenario_status' => 'fail',
                        'finding_source' => 'migration_runtime_contract.finding_policy',
                    ],
                    'unsupported_public_surface' => [
                        'scenario_status' => 'unsupported',
                        'finding_source' => 'migration_runtime_contract.finding_policy',
                    ],
                    'source_capability_absent' => [
                        'scenario_status' => 'not_applicable',
                        'finding_required' => false,
                        'reason_source' => 'migration_runtime_contract.source_capability_policy',
                    ],
                ],
            ],
            'result_gate' => MigrationRuntimeResultGate::spec(),
            'finding_policy' => [
                'missing_or_invalid_published_migration_artifact' => 'link_root_cause_finding_against_artifact_surface_owner',
                'missing_or_wrong_migration_guide_step' => 'link_root_cause_finding_against_docs',
                'data_loss_or_replay_break' => 'link_root_cause_finding_against_workflow_or_server',
                'queue_state_loss' => 'link_root_cause_finding_against_workflow_or_server',
                'schedule_drift' => 'link_root_cause_finding_against_server_or_workflow',
                'waterline_visibility_break' => 'link_root_cause_finding_against_waterline',
                'cli_regression' => 'link_root_cause_finding_against_cli',
                'worker_compatibility_gap' => 'link_root_cause_finding_against_workflow_or_sdk',
                'rollback_mismatch' => 'link_root_cause_finding_against_docs_or_product_owner',
                'skew_silence' => 'link_root_cause_finding_against_skewed_surface_owner',
                'conformance_runner_coverage_gap' => 'link_root_cause_finding_against_conformance_harness',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function scenarioRequirements(): array
    {
        return [
            'published_artifact_install_only' => [
                'required_fields' => [
                    'resolved_artifact_versions',
                    'artifact_sources',
                    'local_product_source_checkouts_used',
                ],
            ],
            'latest_supported_v1_state_setup' => [
                'required_fields' => [
                    'source_release_versions',
                    'source_capabilities',
                    'seeded_workflows',
                    'seeded_schedules',
                    'seeded_worker_registrations',
                    'seeded_queue_state',
                    'queryable_history',
                ],
            ],
            'documented_migration_steps_execute' => [
                'required_fields' => [
                    'migration_guide_revision',
                    'guide_command_executability',
                    'commands_executed',
                    'exit_codes',
                    'command_timings',
                    'schema_or_storage_migration_output',
                ],
            ],
            'completed_history_preservation_and_replay' => [
                'required_fields' => [
                    'preupgrade_history_export',
                    'postupgrade_history_export',
                    'replay_result',
                    'query_result',
                ],
            ],
            'in_flight_workflow_progress_preserved' => [
                'required_fields' => [
                    'preupgrade_progress_marker',
                    'postupgrade_progress_marker',
                    'completion_result',
                    'history_dumps',
                ],
            ],
            'mid_activity_retry_preserved' => [
                'required_fields' => [
                    'preupgrade_activity_attempt',
                    'postupgrade_activity_attempt',
                    'retry_policy',
                    'final_activity_result',
                ],
            ],
            'queue_state_preserved' => [
                'required_fields' => [
                    'preupgrade_queue_state',
                    'postupgrade_queue_state',
                    'pending_task_identity',
                    'dequeue_or_completion_result',
                ],
            ],
            'schedule_cross_upgrade_cadence_preserved' => [
                'required_fields' => [
                    'preupgrade_schedule_spec',
                    'last_tick_before_upgrade',
                    'first_tick_after_upgrade',
                    'missed_or_duplicate_ticks',
                ],
            ],
            'worker_registration_projection_preserved' => [
                'required_fields' => [
                    'preupgrade_worker_list',
                    'postupgrade_worker_list',
                    'task_queue_projection',
                    'polling_continuity',
                ],
            ],
            'waterline_operator_visibility_preserved' => [
                'required_fields' => [
                    'preupgrade_waterline_snapshot',
                    'postupgrade_waterline_snapshot',
                    'run_detail_visibility',
                    'history_visibility',
                ],
            ],
            'cli_access_to_preupgrade_state' => [
                'required_fields' => [
                    'workflow_describe_json',
                    'workflow_history_json',
                    'operator_api_responses',
                    'typed_response_contracts',
                    'exit_codes',
                ],
            ],
            'new_v2_workflow_start_after_upgrade' => [
                'required_fields' => [
                    'start_request',
                    'run_id',
                    'completion_result',
                    'history_dumps',
                ],
            ],
            'new_v2_schedule_after_upgrade' => [
                'required_fields' => [
                    'create_request',
                    'schedule_id',
                    'schedule_list_json',
                    'operator_api_response',
                    'typed_response_contracts',
                    'observed_ticks',
                ],
            ],
            'new_v2_worker_registration_after_upgrade' => [
                'required_fields' => [
                    'registration_request',
                    'registration_response',
                    'worker_id',
                    'namespace',
                    'task_queue',
                    'unique_task_queue',
                    'task_queue_projection',
                    'operator_api_response',
                    'cli_worker_projection',
                    'typed_response_contracts',
                    'protocol_metadata',
                    'freshness',
                    'poll_request',
                    'polling_result',
                    'request_response_evidence',
                    'exit_codes',
                    'timestamps',
                ],
            ],
            'rollback_contract_verified' => [
                'required_fields' => [
                    'rollback_steps',
                    'rollback_supported_state',
                    'public_operator_signal',
                    'postrollback_visibility',
                    'postrollback_execution_result',
                ],
            ],
            'version_skew_refusal' => [
                'required_fields' => [
                    'skew_matrix',
                    'cli_skew_observations',
                    'worker_skew_observations',
                    'refusal_errors',
                    'operator_visible_reason',
                    'request_response_evidence',
                    'no_partial_mutation_evidence',
                    'applicability_evidence',
                ],
            ],
        ];
    }
}
