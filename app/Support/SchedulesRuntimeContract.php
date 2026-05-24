<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for the published-artifact schedules conformance
 * run.
 *
 * A schedule smoke proves basic CRUD. This manifest names the full recurring
 * work contract needed before schedules can pass conformance: cadence,
 * operator controls, missed-fire policy, restart survival, CLI/SDK/PHP
 * surfaces, and cross-language dispatch.
 */
final class SchedulesRuntimeContract
{
    public const SCHEMA = 'durable-workflow.v2.schedules-runtime.contract';

    public const VERSION = 1;

    public const RESULT_SCHEMA = 'durable-workflow.v2.schedules-runtime.result';

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
            'fixture_category' => 'schedules_runtime_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'schedules_runtime_contract',
                'suite_schema' => PlatformConformanceSuite::SCHEMA,
                'suite_version' => PlatformConformanceSuite::VERSION,
                'public_path' => 'https://durable-workflow.com/platform-conformance/schedules-runtime-scenarios.json',
                'source_path' => 'static/platform-conformance/schedules-runtime-scenarios.json',
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
                    'cadence_observations',
                    'operator_controls',
                    'missed_fire_policy',
                    'restart_survival',
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
            'schedule_policy' => [
                'missed_fire_policy' => 'fire_once_on_resume_then_skip_remaining_missed',
                'definition' => 'When the scheduler is down through one or more nominal fire times, the next evaluation starts one workflow for the stored overdue occurrence, records that occurrence_time, then computes next_fire_at from the evaluation time. Additional missed occurrences are skipped unless the operator explicitly backfills them.',
                'explicit_backfill_path' => 'POST /api/schedules/{scheduleId}/backfill',
                'pause_policy' => 'paused schedules do not fire; resume computes the next fire from resume time without automatic catch-up',
                'restart_policy' => 'active schedule rows survive server restart and fire at the first due tick after restart',
            ],
            'topology' => [
                'namespace' => 'schedules-conformance',
                'task_queue' => 'schedules-shared',
                'required_workers' => [
                    'workflow-php',
                    'sdk-python',
                ],
                'required_clients' => [
                    'cli',
                    'sdk-python',
                    'workflow-php-sdk',
                ],
                'schedule_types' => [
                    'cron_expression',
                    'fixed_rate_interval',
                ],
                'observation_windows' => [
                    'cron_minutes_minimum' => 5,
                    'fixed_rate_seconds_minimum' => 300,
                    'pause_seconds_minimum' => 120,
                    'restart_fire_deadline_seconds' => 90,
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
                'schedule_types' => [
                    'cron_expression',
                    'fixed_rate_interval',
                ],
                'cross_language_cells' => [
                    [
                        'schedule_creator' => 'sdk-python',
                        'workflow_runtime' => 'workflow-php',
                        'scenario' => 'python_created_php_workflow',
                    ],
                    [
                        'schedule_creator' => 'workflow-php-sdk',
                        'workflow_runtime' => 'sdk-python',
                        'scenario' => 'php_created_python_workflow',
                    ],
                ],
            ],
            'required_scenarios' => [
                'published_artifact_install_only',
                'cron_cadence',
                'fixed_rate_cadence',
                'list_describe_visibility',
                'pause_resume_no_fire_window',
                'delete_stops_future_fires',
                'missed_fire_policy',
                'restart_survival',
                'cli_schedule_surface',
                'python_sdk_schedule_surface',
                'php_schedule_surface',
                'python_created_php_workflow',
                'php_created_python_workflow',
                'invalid_cron_refusal',
                'nonexistent_workflow_type_outcome',
            ],
            'scenario_requirements' => [
                'cron_cadence' => [
                    'cron_expression' => '* * * * *',
                    'minimum_observed_fires' => 4,
                    'required_fields' => [
                        'actual_fire_timestamps',
                        'nominal_fire_timestamps',
                        'drift_ms',
                    ],
                ],
                'fixed_rate_cadence' => [
                    'interval' => 'PT30S',
                    'minimum_observed_fires' => 8,
                    'required_fields' => [
                        'actual_fire_timestamps',
                        'nominal_fire_timestamps',
                        'drift_ms',
                    ],
                ],
                'list_describe_visibility' => [
                    'required_surfaces' => [
                        'dw schedules list',
                        'sdk-python list_schedules',
                        'workflow-php schedule list or describe',
                    ],
                    'required_fields' => [
                        'cron_or_interval',
                        'last_fire_at',
                        'next_fire_at',
                        'paused',
                    ],
                ],
                'pause_resume_no_fire_window' => [
                    'required_behavior' => 'fires_during_pause_count_is_zero',
                ],
                'delete_stops_future_fires' => [
                    'required_behavior' => 'schedule_absent_from_list_and_no_later_fires',
                ],
                'missed_fire_policy' => [
                    'expected_policy' => 'fire_once_on_resume_then_skip_remaining_missed',
                    'required_fields' => [
                        'documented_policy',
                        'observed_policy',
                        'catchup_fire_count',
                        'post_resume_normal_fire_observed',
                    ],
                ],
                'restart_survival' => [
                    'required_behavior' => 'schedule_listed_after_restart_and_fires_after_restart',
                ],
                'invalid_cron_refusal' => [
                    'required_behavior' => 'server_refuses_before_persisting_schedule',
                    'required_fields' => [
                        'refused',
                        'typed_error',
                        'persisted',
                    ],
                ],
                'nonexistent_workflow_type_outcome' => [
                    'allowed_behaviors' => [
                        'refused_at_create',
                        'fails_at_fire_time',
                        'accepted_pending_worker',
                    ],
                    'silent_acceptance_is_nonconforming' => true,
                ],
            ],
            'coverage_gate' => [
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'cron_and_fixed_rate_cadence_reported',
                    'list_pause_resume_delete_controls_reported',
                    'missed_fire_policy_reported',
                    'restart_survival_reported',
                    'cli_python_and_php_surfaces_reported',
                    'cross_language_schedule_workflow_cells_reported',
                    'adversarial_invalid_cron_and_unknown_workflow_reported',
                    'artifact_versions_match_latest_published_set',
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
            'result_gate' => SchedulesRuntimeResultGate::spec(),
            'finding_policy' => [
                'off_cadence_fire' => 'link_root_cause_finding_against_server',
                'duplicate_fire' => 'link_root_cause_finding_against_server',
                'lost_schedule_after_restart' => 'link_root_cause_finding_against_server',
                'pause_window_fire' => 'link_root_cause_finding_against_server',
                'missed_fire_policy_mismatch' => 'link_root_cause_finding_against_server_or_docs',
                'invalid_cron_accepted' => 'link_root_cause_finding_against_server',
                'cli_surface_gap' => 'link_root_cause_finding_against_cli',
                'sdk_surface_gap' => 'link_root_cause_finding_against_sdk_owner',
                'php_surface_gap' => 'link_root_cause_finding_against_workflow_php',
                'cross_language_dispatch_gap' => 'link_root_cause_finding_against_server_or_worker_protocol_owner',
                'documentation_gap' => 'link_root_cause_finding_against_docs',
            ],
        ];
    }
}
