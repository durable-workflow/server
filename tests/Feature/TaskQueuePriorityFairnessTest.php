<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\NamespaceWorkflowScope;
use App\Support\TaskQueuePriorityFairnessSurface;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\InMemoryTaskFairnessState;
use Workflow\V2\Support\TaskFairnessState;

/**
 * Contract tests for the server-side task-queue priority + fairness
 * dispatch surface.
 *
 * The server owns three pieces of the contract that the workflow package
 * cannot own on its own:
 *   1. The /api/workflows start API accepts priority + fairness fields
 *      and propagates them into the persisted workflow run.
 *   2. The /api/task-queues/{queue}/priority-fairness observability route
 *      lets an operator confirm under load that priority is honored
 *      (urgent tiers dominate dispatch counts) and fairness is applied
 *      (counts are roughly balanced subject to declared weights).
 *   3. The server's workflow + activity pollers apply the fairness
 *      reorder pass on each batch and record dispatches against a shared
 *      state store so subsequent polls keep rebalancing toward
 *      under-served classes.
 */
class TaskQueuePriorityFairnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Each test starts from a fresh fairness counter so dispatch
        // assertions are not contaminated by other tests in the suite.
        $this->app->instance(
            TaskFairnessState::class,
            new InMemoryTaskFairnessState(halfLifeSeconds: 60.0),
        );
    }

    public function test_workflow_start_api_accepts_and_persists_priority_and_fairness_fields(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-priority-fairness-start',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'priority-queue',
                'input' => ['Ada'],
                'priority' => 1,
                'fairness_key' => 'tenant-a',
                'fairness_weight' => 4,
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);

        $this->assertSame(1, $run->priority);
        $this->assertSame('tenant-a', $run->fairness_key);
        $this->assertSame(4, $run->fairness_weight);
    }

    public function test_workflow_start_api_rejects_priority_outside_supported_range(): void
    {
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-priority-out-of-range',
                'workflow_type' => 'tests.external-greeting-workflow',
                'priority' => 11,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['priority']);
    }

    public function test_workflow_start_api_rejects_fairness_key_with_disallowed_characters(): void
    {
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-fairness-key-bad-chars',
                'workflow_type' => 'tests.external-greeting-workflow',
                'fairness_key' => 'tenant a/spaces',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['fairness_key']);
    }

    public function test_priority_fairness_observability_endpoint_returns_tiered_counts_per_task_type(): void
    {
        $this->createNamespace('default');

        $run = $this->seedRun('priority-observability');

        // Two ready workflow tasks at urgent priority — one keyed,
        // one unkeyed — so the surface bucket reports both classes
        // including the implicit default class for the unkeyed row.
        $this->seedReadyTask($run, TaskType::Workflow, priority: 1, fairnessKey: 'tenant-a');
        $this->seedReadyTask($run, TaskType::Workflow, priority: 1, fairnessKey: null);

        // One activity task at default priority for a different class so
        // the workflow + activity surfaces are reported separately.
        $this->seedReadyTask($run, TaskType::Activity, priority: 5, fairnessKey: 'tenant-b');

        // Seed a recent-dispatch counter so the snapshot section is
        // non-empty, proving the surface reflects the live state store.
        /** @var TaskFairnessState $state */
        $state = $this->app->make(TaskFairnessState::class);
        $state->recordDispatch(
            TaskQueuePriorityFairnessSurface::BUCKET_WORKFLOW_TASK,
            'priority-observability-queue',
            'tenant-a',
            1,
        );

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/priority-observability-queue/priority-fairness');

        $response->assertOk();
        $response->assertJsonPath('namespace', 'default');
        $response->assertJsonPath('queue', 'priority-observability-queue');
        $response->assertJsonPath('workflow_task.ready_tasks', 2);
        $response->assertJsonPath('activity_task.ready_tasks', 1);

        $tiers = $response->json('workflow_task.priority_tiers');
        $this->assertIsArray($tiers);
        $this->assertCount(1, $tiers);
        $this->assertSame(1, $tiers[0]['priority']);
        $this->assertSame(2, $tiers[0]['count']);

        $classKeys = array_column($tiers[0]['classes'], 'fairness_key');
        $this->assertContains('tenant-a', $classKeys);
        $this->assertContains(null, $classKeys, 'Unkeyed tasks must surface as the implicit default class.');

        $recent = $response->json('workflow_task.recent_dispatch');
        $this->assertIsArray($recent);
        $tenantA = array_values(array_filter(
            $recent,
            static fn (array $entry): bool => $entry['fairness_key'] === 'tenant-a',
        ));
        $this->assertNotEmpty($tenantA);
        $this->assertGreaterThan(0.0, $tenantA[0]['score']);
    }

    public function test_priority_fairness_observability_endpoint_returns_empty_surfaces_when_no_ready_tasks(): void
    {
        $this->createNamespace('default');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/empty-queue/priority-fairness');

        $response->assertOk();
        $response->assertJsonPath('queue', 'empty-queue');
        $response->assertJsonPath('workflow_task.ready_tasks', 0);
        $response->assertJsonPath('workflow_task.priority_tiers', []);
        $response->assertJsonPath('workflow_task.recent_dispatch', []);
        $response->assertJsonPath('activity_task.ready_tasks', 0);
    }

    public function test_workflow_task_poller_reorders_batch_so_distinct_classes_interleave(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $run = $this->seedRun('fairness-interleave');

        // Five "alpha" tasks land first followed by five "beta" tasks at
        // the same priority. Without the fairness reorder pass, FIFO age
        // would let alpha drain entirely before beta sees any dispatch
        // share. With the reorder pass, the worker's poll batch should
        // alternate classes within the priority tier.
        for ($i = 0; $i < 5; $i++) {
            $this->seedReadyTask(
                $run,
                TaskType::Workflow,
                priority: 5,
                fairnessKey: 'alpha',
                secondsAgo: 10 - $i,
            );
        }

        for ($i = 0; $i < 5; $i++) {
            $this->seedReadyTask(
                $run,
                TaskType::Workflow,
                priority: 5,
                fairnessKey: 'beta',
                secondsAgo: 5 - $i,
            );
        }

        $this->registerWorker(
            workerId: 'worker-fairness-interleave',
            taskQueue: 'fairness-interleave-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $observed = [];

        for ($i = 0; $i < 4; $i++) {
            $poll = $this->withHeaders($this->workerProtocolHeaders())
                ->postJson('/api/worker/workflow-tasks/poll', [
                    'worker_id' => 'worker-fairness-interleave',
                    'task_queue' => 'fairness-interleave-queue',
                ]);

            $poll->assertOk();

            $taskId = $poll->json('task.task_id');

            if (! is_string($taskId)) {
                break;
            }

            /** @var WorkflowTask $task */
            $task = WorkflowTask::query()->findOrFail($taskId);
            $observed[] = $task->fairness_key;

            // Mark the leased task as completed so the next poll picks a
            // different ready row instead of returning the same lease.
            $task->forceFill(['status' => TaskStatus::Completed->value])->save();
        }

        $this->assertSame(
            ['alpha', 'beta', 'alpha', 'beta'],
            $observed,
            'Server poll path must apply the fairness reorder so a noisy class yields to its peers within a tier.',
        );

        // Each successful claim must be recorded against the shared
        // fairness state so subsequent polls (in this process or another)
        // see the deficit and continue rebalancing.
        /** @var TaskFairnessState $state */
        $state = $this->app->make(TaskFairnessState::class);
        $snapshot = $state->snapshot(
            TaskQueuePriorityFairnessSurface::BUCKET_WORKFLOW_TASK,
            'fairness-interleave-queue',
            ['alpha', 'beta'],
        );

        $this->assertGreaterThanOrEqual(1.5, $snapshot['alpha']);
        $this->assertGreaterThanOrEqual(1.5, $snapshot['beta']);
    }

    public function test_priority_order_is_preserved_under_the_fairness_reorder_pass(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $run = $this->seedRun('fairness-priority-order');

        // High-priority "tenant-b" comes after low-priority "tenant-a"
        // in arrival order. Priority must dominate fairness — the urgent
        // tier always leads regardless of how the keys interleave within
        // their own tier.
        $this->seedReadyTask($run, TaskType::Workflow, priority: 9, fairnessKey: 'tenant-a', secondsAgo: 10);
        $this->seedReadyTask($run, TaskType::Workflow, priority: 0, fairnessKey: 'tenant-b', secondsAgo: 1);
        $this->seedReadyTask($run, TaskType::Workflow, priority: 5, fairnessKey: 'tenant-a', secondsAgo: 5);

        $this->registerWorker(
            workerId: 'worker-priority-order',
            taskQueue: 'fairness-priority-order-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $observed = [];

        for ($i = 0; $i < 3; $i++) {
            $poll = $this->withHeaders($this->workerProtocolHeaders())
                ->postJson('/api/worker/workflow-tasks/poll', [
                    'worker_id' => 'worker-priority-order',
                    'task_queue' => 'fairness-priority-order-queue',
                ]);

            $poll->assertOk();

            $taskId = $poll->json('task.task_id');

            if (! is_string($taskId)) {
                break;
            }

            /** @var WorkflowTask $task */
            $task = WorkflowTask::query()->findOrFail($taskId);
            $observed[] = $task->priority;

            $task->forceFill(['status' => TaskStatus::Completed->value])->save();
        }

        $this->assertSame(
            [0, 5, 9],
            $observed,
            'Fairness reordering must honor priority tiers — urgent work always leads, fairness only redistributes within a tier.',
        );
    }

    private function seedRun(string $suffix): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => ExternalGreetingWorkflow::class,
            'workflow_type' => 'tests.external-greeting-workflow',
            'namespace' => 'default',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        $queue = $suffix.'-queue';

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => ExternalGreetingWorkflow::class,
            'workflow_type' => 'tests.external-greeting-workflow',
            'namespace' => 'default',
            'status' => 'running',
            'arguments' => Serializer::serialize(['Ada']),
            'connection' => 'redis',
            'queue' => $queue,
            'priority' => 5,
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->forceFill(['current_run_id' => $run->id])->save();

        NamespaceWorkflowScope::bind('default', $instance->id, 'tests.external-greeting-workflow');

        return $run;
    }

    private function seedReadyTask(
        WorkflowRun $run,
        TaskType $type,
        int $priority = 5,
        ?string $fairnessKey = null,
        int $fairnessWeight = 1,
        int $secondsAgo = 1,
    ): WorkflowTask {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => 'default',
            'task_type' => $type->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()->subSeconds(max(0, $secondsAgo)),
            'payload' => [],
            'connection' => 'redis',
            'queue' => $run->queue,
            'priority' => $priority,
            'fairness_key' => $fairnessKey,
            'fairness_weight' => $fairnessWeight,
        ]);

        return $task;
    }

    private function configureWorkflowTypes(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
    }

    private function createNamespace(string $name): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => $name],
            [
                'description' => 'Test namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );
    }

    private function apiHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    private function workerProtocolHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }

    private function registerWorker(string $workerId, string $taskQueue, array $supportedWorkflowTypes): void
    {
        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => 'default'],
            [
                'task_queue' => $taskQueue,
                'runtime' => 'php',
                'supported_workflow_types' => $supportedWorkflowTypes,
                'supported_activity_types' => [],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
    }
}
