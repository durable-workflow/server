<?php

namespace Tests\Unit;

use App\Support\HeartbeatRuntimeContract;
use App\Support\HeartbeatRuntimeResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class HeartbeatRuntimeContractTest extends TestCase
{
    public function test_manifest_requires_published_artifacts_and_run_record_fields(): void
    {
        $manifest = HeartbeatRuntimeContract::manifest();

        $this->assertSame('durable-workflow.v2.heartbeat-runtime.contract', $manifest['schema']);
        $this->assertSame(1, HeartbeatRuntimeContract::VERSION);
        $this->assertSame(HeartbeatRuntimeContract::VERSION, $manifest['version']);
        $this->assertSame('durable-workflow.v2.heartbeat-runtime.result', $manifest['result_schema']);
        $this->assertSame('heartbeat_runtime_contract', $manifest['fixture_category']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            PlatformConformanceSuite::VERSION,
            $manifest['scenario_manifest']['suite_version'],
        );
        $this->assertSame(
            'static/platform-conformance/heartbeat-runtime-scenarios.json',
            $manifest['scenario_manifest']['source_path'],
        );

        foreach (['server', 'cli', 'workflow-php', 'sdk-python', 'sdk-rust', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }

        $this->assertContains(
            'local_product_source_checkout',
            $manifest['artifact_policy']['forbidden_sources'],
        );

        foreach ([
            'artifact_versions',
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
            'cadence_drift_dataset',
            'worker_list_snapshots',
            'heartbeat_shape_diff',
            'stale_transition',
            'routing_exclusion',
            'operator_visibility',
            'adversarial_outcomes',
            'cross_namespace_isolation',
        ] as $field) {
            $this->assertContains($field, $manifest['artifact_policy']['required_run_record_fields']);
        }
    }

    public function test_manifest_names_full_heartbeat_parity_matrix(): void
    {
        $manifest = HeartbeatRuntimeContract::manifest();

        $this->assertContains('workflow-php', $manifest['required_matrix']['runtimes']);
        $this->assertContains('sdk-python', $manifest['required_matrix']['runtimes']);
        $this->assertContains('sdk-rust', $manifest['required_matrix']['runtimes']);
        $this->assertContains('dw worker:list', $manifest['required_matrix']['operator_visibility_paths']);
        $this->assertContains('dw worker:describe', $manifest['required_matrix']['operator_visibility_paths']);
        $this->assertContains('Waterline Worker Status view', $manifest['required_matrix']['operator_visibility_paths']);
        $this->assertContains('stale_workers_excluded_from_workflow_start', $manifest['required_matrix']['routing_cells']);
        $this->assertContains('stale_workers_excluded_from_query_tasks', $manifest['required_matrix']['routing_cells']);
        $this->assertContains('malformed_heartbeat_rejection', $manifest['required_matrix']['adversarial_cells']);
        $this->assertContains('cross_namespace_isolation', $manifest['required_matrix']['adversarial_cells']);

        foreach ([
            'php_sdk_heartbeat_loop',
            'python_sdk_heartbeat_loop',
            'rust_sdk_heartbeat_loop',
            'heartbeat_wire_shape_uniformity',
            'cadence_drift_window',
            'stale_worker_transition_timing',
            'stale_worker_routing_exclusion',
            'waterline_worker_status_visibility',
        ] as $scenario) {
            $this->assertContains($scenario, $manifest['required_scenarios']);
        }

        $this->assertSame(
            $manifest['required_scenarios'],
            array_keys($manifest['scenario_requirements']),
            'every required heartbeat scenario must declare scenario-specific evidence fields',
        );
        $this->assertContains(
            'runner_blocked_false_for_product_evidence',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertSame('non_passing', $manifest['coverage_gate']['smoke_subset_outcome']);
        $this->assertTrue($manifest['coverage_gate']['focused_findings_required_for_uncovered_cells']);
    }

    public function test_scenario_manifest_source_path_is_published_and_matches_contract(): void
    {
        $manifest = HeartbeatRuntimeContract::manifest();
        $scenarioManifestPath = dirname(__DIR__, 2).'/'.$manifest['scenario_manifest']['source_path'];

        $this->assertFileExists(
            $scenarioManifestPath,
            'cluster info must not advertise a heartbeat scenario manifest source path that is missing from the release tree',
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
            'public heartbeat scenario manifest must declare the same scenario required-field keys as cluster info',
        );
        $this->assertSame(
            $manifest['required_matrix'],
            $scenarioManifest['required_matrix'],
            'public heartbeat scenario manifest must advertise the same required matrix as cluster info',
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
        $this->assertTrue($scenarioManifest['artifact_policy']['requires_artifact_sources_for_each_required_artifact']);
        $this->assertTrue($scenarioManifest['artifact_policy']['requires_recognized_published_artifact_sources']);
        $this->assertTrue($scenarioManifest['artifact_policy']['requires_local_product_source_checkouts_used_false']);
        $this->assertTrue($manifest['artifact_policy']['requires_recognized_published_artifact_sources']);
        $this->assertSame(
            $manifest['artifact_policy']['release_artifact_aliases'],
            $scenarioManifest['artifact_policy']['release_artifact_aliases'],
        );
        $this->assertSame(
            $manifest['host_runner_contract'],
            $scenarioManifest['host_runner_contract'],
            'public heartbeat scenario manifest must advertise the same host-runner handoff as cluster info',
        );
    }

    public function test_manifest_publishes_an_enforceable_result_gate(): void
    {
        $resultGate = HeartbeatRuntimeContract::manifest()['result_gate'];

        $this->assertSame(HeartbeatRuntimeResultGate::SCHEMA, $resultGate['schema']);
        $this->assertSame(HeartbeatRuntimeResultGate::VERSION, $resultGate['version']);
        $this->assertSame(
            HeartbeatRuntimeContract::RESULT_SCHEMA,
            $resultGate['evaluates_result_schema'],
        );
        $this->assertContains('scenario_results', $resultGate['scenario_results_fields']);
        $this->assertContains('artifactVersions', $resultGate['artifact_versions_fields']);
        $this->assertContains('published_artifact_versions', $resultGate['artifact_versions_fields']);
        $this->assertSame(['outcome', 'status', 'verdict'], $resultGate['declared_outcome_fields']);
        $this->assertContains(
            'required_php_python_and_rust_workers_are_reported',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'api_cli_and_waterline_operator_visibility_paths_are_reported',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'cadence_stale_routing_restart_adversarial_and_namespace_sections_are_reported',
            $resultGate['pass_requires'],
        );
        $this->assertContains('runner_blocked_false_for_product_evidence', $resultGate['pass_requires']);
        $this->assertContains('artifact_sources_are_recognized_published_channels', $resultGate['pass_requires']);
        $this->assertTrue($resultGate['artifact_version_policy']['requires_recognized_published_artifact_sources']);
        $this->assertContains('smoke_only_results_remain_non_passing', $resultGate['pass_requires']);
        $this->assertSame('non_passing', $resultGate['smoke_subset_outcome']);
    }

    public function test_result_gate_keeps_smoke_only_coverage_non_passing(): void
    {
        $result = $this->completeHeartbeatResult();
        $result['scenario_results'] = array_intersect_key($result['scenario_results'], array_flip([
            'worker_registration_and_ack_metadata',
            'task_slot_and_process_metric_visibility',
            'cli_worker_status_visibility',
        ]));
        $result['outcome'] = 'pass';

        $evaluation = HeartbeatRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertTrue($evaluation['smoke_subset_detected']);
        $this->assertContains('php_sdk_heartbeat_loop', $evaluation['missing_scenarios']);
        $this->assertContains('waterline_worker_status_visibility', $evaluation['missing_scenarios']);
        $this->assertContains('smoke_subset_cannot_pass', array_column($evaluation['gate_failures'], 'code'));
        $this->assertContains('declared_outcome_status_mismatch', array_column($evaluation['gate_failures'], 'code'));
    }

    public function test_result_gate_requires_focused_findings_for_omitted_required_scenarios(): void
    {
        $result = $this->completeHeartbeatResult();
        unset($result['scenario_results']['waterline_worker_status_visibility']);

        $evaluation = HeartbeatRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('waterline_worker_status_visibility', $evaluation['missing_scenarios']);
        $this->assertContains(
            'missing_required_scenario_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );

        $result['finding_links']['waterline_worker_status_visibility'] = [
            $this->structuredHeartbeatFinding(
                'waterline_worker_status_visibility',
                'Waterline worker status shard not yet represented by published-artifact evidence.',
                'waterline',
            ),
        ];

        $evaluationWithFinding = HeartbeatRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluationWithFinding['status']);
        $this->assertNotContains(
            'missing_required_scenario_finding',
            array_column($evaluationWithFinding['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_accepts_complete_published_artifact_matrix(): void
    {
        $evaluation = HeartbeatRuntimeResultGate::evaluate($this->completeHeartbeatResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_rejects_unstructured_sdk_heartbeat_worker_execution_claims(): void
    {
        $result = $this->completeHeartbeatResult();
        $result['scenario_results']['python_sdk_heartbeat_loop']['observed_outputs'][
            'published_artifact_worker_execution'
        ] = 'observed';

        $evaluation = HeartbeatRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'published_artifact_worker_execution_missing'
                && ($failure['scenario_id'] ?? null) === 'python_sdk_heartbeat_loop',
        ));
    }

    public function test_result_gate_enforces_sdk_heartbeat_loop_source_policy(): void
    {
        foreach ([
            'php_sdk_heartbeat_loop' => 'workflow-php',
            'python_sdk_heartbeat_loop' => 'sdk-python',
            'rust_sdk_heartbeat_loop' => 'sdk-rust',
        ] as $scenarioId => $artifact) {
            $result = $this->completeHeartbeatResult();
            $outputs = &$result['scenario_results'][$scenarioId]['observed_outputs'];
            $outputs['local_product_source_checkouts_used'] = true;
            $outputs['artifact_sources'] = [$artifact => 'workspace_repo_as_artifact_under_test'];
            $outputs['published_artifact_worker_execution']['local_product_source_checkouts_used'] = true;
            $outputs['published_artifact_worker_execution']['artifacts'][0]['source'] = 'local_source_checkout';
            $outputs['published_artifact_worker_execution']['artifacts'][0]['local_product_source_checkouts_used'] = true;
            unset($outputs);

            $evaluation = HeartbeatRuntimeResultGate::evaluate($result);
            $failureCodes = array_column($evaluation['gate_failures'], 'code');

            $this->assertSame('non_passing', $evaluation['status'], $scenarioId);
            $this->assertContains('local_product_source_checkouts_used_must_be_false', $failureCodes, $scenarioId);
            $this->assertNotEmpty(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_artifact_source'
                    && ($failure['scenario_id'] ?? null) === $scenarioId,
            ), $scenarioId);
            $this->assertNotEmpty(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_published_artifact_worker_execution_source'
                    && ($failure['scenario_id'] ?? null) === $scenarioId
                    && ($failure['artifact'] ?? null) === $artifact,
            ), $scenarioId);
        }
    }

    public function test_result_gate_rejects_unverified_published_artifact_sources(): void
    {
        $result = $this->completeHeartbeatResult();
        $result['artifact_sources']['server'] = 'ci_cache_image';
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['server'] =
            'ci_cache_image';

        $evaluation = HeartbeatRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'unverified_published_artifact_source'
                && ($failure['artifact'] ?? null) === 'server'
                && ($failure['source'] ?? null) === 'ci_cache_image',
        ));
    }

    public function test_result_gate_rejects_unverified_sdk_worker_execution_sources(): void
    {
        foreach ([
            'php_sdk_heartbeat_loop' => 'workflow-php',
            'python_sdk_heartbeat_loop' => 'sdk-python',
            'rust_sdk_heartbeat_loop' => 'sdk-rust',
        ] as $scenarioId => $artifact) {
            $result = $this->completeHeartbeatResult();
            $result['scenario_results'][$scenarioId]['observed_outputs'][
                'published_artifact_worker_execution'
            ]['artifacts'][0]['source'] = 'ci_cache_package';

            $evaluation = HeartbeatRuntimeResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status'], $scenarioId);
            $this->assertNotEmpty(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'unverified_published_artifact_worker_execution_source'
                    && ($failure['scenario_id'] ?? null) === $scenarioId
                    && ($failure['artifact'] ?? null) === $artifact
                    && ($failure['source'] ?? null) === 'ci_cache_package',
            ), $scenarioId);
        }
    }

    public function test_result_gate_rejects_embedded_placeholder_artifact_versions_and_sources(): void
    {
        $result = $this->completeHeartbeatResult();
        $result['artifact_versions']['server'] = 'durableworkflow/server:latest';
        $result['artifact_sources']['server'] = 'docker://durableworkflow/server:latest';
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['server'] =
            'docker://durableworkflow/server:latest';
        $result['scenario_results']['php_sdk_heartbeat_loop']['observed_outputs'][
            'published_artifact_worker_execution'
        ]['artifacts'][0]['version'] = 'durable-workflow/workflow:latest';

        $evaluation = HeartbeatRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'placeholder_artifact_version'
                && ($failure['artifact'] ?? null) === 'server'
                && ($failure['version'] ?? null) === 'durableworkflow/server:latest',
        ));
        $this->assertNotEmpty(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'placeholder_artifact_source'
                && ($failure['artifact'] ?? null) === 'server'
                && ($failure['source'] ?? null) === 'docker://durableworkflow/server:latest',
        ));
        $this->assertNotEmpty(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'placeholder_published_artifact_worker_execution_version'
                && ($failure['scenario_id'] ?? null) === 'php_sdk_heartbeat_loop'
                && ($failure['artifact'] ?? null) === 'workflow-php'
                && ($failure['version'] ?? null) === 'durable-workflow/workflow:latest',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function completeHeartbeatResult(): array
    {
        $manifest = HeartbeatRuntimeContract::manifest();
        $versions = [
            'server' => '0.2.347',
            'cli' => '0.1.77',
            'workflow' => '2.0.0-alpha.200',
            'sdk-python' => '0.4.85',
            'sdk-rust' => '0.1.0',
            'waterline' => '2.0.0-alpha.83',
        ];
        $sources = [
            'server' => 'published_docker_image',
            'cli' => 'official_install_script',
            'workflow' => 'composer_packagist',
            'sdk-python' => 'pypi',
            'sdk-rust' => 'crates_io',
            'waterline' => 'published_waterline_release',
        ];

        $scenarioResults = [];
        foreach ($manifest['scenario_requirements'] as $scenarioId => $requirements) {
            $scenarioResults[$scenarioId] = [
                'scenario_id' => $scenarioId,
                'status' => 'pass',
                'observed_outputs' => $this->observedOutputsForFields($requirements['required_fields']),
            ];
        }
        $scenarioResults['published_artifact_install_only']['observed_outputs']['artifact_sources'] = $sources;
        $scenarioResults['published_artifact_install_only']['observed_outputs']['resolved_artifact_versions'] = $versions;
        $scenarioResults['published_artifact_install_only']['observed_outputs']['local_product_source_checkouts_used'] = false;
        $scenarioResults['php_sdk_heartbeat_loop']['observed_outputs']['published_artifact_worker_execution'] =
            $this->publishedWorkerExecution('workflow-php', $versions['workflow'], $sources['workflow']);
        $scenarioResults['python_sdk_heartbeat_loop']['observed_outputs']['published_artifact_worker_execution'] =
            $this->publishedWorkerExecution('sdk-python', $versions['sdk-python'], $sources['sdk-python']);
        $scenarioResults['rust_sdk_heartbeat_loop']['observed_outputs']['published_artifact_worker_execution'] =
            $this->publishedWorkerExecution('sdk-rust', $versions['sdk-rust'], $sources['sdk-rust']);

        return [
            'schema' => HeartbeatRuntimeContract::RESULT_SCHEMA,
            'version' => HeartbeatRuntimeContract::RESULT_VERSION,
            'artifact_versions' => $versions,
            'published_artifact_versions' => $versions,
            'resolved_artifact_versions' => $versions,
            'artifact_sources' => $sources,
            'started_at' => '2026-06-05T16:00:00Z',
            'finished_at' => '2026-06-05T16:10:00Z',
            'generated_at' => '2026-06-05T16:10:01Z',
            'outcome' => 'pass',
            'runner_blocked' => false,
            'local_product_source_checkouts_used' => false,
            'scenario_results' => $scenarioResults,
            'findings' => [],
            'finding_links' => ['none' => []],
            'topology' => [
                'namespace' => 'heartbeats-conformance',
                'task_queue' => 'hb-shared',
                'worker_ids' => ['php-worker', 'python-worker', 'rust-worker'],
            ],
            'runtime_matrix' => [
                'runtimes' => $manifest['required_matrix']['runtimes'],
                'client_paths' => $manifest['required_matrix']['client_paths'],
                'operator_visibility_paths' => $manifest['required_matrix']['operator_visibility_paths'],
                'heartbeat_fields' => $manifest['required_matrix']['heartbeat_fields'],
                'routing_cells' => $manifest['required_matrix']['routing_cells'],
                'adversarial_cells' => $manifest['required_matrix']['adversarial_cells'],
            ],
            'cadence_drift_dataset' => ['php-worker' => [60, 61, 59]],
            'worker_list_snapshots' => ['both_up' => ['php-worker', 'python-worker', 'rust-worker']],
            'heartbeat_shape_diff' => ['language_specific_field_diff' => []],
            'stale_transition' => ['stale_after_seconds' => 60, 'observed_seconds' => 63],
            'routing_exclusion' => ['stale_worker_claim_count' => 0],
            'operator_visibility' => ['api' => true, 'cli' => true, 'waterline' => true],
            'adversarial_outcomes' => ['malformed' => 422, 'unregistered' => 404],
            'cross_namespace_isolation' => ['leak_count' => 0],
        ];
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function observedOutputsForFields(array $fields): array
    {
        $outputs = [];

        foreach ($fields as $field) {
            $outputs[$field] = match ($field) {
                'local_product_source_checkouts_used',
                'persisted' => false,
                'leak_count',
                'stale_worker_claim_count' => 0,
                default => 'observed',
            };
        }

        return $outputs;
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedWorkerExecution(string $artifact, string $version, string $source): array
    {
        return [
            'local_product_source_checkouts_used' => false,
            'artifacts' => [
                [
                    'artifact' => $artifact,
                    'version' => $version,
                    'source' => $source,
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredHeartbeatFinding(string $scenarioId, string $observedBehavior, string $owner): array
    {
        return [
            'scenario_id' => $scenarioId,
            'owning_surface' => $owner,
            'artifact_versions' => [
                'server' => '0.2.347',
                'cli' => '0.1.77',
                'workflow' => '2.0.0-alpha.200',
                'sdk-python' => '0.4.85',
                'sdk-rust' => '0.1.0',
                'waterline' => '2.0.0-alpha.83',
            ],
            'observed_behavior' => $observedBehavior,
            'expected_behavior' => 'The heartbeat conformance record includes focused evidence for this matrix cell.',
            'next_acceptance_criterion' => 'Publish a conformance run with this scenario result populated from published artifacts.',
        ];
    }
}
