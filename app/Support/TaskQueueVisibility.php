<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\TaskRepairPolicy;

final class TaskQueueVisibility
{
    private const CURRENT_LEASE_LIMIT = 50;

    /**
     * @return array<string, mixed>
     */
    public function describe(string $namespace, string $taskQueue): array
    {
        $now = now();
        $pollers = $this->pollers($namespace, $taskQueue, $now);

        return [
            'name' => $taskQueue,
            'pollers' => $pollers,
            'stats' => $this->stats($namespace, $taskQueue, $pollers, $now),
            'current_leases' => $this->currentLeases($namespace, $taskQueue, $now),
            'repair' => $this->repairStats($namespace, $taskQueue, $now),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pollers(string $namespace, string $taskQueue, Carbon $now): array
    {
        $workers = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->orderByDesc('last_heartbeat_at')
            ->orderBy('worker_id')
            ->get();

        $pollers = $workers->map(function (WorkerRegistration $worker) use ($now): array {
            $heartbeatDeadline = $worker->last_heartbeat_at?->copy()
                ->addSeconds($this->staleAfterSeconds());
            $isStale = $heartbeatDeadline === null || $heartbeatDeadline->lte($now);

            return [
                'worker_id' => $worker->worker_id,
                'runtime' => $worker->runtime,
                'sdk_version' => $worker->sdk_version,
                'build_id' => $worker->build_id,
                'last_heartbeat_at' => $worker->last_heartbeat_at?->toJSON(),
                'heartbeat_expires_at' => $heartbeatDeadline?->toJSON(),
                'status' => $isStale
                    ? 'stale'
                    : (is_string($worker->status) && $worker->status !== '' ? $worker->status : 'active'),
                'is_stale' => $isStale,
                'supported_workflow_types' => is_array($worker->supported_workflow_types)
                    ? array_values($worker->supported_workflow_types)
                    : [],
                'supported_activity_types' => is_array($worker->supported_activity_types)
                    ? array_values($worker->supported_activity_types)
                    : [],
                'max_concurrent_workflow_tasks' => (int) $worker->max_concurrent_workflow_tasks,
                'max_concurrent_activity_tasks' => (int) $worker->max_concurrent_activity_tasks,
            ];
        })->all();

        usort($pollers, static function (array $left, array $right): int {
            if (($left['is_stale'] ?? false) !== ($right['is_stale'] ?? false)) {
                return ($left['is_stale'] ?? false) <=> ($right['is_stale'] ?? false);
            }

            return strcmp((string) ($left['worker_id'] ?? ''), (string) ($right['worker_id'] ?? ''));
        });

        return $pollers;
    }

    /**
     * @param  list<array<string, mixed>>  $pollers
     * @return array<string, mixed>
     */
    private function stats(string $namespace, string $taskQueue, array $pollers, Carbon $now): array
    {
        $readyCounts = $this->groupedTaskCounts(
            $this->readyTaskQuery($namespace, $taskQueue, $now)
        );
        $leasedCounts = $this->groupedTaskCounts(
            $this->baseTaskQuery($namespace, $taskQueue)
                ->where('workflow_tasks.status', TaskStatus::Leased->value)
        );
        $expiredLeaseCounts = $this->groupedTaskCounts(
            $this->baseTaskQuery($namespace, $taskQueue)
                ->where('workflow_tasks.status', TaskStatus::Leased->value)
                ->whereNotNull('workflow_tasks.lease_expires_at')
                ->where('workflow_tasks.lease_expires_at', '<=', $now)
        );

        /** @var WorkflowTask|null $oldestReadyTask */
        $oldestReadyTask = $this->readyTaskQuery($namespace, $taskQueue, $now)
            ->select('workflow_tasks.*', 'workflow_runs.workflow_instance_id')
            ->orderByRaw('workflow_tasks.available_at is null desc')
            ->orderBy('workflow_tasks.available_at')
            ->orderBy('workflow_tasks.created_at')
            ->orderBy('workflow_tasks.id')
            ->first();

        $readySince = $oldestReadyTask?->available_at ?? $oldestReadyTask?->created_at;
        $backlogAgeSeconds = $readySince instanceof Carbon
            ? max(0, (int) floor($readySince->diffInSeconds($now)))
            : null;
        $activePollers = count(array_filter($pollers, static fn (array $poller): bool => ($poller['is_stale'] ?? false) !== true));
        $stalePollers = count($pollers) - $activePollers;

        return [
            'approximate_backlog_count' => $readyCounts[TaskType::Workflow->value] + $readyCounts[TaskType::Activity->value],
            'approximate_backlog_age' => $this->ageLabel($backlogAgeSeconds),
            'approximate_backlog_age_seconds' => $backlogAgeSeconds,
            'oldest_ready_task' => $oldestReadyTask instanceof WorkflowTask
                ? [
                    'task_id' => $oldestReadyTask->id,
                    'task_type' => $oldestReadyTask->task_type?->value ?? $oldestReadyTask->task_type,
                    'workflow_id' => $oldestReadyTask->workflow_instance_id,
                    'run_id' => $oldestReadyTask->workflow_run_id,
                    'available_at' => ($oldestReadyTask->available_at ?? $oldestReadyTask->created_at)?->toJSON(),
                    'age_seconds' => $backlogAgeSeconds,
                ]
                : null,
            'workflow_tasks' => [
                'ready_count' => $readyCounts[TaskType::Workflow->value],
                'leased_count' => $leasedCounts[TaskType::Workflow->value],
                'expired_lease_count' => $expiredLeaseCounts[TaskType::Workflow->value],
            ],
            'activity_tasks' => [
                'ready_count' => $readyCounts[TaskType::Activity->value],
                'leased_count' => $leasedCounts[TaskType::Activity->value],
                'expired_lease_count' => $expiredLeaseCounts[TaskType::Activity->value],
            ],
            'pollers' => [
                'active_count' => $activePollers,
                'stale_count' => $stalePollers,
                'stale_after_seconds' => $this->staleAfterSeconds(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function currentLeases(string $namespace, string $taskQueue, Carbon $now): array
    {
        $workflowLeases = $this->baseTaskQuery($namespace, $taskQueue)
            ->where('workflow_tasks.task_type', TaskType::Workflow->value)
            ->where('workflow_tasks.status', TaskStatus::Leased->value)
            ->select([
                'workflow_tasks.id as task_id',
                'workflow_tasks.workflow_run_id',
                'workflow_runs.workflow_instance_id',
                'workflow_tasks.lease_owner',
                'workflow_tasks.lease_expires_at',
                'workflow_tasks.attempt_count',
            ])
            ->orderBy('workflow_tasks.lease_expires_at')
            ->orderBy('workflow_tasks.id')
            ->limit(self::CURRENT_LEASE_LIMIT)
            ->get()
            ->map(function ($task) use ($now): array {
                $leaseExpiresAt = $task->lease_expires_at instanceof Carbon
                    ? $task->lease_expires_at
                    : ($task->lease_expires_at ? Carbon::parse($task->lease_expires_at) : null);

                return [
                    'task_id' => $task->task_id,
                    'task_type' => TaskType::Workflow->value,
                    'workflow_id' => $task->workflow_instance_id,
                    'run_id' => $task->workflow_run_id,
                    'lease_owner' => $task->lease_owner,
                    'lease_expires_at' => $leaseExpiresAt?->toJSON(),
                    'is_expired' => $leaseExpiresAt?->lte($now) ?? false,
                    'workflow_task_attempt' => is_int($task->attempt_count)
                        ? (int) $task->attempt_count
                        : null,
                ];
            });

        $activityLeases = $this->baseTaskQuery($namespace, $taskQueue)
            ->leftJoin('activity_attempts', function ($join): void {
                $join->on('activity_attempts.workflow_task_id', '=', 'workflow_tasks.id')
                    ->where('activity_attempts.status', '=', ActivityAttemptStatus::Running->value);
            })
            ->where('workflow_tasks.task_type', TaskType::Activity->value)
            ->where('workflow_tasks.status', TaskStatus::Leased->value)
            ->select([
                'workflow_tasks.id as task_id',
                'workflow_tasks.workflow_run_id',
                'workflow_runs.workflow_instance_id',
                'workflow_tasks.lease_owner',
                'workflow_tasks.lease_expires_at',
                'activity_attempts.id as activity_attempt_id',
                'activity_attempts.attempt_number',
            ])
            ->orderBy('workflow_tasks.lease_expires_at')
            ->orderBy('workflow_tasks.id')
            ->limit(self::CURRENT_LEASE_LIMIT)
            ->get()
            ->map(function ($task) use ($now): array {
                $leaseExpiresAt = $task->lease_expires_at instanceof Carbon
                    ? $task->lease_expires_at
                    : ($task->lease_expires_at ? Carbon::parse($task->lease_expires_at) : null);

                return [
                    'task_id' => $task->task_id,
                    'task_type' => TaskType::Activity->value,
                    'workflow_id' => $task->workflow_instance_id,
                    'run_id' => $task->workflow_run_id,
                    'lease_owner' => $task->lease_owner,
                    'lease_expires_at' => $leaseExpiresAt?->toJSON(),
                    'is_expired' => $leaseExpiresAt?->lte($now) ?? false,
                    'activity_attempt_id' => $task->activity_attempt_id,
                    'attempt_number' => is_numeric($task->attempt_number) ? (int) $task->attempt_number : null,
                ];
            });

        $leases = $workflowLeases
            ->concat($activityLeases)
            ->all();

        usort($leases, static function (array $left, array $right): int {
            if (($left['is_expired'] ?? false) !== ($right['is_expired'] ?? false)) {
                return ($left['is_expired'] ?? false) === true ? -1 : 1;
            }

            return strcmp((string) ($left['task_id'] ?? ''), (string) ($right['task_id'] ?? ''));
        });

        return array_slice($leases, 0, self::CURRENT_LEASE_LIMIT);
    }

    /**
     * @return array<string, mixed>
     */
    private function repairStats(string $namespace, string $taskQueue, Carbon $now): array
    {
        $redispatchCutoff = $now->copy()
            ->subSeconds(TaskRepairPolicy::redispatchAfterSeconds());

        $dispatchFailedCount = $this->baseTaskQuery($namespace, $taskQueue)
            ->where('workflow_tasks.status', TaskStatus::Ready->value)
            ->whereNotNull('workflow_tasks.last_dispatch_attempt_at')
            ->whereNotNull('workflow_tasks.last_dispatch_error')
            ->where('workflow_tasks.last_dispatch_error', '!=', '')
            ->where(static function ($query): void {
                $query->whereNull('workflow_tasks.last_dispatched_at')
                    ->orWhereColumn('workflow_tasks.last_dispatch_attempt_at', '>', 'workflow_tasks.last_dispatched_at');
            })
            ->count();

        $expiredLeaseCount = $this->baseTaskQuery($namespace, $taskQueue)
            ->where('workflow_tasks.status', TaskStatus::Leased->value)
            ->whereNotNull('workflow_tasks.lease_expires_at')
            ->where('workflow_tasks.lease_expires_at', '<=', $now)
            ->count();

        $dispatchOverdueCount = $this->baseTaskQuery($namespace, $taskQueue)
            ->where('workflow_tasks.status', TaskStatus::Ready->value)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('workflow_tasks.available_at')
                    ->orWhere('workflow_tasks.available_at', '<=', $now);
            })
            ->where(static function ($query) use ($redispatchCutoff): void {
                $query->where(static function ($dispatched) use ($redispatchCutoff): void {
                    $dispatched->whereNotNull('workflow_tasks.last_dispatched_at')
                        ->where('workflow_tasks.last_dispatched_at', '<=', $redispatchCutoff);
                })->orWhere(static function ($neverDispatched) use ($redispatchCutoff): void {
                    $neverDispatched->whereNull('workflow_tasks.last_dispatched_at')
                        ->where('workflow_tasks.created_at', '<=', $redispatchCutoff);
                });
            })
            // Exclude tasks already counted as dispatch_failed
            ->where(static function ($query): void {
                $query->whereNull('workflow_tasks.last_dispatch_error')
                    ->orWhere('workflow_tasks.last_dispatch_error', '');
            })
            ->count();

        $totalCandidates = $dispatchFailedCount + $expiredLeaseCount + $dispatchOverdueCount;

        return [
            'candidates' => $totalCandidates,
            'dispatch_failed' => $dispatchFailedCount,
            'expired_leases' => $expiredLeaseCount,
            'dispatch_overdue' => $dispatchOverdueCount,
            'needs_attention' => $totalCandidates > 0,
            'policy' => [
                'redispatch_after_seconds' => TaskRepairPolicy::redispatchAfterSeconds(),
            ],
        ];
    }

    private function baseTaskQuery(string $namespace, string $taskQueue): Builder
    {
        return NamespaceWorkflowScope::taskQuery($namespace)
            ->whereIn('workflow_tasks.task_type', [
                TaskType::Workflow->value,
                TaskType::Activity->value,
            ])
            ->where('workflow_tasks.queue', $taskQueue);
    }

    private function readyTaskQuery(string $namespace, string $taskQueue, Carbon $now): Builder
    {
        $nowTimestamp = $this->databaseTimestamp($now);

        return $this->baseTaskQuery($namespace, $taskQueue)
            ->where('workflow_tasks.status', TaskStatus::Ready->value)
            ->where(function ($query) use ($nowTimestamp): void {
                $query->whereNull('workflow_tasks.available_at')
                    ->orWhere('workflow_tasks.available_at', '<=', $nowTimestamp);
            });
    }

    /**
     * @return array{workflow: int, activity: int}
     */
    private function groupedTaskCounts(Builder $query): array
    {
        $counts = [
            TaskType::Workflow->value => 0,
            TaskType::Activity->value => 0,
        ];

        foreach (
            $query->select('workflow_tasks.task_type')
                ->selectRaw('COUNT(*) as aggregate')
                ->groupBy('workflow_tasks.task_type')
                ->get() as $row
        ) {
            $taskType = $row->task_type instanceof TaskType
                ? $row->task_type->value
                : (is_string($row->task_type) ? $row->task_type : null);

            if ($taskType === null || ! array_key_exists($taskType, $counts)) {
                continue;
            }

            $counts[$taskType] = (int) $row->aggregate;
        }

        return $counts;
    }

    private function ageLabel(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        if ($seconds < 60) {
            return sprintf('%ds', $seconds);
        }

        if ($seconds < 3600) {
            return sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60);
        }

        return sprintf(
            '%dh%02dm%02ds',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60,
        );
    }

    private function staleAfterSeconds(): int
    {
        return max(
            1,
            (int) config(
                'server.workers.stale_after_seconds',
                max((int) config('server.polling.timeout', 30) * 2, 60),
            ),
        );
    }

    private function databaseTimestamp(Carbon $value): string
    {
        return $value->copy()->utc()->format('Y-m-d H:i:s.u');
    }
}
