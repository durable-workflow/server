<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable handoff for Python SDK published-artifact parity evidence.
 *
 * The Python SDK owns the result evaluator in durable_workflow.python_conformance.
 * This server-side manifest advertises the source-free host runner that brings
 * up the public server image and exercises the official CLI plus PyPI SDK path.
 */
final class PythonSdkParityContract
{
    public const SCHEMA = 'durable-workflow.v2.python-sdk-parity.contract';

    public const VERSION = 1;

    public const RESULT_SCHEMA = 'durable-workflow.v2.python-sdk-parity.result';

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
            'fixture_category' => 'python_sdk_published_artifact_parity',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'python_result_gate_authority' => [
                'package' => 'durable-workflow',
                'module' => 'durable_workflow.python_conformance',
                'compose_command' => 'durable-workflow-python-conformance --compose host-evidence.json',
                'evaluate_command' => 'durable-workflow-python-conformance --evaluate python-conformance-result.json',
            ],
            'artifact_policy' => [
                'version_source' => 'latest_complete_published_artifact_set_at_run_time',
                'version_requirement' => 'concrete_published_versions_with_downloadable_install_assets_pinned_at_run_time',
                'placeholder_versions_rejected' => true,
                'release_records_without_assets_are_rejected' => true,
                'install_channels' => [
                    'server' => 'Docker image durableworkflow/server:<exact patch version or digest with DW_SERVER_VERSION>',
                    'cli' => 'official dw install script pinned to its resolved release tag',
                    'sdk-python' => 'PyPI package durable-workflow==<resolved-version>',
                    'workflow' => 'Composer package durable-workflow/workflow:<resolved-version>',
                    'waterline' => 'published Waterline package matching the resolved release set',
                ],
                'forbidden_sources' => [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                    'editable_python_sdk_install',
                    'rolling_server_image_tag',
                ],
                'required_run_record_fields' => [
                    'artifact_versions',
                    'started_at',
                    'finished_at',
                    'generated_at',
                    'outcome',
                    'scenario_results',
                    'capability_table',
                    'protocol_traces',
                    'php_assumption_audit',
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
            'required_scenarios' => self::requiredScenarios(),
            'required_capabilities' => self::requiredCapabilities(),
            'coverage_gate' => [
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'all_required_scenarios_pass',
                    'all_required_capabilities_reported',
                    'all_required_capabilities_pass',
                    'artifact_versions_recorded_and_pinned',
                    'run_timestamps_recorded',
                    'official_cli_install_start_and_result_path_reported',
                    'cold_first_user_setup_reported',
                    'protocol_traces_reported_for_control_and_worker_planes',
                    'php_assumption_audit_passes',
                    'no_local_product_source_artifacts',
                    'findings_linked_for_non_pass_scenarios',
                    'declared_outcome_matches_evaluated_status',
                ],
                'smoke_subset_outcome' => 'non_passing',
                'uncovered_required_scenario_outcome' => 'non_passing',
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
            ],
            'host_runner_contract' => [
                'status' => 'required_for_passing_python_sdk_parity_conformance',
                'runner_repository' => 'server',
                'runner_path' => 'scripts/conformance/python-published-artifacts.sh',
                'runner_command' => 'scripts/conformance/python-published-artifacts.sh --result-dir <result-dir>',
                'result_schema' => self::RESULT_SCHEMA,
                'result_files' => [
                    'pins.json',
                    'run-metadata.json',
                    'python-host-evidence.json',
                    'python-conformance-result.json',
                    'python-conformance-evaluation.json',
                    'python-conformance-record.json',
                    'protocol-traces.json',
                ],
                'support_files' => [
                    'scripts/conformance/python_external_payload_evidence.py',
                    'scripts/conformance/python_worker_stop_deregistration.py',
                ],
                'must_execute_against_published_artifacts' => true,
                'must_record_runner_blocked_false_for_product_evidence' => true,
                'must_emit_result_for_every_required_scenario' => true,
                'must_emit_complete_capability_table' => true,
                'must_compose_with_installed_sdk_result_gate' => true,
                'smoke_summary_only_outcome' => 'non_passing_smoke_only',
                'required_execution_scopes' => [
                    'published-artifact-install',
                    'official-cli-install-start-result',
                    'cold-first-user-setup',
                    'python-worker-registration',
                    'activity-backed-workflow-execution',
                    'worker-restart-activity-signal-state',
                    'runtime-mediated-external-payload-round-trips',
                    'control-plane-protocol-traces',
                    'worker-plane-protocol-traces',
                    'no-php-assumption-audit',
                    'complete-python-capability-table',
                ],
                'runtime_external_payload_contract' => [
                    'standalone_execution' => [
                        'environment' => 'fresh_isolated_standalone_namespace',
                        'storage_ownership' => 'namespace_runtime',
                        'threshold_policy' => 'low_threshold_for_inline_and_forced_externalization',
                        'python_process_provider_credentials' => 'forbidden',
                        'python_process_provider_specific_references' => 'forbidden',
                        'required_observations' => [
                            'client_start_and_result_round_trip',
                            'worker_workflow_and_activity_boundaries',
                            'orderly_worker_replacement',
                            'retained_history_replay_identity',
                            'payload_size_and_sha256',
                            'malformed_reference_rejection',
                            'integrity_mismatch_rejection',
                            'namespace_cleanup',
                        ],
                    ],
                    'isolated_cloud_handoff' => [
                        'environment_variable' => 'DW_PYTHON_CONFORMANCE_CLOUD_EVIDENCE_JSON',
                        'schema' => 'durable-workflow.v2.python-runtime-external-payload.cloud-evidence',
                        'version' => 1,
                        'authority' => 'managed_runtime_conformance',
                        'must_match_exact_artifact_tuple' => true,
                        'standalone_inference_forbidden' => true,
                        'required_identity_fields' => [
                            'evidence_id',
                            'generated_at',
                        ],
                        'required_observations' => [
                            'inline_round_trip',
                            'externalized_round_trip',
                            'cross_language_round_trip',
                            'ordinary_runtime_credentials',
                            'provider_setup_absent',
                            'worker_replacement',
                            'retained_history_replay_identity',
                            'size_sha256_verification',
                            'malformed_reference_rejection',
                            'integrity_mismatch_rejection',
                            'cleanup',
                        ],
                        'missing_evidence_status' => 'not_covered',
                        'mismatched_evidence_status' => 'fail',
                    ],
                ],
                'runtime_shards' => [
                    'cli' => [
                        'scope' => 'official-cli-install-start-result',
                        'artifact' => 'cli',
                        'must_install_from' => 'official_install_script',
                        'must_cover_commands' => [
                            'namespace:create',
                            'workflow:start',
                            'workflow:describe',
                            'workflow:show-run',
                        ],
                    ],
                    'sdk-python' => [
                        'scope' => 'python-worker-registration',
                        'artifact' => 'sdk-python',
                        'must_install_from' => 'pypi',
                        'must_cover_capabilities' => [
                            'python_worker_connects',
                            'python_worker_registers_workflows',
                            'python_worker_registers_activities',
                            'python_workflow_runs',
                            'python_activity_runs',
                            'worker_restart_replays_activity_state',
                            'worker_restart_replays_signal_state',
                            'runtime_external_payload_inline_round_trip',
                            'runtime_external_payload_externalized_round_trip',
                            'runtime_external_payload_cross_language_round_trip',
                            'runtime_external_payload_standalone_server',
                            'runtime_external_payload_isolated_cloud',
                            'runtime_external_payload_provider_setup_absent',
                        ],
                    ],
                ],
                'routing_policy' => [
                    'missing_required_scenario' => [
                        'scenario_status' => 'not_covered',
                        'finding_type' => 'conformance_runner_coverage_gap',
                        'owner' => 'conformance_harness',
                    ],
                    'expanded_cell_product_failure' => [
                        'scenario_status' => 'fail',
                        'finding_type' => 'product_behavior_gap',
                        'owner' => 'owning_product_surface',
                    ],
                    'host_environment_failure' => [
                        'scenario_status' => 'runner_blocked',
                        'finding_type' => 'runner_gap',
                        'owner' => 'conformance_harness',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function requiredScenarios(): array
    {
        return [
            'published_artifact_install_only',
            'official_cli_install_start_result_path',
            'cold_first_user_setup',
            'python_worker_registration',
            'activity_backed_workflow_execution',
            'workflow_result_surface',
            'worker_restart_activity_and_signal_state',
            'runtime_external_payload_round_trips',
            'protocol_trace_capture',
            'php_assumption_audit',
            'capability_table_complete',
        ];
    }

    /**
     * @return list<string>
     */
    public static function requiredCapabilities(): array
    {
        return [
            'server_up',
            'official_cli_installed',
            'cli_reaches_server',
            'cli_starts_workflow',
            'cli_reads_workflow_result',
            'cold_first_user_setup',
            'python_sdk_installed_from_pypi',
            'python_worker_connects',
            'python_worker_registers_workflows',
            'python_worker_registers_activities',
            'python_workflow_runs',
            'python_activity_runs',
            'workflow_result_returned',
            'worker_restart_replays_activity_state',
            'worker_restart_replays_signal_state',
            'runtime_external_payload_inline_round_trip',
            'runtime_external_payload_externalized_round_trip',
            'runtime_external_payload_cross_language_round_trip',
            'runtime_external_payload_standalone_server',
            'runtime_external_payload_isolated_cloud',
            'runtime_external_payload_provider_setup_absent',
            'protocol_traces_recorded',
            'php_assumptions_absent',
        ];
    }
}
