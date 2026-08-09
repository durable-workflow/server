<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for first-class workflow update conformance.
 */
final class WorkflowUpdateRuntimeContract
{
    public const SCHEMA = 'durable-workflow.v2.workflow-update-runtime.contract';

    public const VERSION = 3;

    public const RESULT_SCHEMA = 'durable-workflow.v2.workflow-update-runtime.result';

    public const RESULT_VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        $scenarioRequirements = self::allScenarioRequirements();

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'result_schema' => self::RESULT_SCHEMA,
            'result_version' => self::RESULT_VERSION,
            'fixture_category' => 'workflow_update_runtime_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'workflow_update_runtime_contract',
                'suite_schema' => PlatformConformanceSuite::SCHEMA,
                'suite_version' => PlatformConformanceSuite::VERSION,
                'public_path' => 'https://durable-workflow.github.io/platform-conformance/workflow-update-runtime-scenarios.json',
                'source_path' => 'static/platform-conformance/workflow-update-runtime-scenarios.json',
            ],
            'artifact_policy' => [
                'version_source' => 'latest_published_artifacts_at_run_time',
                'version_requirement' => 'concrete_published_versions_pinned_at_run_time',
                'placeholder_versions_rejected' => true,
                'requires_recognized_published_artifact_sources' => true,
                'requires_source_policy' => true,
                'install_channels' => [
                    'server' => 'docker image durableworkflow/server:<exact published version or digest>',
                    'cli' => 'official dw release install script pinned to its latest release tag',
                    'workflow-php' => 'Composer package durable-workflow/workflow:2.0.0-alpha.<latest>',
                    'sdk-php' => 'Composer package durable-workflow/sdk:<latest>',
                    'sdk-python' => 'PyPI package durable-workflow==<latest>',
                    'waterline' => 'published Waterline observer artifact matching the release set',
                ],
                'release_artifact_aliases' => [
                    'workflow-php' => ['workflow'],
                    'sdk-python' => ['python'],
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
                    'update_cell_outcomes',
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
                'namespace' => 'workflow-updates-conformance',
                'task_queue' => 'workflow-updates-shared',
                'workflow_type' => 'UpdateProbe',
                'declared_updates' => [
                    'approve',
                    'adjust_payload',
                    'fail_update',
                ],
                'required_public_surfaces' => [
                    'POST /api/workflows/{workflowId}/update/{updateName}',
                    'POST /api/workflows/{workflowId}/runs/{runId}/update/{updateName}',
                    'GET /api/workflows/{workflowId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}/history',
                    'CLI workflow:update --json',
                    'Waterline workflow run detail, update list, and history views',
                ],
                'required_workers' => [
                    'sdk-php',
                    'sdk-python',
                ],
            ],
            'service_mode_update_validation' => [
                'supported' => true,
                'acceptance_boundary' => 'validator_approved',
                'worker_capability' => WorkflowUpdateValidationTaskBroker::CAPABILITY,
                'workflow_contract_field' => WorkflowUpdateValidationTaskBroker::CONTRACT_FIELD,
                'validator_execution' => 'replay_without_handler_or_state_commit',
                'request_id_required' => true,
                'validator_sdk_surfaces' => ['sdk-python'],
                'sdk_surfaces_without_validators' => ['sdk-php', 'sdk-rust'],
                'typed_outcomes' => [
                    'update_validator_rejected',
                    'update_validator_contract_missing',
                    'update_validation_request_id_required',
                    'update_validation_idempotency_conflict',
                    'update_validation_capability_unsupported',
                    'update_validator_worker_unavailable',
                    'update_validator_worker_incompatible',
                    'update_validator_worker_lost',
                    'update_validation_task_not_claimed',
                    'update_validation_execution_timeout',
                    'duplicate_update_validation_completion',
                    'stale_update_validation_completion',
                ],
            ],
            'required_matrix' => [
                'runtimes' => [
                    'sdk-php',
                    'sdk-python',
                ],
                'client_paths' => [
                    'raw-api',
                    'cli',
                    'sdk-php',
                    'sdk-python',
                ],
                'observer_paths' => [
                    'control-plane-response',
                    'history-api',
                    'run-detail-api',
                    'waterline-selected-run-detail',
                    'waterline-update-history',
                ],
                'principal_paths' => [
                    'anonymous-disabled-auth',
                    'raw-http-token',
                    'cli-token',
                    'sdk-python-token',
                    'sdk-php-token',
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
                'runner_blocked_cells_are_non_passing' => true,
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'declared_outcome_matches_evaluated_status',
                    'published_artifact_versions_are_recorded_and_pinned',
                    'no_local_product_source_artifacts',
                    'each_pass_scenario_proves_published_artifact_cell_execution',
                    'accepted_running_waiting_completed_and_failed_update_outcomes_are_observed',
                    'duplicate_request_or_idempotency_behavior_is_reported',
                    'unknown_update_invalid_input_and_terminal_workflow_refusals_are_typed',
                    'payload_envelope_round_trip_visible_on_api_history_and_operator_surfaces',
                    'principal_attribution_verified_when_authentication_is_enabled',
                    'php_and_python_update_cells_pass_or_emit_typed_unsupported_evidence',
                    'validator_approval_rejection_worker_replacement_and_completion_fencing_are_observed',
                    'operator_readable_surfaces_cover_control_plane_history_cli_and_waterline',
                ],
            ],
            'host_runner_contract' => [
                'status' => 'focused_server_runtime_probe_php_python_and_operator_diagnostics_shards_implemented',
                'runner_id' => 'workflow-updates',
                'result_schema' => self::RESULT_SCHEMA,
                'runner_repository' => 'server',
                'runner_path' => 'scripts/conformance/workflow-updates-published-artifacts.sh',
                'runner_command' => 'scripts/conformance/workflow-updates-published-artifacts.sh --result-dir <result-dir>',
                'evidence_inputs' => [
                    'DW_WORKFLOW_UPDATES_EVIDENCE',
                    'DW_WORKFLOW_UPDATES_EVIDENCE_PATH',
                    'DW_WORKFLOW_UPDATES_PHP_EVIDENCE',
                    'DW_WORKFLOW_UPDATES_PHP_EVIDENCE_PATH',
                    'DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE',
                    'DW_WORKFLOW_UPDATES_PYTHON_EVIDENCE_PATH',
                    'DW_WORKFLOW_UPDATES_OPERATOR_DIAGNOSTICS_EVIDENCE',
                    'DW_WORKFLOW_UPDATES_OPERATOR_DIAGNOSTICS_EVIDENCE_PATH',
                    'DW_WORKFLOW_UPDATES_SKIP_FOCUSED_HOST_PROBE',
                    'DW_WORKFLOW_UPDATES_SKIP_PHP_PACKAGE_SHARD',
                    'DW_WORKFLOW_UPDATES_SKIP_PYTHON_SDK_SHARD',
                    'DW_WORKFLOW_UPDATES_SKIP_OPERATOR_DIAGNOSTICS_SHARD',
                ],
                'result_files' => [
                    'pins.json',
                    'run-metadata.json',
                    'workflow-updates-focused-evidence.json',
                    'sdk-php-workflow-updates-evidence.json',
                    'python-sdk-workflow-updates-evidence.json',
                    'workflow-updates-operator-diagnostics-evidence.json',
                    'workflow-updates-result.json',
                    'workflow-updates-record.json',
                    'workflow-updates-findings.json',
                ],
                'host_runner_implemented' => true,
                'must_exercise_published_artifacts' => true,
                'must_execute_against_published_artifacts' => true,
                'must_probe_runtime_published_surfaces' => true,
                'must_name_public_artifact_sources' => true,
                'must_emit_per_cell_outcomes' => true,
                'must_record_source_policy' => true,
                'must_record_findings_even_for_clean_pass' => true,
                'no_local_product_source_checkout_pass_evidence' => true,
                'pass_requires_explicit_published_artifact_cell_execution' => true,
                'unsupported_cells_require_documented_typed_refusal' => true,
                'unexecuted_required_scenario_status' => 'not_covered',
                'focused_probe' => [
                    'status' => 'implemented',
                    'runner_scope' => 'published_server_image',
                    'evidence_file' => 'workflow-updates-focused-evidence.json',
                    'covers_required_scenarios' => [
                        'published_artifact_install_only',
                        'declared_update_contract_visibility',
                        'accepted_update_control_plane_and_history',
                        'running_or_waiting_update_operator_visibility',
                        'completed_update_result_round_trip',
                        'failed_update_outcome',
                        'duplicate_request_idempotency',
                        'unknown_update_refusal',
                        'invalid_input_refusal',
                        'payload_envelope_round_trip',
                        'terminal_workflow_update_behavior',
                        'principal_attribution_with_auth',
                        'update_validator_approval_boundary',
                        'update_validator_rejection_boundary',
                        'update_validator_worker_replacement',
                        'duplicate_validation_completion',
                        'unsupported_validation_capability',
                    ],
                    'records_runner_blocked_false_for_executed_product_evidence' => true,
                    'uses_external_worker_update_contracts' => true,
                    'uses_public_control_plane_and_worker_protocol_routes' => true,
                ],
                'php_sidecar' => [
                    'status' => 'implemented',
                    'runner_scope' => 'published_packagist_sdk_php_artifact',
                    'evidence_file' => 'sdk-php-workflow-updates-evidence.json',
                    'covers_required_scenarios' => [
                        'php_client_worker_update_surface',
                    ],
                    'records_runner_blocked_false_for_executed_product_evidence' => true,
                    'out_of_scope_scenario_results_are_ignored' => true,
                    'uses_packagist_artifact_client_and_worker_update_surface' => true,
                    'must_record_package_version_artifact_source_handler_request_and_cells' => true,
                    'must_reject_local_product_source_checkout_and_local_artifact_sources' => true,
                ],
                'python_sidecar' => [
                    'status' => 'implemented',
                    'runner_scope' => 'published_python_sdk_artifact',
                    'evidence_file' => 'python-sdk-workflow-updates-evidence.json',
                    'covers_required_scenarios' => [
                        'python_client_worker_update_surface',
                    ],
                    'records_runner_blocked_false_for_executed_product_evidence' => true,
                    'out_of_scope_scenario_results_are_ignored' => true,
                    'uses_pypi_artifact_client_and_worker_update_surface' => true,
                    'must_record_package_version_artifact_source_handler_request_and_cells' => true,
                    'must_reject_local_product_source_checkout_and_local_artifact_sources' => true,
                ],
                'operator_diagnostics_sidecar' => [
                    'status' => 'implemented',
                    'runner_scope' => 'published_cli_release_and_packagist_waterline_artifact',
                    'evidence_file' => 'workflow-updates-operator-diagnostics-evidence.json',
                    'covers_required_scenarios' => [
                        'operator_diagnostics_surfaces',
                    ],
                    'records_runner_blocked_false_for_executed_product_evidence' => true,
                    'out_of_scope_scenario_results_are_ignored' => true,
                    'uses_official_cli_workflow_update_json_surface' => true,
                    'uses_packagist_waterline_selected_run_update_history_surface' => true,
                    'must_record_request_ids_state_outcome_reason_payload_result_error_and_history_references' => true,
                    'must_reject_local_product_source_checkout_and_local_artifact_sources' => true,
                ],
                'typed_coverage_gaps' => new \stdClass,
                'required_execution_scopes' => [
                    'published-artifact-workflow-updates',
                    'declared-update-contract-visibility',
                    'accepted-update-control-plane-and-history',
                    'running-or-waiting-update-operator-visibility',
                    'completed-update-result-round-trip',
                    'failed-update-outcome',
                    'duplicate-request-idempotency',
                    'unknown-update-refusal',
                    'invalid-input-refusal',
                    'payload-envelope-round-trip',
                    'terminal-workflow-update-behavior',
                    'principal-attribution-with-auth',
                    'update-validator-approval-boundary',
                    'update-validator-rejection-boundary',
                    'update-validator-worker-replacement',
                    'duplicate-validation-completion',
                    'unsupported-validation-capability',
                    'php-sdk-update-surface',
                    'python-sdk-update-surface',
                    'cli-api-history-waterline-operator-diagnostics',
                ],
            ],
            'result_gate' => WorkflowUpdateRuntimeResultGate::spec(),
            'finding_policy' => [
                'runner_gap' => 'link_root_cause_finding_against_conformance_harness',
                'missing_run_record_field' => 'link_root_cause_finding_against_conformance_harness',
                'local_product_source_checkout_used' => 'link_root_cause_finding_against_conformance_harness',
                'missing_artifact_source' => 'link_root_cause_finding_against_conformance_harness',
                'missing_focused_finding_for_non_pass_cell' => 'link_root_cause_finding_against_conformance_harness',
                'unsupported_sdk_surface' => 'link_focused_sdk_finding',
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
        return array_keys(self::allScenarioRequirements());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function allScenarioRequirements(): array
    {
        $ordered = [];

        foreach (self::scenarioRequirements() as $name => $requirements) {
            if ($name === 'php_client_worker_update_surface') {
                $ordered = array_merge($ordered, self::validationScenarioRequirements());
            }

            $ordered[$name] = $requirements;
        }

        return $ordered;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function validationScenarioRequirements(): array
    {
        return [
            'update_validator_approval_boundary' => [
                'evidence' => [
                    'declared_update_validators',
                    'validation_task',
                    'accepted_state_absent_before_approval',
                    'handler_not_invoked_during_validation',
                    'approval_response',
                    'accepted_response',
                    'history_update_accepted_event',
                ],
            ],
            'update_validator_rejection_boundary' => [
                'evidence' => [
                    'validation_task',
                    'validator_rejection',
                    'typed_update_response',
                    'accepted_history_event_count',
                    'rejected_history_event',
                    'handler_not_invoked',
                ],
            ],
            'update_validator_worker_replacement' => [
                'evidence' => [
                    'first_delivery',
                    'replacement_delivery',
                    'replacement_attempt',
                    'accepted_response',
                    'stale_completion_response',
                ],
            ],
            'duplicate_validation_completion' => [
                'evidence' => [
                    'validation_task_id',
                    'terminal_outcome',
                    'duplicate_completion_response',
                    'update_history_event_count',
                ],
            ],
            'unsupported_validation_capability' => [
                'evidence' => [
                    'server_capability_discovery',
                    'worker_capabilities',
                    'typed_update_response',
                    'accepted_history_event_count',
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
                'evidence' => [
                    'published_artifact_versions',
                    'artifact_sources',
                    'artifact_install_evidence',
                    'local_product_source_checkouts_used',
                    'source_policy',
                ],
            ],
            'declared_update_contract_visibility' => [
                'evidence' => [
                    'workflow_type',
                    'declared_updates',
                    'declared_update_contracts',
                    'start_response',
                    'history_start_event',
                ],
            ],
            'accepted_update_control_plane_and_history' => [
                'evidence' => [
                    'update_request',
                    'update_response',
                    'update_id',
                    'update_status',
                    'history_update_accepted_event',
                    'run_detail_update_view',
                ],
                'required_statuses' => [
                    'accepted',
                ],
            ],
            'running_or_waiting_update_operator_visibility' => [
                'evidence' => [
                    'update_id',
                    'workflow_status',
                    'update_status',
                    'waiting_or_running_surface',
                    'waterline_update_view',
                ],
                'accepted_statuses' => [
                    'running',
                    'waiting',
                    'accepted',
                ],
            ],
            'completed_update_result_round_trip' => [
                'evidence' => [
                    'update_id',
                    'request_payload',
                    'result_payload',
                    'result_envelope',
                    'history_update_completed_event',
                    'cli_update_json',
                    'sdk_update_result',
                ],
                'required_statuses' => [
                    'completed',
                ],
            ],
            'failed_update_outcome' => [
                'evidence' => [
                    'update_id',
                    'failure_type',
                    'failure_message',
                    'history_update_completed_or_failed_event',
                    'control_plane_error_envelope',
                    'operator_failure_view',
                ],
                'accepted_statuses' => [
                    'failed',
                    'rejected',
                ],
            ],
            'duplicate_request_idempotency' => [
                'evidence' => [
                    'idempotency_key_or_update_id',
                    'first_response',
                    'duplicate_response',
                    'history_event_count',
                    'handler_observation_count',
                    'documented_contract',
                ],
            ],
            'unknown_update_refusal' => [
                'evidence' => [
                    'unknown_update_name',
                    'error_type',
                    'http_status_or_sdk_error',
                    'history_absence_or_rejection_event',
                    'operator_visible_refusal',
                ],
                'required_error_types' => [
                    'missing_workflow_update',
                    'unknown_update',
                    'update_not_registered',
                ],
            ],
            'invalid_input_refusal' => [
                'evidence' => [
                    'invalid_payload',
                    'error_type',
                    'validation_errors',
                    'handler_not_invoked',
                    'history_absence_or_rejection_event',
                    'operator_visible_refusal',
                ],
                'required_error_types' => [
                    'invalid_update_arguments',
                    'payload_decode_failed',
                    'validation_failed',
                ],
            ],
            'payload_envelope_round_trip' => [
                'evidence' => [
                    'codec',
                    'request_envelope',
                    'history_arguments_envelope',
                    'history_result_envelope',
                    'control_plane_result_envelope',
                    'sdk_decoded_result',
                ],
            ],
            'terminal_workflow_update_behavior' => [
                'evidence' => [
                    'terminal_workflow_status',
                    'update_request',
                    'error_type',
                    'http_status_or_sdk_error',
                    'history_absence_or_rejection_event',
                    'operator_visible_refusal',
                ],
                'terminal_states' => [
                    'completed',
                    'failed',
                    'cancelled',
                    'terminated',
                    'timed_out',
                ],
            ],
            'principal_attribution_with_auth' => [
                'evidence' => [
                    'auth_mode',
                    'principal',
                    'update_request_surface',
                    'control_plane_principal_fields',
                    'history_principal_fields',
                    'waterline_principal_fields',
                ],
                'unsupported_when' => [
                    'authentication_disabled',
                ],
            ],
            'php_client_worker_update_surface' => [
                'evidence' => [
                    'sdk_php_artifact_version',
                    'php_worker_update_handler',
                    'php_client_update_request',
                    'covered_cells',
                    'unsupported_cells',
                    'typed_errors',
                ],
            ],
            'python_client_worker_update_surface' => [
                'evidence' => [
                    'sdk_python_artifact_version',
                    'python_worker_update_handler',
                    'python_client_update_request',
                    'covered_cells',
                    'unsupported_cells',
                    'typed_errors',
                ],
            ],
            'operator_diagnostics_surfaces' => [
                'evidence' => [
                    'workflow_id',
                    'run_id',
                    'cli_fields',
                    'api_fields',
                    'history_fields',
                    'waterline_fields',
                    'diagnostic_transition_matrix',
                ],
            ],
        ];
    }
}
