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

    public const VERSION = 1;

    public const RESULT_SCHEMA = 'durable-workflow.v2.migration-runtime.result';

    public const RESULT_VERSION = 1;

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
                    'server-v1' => 'latest supported v1 server image or release artifact pinned by exact tag or digest',
                    'server-v2' => 'Docker image durableworkflow/server:<exact patch version or digest with DW_SERVER_VERSION>',
                    'cli' => 'official dw GitHub release install.sh asset after downloadability check',
                    'workflow-php-v1' => 'Composer package durable-workflow/workflow or laravel-workflow/laravel-workflow at the latest supported v1 release',
                    'workflow-php-v2' => 'Composer package durable-workflow/workflow:2.0.0-alpha.<exact>',
                    'sdk-python' => 'PyPI package durable-workflow==<exact>',
                    'waterline' => 'published Waterline package matching the target release set',
                ],
                'release_artifact_aliases' => [
                    'workflow-php-v1' => ['workflow-v1'],
                    'workflow-php-v2' => ['workflow', 'workflow-php'],
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
                'not_covered',
                'runner_blocked',
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
                    'active_schedule',
                    'registered_workers',
                    'queryable_history',
                    'waterline_projection',
                ],
                'operator_visibility_paths' => [
                    'dw workflow:describe <workflow-id>',
                    'dw workflow:show-run <workflow-id> <run-id>',
                    'dw workflow:history <workflow-id> <run-id>',
                    'dw workflow:history-export <workflow-id> <run-id>',
                    'dw schedule:list',
                    'GET /api/workflows/{workflowId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}/history',
                    'GET /api/schedules',
                    'GET /waterline/api/instances/{instanceId}',
                    'GET /waterline/api/instances/{instanceId}/runs/{runId}',
                ],
            ],
            'required_matrix' => [
                'source_release_set' => [
                    'server-v1',
                    'workflow-php-v1',
                ],
                'target_release_set' => [
                    'server-v2',
                    'workflow-php-v2',
                    'sdk-python',
                ],
                'client_paths' => [
                    'cli',
                    'workflow-php-sdk',
                    'sdk-python',
                ],
                'operator_visibility_paths' => [
                    'dw workflow:describe <workflow-id>',
                    'dw workflow:show-run <workflow-id> <run-id>',
                    'dw workflow:history <workflow-id> <run-id>',
                    'dw workflow:history-export <workflow-id> <run-id>',
                    'dw schedule:list',
                    'GET /api/workflows/{workflowId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}/history',
                    'GET /api/schedules',
                    'GET /waterline/api/instances/{instanceId}',
                    'GET /waterline/api/instances/{instanceId}/runs/{runId}',
                ],
                'state_kinds' => [
                    'completed_history',
                    'in_flight_workflow',
                    'retrying_activity',
                    'schedule',
                    'worker_registration',
                ],
                'skew_cells' => [
                    [
                        'server' => 'server-v1',
                        'worker' => 'workflow-php-v2',
                        'scenario' => 'version_skew_refusal',
                    ],
                    [
                        'server' => 'server-v2',
                        'worker' => 'workflow-php-v1',
                        'scenario' => 'version_skew_refusal',
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
                'schedule_cross_upgrade_cadence_preserved',
                'worker_registration_projection_preserved',
                'waterline_operator_visibility_preserved',
                'cli_access_to_preupgrade_state',
                'new_v2_workflow_start_after_upgrade',
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
                    'cli_and_waterline_preupgrade_state_visibility_reported',
                    'new_v2_workflow_start_reported',
                    'rollback_or_documented_no_rollback_reported',
                    'version_skew_refusal_reported',
                    'storage_connection_smoke_is_recorded_but_not_counted_as_complete',
                    'run_timestamps_outcome_and_findings_are_recorded',
                    'declared_outcome_matches_evaluated_status',
                    'artifact_source_recorded_for_each_install_channel',
                    'artifact_prerequisite_failures_are_linked_when_artifacts_are_missing',
                    'local_product_source_checkouts_used_explicitly_false',
                    'runner_blocked_false_for_product_evidence',
                    'findings_linked_for_non_pass_scenarios',
                ],
                'uncovered_required_scenario_outcome' => 'non_passing',
                'smoke_subset_outcome' => 'non_passing',
                'storage_connection_smoke_only_outcome' => 'non_passing',
                'unsupported_public_surface_outcome' => 'non_passing_with_root_cause_finding',
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
                    'DW_MIGRATION_EVIDENCE_JSON' => 'Optional full-result or scenario-shard JSON captured by the host runner after executing the public migration guide against published artifacts.',
                    'DW_MIGRATION_EVIDENCE_DIR' => 'Optional directory of JSON evidence shards; files are merged in lexical order so the host runner can collect required migration scopes independently.',
                    'DW_MIGRATION_STORAGE_SMOKE_JSON' => 'Optional storage-connection smoke JSON to attach as advisory context.',
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
                    'schedule-cadence',
                    'worker-registration-projection',
                    'waterline-operator-visibility',
                    'cli-access-to-preupgrade-state',
                    'new-v2-start',
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
                        'schedule-cadence',
                        'worker-registration-projection',
                        'waterline-operator-visibility',
                        'cli-access-to-preupgrade-state',
                        'new-v2-start',
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
                ],
            ],
            'result_gate' => MigrationRuntimeResultGate::spec(),
            'finding_policy' => [
                'missing_or_invalid_published_migration_artifact' => 'link_root_cause_finding_against_artifact_surface_owner',
                'missing_or_wrong_migration_guide_step' => 'link_root_cause_finding_against_docs',
                'data_loss_or_replay_break' => 'link_root_cause_finding_against_workflow_or_server',
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
                    'seeded_workflows',
                    'seeded_schedules',
                    'seeded_worker_registrations',
                ],
            ],
            'documented_migration_steps_execute' => [
                'required_fields' => [
                    'migration_guide_revision',
                    'commands_executed',
                    'exit_codes',
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
                    'schedule_list_json',
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
            'rollback_contract_verified' => [
                'required_fields' => [
                    'rollback_steps',
                    'rollback_supported_state',
                    'postrollback_visibility',
                    'postrollback_execution_result',
                ],
            ],
            'version_skew_refusal' => [
                'required_fields' => [
                    'skew_matrix',
                    'refusal_errors',
                    'operator_visible_reason',
                    'no_partial_mutation_evidence',
                ],
            ],
        ];
    }
}
