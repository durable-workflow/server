<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;
use Workflow\V2\Contracts\LongPollWakeStore;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * Server implementation of long-poll wake signal store.
 *
 * Implements the workflow package's LongPollWakeStore contract, enabling
 * cross-node coordination when the cache backend is shared (Redis, database, etc.).
 *
 * @see LongPollWakeStore
 */
final class LongPollSignalStore implements LongPollWakeStore
{
    private const CACHE_PREFIX = 'server:long-poll-signal:';

    public function __construct(
        private readonly ServerPollingCache $cache,
    ) {}

    /**
     * @param  list<string>  $channels
     * @return array<string, string|null>
     */
    public function snapshot(array $channels): array
    {
        $snapshot = [];

        foreach ($this->normalizeChannels($channels) as $channel) {
            $snapshot[$channel] = $this->version($channel);
        }

        return $snapshot;
    }

    /**
     * @param  array<string, string|null>  $snapshot
     */
    public function changed(array $snapshot): bool
    {
        foreach ($snapshot as $channel => $version) {
            if ($this->version($channel) !== $version) {
                return true;
            }
        }

        return false;
    }

    public function signal(...$channels): void
    {
        $channels = $this->normalizeChannels($channels);

        if ($channels === []) {
            return;
        }

        $version = sprintf('%.6F:%s', microtime(true), (string) Str::ulid());

        foreach ($channels as $channel) {
            $this->store()->put(
                $this->cacheKey($channel),
                $version,
                now()->addSeconds($this->signalTtlSeconds()),
            );
        }
    }

    /**
     * @return list<string>
     */
    public function workflowTaskPollChannels(string $namespace, ?string $connection, ?string $queue): array
    {
        return $this->normalizeChannels([
            $this->queueChannel('workflow-tasks', null, $connection, $queue),
            $this->queueChannel('workflow-tasks', $namespace, $connection, $queue),
        ]);
    }

    /**
     * @return list<string>
     */
    public function activityTaskPollChannels(string $namespace, ?string $connection, ?string $queue): array
    {
        return $this->normalizeChannels([
            $this->queueChannel('activity-tasks', null, $connection, $queue),
            $this->queueChannel('activity-tasks', $namespace, $connection, $queue),
        ]);
    }

    /**
     * @return list<string>
     */
    public function queryTaskPollChannels(string $namespace, ?string $queue): array
    {
        return $this->normalizeChannels([
            $this->queueChannel('query-tasks', null, null, $queue),
            $this->queueChannel('query-tasks', $namespace, null, $queue),
        ]);
    }

    public function signalQueryTaskQueue(string $namespace, ?string $queue): void
    {
        $this->signal(
            $this->queueChannel('query-tasks', null, null, $queue),
            $this->queueChannel('query-tasks', $namespace, null, $queue),
        );
    }

    public function queryTaskResultChannel(string $queryTaskId): string
    {
        return sprintf('query-task-result:%s', $queryTaskId);
    }

    public function signalQueryTaskResult(string $queryTaskId): void
    {
        $this->signal($this->queryTaskResultChannel($queryTaskId));
    }

    public function historyRunChannel(string $runId): string
    {
        return sprintf('history:%s', $runId);
    }

    public function signalHistoryEvent(WorkflowHistoryEvent $event): void
    {
        if (! is_string($event->workflow_run_id) || $event->workflow_run_id === '') {
            return;
        }

        $this->signal($this->historyRunChannel($event->workflow_run_id));
    }

