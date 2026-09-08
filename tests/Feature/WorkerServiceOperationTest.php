<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Avro;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Models\WorkflowTask;

final class WorkerServiceOperationTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createNamespace('default');
        $this->registerWorker('nexus-worker', 'nexus', supportedWorkflowTypes: ['tests.nexus-caller', 'tests.nexus-target']);
    }

    public function test_worker_command_dispatches_a_service_operation_and_persists_its_outcome(): void
    {
        $this->seedCatalog();
        $task = $this->leaseWorkflow();

        $this->complete($task, $this->command())->assertOk();

        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame('started', $call->status->value ?? $call->status);
        $this->assertSame('billing', $call->endpoint_name);
        $this->assertSame('invoicing', $call->service_name);
        $this->assertSame('createinvoice', $call->operation_name);
        $this->assertSame($task['run_id'], $call->caller_workflow_run_id);
        $this->assertSame('default', $call->caller_namespace);
        $target = WorkflowRun::query()->findOrFail($call->linked_workflow_run_id);
        $this->assertSame([['invoice' => 42]], Avro::unserialize($target->input));
        $this->assertDatabaseHas('workflow_tasks', ['workflow_run_id' => $target->id, 'status' => 'ready']);
        $this->assertDatabaseHas('workflow_history_events', ['workflow_run_id' => $task['run_id'], 'event_type' => 'ServiceCallStarted']);
        $this->assertSame('completed', WorkflowTask::query()->findOrFail($task['task_id'])->status->value);
    }

    private function command(array $options = []): array
    {
        return $options + [
            'type' => 'start_service_operation',
            'endpoint_name' => 'billing',
            'service_name' => 'invoicing',
            'operation_name' => 'createinvoice',
            'request_payload' => Avro::envelope([['invoice' => 42]]),
        ];
    }

    private function complete(array $task, array $command): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [$command],
        ], $this->workerHeaders());
    }

    private function leaseWorkflow(): array
    {
        $this->postJson('/api/workflows', [
            'workflow_id' => 'nexus-caller', 'workflow_type' => 'tests.nexus-caller',
            'task_queue' => 'nexus', 'input' => [],
        ], $this->apiHeaders())->assertCreated();

        return $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'nexus-worker', 'task_queue' => 'nexus',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('poll_status', 'leased')->json('task');
    }

    private function seedCatalog(string $namespace = 'default', array $policy = []): WorkflowServiceOperation
    {
        $endpoint = WorkflowServiceEndpoint::query()->create(['namespace' => $namespace, 'endpoint_name' => 'billing']);
        $service = WorkflowService::query()->create([
            'workflow_service_endpoint_id' => $endpoint->id, 'namespace' => $namespace, 'service_name' => 'invoicing',
        ]);

        return WorkflowServiceOperation::query()->create([
            'workflow_service_endpoint_id' => $endpoint->id, 'workflow_service_id' => $service->id,
            'namespace' => $namespace, 'operation_name' => 'createinvoice', 'operation_mode' => 'async',
            'handler_binding_kind' => 'start_workflow', 'handler_target_reference' => 'tests.nexus-target',
            'handler_binding' => ['workflow_type' => 'tests.nexus-target'], 'boundary_policy' => $policy,
        ]);
    }
}
