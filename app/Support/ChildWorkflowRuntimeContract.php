<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for the live child-workflow conformance run.
 *
 * The platform conformance suite owns the category and scenario list. This
 * server-owned manifest expands that into the matrix axes, evidence fields,
 * gate behavior, and finding routing needed to prove Temporal-parity child
 * workflow behavior without treating a same-language Python smoke as complete.
 */
final class ChildWorkflowRuntimeContract
{
    public const SCHEMA = 'durable-workflow.v2.child-workflow-runtime.contract';

    public const VERSION = 10;

    public const RESULT_SCHEMA = 'durable-workflow.v2.child-workflow-runtime.result';

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
            'fixture_category' => 'child_workflow_runtime_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'child_workflow_runtime_contract',
                'suite_schema' => PlatformConformanceSuite::SCHEMA,
                'suite_version' => PlatformConformanceSuite::VERSION,
                'public_path' => 'https://durable-workflow.github.io/platform-conformance/child-workflow-runtime-scenarios.json',
                'source_path' => 'static/platform-conformance/child-workflow-runtime-scenarios.json',
            ],
            'artifact_policy' => [
                'version_source' => 'latest_published_artifacts_at_run_time',
                'install_channels' => [
                    'server' => 'docker image durableworkflow/server:<latest>',
                    'cli' => 'official dw install script pinned to its latest release tag',
                    'workflow-php' => 'Composer package durable-workflow/workflow:2.0.0-alpha.<latest>',
                    'sdk-python' => 'PyPI package durable-workflow==<latest>',
                    'sdk-rust' => 'crates.io package durable-workflow=<latest>',
                    'waterline' => 'published Waterline observer artifact when claimed by the release set',
                ],
                'forbidden_sources' => [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                ],
                'required_run_record_fields' => [
                    'artifact_versions',
                    'started_at',
                    'finished_at',
                    'generated_at',
                    'outcome',
                    'scenario_results',
                    'findings',
                    'finding_links',
                    'runtime_evidence_source',
                    'local_product_source_checkouts_used',
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
                'task_queue' => 'cw-shared',
                'required_workers' => [
                    'workflow-php',
                    'sdk-python',
                ],
                'workflow_types' => [
                    'workflow-php' => [
                        'parent' => 'PhpParent',
                        'child' => 'PhpChild',
                    ],
                    'sdk-python' => [
                        'parent' => 'PythonParent',
                        'child' => 'PythonChild',
                    ],
                ],
                'required_parent_child_behaviors' => [
                    'start_child_by_workflow_type',
                    'parent_observes_child_result',
                    'parent_observes_typed_child_failure',
                    'parent_cancellation_requests_child_cancellation',
                    'direct_child_cancellation_returns_typed_parent_failure',
                    'parent_replay_observes_recorded_child_outcome',
                    'concurrent_child_fan_out',
                    'namespace_scoped_lineage',
                ],
            ],
            'required_matrix' => [
                'runtimes' => [
                    'workflow-php',
                    'sdk-python',
                ],
                'same_language_cells' => [
                    [
                        'parent' => 'sdk-python',
                        'child' => 'sdk-python',
                        'scenario' => 'python_parent_python_child_baseline',
                    ],
                    [
                        'parent' => 'workflow-php',
                        'child' => 'workflow-php',
                        'scenario' => 'php_parent_php_child_baseline',
                    ],
                ],
                'cross_language_cells' => [
                    [
                        'parent' => 'workflow-php',
                        'child' => 'sdk-python',
                        'scenario' => 'php_parent_python_child_cross_language',
                    ],
                    [
                        'parent' => 'sdk-python',
                        'child' => 'workflow-php',
                        'scenario' => 'python_parent_php_child_cross_language',
                    ],
                ],
                'failure_round_trip_cells' => [
                    [
                        'parent' => 'sdk-python',
                        'child' => 'sdk-python',
                        'scenario' => 'child_failure_round_trip_matrix',
                    ],
                    [
                        'parent' => 'workflow-php',
                        'child' => 'workflow-php',
                        'scenario' => 'child_failure_round_trip_matrix',
                    ],
                    [
                        'parent' => 'workflow-php',
                        'child' => 'sdk-python',
                        'scenario' => 'child_failure_round_trip_matrix',
                    ],
                    [
                        'parent' => 'sdk-python',
                        'child' => 'workflow-php',
                        'scenario' => 'child_failure_round_trip_matrix',
                    ],
                ],
            ],
            'required_scenarios' => [
                'published_artifact_install_only',
                'python_parent_python_child_baseline',
                'php_parent_php_child_baseline',
                'php_parent_python_child_cross_language',
                'python_parent_php_child_cross_language',
                'child_failure_round_trip_matrix',
                'parent_cancellation_propagates_to_child',
                'direct_child_cancellation_observed_by_parent',
                'worker_restart_replay_preserves_child_outcome',
                'concurrent_child_fan_out',
                'child_workflow_namespace_contract',
            ],
            'scenario_requirements' => [
                'published_artifact_install_only' => [
                    'required_behavior' => 'all_artifacts_resolved_from_published_channels',
                    'evidence' => [
                        'artifact_install_evidence',
                        'server_image',
                        'cli_release',
                        'workflow_php_package',
                        'sdk_python_package',
                        'sdk_rust_package',
                        'waterline_artifact',
                        'per_artifact_command_and_output_provenance',
                    ],
                ],
                'child_result_round_trip' => [
                    'evidence' => [
                        'parent_workflow_id',
                        'parent_run_id',
                        'child_workflow_id',
                        'child_run_id',
                        'task_queue',
                        'observed_at',
                        'parent_final_result',
                        'parent_history',
                        'child_history',
                        'child_history_excerpt',
                        'runtime_observations',
                    ],
                    'result_integrity' => 'parent_result_contains_child_return_value_without_string_shaping',
                ],
                'child_failure_round_trip_matrix' => [
                    'required_parent_failure_fields' => [
                        'exception_class',
                        'exception_type',
                        'message',
                        'failure_kind',
                        'parent_and_child_workflow_run_identities',
                        'task_queue',
                        'history_excerpts',
                        'observed_at',
                    ],
                    'required_cells' => 'all_same_language_and_cross_language_parent_child_pairs',
                ],
                'parent_cancellation_propagates_to_child' => [
                    'required_behavior' => 'child_reaches_cancelled_after_parent_cancel',
                    'evidence' => [
                        'cancel_issued_at',
                        'child_cancelled_at',
                        'typed_cancellation_observed',
                        'child_cancellation_history_evidence',
                        'parent_close_policy_evidence',
                    ],
                ],
                'direct_child_cancellation_observed_by_parent' => [
                    'required_behavior' => 'parent_observes_typed_child_cancellation_not_timeout',
                    'evidence' => [
                        'child_cancel_issued_at',
                        'parent_observed_at',
                        'parent_failure_kind',
                    ],
                ],
                'worker_restart_replay_preserves_child_outcome' => [
                    'required_behavior' => 'parent_decision_sequence_matches_after_restart',
                    'evidence' => [
                        'parent_worker_stopped_at',
                        'parent_worker_restarted_at',
                        'original_decision_sequence',
                        'replayed_decision_sequence',
                    ],
                ],
                'concurrent_child_fan_out' => [
                    'required_child_count' => 5,
                    'evidence' => [
                        'child_started_at_values',
                        'child_completed_at_values',
                        'aggregate_result',
                    ],
                    'required_behavior' => 'children_overlap_in_time_and_aggregate_matches_expected_value',
                ],
                'child_workflow_namespace_contract' => [
                    'evidence' => [
                        'parent_namespace',
                        'child_namespace',
                        'lineage_links',
                        'cross_namespace_verdict',
                    ],
                    'cross_namespace_contract' => 'pass_if_supported_or_link_documented_contract_finding_if_unsupported_or_inconsistent',
                ],
            ],
            'coverage_gate' => [
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'all_required_runtimes_present',
                    'same_language_cells_reported',
                    'cross_language_cells_reported',
                    'failure_round_trip_cells_reported',
                    'parent_cancellation_reported',
                    'direct_child_cancellation_reported',
                    'replay_restart_reported',
                    'fan_out_concurrency_reported',
                    'namespace_behavior_reported',
                    'required_run_metadata_recorded',
                    'declared_outcome_matches_evaluated_status',
                    'scenario_specific_evidence_reported',
                    'published_artifact_install_evidence_reported',
                    'omitted_required_scenarios_link_findings',
                    'artifact_versions_match_latest_published_set',
                    'no_local_product_source_artifacts',
                    'runtime_evidence_emitted_by_published_image_probe',
                    'caller_authored_scenario_json_rejected',
                    'findings_linked_for_non_pass_scenarios',
                ],
                'uncovered_required_scenario_outcome' => 'non_passing',
                'smoke_subset_outcome' => 'non_passing',
                'unsupported_public_surface_outcome' => 'non_passing_with_root_cause_finding',
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
            ],
            'host_runner_contract' => [
                'status' => 'required_for_passing_child_workflows_conformance',
                'result_schema' => self::RESULT_SCHEMA,
                'published_artifact_runner' => 'scripts/conformance/child-workflows-published-artifacts.sh',
                'must_probe_runtime_published_surfaces' => true,
                'must_emit_result_for_every_required_scenario' => true,
                'must_generate_evidence_internally' => true,
                'caller_authored_pass_json_allowed' => false,
                'smoke_summary_only_outcome' => 'non_passing',
                'unexecuted_required_scenario_status' => 'not_covered',
                'coverage_gap_finding_type' => 'conformance_runner_coverage_gap',
                'coverage_gap_owner' => 'conformance_harness',
                'required_execution_scopes' => [
                    'published-artifact-install',
                    'workflow-php-parent-child-shard',
                    'sdk-python-parent-child-shard',
                    'cross-language-parent-child-shard',
                    'failure-round-trip-shard',
                    'cancellation-propagation-shard',
                    'parent-close-policy-shard',
                    'replay-restart-shard',
                    'fan-out-concurrency-shard',
                    'namespace-behavior-shard',
                ],
                'runtime_shards' => [
                    'workflow-php' => [
                        'scope' => 'workflow-php-parent-child-shard',
                        'must_register_workflows' => [
                            'PhpParent',
                            'PhpChild',
                        ],
                        'fallback_status_when_surface_missing' => 'unsupported',
                        'fallback_finding_type' => 'unsupported_public_surface',
                    ],
                    'sdk-python' => [
                        'scope' => 'sdk-python-parent-child-shard',
                        'must_register_workflows' => [
                            'PythonParent',
                            'PythonChild',
                        ],
                        'fallback_status_when_surface_missing' => 'unsupported',
                        'fallback_finding_type' => 'unsupported_public_surface',
                    ],
                ],
                'merge_policy' => [
                    'input_scopes' => [
                        'published-artifact-install',
                        'workflow-php-parent-child-shard',
                        'sdk-python-parent-child-shard',
                        'cross-language-parent-child-shard',
                        'failure-round-trip-shard',
                        'cancellation-propagation-shard',
                        'parent-close-policy-shard',
                        'replay-restart-shard',
                        'fan-out-concurrency-shard',
                        'namespace-behavior-shard',
                    ],
                    'output_schema' => self::RESULT_SCHEMA,
                    'requires_required_runtimes' => [
                        'workflow-php',
                        'sdk-python',
                    ],
                    'requires_required_scenarios' => 'child_workflow_runtime_contract.required_scenarios',
                    'requires_sections' => [
                        'published_artifact_install',
                        'runtime_matrix',
                        'failure_round_trip',
                        'cancellation_propagation',
                        'replay_restart',
                        'fan_out',
                        'namespace_behavior',
                    ],
                ],
                'routing_policy' => [
                    'missing_required_scenario' => [
                        'scenario_status' => 'not_covered',
                        'finding_type' => 'conformance_runner_coverage_gap',
                        'owner' => 'conformance_harness',
                    ],
                    'scenario_product_failure' => [
                        'scenario_status' => 'fail',
                        'finding_source' => 'child_workflow_runtime_contract.finding_policy',
                    ],
                ],
            ],
            'result_gate' => ChildWorkflowRuntimeResultGate::spec(),
            'finding_policy' => [
                'child_result_not_observed' => 'link_root_cause_finding_against_server',
                'cancellation_leak' => 'link_root_cause_finding_against_server',
                'cancellation_observed_as_timeout' => 'link_root_cause_finding_against_parent_sdk',
                'failure_type_erased' => 'link_root_cause_finding_against_codec_or_dispatch_owner',
                'fan_out_serialized' => 'link_root_cause_finding_against_server',
                'replay_divergence' => 'link_root_cause_finding_against_replay_owner',
                'namespace_contract_gap' => 'link_root_cause_finding_against_docs_or_server_owner',
                'unsupported_public_surface' => 'link_root_cause_finding_against_surface_owner',
                'conformance_runner_coverage_gap' => 'link_root_cause_finding_against_conformance_harness',
            ],
        ];
    }
}
