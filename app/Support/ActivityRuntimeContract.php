<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for the published-artifact activity conformance
 * run.
 *
 * Activity behavior is a load-bearing Temporal-parity surface. This manifest
 * separates the activity contract from incidental coverage in workflow,
 * replay, saga, and quickstart scenarios so host runners can produce one
 * first-class row with focused root-cause routing.
 */
final class ActivityRuntimeContract
{
    public const SCHEMA = 'durable-workflow.v2.activity-runtime.contract';

    public const VERSION = 1;

    public const RESULT_SCHEMA = 'durable-workflow.v2.activity-runtime.result';

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
            'fixture_category' => 'activity_runtime_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'activity_runtime_contract',
                'suite_schema' => PlatformConformanceSuite::SCHEMA,
                'suite_version' => PlatformConformanceSuite::VERSION,
                'public_path' => 'https://durable-workflow.github.io/platform-conformance/activity-runtime-scenarios.json',
                'source_path' => 'static/platform-conformance/activity-runtime-scenarios.json',
            ],
            'artifact_policy' => [
                'version_source' => 'latest_published_artifacts_at_run_time',
                'version_requirement' => 'concrete_published_versions_pinned_at_run_time',
                'placeholder_versions_rejected' => true,
                'requires_recognized_published_artifact_sources' => true,
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
                    'server' => 'docker image durableworkflow/server:<exact published version or digest>',
                    'cli' => 'official dw release install script pinned to its latest release tag',
                    'workflow-php' => 'Composer package durable-workflow/workflow:2.0.0-alpha.<latest>',
                    'sdk-php' => 'Composer package durable-workflow/sdk:<latest>',
                    'sdk-python' => 'PyPI package durable-workflow==<latest>',
                    'waterline' => 'published Waterline observer artifact matching the release set',
                ],
                'release_artifact_aliases' => [
                    'workflow-php' => ['workflow'],
                    'sdk-php' => ['php'],
                    'sdk-python' => ['python'],
                ],
                'forbidden_sources' => [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                    'local_checkout_artifact',
                    'local_source_checkout',
                    'workspace_repo',
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
                    'findings',
                    'finding_links',
                    'topology',
                    'runtime_matrix',
                    'published_artifact_install',
                    'durable_result_recording',
                    'retry_backoff',
                    'timeout_behavior',
                    'heartbeat_timeout_renewal',
                    'typed_failure_propagation',
                    'heartbeat_cancellation',
                    'idempotent_completion',
                    'operator_visibility',
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
                'namespace' => 'activities-conformance',
                'task_queue_strategy' => 'per_scenario_isolated',
                'task_queue_identity_source' => 'scenario_execution_identity',
                'worker_identity_strategy' => 'per_scenario_or_restart_pair',
                'required_workers' => [
                    'workflow-php',
                    'sdk-php',
                    'sdk-python',
                ],
                'execution_modes' => [
                    'workflow-embedded',
                    'standalone',
                ],
                'required_public_surfaces' => [
                    'POST /api/activities',
                    'GET /api/activities',
                    'GET /api/activities/{activityId}',
                    'POST /api/worker/activity-tasks/poll',
                    'POST /api/worker/activity-tasks/{taskId}/complete',
                    'POST /api/worker/activity-tasks/{taskId}/fail',
                    'POST /api/worker/activity-tasks/{taskId}/heartbeat',
                    'GET /api/workflows/{workflowId}/runs/{runId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}/history',
                    'GET /api/system/activity-timeouts',
                    'POST /api/system/activity-timeouts/pass',
                    'Waterline selected run activity attempt view',
                ],
            ],
            'required_matrix' => [
                'execution_modes' => [
                    'workflow-embedded',
                    'standalone',
                ],
                'runtimes' => [
                    'workflow-php',
                    'sdk-php',
                    'sdk-python',
                ],
                'activity_cells' => [
                    [
                        'mode' => 'workflow-embedded',
                        'runtime' => 'workflow-php',
                        'scenario' => 'workflow_embedded_activity_result',
                    ],
                    [
                        'mode' => 'workflow-embedded',
                        'runtime' => 'sdk-python',
                        'scenario' => 'workflow_embedded_activity_result',
                    ],
                    [
                        'mode' => 'standalone',
                        'runtime' => 'workflow-php',
                        'scenario' => 'standalone_activity_result',
                    ],
                    [
                        'mode' => 'standalone',
                        'runtime' => 'sdk-python',
                        'scenario' => 'standalone_activity_result',
                    ],
                ],
                'behavior_cells' => [
                    'durable_result_recording_after_worker_restart',
                    'retry_attempt_backoff_behavior',
                    'timeout_behavior',
                    'typed_failure_propagation',
                    'heartbeat_and_cancellation_observation',
                    'heartbeat_timeout_renewal_across_enforcement_passes',
                    'idempotent_completion_handling',
                    'php_python_activity_parity',
                    'operator_visible_activity_attempt_state',
                ],
            ],
            'required_scenarios' => [
                'published_artifact_install_only',
                'workflow_embedded_activity_result',
                'standalone_activity_result',
                'durable_result_recording_after_worker_restart',
                'retry_attempt_backoff_behavior',
                'timeout_behavior',
                'typed_failure_propagation',
                'heartbeat_and_cancellation_observation',
                'heartbeat_timeout_renewal_across_enforcement_passes',
                'idempotent_completion_handling',
                'php_python_activity_parity',
                'operator_visible_activity_attempt_state',
            ],
            'scenario_requirements' => self::scenarioRequirements(),
            'coverage_gate' => [
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'workflow_embedded_and_standalone_modes_reported',
                    'php_and_python_activity_runtimes_reported',
                    'durable_result_recorded_after_worker_restart',
                    'retry_attempt_count_and_backoff_reported',
                    'start_to_close_or_schedule_to_close_timeout_reported',
                    'published_php_sdk_heartbeat_timeout_renewal_reported',
                    'typed_failure_payload_reported',
                    'heartbeat_and_cancellation_observation_reported',
                    'idempotent_completion_result_reported',
                    'operator_visible_activity_attempt_state_reported',
                    'published_artifact_versions_are_recorded_and_pinned',
                    'artifact_source_recorded_for_each_install_channel',
                    'run_timestamps_outcome_and_findings_are_recorded',
                    'declared_outcome_matches_evaluated_status',
                    'no_local_product_source_artifacts',
                    'runner_blocked_false_for_product_evidence',
                    'findings_linked_for_non_pass_scenarios',
                    'non_pass_cells_classified_by_root_cause',
                ],
                'uncovered_required_scenario_outcome' => 'non_passing',
                'smoke_subset_outcome' => 'non_passing',
                'unsupported_public_surface_outcome' => 'non_passing_with_root_cause_finding',
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
                'focused_findings_required_for_uncovered_cells' => true,
                'allowed_non_pass_classifications' => [
                    'product-gap',
                    'coverage-gap',
                    'runner-gap',
                    'stale-artifact',
                    'pipeline-churn',
                ],
            ],
            'host_runner_contract' => [
                'status' => 'required_for_passing_activities_conformance',
                'result_schema' => self::RESULT_SCHEMA,
                'runner_repository' => 'platform_conformance_host',
                'runner_id' => 'activities',
                'runner_path' => 'scripts/conformance/activities-published-artifacts.sh',
                'runner_command' => 'scripts/conformance/activities-published-artifacts.sh --result-dir <result-dir>',
                'portable_result_contract' => [
                    'schema' => 'durable-workflow.v1.portable-native-evidence',
                    'runner_max_bytes' => 4 * 1024 * 1024,
                    'projection_target_bytes' => 3 * 1024 * 1024,
                    'host_consumer_max_bytes' => 4 * 1024 * 1024,
                    'required_top_level_fields' => [
                        'schema',
                        'started_at',
                        'finished_at',
                        'outcome',
                        'runner_blocked',
                        'artifact_versions',
                        'published_artifact_versions',
                        'executed_distribution_identities',
                        'runtime_matrix',
                        'scenario_results',
                        'findings',
                        'finding_links',
                    ],
                    'scenario_evidence_source' => 'scenario_requirements.*.required_fields',
                    'required_scenario_status_source' => 'required_scenarios',
                    'exact_distribution_identity_field' => 'executed_distribution_identities',
                    'native_evidence_failure_classification' => [
                        'oversized' => 'runner_infrastructure_failure',
                        'malformed' => 'runner_infrastructure_failure',
                        'incomplete' => 'runner_infrastructure_failure',
                    ],
                    'product_assertions' => 'fail_closed_before_projection',
                    'sensitive_values' => 'omitted',
                    'unbounded_payloads' => 'sha256_summary_without_payload_bytes',
                ],
                'result_files' => [
                    'activities-result.json',
                    'activities-record.json',
                    'activities-findings.json',
                    'run-metadata.json',
                    'pins.json',
                ],
                'must_execute_against_published_artifacts' => true,
                'must_record_runner_blocked_false_for_product_evidence' => true,
                'must_prove_activity_runtime_executes_pinned_server_artifact' => true,
                'published_server_execution_evidence_fields' => [
                    'published_artifact_worker_execution',
                    'published_server_artifact_execution',
                    'published_artifact_execution',
                ],
                'must_emit_result_for_every_required_scenario' => true,
                'smoke_summary_only_outcome' => 'non_passing',
                'unexecuted_required_scenario_status' => 'not_covered',
                'coverage_gap_finding_type' => 'conformance_runner_coverage_gap',
                'coverage_gap_owner' => 'conformance_harness',
                'required_execution_scopes' => [
                    'published-artifact-install',
                    'published-server-artifact-execution',
                    'workflow-embedded-activity-shard',
                    'standalone-activity-shard',
                    'worker-restart-durability-shard',
                    'retry-backoff-shard',
                    'timeout-shard',
                    'php-sdk-heartbeat-timeout-renewal-shard',
                    'typed-failure-shard',
                    'heartbeat-cancellation-shard',
                    'idempotent-completion-shard',
                    'php-python-parity-shard',
                    'operator-visibility-shard',
                ],
                'runtime_shards' => [
                    'workflow-php' => [
                        'artifact' => 'durable-workflow/workflow',
                        'must_cover_scenarios' => [
                            'workflow_embedded_activity_result',
                            'standalone_activity_result',
                            'php_python_activity_parity',
                        ],
                    ],
                    'sdk-python' => [
                        'artifact' => 'durable-workflow',
                        'must_cover_scenarios' => [
                            'workflow_embedded_activity_result',
                            'standalone_activity_result',
                            'durable_result_recording_after_worker_restart',
                            'php_python_activity_parity',
                        ],
                    ],
                    'sdk-php' => [
                        'artifact' => 'durable-workflow/sdk',
                        'must_cover_scenarios' => [
                            'heartbeat_timeout_renewal_across_enforcement_passes',
                        ],
                    ],
                    'waterline' => [
                        'artifact' => 'durable-workflow/waterline',
                        'must_cover_scenarios' => [
                            'operator_visible_activity_attempt_state',
                        ],
                        'must_capture_fields' => [
                            'activity_id',
                            'activity_execution_id',
                            'activity_type',
                            'attempt',
                            'status',
                            'last_heartbeat_at',
                            'retry_state',
                            'timeout_deadlines',
                        ],
                    ],
                ],
                'routing_policy' => [
                    'missing_required_scenario' => [
                        'scenario_status' => 'not_covered',
                        'finding_type' => 'conformance_runner_coverage_gap',
                        'classification' => 'coverage-gap',
                        'owner' => 'conformance_harness',
                    ],
                    'host_environment_failure' => [
                        'scenario_status' => 'runner_blocked',
                        'finding_type' => 'runner_gap',
                        'classification' => 'runner-gap',
                        'owner' => 'conformance_harness',
                    ],
                    'stale_artifact_tuple' => [
                        'scenario_status' => 'fail',
                        'finding_type' => 'stale_artifact',
                        'classification' => 'stale-artifact',
                        'owner' => 'release_harness',
                    ],
                    'pipeline_churn' => [
                        'scenario_status' => 'fail',
                        'finding_type' => 'pipeline_churn',
                        'classification' => 'pipeline-churn',
                        'owner' => 'release_harness',
                    ],
                    'product_behavior_failure' => [
                        'scenario_status' => 'fail',
                        'finding_source' => 'activity_runtime_contract.finding_policy',
                        'classification' => 'product-gap',
                    ],
                ],
            ],
            'result_gate' => ActivityRuntimeResultGate::spec(),
            'finding_policy' => [
                'workflow_embedded_activity_missing' => 'link_root_cause_finding_against_server_or_worker_runtime',
                'standalone_activity_missing' => 'link_root_cause_finding_against_server',
                'durable_result_not_replayed_after_restart' => 'link_root_cause_finding_against_replay_owner',
                'retry_attempt_or_backoff_mismatch' => 'link_root_cause_finding_against_server',
                'timeout_mismatch' => 'link_root_cause_finding_against_server_or_worker_runtime',
                'heartbeat_timeout_renewal_mismatch' => 'link_root_cause_finding_against_server_or_php_sdk',
                'failure_type_erased' => 'link_root_cause_finding_against_codec_or_dispatch_owner',
                'heartbeat_or_cancellation_gap' => 'link_root_cause_finding_against_worker_runtime_or_server',
                'completion_idempotency_gap' => 'link_root_cause_finding_against_server',
                'operator_visibility_gap' => 'link_root_cause_finding_against_waterline_or_server',
                'unsupported_public_surface' => 'link_root_cause_finding_against_surface_owner',
                'conformance_runner_coverage_gap' => 'link_root_cause_finding_against_conformance_harness',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function scenarioRequirements(): array
    {
        return [
            'published_artifact_install_only' => [
                'required_behavior' => 'all_artifacts_resolved_from_published_channels',
                'required_fields' => [
                    'server_image',
                    'cli_release',
                    'workflow_php_package',
                    'sdk_php_package',
                    'sdk_python_package',
                    'waterline_artifact',
                ],
            ],
            'workflow_embedded_activity_result' => [
                'required_modes' => ['workflow-embedded'],
                'required_runtimes' => ['workflow-php', 'sdk-python'],
                'required_fields' => [
                    'workflow_id',
                    'run_id',
                    'activity_execution_id',
                    'activity_type',
                    'result_payload',
                    'history_events',
                ],
                'expected_behavior' => 'a workflow-scheduled activity completes through the worker protocol and the workflow observes the exact typed result',
            ],
            'standalone_activity_result' => [
                'required_modes' => ['standalone'],
                'required_runtimes' => ['workflow-php', 'sdk-python'],
                'required_fields' => [
                    'activity_id',
                    'activity_execution_id',
                    'workflow_run_id',
                    'activity_type',
                    'result_payload',
                    'handle_response',
                ],
                'expected_behavior' => 'a top-level activity started through POST /api/activities closes its host run with the activity result',
            ],
            'durable_result_recording_after_worker_restart' => [
                'required_fields' => [
                    'first_worker_identity',
                    'restart_worker_identity',
                    'activity_execution_id',
                    'result_recorded_before_restart',
                    'result_observed_after_restart',
                    'duplicate_activity_count',
                ],
                'expected_behavior' => 'activity result recording survives worker restart and replay does not duplicate completion',
            ],
            'retry_attempt_backoff_behavior' => [
                'required_fields' => [
                    'attempts',
                    'failure_payloads',
                    'retry_policy',
                    'scheduled_backoff_seconds',
                    'observed_redelivery_timestamps',
                    'terminal_result',
                ],
                'expected_behavior' => 'failed attempts increment attempt state, respect configured backoff, and complete or fail according to retry policy',
            ],
            'timeout_behavior' => [
                'required_fields' => [
                    'timeout_type',
                    'deadline_at',
                    'enforcement_observed_at',
                    'activity_status',
                    'history_events',
                ],
                'allowed_timeout_types' => [
                    'start_to_close',
                    'schedule_to_close',
                ],
                'expected_behavior' => 'start-to-close or schedule-to-close deadline is visible to the worker and enforced as a typed timeout',
            ],
            'typed_failure_propagation' => [
                'required_fields' => [
                    'failure_type',
                    'failure_message',
                    'failure_details',
                    'history_exception',
                    'caller_observed_failure',
                ],
                'expected_behavior' => 'activity failures preserve type, message, and details through history and the caller runtime',
            ],
            'heartbeat_and_cancellation_observation' => [
                'required_fields' => [
                    'heartbeat_details',
                    'heartbeat_history_event',
                    'cancel_requested_response',
                    'worker_observed_cancellation',
                ],
                'expected_behavior' => 'activity heartbeat details are recorded and cancellation is observable by a running worker',
            ],
            'heartbeat_timeout_renewal_across_enforcement_passes' => [
                'required_runtimes' => ['sdk-php'],
                'required_fields' => [
                    'php_sdk_worker_artifact',
                    'heartbeat_timeout_seconds',
                    'heartbeat_cadence_seconds',
                    'initial_heartbeat_deadline_at',
                    'heartbeat_acknowledgements',
                    'enforcement_passes',
                    'in_flight_duration_seconds',
                    'completion_response',
                    'terminal_history',
                    'negative_control',
                    'isolated_cleanup',
                ],
                'expected_behavior' => 'an exact published PHP SDK worker renews the authoritative heartbeat deadline across repeated real timeout-enforcement passes, completes once without contradictory timeout history, and a no-heartbeat control retains typed timeout and deterministic stale-attempt responses',
            ],
            'idempotent_completion_handling' => [
                'required_fields' => [
                    'first_completion_response',
                    'duplicate_completion_response',
                    'activity_attempt_id',
                    'recorded_once',
                    'stale_attempt_or_idempotent_verdict',
                ],
                'expected_behavior' => 'duplicate completion attempts do not create duplicate terminal records and return a deterministic worker-protocol response',
            ],
            'php_python_activity_parity' => [
                'required_fields' => [
                    'php_activity_result',
                    'python_activity_result',
                    'cross_language_payload_shape',
                    'runtime_matrix',
                ],
                'expected_behavior' => 'PHP and Python activity workers produce compatible payload, failure, retry, timeout, and heartbeat observations where both runtimes support the surface',
            ],
            'operator_visible_activity_attempt_state' => [
                'required_fields' => [
                    'api_run_detail',
                    'history_activity_attempts',
                    'operator_metrics',
                    'waterline_activity_attempt_view',
                    'cli_json_list_evidence',
                ],
                'expected_behavior' => 'operators can see current and historical activity attempt state through API metrics, Waterline, and CLI JSON list/detail output',
            ],
        ];
    }
}
