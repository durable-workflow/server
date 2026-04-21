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
                ->lock($this->lockKey($namespace, $taskQueue, $taskKind, $budget), $this->lockTtlSeconds())
                ->block($this->lockWaitSeconds(), function () use ($namespace, $taskQueue, $taskKind, $callback) {
                    $fresh = $this->budget($namespace, $taskQueue, $taskKind);

                    if (
                        $fresh['max_active_leases_per_queue'] !== null
                        && $fresh['active_lease_count'] >= $fresh['max_active_leases_per_queue']
                    ) {
                        return null;
                    }

                    if (
                        $fresh['max_active_leases_per_namespace'] !== null
                        && $fresh['namespace_active_lease_count'] >= $fresh['max_active_leases_per_namespace']
                    ) {
                        return null;
                    }

                    if (
                        $fresh['max_dispatches_per_minute'] !== null
                        && $fresh['dispatch_count_this_minute'] >= $fresh['max_dispatches_per_minute']
                    ) {
                        return null;
                    }

                    if (
                        $fresh['max_dispatches_per_minute_per_namespace'] !== null
                        && $fresh['namespace_dispatch_count_this_minute'] >= $fresh['max_dispatches_per_minute_per_namespace']
                    ) {
                        return null;
                    }

                    if (
                        $fresh['max_dispatches_per_minute_per_budget_group'] !== null
                        && $fresh['budget_group_dispatch_count_this_minute'] >= $fresh['max_dispatches_per_minute_per_budget_group']
                    ) {
                        return null;
                    }

                    $result = $callback();

                    if ($result !== null && $this->hasDispatchBudget($fresh)) {
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
     *     max_active_leases_per_namespace: int|null,
     *     namespace_active_lease_count: int,
     *     remaining_namespace_active_lease_capacity: int|null,
     *     max_dispatches_per_minute: int|null,
     *     dispatch_count_this_minute: int,
     *     remaining_dispatch_capacity: int|null,
     *     max_dispatches_per_minute_per_namespace: int|null,
     *     namespace_dispatch_count_this_minute: int,
     *     remaining_namespace_dispatch_capacity: int|null,
     *     dispatch_budget_group: string|null,
     *     max_dispatches_per_minute_per_budget_group: int|null,
     *     budget_group_dispatch_count_this_minute: int,
     *     remaining_budget_group_dispatch_capacity: int|null,
     *     lock_required: bool,
     *     lock_supported: bool,
     *     status: string
     * }
     */
    public function budget(string $namespace, string $taskQueue, string $taskKind): array
    {
        $limit = $this->maxActiveLeasesPerQueue($namespace, $taskQueue, $taskKind);
        $namespaceLimit = $this->maxActiveLeasesPerNamespace($namespace, $taskQueue, $taskKind);
        $dispatchLimit = $this->maxDispatchesPerMinute($namespace, $taskQueue, $taskKind);
        $namespaceDispatchLimit = $this->maxDispatchesPerMinutePerNamespace($namespace, $taskQueue, $taskKind);
        $budgetGroup = $this->dispatchBudgetGroup($namespace, $taskQueue, $taskKind);
        $budgetGroupDispatchLimit = $budgetGroup['value'] === null
            ? ['value' => null, 'source' => 'server.admission.queue_overrides']
            : $this->maxDispatchesPerMinutePerBudgetGroup($namespace, $taskQueue, $taskKind);
        $activeLeases = $this->activeLeaseCount($namespace, $taskQueue, $taskKind);
        $namespaceActiveLeases = $this->namespaceActiveLeaseCount($namespace, $taskKind);
        $dispatchCount = $this->dispatchCountThisMinute($namespace, $taskQueue, $taskKind);
        $namespaceDispatchCount = $this->namespaceDispatchCountThisMinute($namespace, $taskKind);
        $budgetGroupDispatchCount = $budgetGroup['value'] === null
            ? 0
            : $this->budgetGroupDispatchCountThisMinute($namespace, $budgetGroup['value'], $taskKind);
        $lockSupported = $this->cache->store()->getStore() instanceof LockProvider;

        return [
            'budget_source' => $this->budgetSource($limit, $namespaceLimit, $dispatchLimit, $namespaceDispatchLimit, $budgetGroupDispatchLimit),
            'max_active_leases_per_queue' => $limit['value'],
            'active_lease_count' => $activeLeases,
            'remaining_active_lease_capacity' => $limit['value'] === null
                ? null
                : max(0, $limit['value'] - $activeLeases),
            'max_active_leases_per_namespace' => $namespaceLimit['value'],
            'namespace_active_lease_count' => $namespaceActiveLeases,
            'remaining_namespace_active_lease_capacity' => $namespaceLimit['value'] === null
                ? null
                : max(0, $namespaceLimit['value'] - $namespaceActiveLeases),
            'max_dispatches_per_minute' => $dispatchLimit['value'],
            'dispatch_count_this_minute' => $dispatchCount,
            'remaining_dispatch_capacity' => $dispatchLimit['value'] === null
                ? null
                : max(0, $dispatchLimit['value'] - $dispatchCount),
            'max_dispatches_per_minute_per_namespace' => $namespaceDispatchLimit['value'],
            'namespace_dispatch_count_this_minute' => $namespaceDispatchCount,
            'remaining_namespace_dispatch_capacity' => $namespaceDispatchLimit['value'] === null
                ? null
                : max(0, $namespaceDispatchLimit['value'] - $namespaceDispatchCount),
            'dispatch_budget_group' => $budgetGroup['value'],
            'max_dispatches_per_minute_per_budget_group' => $budgetGroupDispatchLimit['value'],
            'budget_group_dispatch_count_this_minute' => $budgetGroupDispatchCount,
            'remaining_budget_group_dispatch_capacity' => $budgetGroupDispatchLimit['value'] === null
                ? null
                : max(0, $budgetGroupDispatchLimit['value'] - $budgetGroupDispatchCount),
            'lock_required' => $limit['value'] !== null
                || $namespaceLimit['value'] !== null
                || $dispatchLimit['value'] !== null
                || $namespaceDispatchLimit['value'] !== null
                || $budgetGroupDispatchLimit['value'] !== null,
            'lock_supported' => $lockSupported,
            'status' => $this->status(
                $limit['value'],
                $activeLeases,
                $namespaceLimit['value'],
                $namespaceActiveLeases,
                $dispatchLimit['value'],
                $dispatchCount,
                $namespaceDispatchLimit['value'],
                $namespaceDispatchCount,
                $budgetGroupDispatchLimit['value'],
                $budgetGroupDispatchCount,
                $lockSupported,
            ),
        ];
    }

    /**
     * @return array{value: int|null, source: string}
     */
    private function maxActiveLeasesPerQueue(string $namespace, string $taskQueue, string $taskKind): array
    {
        $override = $this->queueOverride($namespace, $taskQueue, $taskKind, 'max_active_leases');

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
    private function maxActiveLeasesPerNamespace(string $namespace, string $taskQueue, string $taskKind): array
    {
        $override = $this->queueOverride($namespace, $taskQueue, $taskKind, 'max_active_leases_per_namespace');

        if ($override !== null) {
            return [
                'value' => $this->positiveIntOrNull($override),
                'source' => 'server.admission.queue_overrides',
            ];
        }

        return [
            'value' => $this->positiveIntOrNull(config("server.admission.{$taskKind}.max_active_leases_per_namespace")),
            'source' => "server.admission.{$taskKind}.max_active_leases_per_namespace",
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

    /**
     * @return array{value: int|null, source: string}
     */
    private function maxDispatchesPerMinutePerNamespace(string $namespace, string $taskQueue, string $taskKind): array
    {
        $override = $this->queueOverride($namespace, $taskQueue, $taskKind, 'max_dispatches_per_minute_per_namespace');

        if ($override !== null) {
            return [
                'value' => $this->positiveIntOrNull($override),
                'source' => 'server.admission.queue_overrides',
            ];
        }

        return [
            'value' => $this->positiveIntOrNull(config("server.admission.{$taskKind}.max_dispatches_per_minute_per_namespace")),
            'source' => "server.admission.{$taskKind}.max_dispatches_per_minute_per_namespace",
        ];
    }

    /**
     * @return array{value: string|null, source: string}
     */
    private function dispatchBudgetGroup(string $namespace, string $taskQueue, string $taskKind): array
    {
        $group = $this->queueOverride($namespace, $taskQueue, $taskKind, 'dispatch_budget_group')
            ?? $this->queueOverride($namespace, $taskQueue, $taskKind, 'budget_group');

        if (! is_string($group)) {
            return [
                'value' => null,
                'source' => 'server.admission.queue_overrides',
            ];
        }

        $group = trim($group);

        return [
            'value' => $group === '' ? null : $group,
            'source' => 'server.admission.queue_overrides',
        ];
    }

    /**
     * @return array{value: int|null, source: string}
     */
    private function maxDispatchesPerMinutePerBudgetGroup(string $namespace, string $taskQueue, string $taskKind): array
    {
        $override = $this->queueOverride($namespace, $taskQueue, $taskKind, 'max_dispatches_per_minute_per_budget_group');

        if ($override !== null) {
            return [
                'value' => $this->positiveIntOrNull($override),
                'source' => 'server.admission.queue_overrides',
            ];
        }

        return [
            'value' => null,
            'source' => 'server.admission.queue_overrides',
        ];
    }

    private function queueOverride(string $namespace, string $taskQueue, string $taskKind, string $field = 'max_active_leases'): mixed
    {
        $overrides = config('server.admission.queue_overrides', []);

        if (! is_array($overrides)) {
            return null;
        }

        foreach ([$namespace.':'.$taskQueue, $namespace.':*', $taskQueue, '*'] as $key) {
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

    private function namespaceDispatchCountThisMinute(string $namespace, string $taskKind): int
    {
        return max(0, (int) $this->cache->store()->get($this->namespaceDispatchCounterKey($namespace, $taskKind), 0));
    }

    private function budgetGroupDispatchCountThisMinute(string $namespace, string $budgetGroup, string $taskKind): int
    {
        return max(0, (int) $this->cache->store()->get($this->budgetGroupDispatchCounterKey($namespace, $budgetGroup, $taskKind), 0));
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

    private function namespaceActiveLeaseCount(string $namespace, string $taskKind): int
    {
        return NamespaceWorkflowScope::taskQuery($namespace)
            ->where('workflow_tasks.task_type', $this->taskType($taskKind)->value)
            ->where('workflow_tasks.status', TaskStatus::Leased->value)
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

    private function status(
        ?int $activeLimit,
        int $activeLeases,
        ?int $namespaceActiveLimit,
        int $namespaceActiveLeases,
        ?int $dispatchLimit,
        int $dispatchCount,
        ?int $namespaceDispatchLimit,
        int $namespaceDispatchCount,
        ?int $budgetGroupDispatchLimit,
        int $budgetGroupDispatchCount,
        bool $lockSupported,
    ): string {
        if (
            $activeLimit === null
            && $namespaceActiveLimit === null
            && $dispatchLimit === null
            && $namespaceDispatchLimit === null
            && $budgetGroupDispatchLimit === null
        ) {
            return 'unlimited';
        }

        if (! $lockSupported) {
            return 'unavailable';
        }

        if ($activeLimit !== null && $activeLeases >= $activeLimit) {
            return 'throttled';
        }

        if ($namespaceActiveLimit !== null && $namespaceActiveLeases >= $namespaceActiveLimit) {
            return 'throttled';
        }

        if ($dispatchLimit !== null && $dispatchCount >= $dispatchLimit) {
            return 'throttled';
        }

        if ($namespaceDispatchLimit !== null && $namespaceDispatchCount >= $namespaceDispatchLimit) {
            return 'throttled';
        }

        if ($budgetGroupDispatchLimit !== null && $budgetGroupDispatchCount >= $budgetGroupDispatchLimit) {
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

    /**
     * @param  array<string, mixed>  $budget
     */
    private function lockKey(string $namespace, string $taskQueue, string $taskKind, array $budget): string
    {
        if (
            $budget['max_active_leases_per_namespace'] !== null
            || $budget['max_dispatches_per_minute_per_namespace'] !== null
        ) {
            return sprintf('server:task-queue-admission:%s:namespace:%s', sha1($namespace), $taskKind);
        }

        if (
            $budget['dispatch_budget_group'] !== null
            && $budget['max_dispatches_per_minute_per_budget_group'] !== null
        ) {
            return sprintf(
                'server:task-queue-admission:%s:budget-group:%s:%s',
                sha1($namespace),
                sha1($budget['dispatch_budget_group']),
                $taskKind,
            );
        }

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

    private function namespaceDispatchCounterKey(string $namespace, string $taskKind): string
    {
        return sprintf(
            'server:task-queue-dispatch:%s:namespace:%s:%s',
            sha1($namespace),
            $taskKind,
            now()->format('YmdHi'),
        );
    }

    private function budgetGroupDispatchCounterKey(string $namespace, string $budgetGroup, string $taskKind): string
    {
        return sprintf(
            'server:task-queue-dispatch:%s:budget-group:%s:%s:%s',
            sha1($namespace),
            sha1($budgetGroup),
            $taskKind,
            now()->format('YmdHi'),
        );
    }

    private function recordDispatch(string $namespace, string $taskQueue, string $taskKind): void
    {
        $key = $this->dispatchCounterKey($namespace, $taskQueue, $taskKind);

        if ($this->cache->store()->add($key, 1, now()->addMinutes(2))) {
            $this->recordNamespaceDispatch($namespace, $taskQueue, $taskKind);
            $this->recordBudgetGroupDispatch($namespace, $taskQueue, $taskKind);

            return;
        }

        $this->cache->store()->increment($key);
        $this->recordNamespaceDispatch($namespace, $taskQueue, $taskKind);
        $this->recordBudgetGroupDispatch($namespace, $taskQueue, $taskKind);
    }

    /**
     * @param  array<string, mixed>  $budget
     */
    private function hasDispatchBudget(array $budget): bool
    {
        return $budget['max_dispatches_per_minute'] !== null
            || $budget['max_dispatches_per_minute_per_namespace'] !== null
            || $budget['max_dispatches_per_minute_per_budget_group'] !== null;
    }

    private function recordNamespaceDispatch(string $namespace, string $taskQueue, string $taskKind): void
    {
        if ($this->maxDispatchesPerMinutePerNamespace($namespace, $taskQueue, $taskKind)['value'] === null) {
            return;
        }

        $key = $this->namespaceDispatchCounterKey($namespace, $taskKind);

        if ($this->cache->store()->add($key, 1, now()->addMinutes(2))) {
            return;
        }

        $this->cache->store()->increment($key);
    }

    private function recordBudgetGroupDispatch(string $namespace, string $taskQueue, string $taskKind): void
    {
        $budgetGroup = $this->dispatchBudgetGroup($namespace, $taskQueue, $taskKind)['value'];

        if (
            $budgetGroup === null
            || $this->maxDispatchesPerMinutePerBudgetGroup($namespace, $taskQueue, $taskKind)['value'] === null
        ) {
            return;
        }

        $key = $this->budgetGroupDispatchCounterKey($namespace, $budgetGroup, $taskKind);

        if ($this->cache->store()->add($key, 1, now()->addMinutes(2))) {
            return;
        }

        $this->cache->store()->increment($key);
    }

    /**
     * @param  array{value: int|null, source: string}  $activeLimit
     * @param  array{value: int|null, source: string}  $namespaceActiveLimit
     * @param  array{value: int|null, source: string}  $dispatchLimit
     * @param  array{value: int|null, source: string}  $namespaceDispatchLimit
     * @param  array{value: int|null, source: string}  $budgetGroupDispatchLimit
     */
    private function budgetSource(
        array $activeLimit,
        array $namespaceActiveLimit,
        array $dispatchLimit,
        array $namespaceDispatchLimit,
        array $budgetGroupDispatchLimit,
    ): string {
        $sources = [];

        foreach ([$activeLimit, $namespaceActiveLimit, $dispatchLimit, $namespaceDispatchLimit, $budgetGroupDispatchLimit] as $limit) {
            if ($limit['value'] === null) {
                continue;
            }

            $sources[] = $limit['source'];
        }

        if ($sources === []) {
            return $activeLimit['source'];
        }

        return implode(';', array_values(array_unique($sources)));
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
