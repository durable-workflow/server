<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;

final class ServiceModeParallelChildBarrierContractTest extends TestCase
{
    use RefreshDatabase;

    private DefaultWorkflowTaskBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'workflows.v2.compatibility.current' => 'server-parallel-contract',
            'workflows.v2.compatibility.supported' => ['server-parallel-contract'],
        ]);
        Queue::fake();

        $this->bridge = $this->app->make(DefaultWorkflowTaskBridge::class);
    }

    public function test_child_only_group_releases_one_parent_task_after_reverse_order_completion(): void
    {
        [$parentRun, $childTasks] = $this->stageChildGroup([
            [$this->parallelEntry('parallel-children:1:2', 'child', 1, 2, 0)],
            [$this->parallelEntry('parallel-children:1:2', 'child', 1, 2, 1)],
        ]);

        $this->completeChild($childTasks[1], 'child-worker-two');

        $this->assertSame(0, $this->openParentWorkflowTaskCount($parentRun));

        /** @var WorkflowHistoryEvent $earlyResolution */
        $earlyResolution = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->firstOrFail();

        $this->assertSame(2, $earlyResolution->payload['sequence'] ?? null);
        $this->assertSame('parallel-children:1:2', $earlyResolution->payload['parallel_group_id'] ?? null);
        $this->assertSame(
            'parallel-children:1:2',
            $earlyResolution->payload['parallel_group_path'][0]['parallel_group_id'] ?? null,
        );

        $this->completeChild($childTasks[0], 'child-worker-one');

        $this->assertSame(1, $this->openParentWorkflowTaskCount($parentRun));

        /** @var WorkflowTask $resumeTask */
        $resumeTask = WorkflowTask::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();
        $resolvedWaits = WorkflowRunWait::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('kind', 'child')
            ->where('status', 'resolved')
            ->get();

        $this->assertCount(2, $resolvedWaits);
        $this->assertSame(
            [$resumeTask->id],
            $resolvedWaits->pluck('task_id')->unique()->values()->all(),
        );

        $duplicate = $this->bridge->complete($childTasks[1]->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize(['child' => 'duplicate']),
        ]]);

        $this->assertFalse($duplicate['completed']);
        $this->assertSame('task_not_leased', $duplicate['reason']);
        $this->assertSame(1, $this->openParentWorkflowTaskCount($parentRun));
        $this->assertSame(2, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->count());
    }

    public function test_mixed_child_and_timer_group_waits_for_both_completion_orders(): void
    {
        foreach ([true, false] as $timerFirst) {
            $parentRun = $this->createWaitingRun();
            $parentTask = $this->createLeasedTask($parentRun);
            $childEntry = $this->parallelEntry('parallel-calls:1:2', 'mixed', 1, 2, 0);
            $timerEntry = $this->parallelEntry('parallel-calls:1:2', 'mixed', 1, 2, 1);

            $result = $this->bridge->complete($parentTask->id, [
                [
                    'type' => 'start_child_workflow',
                    'workflow_type' => 'server-service-mixed-child',
                    'arguments' => Serializer::serialize(['mixed-child']),
                    ...$childEntry,
                    'parallel_group_path' => [$childEntry],
                ],
                [
                    'type' => 'start_timer',
                    'delay_seconds' => 0,
                    ...$timerEntry,
                    'parallel_group_path' => [$timerEntry],
                ],
            ]);

            $this->assertTrue($result['completed']);
            $this->assertCount(2, $result['created_task_ids']);

            /** @var WorkflowTask $childTask */
            $childTask = WorkflowTask::query()
                ->whereIn('id', $result['created_task_ids'])
                ->where('task_type', TaskType::Workflow->value)
                ->firstOrFail();
            /** @var WorkflowTask $timerTask */
            $timerTask = WorkflowTask::query()
                ->whereIn('id', $result['created_task_ids'])
                ->where('task_type', TaskType::Timer->value)
                ->firstOrFail();

            $completeChild = fn (): array => $this->completeChild(
                $childTask,
                $timerFirst ? 'mixed-child-worker-timer-first' : 'mixed-child-worker-child-first',
            );
            $fireTimer = fn (): mixed => $this->app->call([new RunTimerTask($timerTask->id), 'handle']);

            $timerFirst ? $fireTimer() : $completeChild();

            $this->assertSame(0, $this->openParentWorkflowTaskCount($parentRun));

            $timerFirst ? $completeChild() : $fireTimer();

            $this->assertSame(1, $this->openParentWorkflowTaskCount($parentRun));
        }
    }

    /**
     * @param  list<list<array<string, mixed>>>  $parallelPaths
     * @return array{0: WorkflowRun, 1: list<WorkflowTask>}
     */
    private function stageChildGroup(array $parallelPaths): array
    {
        $parentRun = $this->createWaitingRun();
        $parentTask = $this->createLeasedTask($parentRun);
        $commands = [];

        foreach ($parallelPaths as $index => $parallelPath) {
            $commands[] = [
                'type' => 'start_child_workflow',
                'workflow_type' => sprintf('server-service-child-%d', $index + 1),
                'arguments' => Serializer::serialize([sprintf('child-%d', $index + 1)]),
                ...$parallelPath[array_key_last($parallelPath)],
                'parallel_group_path' => $parallelPath,
            ];
        }

        $result = $this->bridge->complete($parentTask->id, $commands);

        $this->assertTrue($result['completed']);
        $this->assertSame(RunStatus::Waiting->value, $result['run_status']);

        $childTasks = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRun->id)
            ->where('link_type', 'child_workflow')
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowLink $link): WorkflowTask => WorkflowTask::query()
                ->where('workflow_run_id', $link->child_workflow_run_id)
                ->where('task_type', TaskType::Workflow->value)
                ->firstOrFail())
            ->values()
            ->all();

        $this->assertCount(count($parallelPaths), $childTasks);

        return [$parentRun, $childTasks];
    }

    private function completeChild(WorkflowTask $task, string $leaseOwner): array
    {
        $claim = $this->bridge->claimStatus($task->id, $leaseOwner);

        $this->assertTrue($claim['claimed']);

        $result = $this->bridge->complete($task->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize(['child' => $task->workflow_run_id]),
        ]]);

        $this->assertTrue($result['completed']);

        return $result;
    }

    /**
     * @return array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }
     */
    private function parallelEntry(string $id, string $kind, int $base, int $size, int $index): array
    {
        return [
            'parallel_group_id' => $id,
            'parallel_group_kind' => $kind,
            'parallel_group_base_sequence' => $base,
            'parallel_group_size' => $size,
            'parallel_group_index' => $index,
        ];
    }

    private function openParentWorkflowTaskCount(WorkflowRun $run): int
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count();
    }

    private function createLeasedTask(WorkflowRun $run): WorkflowTask
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'server-parallel-contract',
            'lease_owner' => 'external-parent-worker',
            'lease_expires_at' => now()->addMinutes(5),
        ]);

        return $task;
    }

    private function createWaitingRun(): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => 'Tests\\Fixtures\\ServerServiceParentWorkflow',
            'workflow_type' => 'server-service-parent-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'Tests\\Fixtures\\ServerServiceParentWorkflow',
            'workflow_type' => 'server-service-parent-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'server-parallel-contract',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->forceFill(['current_run_id' => $run->id])->save();

        return $run;
    }
}
