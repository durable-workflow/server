<?php

namespace Tests\Feature;

use App\Models\SearchAttributeDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

class ServiceExecutionRoutesTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    public function test_execute_returns_404_envelope_when_endpoint_missing(): void
    {
        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/missing/services/svc/operations/op/execute', [
                'arguments' => null,
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('reason', 'endpoint_not_found');
    }

    public function test_execute_dispatches_through_service_control_plane(): void
    {
        $this->seedCatalog();

        $stub = new class implements ServiceControlPlane
        {
            public ?array $captured = null;

            public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array
            {
                $this->captured = [
                    'endpoint' => $endpointName,
                    'service' => $serviceName,
                    'operation' => $operationName,
                    'options' => $options,
                ];

                return [
                    'accepted' => true,
                    'service_call_id' => '01JEXECUTECALL000000000000',
                    'namespace' => 'default',
                    'endpoint_name' => $endpointName,
                    'service_name' => $serviceName,
                    'operation_name' => $operationName,
                    'operation_mode' => 'async',
                    'resolved_binding_kind' => 'workflow_run',
                    'resolved_target_reference' => 'workflows.invoice.create',
                    'status' => 'accepted',
                    'linked_workflow_instance_id' => 'invoice-1',
                    'linked_workflow_run_id' => '01JRUN0000000000000000000A',
                    'linked_workflow_update_id' => null,
                    'reason' => null,
                ];
            }

            public function describeCall(string $serviceCallId, array $options = []): array
            {
                return ['found' => false, 'service_call_id' => $serviceCallId] + array_fill_keys([
                    'namespace',
                    'endpoint_name',
                    'service_name',
                    'operation_name',
                    'operation_mode',
                    'status',
                    'resolved_binding_kind',
                    'resolved_target_reference',
                    'linked_workflow_instance_id',
                    'linked_workflow_run_id',
                    'linked_workflow_update_id',
                    'accepted_at',
                    'started_at',
                    'completed_at',
                    'failed_at',
                    'cancelled_at',
                    'failure_message',
                    'reason',
                ], null);
            }

            public function cancelCall(string $serviceCallId, array $options = []): array
            {
                return [
                    'accepted' => false,
                    'service_call_id' => $serviceCallId,
                    'namespace' => null,
                    'status' => null,
                    'linked_workflow_instance_id' => null,
                    'linked_workflow_run_id' => null,
                    'reason' => 'service_call_not_found',
                ];
            }
        };

        $this->app->instance(ServiceControlPlane::class, $stub);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice/execute', [
                'arguments' => 'codec:json:WyJUYXlsb3IiXQ==',
                'mode_override' => 'async',
                'idempotency_key' => 'idem-1',
                'target_workflow_run_id' => 'run-target-1',
            ]);

        $response->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('service_call_id', '01JEXECUTECALL000000000000')
            ->assertJsonPath('linked_workflow_instance_id', 'invoice-1')
            ->assertJsonPath('resolved_binding_kind', 'workflow_run');

        $this->assertNotNull($stub->captured);
        $this->assertSame('billing', $stub->captured['endpoint']);
        $this->assertSame('invoicing', $stub->captured['service']);
        $this->assertSame('createinvoice', $stub->captured['operation']);
        $this->assertSame('default', $stub->captured['options']['namespace']);
        $this->assertSame('codec:json:WyJUYXlsb3IiXQ==', $stub->captured['options']['arguments']);
        $this->assertSame('async', $stub->captured['options']['mode_override']);
        $this->assertSame('idem-1', $stub->captured['options']['idempotency_key']);
        $this->assertSame('run-target-1', $stub->captured['options']['target_workflow_run_id']);
        $this->assertArrayHasKey('service_call_id', $stub->captured['options']);
    }

    public function test_execute_rejects_registered_search_attribute_type_mismatch_before_dispatch(): void
    {
        $this->seedCatalog();

        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'CustomerAge',
            'type' => 'int',
        ]);

        $stub = new class implements ServiceControlPlane
        {
            public bool $executed = false;

            public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array
            {
                $this->executed = true;

                return ['accepted' => true];
            }

            public function describeCall(string $serviceCallId, array $options = []): array
            {
                return ['found' => false, 'service_call_id' => $serviceCallId];
            }

            public function cancelCall(string $serviceCallId, array $options = []): array
            {
                return ['accepted' => false, 'service_call_id' => $serviceCallId];
            }
        };

        $this->app->instance(ServiceControlPlane::class, $stub);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice/execute', [
                'search_attributes' => ['CustomerAge' => 'not-an-int'],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'validation_failed')
            ->assertJsonPath(
                'validation_errors.search_attributes.0',
                fn (string $msg): bool => str_contains($msg, 'CustomerAge')
                    && str_contains($msg, 'registered as int'),
            );

        $this->assertFalse($stub->executed);
        $this->assertDatabaseMissing('workflow_service_calls', [
            'namespace' => 'default',
            'endpoint_name' => 'billing',
            'service_name' => 'invoicing',
            'operation_name' => 'createinvoice',
        ]);
    }

    public function test_execute_preserves_registered_keyword_list_search_attribute_type(): void
    {
        $this->seedCatalog();

        SearchAttributeDefinition::create([
            'namespace' => 'default',
            'name' => 'Tags',
            'type' => 'keyword_list',
        ]);

        $stub = new class implements ServiceControlPlane
        {
            public ?array $captured = null;

            public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array
            {
                $this->captured = [
                    'endpoint' => $endpointName,
                    'service' => $serviceName,
                    'operation' => $operationName,
                    'options' => $options,
                ];

                return [
                    'accepted' => true,
                    'service_call_id' => $options['service_call_id'] ?? '01JEXECUTECALL000000000001',
                    'namespace' => 'default',
                    'endpoint_name' => $endpointName,
                    'service_name' => $serviceName,
                    'operation_name' => $operationName,
                    'operation_mode' => 'async',
                    'resolved_binding_kind' => 'workflow_run',
                    'resolved_target_reference' => 'workflows.invoice.create',
                    'status' => 'accepted',
                    'linked_workflow_instance_id' => 'invoice-1',
                    'linked_workflow_run_id' => '01JRUN0000000000000000000B',
                    'linked_workflow_update_id' => null,
                    'reason' => null,
                ];
            }

            public function describeCall(string $serviceCallId, array $options = []): array
            {
                return ['found' => false, 'service_call_id' => $serviceCallId];
            }

            public function cancelCall(string $serviceCallId, array $options = []): array
            {
                return ['accepted' => false, 'service_call_id' => $serviceCallId];
            }
        };

        $this->app->instance(ServiceControlPlane::class, $stub);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice/execute', [
                'search_attributes' => ['Tags' => ['alpha', 'beta']],
            ])
            ->assertOk()
            ->assertJsonPath('accepted', true);

        $this->assertNotNull($stub->captured);
        $this->assertSame(['Tags' => ['alpha', 'beta']], $stub->captured['options']['search_attributes']);
        $this->assertSame(['Tags' => 'keyword_list'], $stub->captured['options']['search_attribute_types']);
    }

    public function test_execute_rejects_boundary_policy_before_control_plane_dispatch(): void
    {
        [, , $operation] = $this->seedCatalog();
        $operation->update([
            'boundary_policy' => [
                'authorization' => [
                    'caller_namespaces' => ['deny' => ['analytics']],
                ],
            ],
        ]);

        $stub = new class implements ServiceControlPlane
        {
            public bool $executed = false;

            public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array
            {
                $this->executed = true;

                return ['accepted' => true];
            }

            public function describeCall(string $serviceCallId, array $options = []): array
            {
                return ['found' => false, 'service_call_id' => $serviceCallId];
            }

            public function cancelCall(string $serviceCallId, array $options = []): array
            {
                return ['accepted' => false, 'service_call_id' => $serviceCallId];
            }
        };

        $this->app->instance(ServiceControlPlane::class, $stub);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice/execute', [
                'caller_namespace' => 'analytics',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('outcome', 'rejected_forbidden')
            ->assertJsonPath('reason', 'caller_namespace_denied')
            ->assertJsonPath('caller_namespace', 'analytics');

        $this->assertFalse($stub->executed);
        $this->assertDatabaseHas('workflow_service_calls', [
            'namespace' => 'default',
            'caller_namespace' => 'analytics',
            'endpoint_name' => 'billing',
            'service_name' => 'invoicing',
            'operation_name' => 'createinvoice',
            'status' => 'failed',
            'outcome' => 'rejected_forbidden',
        ]);
    }

    public function test_cancel_route_routes_through_service_control_plane(): void
    {
        [, , $operation] = $this->seedCatalog();

        $serviceCall = WorkflowServiceCall::query()->create([
            'workflow_service_endpoint_id' => $operation->workflow_service_endpoint_id,
            'workflow_service_id' => $operation->workflow_service_id,
            'workflow_service_operation_id' => $operation->id,
            'namespace' => 'default',
            'endpoint_name' => 'billing',
            'service_name' => 'invoicing',
            'operation_name' => 'createinvoice',
            'status' => 'accepted',
            'operation_mode' => 'async',
            'resolved_binding_kind' => 'workflow_run',
            'resolved_target_reference' => 'workflows.invoice.create',
        ]);

        $stub = new class implements ServiceControlPlane
        {
            public ?array $captured = null;

            public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array
            {
                return [];
            }

            public function describeCall(string $serviceCallId, array $options = []): array
            {
                return ['found' => false, 'service_call_id' => $serviceCallId];
            }

            public function cancelCall(string $serviceCallId, array $options = []): array
            {
                $this->captured = [
                    'service_call_id' => $serviceCallId,
                    'options' => $options,
                ];

                return [
                    'accepted' => true,
                    'service_call_id' => $serviceCallId,
                    'namespace' => $options['namespace'] ?? null,
                    'status' => 'cancelled',
                    'linked_workflow_instance_id' => null,
                    'linked_workflow_run_id' => null,
                    'reason' => null,
                ];
            }
        };

        $this->app->instance(ServiceControlPlane::class, $stub);

        $url = sprintf(
            '/api/service-endpoints/billing/services/invoicing/operations/createinvoice/service-calls/%s/cancel',
            $serviceCall->id,
        );

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson($url, [
                'reason' => 'caller-abandoned',
            ]);

        $response->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('service_call_id', $serviceCall->id)
            ->assertJsonPath('status', 'cancelled');

        $this->assertNotNull($stub->captured);
        $this->assertSame($serviceCall->id, $stub->captured['service_call_id']);
        $this->assertSame('default', $stub->captured['options']['namespace']);
        $this->assertSame('caller-abandoned', $stub->captured['options']['reason']);
    }

    public function test_service_call_show_routes_through_service_control_plane_describe(): void
    {
        [, , $operation] = $this->seedCatalog();

        $serviceCall = WorkflowServiceCall::query()->create([
            'workflow_service_endpoint_id' => $operation->workflow_service_endpoint_id,
            'workflow_service_id' => $operation->workflow_service_id,
            'workflow_service_operation_id' => $operation->id,
            'namespace' => 'default',
            'endpoint_name' => 'billing',
            'service_name' => 'invoicing',
            'operation_name' => 'createinvoice',
            'status' => 'started',
            'operation_mode' => 'async',
            'resolved_binding_kind' => 'workflow_run',
            'resolved_target_reference' => '01JSVCCALLRUN00000000004',
            'linked_workflow_instance_id' => 'invoice-4',
            'linked_workflow_run_id' => '01JSVCCALLRUN00000000004',
        ]);

        $stub = new class implements ServiceControlPlane
        {
            public ?array $captured = null;

            public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array
            {
                return [];
            }

            public function describeCall(string $serviceCallId, array $options = []): array
            {
                $this->captured = [
                    'service_call_id' => $serviceCallId,
                    'options' => $options,
                ];

                return [
                    'found' => true,
                    'service_call_id' => $serviceCallId,
                    'namespace' => $options['namespace'] ?? null,
                    'status' => 'started',
                    'outcome' => 'accepted',
                    'linked_workflow_instance_id' => 'invoice-4',
                    'linked_workflow_run_id' => '01JSVCCALLRUN00000000004',
                    'linked_workflow_update_id' => null,
                    'resolved_binding_kind' => 'workflow_run',
                    'resolved_target_reference' => '01JSVCCALLRUN00000000004',
                    'reason' => null,
                ];
            }

            public function cancelCall(string $serviceCallId, array $options = []): array
            {
                return [];
            }
        };

        $this->app->instance(ServiceControlPlane::class, $stub);

        $url = sprintf(
            '/api/service-endpoints/billing/services/invoicing/operations/createinvoice/service-calls/%s',
            $serviceCall->id,
        );

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson($url);

        $response->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('service_call_id', $serviceCall->id)
            ->assertJsonPath('linked_workflow_instance_id', 'invoice-4');

        $this->assertNotNull($stub->captured);
        $this->assertSame($serviceCall->id, $stub->captured['service_call_id']);
        $this->assertSame('default', $stub->captured['options']['namespace']);
    }

    /**
     * @return array{0: WorkflowServiceEndpoint, 1: WorkflowService, 2: WorkflowServiceOperation}
     */
    private function seedCatalog(): array
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'default',
            'endpoint_name' => 'billing',
        ]);

        $service = WorkflowService::query()->create([
            'workflow_service_endpoint_id' => $endpoint->id,
            'namespace' => 'default',
            'service_name' => 'invoicing',
        ]);

        $operation = WorkflowServiceOperation::query()->create([
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'namespace' => 'default',
            'operation_name' => 'createinvoice',
            'operation_mode' => 'async',
            'handler_binding_kind' => 'start_workflow',
            'handler_target_reference' => 'workflows.invoice.create',
            'handler_binding' => ['workflow_type' => 'InvoiceWorkflow'],
        ]);

        return [$endpoint, $service, $operation];
    }
}
