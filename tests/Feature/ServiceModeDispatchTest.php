<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\ServerPollingCache;
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

    public function test_request_id_polling_and_completion_use_the_durable_database_while_redis_is_unavailable(): void
    {
        config([
            'cache.default' => 'redis',
            'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'missing-polling-connection'],
        ]);
        app('cache')->forgetDriver('redis');
        app()->forgetInstance(ServerPollingCache::class);

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
        $this->createNamespace('default');
        $this->registerWorker('redis-loss-worker', 'default');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-redis-loss-poll',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'default',
                'input' => ['Durable'],
            ])
            ->assertCreated();

        $pollPayload = [
            'worker_id' => 'redis-loss-worker',
            'task_queue' => 'default',
            'lease_owner' => 'redis-loss-worker',
            'poll_request_id' => 'redis-loss-idempotent-poll',
        ];
        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', $pollPayload)
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-redis-loss-poll');

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $replayed = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', $pollPayload)
            ->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.workflow_task_attempt', $attempt);

        $this->assertSame(1, WorkflowTask::query()->whereKey($taskId)->where('attempt_count', 1)->count());
        $this->assertSame($poll->json('task.lease_owner'), $replayed->json('task.lease_owner'));

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'redis-loss-worker',
                'workflow_task_attempt' => $attempt,
                'commands' => [['type' => 'complete_workflow', 'result' => null]],
            ])
            ->assertOk()
            ->assertJsonPath('recorded', true);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-redis-loss-poll/runs/'.$start->json('run_id'))
            ->assertOk()
            ->assertJsonPath('status', 'completed');
    }

    public function test_build_worker_replays_its_unversioned_workflow_lease_while_redis_is_unavailable(): void
    {
        config([
            'cache.default' => 'redis',
            'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'missing-polling-connection'],
        ]);
        app('cache')->forgetDriver('redis');
        app()->forgetInstance(ServerPollingCache::class);

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
        $this->createNamespace('default');

        foreach (['first', 'second'] as $workflow) {
            $this->withHeaders($this->apiHeaders())
                ->postJson('/api/workflows', [
                    'workflow_id' => "wf-redis-loss-unversioned-{$workflow}",
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'task_queue' => 'default',
                    'input' => ['Durable'],
                ])
                ->assertCreated();
        }

        $this->assertSame(2, WorkflowTask::query()
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->whereNull('compatibility')
            ->count());

        $this->registerWorker('redis-loss-build-worker', 'default');
        WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'redis-loss-build-worker')
            ->update(['build_id' => 'build-a']);

        $pollPayload = [
            'worker_id' => 'redis-loss-build-worker',
            'task_queue' => 'default',
            'poll_request_id' => 'redis-loss-build-poll',
        ];

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', $pollPayload)
            ->assertOk()
            ->assertJsonPath('poll_status', 'leased');

        $firstTaskId = (string) $firstPoll->json('task.task_id');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', $pollPayload)
            ->assertOk()
            ->assertJsonPath('task.task_id', $firstTaskId)
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $this->assertSame(1, WorkflowTask::query()
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Leased->value)
            ->where('lease_owner', 'redis-loss-build-worker')
            ->whereNull('compatibility')
            ->count());
        $this->assertSame(1, WorkflowTask::query()
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->whereNull('compatibility')
            ->count());
    }

    public function test_idless_workflow_poll_does_not_replay_an_active_lease_while_redis_is_unavailable(): void
    {
        config([
            'cache.default' => 'redis',
            'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'missing-polling-connection'],
        ]);
        app('cache')->forgetDriver('redis');
        app()->forgetInstance(ServerPollingCache::class);

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
        $this->createNamespace('default');
        $this->registerWorker('redis-loss-idless-worker', 'default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-redis-loss-idless-poll',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'default',
                'input' => ['Durable'],
            ])
            ->assertCreated();

        $firstSlot = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'redis-loss-idless-worker',
                'task_queue' => 'default',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-redis-loss-idless-poll');

        $taskId = (string) $firstSlot->json('task.task_id');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'redis-loss-idless-worker',
                'task_queue' => 'default',
                'timeout_seconds' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');

        $this->assertSame(1, WorkflowTask::query()->whereKey($taskId)->where('attempt_count', 1)->count());
    }
}
