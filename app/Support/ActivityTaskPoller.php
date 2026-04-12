<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Workflow\V2\Contracts\ActivityTaskBridge as ActivityTaskBridgeContract;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;

final class ActivityTaskPoller
{
    public function __construct(
        private readonly LongPoller $longPoller,
        private readonly ActivityTaskBridgeContract $bridge,
        private readonly LongPollSignalStore $signals,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function poll(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
    ): ?array {
        $limit = max(10, max(1, (int) config('server.polling.max_tasks_per_poll', 1)) * 10);
        $nextProbeAt = null;

        return $this->longPoller->until(
            function () use (
                $namespace,
                $taskQueue,
                $leaseOwner,
                $buildId,
                $limit,
                &$nextProbeAt,
            ): ?array {
                $result = $this->nextTask(
                    $namespace,
                    $taskQueue,
                    $leaseOwner,
                    $buildId,
                    $limit,
                );
                $nextProbeAt = $result['next_probe_at'] ?? null;

                return $result['task'] ?? null;
            },
            static fn (?array $task): bool => is_array($task),
            wakeChannels: $this->signals->activityTaskPollChannels($namespace, null, $taskQueue),
            nextProbeAt: function () use (&$nextProbeAt): mixed {
                return $nextProbeAt;
            },
        );
    }

    /**
     * @return array{task: array<string, mixed>|null, next_probe_at: \DateTimeInterface|null}
     */
    private function nextTask(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        int $limit,
    ): array {
        $this->applyWorkerCompatibility($namespace, $buildId);

        $task = $this->claimReadyTask($namespace, $taskQueue, $leaseOwner, $buildId, $limit);

        return [
            'task' => $task,
            'next_probe_at' => $task === null
                ? $this->nextVisibleReadyAt($namespace, $taskQueue, $buildId)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claimReadyTask(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        int $limit,
    ): ?array {
        $readyTasks = $this->bridge->poll(
            connection: null,
            queue: $taskQueue,
            limit: $limit,
            compatibility: $buildId,
        );

        foreach ($readyTasks as $readyTask) {
            if ($this->availableAtIsFuture($readyTask['available_at'] ?? null)) {
                continue;
            }

            $workflowId = is_string($readyTask['workflow_instance_id'] ?? null)
                ? $readyTask['workflow_instance_id']
                : null;

            if ($workflowId === null || ! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
                continue;
            }

            if (! $this->matchesCompatibility($buildId, $readyTask['compatibility'] ?? null)) {
                continue;
            }

            $taskId = is_string($readyTask['task_id'] ?? null)
                ? $readyTask['task_id']
                : null;

            if ($taskId === null) {
                continue;
            }

            $claim = $this->bridge->claimStatus($taskId, $leaseOwner);

            if (($claim['claimed'] ?? false) === true) {
                return $claim;
            }
        }

        return null;
    }

    private function applyWorkerCompatibility(string $namespace, ?string $buildId): void
    {
        config([
            'workflows.v2.compatibility.namespace' => $namespace,
            'workflows.v2.compatibility.current' => $buildId,
            'workflows.v2.compatibility.supported' => $buildId === null ? [] : [$buildId],
        ]);
    }

    private function availableAtIsFuture(mixed $availableAt): bool
    {
        if ($availableAt instanceof \DateTimeInterface) {
            return $availableAt > now();
        }

        if (! is_string($availableAt) || trim($availableAt) === '') {
            return false;
        }

        try {
            return now()->lt(Carbon::parse($availableAt));
        } catch (\Throwable) {
            return false;
        }
    }

    private function matchesCompatibility(?string $buildId, mixed $compatibility): bool
    {
        if (! is_string($compatibility) || trim($compatibility) === '') {
            return true;
        }

        return $buildId !== null && $compatibility === $buildId;
    }

    private function nextVisibleReadyAt(string $namespace, string $taskQueue, ?string $buildId): ?\DateTimeInterface
    {
        $query = NamespaceWorkflowScope::taskQuery($namespace)
            ->where('workflow_tasks.task_type', TaskType::Activity->value)
            ->where('workflow_tasks.status', TaskStatus::Ready->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->whereNotNull('workflow_tasks.available_at')
            ->where('workflow_tasks.available_at', '>', now())
            ->orderBy('workflow_tasks.available_at')
            ->orderBy('workflow_tasks.id');

        if ($buildId === null) {
            $query->where(function ($builder): void {
                $builder->whereNull('workflow_tasks.compatibility')
                    ->orWhere('workflow_tasks.compatibility', '');
            });
        } else {
            $query->where('workflow_tasks.compatibility', $buildId);
        }

        /** @var WorkflowTask|null $task */
        $task = $query->first();

        return $task?->available_at;
    }
}
