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
        $this->assertSame(7, HeartbeatRuntimeContract::VERSION);
        $this->assertSame(HeartbeatRuntimeContract::VERSION, $manifest['version']);
        $this->assertSame('durable-workflow.v2.heartbeat-runtime.result', $manifest['result_schema']);
        $this->assertSame(2, $manifest['result_version']);
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

        foreach (['server', 'cli', 'sdk-php', 'sdk-python', 'sdk-rust', 'waterline'] as $artifact) {
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

        $this->assertContains('sdk-php', $manifest['required_matrix']['runtimes']);
        $this->assertContains('sdk-python', $manifest['required_matrix']['runtimes']);
        $this->assertContains('sdk-rust', $manifest['required_matrix']['runtimes']);
        $this->assertContains('dw worker:list', $manifest['required_matrix']['operator_visibility_paths']);
        $this->assertContains('dw worker:describe', $manifest['required_matrix']['operator_visibility_paths']);
        $this->assertContains('Waterline Worker Status view', $manifest['required_matrix']['operator_visibility_paths']);
        $this->assertContains('stale_workers_excluded_from_workflow_start', $manifest['required_matrix']['routing_cells']);
        $this->assertContains('stale_workers_excluded_from_query_tasks', $manifest['required_matrix']['routing_cells']);
        $this->assertContains('fresh_worker_remains_eligible_after_peer_stale', $manifest['required_matrix']['routing_cells']);
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

    public function test_manifest_publishes_the_focused_php_sdk_heartbeat_runner(): void
    {
        $manifest = HeartbeatRuntimeContract::manifest();
        $runner = $manifest['host_runner_contract']['focused_runners']['sdk-php-heartbeat-loop'];

        $this->assertSame('host_executable_published_artifact_runner', $runner['status']);
        $this->assertSame('server', $runner['runner_repository']);
        $this->assertSame('scripts/conformance/heartbeats-published-artifacts.sh', $runner['runner_path']);
        $this->assertSame('php_sdk_heartbeat_loop', $runner['scenario_id']);
        $this->assertSame(
            'durable-workflow.v2.heartbeat-runtime.php-sdk-loop-evidence',
            $runner['result_schema'],
        );
        $this->assertSame('php-sdk-heartbeat-loop-evidence.json', $runner['result_file']);
        $this->assertTrue($runner['host_runner_implemented']);
        $this->assertTrue($runner['must_execute_against_published_artifacts']);
        $this->assertTrue($runner['must_record_runner_blocked_false_for_product_evidence']);
        $this->assertSame(['server', 'cli', 'sdk-php'], $runner['required_artifact_pins']);
        $this->assertContains(
            'DurableWorkflow\\Client',
            $runner['required_worker_api'],
        );
        $this->assertContains(
            'DurableWorkflow\\Worker::tick()',
            $runner['required_worker_api'],
        );
        $this->assertContains(
            'DurableWorkflow\\Client::heartbeatWorker()',
            $runner['required_worker_api'],
        );
        $this->assertContains('at_least_two_sdk_emitted_heartbeat_timestamps', $runner['must_prove']);
        $this->assertContains('stale_php_worker_poll_is_refused', $runner['must_prove']);
        $this->assertContains('fresh_php_peer_remains_eligible_for_new_work', $runner['must_prove']);
        $this->assertSame([
            'python_sdk_heartbeat_loop',
            'rust_sdk_heartbeat_loop',
            'waterline_worker_status_visibility',
        ], $runner['does_not_claim_scenarios']);
        $this->assertContains(
            'php-sdk-heartbeat-loop-evidence.json',
            $manifest['host_runner_contract']['result_files'],
        );
        $this->assertSame(
            'sdk-php-heartbeat-loop',
            $manifest['host_runner_contract']['runtime_shards']['sdk-php']['focused_runner'],
        );
    }

    public function test_manifest_publishes_the_bounded_shared_server_wave(): void
    {
        $manifest = HeartbeatRuntimeContract::manifest();
        $wave = $manifest['host_runner_contract']['shared_server_wave'];

        $this->assertSame('host_executable_published_artifact_runner', $wave['status']);
        $this->assertSame(
            'scripts/conformance/heartbeats-wave-published-artifacts.sh',
            $wave['runner_path'],
        );
        $this->assertSame(
            'scripts/conformance/heartbeats-shared-server.sh',
            $wave['server_lifecycle_path'],
        );
        $this->assertSame(540, $wave['maximum_wall_time_seconds']);
        $this->assertSame([
            'concurrent_cell' => 450,
            'rust_preparation' => 360,
            'rust_heartbeat_execution_reserve' => 90,
            'wave_orchestration_and_cleanup_reserve' => 90,
        ], $wave['default_budget_allocation_seconds']);
        $this->assertSame(1, $wave['clean_published_server_bootstrap_count']);
        $this->assertSame(['php', 'python', 'rust', 'waterline'], $wave['parallel_cells']);
        $this->assertSame([
            'host_endpoint_mode' => 'executor_network_attachment',
            'executor_identity_recorded' => false,
            'server_origin' => 'http://server:8080',
            'native_host_fallback' => 'authenticated_published_loopback',
            'compatibility_relay' => 'long_lived_in_memory_request_proxy',
            'compose_network_owned_by_wave' => true,
            'authenticated_readiness_required' => true,
        ], $wave['daemon_portable_transport']);
        $this->assertContains(
            'shared-server-relay.log',
            $wave['startup_diagnostic_files'],
        );
        $this->assertSame([
            'namespace',
            'task_queue',
            'workflow_id',
            'worker_id',
            'observer_projection',
        ], $wave['isolation_dimensions']);
        $this->assertContains('bounded_per_cell_timeout', $wave['failure_policy']);
        $this->assertContains('retain_independent_cell_evidence', $wave['failure_policy']);
        $this->assertContains(
            'cleanup_shared_compose_project_after_every_terminal_path',
            $wave['failure_policy'],
        );
        $this->assertContains(
            'cleanup_shared_network_cell_containers_after_every_terminal_path',
            $wave['failure_policy'],
        );
        $this->assertContains(
            'cleanup_executor_network_attachment_after_every_terminal_path',
            $wave['failure_policy'],
        );
        $this->assertContains(
            'cleanup_host_relay_after_every_terminal_path',
            $wave['failure_policy'],
        );
        $this->assertTrue($wave['standalone_clean_bootstrap_path_preserved']);
        $this->assertContains(
            'heartbeat-shared-wave-result.json',
            $manifest['host_runner_contract']['result_files'],
        );
    }

    public function test_manifest_publishes_the_focused_python_sdk_heartbeat_runner(): void
    {
        $manifest = HeartbeatRuntimeContract::manifest();
        $runner = $manifest['host_runner_contract']['focused_runners']['sdk-python-heartbeat-loop'];

        $this->assertSame('host_executable_published_artifact_runner', $runner['status']);
        $this->assertSame('server', $runner['runner_repository']);
        $this->assertSame(
            'scripts/conformance/heartbeats-python-published-artifacts.sh',
            $runner['runner_path'],
        );
        $this->assertSame('python_sdk_heartbeat_loop', $runner['scenario_id']);
        $this->assertSame(
            'durable-workflow.v2.heartbeat-runtime.python-sdk-loop-evidence',
            $runner['result_schema'],
        );
        $this->assertSame('python-sdk-heartbeat-loop-evidence.json', $runner['result_file']);
        $this->assertTrue($runner['host_runner_implemented']);
        $this->assertTrue($runner['must_execute_against_published_artifacts']);
        $this->assertTrue($runner['must_record_runner_blocked_false_for_product_evidence']);
        $this->assertSame(['server', 'cli', 'sdk-python'], $runner['required_artifact_pins']);
        $this->assertContains('durable_workflow.Client', $runner['required_worker_api']);
        $this->assertContains('durable_workflow.Worker.run()', $runner['required_worker_api']);
        $this->assertContains('at_least_two_sdk_emitted_heartbeat_timestamps', $runner['must_prove']);
        $this->assertContains('stale_python_worker_poll_is_refused', $runner['must_prove']);
        $this->assertContains('fresh_python_peer_remains_eligible_for_new_work', $runner['must_prove']);
        $this->assertSame([
            'php_sdk_heartbeat_loop',
            'rust_sdk_heartbeat_loop',
            'waterline_worker_status_visibility',
        ], $runner['does_not_claim_scenarios']);
        $this->assertContains(
            'python-sdk-heartbeat-loop-evidence.json',
            $manifest['host_runner_contract']['result_files'],
        );
        $this->assertSame(
            'sdk-python-heartbeat-loop',
            $manifest['host_runner_contract']['runtime_shards']['sdk-python']['focused_runner'],
        );
    }

    public function test_manifest_publishes_the_focused_rust_sdk_heartbeat_runner(): void
    {
        $manifest = HeartbeatRuntimeContract::manifest();
        $runner = $manifest['host_runner_contract']['focused_runners']['sdk-rust-heartbeat-loop'];

        $this->assertSame('host_executable_published_artifact_runner', $runner['status']);
        $this->assertSame('server', $runner['runner_repository']);
        $this->assertSame(
            'scripts/conformance/heartbeats-rust-published-artifacts.sh',
            $runner['runner_path'],
        );
        $this->assertSame('rust_sdk_heartbeat_loop', $runner['scenario_id']);
        $this->assertSame(
            'durable-workflow.v2.heartbeat-runtime.rust-sdk-loop-evidence',
            $runner['result_schema'],
        );
        $this->assertSame('rust-sdk-heartbeat-loop-evidence.json', $runner['result_file']);
        $this->assertTrue($runner['host_runner_implemented']);
        $this->assertTrue($runner['must_execute_against_published_artifacts']);
        $this->assertTrue($runner['must_record_runner_blocked_false_for_product_evidence']);
        $this->assertSame(['server', 'cli', 'sdk-rust'], $runner['required_artifact_pins']);
        $this->assertContains('durable_workflow::Client', $runner['required_worker_api']);
        $this->assertContains('durable_workflow::Worker::run_until()', $runner['required_worker_api']);
        $this->assertContains('registry_checksum_and_repository_provenance', $runner['must_prove']);
        $this->assertContains('at_least_two_sdk_emitted_heartbeat_timestamps', $runner['must_prove']);
        $this->assertContains('stale_rust_worker_poll_is_refused', $runner['must_prove']);
        $this->assertContains('fresh_rust_peer_remains_eligible_for_new_work', $runner['must_prove']);
        $this->assertSame([
            'php_sdk_heartbeat_loop',
            'python_sdk_heartbeat_loop',
            'waterline_worker_status_visibility',
        ], $runner['does_not_claim_scenarios']);
        $this->assertContains(
            'rust-sdk-heartbeat-loop-evidence.json',
            $manifest['host_runner_contract']['result_files'],
        );
        $this->assertSame(
            'sdk-rust-heartbeat-loop',
            $manifest['host_runner_contract']['runtime_shards']['sdk-rust']['focused_runner'],
        );
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
        $this->assertContains('stale_worker_routing_reports_zero_claims', $resultGate['pass_requires']);
        $this->assertContains(
            'two_worker_stale_routing_records_before_and_after_observations',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'stale_routing_records_conformance_run_id_timestamp_and_public_surfaces',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'fresh_worker_remains_eligible_after_peer_stale',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'adversarial_heartbeat_rejections_are_4xx_typed_and_not_persisted',
            $resultGate['pass_requires'],
        );
        $this->assertContains('cross_namespace_isolation_reports_zero_leaks', $resultGate['pass_requires']);
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

    public function test_result_gate_rejects_literal_observed_runtime_evidence(): void
    {
        $result = $this->completeHeartbeatResult();
        foreach ($result['scenario_results'] as $scenarioId => $scenarioResult) {
            foreach (array_keys($scenarioResult['observed_outputs']) as $field) {
                $result['scenario_results'][$scenarioId]['observed_outputs'][$field] = 'observed';
            }
        }
        $result['local_product_source_checkouts_used'] = false;
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['local_product_source_checkouts_used'] = false;
        foreach (['php_sdk_heartbeat_loop', 'python_sdk_heartbeat_loop', 'rust_sdk_heartbeat_loop'] as $scenarioId) {
            $result['scenario_results'][$scenarioId]['observed_outputs']['local_product_source_checkouts_used'] = false;
        }

        $evaluation = HeartbeatRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('sdk_heartbeat_loop_missing_successive_timestamps', $failureCodes);
        $this->assertContains('stale_worker_routing_claims_not_zero', $failureCodes);
        $this->assertContains('heartbeat_rejection_status_not_4xx', $failureCodes);
        $this->assertContains('cross_namespace_worker_leak_count_not_zero', $failureCodes);
    }

    public function test_result_gate_rejects_stale_worker_routing_claims_after_stale(): void
    {
        $result = $this->completeHeartbeatResult();
        $result['scenario_results']['stale_worker_routing_exclusion']['observed_outputs']['stale_worker_claim_count'] = 2;

        $evaluation = HeartbeatRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'stale_worker_routing_claims_not_zero'
                && ($failure['value'] ?? null) === 2,
        ));
    }

    public function test_result_gate_requires_fresh_worker_eligibility_to_name_declared_worker(): void
    {
        $result = $this->completeHeartbeatResult();
        $outputs = &$result['scenario_results']['stale_worker_routing_exclusion']['observed_outputs'];
        $outputs['fresh_worker_eligibility_after_stale'] = 'eligible';

        $genericEvaluation = HeartbeatRuntimeResultGate::evaluate($result);
        $genericFailureCodes = array_column($genericEvaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $genericEvaluation['status']);
        $this->assertContains('fresh_worker_not_eligible_after_peer_stale', $genericFailureCodes);

        $outputs['fresh_worker_eligibility_after_stale'] = [
            'worker_id' => 'rust-worker',
            'eligible' => true,
            'status' => 'active',
        ];

        $wrongWorkerEvaluation = HeartbeatRuntimeResultGate::evaluate($result);
        $wrongWorkerFailureCodes = array_column($wrongWorkerEvaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $wrongWorkerEvaluation['status']);
        $this->assertContains('fresh_worker_not_eligible_after_peer_stale', $wrongWorkerFailureCodes);
        unset($outputs);
    }

    public function test_result_gate_rejects_contradictory_fresh_worker_eligibility(): void
    {
        $result = $this->completeHeartbeatResult();
        $result['scenario_results']['stale_worker_routing_exclusion']['observed_outputs']['fresh_worker_eligibility_after_stale'] = [
            'worker_id' => 'php-worker',
            'eligible' => false,
            'status' => 'active',
        ];

        $evaluation = HeartbeatRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('fresh_worker_not_eligible_after_peer_stale', $failureCodes);
    }

    public function test_result_gate_requires_before_and_after_routing_observations_for_declared_workers(): void
    {
        $result = $this->completeHeartbeatResult();
        $outputs = &$result['scenario_results']['stale_worker_routing_exclusion']['observed_outputs'];
        $outputs['routing_observations_before_stale'] = [
            'phase' => 'both_workers_fresh',
            'eligible_workers' => ['php-worker', 'rust-worker'],
        ];
        $outputs['routing_observations_after_stale'] = [
            'phase' => 'python_worker_stale',
            'eligible_workers' => ['rust-worker'],
            'excluded_workers' => ['rust-worker'],
        ];

        $evaluation = HeartbeatRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('stale_worker_routing_before_missing_stale_worker_evidence', $failureCodes);
        $this->assertContains('stale_worker_routing_after_missing_fresh_worker_evidence', $failureCodes);
        $this->assertContains('stale_worker_routing_after_missing_stale_worker_exclusion', $failureCodes);
        unset($outputs);
    }

    public function test_result_gate_rejects_after_stale_routing_observation_contradictions(): void
    {
        $result = $this->completeHeartbeatResult();
        $outputs = &$result['scenario_results']['stale_worker_routing_exclusion']['observed_outputs'];
        $outputs['routing_observations_after_stale'] = [
            'phase' => 'python_worker_stale',
            'eligible_workers' => ['php-worker', 'python-worker'],
            'excluded_workers' => ['php-worker'],
        ];

        $evaluation = HeartbeatRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('stale_worker_routing_after_missing_stale_worker_exclusion', $failureCodes);
        $this->assertContains('stale_worker_routing_after_stale_worker_still_eligible', $failureCodes);
        $this->assertContains('stale_worker_routing_after_fresh_worker_excluded', $failureCodes);
        unset($outputs);
    }

    public function test_result_gate_rejects_incomplete_focused_stale_routing_evidence(): void
    {
        $result = $this->completeHeartbeatResult();
        $outputs = &$result['scenario_results']['stale_worker_routing_exclusion']['observed_outputs'];
        $outputs['fresh_worker_id'] = $outputs['stale_worker_id'];
        $outputs['configured_stale_threshold_seconds'] = 0;
        $outputs['observed_stale_transition_timing'] = [];
        $outputs['routing_observations_before_stale'] = [];
        $outputs['routing_observations_after_stale'] = [];
        $outputs['fresh_worker_eligibility_after_stale'] = false;
        $outputs['public_surfaces'] = ['internal harness'];
        $outputs['conformance_run_id'] = 'observed';
        $outputs['timestamp'] = 'not-a-date';
        unset($outputs);

        $evaluation = HeartbeatRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('stale_worker_routing_worker_ids_not_distinct', $failureCodes);
        $this->assertContains('stale_worker_routing_invalid_configured_threshold', $failureCodes);
        $this->assertContains('stale_worker_routing_missing_transition_timing', $failureCodes);
        $this->assertContains('stale_worker_routing_missing_before_observations', $failureCodes);
        $this->assertContains('stale_worker_routing_missing_after_observations', $failureCodes);
        $this->assertContains('fresh_worker_not_eligible_after_peer_stale', $failureCodes);
        $this->assertContains('stale_worker_routing_missing_worker_status_surface', $failureCodes);
        $this->assertContains('stale_worker_routing_missing_routing_surface', $failureCodes);
        $this->assertContains('stale_worker_routing_missing_conformance_run_id', $failureCodes);
        $this->assertContains('stale_worker_routing_missing_parseable_timestamp', $failureCodes);
    }

    public function test_result_gate_rejects_successful_or_untyped_heartbeat_rejections(): void
    {
        $result = $this->completeHeartbeatResult();
        $result['scenario_results']['malformed_heartbeat_rejection']['observed_outputs']['status'] = 200;
        $result['scenario_results']['malformed_heartbeat_rejection']['observed_outputs']['typed_error'] = 'observed';
        $result['scenario_results']['unregistered_heartbeat_rejection']['observed_outputs']['persisted'] = true;

        $evaluation = HeartbeatRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('heartbeat_rejection_status_not_4xx', $failureCodes);
        $this->assertContains('heartbeat_rejection_missing_typed_error', $failureCodes);
        $this->assertContains('heartbeat_rejection_persisted', $failureCodes);
    }

    public function test_result_gate_rejects_cross_namespace_worker_leakage(): void
    {
        $result = $this->completeHeartbeatResult();
        $result['scenario_results']['cross_namespace_isolation']['observed_outputs']['leak_count'] = 1;
        $result['scenario_results']['cross_namespace_isolation']['observed_outputs']['worker_list_b'][] = 'tenant-a-php-worker';

        $evaluation = HeartbeatRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('cross_namespace_worker_leak_count_not_zero', $failureCodes);
        $this->assertContains('cross_namespace_worker_ids_overlap', $failureCodes);
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
            'php_sdk_heartbeat_loop' => 'sdk-php',
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
            'php_sdk_heartbeat_loop' => 'sdk-php',
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
        ]['artifacts'][0]['version'] = 'durable-workflow/sdk:latest';

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
                && ($failure['artifact'] ?? null) === 'sdk-php'
                && ($failure['version'] ?? null) === 'durable-workflow/sdk:latest',
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
            'sdk-php' => '0.1.1',
            'sdk-python' => '0.4.85',
            'sdk-rust' => '0.1.0',
            'waterline' => '2.0.0-alpha.83',
        ];
        $sources = [
            'server' => 'published_docker_image',
            'cli' => 'official_install_script',
            'sdk-php' => 'packagist://durable-workflow/sdk@0.1.1',
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
        $scenarioResults['php_sdk_heartbeat_loop']['observed_outputs'] = array_replace(
            $scenarioResults['php_sdk_heartbeat_loop']['observed_outputs'],
            $this->sdkLoopOutputs('sdk-php', 'php-worker'),
        );
        $scenarioResults['python_sdk_heartbeat_loop']['observed_outputs'] = array_replace(
            $scenarioResults['python_sdk_heartbeat_loop']['observed_outputs'],
            $this->sdkLoopOutputs('sdk-python', 'python-worker'),
        );
        $scenarioResults['rust_sdk_heartbeat_loop']['observed_outputs'] = array_replace(
            $scenarioResults['rust_sdk_heartbeat_loop']['observed_outputs'],
            $this->sdkLoopOutputs('sdk-rust', 'rust-worker'),
        );
        $scenarioResults['php_sdk_heartbeat_loop']['observed_outputs']['published_artifact_worker_execution'] =
            $this->publishedWorkerExecution('sdk-php', $versions['sdk-php'], $sources['sdk-php']);
        $scenarioResults['python_sdk_heartbeat_loop']['observed_outputs']['published_artifact_worker_execution'] =
            $this->publishedWorkerExecution('sdk-python', $versions['sdk-python'], $sources['sdk-python']);
        $scenarioResults['rust_sdk_heartbeat_loop']['observed_outputs']['published_artifact_worker_execution'] =
            $this->publishedWorkerExecution('sdk-rust', $versions['sdk-rust'], $sources['sdk-rust']);
        $scenarioResults['heartbeat_wire_shape_uniformity']['observed_outputs'] = [
            'runtime_records' => [
                'sdk-php' => ['worker_id', 'task_queue', 'runtime', 'task_slots', 'process_metrics'],
                'sdk-python' => ['worker_id', 'task_queue', 'runtime', 'task_slots', 'process_metrics'],
                'sdk-rust' => ['worker_id', 'task_queue', 'runtime', 'task_slots', 'process_metrics'],
            ],
            'common_field_set' => [
                'worker_id',
                'namespace',
                'task_queue',
                'runtime',
                'task_slots',
                'process_metrics',
                'last_heartbeat_at',
            ],
            'language_specific_field_diff' => [],
            'server_records' => [
                ['worker_id' => 'php-worker', 'runtime' => 'sdk-php'],
                ['worker_id' => 'python-worker', 'runtime' => 'sdk-python'],
                ['worker_id' => 'rust-worker', 'runtime' => 'sdk-rust'],
            ],
        ];
        $scenarioResults['cadence_drift_window']['observed_outputs'] = [
            'heartbeat_timestamps' => [
                'php-worker' => ['2026-06-05T16:00:00Z', '2026-06-05T16:01:00Z', '2026-06-05T16:02:01Z'],
                'python-worker' => ['2026-06-05T16:00:03Z', '2026-06-05T16:01:02Z', '2026-06-05T16:02:02Z'],
                'rust-worker' => ['2026-06-05T16:00:05Z', '2026-06-05T16:01:04Z', '2026-06-05T16:02:04Z'],
            ],
            'inter_arrival_seconds' => [
                'php-worker' => [60, 61],
                'python-worker' => [59, 60],
                'rust-worker' => [59, 60],
            ],
            'nominal_interval_seconds' => 60,
            'tolerance_percent' => 20,
        ];
        $scenarioResults['stale_worker_transition_timing']['observed_outputs'] = [
            'stopped_worker_id' => 'python-worker',
            'stop_timestamp' => '2026-06-05T16:03:00Z',
            'stale_after_seconds' => 60,
            'probe_grace_seconds' => 10,
            'disappeared_from_default_list_at' => '2026-06-05T16:04:03Z',
            'stale_list_entry' => ['worker_id' => 'python-worker', 'status' => 'stale'],
        ];
        $scenarioResults['stale_worker_routing_exclusion']['observed_outputs'] = [
            'stale_worker_id' => 'python-worker',
            'fresh_worker_id' => 'php-worker',
            'configured_stale_threshold_seconds' => 60,
            'observed_stale_transition_timing' => [
                'stop_timestamp' => '2026-06-05T16:03:00Z',
                'stale_observed_at' => '2026-06-05T16:04:03Z',
                'observed_seconds' => 63,
            ],
            'routing_observations_before_stale' => [
                'phase' => 'both_workers_fresh',
                'task_queue' => 'hb-shared',
                'eligible_workers' => ['php-worker', 'python-worker'],
                'workflow_start_admitted' => true,
            ],
            'routing_observations_after_stale' => [
                'phase' => 'python_worker_stale',
                'task_queue' => 'hb-shared',
                'eligible_workers' => ['php-worker'],
                'excluded_workers' => ['python-worker'],
                'workflow_start_admitted' => true,
                'query_task_lease_owners' => ['php-worker'],
            ],
            'fresh_worker_eligibility_after_stale' => [
                'worker_id' => 'php-worker',
                'eligible' => true,
                'status' => 'active',
            ],
            'stale_worker_claim_count' => 0,
            'public_surfaces' => [
                'GET /api/workers',
                'GET /api/workers/{workerId}',
                'POST /api/workflows',
                'POST /api/worker/query-tasks/poll',
            ],
            'conformance_run_id' => '6671',
            'timestamp' => '2026-06-05T16:04:05Z',
        ];
        $scenarioResults['waterline_worker_status_visibility']['observed_outputs'] = [
            'surface_snapshot' => ['view' => 'Waterline Worker Status', 'workers' => 3],
            'stale_worker_render' => ['worker_id' => 'python-worker', 'status' => 'stale'],
            'task_slots_render' => ['workflow_available' => 2, 'activity_available' => 4],
            'process_metrics_render' => ['cpu_percent' => 13.5, 'memory_bytes' => 104857600],
        ];
        $scenarioResults['malformed_heartbeat_rejection']['observed_outputs'] = [
            'request' => ['worker_id' => '', 'task_queue' => 'hb-shared'],
            'status' => 422,
            'typed_error' => 'validation_error',
            'persisted' => false,
        ];
        $scenarioResults['unregistered_heartbeat_rejection']['observed_outputs'] = [
            'request' => ['worker_id' => 'missing-worker', 'task_queue' => 'hb-shared'],
            'status' => 404,
            'typed_error' => 'worker_not_registered',
            'persisted' => false,
        ];
        $scenarioResults['cross_namespace_isolation']['observed_outputs'] = [
            'namespaces' => ['heartbeats-conformance', 'heartbeats-conformance-other'],
            'worker_list_a' => ['tenant-a-php-worker', 'tenant-a-python-worker'],
            'worker_list_b' => ['tenant-b-rust-worker'],
            'leak_count' => 0,
        ];

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
     * @param  list<string>  $fields
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
    private function sdkLoopOutputs(string $runtime, string $workerId): array
    {
        return [
            'runtime' => $runtime,
            'worker_id' => $workerId,
            'registered_types' => [
                'workflows' => ['HeartbeatWorkflow'],
                'activities' => ['HeartbeatActivity'],
            ],
            'heartbeat_timestamps' => [
                '2026-06-05T16:00:00Z',
                '2026-06-05T16:01:00Z',
                '2026-06-05T16:02:00Z',
            ],
            'task_slots' => [
                'workflow_available' => 2,
                'activity_available' => 4,
            ],
            'process_metrics' => [
                'cpu_percent' => 12.5,
                'memory_bytes' => 104857600,
                'process_uptime_seconds' => 180,
            ],
            'local_product_source_checkouts_used' => false,
        ];
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
                'sdk-php' => '0.1.1',
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
