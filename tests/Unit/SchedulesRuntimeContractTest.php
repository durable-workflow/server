<?php

namespace Tests\Unit;

use App\Support\SchedulesRuntimeContract;
use App\Support\SchedulesRuntimeResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class SchedulesRuntimeContractTest extends TestCase
{
    public function test_manifest_requires_published_artifacts_and_run_record_fields(): void
    {
        $manifest = SchedulesRuntimeContract::manifest();

        $this->assertSame('durable-workflow.v2.schedules-runtime.contract', $manifest['schema']);
        $this->assertSame(4, SchedulesRuntimeContract::VERSION);
        $this->assertSame(SchedulesRuntimeContract::VERSION, $manifest['version']);
        $this->assertSame('durable-workflow.v2.schedules-runtime.result', $manifest['result_schema']);
        $this->assertSame('schedules_runtime_contract', $manifest['fixture_category']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['scenario_manifest']['suite_schema'],
        );
        $this->assertSame(
            PlatformConformanceSuite::VERSION,
            $manifest['scenario_manifest']['suite_version'],
        );
        $this->assertSame(
            'concrete_published_versions_pinned_at_run_time',
            $manifest['artifact_policy']['version_requirement'],
        );
        $this->assertTrue($manifest['artifact_policy']['placeholder_versions_rejected']);

        foreach (['server', 'cli', 'sdk-php', 'sdk-python', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }
        $this->assertSame(
            'durable-workflow/sdk',
            $manifest['artifact_policy']['standalone_php_sdk_resolution']['package'],
        );
        $this->assertSame(
            'Composer\\InstalledVersions',
            $manifest['artifact_policy']['standalone_php_sdk_resolution']['installed_version_source'],
        );
        $this->assertTrue(
            $manifest['artifact_policy']['standalone_php_sdk_resolution']['installed_version_must_match_resolved_version'],
        );

        foreach ([
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
        ] as $field) {
            $this->assertContains($field, $manifest['artifact_policy']['required_run_record_fields']);
        }
    }

    public function test_manifest_names_the_full_schedules_matrix_and_policy(): void
    {
        $manifest = SchedulesRuntimeContract::manifest();

        $this->assertSame(
            'fire_once_on_resume_then_skip_remaining_missed',
            $manifest['schedule_policy']['missed_fire_policy'],
        );
        $this->assertContains('cron_cadence', $manifest['required_scenarios']);
        $this->assertContains('fixed_rate_cadence', $manifest['required_scenarios']);
        $this->assertContains('pause_resume_no_fire_window', $manifest['required_scenarios']);
        $this->assertContains('missed_fire_policy', $manifest['required_scenarios']);
        $this->assertContains('restart_survival', $manifest['required_scenarios']);
        $this->assertContains('cli_schedule_surface', $manifest['required_scenarios']);
        $this->assertContains('php_schedule_surface', $manifest['required_scenarios']);
        $this->assertContains('python_created_php_workflow', $manifest['required_scenarios']);
        $this->assertContains('php_created_python_workflow', $manifest['required_scenarios']);
        $this->assertContains('invalid_cron_refusal', $manifest['required_scenarios']);

        $matrix = $manifest['required_matrix'];
        $this->assertContains('sdk-php', $matrix['runtimes']);
        $this->assertContains('sdk-python', $matrix['runtimes']);
        $this->assertContains('cli', $matrix['client_paths']);
        $this->assertContains('sdk-php', $matrix['client_paths']);
        $this->assertContains('cron_expression', $matrix['schedule_types']);
        $this->assertContains('fixed_rate_interval', $matrix['schedule_types']);
        $this->assertContains(
            [
                'schedule_creator' => 'sdk-python',
                'workflow_runtime' => 'sdk-php',
                'scenario' => 'python_created_php_workflow',
            ],
            $matrix['cross_language_cells'],
        );
    }

    public function test_manifest_publishes_host_runner_contract_for_full_schedules_coverage(): void
    {
        $manifest = SchedulesRuntimeContract::manifest();
        $hostRunner = $manifest['host_runner_contract'];

        $this->assertSame('required_for_passing_schedules_conformance', $hostRunner['status']);
        $this->assertSame(SchedulesRuntimeContract::RESULT_SCHEMA, $hostRunner['result_schema']);
        $this->assertTrue($hostRunner['must_probe_runtime_published_surfaces']);
        $this->assertTrue($hostRunner['must_emit_result_for_every_required_scenario']);
        $this->assertSame('non_passing', $hostRunner['smoke_summary_only_outcome']);
        $this->assertSame('not_covered', $hostRunner['unexecuted_required_scenario_status']);
        $this->assertSame('conformance_runner_coverage_gap', $hostRunner['coverage_gap_finding_type']);
        $this->assertSame('conformance_harness', $hostRunner['coverage_gap_owner']);

        foreach ([
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
        ] as $scope) {
            $this->assertContains($scope, $hostRunner['required_execution_scopes']);
            $this->assertContains($scope, $hostRunner['merge_policy']['input_scopes']);
        }

        $this->assertArrayHasKey('coverage_gap_findings', $hostRunner);
        $coverageGapFindings = $hostRunner['coverage_gap_findings'];
        foreach ($manifest['required_scenarios'] as $scenarioId) {
            $this->assertArrayHasKey(
                $scenarioId,
                $coverageGapFindings,
                sprintf('required scenario [%s] must have focused coverage-gap routing', $scenarioId),
            );
            $this->assertContains(
                $coverageGapFindings[$scenarioId]['owner'],
                ['conformance_harness', 'cli', 'sdk-python', 'sdk-php', 'server'],
            );
            $this->assertNotEmpty($coverageGapFindings[$scenarioId]['id']);
            $this->assertNotEmpty($coverageGapFindings[$scenarioId]['scope']);
            $this->assertNotEmpty($coverageGapFindings[$scenarioId]['current_evidence']);
            $this->assertNotEmpty($coverageGapFindings[$scenarioId]['expected_behavior']);
            $this->assertNotEmpty($coverageGapFindings[$scenarioId]['acceptance']);
        }
        $this->assertSame('schedules-cron-cadence-coverage', $coverageGapFindings['cron_cadence']['id']);
        $this->assertSame('cli', $coverageGapFindings['cli_schedule_surface']['owner']);
        $this->assertSame('sdk-php', $coverageGapFindings['php_schedule_surface']['owner']);
        $this->assertSame('server', $coverageGapFindings['invalid_cron_refusal']['owner']);

        $this->assertSame(
            ['SchedulesConformancePhpWorkflow'],
            $hostRunner['runtime_shards']['sdk-php-worker']['must_register_workflows'],
        );
        $this->assertSame(
            ['create_or_observe', 'list_or_describe', 'update', 'pause', 'resume', 'trigger', 'backfill', 'history', 'delete'],
            $hostRunner['runtime_shards']['sdk-php']['must_cover_controls'],
        );
        $this->assertSame(
            ['SchedulesConformancePythonWorkflow'],
            $hostRunner['runtime_shards']['sdk-python-worker']['must_register_workflows'],
        );
        $this->assertSame(
            'schedules_runtime_contract.required_scenarios',
            $hostRunner['merge_policy']['requires_required_scenarios'],
        );
        foreach (['sdk-php', 'sdk-python'] as $runtime) {
            $this->assertContains($runtime, $hostRunner['merge_policy']['requires_required_runtimes']);
        }
        foreach (['cli', 'sdk-python', 'sdk-php'] as $client) {
            $this->assertContains($client, $hostRunner['merge_policy']['requires_required_clients']);
        }
        foreach ([
            'published_artifact_install',
            'runtime_matrix',
            'cadence_observations',
            'operator_controls',
            'missed_fire_policy',
            'restart_survival',
            'cross_language_matrix',
            'adversarial_outcomes',
        ] as $section) {
            $this->assertContains($section, $hostRunner['merge_policy']['requires_sections']);
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
            'link_root_cause_finding_against_conformance_harness',
            $manifest['finding_policy']['conformance_runner_coverage_gap'],
        );
    }

    public function test_manifest_publishes_an_enforceable_result_gate(): void
    {
        $resultGate = SchedulesRuntimeContract::manifest()['result_gate'];

        $this->assertSame(SchedulesRuntimeResultGate::SCHEMA, $resultGate['schema']);
        $this->assertSame(SchedulesRuntimeResultGate::VERSION, $resultGate['version']);
        $this->assertSame(
            SchedulesRuntimeContract::RESULT_SCHEMA,
            $resultGate['evaluates_result_schema'],
        );
        $this->assertContains('scenario_results', $resultGate['scenario_results_fields']);
        $this->assertContains('artifactVersions', $resultGate['artifact_versions_fields']);
        $this->assertContains(
            'cross_language_schedule_workflow_cells_are_reported',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'cadence_operator_missed_restart_cross_language_and_adversarial_sections_are_reported',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'cadence_observation_counts_match_scenario_requirements',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'published_artifact_install_evidence_has_passing_non_local_entries',
            $resultGate['pass_requires'],
        );
        $this->assertSame('non_passing', $resultGate['smoke_subset_outcome']);
        $this->assertTrue($resultGate['artifact_version_policy']['requires_recorded_and_pinned_versions']);
        $this->assertTrue($resultGate['artifact_version_policy']['rejects_placeholder_versions']);
        foreach (['latest', 'current', 'head', 'unresolved', 'placeholder', '<latest>'] as $example) {
            $this->assertContains($example, $resultGate['artifact_version_policy']['placeholder_version_examples']);
        }
    }

    public function test_scenario_manifest_source_path_is_published_and_matches_contract(): void
    {
        $manifest = SchedulesRuntimeContract::manifest();
        $scenarioManifestPath = dirname(__DIR__, 2).'/'.$manifest['scenario_manifest']['source_path'];

        $this->assertFileExists(
            $scenarioManifestPath,
            'cluster info must not advertise a schedule scenario manifest source path that is missing from the release tree',
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
        $this->assertSame(
            $manifest['required_scenarios'],
            array_column($scenarioManifest['scenarios'], 'id'),
        );
        $this->assertSame(
            $manifest['scenario_requirements']['fixed_rate_cadence']['minimum_observed_fires'],
            $scenarioManifest['scenario_requirements']['fixed_rate_cadence']['minimum_observed_fires'],
        );
        $this->assertSame(
            $manifest['schedule_policy']['missed_fire_policy'],
            $scenarioManifest['schedule_policy']['missed_fire_policy'],
        );
        $this->assertSame(
            $manifest['host_runner_contract'],
            $scenarioManifest['host_runner_contract'],
        );
    }

    public function test_result_gate_rejects_schedule_smoke_subset_even_when_the_smoke_passes(): void
    {
        $evaluation = SchedulesRuntimeResultGate::evaluate([
            'schema' => SchedulesRuntimeContract::RESULT_SCHEMA,
            'artifactVersions' => [
                'server' => '0.2.174',
                'cli' => '0.1.56',
                'sdk-python' => '0.4.74',
                'sdk-php' => '0.1.1',
                'waterline' => '2.0.0-alpha.57',
            ],
            'scenario_results' => [
                'published_artifact_install_only' => [
                    'status' => 'pass',
                    'observed_outputs' => [
                        'artifact_sources' => ['server' => 'published_docker_image'],
                    ],
                ],
                'python_sdk_schedule_surface' => [
                    'status' => 'pass',
                    'observed_outputs' => [
                        'create_or_observe' => true,
                        'list_observed' => true,
                        'control_observed' => true,
                    ],
                ],
                'invalid_cron_refusal' => [
                    'status' => 'pass',
                    'observed_outputs' => [
                        'refused' => true,
                        'typed_error' => true,
                        'persisted' => false,
                    ],
                ],
            ],
        ]);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertTrue($evaluation['smoke_subset_detected']);
        $this->assertContains('cron_cadence', $evaluation['missing_scenarios']);
        $this->assertContains(
            'smoke_subset_cannot_pass',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_findings_for_non_pass_scenarios(): void
    {
        $result = $this->completeSchedulesResult();
        $result['scenario_results']['fixed_rate_cadence']['status'] = 'fail';
        unset($result['scenario_results']['fixed_rate_cadence']['linked_findings']);

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('fixed_rate_cadence', $evaluation['non_pass_scenarios']);
        $this->assertContains(
            'missing_non_pass_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_missing_missed_fire_and_restart_evidence(): void
    {
        $result = $this->completeSchedulesResult();
        unset($result['missed_fire_policy']['post_resume_normal_fire_observed']);
        unset($result['restart_survival']['fired_after_restart']);
        unset($result['scenario_results']['missed_fire_policy']['observed_outputs']['post_resume_normal_fire_observed']);
        unset($result['scenario_results']['restart_survival']['observed_outputs']['fired_after_restart']);

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_post_resume_normal_fire_evidence', $failureCodes);
        $this->assertContains('missing_restart_survival_evidence', $failureCodes);
    }

    public function test_result_gate_rejects_missed_fire_policy_without_a_confirmed_scheduler_outage(): void
    {
        $result = $this->completeSchedulesResult();
        $result['missed_fire_policy']['scheduler_stop_confirmed'] = false;
        $result['missed_fire_policy']['fires_during_scheduler_outage_count'] = 2;
        $result['scenario_results']['missed_fire_policy']['observed_outputs']['scheduler_stop_confirmed'] = false;
        $result['scenario_results']['missed_fire_policy']['observed_outputs']['fires_during_scheduler_outage_count'] = 2;

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'scheduler_outage_not_proven',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_cadence_counts_from_the_manifest(): void
    {
        $result = $this->completeSchedulesResult();
        $shortFixedRateCadence = [
            'actual_fire_timestamps' => [
                '2026-05-24T05:00:00Z',
                '2026-05-24T05:00:30Z',
                '2026-05-24T05:01:00Z',
                '2026-05-24T05:01:30Z',
            ],
            'nominal_fire_timestamps' => [
                '2026-05-24T05:00:00Z',
                '2026-05-24T05:00:30Z',
                '2026-05-24T05:01:00Z',
                '2026-05-24T05:01:30Z',
            ],
            'drift_ms' => [0, 15, 12, 18],
        ];
        $result['scenario_results']['fixed_rate_cadence']['observed_outputs'] = $shortFixedRateCadence;
        $result['cadence_observations']['fixed_rate'] = $shortFixedRateCadence;

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $fixedRateTimestampFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'insufficient_cadence_fire_timestamps'
                && ($failure['scenario_id'] ?? null) === 'fixed_rate_cadence'
                && ($failure['field'] ?? null) === 'actual_fire_timestamps',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($fixedRateTimestampFailures);
        $this->assertSame(8, $fixedRateTimestampFailures[0]['minimum_observed_fires']);
        $this->assertSame(4, $fixedRateTimestampFailures[0]['observed_count']);
    }

    public function test_result_gate_rejects_forbidden_sources_reported_in_scenario_outputs(): void
    {
        $result = $this->completeSchedulesResult();
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['server'] =
            'local_product_source_checkout';

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $forbiddenSourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_artifact_source'
                && ($failure['scenario_id'] ?? null) === 'published_artifact_install_only'
                && ($failure['artifact'] ?? null) === 'server',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($forbiddenSourceFailures);
    }

    public function test_result_gate_requires_each_published_artifact_install_source(): void
    {
        $result = $this->completeSchedulesResult();
        unset($result['artifact_sources']);
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources'] = [
            'server' => 'published_docker_image',
        ];

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $missingCliSourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_published_artifact_install_source'
                && ($failure['artifact'] ?? null) === 'cli',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($missingCliSourceFailures);
    }

    public function test_result_gate_requires_explicit_no_local_source_evidence_for_install_pass(): void
    {
        $result = $this->completeSchedulesResult();
        unset($result['local_product_source_checkouts_used']);
        unset($result['scenario_results']['published_artifact_install_only']['observed_outputs']['local_product_source_checkouts_used']);

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $missingExplicitFalseFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_explicit_source_free_evidence'
                && ($failure['scenario_id'] ?? null) === 'published_artifact_install_only',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($missingExplicitFalseFailures);
    }

    public function test_result_gate_requires_published_artifact_install_evidence(): void
    {
        $result = $this->completeSchedulesResult();
        unset($result['artifact_install_evidence']);
        unset($result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']);

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_published_artifact_install_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_non_passing_artifact_install_evidence(): void
    {
        $result = $this->completeSchedulesResult();
        $result['artifact_install_evidence']['artifacts'][1]['status'] = 'not_covered';
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']['artifacts'][1]['status'] =
            'not_covered';

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'published_artifact_install_evidence_not_pass'
                && ($failure['artifact'] ?? null) === 'cli',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_forbidden_artifact_install_evidence_source(): void
    {
        $result = $this->completeSchedulesResult();
        $result['artifact_install_evidence']['artifacts'][0]['source'] = 'workspace_repo_as_artifact_under_test';
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']['artifacts'][0]['source'] =
            'workspace_repo_as_artifact_under_test';

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_published_artifact_install_evidence_source'
                && ($failure['artifact'] ?? null) === 'server',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_unallowlisted_published_install_source_label(): void
    {
        $result = $this->completeSchedulesResult();
        $result['artifact_sources']['server'] = 'banana';
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['server'] =
            'banana';

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $invalidSourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'invalid_published_artifact_install_source'
                && ($failure['scenario_id'] ?? null) === 'published_artifact_install_only'
                && ($failure['artifact'] ?? null) === 'server',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($invalidSourceFailures);
    }

    public function test_result_gate_rejects_unallowlisted_artifact_install_evidence_source_label(): void
    {
        $result = $this->completeSchedulesResult();
        $result['artifact_install_evidence']['artifacts'][2]['source'] = 'pypi_package';
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']['artifacts'][2]['source'] =
            'pypi_package';

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $invalidSourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'invalid_published_artifact_install_evidence_source'
                && ($failure['scenario_id'] ?? null) === 'published_artifact_install_only'
                && ($failure['artifact'] ?? null) === 'sdk-python',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($invalidSourceFailures);
    }

    public function test_result_gate_rejects_local_checkout_install_source_paths(): void
    {
        $result = $this->completeSchedulesResult();
        $result['artifact_sources']['server'] = 'local_checkout/banana';
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['server'] =
            'local_checkout/banana';

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $forbiddenSourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_artifact_source'
                && ($failure['artifact'] ?? null) === 'server',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($forbiddenSourceFailures);
    }

    public function test_result_gate_rejects_local_product_source_checkout_use_flags(): void
    {
        $result = $this->completeSchedulesResult();
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['local_product_source_checkouts_used'] =
            'true';
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']['artifacts'][1]['local_product_source_checkouts_used'] =
            true;

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('local_product_source_checkouts_used_must_be_false', $failureCodes);
    }

    public function test_result_gate_rejects_placeholder_artifact_versions_embedded_in_install_channel_strings(): void
    {
        $result = $this->completeSchedulesResult();
        $result['artifactVersions'] = [
            'server' => 'durableworkflow/server:head',
            'cli' => 'durable-workflow-cli==current',
            'sdk-python' => 'durable-workflow==unresolved',
            'sdk-php' => 'durable-workflow/sdk:placeholder',
            'waterline' => 'durable-workflow/waterline:<latest>',
        ];

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $placeholderFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'placeholder_artifact_version',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame(
            ['server', 'cli', 'sdk-php', 'sdk-python', 'waterline'],
            array_column($placeholderFailures, 'artifact'),
        );
    }

    public function test_result_gate_rejects_each_advertised_placeholder_word_inside_an_artifact_version(): void
    {
        foreach (['latest', 'current', 'head', 'unresolved', 'placeholder'] as $placeholder) {
            $result = $this->completeSchedulesResult();
            $result['artifactVersions']['server'] = 'durableworkflow/server:'.$placeholder;

            $evaluation = SchedulesRuntimeResultGate::evaluate($result);
            $serverPlaceholderFailures = array_values(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'placeholder_artifact_version'
                    && ($failure['artifact'] ?? null) === 'server',
            ));

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertCount(1, $serverPlaceholderFailures);
            $this->assertSame('durableworkflow/server:'.$placeholder, $serverPlaceholderFailures[0]['version']);
        }
    }

    public function test_result_gate_requires_invalid_cron_to_report_explicit_not_persisted(): void
    {
        $result = $this->completeSchedulesResult();
        unset($result['adversarial_outcomes']['invalid_cron']['persisted']);
        unset($result['scenario_results']['invalid_cron_refusal']['observed_outputs']['persisted']);

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_invalid_cron_refusal_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_the_php_sdk_worker_runtime(): void
    {
        $result = $this->completeSchedulesResult();
        $result['runtime_matrix']['runtimes'] = ['sdk-python'];

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $runtimeFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_required_runtime'
                && ($failure['runtime'] ?? null) === 'sdk-php',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($runtimeFailures);
    }

    public function test_result_gate_requires_the_php_sdk_client_path(): void
    {
        $result = $this->completeSchedulesResult();
        $result['runtime_matrix']['client_paths'] = ['cli', 'sdk-python'];

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $clientFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_required_client_path'
                && ($failure['client_path'] ?? null) === 'sdk-php',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($clientFailures);
    }

    public function test_result_gate_accepts_a_complete_passing_matrix(): void
    {
        $evaluation = SchedulesRuntimeResultGate::evaluate($this->completeSchedulesResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_accepts_ghcr_server_image_sources(): void
    {
        $result = $this->completeSchedulesResult();
        $source = 'docker://ghcr.io/durable-workflow/server:0.2.174';
        $result['artifact_sources']['server'] = $source;
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['server'] =
            $source;
        $result['artifact_install_evidence']['artifacts'][0]['source'] = $source;
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']['artifacts'][0]['source'] =
            $source;

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_requires_cli_schedule_command_transcripts_for_cli_surface_pass(): void
    {
        $result = $this->completeSchedulesResult();
        unset($result['scenario_results']['cli_schedule_surface']['observed_outputs']['command_outputs']['trigger']);
        unset($result['client_surfaces']['cli']['command_outputs']['trigger']);

        $evaluation = SchedulesRuntimeResultGate::evaluate($result);
        $transcriptFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_cli_schedule_command_transcript'
                && ($failure['operation'] ?? null) === 'trigger',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($transcriptFailures);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeSchedulesResult(): array
    {
        $artifactSources = [
            'server' => 'docker://durableworkflow/server:0.2.174',
            'cli' => 'https://github.com/durable-workflow/cli/releases/download/0.1.56/dw.phar',
            'sdk-python' => 'pypi://durable-workflow==0.4.74',
            'sdk-php' => 'packagist://durable-workflow/sdk@0.1.1',
            'waterline' => 'packagist://durable-workflow/waterline@2.0.0-alpha.57',
        ];
        $cronCadence = [
            'actual_fire_timestamps' => [
                '2026-05-24T05:00:00Z',
                '2026-05-24T05:01:00Z',
                '2026-05-24T05:02:00Z',
                '2026-05-24T05:03:00Z',
            ],
            'nominal_fire_timestamps' => [
                '2026-05-24T05:00:00Z',
                '2026-05-24T05:01:00Z',
                '2026-05-24T05:02:00Z',
                '2026-05-24T05:03:00Z',
            ],
            'drift_ms' => [0, 20, 30, 10],
        ];
        $fixedRateCadence = [
            'actual_fire_timestamps' => [
                '2026-05-24T05:00:00Z',
                '2026-05-24T05:00:30Z',
                '2026-05-24T05:01:00Z',
                '2026-05-24T05:01:30Z',
                '2026-05-24T05:02:00Z',
                '2026-05-24T05:02:30Z',
                '2026-05-24T05:03:00Z',
                '2026-05-24T05:03:30Z',
            ],
            'nominal_fire_timestamps' => [
                '2026-05-24T05:00:00Z',
                '2026-05-24T05:00:30Z',
                '2026-05-24T05:01:00Z',
                '2026-05-24T05:01:30Z',
                '2026-05-24T05:02:00Z',
                '2026-05-24T05:02:30Z',
                '2026-05-24T05:03:00Z',
                '2026-05-24T05:03:30Z',
            ],
            'drift_ms' => [0, 15, 12, 18, 20, 16, 14, 19],
        ];
        $listDescribe = [
            'cli_list_observed' => true,
            'sdk_list_observed' => true,
            'last_fire_at_observed' => true,
            'next_fire_at_observed' => true,
            'pause_state_observed' => true,
        ];
        $pauseResume = [
            'fires_during_pause_count' => 0,
            'resumed_after_pause' => true,
        ];
        $delete = [
            'absent_from_list_after_delete' => true,
            'no_fires_after_delete' => true,
        ];
        $missedFirePolicy = [
            'documented_policy' => 'fire_once_on_resume_then_skip_remaining_missed',
            'observed_policy' => 'fire_once_on_resume_then_skip_remaining_missed',
            'catchup_fire_count' => 1,
            'post_resume_normal_fire_observed' => true,
            'scheduler_stop_confirmed' => true,
            'fires_during_scheduler_outage_count' => 0,
            'stored_overdue_occurrence_elapsed_during_outage' => true,
        ];
        $restartSurvival = [
            'schedule_listed_after_restart' => true,
            'fired_after_restart' => true,
        ];
        $cliTranscript = static fn (string $operation): array => [
            'command' => ['dw', 'schedules', $operation, '--json'],
            'exit_code' => 0,
            'stdout' => '{"schedule_id":"cli-surface-schedule"}'."\n",
            'stderr' => '',
        ];
        $cliCommandOutputs = [
            'create' => $cliTranscript('create'),
            'list' => [
                'command' => ['dw', 'schedules', 'list', '--json'],
                'exit_code' => 0,
                'stdout' => '{"schedules":[{"schedule_id":"cli-surface-schedule"}]}'."\n",
                'stderr' => '',
            ],
            'describe' => $cliTranscript('describe'),
            'pause' => $cliTranscript('pause'),
            'resume' => $cliTranscript('resume'),
            'trigger' => [
                'command' => ['dw', 'schedules', 'trigger', '--json'],
                'exit_code' => 0,
                'stdout' => '{"schedule_id":"cli-surface-schedule","outcome":"started"}'."\n",
                'stderr' => '',
            ],
            'delete' => $cliTranscript('delete'),
        ];
        $clientSurfaces = [
            'cli' => [
                'create_or_observe' => true,
                'list_observed' => true,
                'control_observed' => true,
                'command_outputs' => $cliCommandOutputs,
            ],
            'sdk-python' => [
                'create_or_observe' => true,
                'list_observed' => true,
                'control_observed' => true,
            ],
            'sdk-php' => [
                'create_or_observe' => true,
                'list_observed' => true,
                'control_observed' => true,
            ],
        ];
        $pythonCreatedPhp = [
            'schedule_creator' => 'sdk-python',
            'workflow_runtime' => 'sdk-php',
            'schedule_visible_in_cli' => true,
            'workflow_completed' => true,
        ];
        $phpCreatedPython = [
            'schedule_creator' => 'sdk-php',
            'workflow_runtime' => 'sdk-python',
            'schedule_visible_in_cli' => true,
            'workflow_completed' => true,
        ];
        $invalidCron = [
            'refused' => true,
            'typed_error' => true,
            'persisted' => false,
        ];
        $nonexistentWorkflow = [
            'behavior' => 'fails_at_fire_time',
            'operator_visible_failure' => true,
        ];
        $scenarioResults = [
            'published_artifact_install_only' => [
                'status' => 'pass',
                'observed_outputs' => [
                    'resolved_artifact_versions' => [
                        'server' => '0.2.174',
                        'cli' => '0.1.56',
                        'sdk-python' => '0.4.74',
                        'sdk-php' => '0.1.1',
                        'waterline' => '2.0.0-alpha.57',
                    ],
                    'artifact_sources' => $artifactSources,
                    'local_product_source_checkouts_used' => false,
                    'artifact_install_evidence' => [
                        'local_product_source_checkouts_used' => false,
                        'artifacts' => [
                            ['artifact' => 'server', 'version' => '0.2.174', 'source' => $artifactSources['server'], 'status' => 'pass'],
                            ['artifact' => 'cli', 'version' => '0.1.56', 'source' => $artifactSources['cli'], 'status' => 'pass'],
                            ['artifact' => 'sdk-python', 'version' => '0.4.74', 'source' => $artifactSources['sdk-python'], 'status' => 'pass'],
                            ['artifact' => 'sdk-php', 'version' => '0.1.1', 'source' => $artifactSources['sdk-php'], 'status' => 'pass'],
                            ['artifact' => 'waterline', 'version' => '2.0.0-alpha.57', 'source' => $artifactSources['waterline'], 'status' => 'pass'],
                        ],
                    ],
                ],
            ],
            'cron_cadence' => ['status' => 'pass', 'observed_outputs' => $cronCadence],
            'fixed_rate_cadence' => ['status' => 'pass', 'observed_outputs' => $fixedRateCadence],
            'list_describe_visibility' => ['status' => 'pass', 'observed_outputs' => $listDescribe],
            'pause_resume_no_fire_window' => ['status' => 'pass', 'observed_outputs' => $pauseResume],
            'delete_stops_future_fires' => ['status' => 'pass', 'observed_outputs' => $delete],
            'missed_fire_policy' => ['status' => 'pass', 'observed_outputs' => $missedFirePolicy],
            'restart_survival' => ['status' => 'pass', 'observed_outputs' => $restartSurvival],
            'cli_schedule_surface' => ['status' => 'pass', 'observed_outputs' => $clientSurfaces['cli']],
            'python_sdk_schedule_surface' => ['status' => 'pass', 'observed_outputs' => $clientSurfaces['sdk-python']],
            'php_schedule_surface' => ['status' => 'pass', 'observed_outputs' => $clientSurfaces['sdk-php']],
            'python_created_php_workflow' => ['status' => 'pass', 'observed_outputs' => $pythonCreatedPhp],
            'php_created_python_workflow' => ['status' => 'pass', 'observed_outputs' => $phpCreatedPython],
            'invalid_cron_refusal' => ['status' => 'pass', 'observed_outputs' => $invalidCron],
            'nonexistent_workflow_type_outcome' => ['status' => 'pass', 'observed_outputs' => $nonexistentWorkflow],
        ];

        return [
            'schema' => SchedulesRuntimeContract::RESULT_SCHEMA,
            'outcome' => 'pass',
            'started_at' => '2026-05-24T05:00:00Z',
            'finished_at' => '2026-05-24T05:08:00Z',
            'generated_at' => '2026-05-24T05:08:01Z',
            'artifactVersions' => [
                'server' => '0.2.174',
                'cli' => '0.1.56',
                'sdk-python' => '0.4.74',
                'sdk-php' => '0.1.1',
                'waterline' => '2.0.0-alpha.57',
            ],
            'artifact_sources' => $artifactSources,
            'local_product_source_checkouts_used' => false,
            'artifact_install_evidence' => $scenarioResults['published_artifact_install_only']['observed_outputs']['artifact_install_evidence'],
            'topology' => [
                'namespace' => 'schedules-conformance',
                'task_queue' => 'schedules-shared',
            ],
            'runtime_matrix' => [
                'runtimes' => ['sdk-php', 'sdk-python'],
                'client_paths' => ['cli', 'sdk-python', 'sdk-php'],
                'schedule_types' => ['cron_expression', 'fixed_rate_interval'],
                'cross_language_cells' => [
                    [
                        'scenario' => 'python_created_php_workflow',
                        'schedule_creator' => 'sdk-python',
                        'workflow_runtime' => 'sdk-php',
                    ],
                    [
                        'scenario' => 'php_created_python_workflow',
                        'schedule_creator' => 'sdk-php',
                        'workflow_runtime' => 'sdk-python',
                    ],
                ],
            ],
            'cadence_observations' => [
                'cron' => $cronCadence,
                'fixed_rate' => $fixedRateCadence,
            ],
            'operator_controls' => [
                'list_describe' => $listDescribe,
                'pause_resume' => $pauseResume,
                'delete' => $delete,
            ],
            'missed_fire_policy' => $missedFirePolicy,
            'restart_survival' => $restartSurvival,
            'client_surfaces' => $clientSurfaces,
            'cross_language_matrix' => [
                'cross_language_cells' => [
                    $pythonCreatedPhp,
                    $phpCreatedPython,
                ],
            ],
            'adversarial_outcomes' => [
                'invalid_cron' => $invalidCron,
                'nonexistent_workflow_type' => $nonexistentWorkflow,
            ],
            'scenario_results' => $scenarioResults,
            'findings' => [
                'summary' => 'passing run',
            ],
            'finding_links' => [
                'all' => ['not_applicable' => 'passing run'],
            ],
        ];
    }
}
