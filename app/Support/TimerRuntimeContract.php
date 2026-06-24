<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for timer and sleep runtime conformance.
 *
 * The current handoff is intentionally a runner-gap contract: it describes the
 * evidence a future published-artifact host runner must emit before timer
 * behavior can be classified as product evidence.
 */
final class TimerRuntimeContract
{
    public const SCHEMA = 'durable-workflow.v2.timer-runtime.contract';

    public const VERSION = 1;

    public const RESULT_SCHEMA = 'durable-workflow.v2.timer-runtime.result';

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
            'fixture_category' => 'timer_runtime_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'timer_runtime_contract',
                'suite_schema' => PlatformConformanceSuite::SCHEMA,
                'suite_version' => PlatformConformanceSuite::VERSION,
                'public_path' => 'https://durable-workflow.github.io/platform-conformance/timer-runtime-scenarios.json',
                'source_path' => 'static/platform-conformance/timer-runtime-scenarios.json',
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
                    'runner_blocked',
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
            'timer_semantics' => [
                'concurrent_timers_distinct_deadlines' => [
                    'required_behavior' => 'timers_resume_in_recorded_wake_up_order_without_early_or_duplicate_fires',
                    'required_evidence' => [
                        'wake_up_times',
                        'observed_resume_order',
                        'fired_at_times',
                    ],
                    'order_policy' => 'observed_resume_order_must_equal_wake_up_times_sorted_by_deadline',
                    'no_early_fire_policy' => 'each fired_at timestamp must be greater than or equal to its timer wake_up_at',
                    'no_duplicate_fire_policy' => 'each timer id may appear once in observed_resume_order and once in fired_at_times',
                ],
                'cancellation_while_waiting' => [
                    'required_behavior' => 'cancellation_requested_before_recorded_wake_up_and_timer_never_fires_after_cancel',
                    'required_evidence' => [
                        'cancellation_requested_at',
                        'wake_up_at',
                        'fired_after_cancel',
                        'workflow_status',
                    ],
                    'allowed_terminal_workflow_statuses' => [
                        'cancelled',
                        'terminated',
                        'failed',
                        'completed',
                    ],
                ],
                'operator_visible_timer_waiting_state' => [
                    'required_behavior' => 'operators_can_observe_an_explicit_waiting_or_timer_waiting_state_on_a_public_surface',
                    'recognized_public_surfaces' => [
                        'cli',
                        'waterline',
                        'public_api',
                    ],
                    'explicit_waiting_statuses' => [
                        'waiting',
                        'timer_waiting',
                        'waiting_on_timer',
                        'waiting_for_timer',
                    ],
                ],
            ],
            'required_scenarios' => [
                'concurrent_timers_distinct_deadlines',
                'cancellation_while_waiting',
                'operator_visible_timer_waiting_state',
            ],
            'scenario_requirements' => [
                'concurrent_timers_distinct_deadlines' => [
                    'evidence' => [
                        'wake_up_times',
                        'observed_resume_order',
                        'fired_at_times',
                    ],
                    'required_behavior' => 'resume_order_matches_wake_up_times_no_early_fires_no_duplicate_fires',
                ],
                'cancellation_while_waiting' => [
                    'evidence' => [
                        'cancellation_requested_at',
                        'wake_up_at',
                        'fired_after_cancel',
                        'workflow_status',
                    ],
                    'allowed_terminal_workflow_statuses' => [
                        'cancelled',
                        'terminated',
                        'failed',
                        'completed',
                    ],
                ],
                'operator_visible_timer_waiting_state' => [
                    'evidence' => [
                        'status',
                        'surface',
                    ],
                    'explicit_waiting_statuses' => [
                        'waiting',
                        'timer_waiting',
                        'waiting_on_timer',
                        'waiting_for_timer',
                    ],
                    'recognized_public_surfaces' => [
                        'cli',
                        'waterline',
                        'public_api',
                    ],
                ],
            ],
            'coverage_gate' => [
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'run_timestamps_outcome_runner_blocked_and_findings_are_recorded',
                    'declared_outcome_matches_evaluated_status',
                    'published_artifact_versions_are_recorded_and_pinned',
                    'no_local_product_source_artifacts',
                    'findings_linked_for_non_pass_scenarios',
                    'coverage_gap_scenario_findings_are_top_level_and_linked',
                    'concurrent_timer_resume_order_matches_wake_up_times',
                    'concurrent_timer_fires_are_not_early',
                    'concurrent_timer_fires_are_not_duplicated',
                    'cancellation_occurs_before_recorded_wake_up',
                    'cancelled_timer_does_not_fire_after_cancel',
                    'cancellation_terminal_status_is_documented',
                    'operator_waiting_state_uses_explicit_waiting_status',
                    'operator_waiting_state_uses_recognized_public_surface',
                ],
                'uncovered_required_scenario_outcome' => 'non_passing',
                'smoke_subset_outcome' => 'non_passing',
                'runner_blocked_outcome' => 'runner_blocked',
                'coverage_gap_outcome' => 'non_passing',
            ],
            'host_runner_contract' => [
                'status' => 'runner_gap_until_timer_host_runner_exists',
                'result_schema' => self::RESULT_SCHEMA,
                'host_runner_implemented' => false,
                'must_probe_runtime_published_surfaces' => true,
                'must_emit_result_for_every_required_scenario' => true,
                'smoke_summary_only_outcome' => 'non_passing',
                'unexecuted_required_scenario_status' => 'runner_blocked',
                'coverage_gap_finding_type' => 'conformance_runner_coverage_gap',
                'coverage_gap_owner' => 'conformance_harness',
                'required_execution_scopes' => [
                    'published-artifact-timer-runtime',
                    'concurrent-timers-distinct-deadlines-shard',
                    'cancellation-while-waiting-shard',
                    'operator-visible-timer-waiting-state-shard',
                ],
                'routing_policy' => [
                    'missing_host_runner' => [
                        'scenario_status' => 'runner_blocked',
                        'classification' => 'runner-gap',
                        'finding_type' => 'conformance_runner_coverage_gap',
                        'owner' => 'conformance_harness',
                    ],
                    'missing_required_scenario' => [
                        'scenario_status' => 'not_covered',
                        'classification' => 'coverage-gap',
                        'finding_type' => 'conformance_runner_coverage_gap',
                        'owner' => 'conformance_harness',
                    ],
                    'scenario_product_failure' => [
                        'scenario_status' => 'fail',
                        'finding_source' => 'timer_runtime_contract.finding_policy',
                    ],
                ],
            ],
            'result_gate' => TimerRuntimeResultGate::spec(),
            'finding_policy' => [
                'timer_resume_order_mismatch' => 'link_root_cause_finding_against_server_or_runtime_timer_owner',
                'timer_early_fire' => 'link_root_cause_finding_against_server_timer_dispatch',
                'timer_duplicate_fire' => 'link_root_cause_finding_against_server_timer_dispatch',
                'timer_cancellation_leak' => 'link_root_cause_finding_against_server_or_sdk_cancellation_owner',
                'operator_timer_visibility_gap' => 'link_root_cause_finding_against_cli_waterline_or_public_api_owner',
                'conformance_runner_coverage_gap' => 'link_root_cause_finding_against_conformance_harness',
            ],
        ];
    }
}
