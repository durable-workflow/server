<?php

namespace App\Support;

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
        private readonly TaskQueueAdmission $admission,
    ) {}

    /**
     * @param  list<string>  $supportedActivityTypes
     */
    public function poll(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        array $supportedActivityTypes = [],
    ): ?array {
        $limit = max(10, max(1, (int) config('server.polling.max_tasks_per_poll', 1)) * 10);
        $nextProbeAt = null;

        return $this->longPoller->until(
            function () use (
                $namespace,
                $taskQueue,
                $leaseOwner,
                $buildId,
                $supportedActivityTypes,
                $limit,
                &$nextProbeAt,
            ): ?array {
                $result = $this->nextTask(
                    $namespace,
                    $taskQueue,
                    $leaseOwner,
                    $buildId,
                    $limit,
                    $supportedActivityTypes,
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
     * @param  list<string>  $supportedActivityTypes
     */
    private function nextTask(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        int $limit,
        array $supportedActivityTypes = [],
    ): array {
        $this->applyWorkerCompatibility($namespace, $buildId);

        $task = $this->admission->withLeaseAdmission(
            $namespace,
            $taskQueue,
            TaskQueueAdmission::ACTIVITY_TASKS,
            fn (): ?array => $this->claimReadyTask($namespace, $taskQueue, $leaseOwner, $buildId, $limit, $supportedActivityTypes),
        );

        return [
            'task' => $task,
            'next_probe_at' => $task === null
                ? $this->nextVisibleReadyAt($namespace, $taskQueue, $buildId)
                : null,
        ];
    }

    /**
     * Claim the first available activity task by delegating filtering to the
     * bridge's poll query and claim validation to ActivityTaskClaimer (via
     * bridge->claimStatus). The poller no longer reimplements availability,
     * compatibility, or activity-type checks — those live in the package.
     *
     * @param  list<string>  $supportedActivityTypes
     */
    private function claimReadyTask(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        int $limit,
        array $supportedActivityTypes = [],
    ): ?array {
        $readyTasks = $this->bridge->poll(
            connection: null,
            queue: $taskQueue,
            limit: $limit,
            compatibility: $buildId,
            namespace: $namespace,
            activityTypes: $supportedActivityTypes,
        );

        foreach ($readyTasks as $readyTask) {
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
