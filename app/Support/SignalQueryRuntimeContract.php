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

    public const VERSION = 23;

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
                'install_proof_artifacts' => [
                    'server',
                    'cli',
                    'sdk-python',
                ],
                'expected_sources' => [
                    'server' => 'published_docker_image',
                    'cli' => 'published_cli_release',
                    'workflow-php' => 'published_composer_package',
                    'sdk-python' => 'published_pypi_package',
                    'waterline' => 'published_waterline_artifact',
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
                        'artifact_install_evidence',
                    ],
                ],
                'python_worker_cli_and_sdk_baseline' => [
                    'evidence' => [
                        'worker_runtime',
                        'python_worker_artifact_source',
                        'python_worker_sdk_version',
                        'python_worker_query_task_routing',
                        'routed_current_query_task',
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
                        'accepted_signal_inputs',
                        'accepted_signal_total',
                        'queried_total',
                        'history_signal_order',
                        'final_run_status',
                    ],
                    'expected_rapid_increment_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
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
                        'signal_api_sample',
                        'signal_status_code',
                        'worker_restart_at',
                        'signal_sent_at',
                        'replay_completed_at',
                        'signal_applied_at',
                    ],
                    'timestamp_order' => [
                        'worker_restart_at <= signal_sent_at',
                        'signal_sent_at < replay_completed_at',
                        'replay_completed_at <= signal_applied_at',
                    ],
                ],
                'query_during_replay' => [
                    'required_behavior' => 'query_waits_for_replay_consistency',
                    'evidence' => [
                        'query_api_sample',
                        'query_status_code',
                        'worker_restart_at',
                        'query_sent_at',
                        'replay_completed_at',
                        'query_handler_invoked_at',
                        'query_completed_at',
                        'query_answer',
                        'expected_answer',
                    ],
                    'timestamp_order' => [
                        'worker_restart_at <= query_sent_at',
                        'query_sent_at < replay_completed_at',
                        'replay_completed_at <= query_handler_invoked_at',
                        'query_handler_invoked_at <= query_completed_at',
                    ],
                ],
                'completed_run_signal_and_query' => [
                    'signal_behavior' => 'typed_terminal_state_error_or_documented_dead_letter',
                    'query_behavior' => 'documented_completed_run_query_behavior',
                    'evidence' => [
                        'completed_run_id',
                        'completed_at',
                        'signal_api_sample',
                        'signal_error.status_code',
                        'signal_error.reason',
                        'signal_error.rejection_reason',
                        'query_api_sample',
                        'query_result_or_error.status_code',
                        'query_result_or_error.outcome',
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
                    'evidence' => [
                        'unknown_signal',
                        'missing_workflow_signal',
                        'missing_workflow_query',
                        'query_not_found',
                        'rejected_unknown_query',
                        'known_query_after_unknown_errors',
                    ],
                    'optional_public_client_error_samples' => [
                        'cli_unknown_signal_sample',
                        'cli_unknown_query_sample',
                        'cli_missing_workflow_signal_sample',
                        'cli_missing_workflow_query_sample',
                        'sdk_python_unknown_signal_sample',
                        'sdk_python_unknown_query_sample',
                        'sdk_python_missing_workflow_signal_sample',
                        'sdk_python_missing_workflow_query_sample',
                    ],
                    'history_integrity' => 'no_handler_invocation_or_state_corruption',
                ],
                'malformed_signal_and_query_payloads' => [
                    'required_errors' => [
                        'invalid_signal_arguments',
                        'invalid_query_arguments',
                    ],
                    'evidence' => [
                        'invalid_signal_arguments.status_code',
                        'invalid_signal_arguments.reason',
                        'invalid_query_arguments.status_code',
                        'invalid_query_arguments.reason',
                        'invalid_signal_arguments_context',
                        'invalid_query_arguments_context',
                        'signal_handler_invocation_count_after_invalid_payload',
                        'query_state_mutation_count_after_invalid_payload',
                        'post_error_valid_query_result',
                        'cli_invalid_signal_arguments_sample',
                        'cli_invalid_query_arguments_sample',
                        'sdk_python_invalid_signal_arguments_sample',
                        'sdk_python_invalid_query_arguments_sample',
                    ],
                    'history_integrity' => 'no_handler_invocation_or_state_corruption',
                ],
                'waterline_operator_visibility' => [
                    'required_surfaces' => [
                        'artifact_versions',
                        'artifact_sources',
                        'captured_at',
                        'observer_state.selected_run',
                        'observer_state.signals',
                        'observer_state.queries',
                        'observer_state.paths.selected_run_query_template',
                        'api_paths.selected_run_detail',
                        'api_paths.selected_run_query_action',
                        'dashboard_json_envelopes.selected_run_detail',
                        'api_captures.selected_run_detail',
                        'api_captures.selected_run_query_action',
                        'comparison.run_status_matches_public_clients',
                        'comparison.counter_state_matches_public_clients',
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
                    'published_artifact_sources_match_expected_channels',
                    'published_artifact_install_only_includes_per_artifact_install_proof',
                    'python_worker_baseline_identifies_a_published_python_sdk_worker',
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
                    'docker',
                    'sh',
                ],
                'adversarial_probe_overrides' => [
                    'DW_SIGNALS_QUERIES_RUN_BASELINE_PROBE',
                    'DW_SIGNALS_QUERIES_RUN_ADVERSARIAL_PROBE',
                    'DW_SIGNALS_QUERIES_SERVER_URL',
                    'DW_SIGNALS_QUERIES_SERVER_READY_TIMEOUT_SECONDS',
                    'DW_SIGNALS_QUERIES_AUTH_TOKEN',
                    'DW_SIGNALS_QUERIES_NAMESPACE',
                    'DW_SIGNALS_QUERIES_CLI_BIN',
                    'DW_SIGNALS_QUERIES_PYTHON',
                    'DW_SIGNALS_QUERIES_KEEP_RUN_ROOT',
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
                'baseline_probe_not_claimed_as_pass' => [],
                'evidence_shards' => [
                    'published_artifact_install' => [
                        'must_cover_scenarios' => [
                            'published_artifact_install_only',
                        ],
                        'current_evidence_fields' => [
                            'published_artifact_versions',
                            'artifact_sources',
                            'artifact_install_evidence',
                            'external_smoke_evidence',
                        ],
                        'expected_artifact_sources' => [
                            'server' => 'published_docker_image',
                            'cli' => 'published_cli_release',
                            'workflow-php' => 'published_composer_package',
                            'sdk-python' => 'published_pypi_package',
                            'waterline' => 'published_waterline_artifact',
                        ],
                        'install_proof_artifacts' => [
                            'server',
                            'cli',
                            'sdk-python',
                        ],
                        'baseline_probe_claims_pass' => true,
                        'pass_claim_source' => 'published_artifact_install_probe',
                        'finding_type_when_missing' => 'signal_query_published_artifact_install_uncovered',
                        'owning_surface' => 'conformance_harness',
                    ],
                    'python_worker_cli_and_sdk_smoke' => [
                        'must_cover_scenarios' => [
                            'python_worker_cli_and_sdk_baseline',
                        ],
                        'current_evidence_fields' => [
                            'worker_runtime',
                            'python_worker_artifact_source',
                            'python_worker_sdk_version',
                            'python_worker_query_task_routing',
                            'routed_current_query_task',
                            'cli_signal_and_query',
                            'sdk_python_signal_and_query',
                            'immediate_repeat_query_consistency',
                        ],
                        'baseline_probe_claims_pass' => true,
                        'pass_claim_source' => 'published_python_sdk_worker_baseline_probe',
                        'finding_type_when_missing' => 'signal_query_python_smoke_uncovered',
                        'finding_type_when_routed_current_query_missing' => 'signal_query_python_routed_current_query_evidence_missing',
                        'owning_surface' => 'sdk-python, cli, server',
                    ],
                    'ordered_signal_delivery' => [
                        'must_cover_scenarios' => [
                            'ordered_signal_delivery',
                        ],
                        'current_evidence_fields' => [
                            'rapid_increment_inputs',
                            'accepted_signal_inputs',
                            'accepted_signal_total',
                            'queried_total',
                            'history_signal_order',
                            'final_run_status',
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
                            'signal_api_sample',
                            'signal_status_code',
                            'worker_restart_at',
                            'signal_sent_at',
                            'replay_completed_at',
                            'signal_applied_at',
                            'query_api_sample',
                            'query_status_code',
                            'query_sent_at',
                            'query_handler_invoked_at',
                            'query_completed_at',
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
                            'completed_at',
                            'signal_api_sample',
                            'signal_error.status_code',
                            'signal_error.reason',
                            'signal_error.rejection_reason',
                            'query_api_sample',
                            'query_result_or_error.status_code',
                            'query_result_or_error.outcome',
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
                            'known_query_after_unknown_errors',
                        ],
                        'optional_evidence_fields' => [
                            'cli_unknown_signal_sample',
                            'cli_unknown_query_sample',
                            'cli_missing_workflow_signal_sample',
                            'cli_missing_workflow_query_sample',
                            'sdk_python_unknown_signal_sample',
                            'sdk_python_unknown_query_sample',
                            'sdk_python_missing_workflow_signal_sample',
                            'sdk_python_missing_workflow_query_sample',
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
                            'invalid_signal_arguments.status_code',
                            'invalid_signal_arguments.reason',
                            'invalid_query_arguments.status_code',
                            'invalid_query_arguments.reason',
                            'invalid_signal_arguments_context',
                            'invalid_query_arguments_context',
                            'signal_handler_invocation_count_after_invalid_payload',
                            'query_state_mutation_count_after_invalid_payload',
                            'post_error_valid_query_result',
                            'cli_invalid_signal_arguments_sample',
                            'cli_invalid_query_arguments_sample',
                            'sdk_python_invalid_signal_arguments_sample',
                            'sdk_python_invalid_query_arguments_sample',
                        ],
                        'finding_type_when_missing' => 'signal_query_adversarial_error_shapes_uncovered',
                        'owning_surface' => 'server, workflow, sdk-python, cli',
                    ],
                    'waterline_observer_comparison' => [
                        'must_cover_scenarios' => [
                            'waterline_operator_visibility',
                        ],
                        'required_evidence_fields' => [
                            'artifact_versions',
                            'artifact_sources',
                            'captured_at',
                            'observer_state.selected_run',
                            'observer_state.signals',
                            'observer_state.queries',
                            'observer_state.paths.selected_run_query_template',
                            'api_paths.selected_run_detail',
                            'api_paths.selected_run_query_action',
                            'dashboard_json_envelopes.selected_run_detail',
                            'api_captures.selected_run_detail',
                            'api_captures.selected_run_query_action',
                            'comparison.run_status_matches_public_clients',
                            'comparison.counter_state_matches_public_clients',
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
