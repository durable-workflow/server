<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for published-artifact saga conformance.
 *
 * The public suite owns the scenario catalog. This server-owned manifest gives
 * host runners an executable handoff for the source-free saga runner that ships
 * with the server release and defines the result shape needed before saga
 * compensation can be counted as product evidence.
 */
final class SagaRuntimeContract
{
    public const SCHEMA = 'durable-workflow.v2.saga-runtime.contract';

    public const VERSION = 1;

    public const RESULT_SCHEMA = 'durable-workflow.v2.saga-runtime-conformance.result';

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
            'fixture_category' => 'saga_runtime_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'saga_runtime_contract',
                'suite_schema' => PlatformConformanceSuite::SCHEMA,
                'suite_version' => PlatformConformanceSuite::VERSION,
                'public_path' => 'https://durable-workflow.github.io/platform-conformance/saga-runtime-scenarios.json',
                'source_path' => 'static/platform-conformance/saga-runtime-scenarios.json',
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
                    '2.0.0-alpha.<latest>',
                    '${VERSION}',
                    '{{ version }}',
                ],
                'install_channels' => [
                    'server' => 'Docker image durableworkflow/server:<exact patch version or digest with DW_SERVER_VERSION>',
                    'cli' => 'official dw GitHub release install.sh asset after downloadability check',
                    'workflow-php' => 'Composer package durable-workflow/workflow:2.0.0-alpha.<latest>',
                    'sdk-python' => 'PyPI package durable-workflow==<latest>',
                    'waterline' => 'published Waterline package matching the latest complete release set',
                ],
                'release_artifact_aliases' => [
                    'workflow-php' => ['workflow'],
                ],
                'forbidden_sources' => [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                    'release_tag_without_required_assets',
                    'rolling_server_image_tag',
                ],
                'required_run_record_fields' => [
                    'published_artifact_versions',
                    'resolved_artifact_versions',
                    'started_at',
                    'finished_at',
                    'generated_at',
                    'outcome',
                    'runner_blocked',
                    'scenario_results',
                    'findings',
                    'topology',
                    'runtime_matrix',
                    'side_store_deltas',
                    'history_dumps',
                    'worker_restart_observations',
                    'operator_visibility_snapshots',
                    'cross_language_matrix',
                    'typed_error_shapes',
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
                'namespace' => 'default',
                'task_queues' => [
                    'workflow-php' => 'sagas-php',
                    'sdk-python' => 'sagas-python',
                ],
                'workflow_type' => 'BookTrip',
                'steps' => [
                    'reserve_flight' => ['compensation' => 'cancel_flight'],
                    'reserve_hotel' => ['compensation' => 'cancel_hotel'],
                    'charge_card' => ['compensation' => 'refund_card'],
                    'send_confirmation' => ['compensation' => null],
                ],
                'side_store' => 'append-only JSONL rows outside product storage used only for conformance assertions',
                'operator_visibility_paths' => [
                    'server workflow describe',
                    'server workflow history export',
                    'dw workflow:show',
                    'dw workflow:history',
                    'waterline selected run detail',
                ],
            ],
            'required_matrix' => [
                'workflow_runtimes' => [
                    'workflow-php',
                    'sdk-python',
                ],
                'activity_runtimes' => [
                    'workflow-php',
                    'sdk-python',
                ],
                'same_language_cells' => [
                    [
                        'workflow_runtime' => 'workflow-php',
                        'activity_runtime' => 'workflow-php',
                    ],
                    [
                        'workflow_runtime' => 'sdk-python',
                        'activity_runtime' => 'sdk-python',
                    ],
                ],
                'cross_language_cells' => [
                    [
                        'workflow_runtime' => 'workflow-php',
                        'compensation_runtime' => 'sdk-python',
                        'scenario' => 'php_workflow_python_compensation',
                    ],
                    [
                        'workflow_runtime' => 'sdk-python',
                        'compensation_runtime' => 'workflow-php',
                        'scenario' => 'python_workflow_php_compensation',
                    ],
                ],
                'failure_modes' => [
                    'later_step_failure',
                    'early_step_failure',
                    'compensation_retry',
                    'compensation_failure',
                    'worker_restart_mid_compensation',
                    'typed_compensation_error',
                ],
                'operator_visibility_paths' => [
                    'server',
                    'cli',
                    'waterline',
                ],
            ],
            'required_scenarios' => [
                'published_artifact_install_only',
                'forward_success_path',
                'failure_at_d_reverse_compensation',
                'failure_at_c_reverse_compensation',
                'failure_at_a_no_compensation',
                'compensation_retry_idempotence',
                'compensation_failure_visibility',
                'mid_compensation_worker_restart',
                'php_workflow_python_compensation',
                'python_workflow_php_compensation',
                'typed_compensation_error_round_trip',
                'operator_visible_mid_compensation_status',
            ],
            'scenario_requirements' => self::scenarioRequirements(),
            'coverage_gate' => [
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'all_required_artifacts_resolved_from_published_channels',
                    'server_image_is_exact_patch_or_digest_pinned_with_version',
                    'cli_release_asset_is_downloadable_before_tag_is_recorded',
                    'same_language_php_and_python_cells_reported',
                    'cross_language_compensation_cells_reported',
                    'reverse_compensation_order_reported_for_later_and_early_failures',
                    'retry_idempotence_business_effect_count_reported',
                    'compensation_failure_terminal_shape_reported',
                    'worker_restart_resume_point_and_duplicate_counts_reported',
                    'typed_compensation_error_shape_reported',
                    'operator_visibility_surfaces_reported_including_waterline',
                    'run_timestamps_outcome_and_findings_are_recorded',
                    'runner_exit_status_zero_for_passing_record',
                    'declared_outcome_matches_evaluated_status',
                    'artifact_source_recorded_for_each_install_channel',
                    'local_product_source_checkouts_used_explicitly_false',
                    'no_local_product_source_artifacts',
                    'runner_blocked_false_for_product_evidence',
                    'findings_linked_for_non_pass_scenarios',
                ],
                'uncovered_required_scenario_outcome' => 'non_passing',
                'smoke_subset_outcome' => 'non_passing',
                'unsupported_public_surface_outcome' => 'non_passing_with_root_cause_finding',
                'waterline_operator_visibility_gap_outcome' => 'non_passing_waterline_finding_required',
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
            ],
            'host_runner_contract' => [
                'status' => 'required_for_passing_sagas_conformance',
                'runner_repository' => 'server',
                'runner_path' => 'scripts/conformance/sagas-published-artifacts.sh',
                'runner_command' => 'scripts/conformance/sagas-published-artifacts.sh --result-dir <result-dir>',
                'result_schema' => self::RESULT_SCHEMA,
                'result_files' => [
                    'pins.json',
                    'run-metadata.json',
                    'sagas-result.json',
                    'sagas-record.json',
                ],
                'must_execute_against_published_artifacts' => true,
                'pass_record_requires_zero_exit_status' => true,
                'nonzero_runner_exit_record_outcome' => 'error',
                'must_record_runner_blocked_false_for_product_evidence' => true,
                'must_emit_result_for_every_required_scenario' => true,
                'release_asset_handoff_policy' => 'do not advance to a tag until the required install asset is downloadable',
                'required_execution_scopes' => [
                    'published-artifact-install',
                    'forward-success-path',
                    'reverse-compensation-failure-at-d',
                    'reverse-compensation-failure-at-c',
                    'early-failure-no-compensation',
                    'compensation-retry-idempotence',
                    'compensation-failure-visibility',
                    'mid-compensation-worker-restart',
                    'php-workflow-python-compensation',
                    'python-workflow-php-compensation',
                    'typed-compensation-error-round-trip',
                    'operator-visible-mid-compensation-status',
                ],
                'routing_policy' => [
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
                    'unsupported_public_surface' => [
                        'scenario_status' => 'unsupported',
                        'finding_source' => 'saga_runtime_contract.finding_policy',
                    ],
                    'waterline_operator_visibility_failure' => [
                        'scenario_status' => 'fail',
                        'finding_source' => 'saga_runtime_contract.finding_policy',
                        'owner' => 'waterline',
                    ],
                    'product_behavior_failure' => [
                        'scenario_status' => 'fail',
                        'finding_source' => 'saga_runtime_contract.finding_policy',
                    ],
                ],
            ],
            'result_gate' => SagaRuntimeResultGate::spec(),
            'finding_policy' => [
                'root_cause_owners' => [
                    'skipped_compensation_or_wrong_order' => 'workflow_or_sdk-python',
                    'duplicate_compensation_effect' => 'workflow_or_sdk-python_activity_contract',
                    'worker_restart_replay_drift' => 'workflow_or_sdk-python',
                    'cross_language_error_shape_loss' => 'codec_or_worker_protocol',
                    'silent_compensation_failed_terminal_state' => 'workflow_or_sdk-python',
                    'server_history_or_visibility_gap' => 'server',
                    'cli_operator_visibility_gap' => 'cli',
                    'waterline_operator_visibility_gap' => 'waterline',
                    'published_artifact_handoff_gap' => 'owning_release_surface',
                    'conformance_runner_coverage_gap' => 'conformance_harness',
                ],
                'required_for_non_pass' => [
                    'scenario_id',
                    'owning_surface',
                    'artifact_versions',
                    'observed_behavior',
                    'expected_behavior',
                    'next_acceptance_criterion',
                ],
                'surface_routing' => [
                    'compensation skipped silently' => 'workflow_or_sdk-python',
                    'compensation order violated' => 'workflow_or_sdk-python',
                    'compensation runs twice under retry' => 'workflow_or_sdk-python_activity_contract',
                    'compensation replay drift' => 'workflow_or_sdk-python',
                    'typed error lost across language boundary' => 'codec_or_worker_protocol',
                    'operator cannot inspect in-progress compensation' => 'cli_or_waterline',
                    'compensation failure is silent' => 'workflow_or_sdk-python',
                ],
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
                'expected_behavior' => 'all artifacts are resolved from complete published channels and no local product checkout is used as an artifact under test',
            ],
            'forward_success_path' => [
                'required_fields' => [
                    'forward_rows',
                    'compensation_rows',
                    'workflow_status',
                    'history_dumps',
                ],
                'expected_behavior' => 'A, B, C, and D complete with no compensation rows and a completed terminal state',
            ],
            'failure_at_d_reverse_compensation' => [
                'required_fields' => [
                    'forward_rows',
                    'compensation_rows',
                    'compensation_order',
                    'workflow_status',
                    'history_dumps',
                ],
                'expected_behavior' => 'after D fails, C, B, and A compensate in reverse order and the workflow reaches a documented terminal state',
            ],
            'failure_at_c_reverse_compensation' => [
                'required_fields' => [
                    'forward_rows',
                    'compensation_rows',
                    'compensation_order',
                    'send_confirmation_invocations',
                    'workflow_status',
                ],
                'expected_behavior' => 'after C fails, B and A compensate in reverse order and D is not invoked',
            ],
            'failure_at_a_no_compensation' => [
                'required_fields' => [
                    'forward_rows',
                    'compensation_rows',
                    'workflow_status',
                ],
                'expected_behavior' => 'A failing before its forward effect records no completed forward rows and no compensation rows',
            ],
            'compensation_retry_idempotence' => [
                'required_fields' => [
                    'retry_attempts',
                    'business_effect_count',
                    'workflow_status',
                ],
                'expected_behavior' => 'a retrying compensation may retry the task but applies the underlying business undo exactly once',
            ],
            'compensation_failure_visibility' => [
                'required_fields' => [
                    'failed_compensation_step',
                    'terminal_failure_shape',
                    'operator_visible_reason',
                    'workflow_status',
                ],
                'expected_behavior' => 'a definitive compensation failure is visible in the terminal failure shape and operator surfaces',
            ],
            'mid_compensation_worker_restart' => [
                'required_fields' => [
                    'restart_timing',
                    'resumed_compensation_step',
                    'duplicate_compensation_counts',
                    'history_dumps',
                ],
                'expected_behavior' => 'after a worker restart, compensation resumes from the recorded step without duplicate compensation effects',
            ],
            'php_workflow_python_compensation' => [
                'required_fields' => [
                    'workflow_runtime',
                    'compensation_runtime',
                    'compensation_order',
                    'typed_result_shapes',
                ],
                'expected_behavior' => 'a PHP workflow can call Python compensation handlers in the correct order and observe their result shapes',
            ],
            'python_workflow_php_compensation' => [
                'required_fields' => [
                    'workflow_runtime',
                    'compensation_runtime',
                    'compensation_order',
                    'typed_result_shapes',
                ],
                'expected_behavior' => 'a Python workflow can call PHP compensation handlers in the correct order and observe their result shapes',
            ],
            'typed_compensation_error_round_trip' => [
                'required_fields' => [
                    'raised_error_type',
                    'observed_error_type',
                    'observed_error_message',
                    'terminal_failure_shape',
                ],
                'expected_behavior' => 'a typed compensation error survives the worker boundary and is visible in the workflow failure shape',
            ],
            'operator_visible_mid_compensation_status' => [
                'required_fields' => [
                    'completed_forward_steps',
                    'running_compensation_step',
                    'completed_compensations',
                    'pending_compensations',
                    'failed_compensations',
                    'operator_visibility_snapshots',
                    'waterline_operator_evidence',
                ],
                'expected_behavior' => 'operators can tell which forward steps completed and which compensations are running, completed, pending, or failed',
            ],
        ];
    }
}
