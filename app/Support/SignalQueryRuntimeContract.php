<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for the live signals/queries conformance run.
 *
 * The platform conformance suite owns the category and scenario list. This
 * server-owned manifest expands that into the result fields, matrix axes,
 * gate behavior, and finding routing a runner needs to prove the category
 * without treating the Python smoke subset as complete coverage.
 */
final class SignalQueryRuntimeContract
{
    public const SCHEMA = 'durable-workflow.v2.signal-query-runtime.contract';

    public const VERSION = 9;

    public const RESULT_SCHEMA = 'durable-workflow.v2.signal-query-runtime.result';

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
            'fixture_category' => 'signal_query_runtime_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'signal_query_runtime_contract',
                'public_path' => 'https://durable-workflow.github.io/platform-conformance/signal-query-runtime-scenarios.json',
                'source_path' => 'static/platform-conformance/signal-query-runtime-scenarios.json',
            ],
            'artifact_policy' => [
                'version_source' => 'latest_published_artifacts_at_run_time',
                'version_requirement' => 'concrete_published_versions_pinned_at_run_time',
                'placeholder_versions_rejected' => true,
                'placeholder_version_examples' => [
                    'latest',
                    'current',
                    'head',
                    'unresolved',
                    'placeholder',
                    '<latest>',
                    '${VERSION}',
                    '{{ version }}',
                ],
                'install_channels' => [
                    'server' => 'docker image durableworkflow/server:<latest>',
                    'cli' => 'official dw install script pinned to its latest release tag',
                    'workflow-php' => 'Composer package durable-workflow/workflow:2.0.0-alpha.<latest>',
                    'sdk-python' => 'PyPI package durable-workflow==<latest>',
                    'waterline' => 'published Waterline package or image matching the latest release tag',
                ],
                'forbidden_sources' => [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                ],
                'required_run_record_fields' => [
                    'artifact_versions',
                    'started_at',
                    'finished_at',
                    'outcome',
                    'scenario_results',
                    'findings',
                    'finding_links',
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
                'workflow_type' => 'Counter',
                'required_workers' => [
                    'workflow-php',
                    'sdk-python',
                ],
                'required_handlers' => [
                    'signals' => [
                        'increment' => [
                            'arguments' => ['n:int'],
                            'effect' => 'adds n to the in-workflow counter',
                        ],
                        'set' => [
                            'arguments' => ['value:int'],
                            'effect' => 'overwrites the in-workflow counter',
                        ],
                    ],
                    'queries' => [
                        'current' => [
                            'returns' => 'int',
                            'effect' => 'reads the replayed counter state without mutation',
                        ],
                    ],
                ],
            ],
            'required_matrix' => [
                'runtimes' => [
                    'workflow-php',
                    'sdk-python',
                ],
                'client_paths' => [
                    'cli',
                    'workflow-php-sdk',
                    'sdk-python',
                ],
                'observer_paths' => [
                    'waterline-selected-run-detail',
                    'waterline-query-action',
                ],
                'same_language_cells' => [
                    [
                        'worker' => 'sdk-python',
                        'clients' => ['cli', 'sdk-python'],
                        'scenario' => 'python_worker_cli_and_sdk_baseline',
                    ],
                    [
                        'worker' => 'workflow-php',
                        'clients' => ['cli', 'workflow-php-sdk'],
                        'scenario' => 'php_worker_cli_and_sdk_baseline',
                    ],
                ],
                'cross_language_cells' => [
                    [
                        'worker' => 'sdk-python',
                        'clients' => ['workflow-php-sdk', 'cli'],
                        'scenario' => 'python_worker_php_facing_and_cli_clients',
                    ],
                    [
                        'worker' => 'workflow-php',
                        'clients' => ['sdk-python', 'cli'],
                        'scenario' => 'php_worker_python_and_cli_clients',
                    ],
                ],
            ],
            'required_scenarios' => [
                'published_artifact_install_only',
                'python_worker_cli_and_sdk_baseline',
                'php_worker_cli_and_sdk_baseline',
                'python_worker_php_facing_and_cli_clients',
                'php_worker_python_and_cli_clients',
                'ordered_signal_delivery',
                'dedup_contract_observation',
                'signal_during_replay',
                'query_during_replay',
                'completed_run_signal_and_query',
                'unknown_signal_and_query_errors',
                'malformed_signal_and_query_payloads',
                'waterline_operator_visibility',
            ],
            'scenario_requirements' => [
                'published_artifact_install_only' => [
                    'evidence' => [
                        'published_artifact_versions',
                        'artifact_sources',
                    ],
                ],
                'python_worker_cli_and_sdk_baseline' => [
                    'evidence' => [
                        'python_worker_query_task_routing',
                        'cli_signal_and_query',
                        'sdk_python_signal_and_query',
                        'immediate_repeat_query_consistency',
                    ],
                ],
                'php_worker_cli_and_sdk_baseline' => [
                    'evidence' => [
                        'php_worker_query_task_routing',
                        'cli_signal_and_query',
                        'workflow_php_signal_and_query',
                        'immediate_repeat_query_consistency',
                    ],
                ],
                'python_worker_php_facing_and_cli_clients' => [
                    'evidence' => [
                        'php_client_signal_and_query',
                        'cli_signal_and_query',
                        'cross_language_query_consistency',
                        'wire_envelope_compatibility',
                    ],
                ],
                'php_worker_python_and_cli_clients' => [
                    'evidence' => [
                        'sdk_python_signal_and_query',
                        'cli_signal_and_query',
                        'cross_language_query_consistency',
                        'wire_envelope_compatibility',
                    ],
                ],
                'ordered_signal_delivery' => [
                    'evidence' => [
                        'rapid_increment_inputs',
                        'queried_total',
                        'history_signal_order',
                    ],
                    'expected_total_for_1_through_10' => 55,
                    'expected_history_signal_order' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
                ],
                'dedup_contract_observation' => [
                    'evidence' => [
                        'client_side_key_support',
                        'documented_contract',
                        'handler_observation_count',
                    ],
                ],
                'signal_during_replay' => [
                    'required_behavior' => 'signal_applies_after_replay_consistent_point',
                    'evidence' => [
                        'worker_restart_at',
                        'signal_sent_at',
                        'replay_completed_at',
                        'signal_applied_at',
                    ],
                ],
                'query_during_replay' => [
                    'required_behavior' => 'query_waits_for_replay_consistency',
                    'evidence' => [
                        'worker_restart_at',
                        'query_sent_at',
                        'query_answer',
                        'expected_answer',
                    ],
                ],
                'completed_run_signal_and_query' => [
                    'signal_behavior' => 'typed_terminal_state_error_or_documented_dead_letter',
                    'query_behavior' => 'documented_completed_run_query_behavior',
                    'evidence' => [
                        'completed_run_id',
                        'signal_error',
                        'query_result_or_error',
                        'public_query_surfaces',
                        'run_status_after_operations',
                    ],
                ],
                'unknown_signal_and_query_errors' => [
                    'required_errors' => [
                        'unknown_signal',
                        'missing_workflow_signal',
                        'missing_workflow_query',
                        'query_not_found',
                        'rejected_unknown_query',
                    ],
                    'history_integrity' => 'no_handler_invocation_or_state_corruption',
                ],
                'malformed_signal_and_query_payloads' => [
                    'required_errors' => [
                        'invalid_signal_arguments',
                        'invalid_query_arguments',
                    ],
                    'evidence' => [
                        'invalid_signal_arguments_context',
                        'invalid_query_arguments_context',
                        'post_error_valid_query_result',
                    ],
                    'history_integrity' => 'no_handler_invocation_or_state_corruption',
                ],
                'waterline_operator_visibility' => [
                    'required_surfaces' => [
                        'observer_state.selected_run',
                        'observer_state.signals',
                        'observer_state.queries',
                        'observer_state.paths.selected_run_query_template',
                        'comparison.server_observation',
                        'comparison.cli_observation',
                        'comparison.sdk_observation',
                    ],
                    'allowed_live_query_detail_limitation' => 'query_results_not_materialized_in_selected_run_detail',
                ],
            ],
            'coverage_gate' => [
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'all_required_runtimes_present',
                    'same_language_cells_reported',
                    'cross_language_cells_reported',
                    'replay_timing_reported',
                    'terminal_run_behavior_reported',
                    'adversarial_errors_typed',
                    'waterline_observer_comparison_reported',
                    'artifact_versions_match_latest_published_set',
                    'no_local_product_source_artifacts',
                    'findings_linked_for_non_pass_scenarios',
                    'omitted_required_scenarios_link_findings',
                ],
                'uncovered_required_scenario_outcome' => 'non_passing',
                'smoke_subset_outcome' => 'non_passing',
                'unsupported_public_surface_outcome' => 'non_passing_with_root_cause_finding',
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
            ],
            'host_runner_contract' => [
                'status' => 'required_for_passing_signal_query_conformance',
                'runner_repository' => 'server',
                'runner_path' => 'scripts/conformance/signals-queries-published-artifacts.sh',
                'runner_command' => 'scripts/conformance/signals-queries-published-artifacts.sh --result-dir <result-dir>',
                'result_schema' => self::RESULT_SCHEMA,
                'result_files' => [
                    'pins.json',
                    'run-metadata.json',
                    'signals-queries-result.json',
                    'signals-queries-record.json',
                    'signals-queries-findings.json',
                ],
                'must_execute_against_published_artifacts' => true,
                'must_record_runner_blocked_false_for_product_evidence' => true,
                'must_emit_focused_findings_for_uncovered_cells' => true,
                'required_host_commands' => [
                    'bash',
                    'python3',
                ],
                'required_execution_scopes' => [
                    'published_artifact_install',
                    'python_worker_cli_and_sdk_smoke',
                    'php_worker_mirror',
                    'cross_language_client_matrix',
                    'ordered_signal_delivery',
                    'dedup_contract_observation',
                    'replay_timing',
                    'completed_run_handling',
                    'unknown_handler_errors',
                    'malformed_payload_errors',
                    'waterline_observer_comparison',
                ],
                'evidence_shards' => [
                    'published_artifact_install' => [
                        'must_cover_scenarios' => [
                            'published_artifact_install_only',
                        ],
                        'current_evidence_fields' => [
                            'published_artifact_versions',
                            'external_smoke_evidence',
                        ],
                        'finding_type_when_missing' => 'signal_query_published_artifact_install_uncovered',
                        'owning_surface' => 'conformance_harness',
                    ],
                    'python_worker_cli_and_sdk_smoke' => [
                        'must_cover_scenarios' => [
                            'python_worker_cli_and_sdk_baseline',
                        ],
                        'current_evidence_fields' => [
                            'python_worker_query_task_routing',
                            'cli_signal_and_query',
                            'sdk_python_signal_and_query',
                            'immediate_repeat_query_consistency',
                        ],
                        'finding_type_when_missing' => 'signal_query_python_smoke_uncovered',
                        'owning_surface' => 'sdk-python, cli, server',
                    ],
                    'ordered_signal_delivery' => [
                        'must_cover_scenarios' => [
                            'ordered_signal_delivery',
                        ],
                        'current_evidence_fields' => [
                            'rapid_increment_inputs',
                            'ten_signal_ordered_delivery_total',
                            'history_signal_order',
                        ],
                        'finding_type_when_missing' => 'signal_query_ordered_delivery_uncovered',
                        'owning_surface' => 'server',
                    ],
                    'dedup_contract_observation' => [
                        'must_cover_scenarios' => [
                            'dedup_contract_observation',
                        ],
                        'required_evidence_fields' => [
                            'client_side_key_support',
                            'documented_contract',
                            'handler_observation_count',
                        ],
                        'finding_type_when_missing' => 'signal_query_dedup_contract_uncovered',
                        'owning_surface' => 'server, sdk-python, workflow, cli, docs',
                    ],
                    'php_worker_mirror' => [
                        'must_cover_scenarios' => [
                            'php_worker_cli_and_sdk_baseline',
                        ],
                        'required_evidence_fields' => [
                            'php_worker_query_task_routing',
                            'cli_signal_and_query',
                            'workflow_php_signal_and_query',
                            'immediate_repeat_query_consistency',
                        ],
                        'finding_type_when_missing' => 'signal_query_php_worker_mirror_uncovered',
                        'owning_surface' => 'workflow',
                    ],
                    'cross_language_client_matrix' => [
                        'must_cover_scenarios' => [
                            'python_worker_php_facing_and_cli_clients',
                            'php_worker_python_and_cli_clients',
                        ],
                        'required_evidence_fields' => [
                            'php_client_signal_and_query',
                            'sdk_python_signal_and_query',
                            'cli_signal_and_query',
                            'cross_language_query_consistency',
                            'wire_envelope_compatibility',
                        ],
                        'finding_type_when_missing' => 'signal_query_cross_language_client_matrix_uncovered',
                        'owning_surface' => 'workflow, sdk-python, cli',
                    ],
                    'replay_timing' => [
                        'must_cover_scenarios' => [
                            'signal_during_replay',
                            'query_during_replay',
                        ],
                        'required_evidence_fields' => [
                            'worker_restart_at',
                            'signal_sent_at',
                            'replay_completed_at',
                            'signal_applied_at',
                            'query_sent_at',
                            'query_answer',
                            'expected_answer',
                        ],
                        'finding_type_when_missing' => 'signal_query_replay_timing_uncovered',
                        'owning_surface' => 'workflow, sdk-python',
                    ],
                    'completed_run_handling' => [
                        'must_cover_scenarios' => [
                            'completed_run_signal_and_query',
                        ],
                        'required_evidence_fields' => [
                            'completed_run_id',
                            'signal_error',
                            'query_result_or_error',
                            'public_query_surfaces',
                            'run_status_after_operations',
                        ],
                        'finding_type_when_missing' => 'signal_query_completed_run_handling_uncovered',
                        'owning_surface' => 'server, workflow, sdk-python, cli',
                    ],
                    'unknown_handler_errors' => [
                        'must_cover_scenarios' => [
                            'unknown_signal_and_query_errors',
                        ],
                        'required_evidence_fields' => [
                            'unknown_signal',
                            'missing_workflow_signal',
                            'missing_workflow_query',
                            'query_not_found',
                            'rejected_unknown_query',
                        ],
                        'finding_type_when_missing' => 'signal_query_unknown_handler_errors_uncovered',
                        'owning_surface' => 'server, workflow, sdk-python, cli',
                    ],
                    'malformed_payload_errors' => [
                        'must_cover_scenarios' => [
                            'malformed_signal_and_query_payloads',
                        ],
                        'required_evidence_fields' => [
                            'invalid_signal_arguments',
                            'invalid_query_arguments',
                            'invalid_signal_arguments_context',
                            'invalid_query_arguments_context',
                            'post_error_valid_query_result',
                        ],
                        'finding_type_when_missing' => 'signal_query_adversarial_error_shapes_uncovered',
                        'owning_surface' => 'server, workflow, sdk-python, cli',
                    ],
                    'waterline_observer_comparison' => [
                        'must_cover_scenarios' => [
                            'waterline_operator_visibility',
                        ],
                        'required_evidence_fields' => [
                            'observer_state.selected_run',
                            'observer_state.signals',
                            'observer_state.queries',
                            'observer_state.paths.selected_run_query_template',
                            'comparison.server_observation',
                            'comparison.cli_observation',
                            'comparison.sdk_observation',
                        ],
                        'finding_type_when_missing' => 'signal_query_waterline_observer_comparison_uncovered',
                        'owning_surface' => 'waterline',
                    ],
                ],
                'routing_policy' => [
                    'missing_required_scenario' => [
                        'result_status' => 'not_covered',
                        'finding_type' => 'conformance_runner_coverage_gap',
                        'owner' => 'conformance_harness',
                    ],
                    'product_behavior_failure' => [
                        'result_status' => 'fail',
                        'finding_type' => 'product_contract_failure',
                        'owner' => 'owning_surface_from_finding_policy',
                    ],
                    'unsupported_public_surface' => [
                        'result_status' => 'unsupported',
                        'finding_type' => 'unsupported_public_surface',
                        'owner' => 'owning_surface_from_evidence_shard',
                    ],
                ],
            ],
            'result_gate' => SignalQueryRuntimeResultGate::spec(),
            'finding_policy' => [
                'ordering_drift' => 'link_root_cause_finding_against_server',
                'query_staleness' => 'link_root_cause_finding_against_owning_sdk_or_runtime',
                'terminal_run_mismatch' => 'link_root_cause_finding_against_server_or_documentation_owner',
                'malformed_payload_acceptance' => 'link_root_cause_finding_against_emitting_surface',
                'runtime_asymmetry' => 'link_root_cause_finding_against_asymmetric_runtime',
                'observer_mismatch' => 'link_root_cause_finding_against_waterline',
                'unsupported_public_surface' => 'link_root_cause_finding_against_surface_owner',
            ],
        ];
    }
}
