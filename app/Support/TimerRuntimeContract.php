<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for timer and sleep runtime conformance.
 *
 * The current handoff emits source-free published-artifact evidence for normal
 * sleep completion, worker and server restart while sleeping, replay after
 * timer fire, concurrent timers, cancellation while waiting, and operator
 * visibility through a public workflow API response.
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
                'replay_after_timer_fire' => [
                    'required_behavior' => 'replay_after_timer_fire_is_deterministic_and_does_not_schedule_duplicate_timers',
                    'required_evidence' => [
                        'workflow_id',
                        'timer_id',
                        'fired_at',
                        'replay_started_at',
                        'replayed_event_ids',
                        'replayed_event_types',
                        'duplicate_timer_commands',
                    ],
                    'replay_timing_policy' => 'replay_started_at must be greater than or equal to fired_at',
                    'replayed_history_policy' => 'replayed_event_ids must be non-empty and replayed_event_types must include TimerFired',
                    'no_duplicate_timer_policy' => 'duplicate_timer_commands must be exactly zero after replaying the fired timer history',
                ],
                'concurrent_timers_distinct_deadlines' => [
                    'required_behavior' => 'timers_resume_in_recorded_wake_up_order_without_early_or_duplicate_fires',
                    'required_evidence' => [
                        'wake_up_times',
                        'observed_resume_order',
                        'fired_at_times',
                        'fire_counts',
                    ],
                    'order_policy' => 'observed_resume_order_must_equal_wake_up_times_sorted_by_deadline',
                    'no_early_fire_policy' => 'each fired_at timestamp must be greater than or equal to its timer wake_up_at',
                    'no_duplicate_fire_policy' => 'each timer id may appear once in observed_resume_order and once in fired_at_times',
                    'fire_count_policy' => 'fire_counts must include every timer id from wake_up_times with value exactly one',
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
                'normal_sleep_completion',
                'worker_restart_while_sleeping',
                'server_restart_while_sleeping',
                'replay_after_timer_fire',
                'concurrent_timers_distinct_deadlines',
                'cancellation_while_waiting',
                'operator_visible_timer_waiting_state',
            ],
            'scenario_requirements' => [
                'normal_sleep_completion' => [
                    'evidence' => [
                        'workflow_id',
                        'sleep_requested_at',
                        'wake_up_at',
                        'completed_at',
                        'workflow_result',
                    ],
                    'required_behavior' => 'workflow_sleep_completes_after_recorded_wake_up_without_early_resume',
                ],
                'worker_restart_while_sleeping' => [
                    'evidence' => [
                        'workflow_id',
                        'sleep_started_at',
                        'worker_restart_window',
                        'wake_up_at',
                        'completed_at',
                        'timer_fire_count',
                        'duplicate_resume_count',
                    ],
                    'required_behavior' => 'worker_restart_does_not_drop_or_duplicate_a_sleeping_timer',
                ],
                'server_restart_while_sleeping' => [
                    'evidence' => [
                        'workflow_id',
                        'sleep_started_at',
                        'server_restart_window',
                        'wake_up_at',
                        'completed_at',
                        'timer_state_recovered',
                        'timer_fire_count',
                        'duplicate_resume_count',
                    ],
                    'required_behavior' => 'server_restart_recovers_waiting_timer_state_and_completes_after_wake_up',
                ],
                'replay_after_timer_fire' => [
                    'evidence' => [
                        'workflow_id',
                        'timer_id',
                        'fired_at',
                        'replay_started_at',
                        'replayed_event_ids',
                        'replayed_event_types',
                        'duplicate_timer_commands',
                    ],
                    'required_behavior' => 'replay_after_timer_fire_is_deterministic_and_does_not_schedule_duplicate_timers',
                ],
                'concurrent_timers_distinct_deadlines' => [
                    'evidence' => [
                        'wake_up_times',
                        'observed_resume_order',
                        'fired_at_times',
                        'fire_counts',
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
                    'each_pass_scenario_reports_required_evidence',
                    'findings_linked_for_non_pass_scenarios',
                    'coverage_gap_scenario_findings_are_top_level_and_linked',
                    'normal_sleep_completion_completes_at_or_after_wake_up',
                    'replay_after_timer_fire_starts_at_or_after_fire',
                    'replay_after_timer_fire_replays_recorded_events',
                    'replay_after_timer_fire_does_not_schedule_duplicate_timer_commands',
                    'concurrent_timer_resume_order_matches_wake_up_times',
                    'concurrent_timer_fires_are_not_early',
                    'concurrent_timer_fires_are_not_duplicated',
                    'concurrent_timer_fire_counts_cover_declared_timer_ids',
                    'concurrent_timer_fire_counts_are_exactly_one',
                    'cancellation_occurs_before_recorded_wake_up',
                    'cancelled_timer_does_not_fire_after_cancel',
                    'cancellation_terminal_status_is_documented',
                    'operator_waiting_state_uses_explicit_waiting_status',
                    'operator_waiting_state_uses_recognized_public_surface',
                    'worker_restart_occurs_before_recorded_wake_up',
                    'worker_restart_completion_occurs_at_or_after_wake_up',
                    'worker_restart_timer_fires_exactly_once',
                    'worker_restart_duplicate_resume_count_is_zero',
                    'server_restart_occurs_before_recorded_wake_up',
                    'server_restart_completion_occurs_at_or_after_wake_up',
                    'server_restart_timer_state_recovered',
                    'server_restart_timer_fires_exactly_once',
                    'server_restart_duplicate_resume_count_is_zero',
                ],
                'uncovered_required_scenario_outcome' => 'non_passing',
                'smoke_subset_outcome' => 'non_passing',
                'runner_blocked_outcome' => 'runner_blocked',
                'coverage_gap_outcome' => 'non_passing',
            ],
            'host_runner_contract' => [
                'status' => 'published_handoff_proves_all_timer_runtime_cells_including_operator_visible_waiting_state',
                'result_schema' => self::RESULT_SCHEMA,
                'runner_repository' => 'server',
                'runner_path' => 'scripts/conformance/timers-published-artifacts.sh',
                'runner_command' => 'scripts/conformance/timers-published-artifacts.sh --result-dir <result-dir>',
                'result_files' => [
                    'pins.json',
                    'run-metadata.json',
                    'timer-runtime-result.json',
                    'timer-runtime-record.json',
                    'timer-runtime-findings.json',
                    'timers-result.json',
                    'timers-record.json',
                ],
                'host_runner_implemented' => true,
                'must_execute_inside_pinned_published_server_image' => true,
                'must_probe_runtime_published_surfaces' => true,
                'must_name_public_artifact_sources' => true,
                'no_local_product_source_checkout_pass_evidence' => true,
                'must_emit_result_for_every_required_scenario' => true,
                'smoke_summary_only_outcome' => 'non_passing',
                'unexecuted_required_scenario_status' => 'not_covered',
                'coverage_gap_finding_type' => 'conformance_runner_coverage_gap',
                'coverage_gap_owner' => 'conformance_harness',
                'required_execution_scopes' => [
                    'published-artifact-timer-runtime',
                    'normal-sleep-completion-shard',
                    'worker-restart-while-sleeping-shard',
                    'server-restart-while-sleeping-shard',
                    'replay-after-timer-fire-shard',
                    'concurrent-timers-distinct-deadlines-shard',
                    'cancellation-while-waiting-shard',
                    'operator-visible-timer-waiting-state-shard',
                ],
                'routing_policy' => [
                    'missing_executable_handoff' => [
                        'scenario_status' => 'runner_blocked',
                        'classification' => 'runner-gap',
                        'finding_type' => 'conformance_runner_coverage_gap',
                        'owner' => 'conformance_harness',
                    ],
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
