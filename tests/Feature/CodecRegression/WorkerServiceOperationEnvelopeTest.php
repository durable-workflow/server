<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

final class WorkerServiceOperationEnvelopeTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public function test_service_operation_request_survives_worker_validation(): void
    {
        Queue::fake();
        $this->createNamespace('default');
        $this->registerWorker('nexus-worker', 'nexus');
        $endpoint = WorkflowServiceEndpoint::query()->create(['namespace' => 'default', 'endpoint_name' => 'billing']);
        $service = WorkflowService::query()->create([
            'namespace' => 'default', 'workflow_service_endpoint_id' => $endpoint->id, 'service_name' => 'invoicing',
        ]);
        WorkflowServiceOperation::query()->create([
            'namespace' => 'default', 'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id, 'operation_name' => 'createinvoice',
            'operation_mode' => 'async', 'handler_binding_kind' => 'start_workflow',
            'handler_target_reference' => 'tests.invoice', 'handler_binding' => ['workflow_type' => 'tests.invoice'],
        ]);
        $this->postJson('/api/workflows', [
            'workflow_id' => 'nexus-caller', 'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'nexus',
        ], $this->apiHeaders())->assertCreated();
        $task = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'nexus-worker', 'task_queue' => 'nexus',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('poll_status', 'leased')->json('task');

        ServerCodecRegressionFixtureExecutor::exercise(function () use ($task): void {
            $this->postJson('/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
                'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
                'commands' => [[
                    'type' => 'start_service_operation', 'endpoint_name' => 'billing', 'service_name' => 'invoicing',
                    'operation_name' => 'createinvoice',
                    'request_payload' => ['codec' => 'avro', 'blob' => 'wwHioz3/VYAiNwwCDgIOaW52b2ljZQRUAAA='],
                ]],
            ], $this->workerHeaders())->assertOk();
            $call = WorkflowServiceCall::query()->sole();
            $this->assertSame('started', $call->status);
            $this->assertNotNull($call->linked_workflow_run_id);
            $this->assertDatabaseHas('workflow_history_events', [
                'workflow_run_id' => $task['run_id'], 'event_type' => 'ServiceCallStarted',
            ]);
        });
    }
}
