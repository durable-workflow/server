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

        foreach (['server-v1', 'server-v2', 'cli', 'workflow-php-v1', 'workflow-php-v2', 'sdk-python', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }

        $this->assertSame(['workflow-v1'], $manifest['artifact_policy']['release_artifact_aliases']['workflow-php-v1']);
        $this->assertContains('workflow', $manifest['artifact_policy']['release_artifact_aliases']['workflow-php-v2']);
        $this->assertTrue($manifest['artifact_policy']['release_records_without_assets_are_rejected']);
        $this->assertContains('server-v1', $manifest['required_matrix']['source_release_set']);
        $this->assertContains('workflow-php-v1', $manifest['required_matrix']['source_release_set']);
        $this->assertContains('server-v2', $manifest['required_matrix']['target_release_set']);
        $this->assertContains('workflow-php-v2', $manifest['required_matrix']['target_release_set']);
        $this->assertContains('sdk-python', $manifest['required_matrix']['target_release_set']);

        foreach ([
            'published_artifact_install_only',
            'latest_supported_v1_state_setup',
            'documented_migration_steps_execute',
            'completed_history_preservation_and_replay',
            'in_flight_workflow_progress_preserved',
            'mid_activity_retry_preserved',
            'schedule_cross_upgrade_cadence_preserved',
            'worker_registration_projection_preserved',
            'waterline_operator_visibility_preserved',
            'cli_access_to_preupgrade_state',
            'new_v2_workflow_start_after_upgrade',
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
            'schedule-cadence',
            'worker-registration-projection',
            'waterline-operator-visibility',
            'cli-access-to-preupgrade-state',
            'new-v2-start',
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
        unset($result['resolved_artifact_versions']['waterline']);

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
                'artifact' => 'waterline',
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
        $result['artifact_versions']['cli'] = " \t ";
        $result['published_artifact_versions']['workflow-php-v1'] = "\n ";
        $result['resolved_artifact_versions']['workflow-php-v2'] = '   ';

        $evaluation = MigrationRuntimeResultGate::evaluate($result);
        $artifactFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_artifact_version',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        foreach ([
            ['field' => 'artifact_versions', 'artifact' => 'cli'],
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

        $result['published_artifact_versions']['workflow-v1'] = $result['published_artifact_versions']['workflow-php-v1'];
        $result['published_artifact_versions']['workflow'] = $result['published_artifact_versions']['workflow-php-v2'];
        unset(
            $result['published_artifact_versions']['workflow-php-v1'],
            $result['published_artifact_versions']['workflow-php-v2'],
        );

        $result['resolved_artifact_versions']['workflow-v1'] = $result['resolved_artifact_versions']['workflow-php-v1'];
        $result['resolved_artifact_versions']['workflow-php'] = $result['resolved_artifact_versions']['workflow-php-v2'];
        unset(
            $result['resolved_artifact_versions']['workflow-php-v1'],
            $result['resolved_artifact_versions']['workflow-php-v2'],
        );

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_requires_artifact_source_for_each_install_channel(): void
    {
        $result = $this->completeMigrationResult();
        unset(
            $result['artifact_sources']['waterline'],
            $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['waterline'],
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
                'artifact' => 'waterline',
            ],
            $sourceFailures,
        );
    }

    public function test_result_gate_rejects_placeholder_artifact_sources(): void
    {
        $result = $this->completeMigrationResult();
        $result['artifact_sources']['cli'] = 'not_exercised';
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['cli'] = 'not_exercised';

        $evaluation = MigrationRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_artifact_source'
                && ($failure['artifact'] ?? null) === 'cli',
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
            'local_product_source_checkouts_used' => false,
            'findings' => [],
            'finding_links' => [],
            'migration_plan' => ['guide_revision' => 'docs/2.0/migration'],
            'preupgrade_state_snapshot' => ['state_kinds' => MigrationRuntimeContract::manifest()['required_matrix']['state_kinds']],
            'postupgrade_state_snapshot' => ['state_kinds' => MigrationRuntimeContract::manifest()['required_matrix']['state_kinds']],
            'history_dumps' => ['completed' => true, 'running' => true],
            'activity_attempts' => ['retry_preserved' => true],
            'schedule_ticks' => ['cadence_preserved' => true],
            'worker_registration_observations' => ['projection_preserved' => true],
            'cli_observations' => ['preupgrade_state_readable' => true],
            'waterline_observations' => ['preupgrade_state_visible' => true],
            'rollback_observations' => ['documented_behavior_verified' => true],
            'version_skew_observations' => ['refused_loudly' => true],
            'storage_connection_smoke' => ['passed' => true],
            'scenario_results' => $scenarioResults,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function artifactVersions(): array
    {
        return [
            'server-v1' => '1.3.9',
            'server-v2' => '0.2.203',
            'cli' => '0.1.70',
            'workflow-php-v1' => '1.7.4',
            'workflow-php-v2' => '2.0.0-alpha.185',
            'sdk-python' => '0.4.83',
            'waterline' => '2.0.0-alpha.69',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function artifactSources(): array
    {
        return [
            'server-v1' => 'published_docker_image',
            'server-v2' => 'published_docker_image',
            'cli' => 'official_install_script',
            'workflow-php-v1' => 'composer_release',
            'workflow-php-v2' => 'composer_release',
            'sdk-python' => 'pypi_release',
            'waterline' => 'published_waterline_release',
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
