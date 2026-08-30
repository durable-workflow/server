<?php

namespace Tests\Feature;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\ActivityRuntimeContract;
use App\Support\ActivityRuntimeResultGate;
use App\Support\ChildWorkflowRuntimeContract;
use App\Support\ChildWorkflowRuntimeResultGate;
use App\Support\ControlPlaneProtocol;
use App\Support\CoordinationHealthContract;
use App\Support\HeartbeatRuntimeContract;
use App\Support\HeartbeatRuntimeResultGate;
use App\Support\MigrationRuntimeContract;
use App\Support\MigrationRuntimeResultGate;
use App\Support\NamespaceRuntimeContract;
use App\Support\NamespaceRuntimeResultGate;
use App\Support\PhpSdkConformanceContract;
use App\Support\PrereleaseReadinessContract;
use App\Support\PrereleaseReadinessResultGate;
use App\Support\PrincipalAttributionContract;
use App\Support\PrincipalAttributionResultGate;
use App\Support\PythonSdkParityContract;
use App\Support\SagaRuntimeContract;
use App\Support\SagaRuntimeResultGate;
use App\Support\SchedulesRuntimeContract;
use App\Support\SchedulesRuntimeResultGate;
use App\Support\SearchAttributeRuntimeContract;
use App\Support\SearchAttributeRuntimeResultGate;
use App\Support\ServerTopology;
use App\Support\SignalQueryRuntimeContract;
use App\Support\SignalQueryRuntimeResultGate;
use App\Support\SingleRegionFailoverContract;
use App\Support\SkewRefusalMatrixContract;
use App\Support\SkewRefusalMatrixResultGate;
use App\Support\TimerRuntimeContract;
use App\Support\TimerRuntimeResultGate;
use App\Support\WorkerVersioningRuntimeContract;
use App\Support\WorkerVersioningRuntimeResultGate;
use App\Support\WorkflowLifecycleContract;
use App\Support\WorkflowLifecycleResultGate;
use App\Support\WorkflowTaskLeaseConfiguration;
use App\Support\WorkflowUpdateRuntimeContract;
use App\Support\WorkflowUpdateRuntimeResultGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class ClusterInfoTest extends TestCase
{
    use RefreshDatabase;

    private ?string $provenanceFixturePath = null;

    /** @var list<string> */
    private array $externalExecutorConfigFixturePaths = [];

    public function test_cluster_info_refuses_explicit_unsupported_control_plane_version(): void
    {
        $this->getJson('/api/cluster/info', [
            ControlPlaneProtocol::HEADER => '999',
        ])
            ->assertStatus(400)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('reason', 'unsupported_control_plane_version')
            ->assertJsonPath('supported_version', ControlPlaneProtocol::VERSION)
            ->assertJsonPath('requested_version', '999');
    }

    protected function tearDown(): void
    {
        if ($this->provenanceFixturePath !== null && is_file($this->provenanceFixturePath)) {
            @unlink($this->provenanceFixturePath);
        }

        foreach ($this->externalExecutorConfigFixturePaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->provenanceFixturePath = null;
        $this->externalExecutorConfigFixturePaths = [];

        parent::tearDown();
    }

    /**
     * Allocate a per-test provenance fixture outside the repo root, point
     * server.package_provenance_path at it, and write the supplied lines.
     * tearDown() removes the fixture.
     *
     * @param  array<int, string>  $lines
     */
    private function useProvenanceFixture(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dw-provenance-');

        if ($path === false) {
            $this->fail('Could not allocate a tempfile for the provenance fixture.');
        }

        file_put_contents($path, implode("\n", $lines));

        config(['server.package_provenance_path' => $path]);

        return $this->provenanceFixturePath = $path;
    }

    /**
     * @param  array<string, mixed>|string  $document
     */
    private function useExternalExecutorConfigFixture(array|string $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dw-executor-config-');

        if ($path === false) {
            $this->fail('Could not allocate a tempfile for the external executor config fixture.');
        }

        $contents = is_array($document)
            ? json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : $document;

        if (! is_string($contents)) {
            $this->fail('Could not encode the external executor config fixture.');
        }

        file_put_contents($path, $contents);
        config(['server.external_executor.config_path' => $path]);

        $this->externalExecutorConfigFixturePaths[] = $path;

        return $path;
    }

    public function test_it_publishes_a_versioned_control_plane_request_contract_manifest(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath(
                'control_plane.request_contract.schema',
                'durable-workflow.v2.control-plane-request.contract',
            )
            ->assertJsonPath('control_plane.request_contract.version', 1)
            ->assertJsonPath(
                'control_plane.request_contract.operations.start.fields.duplicate_policy.canonical_values.1',
                'use-existing',
            )
            ->assertJsonPath(
                'control_plane.request_contract.operations.update.removed_fields.wait_policy',
                'Use wait_for.',
            );
    }

    public function test_it_publishes_a_workflow_start_rejection_contract_in_the_response_manifest(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath(
                'control_plane.response_contract.schema',
                'durable-workflow.v2.control-plane-response',
            )
            ->assertJsonPath('control_plane.response_contract.version', 1);

        $startContract = $response->json('control_plane.response_contract.operations.start');

        $this->assertIsArray($startContract);
        $this->assertContains('workflow_id', $startContract['rejection_fields']);
        $this->assertContains('command_status', $startContract['rejection_fields']);
        $this->assertContains('command_source', $startContract['rejection_fields']);
        $this->assertContains('rejection_reason', $startContract['rejection_fields']);
        $this->assertContains('outcome', $startContract['rejection_fields']);
        $this->assertContains('reason', $startContract['rejection_fields']);
        $this->assertContains('message', $startContract['rejection_fields']);
        $this->assertContains('workflow_id_reserved_in_namespace', $startContract['rejection_reasons']);
        $this->assertContains('task_queue_draining', $startContract['rejection_reasons']);
        $this->assertContains('compatibility_blocked', $startContract['rejection_reasons']);
    }

    public function test_it_publishes_a_signal_rejection_contract_in_the_response_manifest(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk();

        $signalContract = $response->json('control_plane.response_contract.operations.signal');

        $this->assertIsArray($signalContract);
        $this->assertContains('run_id', $signalContract['rejection_fields']);
        $this->assertContains('target_scope', $signalContract['rejection_fields']);
        $this->assertContains('command_contract_source', $signalContract['rejection_fields']);
        $this->assertContains('declared_signals', $signalContract['rejection_fields']);
        $this->assertContains('instance_not_found', $signalContract['rejection_reasons']);
        $this->assertContains('historical_run_command_rejected', $signalContract['rejection_reasons']);
        $this->assertContains('unknown_signal', $signalContract['rejection_reasons']);
    }

    public function test_it_publishes_a_query_rejection_contract_in_the_response_manifest(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk();

        $queryContract = $response->json('control_plane.response_contract.operations.query');

        $this->assertIsArray($queryContract);
        $this->assertContains('result_envelope', $queryContract['success_fields']);
        $this->assertContains('run_id', $queryContract['rejection_fields']);
        $this->assertContains('target_scope', $queryContract['rejection_fields']);
        $this->assertContains('query_name', $queryContract['rejection_fields']);
        $this->assertContains('blocked_reason', $queryContract['rejection_fields']);
        $this->assertContains('validation_errors', $queryContract['rejection_fields']);
        $this->assertContains('instance_not_found', $queryContract['rejection_reasons']);
        $this->assertContains('historical_run_command_rejected', $queryContract['rejection_reasons']);
        $this->assertContains('invalid_query_arguments', $queryContract['rejection_reasons']);
        $this->assertContains('query_worker_unavailable', $queryContract['rejection_reasons']);
        $this->assertContains('query_worker_execution_timeout', $queryContract['rejection_reasons']);
    }

    public function test_it_publishes_the_signal_query_runtime_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('signal_query_runtime_contract.schema', SignalQueryRuntimeContract::SCHEMA)
            ->assertJsonPath('signal_query_runtime_contract.version', SignalQueryRuntimeContract::VERSION)
            ->assertJsonPath(
                'signal_query_runtime_contract.fixture_category',
                'signal_query_runtime_contract',
            )
            ->assertJsonPath(
                'signal_query_runtime_contract.coverage_gate.smoke_subset_outcome',
                'non_passing',
            );

        $contract = $response->json('signal_query_runtime_contract');
        $this->assertIsArray($contract);
        $this->assertContains('sdk-php', $contract['required_matrix']['runtimes']);
        $this->assertContains('sdk-python', $contract['required_matrix']['runtimes']);
        $this->assertContains('sdk-rust', $contract['required_matrix']['runtimes']);
        foreach ([
            'rust_worker_rust_php_python_clients',
            'python_worker_rust_client',
            'php_worker_rust_client',
            'rust_query_error_and_immutability',
            'rust_replayed_instance_state_query_after_cold_restart',
        ] as $rustScenario) {
            $this->assertContains($rustScenario, $contract['required_scenarios']);
        }
        $this->assertContains('waterline_operator_visibility', $contract['required_scenarios']);
        $this->assertContains(
            'findings_linked_for_non_pass_scenarios',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'omitted_required_scenarios_link_findings',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertSame(
            SignalQueryRuntimeResultGate::SCHEMA,
            $contract['result_gate']['schema'],
        );
        $this->assertContains(
            'every_required_scenario_has_one_result',
            $contract['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'published_artifact_versions_are_recorded_and_pinned',
            $contract['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'omitted_required_scenarios_link_findings',
            $contract['result_gate']['pass_requires'],
        );
        $this->assertTrue($contract['result_gate']['artifact_version_policy']['rejects_placeholder_versions']);
    }

    public function test_it_publishes_the_php_sdk_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.php_sdk_conformance_contract', true)
            ->assertJsonPath('php_sdk_conformance_contract.schema', PhpSdkConformanceContract::SCHEMA)
            ->assertJsonPath('php_sdk_conformance_contract.version', PhpSdkConformanceContract::VERSION)
            ->assertJsonPath(
                'php_sdk_conformance_contract.product_boundary.remote_package',
                'durable-workflow/sdk',
            );

        $contract = $response->json('php_sdk_conformance_contract');
        $this->assertContains('durable_replay', $contract['required_scenarios']);
        $this->assertContains('apache_avro_provenance', $contract['required_evidence']);
        $this->assertSame(
            'scripts/conformance/php-sdk-published-artifacts.sh',
            $contract['host_runner_contract']['scenario_runner_path'],
        );
    }

    public function test_it_publishes_the_activity_runtime_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.activity_runtime_contract', true)
            ->assertJsonPath('activity_runtime_contract.schema', ActivityRuntimeContract::SCHEMA)
            ->assertJsonPath('activity_runtime_contract.version', ActivityRuntimeContract::VERSION)
            ->assertJsonPath(
                'activity_runtime_contract.fixture_category',
                'activity_runtime_contract',
            )
            ->assertJsonPath(
                'activity_runtime_contract.coverage_gate.smoke_subset_outcome',
                'non_passing',
            );

        $contract = $response->json('activity_runtime_contract');
        $this->assertIsArray($contract);
        $this->assertSame(
            'https://durable-workflow.github.io/platform-conformance/activity-runtime-scenarios.json',
            $contract['scenario_manifest']['public_path'],
        );
        $this->assertSame(
            'static/platform-conformance/activity-runtime-scenarios.json',
            $contract['scenario_manifest']['source_path'],
        );
        $this->assertArrayHasKey('waterline', $contract['artifact_policy']['install_channels']);
        $this->assertArrayHasKey('sdk-php', $contract['artifact_policy']['install_channels']);
        $this->assertContains('workflow-embedded', $contract['required_matrix']['execution_modes']);
        $this->assertContains('standalone', $contract['required_matrix']['execution_modes']);
        $this->assertContains('sdk-php', $contract['required_matrix']['runtimes']);
        $this->assertContains('sdk-python', $contract['required_matrix']['runtimes']);
        foreach ([
            'workflow_embedded_activity_result',
            'standalone_activity_result',
            'durable_result_recording_after_worker_restart',
            'retry_attempt_backoff_behavior',
            'timeout_behavior',
            'typed_failure_propagation',
            'heartbeat_and_cancellation_observation',
            'heartbeat_timeout_renewal_across_enforcement_passes',
            'idempotent_completion_handling',
            'php_python_activity_parity',
            'operator_visible_activity_attempt_state',
        ] as $scenarioId) {
            $this->assertContains($scenarioId, $contract['required_scenarios']);
        }
        $this->assertContains(
            'non_pass_cells_classified_by_root_cause',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertSame(
            'required_for_passing_activities_conformance',
            $contract['host_runner_contract']['status'],
        );
        $this->assertSame(
            'scripts/conformance/activities-published-artifacts.sh',
            $contract['host_runner_contract']['runner_path'],
        );
        $this->assertSame(
            'coverage-gap',
            $contract['host_runner_contract']['routing_policy']['missing_required_scenario']['classification'],
        );
        $this->assertSame(
            ActivityRuntimeResultGate::SCHEMA,
            $contract['result_gate']['schema'],
        );
        $this->assertContains(
            'workflow_embedded_and_standalone_activity_modes_are_reported',
            $contract['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'non_pass_cells_are_classified_by_root_cause',
            $contract['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'published_artifact_install_sources_are_recorded_for_every_required_channel',
            $contract['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'runner_blocked_false_for_product_evidence',
            $contract['result_gate']['pass_requires'],
        );
    }

    public function test_it_publishes_the_timer_runtime_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.timer_runtime_contract', true)
            ->assertJsonPath('timer_runtime_contract.schema', TimerRuntimeContract::SCHEMA)
            ->assertJsonPath('timer_runtime_contract.version', TimerRuntimeContract::VERSION)
            ->assertJsonPath('timer_runtime_contract.fixture_category', 'timer_runtime_contract')
            ->assertJsonPath(
                'timer_runtime_contract.host_runner_contract.status',
                'published_handoff_proves_all_timer_runtime_cells_including_operator_visible_waiting_state',
            )
            ->assertJsonPath('timer_runtime_contract.host_runner_contract.host_runner_implemented', true)
            ->assertJsonPath(
                'timer_runtime_contract.host_runner_contract.runner_path',
                'scripts/conformance/timers-published-artifacts.sh',
            );

        $contract = $response->json('timer_runtime_contract');
        $this->assertIsArray($contract);
        $this->assertSame(
            'static/platform-conformance/timer-runtime-scenarios.json',
            $contract['scenario_manifest']['source_path'],
        );
        $this->assertContains('normal_sleep_completion', $contract['required_scenarios']);
        $this->assertContains('worker_restart_while_sleeping', $contract['required_scenarios']);
        $this->assertContains('server_restart_while_sleeping', $contract['required_scenarios']);
        $this->assertContains('replay_after_timer_fire', $contract['required_scenarios']);
        $this->assertContains('concurrent_timers_distinct_deadlines', $contract['required_scenarios']);
        $this->assertContains('cancellation_while_waiting', $contract['required_scenarios']);
        $this->assertContains('operator_visible_timer_waiting_state', $contract['required_scenarios']);
        $this->assertSame(
            'scripts/conformance/timers-published-artifacts.sh --result-dir <result-dir>',
            $contract['host_runner_contract']['runner_command'],
        );
        $this->assertContains('timer-runtime-result.json', $contract['host_runner_contract']['result_files']);
        $this->assertContains('timer-runtime-record.json', $contract['host_runner_contract']['result_files']);
        $this->assertTrue($contract['host_runner_contract']['must_execute_inside_pinned_published_server_image']);
        $this->assertTrue($contract['host_runner_contract']['no_local_product_source_checkout_pass_evidence']);
        $this->assertSame(
            ['cancelled', 'terminated', 'failed', 'completed'],
            $contract['scenario_requirements']['cancellation_while_waiting']['allowed_terminal_workflow_statuses'],
        );
        $this->assertSame(TimerRuntimeResultGate::SCHEMA, $contract['result_gate']['schema']);
        $this->assertContains(
            'concurrent_timer_resume_order_matches_wake_up_times',
            $contract['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'normal_sleep_completion_completes_at_or_after_wake_up',
            $contract['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'operator_waiting_state_uses_recognized_public_surface',
            $contract['result_gate']['pass_requires'],
        );
    }

    public function test_it_publishes_the_child_workflow_runtime_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('child_workflow_runtime_contract.schema', ChildWorkflowRuntimeContract::SCHEMA)
            ->assertJsonPath('child_workflow_runtime_contract.version', ChildWorkflowRuntimeContract::VERSION)
            ->assertJsonPath(
                'child_workflow_runtime_contract.fixture_category',
                'child_workflow_runtime_contract',
            )
            ->assertJsonPath(
                'child_workflow_runtime_contract.coverage_gate.smoke_subset_outcome',
                'non_passing',
            );

        $contract = $response->json('child_workflow_runtime_contract');
        $this->assertIsArray($contract);
        $this->assertSame(
            'https://durable-workflow.github.io/platform-conformance/child-workflow-runtime-scenarios.json',
            $contract['scenario_manifest']['public_path'],
        );
        $this->assertSame(
            'static/platform-conformance/child-workflow-runtime-scenarios.json',
            $contract['scenario_manifest']['source_path'],
        );
        $this->assertArrayHasKey('waterline', $contract['artifact_policy']['install_channels']);
        $this->assertContains('workflow-php', $contract['required_matrix']['runtimes']);
        $this->assertContains('sdk-python', $contract['required_matrix']['runtimes']);
        $this->assertContains('php_parent_python_child_cross_language', $contract['required_scenarios']);
        $this->assertContains('python_parent_php_child_cross_language', $contract['required_scenarios']);
        $this->assertContains('child_failure_round_trip_matrix', $contract['required_scenarios']);
        $this->assertContains('parent_cancellation_propagates_to_child', $contract['required_scenarios']);
        $this->assertContains('direct_child_cancellation_observed_by_parent', $contract['required_scenarios']);
        $this->assertContains('worker_restart_replay_preserves_child_outcome', $contract['required_scenarios']);
        $this->assertContains('concurrent_child_fan_out', $contract['required_scenarios']);
        $this->assertContains('child_workflow_namespace_contract', $contract['required_scenarios']);
        $this->assertContains(
            'findings_linked_for_non_pass_scenarios',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertSame(
            'required_for_passing_child_workflows_conformance',
            $contract['host_runner_contract']['status'],
        );
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $contract['host_runner_contract']['routing_policy']['missing_required_scenario']['finding_type'],
        );
        $this->assertSame(
            ChildWorkflowRuntimeResultGate::SCHEMA,
            $contract['result_gate']['schema'],
        );
        $this->assertContains(
            'every_required_scenario_has_one_result',
            $contract['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'each_pass_scenario_has_scenario_specific_evidence',
            $contract['result_gate']['pass_requires'],
        );
    }

    public function test_it_publishes_the_skew_refusal_matrix_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.skew_refusal_matrix_contract', true)
            ->assertJsonPath('skew_refusal_matrix_contract.schema', SkewRefusalMatrixContract::SCHEMA)
            ->assertJsonPath('skew_refusal_matrix_contract.version', SkewRefusalMatrixContract::VERSION)
            ->assertJsonPath(
                'skew_refusal_matrix_contract.coverage_gate.smoke_only_outcome',
                'non_passing_smoke_only',
            );

        $contract = $response->json('skew_refusal_matrix_contract');
        $this->assertIsArray($contract);
        foreach (['server', 'cli', 'sdk-python', 'workflow', 'sdk-php', 'waterline'] as $artifact) {
            $this->assertContains($artifact, $contract['artifact_policy']['required_artifacts']);
        }
        foreach (['cli', 'sdk-python', 'sdk-php', 'waterline'] as $surface) {
            $this->assertArrayHasKey($surface, $contract['required_surfaces']);
            $this->assertSame(
                ['compatible', 'backward_skew', 'forward_skew', 'outside_window'],
                $contract['required_surfaces'][$surface]['required_pairing_classes'],
            );
        }

        $this->assertSame(['register_and_drop'], $contract['worker_skew_classification']['blocking']);
        $this->assertSame(['stale_render'], $contract['waterline_skew_classification']['blocking']);
        $this->assertTrue($contract['coverage_gate']['all_advertised_requests_required_per_operation_group']);
        $this->assertTrue($contract['coverage_gate']['focused_findings_required_for_uncovered_cells']);
        $this->assertSame(
            'required_for_passing_skew_refusal_matrix_conformance',
            $contract['host_runner_contract']['status'],
        );
        $this->assertSame(
            'scripts/conformance/skew-published-artifacts.sh',
            $contract['host_runner_contract']['runner_path'],
        );
        $this->assertContains(
            'request-response-captures.json',
            $contract['host_runner_contract']['result_files'],
        );
        $this->assertTrue($contract['host_runner_contract']['must_execute_against_published_artifacts']);
        $this->assertTrue(
            $contract['host_runner_contract']['must_emit_result_for_every_required_surface_pairing_operation_group'],
        );
        $this->assertContains(
            'sdk-php-skew-surface-shard',
            $contract['host_runner_contract']['required_execution_scopes'],
        );
        $this->assertSame(
            'register_and_drop',
            $contract['host_runner_contract']['runtime_shards']['sdk-php']['blocking_classification'],
        );
        $this->assertSame(
            'stale_render',
            $contract['host_runner_contract']['runtime_shards']['waterline']['blocking_classification'],
        );
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $contract['host_runner_contract']['routing_policy']['missing_required_cell']['finding_type'],
        );
        $this->assertContains('request', $contract['operation_groups']['cluster_info_probe']['evidence']);
        $this->assertContains('status', $contract['operation_groups']['cluster_info_probe']['evidence']);
        $this->assertContains('request_body', $contract['operation_groups']['worker_lifecycle']['evidence']);
        $this->assertContains('status', $contract['operation_groups']['worker_lifecycle']['evidence']);
        $this->assertContains('response_body', $contract['operation_groups']['workflow_control_plane']['evidence']);
        $this->assertContains('status', $contract['operation_groups']['workflow_control_plane']['evidence']);
        $this->assertContains(
            'screenshot_or_dom_snapshot',
            $contract['operation_groups']['waterline_render']['evidence'],
        );
        $this->assertContains('status', $contract['operation_groups']['waterline_render']['evidence']);
        $this->assertContains(
            'waterline_skew_classification',
            $contract['operation_groups']['waterline_render']['evidence'],
        );
        $this->assertSame(SkewRefusalMatrixResultGate::SCHEMA, $contract['result_gate']['schema']);
        $this->assertContains(
            'smoke_only_results_remain_non_passing',
            $contract['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'operation_capture_ids_resolve_to_attached_request_response_captures',
            $contract['result_gate']['pass_requires'],
        );
    }

    public function test_it_publishes_the_search_attribute_runtime_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('search_attribute_runtime_contract.schema', SearchAttributeRuntimeContract::SCHEMA)
            ->assertJsonPath('search_attribute_runtime_contract.version', SearchAttributeRuntimeContract::VERSION)
            ->assertJsonPath(
                'search_attribute_runtime_contract.fixture_category',
                'search_attribute_runtime_contract',
            )
            ->assertJsonPath(
                'search_attribute_runtime_contract.coverage_gate.smoke_subset_outcome',
                'non_passing',
            );

        $contract = $response->json('search_attribute_runtime_contract');
        $this->assertIsArray($contract);
        $this->assertArrayHasKey('waterline', $contract['artifact_policy']['install_channels']);
        $this->assertContains('sdk-php', $contract['required_matrix']['runtimes']);
        $this->assertContains('sdk-python', $contract['required_matrix']['runtimes']);
        $this->assertContains('cli', $contract['required_matrix']['client_paths']);
        $this->assertContains('waterline-workflow-list-filter', $contract['required_matrix']['observer_paths']);
        $this->assertContains('php_worker_start_and_upsert_visibility', $contract['required_scenarios']);
        $this->assertContains('cli_query_and_error_surface', $contract['required_scenarios']);
        $this->assertContains('waterline_operator_visibility', $contract['required_scenarios']);
        $this->assertContains('python_to_php_codec_round_trip', $contract['required_scenarios']);
        $this->assertContains('php_to_python_codec_round_trip', $contract['required_scenarios']);
        $this->assertContains('load_and_bounded_latency', $contract['required_scenarios']);
        $this->assertContains('or_not_query_grammar', $contract['required_scenarios']);
        $this->assertContains('query_injection_hardening', $contract['required_scenarios']);
        $this->assertContains(
            'findings_linked_for_non_pass_scenarios',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertSame(
            SearchAttributeRuntimeResultGate::SCHEMA,
            $contract['result_gate']['schema'],
        );
        $this->assertContains(
            'every_required_scenario_has_one_result',
            $contract['result_gate']['pass_requires'],
        );
    }

    public function test_it_publishes_the_schedules_runtime_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('schedules_runtime_contract.schema', SchedulesRuntimeContract::SCHEMA)
            ->assertJsonPath('schedules_runtime_contract.version', SchedulesRuntimeContract::VERSION)
            ->assertJsonPath(
                'schedules_runtime_contract.fixture_category',
                'schedules_runtime_contract',
            )
            ->assertJsonPath(
                'schedules_runtime_contract.coverage_gate.smoke_subset_outcome',
                'non_passing',
            )
            ->assertJsonPath(
                'schedules_runtime_contract.scenario_manifest.suite_version',
                PlatformConformanceSuite::VERSION,
            );

        $contract = $response->json('schedules_runtime_contract');
        $this->assertIsArray($contract);
        $this->assertArrayHasKey('waterline', $contract['artifact_policy']['install_channels']);
        $this->assertSame(
            'fire_once_on_resume_then_skip_remaining_missed',
            $contract['schedule_policy']['missed_fire_policy'],
        );
        $this->assertContains('sdk-php', $contract['required_matrix']['runtimes']);
        $this->assertContains('sdk-python', $contract['required_matrix']['runtimes']);
        $this->assertContains('cli', $contract['required_matrix']['client_paths']);
        $this->assertContains('sdk-php', $contract['required_matrix']['client_paths']);
        $this->assertContains('cron_cadence', $contract['required_scenarios']);
        $this->assertContains('fixed_rate_cadence', $contract['required_scenarios']);
        $this->assertContains('pause_resume_no_fire_window', $contract['required_scenarios']);
        $this->assertContains('missed_fire_policy', $contract['required_scenarios']);
        $this->assertContains('restart_survival', $contract['required_scenarios']);
        $this->assertContains('cli_schedule_surface', $contract['required_scenarios']);
        $this->assertContains('python_created_php_workflow', $contract['required_scenarios']);
        $this->assertContains('php_created_python_workflow', $contract['required_scenarios']);
        $this->assertContains(
            'findings_linked_for_non_pass_scenarios',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertSame(
            'required_for_passing_schedules_conformance',
            $contract['host_runner_contract']['status'],
        );
        $this->assertContains(
            'cron-cadence-shard',
            $contract['host_runner_contract']['required_execution_scopes'],
        );
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $contract['host_runner_contract']['routing_policy']['missing_required_scenario']['finding_type'],
        );
        $this->assertSame(
            SchedulesRuntimeResultGate::SCHEMA,
            $contract['result_gate']['schema'],
        );
        $this->assertContains(
            'cross_language_schedule_workflow_cells_are_reported',
            $contract['result_gate']['pass_requires'],
        );
    }

    public function test_it_publishes_the_single_region_failover_rehearsal_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.single_region_failover_contract', true)
            ->assertJsonPath('single_region_failover_contract.schema', SingleRegionFailoverContract::SCHEMA)
            ->assertJsonPath('single_region_failover_contract.version', SingleRegionFailoverContract::VERSION)
            ->assertJsonPath(
                'single_region_failover_contract.scenario_manifest.suite_schema',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath(
                'single_region_failover_contract.scenario_manifest.source_path',
                'static/platform-conformance/single-region-failover-scenarios.json',
            )
            ->assertJsonPath(
                'single_region_failover_contract.host_runner_contract.runner_path',
                'scripts/conformance/single-region-failover-published-artifacts.sh',
            );

        $contract = $response->json('single_region_failover_contract');
        $this->assertSame(10, $contract['version']);
        $this->assertSame(10, $contract['recovery_bounds']['database_reclaim_after_lease_seconds']);
        $this->assertSame(2, $contract['required_topology']['api_nodes']);
        $this->assertSame(1, $contract['required_topology']['queue_workers']);
        $this->assertSame(1, $contract['required_topology']['scheduler_maintenance_runners']);
        $this->assertFalse($contract['required_topology']['sticky_sessions']);
        $this->assertContains('database_interruption', $contract['required_scenarios']);
        $this->assertContains('redis_interruption', $contract['required_scenarios']);
        $this->assertContains('worker_lease_loss', $contract['required_scenarios']);
        $this->assertContains('singleton_scheduler_restart', $contract['required_scenarios']);
        $this->assertTrue($contract['artifact_policy']['published_artifacts_only']);
        $this->assertTrue($contract['host_runner_contract']['must_fail_closed_on_local_product_runtime']);
        $this->assertSame('DW_FAILOVER_CONNECT_HOST', $contract['host_runner_contract']['connect_host_environment']);
        $this->assertContains(
            'readiness_observations',
            $contract['host_runner_contract']['topology_start_failure_evidence'],
        );
    }

    public function test_it_publishes_the_worker_versioning_runtime_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('worker_versioning_runtime_contract.schema', WorkerVersioningRuntimeContract::SCHEMA)
            ->assertJsonPath('worker_versioning_runtime_contract.version', WorkerVersioningRuntimeContract::VERSION)
            ->assertJsonPath(
                'worker_versioning_runtime_contract.fixture_category',
                'worker_versioning_runtime_contract',
            )
            ->assertJsonPath(
                'worker_versioning_runtime_contract.coverage_gate.smoke_subset_outcome',
                'non_passing',
            )
            ->assertJsonPath(
                'worker_versioning_runtime_contract.scenario_manifest.suite_version',
                PlatformConformanceSuite::VERSION,
            );

        $contract = $response->json('worker_versioning_runtime_contract');
        $this->assertIsArray($contract);
        $this->assertArrayHasKey('waterline', $contract['artifact_policy']['install_channels']);
        $this->assertContains('sdk-php', $contract['required_matrix']['runtimes']);
        $this->assertContains('sdk-python', $contract['required_matrix']['runtimes']);
        $this->assertContains('cli', $contract['required_matrix']['client_paths']);
        $this->assertContains('Waterline worker and workflow views', $contract['required_matrix']['operator_visibility_paths']);
        $this->assertContains('pin_on_start', $contract['required_scenarios']);
        $this->assertContains('replay_only_by_compatible_workers', $contract['required_scenarios']);
        $this->assertContains('new_starts_to_promoted_version', $contract['required_scenarios']);
        $this->assertContains('replay_across_cache_eviction', $contract['required_scenarios']);
        $this->assertContains('no_compatible_worker_behavior', $contract['required_scenarios']);
        $this->assertContains('cross_language_php_python_pinning', $contract['required_scenarios']);
        $this->assertContains('adversarial_no_version_bump', $contract['required_scenarios']);
        $this->assertContains('history_api_version_pin', $contract['required_scenarios']);
        $this->assertContains(
            'findings_linked_for_non_pass_scenarios',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertSame(
            'required_for_passing_worker_versioning_conformance',
            $contract['host_runner_contract']['status'],
        );
        $this->assertSame(
            'scripts/conformance/worker-versioning-published-artifacts.sh',
            $contract['host_runner_contract']['runner_path'],
        );
        $this->assertContains(
            'compatible_replay_delivery_counts',
            $contract['host_runner_contract']['evidence_shards'],
        );
        $this->assertContains(
            'cache_eviction_delivery_counts',
            $contract['host_runner_contract']['evidence_shards'],
        );
        $this->assertContains(
            'no_compatible_worker_diagnostics',
            $contract['host_runner_contract']['evidence_shards'],
        );
        $this->assertContains(
            'cross_language_php_python_delivery_counts',
            $contract['host_runner_contract']['evidence_shards'],
        );
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $contract['host_runner_contract']['routing_policy']['missing_required_scenario']['finding_type'],
        );
        $this->assertSame(
            WorkerVersioningRuntimeResultGate::SCHEMA,
            $contract['result_gate']['schema'],
        );
        $this->assertContains(
            'pin_replay_promotion_no_compatible_and_history_sections_are_reported',
            $contract['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'no_compatible_worker_has_zero_incompatible_delivery',
            $contract['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'no_compatible_worker_signal_is_explicit',
            $contract['result_gate']['pass_requires'],
        );
    }

    public function test_it_publishes_the_migration_runtime_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.migration_runtime_contract', true)
            ->assertJsonPath('migration_runtime_contract.schema', MigrationRuntimeContract::SCHEMA)
            ->assertJsonPath('migration_runtime_contract.version', MigrationRuntimeContract::VERSION)
            ->assertJsonPath(
                'migration_runtime_contract.fixture_category',
                'migration_runtime_contract',
            )
            ->assertJsonPath(
                'migration_runtime_contract.coverage_gate.storage_connection_smoke_only_outcome',
                'non_passing',
            )
            ->assertJsonPath(
                'migration_runtime_contract.scenario_manifest.suite_version',
                PlatformConformanceSuite::VERSION,
            );

        $contract = $response->json('migration_runtime_contract');
        $this->assertIsArray($contract);
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
            $this->assertArrayHasKey($artifact, $contract['artifact_policy']['install_channels']);
        }
        $this->assertContains('server-v1', $contract['required_matrix']['source_release_set']);
        $this->assertContains('cli-v1', $contract['required_matrix']['source_release_set']);
        $this->assertContains('workflow-php-v1', $contract['required_matrix']['source_release_set']);
        $this->assertContains('waterline-v1', $contract['required_matrix']['source_release_set']);
        $this->assertContains('sample-app-v1', $contract['required_matrix']['source_release_set']);
        $this->assertContains('server-v2', $contract['required_matrix']['target_release_set']);
        $this->assertContains('cli-v2', $contract['required_matrix']['target_release_set']);
        $this->assertContains('workflow-php-v2', $contract['required_matrix']['target_release_set']);
        $this->assertContains('sdk-python', $contract['required_matrix']['target_release_set']);
        $this->assertContains('waterline-v2', $contract['required_matrix']['target_release_set']);
        $this->assertContains('completed_history', $contract['required_matrix']['state_kinds']);
        $this->assertContains('in_flight_workflow', $contract['required_matrix']['state_kinds']);
        $this->assertContains('retrying_activity', $contract['required_matrix']['state_kinds']);
        $this->assertContains('schedule', $contract['required_matrix']['state_kinds']);
        $this->assertContains('worker_registration', $contract['required_matrix']['state_kinds']);
        $this->assertContains('latest_supported_v1_state_setup', $contract['required_scenarios']);
        $this->assertContains('documented_migration_steps_execute', $contract['required_scenarios']);
        $this->assertContains('completed_history_preservation_and_replay', $contract['required_scenarios']);
        $this->assertContains('in_flight_workflow_progress_preserved', $contract['required_scenarios']);
        $this->assertContains('mid_activity_retry_preserved', $contract['required_scenarios']);
        $this->assertContains('schedule_cross_upgrade_cadence_preserved', $contract['required_scenarios']);
        $this->assertContains('worker_registration_projection_preserved', $contract['required_scenarios']);
        $this->assertContains('waterline_operator_visibility_preserved', $contract['required_scenarios']);
        $this->assertContains('cli_access_to_preupgrade_state', $contract['required_scenarios']);
        $this->assertContains('new_v2_workflow_start_after_upgrade', $contract['required_scenarios']);
        $this->assertContains('rollback_contract_verified', $contract['required_scenarios']);
        $this->assertContains('version_skew_refusal', $contract['required_scenarios']);
        $this->assertSame(
            'required_context_not_passing_by_itself',
            $contract['advisory_evidence']['storage_connection_smoke']['status'],
        );
        $this->assertSame(
            'required_for_passing_migration_conformance',
            $contract['host_runner_contract']['status'],
        );
        $this->assertContains(
            'public-guide-upgrade',
            $contract['host_runner_contract']['required_execution_scopes'],
        );
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $contract['host_runner_contract']['routing_policy']['missing_required_scenario']['finding_type'],
        );
        $this->assertSame(
            MigrationRuntimeResultGate::SCHEMA,
            $contract['result_gate']['schema'],
        );
        $this->assertContains(
            'storage_connection_smoke_is_recorded_but_not_counted_as_complete',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
    }

    public function test_it_publishes_the_prerelease_readiness_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.prerelease_readiness_contract', true)
            ->assertJsonPath('prerelease_readiness_contract.schema', PrereleaseReadinessContract::SCHEMA)
            ->assertJsonPath('prerelease_readiness_contract.version', PrereleaseReadinessContract::VERSION)
            ->assertJsonPath(
                'prerelease_readiness_contract.fixture_category',
                'prerelease_readiness_contract',
            )
            ->assertJsonPath(
                'prerelease_readiness_contract.scenario_manifest.source_path',
                'static/platform-conformance/prerelease-readiness-scenarios.json',
            )
            ->assertJsonPath(
                'prerelease_readiness_contract.scenario_manifest.suite_version',
                PlatformConformanceSuite::VERSION,
            );

        $contract = $response->json('prerelease_readiness_contract');
        $this->assertIsArray($contract);

        foreach (['server', 'cli', 'sdk-python', 'workflow', 'waterline', 'sample-app', 'public-docs'] as $artifact) {
            $this->assertContains($artifact, $contract['required_matrix']['ecosystem_artifacts']);
            $this->assertArrayHasKey($artifact, $contract['artifact_policy']['install_channels']);
        }

        foreach ([
            'core_feature_completeness',
            'migration_readiness',
            'public_api_stability',
            'documentation_accuracy',
            'configuration_understandability',
            'cross_component_compatibility',
        ] as $category) {
            $this->assertContains($category, $contract['required_matrix']['readiness_categories']);
        }

        $this->assertContains('quickstart_local_server_hosted_completion', $contract['required_scenarios']);
        $this->assertContains('quickstart_laravel_branch_completion', $contract['required_scenarios']);
        $this->assertContains('focused_finding_routing', $contract['required_scenarios']);
        $this->assertSame(
            'non_passing',
            $contract['coverage_gate']['installability_smoke_only_outcome'],
        );
        $this->assertSame(
            'required_for_passing_prerelease_readiness_conformance',
            $contract['host_runner_contract']['status'],
        );
        $this->assertTrue($contract['host_runner_contract']['must_link_focused_findings_for_every_non_pass_scenario']);
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $contract['host_runner_contract']['routing_policy']['missing_required_scenario']['finding_type'],
        );
        $this->assertSame(
            PrereleaseReadinessResultGate::SCHEMA,
            $contract['result_gate']['schema'],
        );
    }

    public function test_it_publishes_the_saga_runtime_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('saga_runtime_contract.schema', SagaRuntimeContract::SCHEMA)
            ->assertJsonPath('saga_runtime_contract.version', SagaRuntimeContract::VERSION)
            ->assertJsonPath(
                'saga_runtime_contract.fixture_category',
                'saga_runtime_contract',
            )
            ->assertJsonPath(
                'saga_runtime_contract.coverage_gate.runner_blocked_outcome',
                'non_passing_runner_blocked',
            )
            ->assertJsonPath(
                'saga_runtime_contract.host_runner_contract.runner_path',
                'scripts/conformance/sagas-published-artifacts.sh',
            );

        $contract = $response->json('saga_runtime_contract');
        $this->assertIsArray($contract);
        $this->assertArrayHasKey('waterline', $contract['artifact_policy']['install_channels']);
        $this->assertTrue($contract['artifact_policy']['release_records_without_assets_are_rejected']);
        $this->assertContains('workflow-php', $contract['required_matrix']['workflow_runtimes']);
        $this->assertContains('sdk-python', $contract['required_matrix']['workflow_runtimes']);
        $this->assertContains('workflow-php', $contract['required_matrix']['activity_runtimes']);
        $this->assertContains('sdk-python', $contract['required_matrix']['activity_runtimes']);
        $this->assertContains('failure_at_d_reverse_compensation', $contract['required_scenarios']);
        $this->assertContains('compensation_retry_idempotence', $contract['required_scenarios']);
        $this->assertContains('mid_compensation_worker_restart', $contract['required_scenarios']);
        $this->assertContains('php_workflow_python_compensation', $contract['required_scenarios']);
        $this->assertContains('python_workflow_php_compensation', $contract['required_scenarios']);
        $this->assertContains('typed_compensation_error_round_trip', $contract['required_scenarios']);
        $this->assertContains('operator_visible_mid_compensation_status', $contract['required_scenarios']);
        $this->assertContains(
            'runner_blocked_false_for_product_evidence',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertSame(
            'required_for_passing_sagas_conformance',
            $contract['host_runner_contract']['status'],
        );
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $contract['host_runner_contract']['routing_policy']['missing_required_scenario']['finding_type'],
        );
        $this->assertSame(
            SagaRuntimeResultGate::SCHEMA,
            $contract['result_gate']['schema'],
        );
        $this->assertContains(
            'runner_blocked_false_for_product_evidence',
            $contract['result_gate']['pass_requires'],
        );
    }

    public function test_it_publishes_the_heartbeat_runtime_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.heartbeat_runtime_contract', true)
            ->assertJsonPath('heartbeat_runtime_contract.schema', HeartbeatRuntimeContract::SCHEMA)
            ->assertJsonPath('heartbeat_runtime_contract.version', HeartbeatRuntimeContract::VERSION)
            ->assertJsonPath(
                'heartbeat_runtime_contract.fixture_category',
                'heartbeat_runtime_contract',
            )
            ->assertJsonPath(
                'heartbeat_runtime_contract.coverage_gate.smoke_subset_outcome',
                'non_passing',
            )
            ->assertJsonPath(
                'heartbeat_runtime_contract.scenario_manifest.suite_version',
                PlatformConformanceSuite::VERSION,
            );

        $contract = $response->json('heartbeat_runtime_contract');
        $this->assertIsArray($contract);
        foreach (['server', 'cli', 'sdk-php', 'sdk-python', 'sdk-rust', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $contract['artifact_policy']['install_channels']);
        }
        $this->assertContains('sdk-php', $contract['required_matrix']['runtimes']);
        $this->assertContains('sdk-python', $contract['required_matrix']['runtimes']);
        $this->assertContains('sdk-rust', $contract['required_matrix']['runtimes']);
        $this->assertContains('dw worker:list', $contract['required_matrix']['operator_visibility_paths']);
        $this->assertContains('Waterline Worker Status view', $contract['required_matrix']['operator_visibility_paths']);
        $this->assertContains('stale_workers_excluded_from_workflow_start', $contract['required_matrix']['routing_cells']);
        $this->assertContains('stale_workers_excluded_from_query_tasks', $contract['required_matrix']['routing_cells']);
        $this->assertContains('fresh_worker_remains_eligible_after_peer_stale', $contract['required_matrix']['routing_cells']);
        $this->assertContains('php_sdk_heartbeat_loop', $contract['required_scenarios']);
        $this->assertContains('python_sdk_heartbeat_loop', $contract['required_scenarios']);
        $this->assertContains('rust_sdk_heartbeat_loop', $contract['required_scenarios']);
        $this->assertContains('stale_worker_transition_timing', $contract['required_scenarios']);
        $this->assertContains('stale_worker_routing_exclusion', $contract['required_scenarios']);
        $this->assertContains('waterline_worker_status_visibility', $contract['required_scenarios']);
        $this->assertContains('cross_namespace_isolation', $contract['required_scenarios']);
        $this->assertContains(
            'runner_blocked_false_for_product_evidence',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'fresh_worker_remains_eligible_after_peer_stale',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertSame(
            'required_for_passing_heartbeats_conformance',
            $contract['host_runner_contract']['status'],
        );
        $this->assertContains(
            'waterline-worker-status',
            $contract['host_runner_contract']['required_execution_scopes'],
        );
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $contract['host_runner_contract']['routing_policy']['missing_required_scenario']['finding_type'],
        );
        $this->assertSame(
            HeartbeatRuntimeResultGate::SCHEMA,
            $contract['result_gate']['schema'],
        );
        $this->assertContains(
            'smoke_only_results_remain_non_passing',
            $contract['result_gate']['pass_requires'],
        );
    }

    public function test_it_publishes_the_principal_attribution_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('principal_attribution_contract.schema', PrincipalAttributionContract::SCHEMA)
            ->assertJsonPath('principal_attribution_contract.version', PrincipalAttributionContract::VERSION)
            ->assertJsonPath(
                'principal_attribution_contract.fixture_category',
                'principal_attribution_contract',
            )
            ->assertJsonPath(
                'principal_attribution_contract.coverage_gate.runner_blocked_outcome',
                'non_passing_runner_blocked',
            )
            ->assertJsonPath(
                'principal_attribution_contract.host_runner_contract.runner_path',
                'scripts/conformance/principal-attribution-published-artifacts.sh',
            );

        $contract = $response->json('principal_attribution_contract');
        $this->assertIsArray($contract);
        $this->assertContains('named_token_actor_matrix', $contract['required_scenarios']);
        $this->assertContains('start_signal_cancel_spoofing', $contract['required_scenarios']);
        $this->assertContains('completion_failure_attribution', $contract['required_scenarios']);
        $this->assertContains('cli_operator_visibility', $contract['required_scenarios']);
        $this->assertContains('waterline_operator_visibility', $contract['required_scenarios']);
        $this->assertContains(
            'runner_blocked_false_for_product_evidence',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'X-Workflow-Principal-Id',
            $contract['spoofing_guards']['request_headers'],
        );
        $this->assertContains(
            'X-Workflow-Caller-Type',
            $contract['spoofing_guards']['request_headers'],
        );
        $this->assertContains(
            'X-Workflow-Auth-Method',
            $contract['spoofing_guards']['request_headers'],
        );
        $this->assertContains(
            'Authorization-Override',
            $contract['spoofing_guards']['request_headers'],
        );
        $this->assertSame(
            'Bearer mallory',
            $contract['spoofing_guards']['request_header_values']['Authorization-Override'],
        );
        $this->assertSame(
            'required_for_passing_principal_attribution_conformance',
            $contract['host_runner_contract']['status'],
        );
        $this->assertSame(
            PrincipalAttributionResultGate::SCHEMA,
            $contract['result_gate']['schema'],
        );
        $this->assertContains(
            'each_non_pass_scenario_has_focused_linked_findings',
            $contract['result_gate']['pass_requires'],
        );
    }

    public function test_it_publishes_the_python_sdk_parity_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('python_sdk_parity_contract.schema', PythonSdkParityContract::SCHEMA)
            ->assertJsonPath('python_sdk_parity_contract.version', PythonSdkParityContract::VERSION)
            ->assertJsonPath(
                'python_sdk_parity_contract.fixture_category',
                'python_sdk_published_artifact_parity',
            )
            ->assertJsonPath(
                'capabilities.python_sdk_parity_contract',
                true,
            )
            ->assertJsonPath(
                'python_sdk_parity_contract.coverage_gate.smoke_subset_outcome',
                'non_passing',
            )
            ->assertJsonPath(
                'python_sdk_parity_contract.host_runner_contract.runner_path',
                'scripts/conformance/python-published-artifacts.sh',
            );

        $contract = $response->json('python_sdk_parity_contract');
        $this->assertIsArray($contract);
        $this->assertContains('official_cli_install_start_result_path', $contract['required_scenarios']);
        $this->assertContains('cold_first_user_setup', $contract['required_scenarios']);
        $this->assertContains('protocol_trace_capture', $contract['required_scenarios']);
        $this->assertContains('php_assumption_audit', $contract['required_scenarios']);
        $this->assertContains('capability_table_complete', $contract['required_scenarios']);
        $this->assertContains('runtime_external_payload_round_trips', $contract['required_scenarios']);
        $this->assertContains('cli_reads_workflow_result', $contract['required_capabilities']);
        $this->assertContains('runtime_external_payload_isolated_cloud', $contract['required_capabilities']);
        $this->assertContains('protocol_traces_recorded', $contract['required_capabilities']);
        $this->assertSame(
            'durable_workflow.python_conformance',
            $contract['python_result_gate_authority']['module'],
        );
        $this->assertContains(
            'official-cli-install-start-result',
            $contract['host_runner_contract']['required_execution_scopes'],
        );
        $this->assertContains(
            'complete-python-capability-table',
            $contract['host_runner_contract']['required_execution_scopes'],
        );
    }

    public function test_it_publishes_the_namespace_runtime_conformance_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('namespace_runtime_contract.schema', NamespaceRuntimeContract::SCHEMA)
            ->assertJsonPath('namespace_runtime_contract.version', NamespaceRuntimeContract::VERSION)
            ->assertJsonPath(
                'namespace_runtime_contract.fixture_category',
                'namespace_runtime_contract',
            )
            ->assertJsonPath(
                'namespace_runtime_contract.coverage_gate.smoke_subset_outcome',
                'non_passing',
            );

        $contract = $response->json('namespace_runtime_contract');
        $this->assertIsArray($contract);
        $this->assertArrayHasKey('waterline', $contract['artifact_policy']['install_channels']);
        $this->assertContains('tenant-a', $contract['required_matrix']['namespaces']);
        $this->assertContains('tenant-b', $contract['required_matrix']['namespaces']);
        $this->assertContains('shared', $contract['required_matrix']['namespaces']);
        $this->assertContains('sdk-php', $contract['required_matrix']['runtimes']);
        $this->assertContains('sdk-python', $contract['required_matrix']['runtimes']);
        $this->assertSame(
            'static/platform-conformance/namespace-runtime-scenarios.json',
            $contract['scenario_manifest']['source_path'],
        );
        $this->assertSame(
            PlatformConformanceSuite::VERSION,
            $contract['scenario_manifest']['suite_version'],
        );
        $this->assertContains('namespace_lifecycle_cleanup_and_recreate', $contract['required_scenarios']);
        $this->assertContains('nexus_explicit_cross_namespace_invocation', $contract['required_scenarios']);
        $this->assertContains('waterline_operator_namespace_visibility', $contract['required_scenarios']);
        $this->assertContains(
            'waterline-operator-namespace-shard',
            $contract['host_runner_contract']['required_execution_scopes'],
        );
        $this->assertSame(
            'scripts/conformance/namespaces-published-artifacts.sh',
            $contract['host_runner_contract']['runner_path'],
        );
        $this->assertSame(
            'scripts/conformance/namespaces-published-artifacts.sh --result-dir <result-dir>',
            $contract['host_runner_contract']['runner_command'],
        );
        $this->assertSame(
            'php-sdk-published-artifacts',
            $contract['host_runner_contract']['runtime_shards']['sdk-php']['preferred_command'],
        );
        $this->assertSame(
            'scripts/conformance/php-sdk-published-artifacts.sh',
            $contract['host_runner_contract']['runtime_shards']['sdk-php']['runner_path'],
        );
        $this->assertSame(
            [
                'namespace_create_update_describe_and_list',
                'sdk_namespace_selection_parity',
                'php_worker_task_queue_namespace_isolation',
            ],
            $contract['host_runner_contract']['runtime_shards']['sdk-php']['must_cover_scenarios'],
        );
        $this->assertSame(
            'waterline:namespace-conformance',
            $contract['host_runner_contract']['runtime_shards']['waterline']['artisan_command'],
        );
        $this->assertContains(
            'sdk_php_namespace_shard_execution_recorded',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'search_attribute_value_query_isolation_reported',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertSame(
            NamespaceRuntimeResultGate::SCHEMA,
            $contract['result_gate']['schema'],
        );
        $this->assertContains(
            'each_pass_scenario_has_scenario_specific_evidence',
            $contract['result_gate']['pass_requires'],
        );
    }

    public function test_it_publishes_workflow_lifecycle_contract_manifest(): void
    {
        $response = $this->getJson('/api/cluster/info')->assertOk();
        $contract = $response->json('workflow_lifecycle_contract');

        $this->assertSame(WorkflowLifecycleContract::SCHEMA, $contract['schema']);
        $this->assertSame('workflow_lifecycle_contract', $contract['fixture_category']);
        $this->assertSame(
            'static/platform-conformance/workflow-lifecycle-scenarios.json',
            $contract['scenario_manifest']['source_path'],
        );
        $this->assertContains('artifact_sources', $contract['artifact_policy']['required_run_record_fields']);
        $this->assertContains('lifecycle_cell_outcomes', $contract['artifact_policy']['required_run_record_fields']);
        $this->assertContains('findings', $contract['artifact_policy']['required_run_record_fields']);
        $this->assertContains('local_product_source_checkouts_used', $contract['artifact_policy']['required_run_record_fields']);
        $this->assertContains('source_policy', $contract['artifact_policy']['required_run_record_fields']);
        $this->assertSame(
            'scripts/conformance/workflow-lifecycle-host-published-artifacts.sh',
            $contract['host_runner_contract']['runner_path'],
        );
        $this->assertSame(
            'scripts/conformance/workflow-lifecycle-published-artifacts.sh',
            $contract['host_runner_contract']['published_image_result_runner_path'],
        );
        $this->assertSame('docker_capable_host', $contract['host_runner_contract']['published_topology']['executor']);
        $this->assertSame('extract_from_exact_published_server_image', $contract['host_runner_contract']['runner_distribution']);
        $this->assertSame('/app/scripts/conformance/workflow-lifecycle-host-published-artifacts.sh', $contract['host_runner_contract']['runner_image_path']);
        $this->assertTrue($contract['host_runner_contract']['rust_sdk_probe_runs_outside_server_container']);
        $this->assertContains(
            'DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH',
            $contract['host_runner_contract']['evidence_inputs'],
        );
        $this->assertContains('workflow-lifecycle-result.json', $contract['host_runner_contract']['result_files']);
        $this->assertTrue($contract['host_runner_contract']['must_execute_against_published_artifacts']);
        $this->assertTrue($contract['host_runner_contract']['must_emit_per_cell_outcomes']);
        $this->assertTrue($contract['host_runner_contract']['unsupported_cells_require_documented_typed_refusal']);
        $this->assertSame(['cancelled'], $contract['scenario_requirements']['cancellation_public_surface_terminal_state']['terminal_states']);
        $this->assertSame(['terminated'], $contract['scenario_requirements']['termination_public_surface_terminal_state']['terminal_states']);
        $this->assertSame(WorkflowLifecycleResultGate::SCHEMA, $contract['result_gate']['schema']);
        $this->assertContains(
            'local_product_source_truthy_values_are_refused_consistently',
            $contract['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'each_pass_scenario_proves_published_artifact_cell_execution',
            $contract['result_gate']['pass_requires'],
        );
        $this->assertContains(
            'continue_as_new_chain_reports_distinct_run_ids_and_one_workflow_id',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'cli_api_history_and_waterline_surfaces_are_operator_diagnostic_enough',
            $contract['coverage_gate']['passing_outcome_requires'],
        );
    }

    public function test_it_publishes_workflow_update_runtime_contract_manifest(): void
    {
        $response = $this->getJson('/api/cluster/info')->assertOk();
        $contract = $response->json('workflow_update_runtime_contract');

        $this->assertSame(WorkflowUpdateRuntimeContract::SCHEMA, $contract['schema']);
        $this->assertSame('workflow_update_runtime_contract', $contract['fixture_category']);
        $this->assertSame(
            'static/platform-conformance/workflow-update-runtime-scenarios.json',
            $contract['scenario_manifest']['source_path'],
        );
        $this->assertContains('artifact_sources', $contract['artifact_policy']['required_run_record_fields']);
        $this->assertContains('update_cell_outcomes', $contract['artifact_policy']['required_run_record_fields']);
        $this->assertContains('local_product_source_checkouts_used', $contract['artifact_policy']['required_run_record_fields']);
        $this->assertContains('source_policy', $contract['artifact_policy']['required_run_record_fields']);
        $this->assertContains('sdk-php', $contract['required_matrix']['runtimes']);
        $this->assertContains('sdk-python', $contract['required_matrix']['runtimes']);
        $this->assertContains('accepted_update_control_plane_and_history', $contract['required_scenarios']);
        $this->assertContains('running_or_waiting_update_operator_visibility', $contract['required_scenarios']);
        $this->assertContains('completed_update_result_round_trip', $contract['required_scenarios']);
        $this->assertContains('failed_update_outcome', $contract['required_scenarios']);
        $this->assertContains('duplicate_request_idempotency', $contract['required_scenarios']);
        $this->assertContains('unknown_update_refusal', $contract['required_scenarios']);
        $this->assertContains('invalid_input_refusal', $contract['required_scenarios']);
        $this->assertContains('payload_envelope_round_trip', $contract['required_scenarios']);
        $this->assertContains('terminal_workflow_update_behavior', $contract['required_scenarios']);
        $this->assertContains('principal_attribution_with_auth', $contract['required_scenarios']);
        $this->assertContains('php_client_worker_update_surface', $contract['required_scenarios']);
        $this->assertContains('python_client_worker_update_surface', $contract['required_scenarios']);
        $this->assertSame(
            'scripts/conformance/workflow-updates-published-artifacts.sh',
            $contract['host_runner_contract']['runner_path'],
        );
        $this->assertTrue($contract['host_runner_contract']['host_runner_implemented']);
        $this->assertSame('not_covered', $contract['host_runner_contract']['unexecuted_required_scenario_status']);
        $this->assertContains(
            'workflow-updates-focused-evidence.json',
            $contract['host_runner_contract']['result_files'],
        );
        $this->assertContains(
            'sdk-php-workflow-updates-evidence.json',
            $contract['host_runner_contract']['result_files'],
        );
        $this->assertContains(
            'python-sdk-workflow-updates-evidence.json',
            $contract['host_runner_contract']['result_files'],
        );
        $this->assertContains(
            'workflow-updates-operator-diagnostics-evidence.json',
            $contract['host_runner_contract']['result_files'],
        );
        $this->assertContains(
            'accepted_update_control_plane_and_history',
            $contract['host_runner_contract']['focused_probe']['covers_required_scenarios'],
        );
        $this->assertContains(
            'principal_attribution_with_auth',
            $contract['host_runner_contract']['focused_probe']['covers_required_scenarios'],
        );
        $this->assertContains(
            'php_client_worker_update_surface',
            $contract['host_runner_contract']['php_sidecar']['covers_required_scenarios'],
        );
        $this->assertContains(
            'python_client_worker_update_surface',
            $contract['host_runner_contract']['python_sidecar']['covers_required_scenarios'],
        );
        $this->assertContains(
            'operator_diagnostics_surfaces',
            $contract['host_runner_contract']['operator_diagnostics_sidecar']['covers_required_scenarios'],
        );
        $rawPayload = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(
            \stdClass::class,
            $rawPayload->workflow_update_runtime_contract->host_runner_contract->typed_coverage_gaps,
        );
        $this->assertSame(
            [],
            get_object_vars($rawPayload->workflow_update_runtime_contract->host_runner_contract->typed_coverage_gaps),
        );
        $this->assertArrayNotHasKey(
            'python_client_worker_update_surface',
            $contract['host_runner_contract']['typed_coverage_gaps'],
        );
        $this->assertArrayNotHasKey(
            'operator_diagnostics_surfaces',
            $contract['host_runner_contract']['typed_coverage_gaps'],
        );
        $this->assertSame(WorkflowUpdateRuntimeResultGate::SCHEMA, $contract['result_gate']['schema']);
        $this->assertContains(
            'principal_attribution_is_proven_when_authentication_is_enabled',
            $contract['result_gate']['pass_requires'],
        );
    }

    public function test_it_publishes_external_task_input_contract_manifest(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath(
                'worker_protocol.external_task_input_contract.schema',
                'durable-workflow.v2.external-task-input.contract',
            )
            ->assertJsonPath('worker_protocol.external_task_input_contract.version', 1)
            ->assertJsonPath(
                'worker_protocol.external_task_input_contract.envelopes.workflow_task.task_fields.id.source',
                'task.task_id',
            )
            ->assertJsonPath(
                'worker_protocol.external_task_input_contract.envelopes.activity_task.deadline_fields.heartbeat.source',
                'task.deadlines.heartbeat',
            )
            ->assertJsonPath(
                'worker_protocol.external_task_input_contract.fixtures.workflow_task.artifact',
                'durable-workflow.v2.external-task-input.workflow-task.v1',
            )
            ->assertJsonPath(
                'worker_protocol.external_task_input_contract.fixtures.activity_task.example.task.kind',
                'activity_task',
            )
            ->assertJsonPath(
                'worker_protocol.external_task_input_contract.envelopes.workflow_task.history_event_contracts.condition_timeout.correlation.event_adjacency_required',
                false,
            )
            ->assertJsonPath(
                'worker_protocol.external_task_input_contract.envelopes.workflow_task.history_event_contracts.condition_timeout.replay.advances_ordinary_command_cursor',
                false,
            )
            ->assertJsonPath(
                'worker_protocol.external_task_input_contract.fixtures.condition_timeout_history.example.history.events.2.payload.timer_kind',
                'condition_timeout',
            )
            ->assertJsonPath(
                'worker_protocol.server_capabilities.external_task_input.schema',
                'durable-workflow.v2.external-task-input.contract',
            )
            ->assertJsonPath(
                'client_compatibility.required_protocols.worker_protocol.external_task_input_contract.version',
                1,
            );
    }

    public function test_it_publishes_role_topology_for_the_current_server_node(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('topology.schema', ServerTopology::SCHEMA)
            ->assertJsonPath('topology.version', ServerTopology::VERSION)
            ->assertJsonPath('topology.current_shape', 'standalone_server')
            ->assertJsonPath('topology.current_process_class', 'server_http_node')
            ->assertJsonPath('topology.execution_mode', 'remote_worker_protocol')
            ->assertJsonPath('topology.current_roles.0', 'api_ingress')
            ->assertJsonPath('topology.current_roles.1', 'control_plane')
            ->assertJsonPath('topology.current_roles.2', 'matching')
            ->assertJsonPath('topology.current_roles.3', 'history_projection')
            ->assertJsonPath('topology.supported_shapes.0', 'embedded')
            ->assertJsonPath('topology.supported_shapes.1', 'standalone_server')
            ->assertJsonPath('topology.supported_shapes.2', 'split_control_execution')
            ->assertJsonPath('topology.role_vocabulary.4', 'scheduler')
            ->assertJsonPath('topology.role_vocabulary.5', 'execution_plane')
            ->assertJsonPath('topology.matching_role.queue_wake_enabled', true)
            ->assertJsonPath('topology.matching_role.shape', 'in_worker')
            ->assertJsonPath('topology.matching_role.wake_owner', 'worker_loop')
            ->assertJsonPath('topology.matching_role.task_dispatch_mode', 'poll')
            ->assertJsonPath('topology.matching_role.partition_primitives.0', 'connection')
            ->assertJsonPath('topology.matching_role.partition_primitives.3', 'namespace')
            ->assertJsonPath('topology.matching_role.backpressure_model', 'lease_ownership')
            ->assertJsonPath(
                'topology.shape_assignments.standalone_server.process_classes.0.name',
                'server_http_node',
            )
            ->assertJsonPath(
                'topology.shape_assignments.standalone_server.process_classes.0.roles.3',
                'history_projection',
            )
            ->assertJsonPath(
                'topology.shape_assignments.split_control_execution.process_classes.1.roles.0',
                'api_ingress',
            )
            ->assertJsonPath(
                'topology.shape_assignments.split_control_execution.process_classes.4.roles.0',
                'execution_plane',
            )
            ->assertJsonPath(
                'topology.role_catalog.execution_plane.steady_state_interface',
                'worker_protocol',
            )
            ->assertJsonPath(
                'topology.role_catalog.matching.hosted_by_current_node',
                true,
            )
            ->assertJsonPath(
                'topology.authority_boundaries.control_plane.writes.1',
                'workflow_runs.status',
            )
            ->assertJsonPath(
                'topology.authority_surfaces.workflow_tasks.mutations.lease_claim_release.owning_roles.0',
                'matching',
            )
            ->assertJsonPath(
                'topology.authority_surfaces.worker_registrations.mutations.register_heartbeat.read_roles.1',
                'control_plane',
            )
            ->assertJsonPath(
                'topology.authority_boundaries.history_projection.writes.1',
                'workflow_run_summaries',
            )
            ->assertJsonPath(
                'topology.failure_domains.control_plane_down.operator_signal',
                'operator_commands_fail_fast',
            )
            ->assertJsonPath(
                'topology.failure_domains.scheduler_down.effect',
                'scheduled_workflows_stop_firing_and_record_missed_runs',
            )
            ->assertJsonPath(
                'topology.scaling_boundaries.execution_plane',
                'workflow_and_activity_task_rate',
            )
            ->assertJsonPath(
                'topology.supported_topologies.standalone_server.process_classes.worker_node.roles.0',
                'execution_plane',
            )
            ->assertJsonPath(
                'topology.supported_topologies.embedded.execution_mode',
                'local_queue_worker',
            )
            ->assertJsonPath('topology.migration_path.0.step', 'audit_role_boundaries')
            ->assertJsonPath(
                'topology.migration_path.5.step',
                'optional_execution_partitioning',
            )
            ->assertJsonPath('topology.migration_path.0.reversible', true)
            ->assertJsonPath('topology.migration_path.5.reversible', true)
            ->assertJsonPath(
                'topology.kernel_invariants.0.id',
                'single_persistence_engine',
            )
            ->assertJsonPath(
                'topology.kernel_invariants.1.id',
                'single_worker_protocol',
            )
            ->assertJsonPath(
                'topology.kernel_invariants.2.id',
                'single_history_writer',
            )
            ->assertJsonPath(
                'topology.kernel_invariants.3.id',
                'single_control_authority_per_run',
            )
            ->assertJsonPath(
                'topology.kernel_invariants.4.id',
                'embedded_topology_remains_supported',
            )
            ->assertJsonPath(
                'topology.kernel_invariants.5.id',
                'role_split_is_topology_only',
            )
            ->assertJsonPath(
                'topology.kernel_invariants.0.applies_to.0',
                'embedded',
            )
            ->assertJsonPath(
                'topology.kernel_invariants.0.applies_to.1',
                'standalone_server',
            )
            ->assertJsonPath(
                'topology.kernel_invariants.0.applies_to.2',
                'split_control_execution',
            );
    }

    public function test_it_switches_cluster_topology_execution_mode_when_embedded_dispatch_is_enabled(): void
    {
        config(['server.mode' => 'embedded']);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('topology.current_shape', 'standalone_server')
            ->assertJsonPath('topology.execution_mode', 'local_queue_worker');
    }

    public function test_it_can_publish_a_scheduler_process_class_for_standalone_nodes(): void
    {
        config([
            'server.topology.shape' => 'standalone_server',
            'server.topology.process_class' => 'scheduler_node',
        ]);

        $response = $this->getJson('/api/cluster/info')->assertOk();

        $response
            ->assertJsonPath('topology.current_shape', 'standalone_server')
            ->assertJsonPath('topology.current_process_class', 'scheduler_node')
            ->assertJsonPath('topology.current_roles.0', 'scheduler')
            ->assertJsonCount(1, 'topology.current_roles')
            ->assertJsonPath('topology.role_catalog.scheduler.hosted_by_current_node', true)
            ->assertJsonPath('topology.role_catalog.matching.hosted_by_current_node', false)
            ->assertJsonPath('topology.role_catalog.execution_plane.hosted_by_current_node', false);
    }

    public function test_it_can_publish_a_split_control_execution_process_class(): void
    {
        config([
            'server.topology.shape' => 'split_control_execution',
            'server.topology.process_class' => 'matching_node',
        ]);

        $response = $this->getJson('/api/cluster/info')->assertOk();

        $response
            ->assertJsonPath('topology.current_shape', 'split_control_execution')
            ->assertJsonPath('topology.current_process_class', 'matching_node')
            ->assertJsonPath('topology.current_roles.0', 'matching')
            ->assertJsonCount(1, 'topology.current_roles')
            ->assertJsonPath('topology.role_catalog.matching.hosted_by_current_node', true)
            ->assertJsonPath('topology.role_catalog.control_plane.hosted_by_current_node', false)
            ->assertJsonPath('topology.role_catalog.api_ingress.hosted_by_current_node', false);
    }

    public function test_it_can_publish_a_split_control_execution_control_plane_node(): void
    {
        config([
            'server.topology.shape' => 'split_control_execution',
            'server.topology.process_class' => 'control_plane_node',
        ]);

        $response = $this->getJson('/api/cluster/info')->assertOk();

        $response
            ->assertJsonPath('topology.current_shape', 'split_control_execution')
            ->assertJsonPath('topology.current_process_class', 'control_plane_node')
            ->assertJsonPath('topology.current_roles.0', 'api_ingress')
            ->assertJsonPath('topology.current_roles.1', 'control_plane')
            ->assertJsonPath('topology.current_roles.2', 'history_projection')
            ->assertJsonCount(3, 'topology.current_roles')
            ->assertJsonPath('topology.role_catalog.api_ingress.hosted_by_current_node', true)
            ->assertJsonPath('topology.role_catalog.control_plane.hosted_by_current_node', true)
            ->assertJsonPath('topology.role_catalog.history_projection.hosted_by_current_node', true)
            ->assertJsonPath('topology.role_catalog.matching.hosted_by_current_node', false);
    }

    public function test_it_falls_back_to_the_default_process_class_when_the_configured_class_does_not_match_the_shape(): void
    {
        config([
            'server.topology.shape' => 'standalone_server',
            'server.topology.process_class' => 'matching_node',
        ]);

        $response = $this->getJson('/api/cluster/info')->assertOk();

        $response
            ->assertJsonPath('topology.current_shape', 'standalone_server')
            ->assertJsonPath('topology.current_process_class', 'server_http_node')
            ->assertJsonPath('topology.current_roles.0', 'api_ingress')
            ->assertJsonPath('topology.role_catalog.matching.hosted_by_current_node', true)
            ->assertJsonPath('topology.role_catalog.scheduler.hosted_by_current_node', false);
    }

    public function test_it_publishes_a_versioned_coordination_health_manifest(): void
    {
        $response = $this->getJson('/api/cluster/info?include=diagnostics')->assertOk();

        $response
            ->assertJsonPath('coordination_health.schema', CoordinationHealthContract::SCHEMA)
            ->assertJsonPath('coordination_health.version', CoordinationHealthContract::VERSION)
            ->assertJsonPath('coordination_health.namespace_scope', 'all_namespaces')
            ->assertJsonPath('coordination_health.http_status', 200);

        $this->assertContains(
            $response->json('coordination_health.status'),
            ['ok', 'warning', 'error', 'blocked', 'unavailable'],
        );
        $this->assertIsArray($response->json('coordination_health.categories'));
        $this->assertIsArray($response->json('coordination_health.warning_checks'));
        $this->assertIsArray($response->json('coordination_health.error_checks'));
        $this->assertIsArray($response->json('coordination_health.checks'));
        $this->assertIsArray($response->json('coordination_health.routing_drains.queues'));
    }

    public function test_it_surfaces_readiness_blockers_in_coordination_health(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        DB::table('migrations')
            ->where('migration', '2026_04_21_000300_add_workflow_definition_fingerprints_to_worker_registrations')
            ->delete();

        $response = $this->getJson('/api/cluster/info?include=diagnostics')->assertOk();

        $response
            ->assertJsonPath('coordination_health.status', 'blocked')
            ->assertJsonPath('coordination_health.http_status', 503)
            ->assertJsonPath('coordination_health.blocked_by.0', 'migrations')
            ->assertJsonPath(
                'coordination_health.remediation',
                'Restore database connectivity and run server-bootstrap to migrate workflow and configured queue storage before serving workflow v2 traffic.',
            );
    }

    public function test_it_surfaces_draining_build_id_cohorts_in_coordination_health(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);
        WorkflowNamespace::query()->create([
            'name' => 'other',
            'description' => 'Other namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        WorkerRegistration::query()->create([
            'worker_id' => 'worker-active',
            'namespace' => 'default',
            'task_queue' => 'orders',
            'runtime' => 'php',
            'build_id' => 'build-active',
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);
        WorkerRegistration::query()->create([
            'worker_id' => 'worker-draining',
            'namespace' => 'default',
            'task_queue' => 'orders',
            'runtime' => 'php',
            'build_id' => 'build-draining',
            'last_heartbeat_at' => now(),
            'status' => 'draining',
        ]);
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'orders',
            'build_id' => WorkerBuildIdRollout::buildIdKey('build-draining'),
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_DRAINING,
            'drained_at' => now()->subMinute(),
        ]);
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'other',
            'task_queue' => 'payments',
            'build_id' => WorkerBuildIdRollout::buildIdKey('build-ghost'),
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_DRAINING,
            'drained_at' => now()->subMinutes(5),
        ]);

        $this->getJson('/api/cluster/info?include=diagnostics')
            ->assertOk()
            ->assertJsonPath('coordination_health.routing_drains.queues_with_drains', 2)
            ->assertJsonPath('coordination_health.routing_drains.draining_build_id_count', 2)
            ->assertJsonPath('coordination_health.routing_drains.active_worker_count', 1)
            ->assertJsonPath('coordination_health.routing_drains.draining_worker_count', 1)
            ->assertJsonPath('coordination_health.routing_drains.stale_worker_count', 0)
            ->assertJsonPath('coordination_health.routing_drains.queues.0.namespace', 'default')
            ->assertJsonPath('coordination_health.routing_drains.queues.0.task_queue', 'orders')
            ->assertJsonPath('coordination_health.routing_drains.queues.0.draining_build_id_count', 1)
            ->assertJsonPath('coordination_health.routing_drains.queues.0.build_ids.0.build_id', 'build-draining')
            ->assertJsonPath('coordination_health.routing_drains.queues.1.namespace', 'other')
            ->assertJsonPath('coordination_health.routing_drains.queues.1.task_queue', 'payments')
            ->assertJsonPath('coordination_health.routing_drains.queues.1.build_ids.0.build_id', 'build-ghost');
    }

    public function test_it_surfaces_worker_compatibility_warnings_in_coordination_health(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        config([
            'workflows.v2.compatibility.current' => 'build-a',
            'workflows.v2.compatibility.supported' => ['build-a'],
            'workflows.v2.compatibility.namespace' => 'default',
            'workflows.v2.fleet.validation_mode' => 'warn',
        ]);
        WorkerCompatibilityFleet::clear();

        try {
            WorkerCompatibilityFleet::recordForNamespace(
                'default',
                ['build-b'],
                'database',
                'default',
                'worker-b',
            );

            $response = $this->getJson('/api/cluster/info?include=diagnostics')->assertOk();

            $response
                ->assertJsonPath('coordination_health.status', 'warning')
                ->assertJsonPath('coordination_health.http_status', 200);

            $this->assertContains(
                'worker_compatibility',
                $response->json('coordination_health.warning_checks', []),
            );
        } finally {
            WorkerCompatibilityFleet::clear();
        }
    }

    public function test_it_fails_coordination_health_closed_when_fleet_validation_requires_compatible_workers(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        config([
            'workflows.v2.compatibility.current' => 'build-a',
            'workflows.v2.compatibility.supported' => ['build-a'],
            'workflows.v2.compatibility.namespace' => 'default',
            'workflows.v2.fleet.validation_mode' => 'fail',
        ]);
        WorkerCompatibilityFleet::clear();

        try {
            WorkerCompatibilityFleet::recordForNamespace(
                'default',
                ['build-b'],
                'database',
                'default',
                'worker-b',
            );

            $response = $this->getJson('/api/cluster/info?include=diagnostics')->assertOk();

            $response
                ->assertJsonPath('coordination_health.status', 'error')
                ->assertJsonPath('coordination_health.http_status', 503);

            $this->assertContains(
                'worker_compatibility',
                $response->json('coordination_health.error_checks', []),
            );
        } finally {
            WorkerCompatibilityFleet::clear();
        }
    }

    public function test_it_publishes_matching_role_wake_ownership_for_dedicated_matching_shape(): void
    {
        config([
            'workflows.v2.matching_role.queue_wake_enabled' => false,
            'workflows.v2.task_dispatch_mode' => 'queue',
        ]);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('topology.matching_role.queue_wake_enabled', false)
            ->assertJsonPath('topology.matching_role.shape', 'dedicated')
            ->assertJsonPath('topology.matching_role.wake_owner', 'dedicated_repair_pass')
            ->assertJsonPath('topology.matching_role.task_dispatch_mode', 'queue')
            ->assertJsonPath('topology.matching_role.partition_primitives.1', 'queue')
            ->assertJsonPath('topology.matching_role.backpressure_model', 'lease_ownership')
            ->assertJsonPath(
                'topology.matching_role.discovery_limits.workflow_task_lease_seconds',
                (int) config('server.lease.workflow_task_timeout'),
            );
    }

    public function test_cluster_discovery_and_failover_contract_publish_the_effective_workflow_task_lease(): void
    {
        config(['server.lease.workflow_task_timeout' => 8]);
        WorkflowTaskLeaseConfiguration::apply();

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('topology.matching_role.discovery_limits.workflow_task_lease_seconds', 8)
            ->assertJsonPath('single_region_failover_contract.recovery_bounds.workflow_task_lease_seconds', 8);
    }

    public function test_it_publishes_external_execution_surface_contract_manifest(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.schema',
                'durable-workflow.v2.external-execution-surface.contract',
            )
            ->assertJsonPath('worker_protocol.external_execution_surface_contract.version', 1)
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.product_boundary.name',
                'activity_grade_external_execution',
            )
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.product_boundary.primary_wedge',
                'operator_platform_integration',
            )
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.contract_seams.input_envelope.status',
                'published',
            )
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.contract_seams.handler_mappings.status',
                'published',
            )
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.contract_seams.handler_mappings.schema',
                'durable-workflow.v2.external-executor-config.contract',
            )
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.contract_seams.invocable_http_carrier.status',
                'published',
            )
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.contract_seams.bridge_adapters.status',
                'published',
            )
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.contract_seams.bridge_adapters.schema',
                'durable-workflow.v2.bridge-adapter-outcome.contract',
            )
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.contract_seams.bridge_adapters.cluster_info_path',
                'bridge_adapter_outcome_contract',
            )
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.contract_seams.auth_profile_tls_composition.status',
                'published',
            )
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.contract_seams.auth_profile_tls_composition.schema',
                'durable-workflow.v2.auth-composition.contract',
            )
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.contract_seams.auth_profile_tls_composition.cluster_info_path',
                'auth_composition_contract',
            )
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.contract_seams.runtime_external_payload_transport.status',
                'published',
            )
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.contract_seams.runtime_external_payload_transport.cluster_info_path',
                'namespace.external_payload_storage',
            )
            ->assertJsonPath(
                'worker_protocol.server_capabilities.external_execution_surface.schema',
                'durable-workflow.v2.external-execution-surface.contract',
            )
            ->assertJsonPath(
                'client_compatibility.required_protocols.worker_protocol.external_execution_surface_contract.version',
                1,
            );
    }

    public function test_it_exposes_namespace_external_payload_storage_policy_path(): void
    {
        config([
            'filesystems.disks.azure-payloads' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/azure-payloads'),
            ],
        ]);

        WorkflowNamespace::query()->create([
            'name' => 'analytics',
            'description' => 'Analytics namespace',
            'retention_days' => 45,
            'status' => 'active',
            'external_payload_storage' => [
                'driver' => 'custom',
                'enabled' => true,
                'threshold_bytes' => 1024,
                'config' => [
                    'disk' => 'azure-payloads',
                    'container' => 'payloads',
                    'scheme' => 'azblob',
                    'prefix' => 'durable',
                ],
            ],
        ]);

        $this->withHeaders(['X-Namespace' => 'analytics'])
            ->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('namespace.name', 'analytics')
            ->assertJsonPath('namespace.exists', true)
            ->assertJsonPath('namespace.status', 'active')
            ->assertJsonPath('namespace.retention_mode', 'bounded')
            ->assertJsonPath('namespace.retention_days', 45)
            ->assertJsonPath(
                'namespace.external_payload_storage.schema',
                'durable-workflow.v2.runtime-external-payload-reference.v1',
            )
            ->assertJsonPath('namespace.external_payload_storage.version', 1)
            ->assertJsonPath('namespace.external_payload_storage.configured', true)
            ->assertJsonPath('namespace.external_payload_storage.enabled', true)
            ->assertJsonPath('namespace.external_payload_storage.status', 'available')
            ->assertJsonPath('namespace.external_payload_storage.threshold_bytes', 1024)
            ->assertJsonPath('namespace.external_payload_storage.provider_details_exposed', false)
            ->assertJsonPath('namespace.external_payload_storage.transport.version', 1)
            ->assertJsonPath('namespace.external_payload_storage.direct_provider_adapters.required', false)
            ->assertJsonMissingPath('namespace.external_payload_storage.driver')
            ->assertJsonMissingPath('namespace.external_payload_storage.reference_uri_scheme')
            ->assertJsonMissingPath('namespace.external_payload_storage.config');
    }

    public function test_cluster_info_reports_unknown_object_storage_disk_unavailable(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'analytics',
            'description' => 'Analytics namespace',
            'retention_days' => 45,
            'status' => 'active',
            'external_payload_storage' => [
                'driver' => 's3',
                'enabled' => true,
                'threshold_bytes' => 1024,
                'config' => [
                    'disk' => 'missing-payload-disk',
                    'bucket' => 'payloads',
                    'prefix' => 'durable',
                ],
            ],
        ]);

        $this->withHeaders(['X-Namespace' => 'analytics'])
            ->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('namespace.name', 'analytics')
            ->assertJsonPath('namespace.external_payload_storage.configured', true)
            ->assertJsonPath('namespace.external_payload_storage.enabled', true)
            ->assertJsonPath('namespace.external_payload_storage.status', 'driver_unavailable')
            ->assertJsonPath('namespace.external_payload_storage.provider_details_exposed', false)
            ->assertJsonMissingPath('namespace.external_payload_storage.driver')
            ->assertJsonMissingPath('namespace.external_payload_storage.reference_uri_scheme')
            ->assertJsonMissingPath('namespace.external_payload_storage.config');
    }

    public function test_cluster_info_exposes_unconfigured_external_payload_storage_object(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('namespace.name', 'default')
            ->assertJsonPath('namespace.exists', false)
            ->assertJsonPath('namespace.external_payload_storage.configured', false)
            ->assertJsonPath('namespace.external_payload_storage.enabled', false)
            ->assertJsonPath('namespace.external_payload_storage.status', 'unconfigured')
            ->assertJsonPath(
                'namespace.external_payload_storage.schema',
                'durable-workflow.v2.runtime-external-payload-reference.v1',
            );
    }

    public function test_it_publishes_invocable_carrier_contract_manifest(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath(
                'worker_protocol.invocable_carrier_contract.schema',
                'durable-workflow.v2.invocable-carrier.contract',
            )
            ->assertJsonPath('worker_protocol.invocable_carrier_contract.carrier_type', 'invocable_http')
            ->assertJsonPath('worker_protocol.invocable_carrier_contract.scope.task_kinds.0', 'activity_task')
            ->assertJsonPath(
                'worker_protocol.invocable_carrier_contract.request.body_schema',
                'durable-workflow.v2.external-task-input.contract',
            )
            ->assertJsonPath(
                'worker_protocol.invocable_carrier_contract.response.body_schema',
                'durable-workflow.v2.external-task-result.contract',
            )
            ->assertJsonPath(
                'worker_protocol.server_capabilities.invocable_carrier.schema',
                'durable-workflow.v2.invocable-carrier.contract',
            )
            ->assertJsonPath('capabilities.invocable_carrier_contract', true)
            ->assertJsonPath(
                'client_compatibility.required_protocols.worker_protocol.invocable_carrier_contract.version',
                1,
            );
    }

    public function test_it_publishes_service_execution_contract_manifest(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.service_catalog', true)
            ->assertJsonPath('capabilities.service_execution', true)
            ->assertJsonPath(
                'service_execution_contract.schema',
                'durable-workflow.v2.service-execution.contract',
            )
            ->assertJsonPath('service_execution_contract.version', 1)
            ->assertJsonPath('service_execution_contract.handler_binding_kinds.0', 'start_workflow')
            ->assertJsonPath('service_execution_contract.handler_binding_kinds.5', 'invocable_http')
            ->assertJsonPath(
                'service_execution_contract.resolved_target_binding_kinds.workflow_run.terminal_link_reference',
                'workflow_run_id',
            )
            ->assertJsonPath(
                'service_execution_contract.resolved_target_binding_kinds.invocable_carrier_request.terminal_link_reference',
                'carrier_request_id',
            )
            ->assertJsonPath(
                'service_execution_contract.durable_response_fields.0',
                'service_call_id',
            )
            ->assertJsonPath(
                'control_plane.request_contract.operations.service_execute.durable_response_fields.0',
                'service_call_id',
            );
    }

    public function test_it_publishes_external_executor_config_contract_when_no_config_is_set(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath(
                'worker_protocol.external_executor_config_contract.schema',
                'durable-workflow.v2.external-executor-config.contract',
            )
            ->assertJsonPath(
                'worker_protocol.external_executor_config_contract.config_schema.schema',
                'durable-workflow.external-executor.config',
            )
            ->assertJsonPath(
                'worker_protocol.external_executor_config_contract.runtime.configured',
                false,
            )
            ->assertJsonPath(
                'worker_protocol.external_executor_config_contract.runtime.status',
                'not_configured',
            )
            ->assertJsonPath(
                'worker_protocol.server_capabilities.external_executor_config.config_schema',
                'durable-workflow.external-executor.config',
            )
            ->assertJsonPath('capabilities.external_executor_config_contract', true)
            ->assertJsonPath(
                'client_compatibility.required_protocols.worker_protocol.external_executor_config_contract.schema',
                'durable-workflow.v2.external-executor-config.contract',
            );
    }

    public function test_it_validates_configured_external_executor_config_without_exposing_the_full_path(): void
    {
        $path = $this->useExternalExecutorConfigFixture([
            'schema' => 'durable-workflow.external-executor.config',
            'version' => 1,
            'defaults' => [
                'namespace' => 'operations',
                'task_queue' => 'operator-tasks',
                'auth_ref' => 'prod-profile',
            ],
            'auth_refs' => [
                'prod-profile' => ['type' => 'profile', 'profile' => 'prod'],
            ],
            'carriers' => [
                'artisan-operator' => [
                    'type' => 'process',
                    'command' => ['php', 'artisan', 'durable:external-handler'],
                    'capabilities' => ['activity_task'],
                ],
            ],
            'mappings' => [
                [
                    'name' => 'billing.backfill-invoices',
                    'kind' => 'activity',
                    'activity_type' => 'billing.backfill-invoices',
                    'carrier' => 'artisan-operator',
                    'handler' => 'App\\Durable\\Handlers\\BackfillInvoices',
                ],
            ],
        ]);

        $response = $this->getJson('/api/cluster/info')->assertOk();

        $response->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.configured', true)
            ->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.status', 'valid')
            ->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.source.type', 'file')
            ->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.source.basename', basename($path))
            ->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.summary.carrier_count', 1)
            ->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.summary.mapping_count', 1)
            ->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.summary.mapping_kinds.activity', 1)
            ->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.errors', []);

        $this->assertArrayNotHasKey(
            'path',
            $response->json('worker_protocol.external_executor_config_contract.runtime.source'),
            'Cluster discovery must not expose the absolute external executor config path.',
        );
    }

    public function test_it_reports_named_external_executor_config_validation_errors(): void
    {
        $this->useExternalExecutorConfigFixture([
            'schema' => 'durable-workflow.external-executor.config',
            'version' => 1,
            'defaults' => [
                'auth_ref' => 'missing-auth',
            ],
            'auth_refs' => [],
            'carriers' => [
                'http-bridge' => [
                    'type' => 'http',
                    'url' => 'https://bridge.example.com/durable/events',
                    'capabilities' => ['workflow_signal'],
                ],
            ],
            'mappings' => [
                [
                    'name' => 'duplicate',
                    'kind' => 'activity',
                    'activity_type' => 'billing.backfill-invoices',
                    'carrier' => 'missing-carrier',
                    'handler' => 'billing.backfill-invoices',
                ],
                [
                    'name' => 'duplicate',
                    'kind' => 'activity',
                    'carrier' => 'http-bridge',
                    'handler' => 'billing.other',
                ],
            ],
        ]);

        $response = $this->getJson('/api/cluster/info')->assertOk();
        $codes = array_column(
            $response->json('worker_protocol.external_executor_config_contract.runtime.errors'),
            'code',
        );

        $this->assertContains('unknown_carrier', $codes);
        $this->assertContains('unknown_auth_ref', $codes);
        $this->assertContains('duplicate_mapping_name', $codes);
        $this->assertContains('invalid_queue_binding', $codes);
        $this->assertContains('missing_handler_target', $codes);
        $this->assertContains('unsupported_carrier_capability', $codes);
        $response->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.status', 'invalid');
    }

    public function test_it_fails_closed_on_malformed_invocable_http_carrier_config(): void
    {
        $this->useExternalExecutorConfigFixture([
            'schema' => 'durable-workflow.external-executor.config',
            'version' => 1,
            'defaults' => [
                'task_queue' => 'operator-tasks',
            ],
            'carriers' => [
                'bad-invocable' => [
                    'type' => 'invocable_http',
                    'url' => 'http://carrier.example.com/durable/activity',
                    'method' => 'GET',
                    'timeout_seconds' => true,
                    'retry_policy' => [
                        'max_attempts' => 10,
                        'backoff_seconds' => [1, 600],
                        'retryable_status_codes' => [400, 503],
                    ],
                    'capabilities' => ['activity_task', 'workflow_task'],
                ],
            ],
            'mappings' => [
                [
                    'name' => 'billing.backfill',
                    'kind' => 'activity',
                    'activity_type' => 'billing.backfill',
                    'carrier' => 'bad-invocable',
                    'handler' => 'billing.backfill',
                ],
            ],
        ]);

        $response = $this->getJson('/api/cluster/info')->assertOk();
        $codes = array_column(
            $response->json('worker_protocol.external_executor_config_contract.runtime.errors'),
            'code',
        );

        $this->assertContains('invalid_carrier_target', $codes);
        $this->assertContains('invalid_invocable_carrier_scope', $codes);
        $response->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.status', 'invalid');
    }

    public function test_it_allows_only_https_or_loopback_http_invocable_urls(): void
    {
        $this->useExternalExecutorConfigFixture([
            'schema' => 'durable-workflow.external-executor.config',
            'version' => 1,
            'defaults' => [
                'task_queue' => 'operator-tasks',
                'auth_ref' => 'prod-profile',
            ],
            'auth_refs' => [
                'prod-profile' => ['type' => 'profile', 'profile' => 'prod'],
            ],
            'carriers' => [
                'local-dev' => [
                    'type' => 'invocable_http',
                    'url' => 'http://127.0.0.1:8080/durable/activity',
                    'capabilities' => ['activity_task'],
                ],
                'production' => [
                    'type' => 'invocable_http',
                    'url' => 'https://carrier.example.com/durable/activity',
                    'capabilities' => ['activity_task'],
                ],
            ],
            'mappings' => [
                [
                    'name' => 'billing.backfill.local',
                    'kind' => 'activity',
                    'activity_type' => 'billing.backfill.local',
                    'carrier' => 'local-dev',
                    'handler' => 'billing.backfill',
                ],
                [
                    'name' => 'billing.backfill.production',
                    'kind' => 'activity',
                    'activity_type' => 'billing.backfill.production',
                    'carrier' => 'production',
                    'handler' => 'billing.backfill',
                ],
            ],
        ]);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.status', 'valid')
            ->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.errors', []);
    }

    public function test_it_requires_auth_refs_for_non_loopback_invocable_http_mappings(): void
    {
        $this->useExternalExecutorConfigFixture([
            'schema' => 'durable-workflow.external-executor.config',
            'version' => 1,
            'defaults' => [
                'task_queue' => 'operator-tasks',
            ],
            'carriers' => [
                'local-dev' => [
                    'type' => 'invocable_http',
                    'url' => 'http://localhost:8080/durable/activity',
                    'capabilities' => ['activity_task'],
                ],
                'production' => [
                    'type' => 'invocable_http',
                    'url' => 'https://carrier.example.com/durable/activity',
                    'capabilities' => ['activity_task'],
                ],
            ],
            'mappings' => [
                [
                    'name' => 'billing.backfill.local',
                    'kind' => 'activity',
                    'activity_type' => 'billing.backfill.local',
                    'carrier' => 'local-dev',
                    'handler' => 'billing.backfill',
                ],
                [
                    'name' => 'billing.backfill.production',
                    'kind' => 'activity',
                    'activity_type' => 'billing.backfill.production',
                    'carrier' => 'production',
                    'handler' => 'billing.backfill',
                ],
            ],
        ]);

        $response = $this->getJson('/api/cluster/info')->assertOk();
        $errors = $response->json('worker_protocol.external_executor_config_contract.runtime.errors');

        $this->assertSame(['missing_invocable_auth_ref'], array_column($errors, 'code'));
        $this->assertSame('billing.backfill.production', $errors[0]['context']['mapping']);
        $this->assertSame('production', $errors[0]['context']['carrier']);
        $response->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.status', 'invalid');
    }

    public function test_it_rejects_invocable_urls_with_embedded_credentials(): void
    {
        $this->useExternalExecutorConfigFixture([
            'schema' => 'durable-workflow.external-executor.config',
            'version' => 1,
            'defaults' => [
                'task_queue' => 'operator-tasks',
            ],
            'carriers' => [
                'embedded-secret' => [
                    'type' => 'invocable_http',
                    'url' => 'https://user:secret@carrier.example.com/durable/activity',
                    'capabilities' => ['activity_task'],
                ],
            ],
            'mappings' => [
                [
                    'name' => 'billing.backfill',
                    'kind' => 'activity',
                    'activity_type' => 'billing.backfill',
                    'carrier' => 'embedded-secret',
                    'handler' => 'billing.backfill',
                ],
            ],
        ]);

        $response = $this->getJson('/api/cluster/info')->assertOk();
        $errors = $response->json('worker_protocol.external_executor_config_contract.runtime.errors');

        $this->assertSame('invalid_carrier_target', $errors[0]['code']);
        $this->assertSame('url', $errors[0]['context']['field']);
        $this->assertArrayNotHasKey('user', $errors[0]['context']);
        $this->assertArrayNotHasKey('pass', $errors[0]['context']);
        $response->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.status', 'invalid');
    }

    public function test_it_applies_named_external_executor_config_overlay_before_validation(): void
    {
        config(['server.external_executor.overlay' => 'prod']);

        $this->useExternalExecutorConfigFixture([
            'schema' => 'durable-workflow.external-executor.config',
            'version' => 1,
            'defaults' => [
                'namespace' => 'staging',
                'task_queue' => 'operator-tasks',
            ],
            'carriers' => [
                'operator' => [
                    'type' => 'process',
                    'command' => ['php', 'artisan', 'durable:external-handler'],
                    'capabilities' => ['activity_task'],
                ],
            ],
            'mappings' => [
                [
                    'name' => 'staging.backfill',
                    'kind' => 'activity',
                    'activity_type' => 'billing.backfill-invoices',
                    'carrier' => 'operator',
                    'handler' => 'staging-handler',
                ],
            ],
            'overlays' => [
                'prod' => [
                    'defaults' => ['namespace' => 'operations'],
                    'mappings' => [
                        [
                            'name' => 'prod.backfill',
                            'kind' => 'activity',
                            'activity_type' => 'billing.backfill-invoices',
                            'carrier' => 'operator',
                            'handler' => 'prod-handler',
                        ],
                    ],
                ],
            ],
        ]);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.overlay', 'prod')
            ->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.status', 'valid')
            ->assertJsonPath('worker_protocol.external_executor_config_contract.runtime.summary.mapping_count', 1);
    }

    public function test_it_publishes_external_task_result_contract_manifest(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath(
                'worker_protocol.external_task_result_contract.schema',
                'durable-workflow.v2.external-task-result.contract',
            )
            ->assertJsonPath('worker_protocol.external_task_result_contract.version', 1)
            ->assertJsonPath(
                'worker_protocol.external_task_result_contract.envelopes.failure.failure_fields.classification.values.6',
                'malformed_output',
            )
            ->assertJsonPath(
                'worker_protocol.external_task_result_contract.stderr_policy',
                'logs_only_no_machine_meaning',
            )
            ->assertJsonPath(
                'worker_protocol.external_task_result_contract.fixtures.success.artifact',
                'durable-workflow.v2.external-task-result.success.v1',
            )
            ->assertJsonPath(
                'worker_protocol.external_task_result_contract.fixtures.handler_crash.example.failure.classification',
                'handler_crash',
            )
            ->assertJsonPath(
                'worker_protocol.server_capabilities.external_task_result.schema',
                'durable-workflow.v2.external-task-result.contract',
            )
            ->assertJsonPath(
                'client_compatibility.required_protocols.worker_protocol.external_task_result_contract.version',
                1,
            );
    }

    public function test_it_publishes_bridge_adapter_outcome_contract_manifest(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath(
                'bridge_adapter_outcome_contract.schema',
                'durable-workflow.v2.bridge-adapter-outcome.contract',
            )
            ->assertJsonPath('bridge_adapter_outcome_contract.version', 1)
            ->assertJsonPath('bridge_adapter_outcome_contract.boundary.not_a_workflow_runtime', true)
            ->assertJsonPath('bridge_adapter_outcome_contract.patterns.webhook_receiver.allowed_actions.0', 'start_workflow')
            ->assertJsonPath('bridge_adapter_outcome_contract.patterns.queue_backed_adapter.allowed_actions.0', 'handoff_external_task')
            ->assertJsonPath('bridge_adapter_outcome_contract.idempotency.required', true)
            ->assertJsonPath('bridge_adapter_outcome_contract.outcomes.accepted.http_status', 202)
            ->assertJsonPath('bridge_adapter_outcome_contract.rejection_reasons.0', 'unknown_target')
            ->assertJsonPath(
                'bridge_adapter_outcome_contract.reference_journeys.incident_webhook_signals_workflow.request.action',
                'signal_workflow',
            )
            ->assertJsonPath(
                'bridge_adapter_outcome_contract.reference_journeys.incident_webhook_signals_workflow.expected_outcomes.redelivery.control_plane_outcome',
                'deduped_existing_command',
            )
            ->assertJsonPath(
                'bridge_adapter_outcome_contract.reference_journeys.commerce_event_starts_workflow.expected_outcomes.redelivery.reason',
                'duplicate_start',
            )
            ->assertJsonPath('capabilities.bridge_adapter_outcome_contract', true);
    }

    public function test_it_publishes_auth_composition_contract_manifest(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath(
                'auth_composition_contract.schema',
                'durable-workflow.v2.auth-composition.contract',
            )
            ->assertJsonPath('auth_composition_contract.version', 1)
            ->assertJsonPath('auth_composition_contract.precedence.connection_values.0', 'flag')
            ->assertJsonPath('auth_composition_contract.canonical_environment.server_url', 'DURABLE_WORKFLOW_SERVER_URL')
            ->assertJsonPath('auth_composition_contract.auth_material.token.effective_config_value', 'redacted')
            ->assertJsonPath('auth_composition_contract.auth_material.mtls.persisted_as', 'certificate_and_key_references')
            ->assertJsonPath('auth_composition_contract.effective_config.required_fields.3', 'auth')
            ->assertJsonPath('auth_composition_contract.redaction.never_echo.0', 'bearer_tokens')
            ->assertJsonPath(
                'client_compatibility.required_protocols.auth_composition.schema',
                'durable-workflow.v2.auth-composition.contract',
            );
    }

    public function test_it_advertises_response_compression_in_capabilities(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.response_compression', ['gzip', 'deflate']);
    }

    public function test_it_advertises_response_compression_in_worker_protocol(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('worker_protocol.server_capabilities.response_compression', ['gzip', 'deflate']);
    }

    public function test_it_advertises_worker_command_option_capabilities_in_worker_protocol(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('worker_protocol.server_capabilities.activity_retry_policy', true)
            ->assertJsonPath('worker_protocol.server_capabilities.activity_timeouts', true)
            ->assertJsonPath(
                'worker_protocol.server_capabilities.local_activities.schema',
                'durable-workflow.v2.local-activity.contract',
            )
            ->assertJsonPath('worker_protocol.server_capabilities.local_activities.version', 1)
            ->assertJsonPath('worker_protocol.server_capabilities.local_activities.execution.mode', 'local')
            ->assertJsonPath(
                'worker_protocol.server_capabilities.local_activities.execution.ordinary_activity_task_created',
                false,
            )
            ->assertJsonPath(
                'worker_protocol.server_capabilities.local_activities.routing.rejected_options',
                ['connection', 'queue', 'worker_session', 'schedule_to_start_timeout'],
            )
            ->assertJsonPath('worker_protocol.server_capabilities.child_workflow_retry_policy', true)
            ->assertJsonPath('worker_protocol.server_capabilities.child_workflow_timeouts', true)
            ->assertJsonPath('worker_protocol.server_capabilities.parent_close_policy', true)
            ->assertJsonPath('worker_protocol.server_capabilities.query_tasks', true)
            ->assertJsonPath('worker_protocol.server_capabilities.query_task_poll_request_idempotency', true)
            ->assertJsonPath('worker_protocol.server_capabilities.non_retryable_failures', true)
            ->assertJsonPath('worker_protocol.server_capabilities.worker_status.fields.process_metrics', [
                'cpu_percent',
                'memory_bytes',
                'process_uptime_seconds',
                'process_id',
                'host',
                'process_started_at',
            ]);
    }

    public function test_it_publishes_task_queue_priority_fairness_contract_in_worker_protocol(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath(
                'worker_protocol.server_capabilities.task_queue_priority_fairness.schema',
                'durable-workflow.v2.task-queue-priority-fairness.contract',
            )
            ->assertJsonPath('worker_protocol.server_capabilities.task_queue_priority_fairness.version', 1)
            ->assertJsonPath(
                'worker_protocol.server_capabilities.task_queue_priority_fairness.feature',
                'task_queue_priority_fairness',
            )
            ->assertJsonPath(
                'worker_protocol.server_capabilities.task_queue_priority_fairness.fields.priority.default',
                5,
            )
            ->assertJsonPath(
                'worker_protocol.server_capabilities.task_queue_priority_fairness.fields.priority.min',
                0,
            )
            ->assertJsonPath(
                'worker_protocol.server_capabilities.task_queue_priority_fairness.fields.priority.max',
                9,
            )
            ->assertJsonPath(
                'worker_protocol.server_capabilities.task_queue_priority_fairness.fields.fairness_key.default_class_label',
                '__default__',
            )
            ->assertJsonPath(
                'worker_protocol.server_capabilities.task_queue_priority_fairness.fields.fairness_weight.default',
                1,
            );
    }

    public function test_it_advertises_empty_compression_when_disabled(): void
    {
        config(['server.compression.enabled' => false]);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.response_compression', [])
            ->assertJsonPath('worker_protocol.server_capabilities.response_compression', []);
    }

    public function test_it_omits_package_provenance_when_the_provenance_file_does_not_exist(): void
    {
        // Point at a guaranteed-missing location so the controller exercises
        // the "file not present" branch regardless of repo-root state.
        $missingPath = sys_get_temp_dir().'/dw-provenance-missing-'.bin2hex(random_bytes(6));
        config([
            'server.expose_package_provenance' => true,
            'server.package_provenance_path' => $missingPath,
        ]);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonMissing(['package_provenance']);
    }

    public function test_it_omits_package_provenance_by_default_even_when_file_exists(): void
    {
        $this->useProvenanceFixture([
            'https://github.com/durable-workflow/workflow.git',
            'v2',
            'abc123def456',
        ]);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonMissing(['package_provenance']);
    }

    public function test_it_advertises_only_universal_payload_codecs_publicly(): void
    {
        $response = $this->getJson('/api/cluster/info')->assertOk();

        $this->assertSame(['avro'], $response->json('capabilities.payload_codecs'));
        $this->assertNull($response->json('capabilities.payload_codecs_engine_specific'));
    }

    public function test_it_rejects_requests_when_token_auth_is_enabled_but_token_is_not_configured(): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
        ]);

        $this->getJson('/api/cluster/info')
            ->assertStatus(500)
            ->assertSee('DW_AUTH_TOKEN or DW_PRINCIPAL_TOKENS is not configured');
    }

    public function test_it_rejects_requests_when_signature_auth_is_enabled_but_key_is_not_configured(): void
    {
        config([
            'server.auth.driver' => 'signature',
            'server.auth.signature_key' => null,
        ]);

        $this->getJson('/api/cluster/info')
            ->assertStatus(500)
            ->assertSee('DW_SIGNATURE_KEY is not configured');
    }

    public function test_it_includes_structural_limits_from_the_package(): void
    {
        $response = $this->getJson('/api/cluster/info');

        $response->assertOk()
            ->assertJsonStructure([
                'structural_limits' => [
                    'pending_activity_count',
                    'pending_child_count',
                    'pending_timer_count',
                    'pending_signal_count',
                    'pending_update_count',
                    'command_batch_size',
                    'payload_size_bytes',
                    'memo_size_bytes',
                    'search_attribute_size_bytes',
                    'history_transaction_size',
                    'warning_threshold_percent',
                ],
            ]);

        $limits = $response->json('structural_limits');

        $this->assertIsInt($limits['pending_activity_count']);
        $this->assertIsInt($limits['history_transaction_size']);
        $this->assertGreaterThan(0, $limits['pending_activity_count']);
        $this->assertGreaterThan(0, $limits['history_transaction_size']);
    }

    public function test_it_publishes_the_full_operator_metrics_snapshot(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/cluster/info?include=diagnostics');

        $response->assertOk()
            ->assertJsonStructure([
                'operator_metrics' => [
                    'generated_at',
                    'runs' => [
                        'repair_needed',
                        'claim_failed',
                        'compatibility_blocked',
                    ],
                    'tasks' => [
                        'ready',
                        'ready_due',
                        'delayed',
                        'leased',
                        'dispatch_failed',
                        'claim_failed',
                        'dispatch_overdue',
                        'lease_expired',
                        'unhealthy',
                    ],
                    'backlog' => [
                        'runnable_tasks',
                        'delayed_tasks',
                        'leased_tasks',
                        'tasks_added_last_minute',
                        'tasks_dispatched_last_minute',
                        'unhealthy_tasks',
                        'repair_needed_runs',
                        'claim_failed_runs',
                        'compatibility_blocked_runs',
                    ],
                    'repair' => [
                        'missing_task_candidates',
                        'selected_missing_task_candidates',
                        'oldest_missing_run_started_at',
                        'max_missing_run_age_ms',
                    ],
                    'workers' => [
                        'required_compatibility',
                        'active_workers',
                        'active_worker_scopes',
                        'active_workers_supporting_required',
                        'fleet',
                    ],
                    'backend' => [
                        'supported',
                        'issues',
                    ],
                    'structural_limits',
                    'repair_policy' => [
                        'redispatch_after_seconds',
                        'loop_throttle_seconds',
                        'scan_limit',
                        'failure_backoff_max_seconds',
                    ],
                ],
            ]);

        $this->assertIsArray($response->json('operator_metrics.workers.fleet'));
    }

    public function test_operator_metrics_defaults_to_the_configured_default_namespace(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        config(['server.default_namespace' => 'default']);

        $response = $this->getJson('/api/cluster/info?include=diagnostics');

        $response->assertOk()
            ->assertJsonPath('default_namespace', 'default')
            ->assertJsonPath('operator_metrics.runs.total', 0);
    }

    public function test_operator_metrics_scopes_to_the_x_namespace_header(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        WorkflowNamespace::query()->create([
            'name' => 'imports',
            'description' => 'Imports namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/cluster/info?include=diagnostics', ['X-Namespace' => 'imports']);

        $response->assertOk()
            ->assertJsonPath('operator_metrics.runs.total', 0);
    }

    public function test_structural_limits_reflect_custom_configuration(): void
    {
        config([
            'workflows.v2.structural_limits.pending_activity_count' => 500,
            'workflows.v2.structural_limits.history_transaction_size' => 1000,
        ]);

        $response = $this->getJson('/api/cluster/info');

        $response->assertOk()
            ->assertJsonPath('structural_limits.pending_activity_count', 500)
            ->assertJsonPath('structural_limits.history_transaction_size', 1000);
    }

    public function test_it_includes_package_provenance_when_exposure_is_enabled_and_file_exists(): void
    {
        config(['server.expose_package_provenance' => true]);

        $this->useProvenanceFixture([
            'https://github.com/durable-workflow/workflow.git',
            'v2',
            'abc123def456',
        ]);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('package_provenance.source', 'https://github.com/durable-workflow/workflow.git')
            ->assertJsonPath('package_provenance.ref', 'v2')
            ->assertJsonPath('package_provenance.commit', 'abc123def456');
    }

    public function test_package_provenance_is_admin_only_when_role_tokens_are_configured(): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.role_tokens' => [
                'worker' => 'worker-token',
                'operator' => 'operator-token',
                'admin' => 'admin-token',
            ],
            'server.auth.backward_compatible' => true,
            'server.expose_package_provenance' => true,
        ]);

        $this->useProvenanceFixture([
            'https://github.com/durable-workflow/workflow.git',
            'v2',
            'fedcba987654',
        ]);

        $this->getJson('/api/cluster/info', $this->bearerHeaders('worker-token'))
            ->assertOk()
            ->assertJsonMissingPath('package_provenance');

        $this->getJson('/api/cluster/info', $this->bearerHeaders('operator-token'))
            ->assertOk()
            ->assertJsonMissingPath('package_provenance');

        $this->getJson('/api/cluster/info', $this->bearerHeaders('admin-token'))
            ->assertOk()
            ->assertJsonPath('package_provenance.source', 'https://github.com/durable-workflow/workflow.git')
            ->assertJsonPath('package_provenance.ref', 'v2')
            ->assertJsonPath('package_provenance.commit', 'fedcba987654');
    }

    public function test_tests_do_not_mutate_the_repo_root_provenance_file(): void
    {
        // TD-S041 regression: verify the test fixture never touches
        // base_path('.package-provenance'). Capture its state, run a full
        // provenance-exposing flow, then confirm the repo-root file is
        // unchanged (present-with-same-contents, or still absent).
        $repoProvenance = base_path('.package-provenance');
        $existedBefore = is_file($repoProvenance);
        $beforeContents = $existedBefore ? file_get_contents($repoProvenance) : null;

        config(['server.expose_package_provenance' => true]);
        $this->useProvenanceFixture([
            'https://github.com/durable-workflow/workflow.git',
            'v2',
            'deadbeef12345',
        ]);

        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('package_provenance.commit', 'deadbeef12345');

        $existedAfter = is_file($repoProvenance);
        $this->assertSame(
            $existedBefore,
            $existedAfter,
            'Provenance tests must not change whether the repo-root .package-provenance file exists.',
        );

        if ($existedBefore) {
            $this->assertSame(
                $beforeContents,
                file_get_contents($repoProvenance),
                'Provenance tests must not overwrite the repo-root .package-provenance file.',
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function bearerHeaders(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];
    }
}
