<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\RuntimeExternalPayloadReference;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\SearchAttributeValueValidator;
use App\Support\ServiceCallBoundary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

class ServiceCatalogControllerTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/service-catalog-runtime-payloads'));

        parent::tearDown();
    }

    public function test_it_lists_empty_service_endpoints_for_a_new_namespace(): void
    {
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/service-endpoints');

        $response->assertOk()
            ->assertJsonPath('service_endpoints', []);
    }

    public function test_it_creates_and_shows_a_service_endpoint(): void
    {
        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints', [
                'endpoint_name' => 'Billing.API',
                'description' => 'Billing endpoint',
                'metadata' => ['owner' => 'payments'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('endpoint_name', 'billing.api')
            ->assertJsonPath('description', 'Billing endpoint')
            ->assertJsonPath('metadata.owner', 'payments');

        $this->assertDatabaseHas('workflow_service_endpoints', [
            'namespace' => 'default',
            'endpoint_name' => 'billing.api',
            'description' => 'Billing endpoint',
        ]);

        $showResponse = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/service-endpoints/BILLING.API');

        $showResponse->assertOk()
            ->assertJsonPath('endpoint_name', 'billing.api')
            ->assertJsonPath('metadata.owner', 'payments');
    }

    public function test_catalog_registration_does_not_initialize_execution_services(): void
    {
        $failOnResolution = static fn (): never => throw new \RuntimeException(
            'Catalog registration must not initialize service execution dependencies.',
        );

        $this->app->bind(ServiceControlPlane::class, $failOnResolution);
        $this->app->bind(ServiceCallBoundary::class, $failOnResolution);
        $this->app->bind(SearchAttributeValueValidator::class, $failOnResolution);

        foreach (['tenant-a', 'tenant-b', 'tenant-c', 'shared'] as $namespace) {
            $this->withHeaders($this->apiHeaders())
                ->postJson('/api/namespaces', [
                    'name' => $namespace,
                    'description' => "Apache registration regression namespace {$namespace}",
                ])
                ->assertCreated()
                ->assertJsonPath('name', $namespace);
        }

        $this->withHeaders($this->apiHeaders('shared'))
            ->postJson('/api/service-endpoints', [
                'endpoint_name' => 'shared-greeter',
                'description' => 'Apache registration regression endpoint',
                'metadata' => ['regression' => 'apache-service-registration'],
            ])
            ->assertCreated()
            ->assertJsonPath('endpoint_name', 'shared-greeter');

        $this->withHeaders($this->apiHeaders('shared'))
            ->postJson('/api/service-endpoints/shared-greeter/services', [
                'service_name' => 'Greeter',
                'description' => 'Apache registration regression service',
                'metadata' => ['regression' => 'apache-service-registration'],
            ])
            ->assertCreated()
            ->assertJsonPath('service_name', 'greeter');

        $this->withHeaders($this->apiHeaders('shared'))
            ->postJson('/api/service-endpoints/shared-greeter/services/Greeter/operations', [
                'operation_name' => 'greet',
                'description' => 'Apache registration regression operation',
                'operation_mode' => 'async',
                'handler_binding_kind' => 'activity_execution',
                'handler_target_reference' => 'Greeter.greet',
                'handler_binding' => ['activity_type' => 'Greeter.greet'],
                'retry_policy' => [
                    'maximum_attempts' => 3,
                    'initial_interval_seconds' => 1,
                ],
                'metadata' => ['regression' => 'apache-service-registration'],
            ])
            ->assertCreated()
            ->assertJsonPath('operation_name', 'greet');
    }

    public function test_it_scopes_service_endpoints_to_the_current_namespace(): void
    {
        $this->createNamespace('other');

        WorkflowServiceEndpoint::query()->create([
            'namespace' => 'default',
            'endpoint_name' => 'billing',
        ]);

        WorkflowServiceEndpoint::query()->create([
            'namespace' => 'other',
            'endpoint_name' => 'inventory',
        ]);

        $defaultResponse = $this->withHeaders($this->apiHeaders('default'))
            ->getJson('/api/service-endpoints');

        $defaultResponse->assertOk()
            ->assertJsonCount(1, 'service_endpoints')
            ->assertJsonPath('service_endpoints.0.endpoint_name', 'billing');

        $otherResponse = $this->withHeaders($this->apiHeaders('other'))
            ->getJson('/api/service-endpoints');

        $otherResponse->assertOk()
            ->assertJsonCount(1, 'service_endpoints')
            ->assertJsonPath('service_endpoints.0.endpoint_name', 'inventory');
    }

    public function test_it_rejects_duplicate_endpoint_names_in_the_same_namespace(): void
    {
        WorkflowServiceEndpoint::query()->create([
            'namespace' => 'default',
            'endpoint_name' => 'billing',
        ]);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints', [
                'endpoint_name' => 'BILLING',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('reason', 'endpoint_already_exists')
            ->assertJsonPath('endpoint_name', 'billing');
    }

    public function test_it_creates_lists_shows_and_updates_services_within_an_endpoint(): void
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'default',
            'endpoint_name' => 'billing',
            'description' => 'Billing',
        ]);

        $createResponse = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services', [
                'service_name' => 'Invoicing',
                'description' => 'Invoice service',
                'metadata' => ['tier' => 'gold'],
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('endpoint_name', 'billing')
            ->assertJsonPath('service_name', 'invoicing')
            ->assertJsonPath('metadata.tier', 'gold');

        $this->assertDatabaseHas('workflow_services', [
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoicing',
        ]);

        $listResponse = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/service-endpoints/billing/services');

        $listResponse->assertOk()
            ->assertJsonCount(1, 'services')
            ->assertJsonPath('services.0.service_name', 'invoicing');

        $showResponse = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/service-endpoints/BILLING/services/INVOICING');

        $showResponse->assertOk()
            ->assertJsonPath('service_name', 'invoicing')
            ->assertJsonPath('description', 'Invoice service');

        $updateResponse = $this->withHeaders($this->apiHeaders())
            ->putJson('/api/service-endpoints/billing/services/invoicing', [
                'description' => 'Invoice orchestration service',
                'metadata' => ['tier' => 'platinum'],
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('description', 'Invoice orchestration service')
            ->assertJsonPath('metadata.tier', 'platinum');

        $this->assertDatabaseHas('workflow_services', [
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoicing',
            'description' => 'Invoice orchestration service',
        ]);
    }

    public function test_it_rejects_duplicate_service_names_within_the_same_endpoint(): void
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'default',
            'endpoint_name' => 'billing',
        ]);

        WorkflowService::query()->create([
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoicing',
        ]);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services', [
                'service_name' => 'INVOICING',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('reason', 'service_already_exists')
            ->assertJsonPath('service_name', 'invoicing');
    }

    public function test_it_creates_lists_shows_and_updates_operations(): void
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'default',
            'endpoint_name' => 'billing',
        ]);

        $service = WorkflowService::query()->create([
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoicing',
        ]);

        $createResponse = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations', [
                'operation_name' => 'CreateInvoice',
                'description' => 'Create a new invoice',
                'operation_mode' => 'sync',
                'handler_binding_kind' => 'start_workflow',
                'handler_target_reference' => 'workflows.invoice.create',
                'handler_binding' => ['workflow_type' => 'InvoiceWorkflow'],
                'deadline_policy' => ['timeout_seconds' => 30],
                'idempotency_policy' => ['required' => true],
                'cancellation_policy' => ['mode' => 'allow'],
                'retry_policy' => ['max_attempts' => 3],
                'boundary_policy' => ['visibility' => 'service'],
                'metadata' => ['team' => 'billing'],
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('endpoint_name', 'billing')
            ->assertJsonPath('service_name', 'invoicing')
            ->assertJsonPath('operation_name', 'createinvoice')
            ->assertJsonPath('operation_mode', 'sync')
            ->assertJsonPath('handler_binding_kind', 'start_workflow')
            ->assertJsonPath('handler_target_reference', 'workflows.invoice.create')
            ->assertJsonPath('handler_binding.workflow_type', 'InvoiceWorkflow')
            ->assertJsonPath('retry_policy.max_attempts', 3);

        $operation = WorkflowServiceOperation::query()->where('operation_name', 'createinvoice')->first();
        $this->assertNotNull($operation);

        $listResponse = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/service-endpoints/billing/services/invoicing/operations');

        $listResponse->assertOk()
            ->assertJsonCount(1, 'operations')
            ->assertJsonPath('operations.0.operation_name', 'createinvoice');

        $showResponse = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/service-endpoints/BILLING/services/INVOICING/operations/CREATEINVOICE');

        $showResponse->assertOk()
            ->assertJsonPath('operation_name', 'createinvoice')
            ->assertJsonPath('deadline_policy.timeout_seconds', 30);

        $updateResponse = $this->withHeaders($this->apiHeaders())
            ->putJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice', [
                'operation_mode' => 'async',
                'handler_binding_kind' => 'update_workflow',
                'handler_target_reference' => 'updates.invoice.submit',
                'handler_binding' => ['update_name' => 'submitInvoice'],
                'retry_policy' => ['max_attempts' => 5],
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('operation_mode', 'async')
            ->assertJsonPath('handler_binding_kind', 'update_workflow')
            ->assertJsonPath('handler_target_reference', 'updates.invoice.submit')
            ->assertJsonPath('handler_binding.update_name', 'submitInvoice')
            ->assertJsonPath('retry_policy.max_attempts', 5);

        $this->assertDatabaseHas('workflow_service_operations', [
            'id' => $operation->id,
            'operation_mode' => 'async',
            'handler_binding_kind' => 'update_workflow',
            'handler_target_reference' => 'updates.invoice.submit',
        ]);

        $this->assertSame(
            ['update_name' => 'submitInvoice'],
            $operation->refresh()->handler_binding,
        );
    }

    public function test_it_rejects_duplicate_operation_names_within_the_same_service(): void
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'default',
            'endpoint_name' => 'billing',
        ]);

        $service = WorkflowService::query()->create([
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoicing',
        ]);

        WorkflowServiceOperation::query()->create([
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'createinvoice',
            'operation_mode' => 'sync',
            'handler_binding_kind' => 'start_workflow',
            'handler_target_reference' => 'workflows.invoice.create',
        ]);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations', [
                'operation_name' => 'CREATEINVOICE',
                'operation_mode' => 'sync',
                'handler_binding_kind' => 'start_workflow',
                'handler_target_reference' => 'workflows.invoice.create',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('reason', 'operation_already_exists')
            ->assertJsonPath('operation_name', 'createinvoice');
    }

    public function test_it_shows_a_durable_service_call_snapshot(): void
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'default',
            'endpoint_name' => 'billing',
        ]);

        $service = WorkflowService::query()->create([
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoicing',
        ]);

        $operation = WorkflowServiceOperation::query()->create([
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'createinvoice',
            'operation_mode' => 'async',
            'handler_binding_kind' => 'update_workflow',
            'handler_target_reference' => 'updates.invoice.submit',
        ]);

        $payloadDirectory = storage_path('framework/testing/service-catalog-runtime-payloads');
        File::deleteDirectory($payloadDirectory);
        WorkflowNamespace::query()->where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'threshold_bytes' => 32,
                'config' => ['uri' => 'file://'.$payloadDirectory],
            ],
        ]);
        $registry = app(RuntimeExternalPayloadRegistry::class);
        $inputPayload = 'service-call-input';
        $inputUri = 'file://'.$payloadDirectory.'/input.avro';
        File::ensureDirectoryExists($payloadDirectory);
        File::put($payloadDirectory.'/input.avro', $inputPayload);
        $registry->trackRetained(
            'default',
            $inputUri,
            'avro',
            hash('sha256', $inputPayload),
            strlen($inputPayload),
        );
        $inputReference = $registry->referenceForUri('default', $inputUri);

        $outputPayload = 'service-call-output';
        $outputUri = 'file://'.$payloadDirectory.'/output.avro';
        File::put($payloadDirectory.'/output.avro', $outputPayload);
        $registry->trackRetained(
            'default',
            $outputUri,
            'avro',
            hash('sha256', $outputPayload),
            strlen($outputPayload),
        );
        $outputReference = $registry->referenceForUri('default', $outputUri);

        $serviceCall = WorkflowServiceCall::query()->create([
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'workflow_service_operation_id' => $operation->id,
            'endpoint_name' => $endpoint->endpoint_name,
            'service_name' => $service->service_name,
            'operation_name' => $operation->operation_name,
            'caller_namespace' => 'finance',
            'caller_workflow_instance_id' => 'caller-invoice-workflow',
            'caller_workflow_run_id' => (string) Str::ulid(),
            'target_namespace' => 'default',
            'linked_workflow_instance_id' => 'invoice-target-workflow',
            'linked_workflow_run_id' => (string) Str::ulid(),
            'linked_workflow_update_id' => (string) Str::ulid(),
            'status' => 'running',
            'operation_mode' => 'async',
            'resolved_binding_kind' => 'workflow_update',
            'resolved_target_reference' => 'updates.invoice.submit',
            'payload_codec' => 'avro',
            'input_payload_reference' => $inputUri,
            'output_payload_reference' => $outputUri,
            'idempotency_key' => 'invoice-123',
            'deadline_policy' => ['timeout_seconds' => 60],
            'idempotency_policy' => ['scope' => 'caller'],
            'cancellation_policy' => ['mode' => 'allow'],
            'retry_policy' => ['max_attempts' => 5],
            'boundary_policy' => ['visibility' => 'service'],
            'metadata' => [
                'ticket' => 'svc-1',
                'service_call_attempts' => [
                    [
                        'attempt' => 1,
                        'outcome' => 'handler_failed',
                        'failure_type' => 'TransientGreetingFailure',
                        'retry_scheduled' => true,
                        'scheduled_backoff_seconds' => 1,
                    ],
                    [
                        'attempt' => 2,
                        'outcome' => 'completed',
                        'retry_scheduled' => false,
                    ],
                ],
            ],
            'accepted_at' => now()->subMinute(),
            'started_at' => now()->subSeconds(15),
        ]);

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson(sprintf(
                '/api/service-endpoints/BILLING/services/INVOICING/operations/CREATEINVOICE/service-calls/%s',
                $serviceCall->id,
            ));

        $response->assertOk()
            ->assertJsonPath('id', $serviceCall->id)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('endpoint_name', 'billing')
            ->assertJsonPath('service_name', 'invoicing')
            ->assertJsonPath('operation_name', 'createinvoice')
            ->assertJsonPath('caller_namespace', 'finance')
            ->assertJsonPath('target_namespace', 'default')
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('operation_mode', 'async')
            ->assertJsonPath('resolved_binding_kind', 'workflow_update')
            ->assertJsonPath('resolved_target_reference', 'updates.invoice.submit')
            ->assertJsonPath('payload_codec', 'avro')
            ->assertJsonPath('input_payload_reference.schema', RuntimeExternalPayloadReference::SCHEMA)
            ->assertJsonPath('input_payload_reference.reference_id', $inputReference['reference_id'])
            ->assertJsonPath('input_payload_reference.sha256', hash('sha256', $inputPayload))
            ->assertJsonPath('output_payload_reference.schema', RuntimeExternalPayloadReference::SCHEMA)
            ->assertJsonPath('output_payload_reference.reference_id', $outputReference['reference_id'])
            ->assertJsonPath('output_payload_reference.sha256', hash('sha256', $outputPayload))
            ->assertJsonMissingPath('input_payload_reference.uri')
            ->assertJsonMissingPath('output_payload_reference.uri')
            ->assertJsonPath('idempotency_key', 'invoice-123')
            ->assertJsonPath('deadline_policy.timeout_seconds', 60)
            ->assertJsonPath('idempotency_policy.scope', 'caller')
            ->assertJsonPath('cancellation_policy.mode', 'allow')
            ->assertJsonPath('retry_policy.max_attempts', 5)
            ->assertJsonPath('boundary_policy.visibility', 'service')
            ->assertJsonPath('metadata.ticket', 'svc-1')
            ->assertJsonPath('retry_attempt_count', 2)
            ->assertJsonPath('service_call_attempts.0.attempt', 1)
            ->assertJsonPath('service_call_attempts.0.failure_type', 'TransientGreetingFailure')
            ->assertJsonPath('service_call_attempts.0.scheduled_backoff_seconds', 1)
            ->assertJsonPath('service_call_attempts.1.outcome', 'completed')
            ->assertJsonPath('accepted_at', $serviceCall->accepted_at?->toIso8601String())
            ->assertJsonPath('started_at', $serviceCall->started_at?->toIso8601String());
    }

    public function test_it_requires_a_handler_target_reference_or_non_empty_handler_binding_for_operations(): void
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'default',
            'endpoint_name' => 'billing',
        ]);

        WorkflowService::query()->create([
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoicing',
        ]);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/invoicing/operations', [
                'operation_name' => 'createinvoice',
                'operation_mode' => 'sync',
                'handler_binding_kind' => 'start_workflow',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('handler_target_reference');
    }

    public function test_it_blocks_deleting_service_and_operation_resources_that_still_have_dependents(): void
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'default',
            'endpoint_name' => 'billing',
        ]);

        $service = WorkflowService::query()->create([
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoicing',
        ]);

        $operation = WorkflowServiceOperation::query()->create([
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'createinvoice',
            'operation_mode' => 'sync',
            'handler_binding_kind' => 'start_workflow',
            'handler_target_reference' => 'workflows.invoice.create',
        ]);

        WorkflowServiceCall::query()->create([
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'workflow_service_operation_id' => $operation->id,
            'endpoint_name' => 'billing',
            'service_name' => 'invoicing',
            'operation_name' => 'createinvoice',
            'target_namespace' => 'default',
            'status' => 'accepted',
            'operation_mode' => 'sync',
            'resolved_binding_kind' => 'workflow_run',
            'accepted_at' => now(),
        ]);

        $this->withHeaders($this->apiHeaders())
            ->deleteJson('/api/service-endpoints/billing')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'endpoint_has_services');

        $this->withHeaders($this->apiHeaders())
            ->deleteJson('/api/service-endpoints/billing/services/invoicing')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'service_has_operations');

        $this->withHeaders($this->apiHeaders())
            ->deleteJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'operation_has_service_calls');
    }

    public function test_it_deletes_unused_operations_services_and_endpoints(): void
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'default',
            'endpoint_name' => 'billing',
        ]);

        $service = WorkflowService::query()->create([
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoicing',
        ]);

        WorkflowServiceOperation::query()->create([
            'namespace' => 'default',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'createinvoice',
            'operation_mode' => 'sync',
            'handler_binding_kind' => 'start_workflow',
            'handler_target_reference' => 'workflows.invoice.create',
        ]);

        $this->withHeaders($this->apiHeaders())
            ->deleteJson('/api/service-endpoints/billing/services/invoicing/operations/createinvoice')
            ->assertOk()
            ->assertJsonPath('operation_name', 'createinvoice')
            ->assertJsonPath('outcome', 'deleted');

        $this->withHeaders($this->apiHeaders())
            ->deleteJson('/api/service-endpoints/billing/services/invoicing')
            ->assertOk()
            ->assertJsonPath('service_name', 'invoicing')
            ->assertJsonPath('outcome', 'deleted');

        $this->withHeaders($this->apiHeaders())
            ->deleteJson('/api/service-endpoints/billing')
            ->assertOk()
            ->assertJsonPath('endpoint_name', 'billing')
            ->assertJsonPath('outcome', 'deleted');

        $this->assertDatabaseMissing('workflow_service_operations', [
            'namespace' => 'default',
            'operation_name' => 'createinvoice',
        ]);
        $this->assertDatabaseMissing('workflow_services', [
            'namespace' => 'default',
            'service_name' => 'invoicing',
        ]);
        $this->assertDatabaseMissing('workflow_service_endpoints', [
            'namespace' => 'default',
            'endpoint_name' => 'billing',
        ]);
    }
}
