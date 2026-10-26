<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowTask;

final class NexusWorkflowBindingContractTest extends TestCase
{
    use DatabaseMigrations;
    use ServerTestHelpers;

    private WorkflowRun $baseline;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createNamespace('default');
        $this->registerWorker('binding-worker', 'default', supportedWorkflowTypes: ['sdk.bound-order']);
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'binding-baseline', 'workflow_type' => 'sdk.bound-order', 'input' => [],
        ], $this->apiHeaders())->assertCreated();
        $this->baseline = WorkflowRun::query()->findOrFail($start->json('run_id'));
        $this->postJson('/api/service-endpoints', ['endpoint_name' => 'bindings'], $this->apiHeaders())->assertCreated();
        $this->postJson('/api/service-endpoints/bindings/services', ['service_name' => 'orders'], $this->apiHeaders())->assertCreated();
    }

    public function test_a_service_start_uses_the_regular_routes_default_queue_and_can_be_polled(): void
    {
        $this->operation('start_workflow', 'sdk.bound-order');
        $response = $this->execute(['wait_for' => 'accepted'])->assertSuccessful()->assertJsonPath('accepted', true);
        $run = WorkflowRun::query()->findOrFail($response->json('linked_workflow_run_id'));
        $this->assertSame($this->baseline->queue, $run->queue);
        $this->assertNotNull($run->queue);
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        $this->assertSame($run->queue, $task->queue);
        // Remove the ordinary start's task so the public poll must lease the
        // Nexus-created run, rather than succeeding on the comparison run.
        WorkflowTask::query()->where('workflow_run_id', $this->baseline->id)->delete();
        $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'binding-worker', 'task_queue' => $run->queue,
        ], $this->workerHeaders())->assertOk()->assertJsonPath('task.run_id', $run->id);
    }

    public function test_a_service_start_preserves_json_list_arguments(): void
    {
        $this->operation('start_workflow', 'sdk.bound-order', ['queue' => 'default']);
        $response = $this->execute(['wait_for' => 'accepted', 'arguments' => [7, 'order Ω', null]])
            ->assertSuccessful()->assertJsonPath('accepted', true);
        $run = WorkflowRun::query()->findOrFail($response->json('linked_workflow_run_id'));
        $this->assertIsString($run->arguments);
        $this->assertSame([7, 'order Ω', null], Serializer::unserialize($run->arguments));
    }

    public function test_a_zero_completion_wait_is_refused_before_admitting_an_update_call(): void
    {
        $this->operation('update_workflow', 'approve', ['workflow_instance_id' => $this->baseline->workflow_instance_id]);
        $this->execute(['wait_for' => 'completed', 'wait_timeout_seconds' => 0])
            ->assertStatus(422);
        $this->assertSame(0, WorkflowServiceCall::query()->count());
    }

    private function operation(string $kind, string $target, array $binding = []): void
    {
        $this->postJson('/api/service-endpoints/bindings/services/orders/operations', [
            'operation_name' => 'run', 'operation_mode' => 'async',
            'handler_binding_kind' => $kind, 'handler_target_reference' => $target, 'handler_binding' => $binding,
        ], $this->apiHeaders())->assertCreated();
    }

    private function execute(array $options): TestResponse
    {
        return $this->postJson('/api/service-endpoints/bindings/services/orders/operations/run/execute', $options, $this->apiHeaders());
    }
}
