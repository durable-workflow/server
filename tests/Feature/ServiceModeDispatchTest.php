<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ServiceModeBusDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;

class ServiceModeDispatchTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'file']);
    }

    public function test_service_mode_registers_the_decorator(): void
    {
        $dispatcher = app(BusDispatcher::class);

        $this->assertInstanceOf(ServiceModeBusDispatcher::class, $dispatcher);
    }

    public function test_starting_a_workflow_creates_ready_task_without_queue_dispatch(): void
    {
        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
        $this->createNamespace('default');

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-service-mode-test',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'default',
                'input' => ['Hello'],
            ]);

        $response->assertCreated();

        $runId = $response->json('run_id');
        $this->assertNotNull($runId);

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->first();

        $this->assertNotNull($task, 'Workflow task row should exist');
        $this->assertEquals(TaskStatus::Ready, $task->status, 'Task should remain Ready (not consumed by queue worker)');
    }

    public function test_ready_task_is_available_for_external_worker_polling(): void
    {
        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
        $this->createNamespace('default');
        $this->registerWorker('worker-1', 'default');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-poll-test',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'default',
                'input' => ['World'],
            ]);

        $start->assertCreated();

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-1',
                'task_queue' => 'default',
                'lease_owner' => 'worker-1',
            ]);

        $poll->assertOk();
        $this->assertNotNull($poll->json('task.task_id'), 'External worker should receive the task');
        $this->assertEquals('wf-poll-test', $poll->json('task.workflow_id'));
    }
}
