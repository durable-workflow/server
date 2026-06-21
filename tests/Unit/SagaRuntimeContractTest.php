<?php

namespace Tests\Unit;

use App\Support\SagaRuntimeContract;
use App\Support\SagaRuntimeResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class SagaRuntimeContractTest extends TestCase
{
    public function test_manifest_names_published_artifact_runner_handoff(): void
    {
        $manifest = SagaRuntimeContract::manifest();

        $this->assertSame('durable-workflow.v2.saga-runtime.contract', $manifest['schema']);
        $this->assertSame(1, SagaRuntimeContract::VERSION);
        $this->assertSame(SagaRuntimeContract::VERSION, $manifest['version']);
        $this->assertSame('durable-workflow.v2.saga-runtime-conformance.result', $manifest['result_schema']);
        $this->assertSame('saga_runtime_contract', $manifest['fixture_category']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            PlatformConformanceSuite::VERSION,
            $manifest['scenario_manifest']['suite_version'],
        );
        $this->assertSame(
            'scripts/conformance/sagas-published-artifacts.sh',
            $manifest['host_runner_contract']['runner_path'],
        );
        $this->assertTrue($manifest['host_runner_contract']['must_execute_against_published_artifacts']);
        $this->assertTrue($manifest['host_runner_contract']['pass_record_requires_zero_exit_status']);
        $this->assertSame('error', $manifest['host_runner_contract']['nonzero_runner_exit_record_outcome']);
        $this->assertTrue($manifest['host_runner_contract']['must_record_runner_blocked_false_for_product_evidence']);
        $this->assertTrue($manifest['artifact_policy']['release_records_without_assets_are_rejected']);

        foreach ([
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
            'runtime_matrix',
            'side_store_deltas',
            'history_dumps',
            'worker_restart_observations',
            'operator_visibility_snapshots',
            'cross_language_matrix',
            'typed_error_shapes',
        ] as $field) {
            $this->assertContains($field, $manifest['artifact_policy']['required_run_record_fields']);
        }

        foreach (['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }
        $this->assertSame(
            ['workflow'],
            $manifest['artifact_policy']['release_artifact_aliases']['workflow-php'],
        );
    }

    public function test_manifest_names_full_saga_matrix(): void
    {
        $manifest = SagaRuntimeContract::manifest();

        $this->assertContains('workflow-php', $manifest['required_matrix']['workflow_runtimes']);
        $this->assertContains('sdk-python', $manifest['required_matrix']['workflow_runtimes']);
        $this->assertContains('workflow-php', $manifest['required_matrix']['activity_runtimes']);
        $this->assertContains('sdk-python', $manifest['required_matrix']['activity_runtimes']);
        $this->assertContains('later_step_failure', $manifest['required_matrix']['failure_modes']);
        $this->assertContains('worker_restart_mid_compensation', $manifest['required_matrix']['failure_modes']);
        $this->assertContains('php_workflow_python_compensation', $manifest['required_scenarios']);
        $this->assertContains('python_workflow_php_compensation', $manifest['required_scenarios']);
        $this->assertContains('typed_compensation_error_round_trip', $manifest['required_scenarios']);
        $this->assertContains('operator_visible_mid_compensation_status', $manifest['required_scenarios']);
        $this->assertSame(
            $manifest['required_scenarios'],
            array_keys($manifest['scenario_requirements']),
            'every required saga scenario must declare scenario-specific evidence fields',
        );
        $this->assertContains(
            'runner_blocked_false_for_product_evidence',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'runner_exit_status_zero_for_passing_record',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'operator_visibility_surfaces_reported_including_waterline',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertSame(
            'non_passing_waterline_finding_required',
            $manifest['coverage_gate']['waterline_operator_visibility_gap_outcome'],
        );
        $this->assertSame(
            'fail',
            $manifest['host_runner_contract']['routing_policy']['waterline_operator_visibility_failure']['scenario_status'],
        );
    }

    public function test_scenario_manifest_source_path_is_published_and_matches_contract(): void
    {
        $manifest = SagaRuntimeContract::manifest();
        $scenarioManifestPath = dirname(__DIR__, 2) . '/' . $manifest['scenario_manifest']['source_path'];

        $this->assertFileExists(
            $scenarioManifestPath,
            'cluster info must not advertise a saga scenario manifest source path that is missing from the release tree',
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
            'public saga scenario manifest must declare the same scenario required-field keys as cluster info',
        );

        foreach ($manifest['scenario_requirements'] as $scenarioId => $requirements) {
            $this->assertSame(
                $requirements['required_fields'],
                $scenarioManifest['scenario_requirements'][$scenarioId]['required_fields'],
                sprintf('scenario manifest required fields drifted for %s', $scenarioId),
            );
            $this->assertSame(
                $requirements['expected_behavior'],
                $scenarioManifest['scenario_requirements'][$scenarioId]['expected_behavior'],
                sprintf('scenario manifest expected behavior drifted for %s', $scenarioId),
            );
        }

        $this->assertTrue($scenarioManifest['artifact_policy']['published_artifacts_only']);
        $this->assertTrue($scenarioManifest['artifact_policy']['requires_downloadable_release_assets']);
        $this->assertTrue($scenarioManifest['artifact_policy']['rejects_placeholder_versions']);
        $this->assertTrue($scenarioManifest['artifact_policy']['requires_artifact_sources_for_each_required_artifact']);
        $this->assertTrue($scenarioManifest['artifact_policy']['requires_local_product_source_checkouts_used_false']);
        $this->assertSame(
            $manifest['artifact_policy']['release_artifact_aliases'],
            $scenarioManifest['artifact_policy']['release_artifact_aliases'],
            'public saga scenario manifest must advertise the same release artifact aliases as cluster info',
        );
        $this->assertSame(
            $manifest['host_runner_contract']['runner_path'],
            $scenarioManifest['host_runner_contract']['runner_path'],
        );
        $this->assertSame(
            $manifest['host_runner_contract']['pass_record_requires_zero_exit_status'],
            $scenarioManifest['host_runner_contract']['pass_record_requires_zero_exit_status'],
        );
        $this->assertSame(
            $manifest['host_runner_contract']['nonzero_runner_exit_record_outcome'],
            $scenarioManifest['host_runner_contract']['nonzero_runner_exit_record_outcome'],
        );
        $this->assertSame(
            $manifest['host_runner_contract']['required_execution_scopes'],
            $scenarioManifest['host_runner_contract']['required_execution_scopes'],
        );
        $this->assertSame(
            $manifest['host_runner_contract']['routing_policy']['waterline_operator_visibility_failure'],
            $scenarioManifest['host_runner_contract']['routing_policy']['waterline_operator_visibility_failure'],
            'public saga scenario manifest must advertise the same Waterline operator failure routing as cluster info',
        );

        $this->assertContains('worker_restart_observations', $scenarioManifest['common_result_evidence']);
        $this->assertContains('runner_exit_status', $scenarioManifest['common_result_evidence']);
    }

    public function test_result_gate_keeps_runner_blocked_evidence_non_passing(): void
    {
        $result = $this->completeSagaResult();
        $result['outcome'] = 'error';
        $result['runner_blocked'] = true;
        foreach ($result['scenario_results'] as $scenarioId => &$scenario) {
            $scenario['status'] = 'runner_blocked';
            $scenario['findings'] = [
                $this->structuredSagaFinding(
                    $scenarioId,
                    'host runner did not execute this scenario',
                    'conformance_harness',
                ),
            ];
        }
        unset($scenario);

        $evaluation = SagaRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('forward_success_path', $evaluation['non_pass_scenarios']);
        $this->assertContains(
            'runner_blocked_result_is_not_product_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_conflicting_declared_outcome_aliases(): void
    {
        $result = $this->completeSagaResult();
        $result['status'] = 'fail';
        $result['verdict'] = 'pass';

        $evaluation = SagaRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');
        $aliasFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'conflicting_outcome_tokens',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('declared_outcome_status_mismatch', $failureCodes);
        $this->assertContains('conflicting_outcome_tokens', $failureCodes);
        $this->assertCount(1, $aliasFailures);
        $this->assertSame([
            'outcome' => 'pass',
            'status' => 'fail',
            'verdict' => 'pass',
        ], $aliasFailures[0]['declared_outcomes']);
    }

    public function test_result_gate_rejects_conflicting_status_and_verdict_aliases_when_outcome_is_empty(): void
    {
        $result = $this->completeSagaResult();
        unset($result['outcome']);
        $result['status'] = 'fail';
        $result['verdict'] = 'pass';

        $evaluation = SagaRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');
        $aliasFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'conflicting_outcome_tokens',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('declared_outcome_status_mismatch', $failureCodes);
        $this->assertContains('conflicting_outcome_tokens', $failureCodes);
        $this->assertCount(1, $aliasFailures);
        $this->assertSame([
            'status' => 'fail',
            'verdict' => 'pass',
        ], $aliasFailures[0]['declared_outcomes']);
    }

    public function test_result_gate_rejects_unstructured_non_pass_findings(): void
    {
        $result = $this->completeSagaResult();
        $result['outcome'] = 'fail';
        $result['scenario_results']['compensation_retry_idempotence']['status'] = 'fail';
        $result['scenario_results']['compensation_retry_idempotence']['findings'] = [
            'compensation retry applied the business effect twice',
        ];

        $evaluation = SagaRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'unstructured_non_pass_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_accepts_structured_non_pass_findings(): void
    {
        $result = $this->completeSagaResult();
        $result['outcome'] = 'fail';
        $result['scenario_results']['compensation_retry_idempotence']['status'] = 'fail';
        $result['scenario_results']['compensation_retry_idempotence']['findings'] = [
            $this->structuredSagaFinding(
                'compensation_retry_idempotence',
                'compensation retry applied the business effect twice',
                'workflow_or_sdk-python_activity_contract',
            ),
        ];

        $evaluation = SagaRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('compensation_retry_idempotence', $evaluation['non_pass_scenarios']);
        $this->assertNotContains('missing_non_pass_finding', $failureCodes);
        $this->assertNotContains('unstructured_non_pass_finding', $failureCodes);
        $this->assertNotContains('missing_non_pass_finding_field', $failureCodes);
    }

    public function test_result_gate_treats_nonzero_runner_exit_record_as_non_passing_without_erasing_scenario_evidence(): void
    {
        $result = $this->completeSagaResult();
        $result['outcome'] = 'error';
        $result['runner_exit_status'] = 1;
        $result['findings'] = [
            [
                'id' => 'sagas-runner-exit-status-mismatch',
                'severity' => 'P0',
                'surface' => 'conformance-runner',
                'scenario_id' => 'runner_exit_status',
                'owning_surface' => 'conformance_harness',
                'artifact_versions' => $result['published_artifact_versions'],
                'observed_behavior' => 'The runner process exited with status 1 while sagas-result.json declared outcome=pass.',
                'expected_behavior' => 'A sagas conformance record declares outcome=pass only when the final runner process exit status is 0.',
                'next_acceptance_criterion' => 'make the runner exit path agree with the recorded outcome',
            ],
        ];

        $evaluation = SagaRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame('pass', $evaluation['scenario_statuses']['operator_visible_mid_compensation_status']);
        $this->assertContains('runner_exit_status_nonzero', $failureCodes);
        $this->assertNotContains('declared_outcome_status_mismatch', $failureCodes);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
    }

    public function test_result_gate_requires_zero_runner_exit_status_for_passing_record(): void
    {
        $result = $this->completeSagaResult();
        unset($result['runner_exit_status']);

        $evaluation = SagaRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_runner_exit_status', $failureCodes);
        $this->assertContains('declared_outcome_status_mismatch', $failureCodes);
    }

    public function test_result_gate_requires_scenario_specific_evidence(): void
    {
        $result = $this->completeSagaResult();
        unset($result['scenario_results']['typed_compensation_error_round_trip']['observed_error_type']);

        $evaluation = SagaRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_scenario_required_field',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_explicit_published_resolved_pins_and_runner_blocked_field(): void
    {
        $result = $this->completeSagaResult();
        $result['artifact_versions'] = $result['published_artifact_versions'];
        unset($result['published_artifact_versions'], $result['resolved_artifact_versions'], $result['runner_blocked']);

        $evaluation = SagaRuntimeResultGate::evaluate($result);
        $missingFields = $this->missingRunRecordFields($evaluation);

        $this->assertSame('non_passing', $evaluation['status']);
        foreach (['published_artifact_versions', 'resolved_artifact_versions', 'runner_blocked'] as $field) {
            $this->assertContains($field, $missingFields);
        }
    }

    public function test_result_gate_validates_each_required_artifact_pin_set(): void
    {
        $result = $this->completeSagaResult();
        $result['resolved_artifact_versions']['workflow-php'] = '2.0.0-alpha.<latest>';
        unset($result['published_artifact_versions']['waterline']);

        $evaluation = SagaRuntimeResultGate::evaluate($result);
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
                'field' => 'published_artifact_versions',
                'artifact' => 'waterline',
            ],
            $artifactFailures,
        );
        $this->assertContains(
            [
                'code' => 'placeholder_artifact_version',
                'field' => 'resolved_artifact_versions',
                'artifact' => 'workflow-php',
                'version' => '2.0.0-alpha.<latest>',
            ],
            $artifactFailures,
        );
    }

    public function test_result_gate_rejects_forbidden_artifact_sources(): void
    {
        $result = $this->completeSagaResult();
        $result['artifact_sources'] = [
            'server' => 'docker',
            'workflow-php' => 'workspace_repo_as_artifact_under_test',
        ];
        $result['scenario_results']['published_artifact_install_only']['observed_outputs'] = [
            'artifact_sources' => [
                'sdk-python' => 'local_product_source_checkout/workspace_repo_as_artifact_under_test',
            ],
        ];

        $evaluation = SagaRuntimeResultGate::evaluate($result);
        $sourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_artifact_source',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            [
                'code' => 'forbidden_artifact_source',
                'artifact' => 'workflow-php',
                'source' => 'workspace_repo_as_artifact_under_test',
                'field' => 'artifact_sources',
            ],
            $sourceFailures,
        );
        $this->assertContains(
            [
                'code' => 'forbidden_artifact_source',
                'artifact' => 'sdk-python',
                'source' => 'local_product_source_checkout/workspace_repo_as_artifact_under_test',
                'field' => 'artifact_sources',
                'scenario_id' => 'published_artifact_install_only',
            ],
            $sourceFailures,
        );
    }

    public function test_result_gate_requires_each_published_artifact_install_source(): void
    {
        $result = $this->completeSagaResult();
        $result['scenario_results']['published_artifact_install_only']['artifact_sources'] = [
            'server' => 'published_docker_image',
        ];

        $evaluation = SagaRuntimeResultGate::evaluate($result);
        $missingSourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_published_artifact_install_source',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        foreach (['cli', 'workflow-php', 'sdk-python', 'waterline'] as $artifact) {
            $this->assertContains(
                [
                    'code' => 'missing_published_artifact_install_source',
                    'scenario_id' => 'published_artifact_install_only',
                    'artifact' => $artifact,
                ],
                $missingSourceFailures,
            );
        }
    }

    public function test_result_gate_requires_explicit_false_local_source_checkout_flag(): void
    {
        $result = $this->completeSagaResult();
        unset($result['scenario_results']['published_artifact_install_only']['local_product_source_checkouts_used']);

        $evaluation = SagaRuntimeResultGate::evaluate($result);
        $localCheckoutFlagFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_checkouts_used_must_be_false',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            [
                'code' => 'local_product_source_checkouts_used_must_be_false',
                'scenario_id' => 'published_artifact_install_only',
                'field' => 'local_product_source_checkouts_used',
                'value' => null,
            ],
            $localCheckoutFlagFailures,
        );
    }

    public function test_result_gate_rejects_local_source_checkout_flag_from_observed_outputs(): void
    {
        $result = $this->completeSagaResult();
        $result['scenario_results']['published_artifact_install_only']['observed_outputs'] = [
            'local_product_source_checkouts_used' => true,
        ];

        $evaluation = SagaRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'local_product_source_checkout_used',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_accepts_workflow_alias_for_php_artifact_pin(): void
    {
        $result = $this->completeSagaResult();
        foreach (['published_artifact_versions', 'resolved_artifact_versions'] as $field) {
            $result[$field]['workflow'] = $result[$field]['workflow-php'];
            unset($result[$field]['workflow-php']);
        }
        $result['scenario_results']['published_artifact_install_only']['resolved_artifact_versions']['workflow'] =
            $result['scenario_results']['published_artifact_install_only']['resolved_artifact_versions']['workflow-php'];
        unset($result['scenario_results']['published_artifact_install_only']['resolved_artifact_versions']['workflow-php']);

        $evaluation = SagaRuntimeResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
    }

    public function test_result_gate_accepts_complete_saga_evidence(): void
    {
        $evaluation = SagaRuntimeResultGate::evaluate($this->completeSagaResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    /**
     * @param array<string, mixed> $evaluation
     *
     * @return list<string>
     */
    private function missingRunRecordFields(array $evaluation): array
    {
        $fields = [];
        foreach ($evaluation['gate_failures'] ?? [] as $failure) {
            if (! is_array($failure) || ($failure['code'] ?? null) !== 'missing_run_record_field') {
                continue;
            }

            $field = $failure['field'] ?? null;
            if (is_string($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredSagaFinding(
        string $scenarioId,
        string $observedBehavior = 'scenario did not meet the saga compensation contract',
        string $owningSurface = 'workflow_or_sdk-python',
    ): array {
        return [
            'scenario_id' => $scenarioId,
            'owning_surface' => $owningSurface,
            'artifact_versions' => [
                'server' => '0.2.186',
                'cli' => '0.1.67',
                'workflow-php' => '2.0.0-alpha.177',
                'sdk-python' => '0.4.78',
                'waterline' => '2.0.0-alpha.61',
            ],
            'observed_behavior' => $observedBehavior,
            'expected_behavior' => 'the scenario produces the saga compensation evidence required by the public contract',
            'next_acceptance_criterion' => 're-run this scenario against the complete published artifact set and record passing evidence',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completeSagaResult(): array
    {
        return [
            'schema' => SagaRuntimeContract::RESULT_SCHEMA,
            'schema_version' => SagaRuntimeContract::RESULT_VERSION,
            'suite_schema' => PlatformConformanceSuite::SCHEMA,
            'suite_version' => PlatformConformanceSuite::VERSION,
            'category' => 'saga_runtime_contract',
            'outcome' => 'pass',
            'runner_blocked' => false,
            'runner_exit_status' => 0,
            'started_at' => '2026-05-24T21:00:00Z',
            'finished_at' => '2026-05-24T21:05:00Z',
            'generated_at' => '2026-05-24T21:05:00Z',
            'published_artifact_versions' => [
                'server' => '0.2.186',
                'cli' => '0.1.67',
                'workflow-php' => '2.0.0-alpha.177',
                'sdk-python' => '0.4.78',
                'waterline' => '2.0.0-alpha.61',
            ],
            'resolved_artifact_versions' => [
                'server' => '0.2.186',
                'cli' => '0.1.67',
                'workflow-php' => '2.0.0-alpha.177',
                'sdk-python' => '0.4.78',
                'waterline' => '2.0.0-alpha.61',
            ],
            'topology' => ['server' => 'published Docker image'],
            'runtime_matrix' => [
                'workflow_runtimes' => ['workflow-php', 'sdk-python'],
                'activity_runtimes' => ['workflow-php', 'sdk-python'],
                'cross_language_cells' => [
                    'php_workflow_python_compensation',
                    'python_workflow_php_compensation',
                ],
            ],
            'side_store_deltas' => [['scenario_id' => 'forward_success_path']],
            'history_dumps' => ['forward_success_path' => ['events' => []]],
            'worker_restart_observations' => [['resumed_compensation_step' => 'cancel_hotel']],
            'operator_visibility_snapshots' => ['cli' => ['workflow_show' => ['ok' => true]]],
            'cross_language_matrix' => [['workflow_runtime' => 'workflow-php', 'compensation_runtime' => 'sdk-python']],
            'typed_error_shapes' => [['observed_error_type' => 'TypedCancelFlightError']],
            'findings' => [],
            'scenario_results' => [
                'published_artifact_install_only' => [
                    'status' => 'pass',
                    'resolved_artifact_versions' => [
                        'server' => '0.2.186',
                        'cli' => '0.1.67',
                        'workflow-php' => '2.0.0-alpha.177',
                        'sdk-python' => '0.4.78',
                        'waterline' => '2.0.0-alpha.61',
                    ],
                    'artifact_sources' => [
                        'server' => 'published_docker_image',
                        'cli' => 'published_cli_release_asset',
                        'workflow-php' => 'published_composer_package',
                        'sdk-python' => 'published_pypi_release',
                        'waterline' => 'published_composer_package',
                    ],
                    'local_product_source_checkouts_used' => false,
                ],
                'forward_success_path' => [
                    'status' => 'pass',
                    'forward_rows' => ['reserve_flight', 'reserve_hotel', 'charge_card', 'send_confirmation'],
                    'compensation_rows' => [],
                    'workflow_status' => ['status' => 'completed'],
                    'history_dumps' => ['events' => []],
                ],
                'failure_at_d_reverse_compensation' => [
                    'status' => 'pass',
                    'forward_rows' => ['reserve_flight', 'reserve_hotel', 'charge_card'],
                    'compensation_rows' => ['refund_card', 'cancel_hotel', 'cancel_flight'],
                    'compensation_order' => ['refund_card', 'cancel_hotel', 'cancel_flight'],
                    'workflow_status' => ['status' => 'completed'],
                    'history_dumps' => ['events' => []],
                ],
                'failure_at_c_reverse_compensation' => [
                    'status' => 'pass',
                    'forward_rows' => ['reserve_flight', 'reserve_hotel'],
                    'compensation_rows' => ['cancel_hotel', 'cancel_flight'],
                    'compensation_order' => ['cancel_hotel', 'cancel_flight'],
                    'send_confirmation_invocations' => 0,
                    'workflow_status' => ['status' => 'completed'],
                ],
                'failure_at_a_no_compensation' => [
                    'status' => 'pass',
                    'forward_rows' => [],
                    'compensation_rows' => [],
                    'workflow_status' => ['status' => 'failed'],
                ],
                'compensation_retry_idempotence' => [
                    'status' => 'pass',
                    'retry_attempts' => 2,
                    'business_effect_count' => 1,
                    'workflow_status' => ['status' => 'completed'],
                ],
                'compensation_failure_visibility' => [
                    'status' => 'pass',
                    'failed_compensation_step' => 'cancel_flight',
                    'terminal_failure_shape' => ['error_type' => 'TypedCancelFlightError'],
                    'operator_visible_reason' => ['message' => 'compensation failed for cancel_flight'],
                    'workflow_status' => ['status' => 'failed'],
                ],
                'mid_compensation_worker_restart' => [
                    'status' => 'pass',
                    'restart_timing' => ['stopped_at' => '2026-05-24T21:01:00Z'],
                    'resumed_compensation_step' => 'cancel_hotel',
                    'duplicate_compensation_counts' => ['refund_card' => 1, 'cancel_hotel' => 1],
                    'history_dumps' => ['events' => []],
                ],
                'php_workflow_python_compensation' => [
                    'status' => 'pass',
                    'workflow_runtime' => 'workflow-php',
                    'compensation_runtime' => 'sdk-python',
                    'compensation_order' => ['refund_card', 'cancel_hotel', 'cancel_flight'],
                    'typed_result_shapes' => [['runtime' => 'sdk-python']],
                ],
                'python_workflow_php_compensation' => [
                    'status' => 'pass',
                    'workflow_runtime' => 'sdk-python',
                    'compensation_runtime' => 'workflow-php',
                    'compensation_order' => ['refund_card', 'cancel_hotel', 'cancel_flight'],
                    'typed_result_shapes' => [['runtime' => 'workflow-php']],
                ],
                'typed_compensation_error_round_trip' => [
                    'status' => 'pass',
                    'raised_error_type' => 'TypedCancelFlightError',
                    'observed_error_type' => 'TypedCancelFlightError',
                    'observed_error_message' => 'cancel_flight typed compensation failure',
                    'terminal_failure_shape' => ['error_type' => 'TypedCancelFlightError'],
                ],
                'operator_visible_mid_compensation_status' => [
                    'status' => 'pass',
                    'completed_forward_steps' => ['reserve_flight', 'reserve_hotel', 'charge_card'],
                    'running_compensation_step' => 'pause_after_refund',
                    'completed_compensations' => ['refund_card'],
                    'pending_compensations' => ['cancel_hotel', 'cancel_flight'],
                    'failed_compensations' => [],
                    'operator_visibility_snapshots' => [
                        'cli' => ['workflow_show' => ['ok' => true]],
                        'waterline' => ['ok' => true, 'current_compensation_marker' => 'pause_after_refund'],
                    ],
                    'waterline_operator_evidence' => [
                        'ok' => true,
                        'workflow_id' => 'sagas-python-operator_visible_mid_compensation_status',
                        'run_id' => 'run-operator-visible-mid-compensation-status',
                        'observed_workflow_id' => 'sagas-python-operator_visible_mid_compensation_status',
                        'observed_run_id' => 'run-operator-visible-mid-compensation-status',
                        'visible_status' => 'running',
                        'current_compensation_marker' => 'pause_after_refund',
                        'captures' => [
                            'selected_run_detail' => ['ok' => true],
                            'running_list' => ['ok' => true],
                        ],
                    ],
                ],
            ],
        ];
    }
}
