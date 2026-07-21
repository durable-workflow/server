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

    public const VERSION = 4;

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
                    'sdk-php' => 'Composer package durable-workflow/sdk:<exact released version>',
                    'sdk-python' => 'PyPI package durable-workflow==<latest>',
                    'waterline' => 'published Waterline observer artifact when claimed by the release set',
                ],
                'standalone_php_sdk_resolution' => [
                    'package' => 'durable-workflow/sdk',
                    'configured_version_env' => 'DW_PHP_SDK_VERSION',
                    'fallback_version_source' => 'Composer Packagist metadata',
                    'installed_version_source' => 'Composer\\InstalledVersions',
                    'installed_version_must_match_resolved_version' => true,
                    'canonical_source' => 'packagist://durable-workflow/sdk@<exact released version>',
                ],
                'forbidden_sources' => [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                ],
                'required_run_record_fields' => [
                    'artifact_versions',
                    'artifact_sources',
                    'artifact_install_evidence',
                    'artifact_version_resolution',
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
                    'sdk-php',
                    'sdk-python',
                ],
                'required_clients' => [
                    'cli',
                    'sdk-python',
                    'sdk-php',
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
                    'sdk-php',
                    'sdk-python',
                ],
                'client_paths' => [
                    'cli',
                    'sdk-python',
                    'sdk-php',
                ],
                'schedule_types' => [
                    'cron_expression',
                    'fixed_rate_interval',
                ],
                'cross_language_cells' => [
                    [
                        'schedule_creator' => 'sdk-python',
                        'workflow_runtime' => 'sdk-php',
                        'scenario' => 'python_created_php_workflow',
                    ],
                    [
                        'schedule_creator' => 'sdk-php',
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
                        'sdk-php schedule list or describe',
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
                        'scheduler_stop_confirmed',
                        'fires_during_scheduler_outage_count',
                        'stored_overdue_occurrence_elapsed_during_outage',
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
            'host_runner_contract' => [
                'status' => 'required_for_passing_schedules_conformance',
                'result_schema' => self::RESULT_SCHEMA,
                'must_probe_runtime_published_surfaces' => true,
                'must_emit_result_for_every_required_scenario' => true,
                'smoke_summary_only_outcome' => 'non_passing',
                'unexecuted_required_scenario_status' => 'not_covered',
                'coverage_gap_finding_type' => 'conformance_runner_coverage_gap',
                'coverage_gap_owner' => 'conformance_harness',
                'coverage_gap_findings' => self::coverageGapFindings(),
                'required_execution_scopes' => [
                    'published-artifact-install',
                    'cron-cadence-shard',
                    'fixed-rate-cadence-shard',
                    'operator-controls-shard',
                    'missed-fire-restart-shard',
                    'cli-schedule-surface-shard',
                    'sdk-python-schedule-surface-shard',
                    'sdk-php-schedule-surface-shard',
                    'cross-language-schedule-workflow-shard',
                    'adversarial-schedule-input-shard',
                ],
                'runtime_shards' => [
                    'cli' => [
                        'scope' => 'cli-schedule-surface-shard',
                        'must_cover_controls' => [
                            'list',
                            'describe',
                            'pause',
                            'resume',
                            'trigger',
                            'delete',
                        ],
                        'fallback_status_when_surface_missing' => 'unsupported',
                        'fallback_finding_type' => 'unsupported_public_surface',
                    ],
                    'sdk-python' => [
                        'scope' => 'sdk-python-schedule-surface-shard',
                        'must_cover_controls' => [
                            'create',
                            'list',
                            'describe',
                            'pause',
                            'resume',
                            'trigger',
                            'delete',
                        ],
                        'fallback_status_when_surface_missing' => 'unsupported',
                        'fallback_finding_type' => 'unsupported_public_surface',
                    ],
                    'sdk-php' => [
                        'scope' => 'sdk-php-schedule-surface-shard',
                        'must_cover_controls' => [
                            'create_or_observe',
                            'list_or_describe',
                            'update',
                            'pause',
                            'resume',
                            'trigger',
                            'backfill',
                            'history',
                            'delete',
                        ],
                        'fallback_status_when_surface_missing' => 'unsupported',
                        'fallback_finding_type' => 'unsupported_public_surface',
                    ],
                    'sdk-php-worker' => [
                        'scope' => 'cross-language-schedule-workflow-shard',
                        'must_register_workflows' => [
                            'SchedulesConformancePhpWorkflow',
                        ],
                        'fallback_status_when_surface_missing' => 'unsupported',
                        'fallback_finding_type' => 'unsupported_public_surface',
                    ],
                    'sdk-python-worker' => [
                        'scope' => 'cross-language-schedule-workflow-shard',
                        'must_register_workflows' => [
                            'SchedulesConformancePythonWorkflow',
                        ],
                        'fallback_status_when_surface_missing' => 'unsupported',
                        'fallback_finding_type' => 'unsupported_public_surface',
                    ],
                ],
                'merge_policy' => [
                    'input_scopes' => [
                        'published-artifact-install',
                        'cron-cadence-shard',
                        'fixed-rate-cadence-shard',
                        'operator-controls-shard',
                        'missed-fire-restart-shard',
                        'cli-schedule-surface-shard',
                        'sdk-python-schedule-surface-shard',
                        'sdk-php-schedule-surface-shard',
                        'cross-language-schedule-workflow-shard',
                        'adversarial-schedule-input-shard',
                    ],
                    'output_schema' => self::RESULT_SCHEMA,
                    'requires_required_runtimes' => [
                        'sdk-php',
                        'sdk-python',
                    ],
                    'requires_required_clients' => [
                        'cli',
                        'sdk-python',
                        'sdk-php',
                    ],
                    'requires_required_scenarios' => 'schedules_runtime_contract.required_scenarios',
                    'requires_sections' => [
                        'published_artifact_install',
                        'runtime_matrix',
                        'cadence_observations',
                        'operator_controls',
                        'missed_fire_policy',
                        'restart_survival',
                        'cross_language_matrix',
                        'adversarial_outcomes',
                    ],
                ],
                'routing_policy' => [
                    'missing_required_scenario' => [
                        'scenario_status' => 'not_covered',
                        'finding_type' => 'conformance_runner_coverage_gap',
                        'owner' => 'conformance_harness',
                    ],
                    'missing_public_surface' => [
                        'scenario_status' => 'unsupported',
                        'finding_type' => 'unsupported_public_surface',
                        'owner' => 'surface_owner',
                    ],
                    'scenario_product_failure' => [
                        'scenario_status' => 'fail',
                        'finding_source' => 'schedules_runtime_contract.finding_policy',
                    ],
                ],
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
                'php_surface_gap' => 'link_root_cause_finding_against_sdk_php',
                'cross_language_dispatch_gap' => 'link_root_cause_finding_against_server_or_worker_protocol_owner',
                'documentation_gap' => 'link_root_cause_finding_against_docs',
                'unsupported_public_surface' => 'link_root_cause_finding_against_surface_owner',
                'conformance_runner_coverage_gap' => 'link_root_cause_finding_against_conformance_harness',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function coverageGapFindings(): array
    {
        $currentEvidence = 'The current published-artifact evidence proves only the Python SDK schedule lifecycle smoke: create, list, describe, pause, resume, manual trigger, delete, triggered workflow completion, and invalid cron refusal.';

        return [
            'published_artifact_install_only' => [
                'id' => 'schedules-published-artifact-install-evidence',
                'owner' => 'conformance_harness',
                'scope' => 'published-artifact-install',
                'current_evidence' => 'The schedules result must name concrete published versions for every actor and prove none came from a local product checkout.',
                'expected_behavior' => 'Server image, CLI, Python SDK, PHP SDK, and Waterline are installed from published channels and recorded with concrete versions.',
                'acceptance' => [
                    'record server, cli, sdk-python, sdk-php, and waterline artifact versions',
                    'record artifact sources from published channels only',
                    'prove local product source checkouts were not used as artifacts under test',
                ],
            ],
            'cron_cadence' => [
                'id' => 'schedules-cron-cadence-coverage',
                'owner' => 'conformance_harness',
                'scope' => 'cron-cadence-shard',
                'current_evidence' => $currentEvidence.' It does not observe automatic cron fires over nominal minute boundaries.',
                'expected_behavior' => 'A cron schedule created from published artifacts fires on the documented cadence with timestamp and drift evidence.',
                'acceptance' => [
                    'create a * * * * * schedule from published artifacts',
                    'observe at least four fires with nominal and actual timestamps',
                    'record drift milliseconds for every observed fire',
                ],
            ],
            'fixed_rate_cadence' => [
                'id' => 'schedules-fixed-rate-cadence-coverage',
                'owner' => 'conformance_harness',
                'scope' => 'fixed-rate-cadence-shard',
                'current_evidence' => $currentEvidence.' It does not observe a fixed-rate interval schedule firing repeatedly.',
                'expected_behavior' => 'A fixed-rate schedule created from published artifacts fires on its documented interval without duplicate or skipped intervals.',
                'acceptance' => [
                    'create a PT30S interval schedule from published artifacts',
                    'observe at least eight interval fires',
                    'record nominal timestamps, actual timestamps, and drift milliseconds',
                ],
            ],
            'list_describe_visibility' => [
                'id' => 'schedules-public-visibility-coverage',
                'owner' => 'conformance_harness',
                'scope' => 'operator-controls-shard',
                'current_evidence' => $currentEvidence.' It does not prove multi-client list and describe visibility for cron and interval schedules.',
                'expected_behavior' => 'CLI, Python SDK, and PHP-facing client paths report the same schedule identity, cadence, last_fire_at, next_fire_at, and pause state.',
                'acceptance' => [
                    'list and describe cron and interval schedules through CLI JSON output',
                    'list and describe the same schedules through the Python SDK',
                    'list or describe the same schedules through the standalone PHP SDK surface',
                ],
            ],
            'pause_resume_no_fire_window' => [
                'id' => 'schedules-pause-no-fire-coverage',
                'owner' => 'conformance_harness',
                'scope' => 'operator-controls-shard',
                'current_evidence' => $currentEvidence.' It exercises pause and resume calls but does not prove no workflow fires during a paused window.',
                'expected_behavior' => 'A paused schedule records zero fires during the pause window and resumes from resume time without automatic catch-up.',
                'acceptance' => [
                    'pause an active schedule through a public surface',
                    'keep scheduler evaluation running for at least 120 seconds',
                    'record fires_during_pause_count as zero and observe the next normal fire after resume',
                ],
            ],
            'delete_stops_future_fires' => [
                'id' => 'schedules-delete-no-future-fire-coverage',
                'owner' => 'conformance_harness',
                'scope' => 'operator-controls-shard',
                'current_evidence' => $currentEvidence.' It deletes a schedule but does not prove no later automatic fire is attributed to the deleted schedule.',
                'expected_behavior' => 'A deleted schedule disappears from public list and describe surfaces and produces no later scheduled workflow fire.',
                'acceptance' => [
                    'delete an active schedule through a public surface',
                    'verify the deleted schedule is absent from list and describe outputs',
                    'observe at least one later nominal fire window with no workflow attributed to the deleted schedule',
                ],
            ],
            'missed_fire_policy' => [
                'id' => 'schedules-missed-fire-policy-coverage',
                'owner' => 'conformance_harness',
                'scope' => 'missed-fire-restart-shard',
                'current_evidence' => $currentEvidence.' It does not stop scheduler evaluation through missed fire times.',
                'expected_behavior' => 'After scheduler downtime through nominal fire times, the observed catch-up behavior matches fire_once_on_resume_then_skip_remaining_missed.',
                'acceptance' => [
                    'create an active one-minute schedule',
                    'stop scheduler evaluation through one or more nominal fire times',
                    'prove the scheduler stop completed and no fires were recorded before evaluation resumed',
                    'record documented_policy, observed_policy, catchup_fire_count, and post_resume_normal_fire_observed',
                ],
            ],
            'restart_survival' => [
                'id' => 'schedules-restart-survival-coverage',
                'owner' => 'conformance_harness',
                'scope' => 'missed-fire-restart-shard',
                'current_evidence' => $currentEvidence.' It does not restart the server while an active schedule is persisted.',
                'expected_behavior' => 'An active schedule remains listed after server restart and fires within the documented restart deadline.',
                'acceptance' => [
                    'create and persist an active schedule',
                    'restart the server process while keeping durable storage',
                    'verify the schedule is listed and fires after restart within restart_fire_deadline_seconds',
                ],
            ],
            'cli_schedule_surface' => [
                'id' => 'schedules-cli-surface-coverage',
                'owner' => 'cli',
                'scope' => 'cli-schedule-surface-shard',
                'current_evidence' => $currentEvidence.' It does not execute schedule lifecycle operations through the official CLI.',
                'expected_behavior' => 'The CLI schedule surface creates or observes schedules and exposes list, describe, pause, resume, trigger, and delete as machine-readable JSON.',
                'acceptance' => [
                    'run dw schedule:create or observe a schedule through the official CLI',
                    'run dw schedule:list and dw schedule:describe with JSON output',
                    'run dw schedule:pause, resume, trigger, and delete or record unsupported commands with findings',
                ],
            ],
            'python_sdk_schedule_surface' => [
                'id' => 'schedules-python-sdk-surface-evidence',
                'owner' => 'sdk-python',
                'scope' => 'sdk-python-schedule-surface-shard',
                'current_evidence' => $currentEvidence.' A full result still has to attach that smoke as scenario evidence for this cell.',
                'expected_behavior' => 'The Python SDK schedule surface reports lifecycle controls plus effective visibility filtering, deterministic cursor pagination, and typed filter or cursor refusals.',
                'acceptance' => [
                    'attach Python SDK lifecycle smoke outputs to python_sdk_schedule_surface',
                    'create mixed-status, mixed-workflow-type, and search-attributed schedules and prove every supported filter changes the result set',
                    'traverse more than one page without duplicates or omissions and finish with a null continuation token',
                    'record typed malformed, mismatched, cross-namespace, and stale cursor errors with field and last-safe-cursor evidence',
                    'prove the smoke used the published Python SDK artifact version',
                ],
            ],
            'php_schedule_surface' => [
                'id' => 'schedules-php-surface-coverage',
                'owner' => 'sdk-php',
                'scope' => 'sdk-php-schedule-surface-shard',
                'current_evidence' => $currentEvidence.' It does not exercise a PHP-facing schedule client path.',
                'expected_behavior' => 'The standalone PHP SDK can create or observe schedules and report list or describe state consistently with server and CLI state.',
                'acceptance' => [
                    'execute the PHP-facing schedule client path from the published PHP SDK package',
                    'record create_or_observe and list_or_describe outputs',
                    'record update, pause, resume, trigger, backfill, history, and delete behavior when the standalone SDK claims those controls',
                ],
            ],
            'python_created_php_workflow' => [
                'id' => 'schedules-python-created-php-workflow-coverage',
                'owner' => 'conformance_harness',
                'scope' => 'cross-language-schedule-workflow-shard',
                'current_evidence' => $currentEvidence.' It does not run a PHP worker for a schedule created by Python.',
                'expected_behavior' => 'A schedule created by the Python SDK dispatches a scheduled fire to a worker from the published PHP SDK package.',
                'acceptance' => [
                    'create a schedule with sdk-python targeting a PHP workflow type',
                    'run the PHP worker from the published PHP SDK package',
                    'record CLI schedule visibility and PHP workflow completion',
                ],
            ],
            'php_created_python_workflow' => [
                'id' => 'schedules-php-created-python-workflow-coverage',
                'owner' => 'conformance_harness',
                'scope' => 'cross-language-schedule-workflow-shard',
                'current_evidence' => $currentEvidence.' It does not create a schedule through PHP targeting a Python worker.',
                'expected_behavior' => 'A schedule created through the PHP-facing SDK dispatches a scheduled fire to a Python workflow worker from the published Python SDK.',
                'acceptance' => [
                    'create a schedule through the PHP-facing SDK targeting a Python workflow type',
                    'run the Python worker from the published Python SDK',
                    'record CLI schedule visibility and Python workflow completion',
                ],
            ],
            'invalid_cron_refusal' => [
                'id' => 'schedules-invalid-cron-evidence',
                'owner' => 'server',
                'scope' => 'adversarial-schedule-input-shard',
                'current_evidence' => $currentEvidence.' A full result still has to attach refused, typed_error, and persisted=false fields for this cell.',
                'expected_behavior' => 'Invalid cron input is rejected before schedule persistence and the result records the typed error and non-persistence.',
                'acceptance' => [
                    'attempt invalid cron creation through a public client path',
                    'record refused=true and typed_error=true',
                    'record persisted=false from a public list or describe check',
                ],
            ],
            'nonexistent_workflow_type_outcome' => [
                'id' => 'schedules-nonexistent-workflow-outcome-coverage',
                'owner' => 'server',
                'scope' => 'adversarial-schedule-input-shard',
                'current_evidence' => $currentEvidence.' It does not exercise a schedule targeting an unregistered workflow type.',
                'expected_behavior' => 'A schedule targeting a non-existent workflow type produces a documented operator-visible create-time, fire-time, or pending-worker outcome.',
                'acceptance' => [
                    'create or attempt to create a schedule targeting an unregistered workflow type',
                    'observe create-time validation or first scheduled fire behavior',
                    'record one allowed behavior and the operator-visible failure or pending-worker signal',
                ],
            ],
        ];
    }
}
