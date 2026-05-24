<?php

namespace Tests\Unit;

use App\Support\WorkerVersioningRuntimeContract;
use App\Support\WorkerVersioningRuntimeResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class WorkerVersioningRuntimeContractTest extends TestCase
{
    public function test_manifest_requires_published_artifacts_and_safe_deploy_run_record_fields(): void
    {
        $manifest = WorkerVersioningRuntimeContract::manifest();

        $this->assertSame('durable-workflow.v2.worker-versioning-runtime.contract', $manifest['schema']);
        $this->assertSame(1, WorkerVersioningRuntimeContract::VERSION);
        $this->assertSame(WorkerVersioningRuntimeContract::VERSION, $manifest['version']);
        $this->assertSame('durable-workflow.v2.worker-versioning-runtime.result', $manifest['result_schema']);
        $this->assertSame('worker_versioning_runtime_contract', $manifest['fixture_category']);
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

        foreach (['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }

        foreach ([
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
        ] as $field) {
            $this->assertContains($field, $manifest['artifact_policy']['required_run_record_fields']);
        }
    }

    public function test_manifest_names_full_worker_versioning_matrix(): void
    {
        $manifest = WorkerVersioningRuntimeContract::manifest();

        $this->assertContains('workflow-php', $manifest['required_matrix']['runtimes']);
        $this->assertContains('sdk-python', $manifest['required_matrix']['runtimes']);
        $this->assertContains('cli', $manifest['required_matrix']['client_paths']);
        $this->assertContains('workflow-php-sdk', $manifest['required_matrix']['client_paths']);
        $this->assertContains('Waterline worker and workflow views', $manifest['required_matrix']['operator_visibility_paths']);
        $this->assertContains('pin_on_start', $manifest['required_scenarios']);
        $this->assertContains('replay_only_by_compatible_workers', $manifest['required_scenarios']);
        $this->assertContains('new_starts_to_promoted_version', $manifest['required_scenarios']);
        $this->assertContains('replay_across_cache_eviction', $manifest['required_scenarios']);
        $this->assertContains('no_compatible_worker_behavior', $manifest['required_scenarios']);
        $this->assertContains('operator_visibility_surfaces', $manifest['required_scenarios']);
        $this->assertContains('cross_language_php_python_pinning', $manifest['required_scenarios']);
        $this->assertContains('adversarial_no_version_bump', $manifest['required_scenarios']);
        $this->assertContains('history_api_version_pin', $manifest['required_scenarios']);
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $manifest['host_runner_contract']['routing_policy']['missing_required_scenario']['finding_type'],
        );
        $this->assertSame(
            $manifest['required_scenarios'],
            array_keys($manifest['scenario_requirements']),
            'every required worker-versioning scenario must declare scenario-specific evidence fields',
        );
    }

    public function test_scenario_manifest_source_path_is_published_and_matches_contract(): void
    {
        $manifest = WorkerVersioningRuntimeContract::manifest();
        $scenarioManifestPath = dirname(__DIR__, 2) . '/' . $manifest['scenario_manifest']['source_path'];

        $this->assertFileExists(
            $scenarioManifestPath,
            'cluster info must not advertise a worker-versioning scenario manifest source path that is missing from the release tree',
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
            array_keys($manifest['scenario_requirements']),
            array_keys($scenarioManifest['scenario_requirements']),
            'public worker-versioning scenario manifest must declare the same scenario required-field keys as cluster info',
        );

        foreach ($manifest['scenario_requirements'] as $scenarioId => $requirements) {
            $this->assertSame(
                $requirements['required_fields'],
                $scenarioManifest['scenario_requirements'][$scenarioId]['required_fields'],
                sprintf('scenario manifest required fields drifted for %s', $scenarioId),
            );
        }
    }

    public function test_result_gate_rejects_rollout_smoke_subset_even_when_smoke_passes(): void
    {
        $evaluation = WorkerVersioningRuntimeResultGate::evaluate([
            'schema' => WorkerVersioningRuntimeContract::RESULT_SCHEMA,
            'artifactVersions' => [
                'server' => '0.2.178',
                'cli' => '0.1.59',
                'sdk-python' => '0.4.74',
                'workflow' => '2.0.0-alpha.176',
                'waterline' => '2.0.0-alpha.57',
            ],
            'scenario_results' => [
                'published_artifact_install_only' => [
                    'status' => 'pass',
                    'observed_outputs' => ['artifact_sources' => ['server' => 'published_docker_image']],
                ],
                'worker_registration_build_ids' => [
                    'status' => 'pass',
                    'observed_outputs' => ['build_ids' => ['v1', 'v2']],
                ],
                'operator_rollout_visibility' => [
                    'status' => 'pass',
                    'observed_outputs' => ['worker_list' => true],
                ],
            ],
        ]);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertTrue($evaluation['smoke_subset_detected']);
        $this->assertContains('pin_on_start', $evaluation['missing_scenarios']);
        $this->assertContains(
            'smoke_subset_cannot_pass',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_findings_for_non_pass_scenarios(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['no_compatible_worker_behavior']['status'] = 'fail';
        unset($result['scenario_results']['no_compatible_worker_behavior']['linked_findings']);

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('no_compatible_worker_behavior', $evaluation['non_pass_scenarios']);
        $this->assertContains(
            'missing_non_pass_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_accepts_complete_safe_deploy_evidence(): void
    {
        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($this->completeWorkerVersioningResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_enforces_every_declared_scenario_required_field(): void
    {
        $manifest = WorkerVersioningRuntimeContract::manifest();

        foreach ($manifest['scenario_requirements'] as $scenarioId => $requirements) {
            foreach ($requirements['required_fields'] as $requiredField) {
                $result = $this->completeWorkerVersioningResult();
                unset($result['scenario_results'][$scenarioId]['observed_outputs'][$requiredField]);

                $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);

                $this->assertSame(
                    'non_passing',
                    $evaluation['status'],
                    sprintf('missing %s.%s must make the worker-versioning result non-passing', $scenarioId, $requiredField),
                );

                $matchingFailures = array_values(array_filter(
                    $evaluation['gate_failures'],
                    static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_scenario_required_field'
                        && ($failure['scenario_id'] ?? null) === $scenarioId
                        && ($failure['field'] ?? null) === $requiredField,
                ));

                $this->assertNotSame(
                    [],
                    $matchingFailures,
                    sprintf('missing %s.%s must be reported as a missing scenario required field', $scenarioId, $requiredField),
                );
            }
        }
    }

    public function test_result_gate_rejects_generic_observed_outputs_for_rollout_smoke_scenarios(): void
    {
        foreach ([
            'worker_registration_build_ids',
            'operator_rollout_visibility',
            'drain_resume_operator_controls',
        ] as $scenarioId) {
            $result = $this->completeWorkerVersioningResult();
            $result['scenario_results'][$scenarioId]['observed_outputs'] = [
                'scenario_id' => $scenarioId,
                'observed' => true,
            ];

            $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
            $missingFieldFailures = array_values(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_scenario_required_field'
                    && ($failure['scenario_id'] ?? null) === $scenarioId,
            ));

            $this->assertSame(
                'non_passing',
                $evaluation['status'],
                sprintf('generic observed=true evidence must not pass for %s', $scenarioId),
            );
            $this->assertNotEmpty(
                $missingFieldFailures,
                sprintf('generic observed=true evidence for %s must produce required-field failures', $scenarioId),
            );
        }
    }

    public function test_result_gate_requires_worker_runtime_matrix_entries_to_match_runtime_surface(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['runtime_matrix']['runtimes'] = [
            'workflow-php-sdk',
            'sdk-python',
        ];
        $result['topology']['workers'][] = 'workflow-php-v1';

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $missingPhpRuntimeFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_required_runtime'
                && ($failure['runtime'] ?? null) === 'workflow-php',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty(
            $missingPhpRuntimeFailures,
            'workflow-php-sdk client evidence must not satisfy the required workflow-php worker runtime',
        );
    }

    public function test_result_gate_rejects_forbidden_sources_reported_in_scenario_outputs(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['server'] =
            'local_product_source_checkout';

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
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
        $result = $this->completeWorkerVersioningResult();
        unset($result['artifact_sources']);
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources'] = [
            'server' => 'published_docker_image',
        ];

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $missingCliSourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_published_artifact_install_source'
                && ($failure['artifact'] ?? null) === 'cli',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($missingCliSourceFailures);
    }

    public function test_result_gate_rejects_local_product_source_checkout_use_flag(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['local_product_source_checkouts_used'] =
            true;

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $localCheckoutFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_checkouts_used_must_be_false'
                && ($failure['scenario_id'] ?? null) === 'published_artifact_install_only'
                && ($failure['field'] ?? null) === 'local_product_source_checkouts_used',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($localCheckoutFailures);
    }

    public function test_result_gate_rejects_placeholder_artifact_versions(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['artifact_versions']['server'] = 'latest';

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'placeholder_artifact_version',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function completeWorkerVersioningResult(): array
    {
        $scenarioResults = [];
        foreach (WorkerVersioningRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
            $scenarioResults[$scenarioId] = [
                'status' => 'pass',
                'observed_outputs' => [
                    'scenario_id' => $scenarioId,
                    'observed' => true,
                ],
            ];
        }

        $artifactVersions = [
            'server' => '0.2.178',
            'cli' => '0.1.59',
            'sdk-python' => '0.4.74',
            'workflow' => '2.0.0-alpha.176',
            'waterline' => '2.0.0-alpha.57',
        ];
        $artifactSources = [
            'server' => 'published_docker_image',
            'cli' => 'published_install_script',
            'sdk-python' => 'published_pypi',
            'workflow-php' => 'published_composer',
            'waterline' => 'published_artifact',
        ];

        $scenarioResults['published_artifact_install_only']['observed_outputs'] += [
            'resolved_artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'local_product_source_checkouts_used' => false,
        ];
        $scenarioResults['worker_registration_build_ids']['observed_outputs'] += [
            'registered_build_ids' => [
                'workflow-php-v1' => 'v1',
                'workflow-php-v2' => 'v2',
                'sdk-python-v2' => 'v2',
            ],
            'worker_registration_responses' => [
                'workflow-php-v1' => ['build_id' => 'v1'],
                'workflow-php-v2' => ['build_id' => 'v2'],
                'sdk-python-v2' => ['build_id' => 'v2'],
            ],
            'worker_list_build_ids' => ['v1', 'v2'],
            'task_queue_build_ids' => ['v1', 'v2'],
            'active_worker_counts_per_cohort' => ['v1' => 1, 'v2' => 2],
        ];
        $scenarioResults['operator_rollout_visibility']['observed_outputs'] += [
            'worker_cohorts' => ['v1', 'v2'],
            'rollout_state' => ['selected_new_start_build_id' => 'v2', 'draining' => []],
            'new_start_build_id' => 'v2',
            'workflow_run_compatibility' => ['old-run' => 'v1'],
            'waterline_operator_visibility' => ['worker_cohorts' => ['v1', 'v2'], 'workflow_compatibility' => 'v1'],
        ];
        $scenarioResults['drain_resume_operator_controls']['observed_outputs'] += [
            'drain_command' => 'dw task-queue build-id drain v1',
            'drain_state_visible' => true,
            'resume_command' => 'dw task-queue build-id resume v1',
            'resume_state_visible' => true,
            'draining_worker_claim_count' => 0,
        ];
        $scenarioResults['pin_on_start']['observed_outputs'] += [
            'run_compatibility' => 'v1',
            'first_task_compatibility' => 'v1',
            'history_or_visibility_field' => 'workflow_runs.compatibility',
        ];
        $scenarioResults['replay_only_by_compatible_workers']['observed_outputs'] += [
            'v1_worker_task_count' => 3,
            'v2_worker_task_count_for_v1_run' => 0,
            'workflow_result' => ['activity_a', 'activity_b'],
        ];
        $scenarioResults['new_starts_to_promoted_version']['observed_outputs'] += [
            'promotion_command' => 'dw task-queue promote-build-id',
            'new_run_compatibility' => 'v2',
            'old_run_continues_on' => 'v1',
        ];
        $scenarioResults['replay_across_cache_eviction']['observed_outputs'] += [
            'cache_eviction_observed' => true,
            'replay_worker_build_id' => 'v1',
            'incompatible_delivery_count' => 0,
        ];
        $scenarioResults['no_compatible_worker_behavior']['observed_outputs'] += [
            'operator_visible_signal' => 'no_compatible_worker',
            'pending_or_typed_error' => 'pending',
            'incompatible_worker_task_count' => 0,
        ];
        $scenarioResults['operator_visibility_surfaces']['observed_outputs'] += [
            'worker_list' => ['v1', 'v2'],
            'task_queue_build_ids' => ['v1', 'v2'],
            'workflow_visibility' => ['compatibility' => 'v1'],
            'waterline_operator_visibility' => ['visible' => true],
        ];
        $scenarioResults['cross_language_php_python_pinning']['observed_outputs'] += [
            'php_worker_build_id' => 'php-v1',
            'python_worker_build_id' => 'python-v2',
            'cross_language_delivery' => ['incompatible_delivery_count' => 0],
        ];
        $scenarioResults['adversarial_no_version_bump']['observed_outputs'] += [
            'observed_behavior' => 'accepted_with_same_build_id',
            'operator_audit_signal' => 'linked_gap_or_warning_present',
        ];
        $scenarioResults['history_api_version_pin']['observed_outputs'] += [
            'history_field' => 'compatibility',
            'compatibility_value' => 'v1',
        ];

        return [
            'schema' => WorkerVersioningRuntimeContract::RESULT_SCHEMA,
            'outcome' => 'pass',
            'started_at' => '2026-05-24T08:00:00Z',
            'finished_at' => '2026-05-24T08:05:00Z',
            'generated_at' => '2026-05-24T08:05:01Z',
            'artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'scenario_results' => $scenarioResults,
            'findings' => ['none' => 'no open findings for passing evidence'],
            'finding_links' => ['none' => 'not-applicable'],
            'topology' => [
                'task_queue' => 'worker-versioning-shared',
                'workers' => ['workflow-php-v1', 'workflow-php-v2', 'sdk-python-v2'],
                'operator_surfaces' => ['dw workers list', 'dw task-queue build-ids', 'Waterline worker and workflow views'],
            ],
            'runtime_matrix' => [
                'runtimes' => ['workflow-php', 'sdk-python'],
                'client_paths' => ['cli', 'sdk-python', 'workflow-php-sdk'],
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
            'versioning_observations' => ['pin_on_start' => 'v1', 'promoted_new_start' => 'v2'],
            'history_version_pins' => ['workflow_runs.compatibility' => 'v1'],
            'operator_controls' => ['drain' => true, 'resume' => true, 'promote' => true],
            'mixed_version_polling' => ['v1_task_count' => 3, 'v2_for_v1_count' => 0],
            'no_compatible_worker' => ['operator_visible_signal' => 'no_compatible_worker'],
            'cross_language_matrix' => ['php_v1_python_v2' => 'pass', 'python_v1_php_v2' => 'pass'],
            'adversarial_outcomes' => ['no_version_bump' => 'captured'],
        ];
    }
}
