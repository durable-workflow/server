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

        foreach (['server', 'cli', 'workflow', 'sdk-php', 'sdk-python', 'waterline'] as $artifact) {
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

        $this->assertContains('sdk-php', $manifest['required_matrix']['runtimes']);
        $this->assertContains('sdk-python', $manifest['required_matrix']['runtimes']);
        $this->assertContains('cli', $manifest['required_matrix']['client_paths']);
        $this->assertContains('sdk-php', $manifest['required_matrix']['client_paths']);
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
        $this->assertContains(
            'published_artifact_worker_execution',
            $manifest['scenario_requirements']['cross_language_php_python_pinning']['required_fields'],
        );
        $this->assertContains(
            'workflow_runs',
            $manifest['scenario_requirements']['cross_language_php_python_pinning']['required_fields'],
        );
        $this->assertContains(
            'rollout_state',
            $manifest['scenario_requirements']['cross_language_php_python_pinning']['required_fields'],
        );
        $this->assertContains(
            'worker_runtime_identities',
            $manifest['scenario_requirements']['cross_language_php_python_pinning']['required_fields'],
        );
        $this->assertContains(
            'public_outcome',
            $manifest['scenario_requirements']['cross_language_php_python_pinning']['required_fields'],
        );
        $this->assertContains(
            'local_product_source_checkouts_used',
            $manifest['scenario_requirements']['cross_language_php_python_pinning']['required_fields'],
        );
        $this->assertContains(
            'published_artifact_worker_execution',
            $manifest['scenario_requirements']['no_compatible_worker_behavior']['required_fields'],
        );
        $this->assertContains(
            'incompatible_worker_poll_attempts',
            $manifest['scenario_requirements']['no_compatible_worker_behavior']['required_fields'],
        );
        $this->assertContains(
            'compatible_worker_deregistered',
            $manifest['scenario_requirements']['no_compatible_worker_behavior']['required_fields'],
        );
        $this->assertContains(
            'local_product_source_checkouts_used',
            $manifest['scenario_requirements']['no_compatible_worker_behavior']['required_fields'],
        );
        $this->assertContains(
            'published_artifact_worker_execution',
            $manifest['scenario_requirements']['adversarial_no_version_bump']['required_fields'],
        );
        $this->assertContains(
            'local_product_source_checkouts_used',
            $manifest['scenario_requirements']['adversarial_no_version_bump']['required_fields'],
        );
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $manifest['host_runner_contract']['routing_policy']['missing_required_scenario']['finding_type'],
        );
        $this->assertSame(
            'scripts/conformance/worker-versioning-published-artifacts.sh',
            $manifest['host_runner_contract']['runner_path'],
        );
        $this->assertSame(
            'scripts/conformance/worker-versioning-published-artifacts.sh --result-dir <result-dir>',
            $manifest['host_runner_contract']['runner_command'],
        );
        $this->assertContains(
            'worker-versioning-result.json',
            $manifest['host_runner_contract']['result_files'],
        );
        $this->assertArrayHasKey(
            'DW_WV_PUBLISHED_WORKER_EVIDENCE',
            $manifest['host_runner_contract']['evidence_inputs'],
        );
        $this->assertStringContainsString(
            'adversarial no-version-bump cells',
            $manifest['host_runner_contract']['evidence_inputs']['DW_WV_PUBLISHED_WORKER_EVIDENCE'],
        );
        $this->assertStringContainsString(
            'Python replay/cache/adversarial shards',
            $manifest['host_runner_contract']['evidence_inputs']['DW_WV_PUBLISHED_WORKER_EVIDENCE'],
        );
        $this->assertArrayHasKey(
            'DW_WV_ARTIFACT_INSTALL_EVIDENCE',
            $manifest['host_runner_contract']['evidence_inputs'],
        );
        $this->assertArrayHasKey(
            'DW_WV_SKIP_PUBLISHED_WORKER_SHARD',
            $manifest['host_runner_contract']['evidence_inputs'],
        );
        $this->assertContains(
            'compatible_replay_delivery_counts',
            $manifest['host_runner_contract']['evidence_shards'],
        );
        $this->assertContains(
            'published_artifact_install_evidence',
            $manifest['host_runner_contract']['evidence_shards'],
        );
        $this->assertContains(
            'published_artifact_worker_execution',
            $manifest['host_runner_contract']['evidence_shards'],
        );
        $this->assertContains(
            'published_php_python_worker_protocol_client_shard',
            $manifest['host_runner_contract']['evidence_shards'],
        );
        $this->assertContains(
            'cache_eviction_delivery_counts',
            $manifest['host_runner_contract']['evidence_shards'],
        );
        $this->assertContains(
            'no_compatible_worker_diagnostics',
            $manifest['host_runner_contract']['evidence_shards'],
        );
        $this->assertContains(
            'cross_language_php_python_delivery_counts',
            $manifest['host_runner_contract']['evidence_shards'],
        );
        $this->assertSame(
            $manifest['required_scenarios'],
            array_keys($manifest['scenario_requirements']),
            'every required worker-versioning scenario must declare scenario-specific evidence fields',
        );
        $this->assertContains(
            'compatible_replay_and_cache_eviction_have_zero_incompatible_delivery',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'no_compatible_worker_has_zero_incompatible_delivery',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'no_compatible_worker_compatible_cohort_stopped',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'no_compatible_worker_incompatible_cohort_polled',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'no_compatible_worker_signal_is_explicit',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'no_compatible_worker_has_zero_incompatible_delivery',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'no_compatible_worker_compatible_cohort_stopped',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'no_compatible_worker_incompatible_cohort_polled',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'no_compatible_worker_signal_is_explicit',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'published_artifact_worker_execution_reported_for_replay_adversarial_and_cross_language_cells',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'no_compatible_worker_public_protocol_probe_or_worker_execution_reported',
            $manifest['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'cross_language_php_python_delivery_counts_are_zero',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'published_artifact_install_evidence_reported',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'published_artifact_worker_execution_reported_for_replay_adversarial_and_cross_language_cells',
            $manifest['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'no_compatible_worker_public_protocol_probe_or_worker_execution_reported',
            $manifest['coverage_gate']['passing_outcome_requires'],
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
        $this->assertSame(
            $manifest['host_runner_contract']['runner_path'],
            $scenarioManifest['host_runner_contract']['runner_path'],
        );
        $this->assertSame(
            $manifest['host_runner_contract']['runner_command'],
            $scenarioManifest['host_runner_contract']['runner_command'],
        );
        $this->assertSame(
            $manifest['host_runner_contract']['result_files'],
            $scenarioManifest['host_runner_contract']['result_files'],
        );
        $this->assertSame(
            $manifest['host_runner_contract']['evidence_inputs'],
            $scenarioManifest['host_runner_contract']['evidence_inputs'],
        );

        foreach ($manifest['scenario_requirements'] as $scenarioId => $requirements) {
            $this->assertSame(
                $requirements['required_fields'],
                $scenarioManifest['scenario_requirements'][$scenarioId]['required_fields'],
                sprintf('scenario manifest required fields drifted for %s', $scenarioId),
            );
        }
    }

    public function test_worker_versioning_runner_handoff_files_are_shipped(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $shell = (string) file_get_contents($repoRoot.'/scripts/conformance/worker-versioning-published-artifacts.sh');
        $runner = (string) file_get_contents($repoRoot.'/scripts/conformance/worker-versioning-published-artifacts.mjs');

        $this->assertStringContainsString(
            'Usage: worker-versioning-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]',
            $shell,
        );
        $this->assertStringContainsString('worker-versioning-result.json', $shell);
        $this->assertStringContainsString('v2_worker_task_count_for_v1_run', $runner);
        $this->assertStringContainsString('incompatible_delivery_count', $runner);
        $this->assertStringContainsString('replay_across_cache_eviction', $runner);
        $this->assertStringContainsString('cross_language_php_python_pinning', $runner);
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

    public function test_result_gate_rejects_drain_resume_without_draining_worker_poll_evidence(): void
    {
        $result = $this->completeWorkerVersioningResult();
        unset(
            $result['scenario_results']['drain_resume_operator_controls']['observed_outputs']['draining_worker_poll'],
            $result['scenario_results']['drain_resume_operator_controls']['observed_outputs']['draining_worker_claim_blocked'],
        );

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_draining_worker_poll_evidence', $codes);
        $this->assertContains('missing_draining_worker_claim_blocked_evidence', $codes);
    }

    public function test_result_gate_rejects_compatible_replay_when_v2_receives_v1_run_task(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['replay_only_by_compatible_workers']['observed_outputs'][
            'v2_worker_task_count_for_v1_run'
        ] = 1;

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'incompatible_delivery_count_nonzero'
                && ($failure['scenario_id'] ?? null) === 'replay_only_by_compatible_workers'
                && ($failure['field'] ?? null) === 'v2_worker_task_count_for_v1_run',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_compatible_replay_without_v1_worker_delivery(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['replay_only_by_compatible_workers']['observed_outputs']['v1_worker_task_count'] = 0;

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'compatible_worker_task_count_not_positive'
                && ($failure['scenario_id'] ?? null) === 'replay_only_by_compatible_workers',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_cache_eviction_when_incompatible_delivery_is_nonzero(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['replay_across_cache_eviction']['observed_outputs'][
            'incompatible_delivery_count'
        ] = 1;

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'incompatible_delivery_count_nonzero'
                && ($failure['scenario_id'] ?? null) === 'replay_across_cache_eviction'
                && ($failure['field'] ?? null) === 'incompatible_delivery_count',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_no_compatible_worker_when_incompatible_delivery_is_nonzero(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'][
            'incompatible_worker_task_count'
        ] = 1;

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'incompatible_delivery_count_nonzero'
                && ($failure['scenario_id'] ?? null) === 'no_compatible_worker_behavior'
                && ($failure['field'] ?? null) === 'incompatible_worker_task_count',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_no_compatible_worker_without_explicit_signal(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'][
            'operator_visible_signal'
        ] = 'empty_poll';
        $result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'][
            'pending_or_typed_error'
        ] = 'silent_stall';

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('no_compatible_worker_signal_not_explicit', $failureCodes);
        $this->assertContains('no_compatible_worker_pending_or_typed_error_not_explicit', $failureCodes);
    }

    public function test_result_gate_rejects_no_compatible_worker_without_stopped_cohort_and_polling_proof(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'][
            'incompatible_worker_poll_attempts'
        ] = 0;
        $result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'][
            'compatible_worker_deregistered'
        ] = false;

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('incompatible_worker_poll_attempts_not_positive', $failureCodes);
        $this->assertContains('scenario_field_must_be_true', $failureCodes);
    }

    public function test_result_gate_accepts_no_compatible_alias_fields_and_public_diagnostic_text(): void
    {
        $result = $this->completeWorkerVersioningResult();
        unset(
            $result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'][
                'operator_visible_signal'
            ],
            $result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'][
                'pending_or_typed_error'
            ],
            $result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'][
                'incompatible_worker_task_count'
            ]
        );
        $result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'] += [
            'publicDiagnostic' => 'No compatible worker is currently available',
            'pendingState' => 'pending',
            'incompatibleTaskCount' => 0,
            'pollAttempts' => 2,
            'compatibleCohortStopped' => true,
        ];

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_rejects_cache_eviction_when_replay_worker_does_not_match_pin(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['replay_across_cache_eviction']['observed_outputs'][
            'replay_worker_build_id'
        ] = 'v2';

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'replay_worker_build_id_mismatch'
                && ($failure['scenario_id'] ?? null) === 'replay_across_cache_eviction',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_accepts_cache_eviction_expected_build_from_replay_shard(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['pin_on_start']['observed_outputs']['run_compatibility'] =
            'server-protocol-v1';
        $result['scenario_results']['replay_across_cache_eviction']['observed_outputs'][
            'expected_replay_worker_build_id'
        ] = 'published-python-v1';
        $result['scenario_results']['replay_across_cache_eviction']['observed_outputs'][
            'pinned_run_build_id'
        ] = 'published-python-v1';
        $result['scenario_results']['replay_across_cache_eviction']['observed_outputs'][
            'replay_worker_build_id'
        ] = 'published-python-v1';

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_rejects_cross_language_pinning_without_directional_counts(): void
    {
        $result = $this->completeWorkerVersioningResult();
        unset(
            $result['scenario_results']['cross_language_php_python_pinning']['observed_outputs'][
                'php_v1_to_python_v2_incompatible_delivery_count'
            ],
            $result['scenario_results']['cross_language_php_python_pinning']['observed_outputs'][
                'python_v1_to_php_v2_incompatible_delivery_count'
            ]
        );
        $result['scenario_results']['cross_language_php_python_pinning']['observed_outputs']['cross_language_delivery'] = [
            'incompatible_delivery_count' => 0,
        ];

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_scenario_required_field'
                && ($failure['scenario_id'] ?? null) === 'cross_language_php_python_pinning'
                && in_array($failure['field'] ?? null, [
                    'php_v1_to_python_v2_incompatible_delivery_count',
                    'python_v1_to_php_v2_incompatible_delivery_count',
                ], true),
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(2, $failures);
    }

    public function test_result_gate_rejects_cross_language_when_php_v1_reaches_python_v2(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['cross_language_php_python_pinning']['observed_outputs'][
            'php_v1_to_python_v2_incompatible_delivery_count'
        ] = 1;

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'incompatible_delivery_count_nonzero'
                && ($failure['scenario_id'] ?? null) === 'cross_language_php_python_pinning'
                && ($failure['field'] ?? null) === 'php_v1_to_python_v2_incompatible_delivery_count',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_cross_language_when_python_v1_reaches_php_v2(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['cross_language_php_python_pinning']['observed_outputs'][
            'python_v1_to_php_v2_incompatible_delivery_count'
        ] = 1;

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'incompatible_delivery_count_nonzero'
                && ($failure['scenario_id'] ?? null) === 'cross_language_php_python_pinning'
                && ($failure['field'] ?? null) === 'python_v1_to_php_v2_incompatible_delivery_count',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
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

    public function test_result_gate_rejects_rollout_visibility_with_unexercised_waterline_placeholder(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['operator_rollout_visibility']['observed_outputs']['cli_operator_command_execution'] = true;
        $result['scenario_results']['operator_rollout_visibility']['observed_outputs']['cli_output'] = [
            'worker_cohorts' => ['v1', 'v2'],
            'new_start_build_id' => 'v2',
            'workflow_run_compatibility' => ['old-run' => 'v1'],
        ];
        $result['scenario_results']['operator_rollout_visibility']['observed_outputs']['waterline_operator_visibility'] = [
            'status' => 'not_exercised_by_server_handoff',
        ];

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $waterlineFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'waterline_operator_visibility_not_exercised'
                && ($failure['scenario_id'] ?? null) === 'operator_rollout_visibility',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty(
            $waterlineFailures,
            'CLI command output must not pass the combined rollout scenario while Waterline is only a handoff placeholder',
        );
    }

    public function test_result_gate_requires_the_php_sdk_worker_runtime(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['runtime_matrix']['runtimes'] = [
            'sdk-python',
        ];

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $missingPhpRuntimeFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_required_runtime'
                && ($failure['runtime'] ?? null) === 'sdk-php',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($missingPhpRuntimeFailures);
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

    public function test_result_gate_rejects_not_exercised_passing_artifact_sources(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['cli'] =
            'not_exercised';

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_published_artifact_install_source'
                && ($failure['scenario_id'] ?? null) === 'published_artifact_install_only'
                && ($failure['artifact'] ?? null) === 'cli',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_not_exercised_passing_artifact_install_evidence_source(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']['artifacts'][1]['source'] =
            'not_exercised';

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_published_artifact_install_evidence_source'
                && ($failure['scenario_id'] ?? null) === 'published_artifact_install_only'
                && ($failure['artifact'] ?? null) === 'cli',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_missing_passing_artifact_install_evidence_source(): void
    {
        $result = $this->completeWorkerVersioningResult();
        unset($result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']['artifacts'][1]['source']);

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_published_artifact_install_evidence_source'
                && ($failure['scenario_id'] ?? null) === 'published_artifact_install_only'
                && ($failure['artifact'] ?? null) === 'cli',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
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

    public function test_result_gate_rejects_string_true_local_product_source_checkout_use_flag(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']['local_product_source_checkouts_used'] =
            'true';

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $localCheckoutFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_checkouts_used_must_be_false'
                && ($failure['scenario_id'] ?? null) === 'published_artifact_install_only'
                && ($failure['field'] ?? null) === 'artifact_install_evidence.local_product_source_checkouts_used',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($localCheckoutFailures);
    }

    public function test_result_gate_rejects_artifact_install_entry_local_product_source_checkout_use_flag(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']['artifacts'][1]['local_product_source_checkouts_used'] =
            'true';

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $localCheckoutFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_checkouts_used_must_be_false'
                && ($failure['scenario_id'] ?? null) === 'published_artifact_install_only'
                && ($failure['artifact'] ?? null) === 'cli'
                && ($failure['field'] ?? null) === 'artifact_install_evidence.artifacts.local_product_source_checkouts_used',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($localCheckoutFailures);
    }

    public function test_result_gate_requires_published_artifact_install_evidence(): void
    {
        $result = $this->completeWorkerVersioningResult();
        unset($result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']);

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_published_artifact_install_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_non_passing_artifact_install_evidence(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']['artifacts'][1]['status'] =
            'not_covered';

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'published_artifact_install_evidence_not_pass'
                && ($failure['artifact'] ?? null) === 'cli',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_replay_pass_without_published_worker_execution(): void
    {
        $result = $this->completeWorkerVersioningResult();
        unset($result['scenario_results']['replay_only_by_compatible_workers']['observed_outputs']['published_artifact_worker_execution']);

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'published_artifact_worker_execution_missing'
                && ($failure['scenario_id'] ?? null) === 'replay_only_by_compatible_workers',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_replay_pass_without_divergent_workflow_execution(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['replay_across_cache_eviction']['observed_outputs']['divergent_workflow_execution_observed'] =
            false;

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'divergent_workflow_execution_not_observed'
                && ($failure['scenario_id'] ?? null) === 'replay_across_cache_eviction',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_cross_language_pass_without_published_worker_execution(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['cross_language_php_python_pinning']['observed_outputs']['published_artifact_worker_execution'] =
            false;

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'published_artifact_worker_execution_missing'
                && ($failure['scenario_id'] ?? null) === 'cross_language_php_python_pinning',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_adversarial_pass_without_published_worker_execution(): void
    {
        $result = $this->completeWorkerVersioningResult();
        unset($result['scenario_results']['adversarial_no_version_bump']['observed_outputs'][
            'published_artifact_worker_execution'
        ]);

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'published_artifact_worker_execution_missing'
                && ($failure['scenario_id'] ?? null) === 'adversarial_no_version_bump',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_accepts_no_compatible_public_protocol_probe_without_published_worker_execution(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'][
            'published_artifact_worker_execution'
        ] = false;
        $result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'][
            'worker_execution_mode'
        ] = 'server_http_protocol_probe';
        $result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'][
            'published_server_protocol_probe'
        ] = true;
        $result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'][
            'published_server_artifact'
        ] = [
            'artifact' => 'server',
            'version' => '0.2.178',
            'source' => 'published_docker_image',
            'status' => 'pass',
            'local_product_source_checkouts_used' => false,
        ];

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_rejects_no_compatible_pass_without_worker_execution_or_protocol_probe(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'][
            'published_artifact_worker_execution'
        ] = false;
        unset($result['scenario_results']['no_compatible_worker_behavior']['observed_outputs'][
            'published_server_protocol_probe'
        ]);

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'no_compatible_worker_evidence_missing'
                && ($failure['scenario_id'] ?? null) === 'no_compatible_worker_behavior',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_boolean_published_worker_execution_claim(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['replay_only_by_compatible_workers']['observed_outputs'][
            'published_artifact_worker_execution'
        ] = true;

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'published_artifact_worker_execution_missing'
                && ($failure['scenario_id'] ?? null) === 'replay_only_by_compatible_workers',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_forbidden_published_worker_execution_source(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['replay_only_by_compatible_workers']['observed_outputs'][
            'published_artifact_worker_execution'
        ]['artifacts'][0]['source'] = 'not_exercised';

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_published_artifact_worker_execution_source'
                && ($failure['scenario_id'] ?? null) === 'replay_only_by_compatible_workers'
                && ($failure['artifact'] ?? null) === 'sdk-python',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_published_worker_execution_local_checkout_flag(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['scenario_results']['replay_across_cache_eviction']['observed_outputs'][
            'published_artifact_worker_execution'
        ]['local_product_source_checkouts_used'] = 'true';

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_checkouts_used_must_be_false'
                && ($failure['scenario_id'] ?? null) === 'replay_across_cache_eviction'
                && ($failure['field'] ?? null) === 'published_artifact_worker_execution.local_product_source_checkouts_used',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_rejects_published_worker_scenario_local_checkout_flag(): void
    {
        foreach ([
            'replay_only_by_compatible_workers',
            'replay_across_cache_eviction',
            'no_compatible_worker_behavior',
            'cross_language_php_python_pinning',
        ] as $scenarioId) {
            $result = $this->completeWorkerVersioningResult();
            $result['scenario_results'][$scenarioId]['observed_outputs']['local_product_source_checkouts_used'] =
                'true';

            $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
            $failures = array_values(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_checkouts_used_must_be_false'
                    && ($failure['scenario_id'] ?? null) === $scenarioId
                    && ($failure['field'] ?? null) === 'local_product_source_checkouts_used',
            ));

            $this->assertSame('non_passing', $evaluation['status'], $scenarioId);
            $this->assertNotEmpty($failures, $scenarioId);
        }
    }

    public function test_result_gate_rejects_top_level_published_worker_evidence_local_checkout_flag(): void
    {
        $result = $this->completeWorkerVersioningResult();
        $result['published_worker_execution_evidence']['local_product_source_checkouts_used'] = true;

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_checkouts_used_must_be_false'
                && ($failure['scenario_id'] ?? null) === 'replay_only_by_compatible_workers'
                && ($failure['field'] ?? null) === 'published_worker_execution_evidence.local_product_source_checkouts_used',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
    }

    public function test_result_gate_requires_top_level_published_worker_evidence_local_checkout_flag(): void
    {
        $result = $this->completeWorkerVersioningResult();
        unset($result['published_worker_execution_evidence']['local_product_source_checkouts_used']);
        $result['published_worker_execution_evidence']['supplied_shard_local_product_source_checkouts_used'] = true;

        $evaluation = WorkerVersioningRuntimeResultGate::evaluate($result);
        $failures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'local_product_source_checkouts_used_must_be_false'
                && ($failure['scenario_id'] ?? null) === 'replay_across_cache_eviction'
                && ($failure['field'] ?? null) === 'published_worker_execution_evidence.local_product_source_checkouts_used',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($failures);
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
            'sdk-php' => '0.1.176',
            'waterline' => '2.0.0-alpha.57',
        ];
        $artifactSources = [
            'server' => 'published_docker_image',
            'cli' => 'published_install_script',
            'sdk-python' => 'published_pypi',
            'sdk-php' => 'published_composer',
            'waterline' => 'published_artifact',
        ];
        $pythonPublishedWorkerExecution = [
            'local_product_source_checkouts_used' => false,
            'artifacts' => [
                [
                    'artifact' => 'sdk-python',
                    'version' => '0.4.74',
                    'source' => 'published_pypi',
                    'status' => 'pass',
                    'command' => 'pip install durable-workflow==0.4.74',
                ],
            ],
        ];
        $crossLanguagePublishedWorkerExecution = [
            'local_product_source_checkouts_used' => false,
            'artifacts' => [
                [
                    'artifact' => 'sdk-php',
                    'version' => '0.1.176',
                    'source' => 'published_composer',
                    'status' => 'pass',
                    'command' => 'composer require durable-workflow/sdk:0.1.176',
                ],
                [
                    'artifact' => 'sdk-python',
                    'version' => '0.4.74',
                    'source' => 'published_pypi',
                    'status' => 'pass',
                    'command' => 'pip install durable-workflow==0.4.74',
                ],
            ],
        ];

        $scenarioResults['published_artifact_install_only']['observed_outputs'] += [
            'resolved_artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'local_product_source_checkouts_used' => false,
            'artifact_install_evidence' => [
                'local_product_source_checkouts_used' => false,
                'artifacts' => [
                    ['artifact' => 'server', 'version' => '0.2.178', 'source' => 'published_docker_image', 'status' => 'pass'],
                    ['artifact' => 'cli', 'version' => '0.1.59', 'source' => 'published_install_script', 'status' => 'pass'],
                    ['artifact' => 'sdk-python', 'version' => '0.4.74', 'source' => 'published_pypi', 'status' => 'pass'],
                    ['artifact' => 'sdk-php', 'version' => '0.1.176', 'source' => 'published_composer', 'status' => 'pass'],
                    ['artifact' => 'waterline', 'version' => '2.0.0-alpha.57', 'source' => 'published_artifact', 'status' => 'pass'],
                ],
            ],
        ];
        $scenarioResults['worker_registration_build_ids']['observed_outputs'] += [
            'registered_build_ids' => [
                'sdk-php-v1' => 'v1',
                'sdk-php-v2' => 'v2',
                'sdk-python-v2' => 'v2',
            ],
            'worker_registration_responses' => [
                'sdk-php-v1' => ['build_id' => 'v1'],
                'sdk-php-v2' => ['build_id' => 'v2'],
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
            'draining_worker_poll' => [
                'http_status' => 409,
                'poll_status' => 'draining',
                'reason' => 'worker_draining',
                'task' => null,
            ],
            'draining_worker_claim_blocked' => true,
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
            'local_product_source_checkouts_used' => false,
            'published_artifact_worker_execution' => $pythonPublishedWorkerExecution,
            'divergent_workflow_execution_observed' => true,
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
            'local_product_source_checkouts_used' => false,
            'published_artifact_worker_execution' => $pythonPublishedWorkerExecution,
            'divergent_workflow_execution_observed' => true,
        ];
        $scenarioResults['no_compatible_worker_behavior']['observed_outputs'] += [
            'operator_visible_signal' => 'no_compatible_worker',
            'pending_or_typed_error' => 'pending',
            'incompatible_worker_task_count' => 0,
            'incompatible_worker_poll_attempts' => 2,
            'compatible_worker_deregistered' => true,
            'local_product_source_checkouts_used' => false,
            'published_artifact_worker_execution' => $pythonPublishedWorkerExecution,
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
            'worker_runtime_identities' => [
                ['worker_id' => 'php-worker-v1', 'runtime' => 'php', 'language' => 'php', 'build_id' => 'php-v1'],
                ['worker_id' => 'python-worker-v2', 'runtime' => 'python', 'language' => 'python', 'build_id' => 'python-v2'],
                ['worker_id' => 'python-worker-v1', 'runtime' => 'python', 'language' => 'python', 'build_id' => 'python-v1'],
                ['worker_id' => 'php-worker-v2', 'runtime' => 'php', 'language' => 'php', 'build_id' => 'php-v2'],
            ],
            'workflow_runs' => [
                'php_v1_started' => [
                    'workflow_id' => 'php-sequence',
                    'run_id' => 'php-run-1',
                    'started_by_runtime' => 'php',
                    'pinned_build_id' => 'php-v1',
                    'compatible_worker_runtime' => 'php',
                    'incompatible_worker_runtime' => 'python',
                ],
                'python_v1_started' => [
                    'workflow_id' => 'python-sequence',
                    'run_id' => 'python-run-1',
                    'started_by_runtime' => 'python',
                    'pinned_build_id' => 'python-v1',
                    'compatible_worker_runtime' => 'python',
                    'incompatible_worker_runtime' => 'php',
                ],
            ],
            'rollout_state' => [
                'after_php_v1_promotion' => ['selected_new_start_build_id' => 'php-v1'],
                'after_python_v1_promotion' => ['selected_new_start_build_id' => 'python-v1'],
                'promoted_build_ids' => [
                    'php_started_run' => 'php-v1',
                    'python_started_run' => 'python-v1',
                ],
            ],
            'php_v1_to_python_v2_incompatible_delivery_count' => 0,
            'python_v1_to_php_v2_incompatible_delivery_count' => 0,
            'local_product_source_checkouts_used' => false,
            'published_artifact_worker_execution' => $crossLanguagePublishedWorkerExecution,
            'cross_language_delivery' => [
                'cells' => [
                    [
                        'scenario' => 'php_v1_not_delivered_to_python_v2',
                        'started_by' => 'sdk-php-v1',
                        'incompatible_worker' => 'sdk-python-v2',
                        'workflow_id' => 'php-sequence',
                        'run_id' => 'php-run-1',
                        'incompatible_delivery_count' => 0,
                    ],
                    [
                        'scenario' => 'python_v1_not_delivered_to_php_v2',
                        'started_by' => 'sdk-python-v1',
                        'incompatible_worker' => 'sdk-php-v2',
                        'workflow_id' => 'python-sequence',
                        'run_id' => 'python-run-1',
                        'incompatible_delivery_count' => 0,
                    ],
                ],
            ],
            'public_outcome' => [
                'verification_surface' => 'published worker poll outputs and task-queue build-id rollout API',
                'passed' => true,
            ],
        ];
        $scenarioResults['adversarial_no_version_bump']['observed_outputs'] += [
            'observed_behavior' => 'accepted_with_same_build_id',
            'operator_audit_signal' => 'linked_gap_or_warning_present',
            'local_product_source_checkouts_used' => false,
            'published_artifact_worker_execution' => $pythonPublishedWorkerExecution,
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
            'published_worker_execution_evidence' => [
                'local_product_source_checkouts_used' => false,
                'scenario_results' => [
                    'replay_only_by_compatible_workers' => [
                        'status' => 'pass',
                    ],
                    'replay_across_cache_eviction' => [
                        'status' => 'pass',
                    ],
                    'cross_language_php_python_pinning' => [
                        'status' => 'pass',
                    ],
                ],
            ],
            'scenario_results' => $scenarioResults,
            'findings' => ['none' => 'no open findings for passing evidence'],
            'finding_links' => ['none' => 'not-applicable'],
            'topology' => [
                'task_queue' => 'worker-versioning-shared',
                'workers' => ['sdk-php-v1', 'sdk-php-v2', 'sdk-python-v2'],
                'operator_surfaces' => ['dw workers list', 'dw task-queue build-ids', 'Waterline worker and workflow views'],
            ],
            'runtime_matrix' => [
                'runtimes' => ['sdk-php', 'sdk-python'],
                'client_paths' => ['cli', 'sdk-python', 'sdk-php'],
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
                        'started_by' => 'sdk-php-v1',
                        'incompatible_worker' => 'sdk-python-v2',
                        'scenario' => 'php_v1_not_delivered_to_python_v2',
                    ],
                    [
                        'started_by' => 'sdk-python-v1',
                        'incompatible_worker' => 'sdk-php-v2',
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
