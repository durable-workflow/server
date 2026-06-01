<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for published-artifact principal attribution.
 *
 * The contract gives host conformance runners a source-free handoff and a
 * stable evidence shape for proving that workflow history records the
 * server-authenticated actor rather than caller-supplied identity claims.
 */
final class PrincipalAttributionContract
{
    public const SCHEMA = 'durable-workflow.v2.principal-attribution.contract';

    public const VERSION = 1;

    public const RESULT_SCHEMA = 'durable-workflow.v2.principal-attribution-conformance.result';

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
            'fixture_category' => 'principal_attribution_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'principal_attribution_contract',
                'suite_schema' => PlatformConformanceSuite::SCHEMA,
                'suite_version' => PlatformConformanceSuite::VERSION,
                'public_path' => 'https://durable-workflow.github.io/platform-conformance/principal-attribution-scenarios.json',
                'source_path' => 'static/platform-conformance/principal-attribution-scenarios.json',
            ],
            'principal_shape' => [
                'event_field' => 'principal',
                'required_fields' => ['type', 'id'],
                'optional_fields' => ['label'],
                'server_source' => 'App\\Http\\Middleware\\Authenticate request principal',
                'anonymous_value' => [
                    'type' => 'server',
                    'id' => 'anonymous',
                ],
                'system_or_internal_events' => 'documented system principal or explicit null for non-caller-generated events',
            ],
            'worker_terminal_event_policy' => [
                'events' => ['WorkflowCompleted', 'WorkflowFailed'],
                'expected_authenticated_worker_principal' => [
                    'type' => 'auth:token',
                    'id' => 'worker:principal-attribution',
                ],
                'documented_system_principals' => [],
                'pass_condition' => 'each worker-caused terminal event principal matches the authenticated worker principal unless a system principal is listed here',
            ],
            'artifact_policy' => [
                'version_source' => 'latest_complete_published_artifact_set_at_run_time',
                'version_requirement' => 'concrete_published_versions_with_downloadable_install_assets_pinned_at_run_time',
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
                    'caller_supplied_principal_as_authority',
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
                    'actor_matrix',
                    'history_dumps',
                    'spoofing_attempts',
                    'operator_visibility',
                    'anonymous_observations',
                ],
            ],
            'scenario_statuses' => [
                'pass',
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'required_scenarios' => [
                'published_artifact_install_only',
                'named_token_actor_matrix',
                'start_signal_cancel_spoofing',
                'query_attribution',
                'completion_failure_attribution',
                'server_originated_events',
                'anonymous_attribution',
                'python_sdk_visibility',
                'php_client_visibility',
                'cli_operator_visibility',
                'waterline_operator_visibility',
            ],
            'scenario_requirements' => self::scenarioRequirements(),
            'spoofing_guards' => [
                'request_body_fields' => ['principal', 'principal_id', 'principal_type', 'actor', 'user'],
                'request_headers' => [
                    'X-Workflow-Principal-Id',
                    'X-Workflow-Principal-Type',
                    'X-Workflow-Principal-Label',
                    'X-Workflow-Caller-Type',
                    'X-Workflow-Caller-Label',
                    'X-Workflow-Auth-Status',
                    'X-Workflow-Auth-Method',
                    'X-Forwarded-User',
                    'X-Forwarded-Email',
                    'X-Remote-User',
                    'Authorization-Override',
                ],
                'expected_behavior' => 'recorded principal is derived only from authenticated server Principal',
            ],
            'coverage_gate' => [
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'all_required_artifacts_resolved_from_published_channels',
                    'server_image_is_exact_patch_or_digest_pinned_with_version',
                    'actor_matrix_distinguishes_alice_bob_and_rotated_alice_credentials',
                    'start_signal_query_cancel_completion_failure_principals_reported',
                    'spoofed_payload_and_header_principals_do_not_land_in_history',
                    'anonymous_behavior_is_explicit',
                    'server_originated_events_are_explicitly_classified',
                    'cli_python_php_and_waterline_visibility_reported_or_linked_as_non_pass_findings',
                    'run_timestamps_outcome_and_findings_are_recorded',
                    'artifact_source_recorded_for_each_install_channel',
                    'local_product_source_checkouts_used_explicitly_false',
                    'runner_blocked_false_for_product_evidence',
                    'findings_linked_for_non_pass_scenarios',
                ],
                'uncovered_required_scenario_outcome' => 'non_passing',
                'unsupported_public_surface_outcome' => 'non_passing_with_root_cause_finding',
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
            ],
            'result_gate' => PrincipalAttributionResultGate::spec(),
            'host_runner_contract' => [
                'status' => 'required_for_passing_principal_attribution_conformance',
                'runner_repository' => 'server',
                'runner_path' => 'scripts/conformance/principal-attribution-published-artifacts.sh',
                'runner_command' => 'scripts/conformance/principal-attribution-published-artifacts.sh --result-dir <result-dir>',
                'result_schema' => self::RESULT_SCHEMA,
                'result_files' => [
                    'pins.json',
                    'run-metadata.json',
                    'artifact-install-evidence.json',
                    'principal-attribution-result.json',
                    'principal-attribution-record.json',
                ],
                'must_execute_against_published_artifacts' => true,
                'must_record_runner_blocked_false_for_product_evidence' => true,
                'must_attempt_spoofing_payloads_and_headers' => true,
                'required_execution_scopes' => [
                    'published-artifact-install',
                    'named-token-actor-matrix',
                    'start-signal-query-cancel-history',
                    'completion-failure-history',
                    'anonymous-history',
                    'spoofing-payload-header',
                    'cli-history-operator-output',
                    'python-sdk-client',
                    'php-client',
                    'waterline-operator-visibility',
                ],
                'routing_policy' => [
                    'spoofing_success' => [
                        'scenario_status' => 'fail',
                        'finding_type' => 'security_blocker',
                        'owner' => 'server',
                    ],
                    'missing_principal_field' => [
                        'scenario_status' => 'fail',
                        'finding_type' => 'audit_contract_gap',
                        'owner' => 'server',
                    ],
                    'missing_operator_visibility' => [
                        'scenario_status' => 'unsupported',
                        'finding_type' => 'operator_visibility_gap',
                        'owner' => 'cli_or_waterline',
                    ],
                    'host_environment_failure' => [
                        'scenario_status' => 'runner_blocked',
                        'finding_type' => 'runner_gap',
                        'owner' => 'conformance_harness',
                    ],
                ],
            ],
            'finding_policy' => [
                'root_cause_owners' => [
                    'spoofed_principal_lands_in_history' => 'server',
                    'principal_field_absent' => 'server',
                    'query_principal_absent' => 'server',
                    'worker_completion_principal_absent' => 'server_or_worker_protocol',
                    'anonymous_principal_undefined' => 'server',
                    'cli_principal_hidden' => 'cli',
                    'php_python_shape_drift' => 'workflow_or_sdk-python',
                    'waterline_principal_hidden' => 'waterline',
                    'runner_coverage_gap' => 'conformance_harness',
                ],
                'required_for_non_pass' => [
                    'scenario_id',
                    'owning_surface',
                    'artifact_versions',
                    'observed_behavior',
                    'expected_behavior',
                    'next_acceptance_criterion',
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
                'required_fields' => ['resolved_artifact_versions', 'artifact_sources', 'local_product_source_checkouts_used'],
            ],
            'named_token_actor_matrix' => [
                'required_fields' => ['actors', 'credentials', 'rotation_observations'],
            ],
            'start_signal_cancel_spoofing' => [
                'required_fields' => ['history_events', 'recorded_principals', 'spoofing_attempts'],
            ],
            'query_attribution' => [
                'required_fields' => ['query_result', 'recorded_principal', 'history_or_query_task_surface'],
            ],
            'completion_failure_attribution' => [
                'required_fields' => [
                    'completion_event_principal',
                    'failure_event_principal',
                    'worker_principal',
                    'expected_worker_principal',
                    'documented_system_principals',
                ],
            ],
            'server_originated_events' => [
                'required_fields' => ['event_types', 'principal_values', 'classification'],
            ],
            'anonymous_attribution' => [
                'required_fields' => ['anonymous_principal', 'documented_value', 'history_events'],
            ],
            'python_sdk_visibility' => [
                'required_fields' => ['client_operation', 'recorded_principal', 'shape_matches_http'],
            ],
            'php_client_visibility' => [
                'required_fields' => ['client_operation', 'recorded_principal', 'shape_matches_http'],
            ],
            'cli_operator_visibility' => [
                'required_fields' => ['command', 'output_sample', 'principal_visible'],
            ],
            'waterline_operator_visibility' => [
                'required_fields' => ['surface', 'output_sample', 'principal_visible'],
            ],
        ];
    }
}
