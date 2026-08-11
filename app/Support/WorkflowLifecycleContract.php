<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for first-class workflow lifecycle conformance.
 */
final class WorkflowLifecycleContract
{
    public const SCHEMA = 'durable-workflow.v2.workflow-lifecycle.contract';

    public const VERSION = 2;

    public const RESULT_SCHEMA = 'durable-workflow.v2.workflow-lifecycle.result';

    public const RESULT_VERSION = 2;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        $scenarioRequirements = self::scenarioRequirements();

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'result_schema' => self::RESULT_SCHEMA,
            'result_version' => self::RESULT_VERSION,
            'fixture_category' => 'workflow_lifecycle_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'workflow_lifecycle_contract',
                'suite_schema' => PlatformConformanceSuite::SCHEMA,
                'suite_version' => PlatformConformanceSuite::VERSION,
                'public_path' => 'https://durable-workflow.github.io/platform-conformance/workflow-lifecycle-scenarios.json',
                'source_path' => 'static/platform-conformance/workflow-lifecycle-scenarios.json',
            ],
            'artifact_policy' => [
                'version_source' => 'latest_published_artifacts_at_run_time',
                'version_requirement' => 'concrete_published_versions_pinned_at_run_time',
                'placeholder_versions_rejected' => true,
                'requires_recognized_published_artifact_sources' => true,
                'requires_source_policy' => true,
                'local_product_source_truthy_values' => [
                    true,
                    1,
                    '1',
                    'true',
                    'yes',
                    'on',
                ],
                'install_channels' => [
                    'server' => 'docker image durableworkflow/server:<exact published version or digest>',
                    'cli' => 'official dw release install script pinned to its latest release tag',
                    'workflow-php' => 'Composer package durable-workflow/workflow:2.0.0-alpha.<latest>',
                    'sdk-php' => 'Composer package durable-workflow/sdk:<exact released version>',
                    'sdk-python' => 'PyPI package durable-workflow==<latest>',
                    'sdk-rust' => 'crates.io package durable-workflow=<exact released version>',
                    'waterline' => 'published Waterline observer artifact matching the release set',
                ],
                'release_artifact_aliases' => [
                    'workflow-php' => ['workflow'],
                    'sdk-python' => ['python'],
                    'sdk-rust' => ['rust'],
                ],
                'forbidden_sources' => [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                    'local_checkout_artifact',
                    'local_source_checkout',
                    'workspace_repo',
                    'branch_source',
                    'local_vendor_tree',
                ],
                'required_run_record_fields' => [
                    'artifact_versions',
                    'published_artifact_versions',
                    'artifact_sources',
                    'started_at',
                    'finished_at',
                    'generated_at',
                    'outcome',
                    'runner_blocked',
                    'scenario_results',
                    'lifecycle_cell_outcomes',
                    'findings',
                    'local_product_source_checkouts_used',
                    'source_policy',
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
                'namespace' => 'workflow-lifecycle-conformance',
                'task_queue' => 'workflow-lifecycle-shared',
                'required_public_surfaces' => [
                    'POST /api/workflows',
                    'GET /api/workflows/{workflowId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}/history',
                    'POST /api/workflows/{workflowId}/runs/{runId}/cancel',
                    'POST /api/workflows/{workflowId}/runs/{runId}/terminate',
                    'CLI workflow start/show/history/cancel/terminate/result',
                    'Waterline workflow run detail and history views',
                ],
                'required_workers' => [
                    'sdk-php',
                    'sdk-python',
                    'sdk-rust',
                ],
            ],
            'required_scenarios' => array_keys($scenarioRequirements),
            'scenario_requirements' => $scenarioRequirements,
            'coverage_gate' => [
                'passing_outcome' => 'pass',
                'non_passing_outcomes' => [
                    'fail',
                    'unsupported',
                    'not_covered',
                    'runner_blocked',
                    'non_passing',
                ],
                'focused_findings_required_for_non_pass_cells' => true,
                'run_record_provenance_required_for_pass' => true,
                'local_product_source_truthy_values_refuse_pass' => true,
                'published_artifact_cell_execution_required_for_pass' => true,
                'unsupported_cells_require_documented_typed_refusal' => true,
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'run_timestamps_outcome_runner_blocked_and_findings_are_recorded',
                    'declared_outcome_matches_evaluated_status',
                    'published_artifact_versions_are_recorded_and_pinned',
                    'no_local_product_source_artifacts',
                    'each_pass_scenario_proves_published_artifact_cell_execution',
                    'each_pass_scenario_reports_required_evidence',
                    'findings_linked_for_non_pass_scenarios',
                    'continue_as_new_chain_reports_distinct_run_ids_and_one_workflow_id',
                    'continue_as_new_history_links_predecessor_and_successor_runs',
                    'continue_as_new_does_not_duplicate_side_effects',
                    'cancellation_reaches_documented_terminal_state_and_typed_errors',
                    'termination_reaches_documented_terminal_state_and_typed_errors',
                    'duplicate_start_policy_is_enforced_or_refused_clearly',
                    'workflow_timeout_records_operator_visible_timing_and_terminal_state',
                    'workflow_retry_backoff_is_proven_or_unsupported_retry_refuses_clearly',
                    'unsupported_cells_report_documented_typed_refusal_evidence',
                    'php_python_and_rust_sdk_cells_pass_or_emit_documented_typed_errors',
                    'rust_sdk_shard_uses_exact_crates_io_and_matching_server_artifacts',
                    'rust_sdk_timed_out_is_server_terminal_not_client_wait_timeout',
                    'rust_sdk_replacement_worker_starts_before_cancelled_activity_settles',
                    'rust_sdk_continue_as_new_redelivery_preserves_predecessor_decisions_across_process_replacement',
                    'rust_sdk_machine_readable_outcomes_are_semantically_validated',
                    'cli_api_history_and_waterline_surfaces_are_operator_diagnostic_enough',
                    'non_passing_lifecycle_shards_retain_bounded_portable_diagnostics',
                ],
            ],
            'host_runner_contract' => [
                'status' => 'host_executable_published_artifact_runner',
                'runner_id' => 'workflow-lifecycle',
                'result_schema' => self::RESULT_SCHEMA,
                'runner_repository' => 'server',
                'runner_path' => 'scripts/conformance/workflow-lifecycle-host-published-artifacts.sh',
                'runner_command' => 'scripts/conformance/workflow-lifecycle-host-published-artifacts.sh --result-dir <result-dir>',
                'runner_execution_context' => 'docker_capable_host',
                'runner_distribution' => 'extract_from_exact_published_server_image',
                'runner_image_path' => '/app/scripts/conformance/workflow-lifecycle-host-published-artifacts.sh',
                'runner_extraction_command' => 'docker cp <created-server-container>:/app/scripts/conformance/workflow-lifecycle-host-published-artifacts.sh <host-runner-path>',
                'published_image_result_runner_path' => 'scripts/conformance/workflow-lifecycle-published-artifacts.sh',
                'published_topology' => [
                    'executor' => 'docker_capable_host',
                    'server' => 'exact_durableworkflow_server_image_http_process',
                    'scheduler' => 'exact_durableworkflow_server_image_scheduler_process',
                    'rust' => 'pinned_rust_image_exact_crates_io_probe',
                    'runner_source' => 'scripts_extracted_from_exact_server_image',
                    'network' => 'isolated_docker_network',
                ],
                'evidence_inputs' => [
                    'DW_WORKFLOW_LIFECYCLE_EVIDENCE',
                    'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH',
                    '<result-dir>/workflow-lifecycle-evidence.json',
                    '<result-dir>/php-sdk-lifecycle-evidence.json',
                    '<result-dir>/python-sdk-lifecycle-evidence.json',
                    '<result-dir>/rust-sdk-lifecycle-evidence.json',
                ],
                'php_sdk_probe_executors' => [
                    'published_server_php_and_composer_separate_processes',
                    'local_php_and_composer',
                ],
                'php_sdk_probe_binary_overrides' => [
                    'DW_WORKFLOW_LIFECYCLE_PHP_BIN',
                    'DW_WORKFLOW_LIFECYCLE_COMPOSER_BIN',
                    'PHP_BIN',
                    'COMPOSER_BIN',
                ],
                'php_sdk_probe_does_not_require_docker_inside_server_container' => true,
                'php_sdk_package' => 'durable-workflow/sdk',
                'php_sdk_version_environment' => 'DW_PHP_SDK_VERSION',
                'php_sdk_process_boundary_required' => true,
                'php_sdk_runner_path' => 'scripts/conformance/php-sdk-published-artifacts.sh',
                'python_sdk_probe_executors' => [
                    'python_venv_pypi_install',
                    'configured_python_binary',
                ],
                'python_sdk_probe_binary_overrides' => [
                    'DW_WORKFLOW_LIFECYCLE_PYTHON_BIN',
                    'PYTHON_BIN',
                ],
                'python_sdk_probe_does_not_require_docker_inside_server_container' => true,
                'python_sdk_runtime_discovery_fixture_path' => 'scripts/conformance/workflow_lifecycle_python_discovery_fixture.py',
                'python_sdk_runtime_discovery_request' => 'GET /api/cluster/info',
                'python_sdk_runtime_discovery_required_capability' => 'worker_protocol.server_capabilities.query_tasks',
                'python_sdk_pre_behavior_failure_classification' => 'runner-gap',
                'python_sdk_unexpected_exception_required_fields' => [
                    'operation',
                    'classification',
                    'owning_surface',
                    'exception_type',
                    'message',
                ],
                'rust_sdk_probe_executors' => [
                    'docker_rust_1_86_exact_crates_io_install',
                    'configured_cargo_binary',
                ],
                'rust_sdk_probe_binary_overrides' => [
                    'DW_WORKFLOW_LIFECYCLE_CARGO_BIN',
                    'CARGO_BIN',
                ],
                'rust_sdk_probe_required' => true,
                'rust_sdk_probe_minimum_version' => '0.1.15',
                'rust_sdk_probe_requires_http_and_scheduler_topology' => true,
                'rust_sdk_probe_runs_outside_server_container' => true,
                'rust_sdk_probe_source_policy' => 'published_crates_io_package_and_published_server_image_only',
                'result_files' => [
                    'pins.json',
                    'run-metadata.json',
                    'php-sdk-lifecycle-evidence.json',
                    'php-sdk-conformance-result.json',
                    'python-sdk-lifecycle-evidence.json',
                    'rust-sdk-lifecycle-evidence.json',
                    'workflow-lifecycle-result.json',
                    'workflow-lifecycle-record.json',
                    'workflow-lifecycle-findings.json',
                    'lifecycle-result.json',
                    'lifecycle-record.json',
                ],
                'lifecycle_shard_diagnostics' => [
                    'schema' => 'durable-workflow.v2.workflow-lifecycle.shard-diagnostic',
                    'non_pass_shards' => [
                        'php_sdk_lifecycle_surface',
                        'python_sdk_lifecycle_surface',
                        'rust_sdk_lifecycle_surface',
                    ],
                    'retention' => 'inline_result_record_scenario_and_finding',
                    'max_bytes_per_shard' => 8192,
                    'required_fields' => [
                        'operation',
                        'process_state',
                        'classification',
                        'owning_surface',
                        'excerpt',
                    ],
                    'http_status_and_reason_retained_when_observed' => true,
                    'readiness_mismatch_and_last_server_observation_retained_when_observed' => true,
                    'assertion_expected_and_observed_per_failed_operation_retained' => true,
                    'assertion_worker_and_sdk_response_layers_retained_when_observed' => true,
                    'client_timeout_and_unavailable_worker_companion_retained' => true,
                    'companion_diagnostic_schema' => 'durable-workflow.v2.php-sdk-companion-failure',
                    'companion_diagnostic_max_bytes' => 6144,
                    'companion_evidence_led_ownership_required' => true,
                    'structured_worker_protocol_failure_required' => true,
                    'worker_runtime_exception_separate_from_protocol_failure' => true,
                    'worker_runtime_exception_required_fields' => [
                        'operation',
                        'classification',
                        'owning_surface',
                        'exception_type',
                        'message',
                    ],
                    'worker_protocol_failure_required_fields' => [
                        'operation',
                        'http_method',
                        'endpoint_class',
                        'status_code',
                        'reason',
                        'retryable',
                        'task_id',
                        'workflow_id',
                        'run_id',
                        'public_error_envelope',
                    ],
                    'generic_protocol_failure_server_error_record_required' => true,
                    'generic_protocol_failure_correlated_error_severity_required' => true,
                    'credential_redaction_required' => true,
                    'workspace_path_reference_is_not_retained_evidence' => true,
                    'passing_shards_remain_without_failure_diagnostics' => true,
                ],
                'host_runner_implemented' => true,
                'must_exercise_published_artifacts' => true,
                'must_execute_against_published_artifacts' => true,
                'must_probe_runtime_published_surfaces' => true,
                'must_name_public_artifact_sources' => true,
                'must_record_lifecycle_cell_outcomes' => true,
                'must_emit_per_cell_outcomes' => true,
                'must_record_source_policy' => true,
                'must_record_findings_even_for_clean_pass' => true,
                'no_local_product_source_checkout_pass_evidence' => true,
                'pass_requires_explicit_published_artifact_cell_execution' => true,
                'unsupported_cells_require_documented_typed_refusal' => true,
                'unexecuted_required_scenario_status' => 'not_covered',
                'smoke_summary_only_outcome' => 'non_passing',
                'required_execution_scopes' => [
                    'published-artifact-workflow-lifecycle',
                    'continue-as-new-run-chain',
                    'continue-as-new-history-continuity',
                    'continue-as-new-side-effect-idempotence',
                    'public-cancellation',
                    'public-termination',
                    'workflow-id-reuse-duplicate-start-policy',
                    'workflow-timeout-terminal-state',
                    'workflow-retry-backoff-or-refusal',
                    'php-sdk-lifecycle-surface',
                    'python-sdk-lifecycle-surface',
                    'rust-sdk-lifecycle-surface',
                    'cli-api-history-waterline-operator-diagnostics',
                ],
            ],
            'result_gate' => WorkflowLifecycleResultGate::spec(),
            'finding_policy' => [
                'missing_run_record_field' => 'link_root_cause_finding_against_conformance_harness',
                'missing_lifecycle_cell_outcome' => 'link_root_cause_finding_against_conformance_harness',
                'local_product_source_checkout_used' => 'link_root_cause_finding_against_conformance_harness',
                'local_product_source_checkouts_used_must_be_false' => 'link_root_cause_finding_against_conformance_harness',
                'missing_artifact_source' => 'link_root_cause_finding_against_conformance_harness',
                'forbidden_artifact_source' => 'link_root_cause_finding_against_conformance_harness',
                'missing_focused_finding_for_non_pass_cell' => 'link_root_cause_finding_against_conformance_harness',
                'product_behavior_failure' => 'link_root_cause_finding_against_server_or_sdk_owner',
                'operator_visibility_gap' => 'link_root_cause_finding_against_waterline_or_server',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function requiredScenarios(): array
    {
        return array_keys(self::scenarioRequirements());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function scenarioRequirements(): array
    {
        $sharedFields = [
            'observed_outputs',
            'lifecycle_cell_outcome',
            'artifact_sources',
            'local_product_source_checkouts_used',
            'source_policy',
        ];

        return [
            'continue_as_new_run_chain_visibility' => [
                'title' => 'Continue-as-new run-chain visibility',
                'required_fields' => $sharedFields,
                'evidence' => ['workflow_id', 'initial_run_id', 'continued_run_id', 'run_count', 'current_run_id', 'run_numbers'],
                'required_behavior' => 'continue_as_new_creates_a_visible_run_chain_under_one_logical_workflow_id',
            ],
            'continue_as_new_identity_and_history_continuity' => [
                'title' => 'Continue-as-new identity and history continuity',
                'required_fields' => $sharedFields,
                'evidence' => ['workflow_id', 'history_events', 'predecessor_closed_event', 'successor_started_event', 'history_api_links'],
                'required_behavior' => 'history_surfaces_link_predecessor_and_successor_runs_without_losing_logical_identity',
            ],
            'continue_as_new_duplicate_side_effect_prevention' => [
                'title' => 'Continue-as-new duplicate side-effect prevention',
                'required_fields' => $sharedFields,
                'evidence' => ['workflow_id', 'side_effect_key', 'expected_count', 'observed_count', 'replay_or_restart_window'],
                'required_behavior' => 'continue_as_new_replay_or_restart_does_not_duplicate_side_effects',
            ],
            'cancellation_public_surface_terminal_state' => [
                'title' => 'Cancellation public terminal state',
                'required_fields' => $sharedFields,
                'evidence' => ['workflow_id', 'request_surface', 'cancel_requested_at', 'terminal_status', 'worker_error_type', 'caller_error_type'],
                'required_behavior' => 'public_cancellation_reaches_cancelled_and_surfaces_typed_worker_and_caller_errors',
                'terminal_states' => ['cancelled'],
            ],
            'termination_public_surface_terminal_state' => [
                'title' => 'Termination public terminal state',
                'required_fields' => $sharedFields,
                'evidence' => ['workflow_id', 'request_surface', 'terminate_requested_at', 'terminal_status', 'worker_error_type', 'caller_error_type'],
                'required_behavior' => 'public_termination_reaches_terminated_and_surfaces_typed_worker_and_caller_errors',
                'terminal_states' => ['terminated'],
            ],
            'workflow_id_reuse_duplicate_start_policy' => [
                'title' => 'Workflow id reuse and duplicate-start policy',
                'required_fields' => $sharedFields,
                'evidence' => ['workflow_id', 'duplicate_policy', 'first_start_outcome', 'first_run_id', 'duplicate_start_outcome', 'http_status_or_error_type', 'run_count_after_duplicate', 'run_ids_after_duplicate'],
                'required_behavior' => 'duplicate_workflow_id_start_fail_policy_refuses_the_duplicate_and_preserves_only_the_first_run',
            ],
            'workflow_timeout_terminal_state' => [
                'title' => 'Workflow timeout terminal state',
                'required_fields' => $sharedFields,
                'evidence' => ['workflow_id', 'timeout_field', 'deadline_at', 'observed_terminal_at', 'terminal_status', 'operator_visible_timing', 'unsupported_timeout_shape_refusals'],
                'required_behavior' => 'workflow_execution_or_run_timeout_records_deadline_timing_and_terminal_state',
            ],
            'workflow_retry_backoff_or_refusal' => [
                'title' => 'Workflow retry backoff or typed unsupported refusal',
                'required_fields' => $sharedFields,
                'evidence' => ['workflow_id', 'retry_policy_shape', 'attempt_count_or_refusal_reason', 'backoff_observation_or_error_type', 'docs_match'],
                'required_behavior' => 'workflow_retry_backoff_is_executed_where_supported_or_retry_policy_is_refused_clearly',
            ],
            'php_sdk_lifecycle_surface' => [
                'title' => 'PHP SDK lifecycle surface',
                'required_fields' => $sharedFields,
                'evidence' => ['sdk', 'covered_cells', 'unsupported_cells', 'typed_errors', 'artifact_version', 'server_version', 'install_provenance', 'apache_avro_provenance', 'client_processes', 'worker_processes', 'callback_counts', 'history_assertions', 'local_product_source_checkouts_used'],
                'required_behavior' => 'php_sdk_exercises_supported_lifecycle_cells_or_refuses_unsupported_cells_with_typed_errors',
            ],
            'python_sdk_lifecycle_surface' => [
                'title' => 'Python SDK lifecycle surface',
                'required_fields' => $sharedFields,
                'evidence' => ['sdk', 'covered_cells', 'unsupported_cells', 'typed_errors', 'artifact_version'],
                'required_behavior' => 'python_sdk_exercises_supported_lifecycle_cells_or_refuses_unsupported_cells_with_typed_errors',
            ],
            'rust_sdk_lifecycle_surface' => [
                'title' => 'Rust SDK exact-crate lifecycle surface',
                'required_fields' => $sharedFields,
                'evidence' => ['sdk', 'covered_cells', 'unsupported_cells', 'typed_errors', 'artifact_version', 'server_version', 'server_cluster_info', 'install_provenance', 'workflow_identities', 'scenario_outcomes', 'stable_reasons', 'payload_contract', 'executor_topology', 'rust_shard_contract_version', 'shard_runner', 'shard_exit_status'],
                'required_behavior' => 'rust_sdk_exact_crate_exercises_lifecycle_and_preserves_committed_predecessor_decisions_across_continue_as_new_redelivery_and_worker_process_replacement',
            ],
            'operator_diagnostics_surfaces' => [
                'title' => 'CLI, API, history, and Waterline lifecycle diagnostics',
                'required_fields' => $sharedFields,
                'evidence' => ['workflow_id', 'cli_fields', 'api_fields', 'history_fields', 'waterline_fields', 'diagnostic_transition_matrix'],
                'required_behavior' => 'cli_api_history_and_waterline_expose_enough_state_to_diagnose_every_lifecycle_transition',
            ],
        ];
    }
}
