<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\CoordinationHealthContract;
use App\Support\ServerTopology;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertJsonPath('topology.matching_role.wake_owner', 'worker_loop')
            ->assertJsonPath('topology.matching_role.task_dispatch_mode', 'poll')
            ->assertJsonPath(
                'topology.shape_assignments.standalone_server.process_classes.0.name',
                'server_http_node',
            )
            ->assertJsonPath(
                'topology.shape_assignments.standalone_server.process_classes.0.roles.3',
                'history_projection',
            )
            ->assertJsonPath(
                'topology.shape_assignments.split_control_execution.process_classes.4.roles.0',
                'execution_plane',
            )
            ->assertJsonPath(
                'topology.authority_boundaries.control_plane.writes.1',
                'workflow_runs.status',
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
            ->assertJsonPath('topology.migration_path.0.step', 'audit_role_boundaries')
            ->assertJsonPath(
                'topology.migration_path.5.step',
                'optional_execution_partitioning',
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
            ->assertJsonPath('topology.matching_role.wake_owner', 'dedicated_repair_pass')
            ->assertJsonPath('topology.matching_role.task_dispatch_mode', 'queue');
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
                'planned',
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
            ->assertJsonPath('worker_protocol.server_capabilities.child_workflow_retry_policy', true)
            ->assertJsonPath('worker_protocol.server_capabilities.child_workflow_timeouts', true)
            ->assertJsonPath('worker_protocol.server_capabilities.parent_close_policy', true)
            ->assertJsonPath('worker_protocol.server_capabilities.query_tasks', true)
            ->assertJsonPath('worker_protocol.server_capabilities.non_retryable_failures', true);
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
