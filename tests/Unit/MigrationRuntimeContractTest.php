<?php

namespace Tests\Unit;

use App\Support\MigrationRuntimeContract;
use App\Support\MigrationRuntimeResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class MigrationRuntimeContractTest extends TestCase
{
    public function test_manifest_names_full_published_artifact_upgrade_contract(): void
    {
        $manifest = MigrationRuntimeContract::manifest();

        $this->assertSame('durable-workflow.v2.migration-runtime.contract', $manifest['schema']);
        $this->assertSame(MigrationRuntimeContract::VERSION, $manifest['version']);
        $this->assertSame('durable-workflow.v2.migration-runtime.result', $manifest['result_schema']);
        $this->assertSame('migration_runtime_contract', $manifest['fixture_category']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            PlatformConformanceSuite::VERSION,
            $manifest['scenario_manifest']['suite_version'],
        );
        $this->assertSame(
            'static/platform-conformance/migration-runtime-scenarios.json',
            $manifest['scenario_manifest']['source_path'],
        );

        foreach ([
            'server-v1',
            'server-v2',
            'cli-v1',
            'cli-v2',
            'workflow-php-v1',
            'workflow-php-v2',
            'sdk-python',
            'waterline-v1',
            'waterline-v2',
            'sample-app-v1',
        ] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }

        $this->assertSame(['cli'], $manifest['artifact_policy']['release_artifact_aliases']['cli-v2']);
        $this->assertSame(['workflow-v1'], $manifest['artifact_policy']['release_artifact_aliases']['workflow-php-v1']);
        $this->assertContains('workflow', $manifest['artifact_policy']['release_artifact_aliases']['workflow-php-v2']);
        $this->assertSame(['waterline'], $manifest['artifact_policy']['release_artifact_aliases']['waterline-v2']);
        $this->assertTrue($manifest['artifact_policy']['release_records_without_assets_are_rejected']);
        $this->assertContains('server-v1', $manifest['required_matrix']['source_release_set']);
        $this->assertContains('cli-v1', $manifest['required_matrix']['source_release_set']);
        $this->assertContains('workflow-php-v1', $manifest['required_matrix']['source_release_set']);
        $this->assertContains('waterline-v1', $manifest['required_matrix']['source_release_set']);
        $this->assertContains('sample-app-v1', $manifest['required_matrix']['source_release_set']);
        $this->assertContains('server-v2', $manifest['required_matrix']['target_release_set']);
        $this->assertContains('cli-v2', $manifest['required_matrix']['target_release_set']);
        $this->assertContains('workflow-php-v2', $manifest['required_matrix']['target_release_set']);
        $this->assertContains('sdk-python', $manifest['required_matrix']['target_release_set']);
        $this->assertContains('waterline-v2', $manifest['required_matrix']['target_release_set']);

        foreach ([
            'published_artifact_install_only',
            'latest_supported_v1_state_setup',
            'documented_migration_steps_execute',
            'completed_history_preservation_and_replay',
            'in_flight_workflow_progress_preserved',
            'mid_activity_retry_preserved',
            'queue_state_preserved',
            'schedule_cross_upgrade_cadence_preserved',
            'worker_registration_projection_preserved',
            'waterline_operator_visibility_preserved',
            'cli_access_to_preupgrade_state',
            'new_v2_workflow_start_after_upgrade',
            'new_v2_schedule_after_upgrade',
            'new_v2_worker_registration_after_upgrade',
            'rollback_contract_verified',
            'version_skew_refusal',
        ] as $scenario) {
            $this->assertContains($scenario, $manifest['required_scenarios']);
            $this->assertArrayHasKey($scenario, $manifest['scenario_requirements']);
        }

        $this->assertSame(
            'required_context_not_passing_by_itself',
            $manifest['advisory_evidence']['storage_connection_smoke']['status'],
        );
        $this->assertSame(
            'non_passing',
            $manifest['coverage_gate']['storage_connection_smoke_only_outcome'],
        );
        $this->assertContains(
            'queryable_history',
            $manifest['scenario_requirements']['latest_supported_v1_state_setup']['required_fields'],
        );
        $this->assertContains(
            'command_timings',
            $manifest['scenario_requirements']['documented_migration_steps_execute']['required_fields'],
        );
        $this->assertContains(
            'guide_command_executability',
            $manifest['scenario_requirements']['documented_migration_steps_execute']['required_fields'],
        );
        $this->assertContains(
            'public_operator_signal',
            $manifest['scenario_requirements']['rollback_contract_verified']['required_fields'],
        );
        $this->assertContains(
            'request_response_evidence',
            $manifest['scenario_requirements']['version_skew_refusal']['required_fields'],
        );
        foreach ([
            'namespace',
            'unique_task_queue',
            'cli_worker_projection',
            'protocol_metadata',
            'freshness',
            'poll_request',
            'request_response_evidence',
            'exit_codes',
            'timestamps',
        ] as $field) {
            $this->assertContains(
                $field,
                $manifest['scenario_requirements']['new_v2_worker_registration_after_upgrade']['required_fields'],
            );
        }
        $this->assertContains('not_applicable', $manifest['scenario_statuses']);
        $this->assertContains('queue_state', $manifest['required_matrix']['state_kinds']);
        $this->assertContains(
            'source_capabilities',
            $manifest['artifact_policy']['required_run_record_fields'],
        );
        $this->assertSame(
            'v1_embedded_runtime_no_durable_schedule_surface',
            $manifest['source_capability_policy']['required_capabilities']['schedule']['absent_reason_code'],
        );
        $this->assertContains(
            'cli_skew_observations',
            $manifest['scenario_requirements']['version_skew_refusal']['required_fields'],
        );
        $this->assertContains(
            'worker_skew_observations',
            $manifest['scenario_requirements']['version_skew_refusal']['required_fields'],
        );
        $this->assertNotEmpty(array_filter(
            $manifest['required_matrix']['skew_cells'],
            static fn (array $cell): bool => ($cell['server'] ?? null) === 'server-v2'
                && ($cell['client'] ?? null) === 'cli-v1',
        ));
        $this->assertNotEmpty(array_filter(
            $manifest['required_matrix']['skew_cells'],
            static fn (array $cell): bool => ($cell['server'] ?? null) === 'server-v1'
                && ($cell['worker'] ?? null) === 'workflow-php-v2',
        ));
    }

    public function test_manifest_publishes_host_runner_handoff_and_result_gate(): void
    {
        $manifest = MigrationRuntimeContract::manifest();
        $hostRunner = $manifest['host_runner_contract'];

        $this->assertSame('required_for_passing_migration_conformance', $hostRunner['status']);
        $this->assertSame(MigrationRuntimeContract::RESULT_SCHEMA, $hostRunner['result_schema']);
        $this->assertTrue($hostRunner['must_execute_against_published_artifacts']);
        $this->assertTrue($hostRunner['must_start_from_latest_supported_v1_release']);
        $this->assertTrue($hostRunner['must_seed_realistic_v1_state']);
        $this->assertTrue($hostRunner['must_follow_public_migration_guide_verbatim']);
        $this->assertTrue($hostRunner['must_emit_result_for_every_required_scenario']);
        $this->assertSame('non_passing', $hostRunner['storage_connection_smoke_only_outcome']);

        foreach ([
            'latest-supported-v1-state',
            'public-guide-upgrade',
            'completed-history-replay',
            'in-flight-progress',
            'mid-activity-retry',
            'queue-state',
            'schedule-cadence',
            'worker-registration-projection',
            'waterline-operator-visibility',
            'cli-access-to-preupgrade-state',
            'new-v2-start',
            'new-v2-schedule',
            'new-v2-worker-registration',
            'rollback-contract',
            'version-skew-refusal',
            'storage-connection-smoke',
        ] as $scope) {
            $this->assertContains($scope, $hostRunner['required_execution_scopes']);
            $this->assertContains($scope, $hostRunner['merge_policy']['input_scopes']);
        }

        $this->assertSame(
            [
                'scenario_status' => 'not_covered',
                'finding_type' => 'conformance_runner_coverage_gap',
                'owner' => 'conformance_harness',
            ],
            $hostRunner['routing_policy']['missing_required_scenario'],
        );
        $this->assertSame(
            [
                'scenario_status' => 'fail',
                'finding_type' => 'missing_or_invalid_published_migration_artifact',
                'owner' => 'artifact_surface_owner',
            ],
            $hostRunner['routing_policy']['artifact_prerequisite_failure'],
        );
        $this->assertSame(
            'link_root_cause_finding_against_artifact_surface_owner',
            $manifest['finding_policy']['missing_or_invalid_published_migration_artifact'],
        );

        $resultGate = $manifest['result_gate'];
        $this->assertSame(MigrationRuntimeResultGate::SCHEMA, $resultGate['schema']);
        $this->assertSame(MigrationRuntimeResultGate::VERSION, $resultGate['version']);
        $this->assertSame(MigrationRuntimeContract::RESULT_SCHEMA, $resultGate['evaluates_result_schema']);
        $this->assertSame(
            'migration_runtime_contract.artifact_policy.required_run_record_fields',
            $resultGate['required_run_record_fields_source'],
        );
        $this->assertContains('scenario_results', $resultGate['scenario_results_fields']);
        $this->assertContains('published_artifact_versions', $resultGate['artifact_versions_fields']);
        $this->assertContains(
            'storage_connection_smoke_is_recorded_but_not_counted_as_complete',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'storage_connection_smoke_is_recorded_but_not_counted_as_complete',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'artifact_source_recorded_for_each_install_channel',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'artifact_prerequisite_failures_are_linked_when_artifacts_are_missing',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'realistic_preupgrade_state_cells_are_recorded',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'migration_guide_command_timings_are_recorded',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'rollback_public_operator_signal_recorded',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'cli_and_worker_skew_request_response_evidence_recorded',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'rollback_public_operator_signal_is_recorded',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'cli_and_worker_skew_request_response_evidence_is_recorded',
            $resultGate['pass_requires'],
        );
        $this->assertSame(
            'scripts/conformance/migration-published-artifacts.sh',
            $hostRunner['runner_path'],
        );
        $this->assertSame(
            'scripts/conformance/migration-published-artifacts.sh --result-dir <result-dir>',
            $hostRunner['runner_command'],
        );
        $this->assertContains('migration-conformance-result.json', $hostRunner['expected_output_files']);
        $this->assertContains('migration-conformance-record.json', $hostRunner['expected_output_files']);
        $this->assertArrayHasKey('DW_MIGRATION_EVIDENCE_JSON', $hostRunner['evidence_inputs']);
        $this->assertArrayHasKey('DW_MIGRATION_EVIDENCE_DIR', $hostRunner['evidence_inputs']);
        $this->assertArrayHasKey('DW_MIGRATION_RUN_PUBLIC_GUIDE_AUDIT', $hostRunner['evidence_inputs']);
        $this->assertArrayHasKey('DW_MIGRATION_GUIDE_AUDIT_TEXT', $hostRunner['evidence_inputs']);
        $this->assertArrayHasKey('DW_MIGRATION_RESOLVE_PUBLIC_ARTIFACTS', $hostRunner['evidence_inputs']);
        $this->assertArrayHasKey('DW_MIGRATION_PUBLIC_ARTIFACTS_JSON', $hostRunner['evidence_inputs']);
        $this->assertContains('artifact_sources', $manifest['artifact_policy']['required_run_record_fields']);
        $this->assertContains('not_exercised', $manifest['artifact_policy']['forbidden_sources']);
    }

    public function test_scenario_manifest_source_path_is_published_and_matches_contract(): void
    {
        $manifest = MigrationRuntimeContract::manifest();
        $scenarioManifestPath = dirname(__DIR__, 2) . '/' . $manifest['scenario_manifest']['source_path'];

        $this->assertFileExists(
            $scenarioManifestPath,
            'cluster info must not advertise a migration scenario manifest source path that is missing from the release tree',
        );

        $scenarioManifest = json_decode(
            (string) file_get_contents($scenarioManifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($manifest['scenario_manifest']['schema'], $scenarioManifest['schema']);
        $this->assertSame($manifest['scenario_manifest']['category'], $scenarioManifest['category']);
        $this->assertSame($manifest['scenario_manifest']['suite_schema'], $scenarioManifest['suite_schema']);
        $this->assertSame($manifest['scenario_manifest']['suite_version'], $scenarioManifest['suite_version']);
        $this->assertSame(PlatformConformanceSuite::VERSION, $scenarioManifest['suite_version']);
        $this->assertSame($manifest['scenario_statuses'], $scenarioManifest['result_statuses']);
        $this->assertSame($manifest['required_scenarios'], array_column($scenarioManifest['scenarios'], 'id'));
        $this->assertSame(
            array_keys($manifest['scenario_requirements']),
            array_keys($scenarioManifest['scenario_requirements']),
            'public migration scenario manifest must declare the same scenario required-field keys as cluster info',
        );
        $this->assertSame(
            array_keys($manifest['artifact_policy']['install_channels']),
            $scenarioManifest['artifact_policy']['required_artifacts'],
            'public migration scenario manifest must name every install channel that requires an artifact source',
        );
        $this->assertTrue($scenarioManifest['artifact_policy']['requires_artifact_sources_for_each_required_artifact']);
        $this->assertContains('artifact_sources', $scenarioManifest['common_result_evidence']);
        $this->assertContains('source_capabilities', $scenarioManifest['common_result_evidence']);
        $this->assertContains('artifact_prerequisite_failures', $scenarioManifest['common_result_evidence']);
        $this->assertContains('storage_connection_smoke', $scenarioManifest['common_result_evidence']);
        $this->assertSame(
            $manifest['artifact_policy']['placeholder_version_examples'],
            $scenarioManifest['artifact_policy']['placeholder_version_examples'],
            'public migration scenario manifest must advertise the same rejected placeholder versions as cluster info',
        );
        $this->assertContains(
            'storage_connection_smoke_is_recorded_but_not_counted_as_complete',
            $scenarioManifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'artifact_prerequisite_failures_are_linked_when_artifacts_are_missing',
            $scenarioManifest['coverage_gate']['passing_outcome_requires'],
        );
    }

    public function test_result_gate_rejects_storage_connection_smoke_only_result(): void
    {
        $evaluation = MigrationRuntimeResultGate::evaluate([
            'schema' => MigrationRuntimeContract::RESULT_SCHEMA,
            'outcome' => 'pass',
            'started_at' => '2026-05-31T22:39:36Z',
            'finished_at' => '2026-05-31T22:40:20Z',
            'generated_at' => '2026-05-31T22:40:20Z',
            'runner_blocked' => false,
            'published_artifact_versions' => $this->artifactVersions(),
            'artifact_sources' => $this->artifactSources(),
            'local_product_source_checkouts_used' => false,
            'findings' => [],
            'finding_links' => [],
            'scenario_results' => [
                'storage_connection_smoke' => [
                    'status' => 'pass',
                    'observed_outputs' => [
                        'workflow_migrations_use_base_class' => true,
                        'dedicated_connection_migration' => true,
                    ],
                ],
            ],
        ]);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertTrue($evaluation['smoke_subset_detected']);
        $this->assertContains('latest_supported_v1_state_setup', $evaluation['missing_scenarios']);
        $this->assertContains(
            'storage_connection_smoke_cannot_pass',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_findings_for_non_pass_scenarios(): void
    {
        $result = $this->completeMigrationResult();
        $result['outcome'] = 'fail';
        $result['scenario_results']['waterline_operator_visibility_preserved']['status'] = 'fail';
        unset($result['scenario_results']['waterline_operator_visibility_preserved']['linked_findings']);

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('waterline_operator_visibility_preserved', $evaluation['non_pass_scenarios']);
        $this->assertContains(
            'missing_non_pass_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_accepts_complete_passing_migration_matrix(): void
    {
        $evaluation = MigrationRuntimeResultGate::evaluate($this->completeMigrationResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_accepts_terminal_queue_result_without_postupgrade_queue_state(): void
    {
        $result = $this->completeMigrationResult();
        $queueOutputs = &$result['scenario_results']['queue_state_preserved']['observed_outputs'];
        $queueOutputs['dequeue_or_completion_result'] = [
            'task_id' => 'migration-queued-activity',
            'workflow_id' => 'migration-queue-holder',
            'activity_id' => 'migration-queued-activity-call',
            'disposition' => 'completed',
            'duplicate_execution_count' => 0,
        ];
        unset($queueOutputs['postupgrade_queue_state']);

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_requires_postupgrade_queue_state_for_claimable_disposition(): void
    {
        $result = $this->completeMigrationResult();
        $queueOutputs = &$result['scenario_results']['queue_state_preserved']['observed_outputs'];
        $queueOutputs['dequeue_or_completion_result'] = [
            'task_id' => 'migration-queued-activity',
            'workflow_id' => 'migration-queue-holder',
            'activity_id' => 'migration-queued-activity-call',
            'disposition' => 'claimable',
            'duplicate_execution_count' => 0,
        ];
        unset($queueOutputs['postupgrade_queue_state']);

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'postupgrade_queue_state',
            $this->missingScenarioRequiredFields($evaluation, 'queue_state_preserved'),
        );
    }

    public function test_result_gate_rejects_unproven_not_applicable_classification(): void
    {
        $result = $this->completeMigrationResult();
        $result['scenario_results']['schedule_cross_upgrade_cadence_preserved']['observed_outputs']['applicability']['reason_code'] =
            'surface_was_not_tested';

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'invalid_not_applicable_scenario',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_keeps_actual_v1_durable_capabilities_required(): void
    {
        $result = $this->completeMigrationResult();
        $result['source_capabilities']['capabilities']['queue_state'] = [
            'status' => 'unsupported',
            'reason_code' => 'queue_state_not_checked',
        ];

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'required_v1_durable_state_capability_unsupported'
                && ($failure['capability'] ?? null) === 'queue_state',
        ));
    }

    public function test_result_gate_rejects_empty_scenario_required_field_values(): void
    {
        $result = $this->completeMigrationResult();
        $result['scenario_results']['latest_supported_v1_state_setup'] = [
            'status' => 'pass',
            'observed_outputs' => [
                'source_release_versions' => null,
                'seeded_workflows' => '',
                'seeded_schedules' => '   ',
                'seeded_worker_registrations' => [],
            ],
        ];

        $evaluation = MigrationRuntimeResultGate::evaluate($result);
        $missingFields = $this->missingScenarioRequiredFields($evaluation, 'latest_supported_v1_state_setup');

        $this->assertSame('non_passing', $evaluation['status']);
        foreach ([
            'source_release_versions',
            'seeded_workflows',
            'seeded_schedules',
            'seeded_worker_registrations',
        ] as $field) {
            $this->assertContains($field, $missingFields);
        }
    }

    public function test_result_gate_requires_realistic_v1_state_cells_before_passing(): void
    {
        $result = $this->completeMigrationResult();
        $result['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_workflows'] = [
            'completed_workflow' => ['workflow_id' => 'migration-completed'],
        ];
        $result['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_schedules'] = [];
        $result['preupgrade_state_snapshot']['observed_states'] = [
            [
                'state_kind' => 'completed_history',
                'workflow_id' => 'migration-completed',
                'history_event_count' => 8,
            ],
            [
                'state_kind' => 'in_flight_workflow',
                'workflow_id' => 'migration-awaiting-signal',
                'signal_name' => 'approve',
            ],
        ];

        $evaluation = MigrationRuntimeResultGate::evaluate($result);
        $missingFields = $this->missingScenarioRequiredFields($evaluation, 'latest_supported_v1_state_setup');

        $this->assertSame('non_passing', $evaluation['status']);
        foreach ([
            'seeded_workflows.running_workflow_waiting_on_signal',
            'seeded_workflows.workflow_with_activity',
            'seeded_workflows.workflow_mid_activity_retry',
            'seeded_schedules.active_schedule',
        ] as $field) {
            $this->assertContains($field, $missingFields);
        }
        $this->assertNotEmpty(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_run_record_state_kind'
                && ($failure['field'] ?? null) === 'preupgrade_state_snapshot'
                && ($failure['state_kind'] ?? null) === 'retrying_activity',
        ));
    }

    public function test_result_gate_rejects_declared_only_realistic_state_item_lists(): void
    {
        $result = $this->completeMigrationResult();
        $result['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_workflows'] = [
            'completed_workflow',
            'running_workflow_waiting_on_signal',
            'workflow_with_activity',
            'workflow_mid_activity_retry',
        ];
        $result['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_schedules'] = [
            'active_schedule',
        ];
        $result['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_worker_registrations'] = [
            'registered_workers',
        ];
        $result['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['queryable_history'] = [
            'queryable_history',
        ];

        $evaluation = MigrationRuntimeResultGate::evaluate($result);
        $missingFields = $this->missingScenarioRequiredFields($evaluation, 'latest_supported_v1_state_setup');

        $this->assertSame('non_passing', $evaluation['status']);
        foreach ([
            'seeded_workflows.completed_workflow',
            'seeded_workflows.running_workflow_waiting_on_signal',
            'seeded_workflows.workflow_with_activity',
            'seeded_workflows.workflow_mid_activity_retry',
            'seeded_schedules.active_schedule',
            'seeded_worker_registrations.registered_workers',
            'queryable_history.queryable_history',
        ] as $field) {
            $this->assertContains($field, $missingFields);
        }
    }

    public function test_result_gate_rejects_expected_state_kind_snapshots_without_observed_state(): void
    {
        $result = $this->completeMigrationResult();
        $expectedStateKinds = MigrationRuntimeContract::manifest()['required_matrix']['state_kinds'];
        $result['preupgrade_state_snapshot'] = [
            'status' => 'pass',
            'expected_state_kinds' => $expectedStateKinds,
            'observed_behavior' => 'runner listed the expected state matrix without observed v1 state evidence',
        ];
        $result['postupgrade_state_snapshot'] = [
            'status' => 'pass',
            'expected_state_kinds' => $expectedStateKinds,
            'observed_behavior' => 'runner listed the expected state matrix without observed v2 state evidence',
        ];

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        foreach (['preupgrade_state_snapshot', 'postupgrade_state_snapshot'] as $field) {
            $this->assertNotEmpty(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_run_record_state_kind'
                    && ($failure['field'] ?? null) === $field
                    && ($failure['state_kind'] ?? null) === 'completed_history',
            ));
        }
    }

    public function test_result_gate_rejects_declared_state_kind_snapshots_without_observed_state(): void
    {
        $result = $this->completeMigrationResult();
        $stateKinds = MigrationRuntimeContract::manifest()['required_matrix']['state_kinds'];
        $result['preupgrade_state_snapshot'] = [
            'status' => 'pass',
            'state_kinds' => $stateKinds,
            'workflow_ids' => ['migration-completed', 'migration-awaiting-signal'],
        ];
        $result['postupgrade_state_snapshot'] = [
            'status' => 'pass',
            'state_kinds' => $stateKinds,
            'workflow_ids' => ['migration-completed', 'migration-awaiting-signal'],
        ];

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        foreach (['preupgrade_state_snapshot', 'postupgrade_state_snapshot'] as $field) {
            $this->assertNotEmpty(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_run_record_state_kind'
                    && ($failure['field'] ?? null) === $field
                    && ($failure['state_kind'] ?? null) === 'completed_history',
            ));
        }
    }

    public function test_result_gate_requires_timed_migration_guide_commands_before_passing(): void
    {
        $result = $this->completeMigrationResult();
        $result['scenario_results']['documented_migration_steps_execute']['observed_outputs']['commands_executed'] =
            'php artisan migrate';
        $result['scenario_results']['documented_migration_steps_execute']['observed_outputs']['command_timings'] = [];
        $result['scenario_results']['documented_migration_steps_execute']['observed_outputs']['guide_command_executability'] = [
            'status' => 'fail',
            'unexecutable_commands' => [
                [
                    'command' => 'sudo supervisorctl restart <your-worker-group>:*',
                    'reasons' => ['unresolved_placeholder'],
                ],
            ],
        ];

        $evaluation = MigrationRuntimeResultGate::evaluate($result);
        $missingFields = $this->missingScenarioRequiredFields($evaluation, 'documented_migration_steps_execute');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('commands_executed', $missingFields);
        $this->assertContains('command_timings', $missingFields);
        $this->assertContains('guide_command_executability.status_pass', $missingFields);
        $this->assertContains('guide_command_executability.unexecutable_commands_empty', $missingFields);
    }

    public function test_result_gate_requires_explicit_rollback_and_skew_observations_before_passing(): void
    {
        $result = $this->completeMigrationResult();
        $result['scenario_results']['rollback_contract_verified']['observed_outputs'] = [
            'rollback_steps' => ['php artisan queue:restart'],
            'rollback_supported_state' => ['documented_behavior_verified' => true],
            'postrollback_visibility' => ['status' => 'checked'],
            'postrollback_execution_result' => ['status' => 'checked'],
        ];
        foreach ([
            'standalone_server_api',
            'standalone_cli_server_surface',
            'remote_worker_endpoint',
        ] as $capability) {
            $result['source_capabilities']['capabilities'][$capability] = [
                'status' => 'supported',
                'evidence_basis' => 'standalone_v1_endpoint_probe',
            ];
        }
        $result['source_capabilities']['runtime_topology'] = 'standalone';
        $result['scenario_results']['version_skew_refusal']['status'] = 'pass';
        $result['scenario_results']['version_skew_refusal']['observed_outputs'] = [
            'skew_matrix' => [
                'cli-v1-to-server-v2' => ['server' => 'server-v2', 'client' => 'cli-v1'],
            ],
            'refusal_errors' => 'refused loudly',
            'operator_visible_reason' => 'version mismatch',
            'no_partial_mutation_evidence' => true,
        ];

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);

        $rollbackMissingFields = $this->missingScenarioRequiredFields($evaluation, 'rollback_contract_verified');
        $this->assertContains('public_operator_signal', $rollbackMissingFields);
        $this->assertContains('rollback_supported_state.supported_refused_or_irreversible', $rollbackMissingFields);

        $skewMissingFields = $this->missingScenarioRequiredFields($evaluation, 'version_skew_refusal');
        foreach ([
            'cli_skew_observations',
            'worker_skew_observations',
            'request_response_evidence',
            'no_partial_mutation_evidence',
            'cli_skew_observations.cli-v1-to-server-v2',
            'cli_skew_observations.cli-v2-to-server-v1',
            'worker_skew_observations.worker-v1-to-server-v2',
            'worker_skew_observations.worker-v2-to-server-v1',
            'request_response_evidence.cli-v1-to-server-v2',
            'request_response_evidence.worker-v2-to-server-v1',
            'applicability_evidence.cli-v1-to-server-v2.status_applicable',
        ] as $field) {
            $this->assertContains($field, $skewMissingFields);
        }
    }

    public function test_result_gate_requires_decisive_postupgrade_worker_registration_evidence(): void
    {
        $result = $this->completeMigrationResult();
        $worker = &$result['scenario_results']['new_v2_worker_registration_after_upgrade']['observed_outputs'];
        $worker['unique_task_queue'] = false;
        unset(
            $worker['cli_worker_projection']['last_heartbeat_at'],
            $worker['cli_worker_projection']['task_slots'],
            $worker['protocol_metadata']['poll'],
            $worker['request_response_evidence']['poll'],
            $worker['exit_codes']['poll'],
            $worker['timestamps']['poll'],
        );

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $missing = $this->missingScenarioRequiredFields(
            $evaluation,
            'new_v2_worker_registration_after_upgrade',
        );
        foreach ([
            'unique_task_queue.true',
            'cli_worker_projection.last_heartbeat_at',
            'cli_worker_projection.task_slots',
            'protocol_metadata.poll',
            'request_response_evidence.poll',
            'exit_codes.poll',
            'timestamps.poll',
        ] as $field) {
            $this->assertContains($field, $missing);
        }
    }

    public function test_result_gate_rejects_unexecuted_unsuccessful_stale_or_mismatched_worker_evidence(): void
    {
        $result = $this->completeMigrationResult();
        $worker = &$result['scenario_results']['new_v2_worker_registration_after_upgrade']['observed_outputs'];
        $worker['request_response_evidence']['registration']['response_source'] = 'plan_descriptor';
        $worker['request_response_evidence']['registration']['response_observed_from_command_stdout'] = false;
        $worker['request_response_evidence']['operator_api']['http_status'] = 500;
        $worker['cli_worker_projection']['sdk_version'] = '2.0.0-adversarial';
        $worker['task_queue_projection']['last_heartbeat_at'] = 'not-a-timestamp';

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $missing = $this->missingScenarioRequiredFields(
            $evaluation,
            'new_v2_worker_registration_after_upgrade',
        );
        foreach ([
            'request_response_evidence.registration.command_stdout_response',
            'request_response_evidence.operator_api.http_status_2xx',
            'operator_api.last_heartbeat_at.valid',
            'typed_response_contracts.api_cli_projection_match.last_heartbeat_at',
            'typed_response_contracts.api_cli_projection_match.sdk_version',
        ] as $field) {
            $this->assertContains($field, $missing);
        }
    }

    public function test_result_gate_uses_the_documented_worker_freshness_window_instead_of_evidence_input(): void
    {
        $result = $this->completeMigrationResult();
        $worker = &$result['scenario_results']['new_v2_worker_registration_after_upgrade']['observed_outputs'];
        $worker['freshness']['stale_after_seconds'] = 31536000;
        $worker['task_queue_projection']['last_heartbeat_at'] = '2025-05-31T22:40:19Z';
        $worker['cli_worker_projection']['last_heartbeat_at'] = '2025-05-31T22:40:19Z';

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $missing = $this->missingScenarioRequiredFields(
            $evaluation,
            'new_v2_worker_registration_after_upgrade',
        );
        $this->assertContains('freshness.stale_after_seconds.300', $missing);
        $this->assertContains('freshness.operator_api.within_stale_window', $missing);
        $this->assertContains('freshness.cli.within_stale_window', $missing);
    }

    public function test_result_gate_rejects_future_worker_heartbeat_timestamps(): void
    {
        $result = $this->completeMigrationResult();
        $worker = &$result['scenario_results']['new_v2_worker_registration_after_upgrade']['observed_outputs'];
        $worker['task_queue_projection']['last_heartbeat_at'] = '2026-06-01T22:40:19Z';
        $worker['cli_worker_projection']['last_heartbeat_at'] = '2026-06-01T22:40:19Z';

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $missing = $this->missingScenarioRequiredFields(
            $evaluation,
            'new_v2_worker_registration_after_upgrade',
        );
        $this->assertContains('freshness.operator_api.within_stale_window', $missing);
        $this->assertContains('freshness.cli.within_stale_window', $missing);
    }

    public function test_result_gate_rejects_not_covered_placeholder_required_evidence(): void
    {
        $result = $this->completeMigrationResult();
        $result['migration_plan'] = [
            'status' => 'not_covered',
            'observed_behavior' => 'migration guide execution was not supplied',
        ];
        $result['rollback_observations'] = [
            'coverage_gap' => true,
            'observed_behavior' => 'rollback was not exercised',
        ];
        $result['scenario_results']['latest_supported_v1_state_setup']['observed_outputs']['seeded_workflows'] = [
            'status' => 'not_covered',
            'observed_behavior' => 'workflow seeding was not supplied',
        ];

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'seeded_workflows',
            $this->missingScenarioRequiredFields($evaluation, 'latest_supported_v1_state_setup'),
        );
        foreach (['migration_plan', 'rollback_observations'] as $field) {
            $this->assertContains($field, $this->missingRunRecordFields($evaluation));
        }
    }

    public function test_result_gate_rejects_placeholder_string_required_evidence(): void
    {
        $result = $this->completeMigrationResult();
        $result['scenario_results']['completed_history_preservation_and_replay']['observed_outputs']['replay_result'] =
            'not_executed_by_public_guide_audit';
        $result['scenario_results']['documented_migration_steps_execute']['observed_outputs']['commands_executed'] = [
            'not_executed_by_public_guide_audit',
        ];
        $result['history_dumps'] = [
            'status' => 'pass',
            'completed_history' => 'not_executed_by_public_guide_audit',
        ];

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'replay_result',
            $this->missingScenarioRequiredFields($evaluation, 'completed_history_preservation_and_replay'),
        );
        $this->assertContains(
            'commands_executed',
            $this->missingScenarioRequiredFields($evaluation, 'documented_migration_steps_execute'),
        );
        $this->assertContains('history_dumps', $this->missingRunRecordFields($evaluation));
    }

    public function test_result_gate_accepts_false_and_zero_scenario_required_field_values(): void
    {
        $result = $this->completeMigrationResult();
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['local_product_source_checkouts_used'] = false;
        $result['scenario_results']['schedule_cross_upgrade_cadence_preserved']['observed_outputs']['missed_or_duplicate_ticks'] = 0;

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $this->missingScenarioRequiredFields($evaluation, 'published_artifact_install_only'));
        $this->assertSame([], $this->missingScenarioRequiredFields($evaluation, 'schedule_cross_upgrade_cadence_preserved'));
    }

    public function test_result_gate_requires_advertised_run_record_fields_before_passing(): void
    {
        $result = $this->completeMigrationResult();
        unset(
            $result['runner_blocked'],
            $result['resolved_artifact_versions'],
            $result['artifact_sources'],
            $result['source_capabilities'],
            $result['migration_plan'],
            $result['preupgrade_state_snapshot'],
            $result['postupgrade_state_snapshot'],
            $result['cli_observations'],
            $result['waterline_observations'],
            $result['rollback_observations'],
            $result['version_skew_observations'],
            $result['storage_connection_smoke'],
        );

        $evaluation = MigrationRuntimeResultGate::evaluate($result);
        $missingFields = $this->missingRunRecordFields($evaluation);

        $this->assertSame('non_passing', $evaluation['status']);
        foreach ([
            'runner_blocked',
            'resolved_artifact_versions',
            'artifact_sources',
            'source_capabilities',
            'migration_plan',
            'preupgrade_state_snapshot',
            'postupgrade_state_snapshot',
            'cli_observations',
            'waterline_observations',
            'rollback_observations',
            'version_skew_observations',
            'storage_connection_smoke',
        ] as $field) {
            $this->assertContains($field, $missingFields);
        }
    }

    public function test_result_gate_rejects_whitespace_only_scalar_run_record_fields(): void
    {
        $result = $this->completeMigrationResult();
        $result['started_at'] = " \t ";
        $result['finished_at'] = "\n ";
        $result['generated_at'] = '   ';

        $evaluation = MigrationRuntimeResultGate::evaluate($result);
        $missingFields = $this->missingRunRecordFields($evaluation);

        $this->assertSame('non_passing', $evaluation['status']);
        foreach (['started_at', 'finished_at', 'generated_at'] as $field) {
            $this->assertContains($field, $missingFields);
        }
    }

    public function test_result_gate_requires_runner_blocked_to_be_explicit_boolean_false(): void
    {
        $result = $this->completeMigrationResult();
        $result['runner_blocked'] = 'false';

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('runner_blocked', $this->missingRunRecordFields($evaluation));
    }

    public function test_result_gate_validates_published_and_resolved_artifact_pin_sets(): void
    {
        $result = $this->completeMigrationResult();
        $result['published_artifact_versions']['workflow-php-v1'] = '1.x';
        $result['published_artifact_versions']['workflow-php-v2'] = '2.0.0-alpha.<latest>';
        unset($result['resolved_artifact_versions']['waterline-v2']);

        $evaluation = MigrationRuntimeResultGate::evaluate($result);
        $artifactFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => in_array(
                $failure['code'] ?? null,
                ['missing_artifact_version', 'placeholder_artifact_version'],
                true,
            ),
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            [
                'code' => 'missing_artifact_version',
                'field' => 'resolved_artifact_versions',
                'artifact' => 'waterline-v2',
            ],
            $artifactFailures,
        );
        $this->assertNotEmpty(array_filter(
            $artifactFailures,
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'placeholder_artifact_version'
                && ($failure['field'] ?? null) === 'published_artifact_versions'
                && ($failure['artifact'] ?? null) === 'workflow-php-v1',
        ));
        $this->assertNotEmpty(array_filter(
            $artifactFailures,
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'placeholder_artifact_version'
                && ($failure['field'] ?? null) === 'published_artifact_versions'
                && ($failure['artifact'] ?? null) === 'workflow-php-v2',
        ));
    }

    public function test_result_gate_rejects_whitespace_only_artifact_versions(): void
    {
        $result = $this->completeMigrationResult();
        $result['artifact_versions'] = $this->artifactVersions();
        $result['artifact_versions']['cli-v2'] = " \t ";
        $result['published_artifact_versions']['workflow-php-v1'] = "\n ";
        $result['resolved_artifact_versions']['workflow-php-v2'] = '   ';

        $evaluation = MigrationRuntimeResultGate::evaluate($result);
        $artifactFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_artifact_version',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        foreach ([
            ['field' => 'artifact_versions', 'artifact' => 'cli-v2'],
            ['field' => 'published_artifact_versions', 'artifact' => 'workflow-php-v1'],
            ['field' => 'resolved_artifact_versions', 'artifact' => 'workflow-php-v2'],
        ] as $expected) {
            $this->assertNotEmpty(array_filter(
                $artifactFailures,
                static fn (array $failure): bool => ($failure['field'] ?? null) === $expected['field']
                    && ($failure['artifact'] ?? null) === $expected['artifact'],
            ));
        }
    }

    public function test_result_gate_accepts_contract_artifact_version_aliases(): void
    {
        $result = $this->completeMigrationResult();

        $result['published_artifact_versions']['cli'] = $result['published_artifact_versions']['cli-v2'];
        $result['published_artifact_versions']['workflow-v1'] = $result['published_artifact_versions']['workflow-php-v1'];
        $result['published_artifact_versions']['workflow'] = $result['published_artifact_versions']['workflow-php-v2'];
        $result['published_artifact_versions']['waterline'] = $result['published_artifact_versions']['waterline-v2'];
        unset(
            $result['published_artifact_versions']['cli-v2'],
            $result['published_artifact_versions']['workflow-php-v1'],
            $result['published_artifact_versions']['workflow-php-v2'],
            $result['published_artifact_versions']['waterline-v2'],
        );

        $result['resolved_artifact_versions']['cli'] = $result['resolved_artifact_versions']['cli-v2'];
        $result['resolved_artifact_versions']['workflow-v1'] = $result['resolved_artifact_versions']['workflow-php-v1'];
        $result['resolved_artifact_versions']['workflow-php'] = $result['resolved_artifact_versions']['workflow-php-v2'];
        $result['resolved_artifact_versions']['waterline'] = $result['resolved_artifact_versions']['waterline-v2'];
        unset(
            $result['resolved_artifact_versions']['cli-v2'],
            $result['resolved_artifact_versions']['workflow-php-v1'],
            $result['resolved_artifact_versions']['workflow-php-v2'],
            $result['resolved_artifact_versions']['waterline-v2'],
        );

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_requires_artifact_source_for_each_install_channel(): void
    {
        $result = $this->completeMigrationResult();
        unset(
            $result['artifact_sources']['waterline-v2'],
            $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['waterline-v2'],
        );

        $evaluation = MigrationRuntimeResultGate::evaluate($result);
        $sourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_published_artifact_install_source',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            [
                'code' => 'missing_published_artifact_install_source',
                'scenario_id' => 'published_artifact_install_only',
                'artifact' => 'waterline-v2',
            ],
            $sourceFailures,
        );
    }

    public function test_result_gate_rejects_placeholder_artifact_sources(): void
    {
        $result = $this->completeMigrationResult();
        $result['artifact_sources']['cli-v2'] = 'not_exercised';
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['cli-v2'] = 'not_exercised';

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_artifact_source'
                && ($failure['artifact'] ?? null) === 'cli-v2',
        ));
    }

    public function test_result_gate_rejects_scenario_level_local_product_source_checkout_usage(): void
    {
        $result = $this->completeMigrationResult();
        $result['local_product_source_checkouts_used'] = false;
        $result['scenario_results']['published_artifact_install_only']['local_product_source_checkouts_used'] = true;

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_artifacts_reported'
                && ($failure['scenario_id'] ?? null) === 'published_artifact_install_only'
                && ($failure['field'] ?? null) === 'local_product_source_checkouts_used',
        ));
    }

    public function test_result_gate_rejects_observed_output_local_product_source_checkout_usage(): void
    {
        $result = $this->completeMigrationResult();
        $result['local_product_source_checkouts_used'] = false;
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['local_product_source_checkouts_used'] = true;

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_artifacts_reported'
                && ($failure['scenario_id'] ?? null) === 'published_artifact_install_only'
                && ($failure['field'] ?? null) === 'observed_outputs.local_product_source_checkouts_used',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function completeMigrationResult(): array
    {
        $scenarioResults = [];
        foreach (MigrationRuntimeContract::manifest()['scenario_requirements'] as $scenarioId => $requirements) {
            $observedOutputs = [];
            foreach ($requirements['required_fields'] as $field) {
                $observedOutputs[$field] = match ($field) {
                    'local_product_source_checkouts_used' => false,
                    'artifact_sources' => $this->artifactSources(),
                    'resolved_artifact_versions' => $this->artifactVersions(),
                    default => $field . '-observed',
                };
            }

            $scenarioResults[$scenarioId] = [
                'status' => 'pass',
                'observed_outputs' => $observedOutputs,
            ];
        }

        $scenarioResults['cli_access_to_preupgrade_state']['observed_outputs']['typed_response_contracts'] = [
            'cli' => ['schema' => 'durable-workflow.cli.workflow-response.v2'],
            'operator_api' => ['schema' => 'durable-workflow.operator.workflow-response.v2'],
        ];
        $scenarioResults['new_v2_schedule_after_upgrade']['observed_outputs']['typed_response_contracts'] = [
            'cli' => ['schema' => 'durable-workflow.cli.schedule-response.v2'],
            'operator_api' => ['schema' => 'durable-workflow.operator.schedule-response.v2'],
            'schedule' => ['type' => 'schedule', 'schema' => 'durable-workflow.schedule.v2'],
        ];
        $scenarioResults['new_v2_worker_registration_after_upgrade']['observed_outputs']['typed_response_contracts'] = [
            'cli' => ['schema' => 'durable-workflow.cli.worker-projection.v2'],
            'operator_api' => ['schema' => 'durable-workflow.operator.worker-response.v2'],
            'worker_registration' => ['type' => 'worker_registration', 'schema' => 'durable-workflow.worker-registration.v2'],
            'worker_poll' => ['type' => 'worker_task_poll', 'schema' => 'durable-workflow.worker-task-poll.v2'],
        ];
        $workerProjection = [
            'worker_id' => 'migration-v2-worker',
            'namespace' => 'migration-conformance',
            'task_queue' => 'migration-v2-registration',
            'status' => 'active',
            'last_heartbeat_at' => '2026-05-31T22:40:19Z',
            'task_slots' => [
                'workflow_available' => 2,
                'activity_available' => 1,
                'session_available' => 1,
                'workflow_capacity' => 2,
                'activity_capacity' => 1,
                'session_capacity' => 1,
            ],
            'runtime' => 'php',
            'sdk_version' => $this->artifactVersions()['workflow-php-v2'],
            'build_id' => 'migration-v2-build',
            'capabilities' => ['workflow_tasks'],
        ];
        $operationTimestamp = [
            'started_at' => '2026-05-31T22:40:18Z',
            'finished_at' => '2026-05-31T22:40:19Z',
        ];
        $scenarioResults['new_v2_worker_registration_after_upgrade']['observed_outputs'] = [
            ...$scenarioResults['new_v2_worker_registration_after_upgrade']['observed_outputs'],
            'worker_id' => $workerProjection['worker_id'],
            'namespace' => $workerProjection['namespace'],
            'task_queue' => $workerProjection['task_queue'],
            'unique_task_queue' => true,
            'task_queue_projection' => $workerProjection,
            'cli_worker_projection' => $workerProjection,
            'protocol_metadata' => [
                'registration' => ['protocol_version' => '1.13'],
                'poll' => ['protocol_version' => '1.13'],
                'operator_api' => ['runtime' => 'php'],
                'cli' => ['runtime' => 'php'],
            ],
            'freshness' => [
                'stale_after_seconds' => 300,
                'operator_api' => ['valid' => true, 'last_heartbeat_at' => $workerProjection['last_heartbeat_at']],
                'cli' => ['valid' => true, 'last_heartbeat_at' => $workerProjection['last_heartbeat_at']],
            ],
            'polling_result' => [
                'request' => ['worker_id' => $workerProjection['worker_id'], 'task_queue' => $workerProjection['task_queue']],
                'response' => ['poll_status' => 'empty', 'task' => null],
                'exit_code' => 0,
                ...$operationTimestamp,
            ],
            'request_response_evidence' => [
                'registration' => ['request' => 'POST /api/worker/register', 'response' => ['registered' => true], 'http_status' => 201, 'response_source' => 'command_stdout_json', 'response_observed_from_command_stdout' => true],
                'operator_api' => ['request' => 'GET /api/workers/migration-v2-worker', 'response' => $workerProjection, 'http_status' => 200, 'response_source' => 'command_stdout_json', 'response_observed_from_command_stdout' => true],
                'cli' => ['request' => 'dw worker:list --output=json', 'response' => ['workers' => [$workerProjection]], 'response_source' => 'command_stdout_json', 'response_observed_from_command_stdout' => true],
                'poll' => ['request' => 'POST /api/worker/workflow-tasks/poll', 'response' => ['poll_status' => 'empty'], 'http_status' => 200, 'response_source' => 'command_stdout_json', 'response_observed_from_command_stdout' => true],
            ],
            'exit_codes' => ['registration' => 0, 'operator_api' => 0, 'cli' => 0, 'poll' => 0],
            'timestamps' => [
                'registration' => $operationTimestamp,
                'operator_api' => $operationTimestamp,
                'cli' => $operationTimestamp,
                'poll' => $operationTimestamp,
            ],
        ];

        $scenarioResults['latest_supported_v1_state_setup']['observed_outputs'] = [
            'source_release_versions' => $this->artifactVersions(),
            'source_capabilities' => $this->sourceCapabilities(),
            'seeded_workflows' => [
                'completed_workflow' => [
                    'workflow_id' => 'migration-completed',
                    'status' => 'completed',
                    'history_event_count' => 8,
                ],
                'running_workflow_waiting_on_signal' => [
                    'workflow_id' => 'migration-awaiting-signal',
                    'status' => 'running',
                    'signal_name' => 'approve',
                ],
                'workflow_with_activity' => [
                    'workflow_id' => 'migration-activity',
                    'activity_type' => 'migration_sample_activity',
                    'activity_completed' => true,
                ],
                'workflow_mid_activity_retry' => [
                    'workflow_id' => 'migration-retrying-activity',
                    'attempt' => 2,
                    'next_retry_at' => '2026-05-31T22:42:00Z',
                ],
            ],
            'seeded_schedules' => $this->notApplicableStateCell(
                'schedule',
                'active_schedule',
            ),
            'seeded_worker_registrations' => $this->notApplicableStateCell(
                'worker_registration',
                'registered_workers',
            ),
            'seeded_queue_state' => [
                'queued_task' => [
                    'task_id' => 'migration-queued-activity',
                    'task_queue' => 'migration-v1',
                    'status' => 'pending',
                ],
            ],
            'queryable_history' => [
                'queryable_history' => [
                    'workflow_ids' => [
                        'migration-completed',
                        'migration-awaiting-signal',
                    ],
                    'history_exported' => true,
                ],
            ],
        ];
        $scenarioResults['documented_migration_steps_execute']['observed_outputs'] = [
            'migration_guide_revision' => [
                'url' => 'https://durable-workflow.github.io/docs/2.0/migration/',
                'sha256' => 'migration-guide-sha',
            ],
            'guide_command_executability' => [
                'status' => 'pass',
                'checked_commands' => [
                    'composer require durable-workflow/workflow:2.0.0-alpha.185',
                    'php artisan migrate',
                    'php artisan queue:restart',
                ],
                'unexecutable_commands' => [],
            ],
            'commands_executed' => [
                'composer require durable-workflow/workflow:2.0.0-alpha.185',
                'php artisan migrate',
                'php artisan queue:restart',
            ],
            'exit_codes' => [0, 0, 0],
            'command_timings' => [
                'composer require durable-workflow/workflow:2.0.0-alpha.185' => 1280,
                'php artisan migrate' => 430,
                'php artisan queue:restart' => 95,
            ],
            'schema_or_storage_migration_output' => [
                'migrations_ran' => true,
                'workflow_storage_tables_created' => true,
            ],
        ];
        $scenarioResults['rollback_contract_verified']['observed_outputs'] = [
            'rollback_steps' => [
                'php artisan down',
                'mysql app < backup-before-v2.sql',
                'composer require laravel-workflow/laravel-workflow:1.7.4 laravel-workflow/waterline:1.4.2',
                'php artisan queue:restart',
            ],
            'rollback_supported_state' => [
                'classification' => 'refused',
                'state_after_v2_writes' => 'irreversible without restoring the pre-upgrade database backup',
            ],
            'public_operator_signal' => [
                'source' => 'https://durable-workflow.github.io/docs/2.0/migration/',
                'message' => 'Rollback after v2 writes is refused unless the operator restores the pre-upgrade database backup first.',
            ],
            'postrollback_visibility' => [
                'workflow_describe_exit_code' => 2,
                'stderr' => 'Refusing rollback without a pre-upgrade database restore.',
            ],
            'postrollback_execution_result' => [
                'status' => 'refused',
                'exit_code' => 2,
                'operator_visible_reason' => 'pre-upgrade backup restore required before v1 workers are restarted',
            ],
        ];
        $scenarioResults['version_skew_refusal']['observed_outputs'] = [
            'skew_matrix' => [
                'cli-v1-to-server-v2' => ['server' => 'server-v2', 'client' => 'cli-v1'],
                'cli-v2-to-server-v1' => ['server' => 'server-v1', 'client' => 'cli-v2'],
                'worker-v1-to-server-v2' => ['server' => 'server-v2', 'worker' => 'workflow-php-v1'],
                'worker-v2-to-server-v1' => ['server' => 'server-v1', 'worker' => 'workflow-php-v2'],
            ],
            'cli_skew_observations' => [
                'cli-v1-to-server-v2' => [
                    'command' => 'dw workflow:list --server http://server-v2',
                    'exit_code' => 2,
                    'stderr' => 'Unsupported server generation for this CLI.',
                ],
                'cli-v2-to-server-v1' => [
                    'command' => 'dw workflow:list --server http://server-v1',
                    'exit_code' => 2,
                    'stderr' => 'Server API is older than the CLI compatibility window.',
                ],
            ],
            'worker_skew_observations' => [
                'worker-v1-to-server-v2' => [
                    'request' => 'POST /api/worker/register',
                    'status' => 409,
                    'body' => ['error' => 'worker_version_unsupported'],
                ],
                'worker-v2-to-server-v1' => [
                    'request' => 'POST /api/worker/register',
                    'status' => 409,
                    'body' => ['error' => 'server_version_unsupported'],
                ],
            ],
            'refusal_errors' => [
                'worker_version_unsupported',
                'server_version_unsupported',
                'cli_server_generation_mismatch',
            ],
            'operator_visible_reason' => [
                'message' => 'Upgrade the CLI or worker to the server generation before submitting workflow operations.',
            ],
            'request_response_evidence' => [
                'cli-v1-to-server-v2' => ['request' => 'dw workflow:list', 'response' => ['exit_code' => 2]],
                'cli-v2-to-server-v1' => ['request' => 'dw workflow:list', 'response' => ['exit_code' => 2]],
                'worker-v1-to-server-v2' => ['request' => 'POST /api/worker/register', 'response' => ['status' => 409]],
                'worker-v2-to-server-v1' => ['request' => 'POST /api/worker/register', 'response' => ['status' => 409]],
            ],
            'no_partial_mutation_evidence' => [
                'workflow_count_before' => 3,
                'workflow_count_after' => 3,
                'worker_registration_count_after' => 0,
            ],
        ];
        $scenarioResults['schedule_cross_upgrade_cadence_preserved'] = $this->notApplicableScenario(
            'schedule_cross_upgrade_cadence_preserved',
            'schedule',
        );
        $scenarioResults['worker_registration_projection_preserved'] = $this->notApplicableScenario(
            'worker_registration_projection_preserved',
            'worker_registration',
        );
        $scenarioResults['version_skew_refusal'] = $this->notApplicableSkewScenario();

        return [
            'schema' => MigrationRuntimeContract::RESULT_SCHEMA,
            'outcome' => 'pass',
            'started_at' => '2026-05-31T22:39:36Z',
            'finished_at' => '2026-05-31T22:40:20Z',
            'generated_at' => '2026-05-31T22:40:20Z',
            'runner_blocked' => false,
            'published_artifact_versions' => $this->artifactVersions(),
            'resolved_artifact_versions' => $this->artifactVersions(),
            'artifact_sources' => $this->artifactSources(),
            'source_capabilities' => $this->sourceCapabilities(),
            'local_product_source_checkouts_used' => false,
            'findings' => [],
            'finding_links' => [],
            'migration_plan' => [
                'guide_revision' => 'docs/2.0/migration',
                'commands_executed' => $scenarioResults['documented_migration_steps_execute']['observed_outputs']['commands_executed'],
                'command_timings' => $scenarioResults['documented_migration_steps_execute']['observed_outputs']['command_timings'],
            ],
            'preupgrade_state_snapshot' => $this->stateSnapshotEvidence('preupgrade'),
            'postupgrade_state_snapshot' => $this->stateSnapshotEvidence('postupgrade'),
            'history_dumps' => ['completed' => true, 'running' => true],
            'activity_attempts' => ['retry_preserved' => true],
            'schedule_ticks' => ['cadence_preserved' => true],
            'worker_registration_observations' => ['projection_preserved' => true],
            'cli_observations' => ['preupgrade_state_readable' => true],
            'waterline_observations' => ['preupgrade_state_visible' => true],
            'rollback_observations' => $scenarioResults['rollback_contract_verified']['observed_outputs'],
            'version_skew_observations' => $scenarioResults['version_skew_refusal']['observed_outputs'],
            'storage_connection_smoke' => ['passed' => true],
            'scenario_results' => $scenarioResults,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stateSnapshotEvidence(string $phase): array
    {
        return [
            'state_kinds' => MigrationRuntimeContract::manifest()['required_matrix']['state_kinds'],
            'observed_states' => [
                [
                    'state_kind' => 'completed_history',
                    'phase' => $phase,
                    'workflow_id' => 'migration-completed',
                    'history_event_count' => 8,
                    'history_readable' => true,
                ],
                [
                    'state_kind' => 'in_flight_workflow',
                    'phase' => $phase,
                    'workflow_id' => 'migration-awaiting-signal',
                    'status' => $phase === 'preupgrade' ? 'running' : 'completed',
                    'signal_name' => 'approve',
                ],
                [
                    'state_kind' => 'retrying_activity',
                    'phase' => $phase,
                    'workflow_id' => 'migration-retrying-activity',
                    'activity_type' => 'migration_sample_activity',
                    'attempt' => $phase === 'preupgrade' ? 2 : 3,
                ],
                [
                    'state_kind' => 'queue_state',
                    'phase' => $phase,
                    'task_id' => 'migration-queued-activity',
                    'task_queue' => 'migration-v1',
                    'status' => $phase === 'preupgrade' ? 'pending' : 'completed',
                ],
                ...($phase === 'postupgrade' ? [
                    [
                        'state_kind' => 'schedule',
                        'phase' => $phase,
                        'schedule_id' => 'migration-v2-schedule',
                        'next_fire_at' => '2026-05-31T22:45:00Z',
                    ],
                    [
                        'state_kind' => 'worker_registration',
                        'phase' => $phase,
                        'worker_id' => 'migration-v2-worker',
                        'task_queue' => 'migration-v2',
                    ],
                ] : []),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceCapabilities(): array
    {
        $definitions = MigrationRuntimeContract::manifest()['source_capability_policy']['required_capabilities'];
        $capabilities = [];
        foreach ($definitions as $capability => $definition) {
            $supported = ($definition['continuity'] ?? null) === 'required';
            $capabilities[$capability] = [
                'status' => $supported ? 'supported' : 'unsupported',
                'evidence_basis' => 'selected_v1_embedded_runtime_profile',
            ];
            if (! $supported) {
                $capabilities[$capability]['reason_code'] = $definition['absent_reason_code'];
            }
        }

        return [
            'schema' => 'durable-workflow.v2.migration-runtime.source-capabilities',
            'status' => 'complete',
            'source_artifact' => 'workflow-php-v1',
            'source_version' => $this->artifactVersions()['workflow-php-v1'],
            'runtime_topology' => 'embedded_laravel',
            'inventory_source' => 'published_artifact_runtime_metadata',
            'inventoried_before_continuity' => true,
            'capabilities' => $capabilities,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notApplicableStateCell(string $capability, string $item): array
    {
        return [
            $item => [
                'status' => 'not_applicable',
                'source_capability' => $capability,
                'reason_code' => $this->sourceCapabilities()['capabilities'][$capability]['reason_code'],
                'durable_state_mutation_attempted' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notApplicableScenario(string $scenarioId, string $capability): array
    {
        return [
            'status' => 'not_applicable',
            'observed_outputs' => [
                'applicability' => [
                    'status' => 'not_applicable',
                    'source_capability' => $capability,
                    'source_capability_status' => 'unsupported',
                    'reason_code' => $this->sourceCapabilities()['capabilities'][$capability]['reason_code'],
                    'source_artifact' => 'workflow-php-v1',
                    'source_version' => $this->artifactVersions()['workflow-php-v1'],
                    'durable_state_mutation_attempted' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notApplicableSkewScenario(): array
    {
        $applicability = [];
        foreach (MigrationRuntimeContract::manifest()['required_matrix']['skew_cells'] as $cell) {
            $subject = isset($cell['worker'])
                ? preg_replace('/^workflow-php-/', 'worker-', $cell['worker'])
                : $cell['client'];
            $reasons = [];
            foreach ($cell['requires_source_capabilities'] as $capability) {
                $entry = $this->sourceCapabilities()['capabilities'][$capability];
                if ($entry['status'] === 'unsupported') {
                    $reasons[] = $entry['reason_code'];
                }
            }
            $applicability[$subject.'-to-'.$cell['server']] = [
                'status' => 'not_applicable',
                'required_source_capabilities' => $cell['requires_source_capabilities'],
                'reason_codes' => $reasons,
                'preflight_refusal' => true,
                'durable_state_mutation_attempted' => false,
            ];
        }

        return [
            'status' => 'not_applicable',
            'observed_outputs' => [
                'applicability_evidence' => $applicability,
                'skew_matrix' => $applicability,
                'request_response_evidence' => $applicability,
                'no_partial_mutation_evidence' => [
                    'durable_state_mutation_attempted' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function artifactVersions(): array
    {
        return [
            'server-v1' => '1.0.76',
            'server-v2' => '0.2.203',
            'cli-v1' => '0.1.44',
            'cli-v2' => '0.1.70',
            'workflow-php-v1' => '1.0.76',
            'workflow-php-v2' => '2.0.0-alpha.185',
            'sdk-python' => '0.4.83',
            'waterline-v1' => '1.4.2',
            'waterline-v2' => '2.0.0-alpha.69',
            'sample-app-v1' => 'v1.12.0',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function artifactSources(): array
    {
        return [
            'server-v1' => 'packagist:laravel-workflow/laravel-workflow:1.0.76:embedded-v1-server-runtime',
            'server-v2' => 'published_docker_image',
            'cli-v1' => 'official_v1_install_script',
            'cli-v2' => 'official_install_script',
            'workflow-php-v1' => 'composer_release',
            'workflow-php-v2' => 'composer_release',
            'sdk-python' => 'pypi_release',
            'waterline-v1' => 'published_waterline_v1_release',
            'waterline-v2' => 'published_waterline_release',
            'sample-app-v1' => 'published_sample_app_v1_tag',
        ];
    }

    /**
     * @param array<string, mixed> $evaluation
     *
     * @return list<string>
     */
    private function missingRunRecordFields(array $evaluation): array
    {
        return array_values(array_filter(array_map(
            static fn (array $failure): string => ($failure['code'] ?? null) === 'missing_run_record_field'
                ? (string) ($failure['field'] ?? '')
                : '',
            $evaluation['gate_failures'],
        )));
    }

    /**
     * @param array<string, mixed> $evaluation
     *
     * @return list<string>
     */
    private function missingScenarioRequiredFields(array $evaluation, string $scenarioId): array
    {
        $fields = [];

        foreach ($evaluation['gate_failures'] as $failure) {
            if (
                ($failure['code'] ?? null) === 'missing_scenario_required_field'
                && ($failure['scenario_id'] ?? null) === $scenarioId
            ) {
                $fields[] = (string) ($failure['field'] ?? '');
            }
        }

        return array_values(array_filter($fields));
    }
}
