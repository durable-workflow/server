<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

class ServiceCallHttpReplayTest extends TestCase
{
    use DatabaseMigrations;
    use ServerTestHelpers;

    #[DataProvider('handlerKinds')]
    public function test_foreign_caller_replay_cannot_admit_an_orphan_or_rebind_the_original(string $kind): void
    {
        $this->createNamespace('default');
        $this->createNamespace('other');
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'default', 'endpoint_name' => 'billing',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'default', 'workflow_service_endpoint_id' => $endpoint->id, 'service_name' => 'invoices',
        ]);
        WorkflowServiceOperation::query()->create([
            'namespace' => 'default', 'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id, 'operation_name' => 'create',
            'operation_mode' => 'async', 'handler_binding_kind' => $kind,
            'handler_target_reference' => 'original-target',
        ]);
        $path = '/api/service-endpoints/billing/services/invoices/operations/create/execute';
        $this->withHeaders($this->apiHeaders())->postJson($path, ['idempotency_key' => 'same-key'])->assertOk();
        $call = WorkflowServiceCall::query()->sole();
        $before = $call->getRawOriginal();
        $this->withHeaders($this->apiHeaders())->postJson(str_replace(
            ['billing', 'invoices', 'create'], ['BILLING', 'INVOICES', 'CREATE'], $path,
        ), [
            'idempotency_key' => 'same-key',
        ])->assertOk()->assertJsonPath('service_call_id', $call->id)->assertJsonPath('idempotent_replay', true);
        $this->withHeaders($this->apiHeaders())->postJson($path, [
            'idempotency_key' => 'same-key', 'caller_namespace' => 'other',
        ])->assertForbidden()->assertJsonPath('reason', 'service_operation_forbidden');
        $this->assertSame($before, $call->fresh()->getRawOriginal());
        $this->assertDatabaseCount('workflow_service_calls', 1);
    }

    public static function handlerKinds(): array
    {
        return [['activity_execution'], ['invocable_carrier_request']];
    }
}
