<?php

namespace Tests\Feature;

use App\Models\SearchAttributeDefinition;
use App\Support\RuntimeExternalPayloadReference;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\ServiceCallBoundary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceBoundaryPolicy;

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
            ->postJson('/api/service-endpoints/missing/services/svc/operations/op/execute', [
                'arguments' => null,
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('reason', 'endpoint_not_found');

        $this->assertFalse($stub->executed);
        $this->assertDatabaseMissing('workflow_service_calls', [
            'namespace' => 'default',
            'endpoint_name' => 'missing',
            'service_name' => 'svc',
            'operation_name' => 'op',
        ]);
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
                'arguments' => ['Taylor'],
                'mode_override' => 'async',
                'idempotency_key' => 'idem-1',
                'target_workflow_run_id' => 'run-target-1',
            ]);

        $recordedCall = WorkflowServiceCall::query()->firstOrFail();

        $response->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('id', $recordedCall->id)
            ->assertJsonPath('service_call_id', '01JEXECUTECALL000000000000')
            ->assertJsonPath('caller_namespace', 'default')
            ->assertJsonPath('outcome', 'accepted')
            ->assertJsonPath('linked_workflow_instance_id', 'invoice-1')
            ->assertJsonPath('resolved_binding_kind', 'workflow_run');

        $this->assertNotNull($stub->captured);
        $this->assertSame('billing', $stub->captured['endpoint']);
        $this->assertSame('invoicing', $stub->captured['service']);
        $this->assertSame('createinvoice', $stub->captured['operation']);
        $this->assertSame('default', $stub->captured['options']['namespace']);
        $this->assertSame(['Taylor'], $stub->captured['options']['arguments']);
        $this->assertSame('avro', $stub->captured['options']['payload_codec']);
        $this->assertSame('async', $stub->captured['options']['mode_override']);
        $this->assertSame('idem-1', $stub->captured['options']['idempotency_key']);
        $this->assertSame('run-target-1', $stub->captured['options']['target_workflow_run_id']);
        $this->assertArrayHasKey('service_call_id', $stub->captured['options']);
    }

    public function test_execute_replay_with_matching_idempotency_key_returns_existing_call_without_new_admission(): void
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
            'caller_namespace' => 'tenant-a',
            'caller_workflow_instance_id' => 'caller-1',
            'caller_workflow_run_id' => 'run-1',
            'target_namespace' => 'default',
            'status' => 'started',
            'outcome' => 'accepted',
            'operation_mode' => 'async',
            'resolved_binding_kind' => 'workflow_run',
            'resolved_target_reference' => 'workflows.invoice.create',
            'idempotency_key' => 'caller-1-nexus-op-1',
            'accepted_at' => now(),
            'started_at' => now(),
        ]);

        $stub = new class($serviceCall) implements ServiceControlPlane
        {
            public bool $executeCalled = false;

            public ?array $describeCaptured = null;

            public function __construct(private readonly WorkflowServiceCall $serviceCall) {}

            public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array
            {
                $this->executeCalled = true;

                return ['accepted' => false];
            }

            public function describeCall(string $serviceCallId, array $options = []): array
            {
                $this->describeCaptured = [
                    'service_call_id' => $serviceCallId,
                    'options' => $options,
                ];

                return [
                    'found' => true,
                    'service_call_id' => $this->serviceCall->id,
                    'namespace' => $this->serviceCall->namespace,
                    'caller_namespace' => $this->serviceCall->caller_namespace,
                    'target_namespace' => $this->serviceCall->target_namespace,
                    'endpoint_name' => $this->serviceCall->endpoint_name,
                    'service_name' => $this->serviceCall->service_name,
                    'operation_name' => $this->serviceCall->operation_name,
                    'operation_mode' => 'async',
                    'status' => 'started',
                    'outcome' => 'accepted',
                    'resolved_binding_kind' => 'workflow_run',
                    'resolved_target_reference' => 'workflows.invoice.create',
                    'reason' => null,
                ];
            }

            public function cancelCall(string $serviceCallId, array $options = []): array
            {
                return ['accepted' => false, 'service_call_id' => $serviceCallId];
            }
        };

        $this->app->instance(ServiceControlPlane::class, $stub);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice/execute', [
                'caller_namespace' => 'tenant-a',
                'caller_workflow_instance_id' => 'caller-1',
                'caller_workflow_run_id' => 'run-1',
                'idempotency_key' => 'caller-1-nexus-op-1',
            ]);

        $response->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('service_call_id', $serviceCall->id);

        $this->assertSame(1, WorkflowServiceCall::query()->count());
        $this->assertFalse($stub->executeCalled);
        $this->assertNotNull($stub->describeCaptured);
        $this->assertSame($serviceCall->id, $stub->describeCaptured['service_call_id']);
        $this->assertSame('default', $stub->describeCaptured['options']['namespace']);
    }

    public function test_execute_replay_dispatches_existing_pre_dispatch_call_without_new_admission(): void
    {
        [, , $operation] = $this->seedCatalog();

        SearchAttributeDefinition::query()->create([
            'namespace' => 'default',
            'name' => 'InvoiceRegion',
            'type' => 'keyword',
        ]);

        $serviceCall = WorkflowServiceCall::query()->create([
            'workflow_service_endpoint_id' => $operation->workflow_service_endpoint_id,
            'workflow_service_id' => $operation->workflow_service_id,
            'workflow_service_operation_id' => $operation->id,
            'namespace' => 'default',
            'endpoint_name' => 'billing',
            'service_name' => 'invoicing',
            'operation_name' => 'createinvoice',
            'caller_namespace' => 'tenant-a',
            'caller_workflow_instance_id' => 'caller-1',
            'caller_workflow_run_id' => 'run-1',
            'target_namespace' => 'default',
            'status' => 'accepted',
            'outcome' => 'accepted',
            'operation_mode' => 'async',
            'resolved_binding_kind' => 'workflow_run',
            'resolved_target_reference' => 'workflows.invoice.create',
            'idempotency_key' => 'caller-1-nexus-op-2',
            'accepted_at' => now(),
        ]);

        $stub = new class($serviceCall) implements ServiceControlPlane
        {
            public ?array $captured = null;

            public function __construct(private readonly WorkflowServiceCall $serviceCall) {}

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
                    'service_call_id' => $this->serviceCall->id,
                    'status' => 'started',
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
                'arguments' => ['customer' => 'Taylor', 'invoice_id' => 42],
                'payload_codec' => 'json',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.payload_codec.0',
                fn (string $message): bool => str_contains($message, 'unsupported_payload_codec')
                    && str_contains($message, 'HTTP document transport'),
            );
        $this->assertNull($stub->captured);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice/execute', [
                'arguments' => ['customer' => 'Taylor', 'invoice_id' => 42],
                'payload_codec' => 'avro',
                'mode_override' => 'async',
                'wait_for' => 'completed',
                'wait_timeout_seconds' => 15,
                'caller_namespace' => 'tenant-a',
                'caller_workflow_instance_id' => 'caller-1',
                'caller_workflow_run_id' => 'run-1',
                'target_workflow_instance_id' => 'invoice-target-2',
                'target_workflow_run_id' => 'run-target-2',
                'connection' => 'shared-connection',
                'queue' => 'nexus-targets',
                'business_key' => 'invoice-42',
                'labels' => ['tenant' => 'tenant-a', 'kind' => 'invoice'],
                'memo' => ['source' => 'restart-replay'],
                'search_attributes' => ['InvoiceRegion' => 'west'],
                'duplicate_start_policy' => 'return_existing_active',
                'idempotency_key' => 'caller-1-nexus-op-2',
            ]);

        $response->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('service_call_id', $serviceCall->id);

        $this->assertSame(1, WorkflowServiceCall::query()->count());
        $this->assertNotNull($stub->captured);
        $this->assertSame($serviceCall->id, $stub->captured['options']['service_call_id']);
        $this->assertSame('accepted', $stub->captured['options']['boundary_policy_outcome']);
        $this->assertSame(['customer' => 'Taylor', 'invoice_id' => 42], $stub->captured['options']['arguments']);
        $this->assertSame('avro', $stub->captured['options']['payload_codec']);
        $this->assertSame('async', $stub->captured['options']['mode_override']);
        $this->assertSame('completed', $stub->captured['options']['wait_for']);
        $this->assertSame(15, $stub->captured['options']['wait_timeout_seconds']);
        $this->assertSame('caller-1-nexus-op-2', $stub->captured['options']['idempotency_key']);
        $this->assertSame('tenant-a', $stub->captured['options']['caller_namespace']);
        $this->assertSame('caller-1', $stub->captured['options']['caller_workflow_instance_id']);
        $this->assertSame('run-1', $stub->captured['options']['caller_workflow_run_id']);
        $this->assertSame('invoice-target-2', $stub->captured['options']['target_workflow_instance_id']);
        $this->assertSame('run-target-2', $stub->captured['options']['target_workflow_run_id']);
        $this->assertSame('shared-connection', $stub->captured['options']['connection']);
        $this->assertSame('nexus-targets', $stub->captured['options']['queue']);
        $this->assertSame('invoice-42', $stub->captured['options']['business_key']);
        $this->assertSame(['tenant' => 'tenant-a', 'kind' => 'invoice'], $stub->captured['options']['labels']);
        $this->assertSame(['source' => 'restart-replay'], $stub->captured['options']['memo']);
        $this->assertSame(['InvoiceRegion' => 'west'], $stub->captured['options']['search_attributes']);
        $this->assertSame(['InvoiceRegion' => 'keyword'], $stub->captured['options']['search_attribute_types']);
        $this->assertSame('return_existing_active', $stub->captured['options']['duplicate_start_policy']);
    }

    public function test_execute_replay_returns_existing_accepted_activity_call_after_handler_issue(): void
    {
        [, , $operation] = $this->seedCatalog();
        $operation->update([
            'handler_binding_kind' => 'activity_execution',
            'handler_target_reference' => 'Greeter.greet',
            'handler_binding' => ['activity_type' => 'Greeter.greet'],
        ]);

        $serviceCall = WorkflowServiceCall::query()->create([
            'workflow_service_endpoint_id' => $operation->workflow_service_endpoint_id,
            'workflow_service_id' => $operation->workflow_service_id,
            'workflow_service_operation_id' => $operation->id,
            'namespace' => 'default',
            'endpoint_name' => 'billing',
            'service_name' => 'invoicing',
            'operation_name' => 'createinvoice',
            'caller_namespace' => 'tenant-a',
            'caller_workflow_instance_id' => 'caller-activity',
            'caller_workflow_run_id' => 'run-activity',
            'target_namespace' => 'default',
            'status' => 'accepted',
            'outcome' => 'accepted',
            'operation_mode' => 'async',
            'resolved_binding_kind' => 'activity_execution',
            'resolved_target_reference' => 'activity-issued-1',
            'metadata' => [
                'activity_execution_id' => 'activity-issued-1',
                'activity_type' => 'Greeter.greet',
            ],
            'idempotency_key' => 'caller-activity-nexus-op-1',
            'accepted_at' => now(),
        ]);

        $stub = new class($serviceCall) implements ServiceControlPlane
        {
            public bool $executeCalled = false;

            public ?array $describeCaptured = null;

            public function __construct(private readonly WorkflowServiceCall $serviceCall) {}

            public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array
            {
                $this->executeCalled = true;

                return ['accepted' => false];
            }

            public function describeCall(string $serviceCallId, array $options = []): array
            {
                $this->describeCaptured = [
                    'service_call_id' => $serviceCallId,
                    'options' => $options,
                ];

                return [
                    'found' => true,
                    'accepted' => true,
                    'service_call_id' => $this->serviceCall->id,
                    'namespace' => $this->serviceCall->namespace,
                    'caller_namespace' => $this->serviceCall->caller_namespace,
                    'target_namespace' => $this->serviceCall->target_namespace,
                    'endpoint_name' => $this->serviceCall->endpoint_name,
                    'service_name' => $this->serviceCall->service_name,
                    'operation_name' => $this->serviceCall->operation_name,
                    'operation_mode' => 'async',
                    'status' => 'accepted',
                    'outcome' => 'accepted',
                    'resolved_binding_kind' => 'activity_execution',
                    'resolved_target_reference' => 'activity-issued-1',
                    'reason' => null,
                ];
            }

            public function cancelCall(string $serviceCallId, array $options = []): array
            {
                return ['accepted' => false, 'service_call_id' => $serviceCallId];
            }
        };

        $this->app->instance(ServiceControlPlane::class, $stub);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice/execute', [
                'caller_namespace' => 'tenant-a',
                'caller_workflow_instance_id' => 'caller-activity',
                'caller_workflow_run_id' => 'run-activity',
                'idempotency_key' => 'caller-activity-nexus-op-1',
            ]);

        $response->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('service_call_id', $serviceCall->id)
            ->assertJsonPath('resolved_target_reference', 'activity-issued-1');

        $this->assertSame(1, WorkflowServiceCall::query()->count());
        $this->assertFalse($stub->executeCalled);
        $this->assertNotNull($stub->describeCaptured);
        $this->assertSame($serviceCall->id, $stub->describeCaptured['service_call_id']);
        $this->assertSame('default', $stub->describeCaptured['options']['namespace']);
    }

    public function test_execute_replay_returns_existing_call_after_rate_limit_quota_is_consumed(): void
    {
        $this->seedCatalog();
        $this->bindDefaultServiceBoundaryPolicy([
            'rate_limit' => [
                'requests_per_minute' => 1,
                'retry_after_seconds' => 5,
            ],
        ]);

        $stub = new class implements ServiceControlPlane
        {
            public int $executeCount = 0;

            public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array
            {
                $this->executeCount++;

                return [
                    'accepted' => true,
                    'service_call_id' => $options['service_call_id'] ?? 'missing-call-id',
                    'status' => 'accepted',
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

        $payload = [
            'caller_namespace' => 'tenant-a',
            'caller_workflow_instance_id' => 'caller-1',
            'caller_workflow_run_id' => 'run-1',
            'idempotency_key' => 'caller-1-rate-limited-replay',
        ];

        $first = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice/execute', $payload);

        $first->assertOk()
            ->assertJsonPath('accepted', true);

        $serviceCallId = $first->json('service_call_id');

        $second = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice/execute', $payload);

        $second->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('service_call_id', $serviceCallId);

        $this->assertSame(2, $stub->executeCount);
        $this->assertSame(1, WorkflowServiceCall::query()->count());
    }

    public function test_execute_replay_returns_existing_call_without_releasing_concurrency_budget(): void
    {
        [, , $operation] = $this->seedCatalog();
        $operation->update([
            'boundary_policy' => [
                'concurrency_limit' => [
                    'max_in_flight' => 1,
                    'sync_only' => false,
                    'retry_after_seconds' => 2,
                ],
            ],
        ]);

        $stub = new class implements ServiceControlPlane
        {
            public int $executeCount = 0;

            public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array
            {
                $this->executeCount++;

                return [
                    'accepted' => true,
                    'service_call_id' => $options['service_call_id'] ?? 'missing-call-id',
                    'status' => 'accepted',
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

        $payload = [
            'caller_namespace' => 'tenant-a',
            'caller_workflow_instance_id' => 'caller-1',
            'caller_workflow_run_id' => 'run-1',
            'idempotency_key' => 'caller-1-concurrency-replay',
        ];

        $first = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice/execute', $payload);

        $first->assertOk()
            ->assertJsonPath('accepted', true);

        $serviceCallId = $first->json('service_call_id');

        $second = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice/execute', $payload);

        $second->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('service_call_id', $serviceCallId);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice/execute', [
                'caller_namespace' => 'tenant-a',
                'caller_workflow_instance_id' => 'caller-2',
                'caller_workflow_run_id' => 'run-2',
                'idempotency_key' => 'caller-2-concurrency-fresh',
            ])
            ->assertStatus(429)
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('reason', 'concurrency_limit_exceeded');

        $this->assertSame(2, $stub->executeCount);
        $this->assertSame(2, WorkflowServiceCall::query()->count());
    }

    public function test_execute_replay_with_denied_caller_namespace_returns_boundary_rejection(): void
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
            'caller_namespace' => 'analytics',
            'caller_workflow_instance_id' => 'caller-denied',
            'caller_workflow_run_id' => 'run-denied',
            'target_namespace' => 'default',
            'status' => 'started',
            'outcome' => 'accepted',
            'operation_mode' => 'async',
            'resolved_binding_kind' => 'workflow_run',
            'resolved_target_reference' => 'workflows.invoice.create',
            'idempotency_key' => 'caller-denied-nexus-op-1',
            'accepted_at' => now(),
            'started_at' => now(),
        ]);

        $operation->update([
            'boundary_policy' => [
                'authorization' => [
                    'caller_namespaces' => ['deny' => ['analytics']],
                ],
            ],
        ]);

        $stub = new class implements ServiceControlPlane
        {
            public bool $executeCalled = false;

            public bool $describeCalled = false;

            public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array
            {
                $this->executeCalled = true;

                return ['accepted' => true];
            }

            public function describeCall(string $serviceCallId, array $options = []): array
            {
                $this->describeCalled = true;

                return ['found' => true, 'service_call_id' => $serviceCallId];
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
                'caller_workflow_instance_id' => 'caller-denied',
                'caller_workflow_run_id' => 'run-denied',
                'idempotency_key' => 'caller-denied-nexus-op-1',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('error_type', 'rejected_forbidden')
            ->assertJsonPath('outcome', 'rejected_forbidden')
            ->assertJsonPath('reason', 'caller_namespace_denied')
            ->assertJsonPath('caller_namespace', 'analytics');
        $this->assertForbiddenResponseDoesNotDiscloseTarget($response->json());

        $this->assertNotSame($serviceCall->id, $response->json('service_call_id'));
        $this->assertFalse($stub->executeCalled);
        $this->assertFalse($stub->describeCalled);
        $this->assertSame(2, WorkflowServiceCall::query()->count());
        $this->assertDatabaseHas('workflow_service_calls', [
            'id' => $serviceCall->id,
            'namespace' => 'default',
            'caller_namespace' => 'analytics',
            'idempotency_key' => 'caller-denied-nexus-op-1',
            'status' => 'started',
            'outcome' => 'accepted',
        ]);
        $this->assertDatabaseHas('workflow_service_calls', [
            'namespace' => 'default',
            'caller_namespace' => 'analytics',
            'endpoint_name' => 'billing',
            'service_name' => 'invoicing',
            'operation_name' => 'createinvoice',
            'idempotency_key' => 'caller-denied-nexus-op-1',
            'status' => 'failed',
            'outcome' => 'rejected_forbidden',
            'outcome_reason' => 'caller_namespace_denied',
        ]);
    }

    public function test_execute_rejects_unlisted_caller_namespace_without_target_disclosure(): void
    {
        [, , $operation] = $this->seedCatalog();
        $operation->update([
            'boundary_policy' => [
                'authorization' => [
                    'caller_namespaces' => ['allow' => ['trusted']],
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
                'caller_workflow_instance_id' => 'caller-not-allowed',
                'caller_workflow_run_id' => 'run-not-allowed',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('error_type', 'rejected_forbidden')
            ->assertJsonPath('outcome', 'rejected_forbidden')
            ->assertJsonPath('reason', 'caller_namespace_not_allowed')
            ->assertJsonPath('message', 'Nexus operation is not permitted for this caller.')
            ->assertJsonPath('caller_namespace', 'analytics');
        $this->assertForbiddenResponseDoesNotDiscloseTarget($response->json());

        $this->assertFalse($stub->executed);
        $this->assertDatabaseHas('workflow_service_calls', [
            'namespace' => 'default',
            'caller_namespace' => 'analytics',
            'endpoint_name' => 'billing',
            'service_name' => 'invoicing',
            'operation_name' => 'createinvoice',
            'status' => 'failed',
            'outcome' => 'rejected_forbidden',
            'outcome_reason' => 'caller_namespace_not_allowed',
        ]);
    }

    public function test_execute_redacts_forbidden_policy_metadata_without_target_disclosure(): void
    {
        $this->seedCatalog();

        $this->app->forgetInstance(ServiceBoundaryPolicy::class);
        $this->app->instance(ServiceBoundaryPolicy::class, new class implements ServiceBoundaryPolicy
        {
            public function evaluate(
                \Workflow\V2\Support\ServiceBoundaryRequest $request,
            ): \Workflow\V2\Support\ServiceBoundaryDecision {
                return \Workflow\V2\Support\ServiceBoundaryDecision::denyAuthorization(
                    reason: 'custom_policy_forbidden',
                    message: sprintf(
                        'Denied [%s/%s/%s].',
                        $request->endpointName,
                        $request->serviceName,
                        $request->operationName,
                    ),
                    metadata: [
                        'failure_reason' => 'policy_rejection',
                        'endpoint_name' => $request->endpointName,
                        'service_name' => $request->serviceName,
                        'operation_name' => $request->operationName,
                        'resolved_target_reference' => $request->resolvedTargetReference,
                    ],
                );
            }
        });
        $this->app->forgetInstance(ServiceCallBoundary::class);

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
                'caller_workflow_instance_id' => 'caller-custom-denied',
                'caller_workflow_run_id' => 'run-custom-denied',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('error_type', 'rejected_forbidden')
            ->assertJsonPath('reason', 'custom_policy_forbidden')
            ->assertJsonPath('message', 'Nexus operation is not permitted for this caller.')
            ->assertJsonPath('outcome_metadata.failure_reason', 'policy_rejection');
        $this->assertForbiddenResponseDoesNotDiscloseTarget($response->json());

        $this->assertFalse($stub->executed);
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

    public function test_execute_rejects_malformed_payload_before_admission_or_dispatch(): void
    {
        $this->seedCatalog();

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
                'arguments' => ['name' => 'world'],
                'wait_for' => 'dispatch_anyway',
                'caller_namespace' => 'analytics',
                'caller_workflow_instance_id' => 'caller-malformed',
                'caller_workflow_run_id' => 'run-malformed',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'validation_failed')
            ->assertJsonPath(
                'validation_errors.wait_for.0',
                fn (string $message): bool => str_contains($message, 'selected wait for is invalid'),
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
            ->assertJsonPath('error_type', 'rejected_forbidden')
            ->assertJsonPath('outcome', 'rejected_forbidden')
            ->assertJsonPath('reason', 'caller_namespace_denied')
            ->assertJsonPath('caller_namespace', 'analytics');
        $this->assertForbiddenResponseDoesNotDiscloseTarget($response->json());

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

        $registry = app(RuntimeExternalPayloadRegistry::class);
        $payloadReferences = [];
        foreach (['input', 'output', 'failure'] as $kind) {
            $payload = 'service-call-'.$kind;
            $uri = 'file:///runtime-payloads/'.$kind.'.avro';
            $registry->trackRetained(
                'default',
                $uri,
                'avro',
                hash('sha256', $payload),
                strlen($payload),
            );
            $payloadReferences[$kind] = [
                'uri' => $uri,
                'reference' => $registry->referenceForUri('default', $uri),
            ];
        }

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
            'payload_codec' => 'avro',
            'input_payload_reference' => $payloadReferences['input']['uri'],
            'output_payload_reference' => $payloadReferences['output']['uri'],
            'failure_payload_reference' => $payloadReferences['failure']['uri'],
        ]);

        $stub = new class($serviceCall) implements ServiceControlPlane
        {
            public ?array $captured = null;

            public function __construct(private readonly WorkflowServiceCall $serviceCall) {}

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

                $this->serviceCall->forceFill([
                    'status' => 'cancelled',
                    'outcome' => 'cancelled',
                    'outcome_category' => 'handler',
                    'outcome_reason' => 'cancelled_by_request',
                    'outcome_metadata' => [
                        'failure_reason' => 'cancellation',
                    ],
                    'cancelled_at' => now(),
                ])->save();

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
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('outcome_metadata.failure_reason', 'cancellation')
            ->assertJsonPath('outcome_reason', 'cancelled_by_request')
            ->assertJsonPath('input_payload_reference.schema', RuntimeExternalPayloadReference::SCHEMA)
            ->assertJsonPath(
                'input_payload_reference.reference_id',
                $payloadReferences['input']['reference']['reference_id'],
            )
            ->assertJsonPath('output_payload_reference.schema', RuntimeExternalPayloadReference::SCHEMA)
            ->assertJsonPath(
                'output_payload_reference.reference_id',
                $payloadReferences['output']['reference']['reference_id'],
            )
            ->assertJsonPath('failure_payload_reference.schema', RuntimeExternalPayloadReference::SCHEMA)
            ->assertJsonPath(
                'failure_payload_reference.reference_id',
                $payloadReferences['failure']['reference']['reference_id'],
            )
            ->assertJsonMissingPath('input_payload_reference.uri')
            ->assertJsonMissingPath('output_payload_reference.uri')
            ->assertJsonMissingPath('failure_payload_reference.uri');

        $this->assertStringNotContainsString(
            'file://',
            json_encode($response->json(), JSON_THROW_ON_ERROR),
        );

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

    /**
     * @param array<string, mixed> $body
     */
    private function assertForbiddenResponseDoesNotDiscloseTarget(array $body): void
    {
        foreach ([
            'endpoint_id',
            'endpoint_name',
            'service_id',
            'service_name',
            'operation_id',
            'operation_name',
            'operation_mode',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $body);
        }

        $encoded = strtolower(json_encode($body, JSON_THROW_ON_ERROR));
        foreach (['billing', 'invoicing', 'createinvoice', 'workflows.invoice.create'] as $token) {
            $this->assertStringNotContainsString($token, $encoded);
        }
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function bindDefaultServiceBoundaryPolicy(array $rules): void
    {
        $this->app->forgetInstance(ServiceBoundaryPolicy::class);
        $this->app->instance(ServiceBoundaryPolicy::class, new DefaultServiceBoundaryPolicy($rules));
        $this->app->forgetInstance(ServiceCallBoundary::class);
    }
}
