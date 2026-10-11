<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

final class NexusExecuteArgumentsTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public function test_execute_envelope_keeps_serialized_service_arguments(): void
    {
        $this->createNamespace('default');
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'default', 'endpoint_name' => 'greeter',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'default', 'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'shared',
        ]);
        WorkflowServiceOperation::query()->create([
            'namespace' => 'default', 'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id, 'operation_name' => 'greet',
            'operation_mode' => 'async', 'handler_binding_kind' => 'start_workflow',
            'handler_target_reference' => 'php.greeter',
        ]);
        $this->mock(ServiceControlPlane::class)->shouldReceive('execute')->once()
            ->with('greeter', 'shared', 'greet', Mockery::subset([
                'namespace' => 'default', 'payload_codec' => 'avro',
                'arguments' => 'wwHioz3/VYAiNwwECgZBZGEKCk5leHVzAA==',
                'payload_blob' => 'wwHioz3/VYAiNwwECgZBZGEKCk5leHVzAA==',
            ]))->andReturn(['accepted' => true, 'status' => 'started']);

        ServerCodecRegressionFixtureExecutor::exercise(function (): void {
            $this->postJson('/api/service-endpoints/greeter/services/shared/operations/greet/execute', [
                'arguments' => ['codec' => 'avro', 'blob' => 'wwHioz3/VYAiNwwECgZBZGEKCk5leHVzAA=='],
                'payload_codec' => 'avro',
            ], $this->apiHeaders())->assertOk();
        });
    }
}
