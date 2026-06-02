<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for published-artifact worker-versioning
 * conformance.
 *
 * Worker registration and rollout visibility are useful smoke coverage, but
 * the safe-deploy contract only passes when workflow history is pinned to the
 * compatible worker build across promotion, eviction, restart, and mixed
 * language polling.
 */
final class WorkerVersioningRuntimeContract
{
    public const SCHEMA = 'durable-workflow.v2.worker-versioning-runtime.contract';

    public const VERSION = 1;

    public const RESULT_SCHEMA = 'durable-workflow.v2.worker-versioning-runtime.result';

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
            'fixture_category' => 'worker_versioning_runtime_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'worker_versioning_runtime_contract',
                'suite_schema' => PlatformConformanceSuite::SCHEMA,
                'suite_version' => PlatformConformanceSuite::VERSION,
                'source_path' => 'static/platform-conformance/worker-versioning-runtime-scenarios.json',
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
                    'topology',
                    'runtime_matrix',
                    'versioning_observations',
                    'history_version_pins',
                    'operator_controls',
                    'mixed_version_polling',
                    'no_compatible_worker',
                    'cross_language_matrix',
                    'adversarial_outcomes',
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
                'namespace' => 'worker-versioning-conformance',
                'task_queue' => 'worker-versioning-shared',
                'required_workers' => [
                    'workflow-php-v1',
                    'workflow-php-v2',
                    'sdk-python-v1-or-v2',
                ],
                'required_clients' => [
                    'cli',
                    'sdk-python',
                    'workflow-php-sdk',
                    'waterline',
                ],
                'workflow_shape' => [
                    'workflow_type' => 'Sequence',
                    'v1_order' => ['activity_a', 'activity_b'],
                    'v2_order' => ['activity_b', 'activity_a'],
                    'divergence_required' => true,
                ],
                'deadlines' => [
                    'promotion_visibility_seconds' => 30,
                    'no_compatible_worker_visibility_seconds' => 60,
                    'cache_eviction_replay_seconds' => 120,
                ],
            ],
            'required_matrix' => [
                'runtimes' => [
                    'workflow-php',
                    'sdk-python',
                ],
                'client_paths' => [
                    'cli',
                    'sdk-python',
                    'workflow-php-sdk',
                ],
                'operator_visibility_paths' => [
                    'dw workers list',
                    'dw task-queue build-ids',
                    'workflow show compatibility',
                    'history API compatibility',
                    'Waterline worker and workflow views',
                ],
                'worker_cohorts' => [
                    'v1',
                    'v2',
                    'draining-v1',
                    'promoted-v2',
                    'no-compatible-worker',
                ],
                'cross_language_cells' => [
                    [
                        'started_by' => 'workflow-php-v1',
                        'incompatible_worker' => 'sdk-python-v2',
                        'scenario' => 'php_v1_not_delivered_to_python_v2',
                    ],
                    [
                        'started_by' => 'sdk-python-v1',
                        'incompatible_worker' => 'workflow-php-v2',
                        'scenario' => 'python_v1_not_delivered_to_php_v2',
                    ],
                ],
            ],
            'required_scenarios' => [
                'published_artifact_install_only',
                'worker_registration_build_ids',
                'operator_rollout_visibility',
                'drain_resume_operator_controls',
                'pin_on_start',
                'replay_only_by_compatible_workers',
                'new_starts_to_promoted_version',
                'replay_across_cache_eviction',
                'no_compatible_worker_behavior',
                'operator_visibility_surfaces',
                'cross_language_php_python_pinning',
                'adversarial_no_version_bump',
                'history_api_version_pin',
            ],
            'scenario_requirements' => [
                'published_artifact_install_only' => [
                    'required_fields' => [
                        'resolved_artifact_versions',
                        'artifact_sources',
                        'local_product_source_checkouts_used',
                    ],
                    'expected_behavior' => 'every actor under test is installed from a resolved published artifact and no local product checkout is used as an artifact under test',
                ],
                'worker_registration_build_ids' => [
                    'required_fields' => [
                        'registered_build_ids',
                        'worker_registration_responses',
                        'worker_list_build_ids',
                        'task_queue_build_ids',
                        'active_worker_counts_per_cohort',
                    ],
                    'expected_behavior' => 'multiple live worker cohorts on the shared task queue retain distinct requested build ids and expose cohort counts through public worker and task-queue surfaces',
                ],
                'operator_rollout_visibility' => [
                    'required_fields' => [
                        'worker_cohorts',
                        'rollout_state',
                        'new_start_build_id',
                        'workflow_run_compatibility',
                        'waterline_operator_visibility',
                    ],
                    'expected_behavior' => 'operators can distinguish cohorts, see the build id selected for new starts, and inspect existing run compatibility through CLI and Waterline surfaces',
                ],
                'drain_resume_operator_controls' => [
                    'required_fields' => [
                        'drain_command',
                        'drain_state_visible',
                        'resume_command',
                        'resume_state_visible',
                        'draining_worker_claim_count',
                    ],
                    'expected_behavior' => 'documented drain and resume controls update operator-visible rollout state and draining workers do not silently claim new work',
                ],
                'pin_on_start' => [
                    'required_fields' => [
                        'run_compatibility',
                        'first_task_compatibility',
                        'history_or_visibility_field',
                    ],
                    'expected_behavior' => 'new workflow run and first workflow task are stamped with the selected build id before any worker task is delivered',
                ],
                'replay_only_by_compatible_workers' => [
                    'required_fields' => [
                        'v1_worker_task_count',
                        'v2_worker_task_count_for_v1_run',
                        'workflow_result',
                    ],
                    'expected_behavior' => 'v1-pinned history is never delivered to a v2 worker while both cohorts poll the same task queue',
                ],
                'new_starts_to_promoted_version' => [
                    'required_fields' => [
                        'promotion_command',
                        'new_run_compatibility',
                        'old_run_continues_on',
                    ],
                    'expected_behavior' => 'operator promotion routes fresh starts to v2 while older v1 runs stay v1-pinned',
                ],
                'replay_across_cache_eviction' => [
                    'required_fields' => [
                        'cache_eviction_observed',
                        'replay_worker_build_id',
                        'incompatible_delivery_count',
                    ],
                    'expected_behavior' => 'cache eviction or worker restart replays v1 history only on a v1-compatible worker',
                ],
                'no_compatible_worker_behavior' => [
                    'required_fields' => [
                        'operator_visible_signal',
                        'pending_or_typed_error',
                        'incompatible_worker_task_count',
                    ],
                    'expected_behavior' => 'a pinned run without compatible workers is explicit to operators and is not picked up by an incompatible worker',
                ],
                'operator_visibility_surfaces' => [
                    'required_fields' => [
                        'worker_list',
                        'task_queue_build_ids',
                        'workflow_visibility',
                        'waterline_operator_visibility',
                    ],
                    'expected_behavior' => 'operators can see worker cohorts, rollout state, and per-run compatibility without reading storage internals',
                ],
                'cross_language_php_python_pinning' => [
                    'required_fields' => [
                        'php_worker_build_id',
                        'python_worker_build_id',
                        'php_v1_to_python_v2_incompatible_delivery_count',
                        'python_v1_to_php_v2_incompatible_delivery_count',
                        'cross_language_delivery',
                    ],
                    'expected_behavior' => 'PHP and Python workers honor the same build-id pinning contract on a shared queue and both directional incompatible-delivery counts are zero',
                ],
                'adversarial_no_version_bump' => [
                    'required_fields' => [
                        'observed_behavior',
                        'operator_audit_signal',
                    ],
                    'expected_behavior' => 'shipping divergent workflow code under the same build id is captured as an auditable behavior or linked product gap',
                ],
                'history_api_version_pin' => [
                    'required_fields' => [
                        'history_field',
                        'compatibility_value',
                    ],
                    'expected_behavior' => 'history or replay-export surfaces include a durable worker-version pin for the run',
                ],
            ],
            'coverage_gate' => [
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'pin_on_start_and_history_pin_reported',
                    'compatible_replay_and_cache_eviction_reported',
                    'compatible_replay_and_cache_eviction_have_zero_incompatible_delivery',
                    'new_start_promotion_reported',
                    'no_compatible_worker_behavior_reported',
                    'no_compatible_worker_has_zero_incompatible_delivery',
                    'no_compatible_worker_signal_is_explicit',
                    'cli_python_php_and_waterline_surfaces_reported',
                    'cross_language_php_python_cells_reported',
                    'cross_language_php_python_delivery_counts_are_zero',
                    'adversarial_no_version_bump_reported',
                    'artifact_versions_match_latest_published_set',
                    'published_artifact_install_evidence_reported',
                    'published_artifact_worker_execution_reported_for_replay_and_cross_language_cells',
                    'run_timestamps_outcome_and_finding_links_are_recorded',
                    'declared_outcome_matches_evaluated_status',
                    'no_local_product_source_artifacts',
                    'findings_linked_for_non_pass_scenarios',
                ],
                'uncovered_required_scenario_outcome' => 'non_passing',
                'smoke_subset_outcome' => 'non_passing',
                'unsupported_public_surface_outcome' => 'non_passing_with_root_cause_finding',
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
            ],
            'host_runner_contract' => [
                'status' => 'required_for_passing_worker_versioning_conformance',
                'must_execute_against_published_artifacts' => true,
                'must_record_runner_blocked_false_for_product_evidence' => true,
                'runner_path' => 'scripts/conformance/worker-versioning-published-artifacts.sh',
                'runner_command' => 'scripts/conformance/worker-versioning-published-artifacts.sh --result-dir <result-dir>',
                'result_files' => [
                    'published-artifacts.json',
                    'worker-versioning-result.json',
                    'worker-versioning-record.json',
                    'worker-versioning-http-captures.json',
                ],
                'evidence_inputs' => [
                    'DW_WV_ARTIFACT_INSTALL_EVIDENCE' => 'Optional JSON report proving required artifacts were installed and smoke-executed from published channels.',
                    'DW_WV_PUBLISHED_WORKER_EVIDENCE' => 'Optional JSON report from the host worker-versioning topology after published worker artifacts executed replay, cache-eviction, or cross-language cells.',
                ],
                'evidence_shards' => [
                    'published_artifact_versions',
                    'published_artifact_install_evidence',
                    'worker_registration_and_rollout_surfaces',
                    'compatible_replay_delivery_counts',
                    'cache_eviction_delivery_counts',
                    'published_artifact_worker_execution',
                    'cross_language_php_python_delivery_counts',
                    'history_and_visibility_pin_capture',
                ],
                'minimum_required_coverage' => [
                    'pin_on_start',
                    'replay_only_by_compatible_workers',
                    'new_starts_to_promoted_version',
                    'replay_across_cache_eviction',
                    'no_compatible_worker_behavior',
                    'operator_visibility_surfaces',
                    'cross_language_php_python_pinning',
                    'adversarial_no_version_bump',
                    'history_api_version_pin',
                ],
                'routing_policy' => [
                    'missing_required_scenario' => [
                        'outcome' => 'not_covered',
                        'finding_type' => 'conformance_runner_coverage_gap',
                    ],
                    'product_behavior_failure' => [
                        'outcome' => 'fail',
                        'finding_type' => 'product_gap',
                    ],
                    'host_environment_failure' => [
                        'outcome' => 'runner_blocked',
                        'finding_type' => 'runner_gap',
                    ],
                ],
            ],
            'result_gate' => WorkerVersioningRuntimeResultGate::spec(),
            'finding_policy' => [
                'root_cause_owners' => [
                    'server_dispatch_or_history_pin' => 'server',
                    'cli_operator_surface' => 'cli',
                    'waterline_operator_surface' => 'waterline',
                    'python_worker_registration_or_polling' => 'sdk-python',
                    'php_worker_registration_or_polling' => 'workflow',
                    'documentation_or_operator_runbook' => 'durable-workflow.github.io',
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
}