    public function signalTask(WorkflowTask $task): void
    {
        $taskType = $this->taskType($task->task_type);

        if (! $taskType instanceof TaskType) {
            return;
        }

        $namespace = $this->namespaceForTask($task);
        $channels = match ($taskType) {
            TaskType::Workflow => [
                $this->queueChannel('workflow-tasks', null, $task->connection, $task->queue),
                $this->queueChannel('workflow-tasks', null, null, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('workflow-tasks', $namespace, $task->connection, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('workflow-tasks', $namespace, null, $task->queue),
            ],
            TaskType::Activity => [
                $this->queueChannel('activity-tasks', null, $task->connection, $task->queue),
                $this->queueChannel('activity-tasks', null, null, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('activity-tasks', $namespace, $task->connection, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('activity-tasks', $namespace, null, $task->queue),
            ],
            default => [],
        };

        $this->signal(...$channels);
    }

    public function signalWorkflowTaskQueuesForWorkflow(string $workflowId, ?string $namespace = null): void
    {
        $namespace ??= $this->namespaceForWorkflow($workflowId);

        $tasks = WorkflowTask::query()
            ->select('workflow_tasks.*')
            ->join('workflow_runs', 'workflow_runs.id', '=', 'workflow_tasks.workflow_run_id')
            ->where('workflow_runs.workflow_instance_id', $workflowId)
            ->whereIn('workflow_tasks.task_type', [
                TaskType::Workflow->value,
                TaskType::Activity->value,
            ])
            ->get();

        foreach ($tasks as $task) {
            if (! $task instanceof WorkflowTask) {
                continue;
            }

            $this->signalTaskWithNamespace($task, $namespace);
        }
    }

    private function signalTaskWithNamespace(WorkflowTask $task, ?string $namespace): void
    {
        $taskType = $this->taskType($task->task_type);

        if (! $taskType instanceof TaskType) {
            return;
        }

        $channels = match ($taskType) {
            TaskType::Workflow => [
                $this->queueChannel('workflow-tasks', null, $task->connection, $task->queue),
                $this->queueChannel('workflow-tasks', null, null, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('workflow-tasks', $namespace, $task->connection, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('workflow-tasks', $namespace, null, $task->queue),
            ],
            TaskType::Activity => [
                $this->queueChannel('activity-tasks', null, $task->connection, $task->queue),
                $this->queueChannel('activity-tasks', null, null, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('activity-tasks', $namespace, $task->connection, $task->queue),
                $namespace === null
                    ? null
                    : $this->queueChannel('activity-tasks', $namespace, null, $task->queue),
            ],
            default => [],
        };

        $this->signal(...$channels);
    }

    private function namespaceForTask(WorkflowTask $task): ?string
    {
        // Tasks now carry the native namespace column — read it directly
        // instead of the previous two-step lookup (task → run → instance).
        $namespace = $task->namespace;

        if (is_string($namespace) && $namespace !== '') {
            return $namespace;
        }

        // Fallback for tasks created before the native column was populated.
        if (! is_string($task->workflow_run_id) || $task->workflow_run_id === '') {
            return null;
        }

        $workflowId = WorkflowRun::query()
            ->whereKey($task->workflow_run_id)
            ->value('workflow_instance_id');

        return is_string($workflowId) && $workflowId !== ''
            ? $this->namespaceForWorkflow($workflowId)
            : null;
    }

    private function namespaceForWorkflow(string $workflowId): ?string
    {
        $namespace = WorkflowInstance::query()
            ->whereKey($workflowId)
            ->value('namespace');

        return is_string($namespace) && $namespace !== ''
            ? $namespace
            : null;
    }

    private function version(string $channel): ?string
    {
        $version = $this->store()->get($this->cacheKey($channel));

        return is_string($version) && $version !== ''
            ? $version
            : null;
    }

    private function cacheKey(string $channel): string
    {
        return self::CACHE_PREFIX.sha1($channel);
    }

    private function store(): CacheRepository
    {
        return $this->cache->store();
    }

    private function signalTtlSeconds(): int
    {
        $configured = (int) config('server.polling.wake_signal_ttl_seconds', 0);

        if ($configured > 0) {
            return $configured;
        }

        return max((int) config('server.polling.timeout', 30) + 5, 60);
    }

    private function queueChannel(string $plane, ?string $namespace, mixed $connection, mixed $queue): string
    {
        return implode(':', array_filter([
            $plane,
            $namespace === null ? 'shared' : 'namespace',
            $namespace,
            $this->normalizeString($connection) ?? 'any-connection',
            $this->normalizeString($queue) ?? 'any-queue',
        ], static fn (mixed $segment): bool => $segment !== null && $segment !== ''));
    }

    /**
     * @param  list<string|null>  $channels
     * @return list<string>
     */
    private function normalizeChannels(array $channels): array
    {
        $normalized = [];

        foreach ($channels as $channel) {
            if (! is_string($channel)) {
                continue;
            }

            $channel = trim($channel);

            if ($channel === '') {
                continue;
            }

            $normalized[$channel] = $channel;
        }

        return array_values($normalized);
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === ''
            ? null
            : $value;
    }

    private function taskType(mixed $value): ?TaskType
    {
        if ($value instanceof TaskType) {
            return $value;
        }

        return is_string($value)
            ? TaskType::tryFrom($value)
            : null;
    }
}
