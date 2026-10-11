<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\RuntimeExternalPayloadReference;
use App\Support\RuntimeExternalPayloadRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\ServiceOperationView;

class ServiceCatalogReadControllerTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['default', 'caller', 'target', 'unrelated'] as $namespace) {
            $this->createNamespace($namespace);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/catalog-read-payloads'));
        parent::tearDown();
    }

    public function test_discovery_advertises_callable_reads_and_namespace_header_precedes_query(): void
    {
        $this->withHeaders($this->apiHeaders())->getJson('/api/cluster/info')->assertOk()
            ->assertJsonPath('service_catalog_read_contract.schema', 'durable-workflow.v2.service-catalog-read.contract')
            ->assertJsonPath('service_catalog_read_contract.version', 1)
            ->assertJsonPath('service_catalog_read_contract.roles', ['operator', 'admin'])
            ->assertJsonPath('service_catalog_read_contract.pagination.max_per_page', 100);
        $operation = $this->catalog('default');
        $this->catalog('target');
        $this->withHeaders($this->apiHeaders())->getJson('/api/service-catalog/operations?namespace=target')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $operation->id)
            ->assertJsonPath('namespace', 'default');
        $this->withHeaders(['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '999'])
            ->getJson('/api/service-catalog/calls')->assertStatus(400);
    }

    public function test_catalog_reads_use_scoped_shared_views_without_resolving_execution_services(): void
    {
        $this->app->bind(ServiceControlPlane::class, static fn () => throw new \RuntimeException('Reads must not start service execution.'));
        $operation = $this->catalog('default');
        $foreign = $this->catalog('target');

        foreach (['endpoints' => $operation->workflow_service_endpoint_id, 'services' => $operation->workflow_service_id, 'operations' => $operation->id] as $kind => $id) {
            $this->withHeaders($this->apiHeaders())
                ->getJson('/api/service-catalog/'.$kind)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)
                ->assertJsonPath('namespace', 'default')->assertJsonPath('per_page', 50);
            $this->withHeaders($this->apiHeaders())
                ->getJson('/api/service-catalog/'.$kind.'/'.$id)->assertOk()->assertJsonPath('id', $id);
        }
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/service-catalog/operations/'.$operation->id)
            ->assertExactJson(ServiceOperationView::detail($operation, 'default'));
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/service-catalog/services?endpoint_id='.$foreign->workflow_service_endpoint_id)
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('total', 0);
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/service-catalog/operations?service_id='.$operation->workflow_service_id)
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_catalog_pagination_is_bounded_with_stable_tie_order(): void
    {
        $first = $this->catalog('default', 'first');
        $second = $this->catalog('default', 'second');
        $ids = [$first->id, $second->id];
        sort($ids);
        foreach ([1, 2] as $page) {
            $this->withHeaders($this->apiHeaders())
                ->getJson('/api/service-catalog/operations?per_page=1&page='.$page)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[$page - 1])
                ->assertJsonPath('current_page', $page)->assertJsonPath('last_page', 2)->assertJsonPath('total', 2);
        }
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/service-catalog/operations?per_page=1&page=3')
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('total', 2);
    }

    public function test_endpoint_and_service_details_bound_related_collections_and_report_more(): void
    {
        $operation = $this->catalog('default');
        for ($index = 0; $index < 101; $index++) {
            WorkflowServiceOperation::query()->create([
                'namespace' => 'default',
                'workflow_service_endpoint_id' => $operation->workflow_service_endpoint_id,
                'workflow_service_id' => $operation->workflow_service_id,
                'operation_name' => sprintf('operation-%03d', $index),
                'operation_mode' => 'sync',
                'handler_binding_kind' => 'start_workflow',
                'handler_target_reference' => 'test.greet',
            ]);
        }
        foreach (['endpoints' => $operation->workflow_service_endpoint_id, 'services' => $operation->workflow_service_id] as $kind => $id) {
            $this->withHeaders($this->apiHeaders())->getJson('/api/service-catalog/'.$kind.'/'.$id)
                ->assertOk()->assertJsonCount(100, 'operations')->assertJsonPath('related_has_more.operations', true);
        }
        $this->withHeaders($this->apiHeaders())->getJson('/api/service-catalog/operations?per_page=100')
            ->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('total', 102)->assertJsonPath('last_page', 2);
    }

    public function test_call_visibility_uses_durable_caller_target_and_owned_namespace_snapshots(): void
    {
        $operation = $this->catalog('target');
        $outbound = $this->fixtureCall($operation, ['caller_namespace' => 'caller', 'target_namespace' => 'target']);
        $inbound = $this->fixtureCall($operation, ['caller_namespace' => 'target', 'target_namespace' => 'caller']);
        $irrelevant = $this->fixtureCall($operation, ['caller_namespace' => 'target', 'target_namespace' => 'target']);
        foreach (['relevant' => [$outbound->id, $inbound->id], 'caller' => [$outbound->id], 'target' => [$inbound->id], 'owned' => []] as $scope => $expected) {
            sort($expected);
            $response = $this->withHeaders($this->apiHeaders('caller'))->getJson('/api/service-catalog/calls?scope='.$scope)->assertOk();
            $this->assertSame($expected, array_column($response->json('data'), 'id'));
        }
        $this->withHeaders($this->apiHeaders('unrelated'))->getJson('/api/service-catalog/calls')
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('total', 0);
        $this->withHeaders($this->apiHeaders('caller'))->getJson('/api/service-catalog/calls/'.$outbound->id)
            ->assertOk()->assertJsonPath('caller_link.in_observer_namespace', true)
            ->assertJsonPath('linked_run_ref.in_observer_namespace', false);
        $this->withHeaders($this->apiHeaders('target'))->getJson('/api/service-catalog/calls/'.$outbound->id)
            ->assertOk()->assertJsonPath('caller_link.in_observer_namespace', false)
            ->assertJsonPath('linked_run_ref.in_observer_namespace', true);
        $this->withHeaders($this->apiHeaders('caller'))->getJson('/api/service-catalog/calls/'.$irrelevant->id)->assertNotFound();
    }

    public function test_calls_preserve_terminal_cause_original_deadline_and_attempts_and_filter_by_outcome(): void
    {
        $operation = $this->catalog('default');
        $failed = $this->fixtureCall($operation, [
            'status' => 'failed', 'outcome' => 'timed_out',
            'accepted_at' => '2026-10-11 19:00:00.123456', 'call_deadline_at' => '2026-10-11 19:00:01.123456',
            'failed_at' => '2026-10-11 19:00:01.123456', 'failure_message' => 'Original service deadline expired.',
            'outcome_metadata' => ['failure_type' => 'ServiceCallTimedOut'],
            'metadata' => ['service_call_attempts' => [['attempt' => 1, 'failure_type' => 'TransientFailure', 'retry_scheduled' => true]]],
        ]);
        $this->fixtureCall($operation, ['status' => 'completed', 'outcome' => 'completed']);
        $this->fixtureCall($operation, ['status' => 'cancelled', 'outcome' => 'cancelled']);
        $this->withHeaders($this->apiHeaders())->getJson('/api/service-catalog/calls?status_bucket=failed&outcome=timed_out&operation_id='.$operation->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $failed->id)
            ->assertJsonPath('data.0.is_terminal', true)->assertJsonPath('data.0.outcome_bucket', 'failed');
        $this->withHeaders($this->apiHeaders())->getJson('/api/service-catalog/calls/'.$failed->id)
            ->assertOk()->assertJsonPath('accepted_at', '2026-10-11T19:00:00.123456Z')
            ->assertJsonPath('call_deadline_at', '2026-10-11T19:00:01.123456Z')
            ->assertJsonPath('failed_at', '2026-10-11T19:00:01.123456Z')
            ->assertJsonPath('failure_type', 'ServiceCallTimedOut')->assertJsonPath('retry_attempt_count', 1)
            ->assertJsonPath('service_call_attempts.0.failure_type', 'TransientFailure');
        $this->withHeaders($this->apiHeaders())->getJson('/api/service-catalog/calls?bucket=cancelled')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'cancelled');
        $this->withHeaders($this->apiHeaders())->getJson('/api/service-catalog/calls?outcome_bucket=failed')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $failed->id);
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_fail_without_broadening_the_read(string $path, string $field): void
    {
        $this->withHeaders($this->apiHeaders())->getJson('/api/service-catalog/'.$path)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return [
            ['calls?scope=all', 'scope'], ['calls?scope[]=caller', 'scope'],
            ['calls?status=running', 'status'], ['calls?status_bucket=terminal', 'status_bucket'],
            ['calls?bucket=anything', 'bucket'], ['calls?outcome=success', 'outcome'],
            ['calls?outcome_bucket=anything', 'outcome_bucket'], ['calls?status_bucket=failed&bucket=open', 'bucket'],
            ['calls?per_page=0', 'per_page'], ['calls?per_page=101', 'per_page'], ['calls?per_page[]=50', 'per_page'],
            ['calls?page=0', 'page'], ['calls?page=1.5', 'page'], ['calls?page=9999999999999999999999999', 'page'],
            ['calls?endpoint_id[]=foreign', 'endpoint_id'], ['calls?operation_id=', 'operation_id'],
            ['calls?unknown_filter=completed', 'unknown_filter'], ['endpoints?scope=all', 'scope'],
            ['operations?status=completed', 'status'], ['calls/missing?per_page=50', 'per_page'],
        ];
    }

    public function test_missing_and_foreign_details_are_indistinguishable(): void
    {
        $operation = $this->catalog('target');
        $call = $this->fixtureCall($operation);
        foreach (['endpoints' => $operation->workflow_service_endpoint_id, 'services' => $operation->workflow_service_id, 'operations' => $operation->id, 'calls' => $call->id] as $kind => $id) {
            $foreign = $this->withHeaders($this->apiHeaders('unrelated'))->getJson('/api/service-catalog/'.$kind.'/'.$id)->assertNotFound();
            $missing = $this->withHeaders($this->apiHeaders('unrelated'))->getJson('/api/service-catalog/'.$kind.'/missing')->assertNotFound();
            $this->assertSame($foreign->json(), $missing->json());
        }
    }

    public function test_operator_and_admin_reads_are_authorized_but_worker_refusal_precedes_namespace_lookup(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => null, 'server.auth.role_tokens' => [
            'worker' => 'worker-token', 'operator' => 'operator-token', 'admin' => 'admin-token',
        ]]);
        $operation = $this->catalog('default');
        $call = $this->fixtureCall($operation);
        foreach (['endpoints' => $operation->workflow_service_endpoint_id, 'services' => $operation->workflow_service_id, 'operations' => $operation->id, 'calls' => $call->id] as $kind => $id) {
            foreach (['', '/'.$id] as $suffix) {
                foreach (['operator', 'admin'] as $role) {
                    $this->withHeaders($this->apiHeaders() + ['Authorization' => 'Bearer '.$role.'-token'])
                        ->getJson('/api/service-catalog/'.$kind.$suffix)->assertOk();
                }
                $this->withHeaders($this->apiHeaders('nonexistent') + ['Authorization' => 'Bearer worker-token'])
                    ->getJson('/api/service-catalog/'.$kind.$suffix)->assertForbidden()->assertJsonPath('reason', 'forbidden');
            }
        }
        $this->withHeaders($this->apiHeaders() + ['Authorization' => 'Bearer operator-token'])
            ->postJson('/api/service-endpoints', ['endpoint_name' => 'forbidden'])->assertForbidden();
    }

    public function test_call_payloads_are_opaque_in_their_namespace_and_redacted_for_foreign_observers(): void
    {
        $operation = $this->catalog('target');
        $call = $this->fixtureCall($operation, ['caller_namespace' => 'caller']);
        $directory = storage_path('framework/testing/catalog-read-payloads');
        File::ensureDirectoryExists($directory);
        WorkflowNamespace::query()->where('name', 'target')->update(['external_payload_storage' => [
            'driver' => 'local', 'enabled' => true, 'threshold_bytes' => 32, 'config' => ['uri' => 'file://'.$directory],
        ]]);
        $registry = app(RuntimeExternalPayloadRegistry::class);
        $references = [];
        foreach (['input', 'output', 'failure'] as $kind) {
            $bytes = 'transport-'.$kind;
            $uri = 'file://'.$directory.'/'.$kind.'.avro';
            File::put($directory.'/'.$kind.'.avro', $bytes);
            $registry->trackRetained('target', $uri, 'avro', hash('sha256', $bytes), strlen($bytes));
            $call->{$kind.'_payload_reference'} = $uri;
            $references[$kind] = $registry->referenceForUri('target', $uri);
        }
        $call->save();
        $owned = $this->withHeaders($this->apiHeaders('target'))->getJson('/api/service-catalog/calls/'.$call->id)
            ->assertOk()->assertJsonPath('payloads_in_observer_namespace', true);
        $foreign = $this->withHeaders($this->apiHeaders('caller'))->getJson('/api/service-catalog/calls/'.$call->id)
            ->assertOk()->assertJsonPath('payloads_in_observer_namespace', false);
        foreach ($references as $kind => $reference) {
            $owned->assertJsonPath($kind.'_payload_reference.schema', RuntimeExternalPayloadReference::SCHEMA)
                ->assertJsonPath($kind.'_payload_reference.reference_id', $reference['reference_id'])
                ->assertJsonMissingPath($kind.'_payload_reference.uri');
            $foreign->assertJsonPath($kind.'_payload_reference', null);
        }
        $this->assertStringNotContainsString('file://', $owned->getContent());
        $this->assertStringNotContainsString('file://', $foreign->getContent());
    }

    private function catalog(string $namespace, string $endpointName = 'billing'): WorkflowServiceOperation
    {
        $endpoint = WorkflowServiceEndpoint::query()->create(['namespace' => $namespace, 'endpoint_name' => $endpointName]);
        $service = WorkflowService::query()->create(['namespace' => $namespace, 'workflow_service_endpoint_id' => $endpoint->id, 'service_name' => 'greeter']);

        return WorkflowServiceOperation::query()->create([
            'namespace' => $namespace, 'workflow_service_endpoint_id' => $endpoint->id, 'workflow_service_id' => $service->id,
            'operation_name' => 'greet', 'operation_mode' => 'sync', 'handler_binding_kind' => 'start_workflow', 'handler_target_reference' => 'test.greet',
        ]);
    }

    private function fixtureCall(WorkflowServiceOperation $operation, array $overrides = []): WorkflowServiceCall
    {
        return WorkflowServiceCall::query()->create(array_replace([
            'namespace' => $operation->namespace,
            'workflow_service_endpoint_id' => $operation->workflow_service_endpoint_id,
            'workflow_service_id' => $operation->workflow_service_id,
            'workflow_service_operation_id' => $operation->id,
            'endpoint_name' => 'billing', 'service_name' => 'greeter', 'operation_name' => 'greet',
            'caller_namespace' => $operation->namespace, 'target_namespace' => $operation->namespace,
            'caller_workflow_instance_id' => (string) Str::ulid(), 'caller_workflow_run_id' => (string) Str::ulid(),
            'linked_workflow_instance_id' => (string) Str::ulid(), 'linked_workflow_run_id' => (string) Str::ulid(),
            'operation_mode' => 'sync', 'status' => 'accepted', 'outcome' => 'accepted',
            'accepted_at' => '2026-10-11 19:00:00.000000', 'created_at' => '2026-10-11 19:00:00.000000',
        ], $overrides));
    }
}
