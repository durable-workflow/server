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

    public const VERSION = 4;

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
                'ordered_signal_delivery' => [
                    'evidence' => [
                        'rapid_increment_inputs',
                        'queried_total',
                        'history_signal_order',
                    ],
                    'expected_total_for_1_through_10' => 55,
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
                    ],
                ],
                'unknown_signal_and_query_errors' => [
                    'required_errors' => [
                        'unknown_signal',
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
                    'history_integrity' => 'no_handler_invocation_or_state_corruption',
                ],
                'waterline_operator_visibility' => [
                    'required_surfaces' => [
                        'observer_state.selected_run',
                        'observer_state.signals',
                        'observer_state.queries',
                        'observer_state.paths.selected_run_query_template',
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
                ],
                'uncovered_required_scenario_outcome' => 'non_passing',
                'smoke_subset_outcome' => 'non_passing',
                'unsupported_public_surface_outcome' => 'non_passing_with_root_cause_finding',
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
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
