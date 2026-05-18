<?php

namespace Tests\Feature;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\CoordinationHealthContract;
use App\Support\ServerTopology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class ClusterInfoTest extends TestCase
{
    use RefreshDatabase;

    private ?string $provenanceFixturePath = null;

    /** @var list<string> */
    private array $externalExecutorConfigFixturePaths = [];

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
        $response = $this->getJson('/api/cluster/info')->assertOk();

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

        $response = $this->getJson('/api/cluster/info')->assertOk();

        $response
            ->assertJsonPath('coordination_health.status', 'blocked')
            ->assertJsonPath('coordination_health.http_status', 503)
            ->assertJsonPath('coordination_health.blocked_by.0', 'migrations')
            ->assertJsonPath(
                'coordination_health.remediation',
                'Restore database connectivity and migrate the workflow tables before relying on workflow v2 rollout-safety health.',
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

        $this->getJson('/api/cluster/info')
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

            $response = $this->getJson('/api/cluster/info')->assertOk();

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

            $response = $this->getJson('/api/cluster/info')->assertOk();

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
            ->assertJsonPath('topology.matching_role.backpressure_model', 'lease_ownership');
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
                'worker_protocol.external_execution_surface_contract.contract_seams.payload_external_storage.status',
                'published',
            )
            ->assertJsonPath(
                'worker_protocol.external_execution_surface_contract.contract_seams.payload_external_storage.cluster_info_path',
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
            ->assertJsonPath('namespace.retention_days', 45)
            ->assertJsonPath(
                'namespace.external_payload_storage.schema',
                'durable-workflow.v2.external-payload-reference.v1',
            )
            ->assertJsonPath('namespace.external_payload_storage.version', 1)
            ->assertJsonPath('namespace.external_payload_storage.configured', true)
            ->assertJsonPath('namespace.external_payload_storage.enabled', true)
            ->assertJsonPath('namespace.external_payload_storage.status', 'available')
            ->assertJsonPath('namespace.external_payload_storage.driver', 'custom')
            ->assertJsonPath('namespace.external_payload_storage.threshold_bytes', 1024)
            ->assertJsonPath('namespace.external_payload_storage.reference_uri_scheme', 'azblob')
            ->assertJsonPath('namespace.external_payload_storage.custom_driver_configurable', true)
            ->assertJsonPath('namespace.external_payload_storage.config_redacted', true)
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
            ->assertJsonPath('namespace.external_payload_storage.driver', 's3')
            ->assertJsonPath('namespace.external_payload_storage.reference_uri_scheme', 's3')
            ->assertJsonPath('namespace.external_payload_storage.config_redacted', true)
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
                'durable-workflow.v2.external-payload-reference.v1',
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
            ->assertJsonPath('worker_protocol.server_capabilities.non_retryable_failures', true);
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
        $this->assertSame(
            ['workflow-serializer-y', 'workflow-serializer-base64'],
            $response->json('capabilities.payload_codecs_engine_specific.php'),
        );
    }

    public function test_it_rejects_requests_when_token_auth_is_enabled_but_token_is_not_configured(): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
        ]);

        $this->getJson('/api/cluster/info')
            ->assertStatus(500)
            ->assertSee('DW_AUTH_TOKEN is not configured');
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

        $response = $this->getJson('/api/cluster/info');

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

        $response = $this->getJson('/api/cluster/info');

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

        $response = $this->getJson('/api/cluster/info', ['X-Namespace' => 'imports']);

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
