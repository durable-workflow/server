<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;

final class TaskQueueAdmission
{
    public const WORKFLOW_TASKS = 'workflow_tasks';

    public const ACTIVITY_TASKS = 'activity_tasks';

    public function __construct(
        private readonly ServerPollingCache $cache,
    ) {}

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn|null
     */
    public function withLeaseAdmission(string $namespace, string $taskQueue, string $taskKind, Closure $callback): mixed
    {
        $budget = $this->budget($namespace, $taskQueue, $taskKind);

        if ($budget['lock_required'] !== true) {
            return $callback();
        }

        if ($budget['lock_supported'] !== true) {
            Log::warning('Task queue admission is configured but the polling cache store does not support locks.', [
                'namespace' => $namespace,
                'task_queue' => $taskQueue,
                'task_kind' => $taskKind,
            ]);

            return null;
        }

        try {
            return $this->cache->store()
                ->getStore()
                ->lock($this->lockKey($namespace, $taskQueue, $taskKind), $this->lockTtlSeconds())
                ->block($this->lockWaitSeconds(), function () use ($namespace, $taskQueue, $taskKind, $callback) {
                    $fresh = $this->budget($namespace, $taskQueue, $taskKind);

                    if (
                        $fresh['max_active_leases_per_queue'] !== null
                        && $fresh['active_lease_count'] >= $fresh['max_active_leases_per_queue']
                    ) {
                        return null;
                    }

                    if (
                        $fresh['max_dispatches_per_minute'] !== null
                        && $fresh['dispatch_count_this_minute'] >= $fresh['max_dispatches_per_minute']
                    ) {
                        return null;
                    }

                    $result = $callback();

                    if ($result !== null) {
                        $this->recordDispatch($namespace, $taskQueue, $taskKind);
                    }

                    return $result;
                });
        } catch (LockTimeoutException) {
            Log::warning('Task queue admission lock timed out.', [
                'namespace' => $namespace,
                'task_queue' => $taskQueue,
                'task_kind' => $taskKind,
            ]);

            return null;
        }
    }

    /**
     * @return array{
     *     budget_source: string,
     *     max_active_leases_per_queue: int|null,
     *     active_lease_count: int,
     *     remaining_active_lease_capacity: int|null,
     *     max_dispatches_per_minute: int|null,
     *     dispatch_count_this_minute: int,
     *     remaining_dispatch_capacity: int|null,
     *     lock_required: bool,
     *     lock_supported: bool,
     *     status: string
     * }
     */
    public function budget(string $namespace, string $taskQueue, string $taskKind): array
    {
        $limit = $this->maxActiveLeasesPerQueue($namespace, $taskQueue, $taskKind);
        $dispatchLimit = $this->maxDispatchesPerMinute($namespace, $taskQueue, $taskKind);
        $activeLeases = $this->activeLeaseCount($namespace, $taskQueue, $taskKind);
        $dispatchCount = $this->dispatchCountThisMinute($namespace, $taskQueue, $taskKind);
        $lockSupported = $this->cache->store()->getStore() instanceof LockProvider;

        return [
            'budget_source' => $this->budgetSource($limit, $dispatchLimit),
            'max_active_leases_per_queue' => $limit['value'],
            'active_lease_count' => $activeLeases,
            'remaining_active_lease_capacity' => $limit['value'] === null
                ? null
                : max(0, $limit['value'] - $activeLeases),
            'max_dispatches_per_minute' => $dispatchLimit['value'],
            'dispatch_count_this_minute' => $dispatchCount,
            'remaining_dispatch_capacity' => $dispatchLimit['value'] === null
                ? null
                : max(0, $dispatchLimit['value'] - $dispatchCount),
            'lock_required' => $limit['value'] !== null || $dispatchLimit['value'] !== null,
            'lock_supported' => $lockSupported,
            'status' => $this->status($limit['value'], $activeLeases, $dispatchLimit['value'], $dispatchCount, $lockSupported),
        ];
    }

    /**
     * @return array{value: int|null, source: string}
     */
    private function maxActiveLeasesPerQueue(string $namespace, string $taskQueue, string $taskKind): array
    {
        $override = $this->queueOverride($namespace, $taskQueue, $taskKind);

        if ($override !== null) {
            return [
                'value' => $this->positiveIntOrNull($override),
                'source' => 'server.admission.queue_overrides',
            ];
        }

        return [
            'value' => $this->positiveIntOrNull(config("server.admission.{$taskKind}.max_active_leases_per_queue")),
            'source' => "server.admission.{$taskKind}.max_active_leases_per_queue",
        ];
    }

