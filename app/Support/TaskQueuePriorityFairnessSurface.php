<?php

namespace App\Support;

use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\TaskFairnessKey;
use Workflow\V2\Support\TaskFairnessState;
use Workflow\V2\Support\TaskPriority;

/**
 * Builds the operator observability surface for the task-queue priority
 * + fairness dispatch contract. Workflow-task and activity-task buckets
 * are reported separately because the dispatch path keeps them isolated
 * — a noisy workflow class must not borrow against an activity class
 * fairness budget on the same queue.
 *
 * The surface mirrors the workflow package's `Webhooks` snapshot but is
 * scoped to the server's namespace-bound task tables and exposed through
 * the control-plane API, so production operators can confirm priority is
 * honored and fairness is applied without having to talk directly to the
 * package's webhook surface.
 */
final class TaskQueuePriorityFairnessSurface
{
    public const BUCKET_WORKFLOW_TASK = 'workflow_task';

    public const BUCKET_ACTIVITY_TASK = 'activity_task';

    public function __construct(
        private readonly TaskFairnessState $state,
    ) {}

    /**
     * @return array{
     *     namespace: string,
     *     queue: string,
     *     workflow_task: array<string, mixed>,
     *     activity_task: array<string, mixed>,
     * }
     */
    public function snapshot(string $namespace, string $queue): array
    {
        return [
            'namespace' => $namespace,
            'queue' => $queue,
            'workflow_task' => $this->surfaceFor(
                $namespace,
                $queue,
                TaskType::Workflow->value,
                self::BUCKET_WORKFLOW_TASK,
            ),
            'activity_task' => $this->surfaceFor(
                $namespace,
                $queue,
                TaskType::Activity->value,
                self::BUCKET_ACTIVITY_TASK,
            ),
        ];
    }

    /**
     * @return array{
     *     ready_tasks: int,
     *     priority_tiers: list<array{
     *         priority: int,
     *         count: int,
     *         classes: list<array{fairness_key: ?string, count: int, fairness_weight: int}>,
     *     }>,
     *     recent_dispatch: list<array{fairness_key: ?string, score: float}>,
     * }
     */
    private function surfaceFor(
        string $namespace,
        string $queue,
        string $taskType,
        string $bucket,
    ): array {
        $rows = WorkflowTask::query()
            ->where('namespace', $namespace)
            ->where('queue', $queue)
            ->where('task_type', $taskType)
            ->where('status', TaskStatus::Ready->value)
            ->get(['priority', 'fairness_key', 'fairness_weight']);

        /** @var array<int, array<string, array{fairness_key: ?string, count: int, fairness_weight: int}>> $byPriority */
        $byPriority = [];
        $classKeys = [];
        $total = 0;

        foreach ($rows as $row) {
            $priority = is_int($row->priority) ? $row->priority : TaskPriority::DEFAULT;
            $fairnessKey = is_string($row->fairness_key) && $row->fairness_key !== ''
                ? $row->fairness_key
                : null;
            $weight = is_int($row->fairness_weight) && $row->fairness_weight >= 1
                ? $row->fairness_weight
                : 1;

            $classKey = TaskFairnessKey::classFor($fairnessKey);
            $classKeys[$classKey] = true;
            $byPriority[$priority] ??= [];
            $byPriority[$priority][$classKey] ??= [
                'fairness_key' => $fairnessKey,
                'count' => 0,
                'fairness_weight' => $weight,
            ];
            $byPriority[$priority][$classKey]['count']++;
            $total++;
        }

        ksort($byPriority);

        $tiers = [];
        foreach ($byPriority as $priority => $classes) {
            ksort($classes);
            $tierCount = 0;
            foreach ($classes as $class) {
                $tierCount += $class['count'];
            }

            $tiers[] = [
                'priority' => $priority,
                'count' => $tierCount,
                'classes' => array_values($classes),
            ];
        }

        $recent = [];

        if ($classKeys !== []) {
            $snapshot = $this->state->snapshot($bucket, $queue, array_keys($classKeys));

            foreach ($snapshot as $class => $score) {
                $recent[] = [
                    'fairness_key' => $class === TaskFairnessKey::DEFAULT_CLASS ? null : $class,
                    'score' => (float) $score,
                ];
            }
        }

        return [
            'ready_tasks' => $total,
            'priority_tiers' => $tiers,
            'recent_dispatch' => $recent,
        ];
    }
}