    /**
     * @return array{value: int|null, source: string}
     */
    private function maxDispatchesPerMinute(string $namespace, string $taskQueue, string $taskKind): array
    {
        $override = $this->queueOverride($namespace, $taskQueue, $taskKind, 'max_dispatches_per_minute');

        if ($override !== null) {
            return [
                'value' => $this->positiveIntOrNull($override),
                'source' => 'server.admission.queue_overrides',
            ];
        }

        return [
            'value' => $this->positiveIntOrNull(config("server.admission.{$taskKind}.max_dispatches_per_minute")),
            'source' => "server.admission.{$taskKind}.max_dispatches_per_minute",
        ];
    }

    private function queueOverride(string $namespace, string $taskQueue, string $taskKind, string $field = 'max_active_leases'): mixed
    {
        $overrides = config('server.admission.queue_overrides', []);

        if (! is_array($overrides)) {
            return null;
        }

        foreach ([$namespace.':'.$taskQueue, $taskQueue, '*'] as $key) {
            if (! is_array($overrides[$key] ?? null)) {
                continue;
            }

            $kind = $overrides[$key][$taskKind] ?? null;

            if (is_array($kind) && array_key_exists($field, $kind)) {
                return $kind[$field];
            }

            if ($field === 'max_active_leases' && is_array($kind) && array_key_exists('max_active_leases', $kind)) {
                return $kind['max_active_leases'];
            }

            if ($field === 'max_active_leases' && is_array($kind) && array_key_exists('max_active_leases_per_queue', $kind)) {
                return $kind['max_active_leases_per_queue'];
            }
        }

        return null;
    }

    private function dispatchCountThisMinute(string $namespace, string $taskQueue, string $taskKind): int
    {
        return max(0, (int) $this->cache->store()->get($this->dispatchCounterKey($namespace, $taskQueue, $taskKind), 0));
    }

    private function activeLeaseCount(string $namespace, string $taskQueue, string $taskKind): int
    {
        return NamespaceWorkflowScope::taskQuery($namespace)
            ->where('workflow_tasks.task_type', $this->taskType($taskKind)->value)
            ->where('workflow_tasks.status', TaskStatus::Leased->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->where(function ($builder): void {
                $builder->whereNull('workflow_tasks.lease_expires_at')
                    ->orWhere('workflow_tasks.lease_expires_at', '>', now());
            })
            ->count();
    }

    private function taskType(string $taskKind): TaskType
    {
        return $taskKind === self::ACTIVITY_TASKS
            ? TaskType::Activity
            : TaskType::Workflow;
    }

    private function status(?int $activeLimit, int $activeLeases, ?int $dispatchLimit, int $dispatchCount, bool $lockSupported): string
    {
        if ($activeLimit === null && $dispatchLimit === null) {
            return 'unlimited';
        }

        if (! $lockSupported) {
            return 'unavailable';
        }

        if ($activeLimit !== null && $activeLeases >= $activeLimit) {
            return 'throttled';
        }

        if ($dispatchLimit !== null && $dispatchCount >= $dispatchLimit) {
            return 'throttled';
        }

        return 'accepting';
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function lockKey(string $namespace, string $taskQueue, string $taskKind): string
    {
        return sprintf('server:task-queue-admission:%s:%s:%s', sha1($namespace), sha1($taskQueue), $taskKind);
    }

    private function dispatchCounterKey(string $namespace, string $taskQueue, string $taskKind): string
    {
        return sprintf(
            'server:task-queue-dispatch:%s:%s:%s:%s',
            sha1($namespace),
            sha1($taskQueue),
            $taskKind,
            now()->format('YmdHi'),
        );
    }

    private function recordDispatch(string $namespace, string $taskQueue, string $taskKind): void
    {
        $key = $this->dispatchCounterKey($namespace, $taskQueue, $taskKind);

        if ($this->cache->store()->add($key, 1, now()->addMinutes(2))) {
            return;
        }

        $this->cache->store()->increment($key);
    }

    /**
     * @param  array{value: int|null, source: string}  $activeLimit
     * @param  array{value: int|null, source: string}  $dispatchLimit
     */
    private function budgetSource(array $activeLimit, array $dispatchLimit): string
    {
        $activeSource = $activeLimit['source'];
        $dispatchSource = $dispatchLimit['source'];

        if ($activeLimit['value'] === null) {
            return $dispatchLimit['value'] === null ? $activeSource : $dispatchSource;
        }

        if ($dispatchLimit['value'] === null) {
            return $activeSource;
        }

        if ($activeSource === $dispatchSource) {
            return $activeSource;
        }

        return $activeSource.';'.$dispatchSource;
    }

    private function lockTtlSeconds(): int
    {
        return max(1, (int) config('server.admission.lock_ttl_seconds', 5));
    }

    private function lockWaitSeconds(): int
    {
        return max(0, (int) config('server.admission.lock_wait_seconds', 1));
    }
}
