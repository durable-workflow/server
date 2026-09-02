<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use App\Models\WorkflowInboundStream;
use App\Models\WorkflowInboundStreamItem;
use App\Models\WorkflowNamespace;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

final class NamespaceDurableStateQuota
{
    public const METRIC_NAME = 'dw_namespace_durable_state_usage';

    public const WORKFLOW_INSTANCES = 'workflow_instances';

    public const WORKFLOW_RUNS = 'workflow_runs';

    public const OPEN_WORKFLOW_RUNS = 'open_workflow_runs';

    public const SCHEDULES = 'schedules';

    public const SCHEDULE_HISTORY_EVENTS = 'schedule_history_events';

    public const WORKER_REGISTRATIONS = 'worker_registrations';

    public const WORKFLOW_HISTORY_EVENTS = 'workflow_history_events';

    public const WORKFLOW_TASKS = 'workflow_tasks';

    public const PENDING_WORKFLOW_TASKS = 'pending_workflow_tasks';

    public const WORKFLOW_TIMERS = 'workflow_timers';

    public const PENDING_WORKFLOW_TIMERS = 'pending_workflow_timers';

    public const WORKFLOW_RUN_WAITS = 'workflow_run_waits';

    public const OPEN_WORKFLOW_RUN_WAITS = 'open_workflow_run_waits';

    public const WORKFLOW_COMMANDS = 'workflow_commands';

    public const WORKFLOW_STREAMS = 'workflow_streams';

    public const WORKFLOW_STREAM_ITEMS = 'workflow_stream_items';

    /** @var array<string, string> */
    private const LIMIT_FIELDS = [
        self::WORKFLOW_INSTANCES => 'max_workflow_instances',
        self::WORKFLOW_RUNS => 'max_workflow_runs',
        self::OPEN_WORKFLOW_RUNS => 'max_open_workflow_runs',
        self::SCHEDULES => 'max_schedules',
        self::SCHEDULE_HISTORY_EVENTS => 'max_schedule_history_events',
        self::WORKER_REGISTRATIONS => 'max_worker_registrations',
        self::WORKFLOW_HISTORY_EVENTS => 'max_workflow_history_events',
        self::WORKFLOW_TASKS => 'max_workflow_tasks',
        self::PENDING_WORKFLOW_TASKS => 'max_pending_workflow_tasks',
        self::WORKFLOW_TIMERS => 'max_workflow_timers',
        self::PENDING_WORKFLOW_TIMERS => 'max_pending_workflow_timers',
        self::WORKFLOW_RUN_WAITS => 'max_workflow_run_waits',
        self::OPEN_WORKFLOW_RUN_WAITS => 'max_open_workflow_run_waits',
        self::WORKFLOW_COMMANDS => 'max_workflow_commands',
        self::WORKFLOW_STREAMS => 'max_workflow_streams',
        self::WORKFLOW_STREAM_ITEMS => 'max_workflow_stream_items',
    ];

    /** @var array<string, int> */
    private const ABSOLUTE_MAXIMUMS = [
        self::WORKFLOW_INSTANCES => 100_000_000,
        self::WORKFLOW_RUNS => 100_000_000,
        self::OPEN_WORKFLOW_RUNS => 100_000_000,
        self::SCHEDULES => 10_000_000,
        self::SCHEDULE_HISTORY_EVENTS => 1_000_000_000,
        self::WORKER_REGISTRATIONS => 10_000_000,
        self::WORKFLOW_HISTORY_EVENTS => 1_000_000_000,
        self::WORKFLOW_TASKS => 1_000_000_000,
        self::PENDING_WORKFLOW_TASKS => 100_000_000,
        self::WORKFLOW_TIMERS => 1_000_000_000,
        self::PENDING_WORKFLOW_TIMERS => 100_000_000,
        self::WORKFLOW_RUN_WAITS => 1_000_000_000,
        self::OPEN_WORKFLOW_RUN_WAITS => 100_000_000,
        self::WORKFLOW_COMMANDS => 1_000_000_000,
        self::WORKFLOW_STREAMS => 100_000_000,
        self::WORKFLOW_STREAM_ITEMS => 1_000_000_000,
    ];

    /** @var array<string, bool> */
    private const RETRYABLE_RESOURCES = [
        self::WORKFLOW_INSTANCES => false,
        self::WORKFLOW_RUNS => false,
        self::OPEN_WORKFLOW_RUNS => true,
        self::SCHEDULES => false,
        self::SCHEDULE_HISTORY_EVENTS => false,
        self::WORKER_REGISTRATIONS => true,
        self::WORKFLOW_HISTORY_EVENTS => false,
        self::WORKFLOW_TASKS => false,
        self::PENDING_WORKFLOW_TASKS => true,
        self::WORKFLOW_TIMERS => false,
        self::PENDING_WORKFLOW_TIMERS => true,
        self::WORKFLOW_RUN_WAITS => false,
        self::OPEN_WORKFLOW_RUN_WAITS => true,
        self::WORKFLOW_COMMANDS => false,
        self::WORKFLOW_STREAMS => true,
        self::WORKFLOW_STREAM_ITEMS => true,
    ];

    private const UNAVAILABLE_REASON = 'namespace_durable_state_quota_unavailable';

    public function __construct(
        private readonly ServerPollingCache $cache,
    ) {}

    /** @param list<string> $resources */
    public function mayConstrain(array $resources): bool
    {
        try {
            $fields = array_map(
                static fn (string $resource): string => self::LIMIT_FIELDS[$resource],
                $this->normalizeResources($resources),
            );
            $defaults = $this->validatedConfiguredObject('limits');
            $hardLimits = $this->validatedConfiguredObject('hard_limits');
            $overrides = $this->configuredOverrides();
        } catch (NamespaceDurableStateException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable((string) config('server.default_namespace', 'default'), $exception);
        }

        foreach ($fields as $field) {
            if (($defaults[$field] ?? null) !== null || ($hardLimits[$field] ?? null) !== null) {
                return true;
            }

            foreach ($overrides as $override) {
                if (($override[$field] ?? null) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<string> $resources */
    public function constrains(string $namespace, array $resources): bool
    {
        $namespace = $this->normalizeNamespace($namespace);

        try {
            $limits = $this->resourceLimits($namespace);
        } catch (NamespaceDurableStateException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable($namespace, $exception);
        }

        foreach ($this->normalizeResources($resources) as $resource) {
            if ($limits[$resource] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template TResult
     *
     * @param  list<string>  $resources
     * @param  Closure(): TResult  $mutation
     * @return TResult
     */
    public function mutate(string $namespace, array $resources, Closure $mutation): mixed
    {
        if (! $this->constrains($namespace, $resources)) {
            return $mutation();
        }

        return DB::transaction(function () use ($namespace, $resources, $mutation): mixed {
            $snapshot = $this->snapshotForMutation($namespace, $resources);
            $result = $mutation();
            $this->assertNoIncreasePastLimit($snapshot);

            return $result;
        });
    }

    /** @param list<string> $resources */
    public function admitCreate(string $namespace, array $resources): void
    {
        $namespace = $this->normalizeNamespace($namespace);

        try {
            $allLimits = $this->resourceLimits($namespace);
            $limitedResources = [];

            foreach ($this->normalizeResources($resources) as $resource) {
                if ($allLimits[$resource] !== null) {
                    $limitedResources[$resource] = $allLimits[$resource];
                }
            }

            if ($limitedResources === []) {
                return;
            }

            if (DB::connection()->transactionLevel() < 1) {
                throw new InvalidArgumentException(
                    'Namespace durable-state quota admission requires an active transaction.',
                );
            }

            $namespaceRow = WorkflowNamespace::query()
                ->where('name', $namespace)
                ->lockForUpdate()
                ->first();

            if (! $namespaceRow instanceof WorkflowNamespace) {
                throw new InvalidArgumentException('Namespace durable-state quota namespace does not exist.');
            }

            $usage = $this->usage($namespace, array_keys($limitedResources));
        } catch (NamespaceDurableStateException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable($namespace, $exception);
        }

        foreach ($limitedResources as $resource => $limit) {
            $attempted = $usage[$resource] + 1;

            if ($attempted > $limit) {
                throw $this->exhausted($namespace, $resource, $attempted, $limit);
            }
        }
    }

    /**
     * Capture database-authoritative usage while serializing namespace
     * cardinality changes. Call this inside the transaction that performs the
     * mutation, then pass the result to assertNoIncreasePastLimit().
     *
     * @param  list<string>  $resources
     * @return array{namespace: string, limits: array<string, int>, usage: array<string, int>}
     */
    public function snapshotForMutation(string $namespace, array $resources): array
    {
        $namespace = $this->normalizeNamespace($namespace);

        try {
            $allLimits = $this->resourceLimits($namespace);
            $limitedResources = [];

            foreach ($this->normalizeResources($resources) as $resource) {
                if ($allLimits[$resource] !== null) {
                    $limitedResources[$resource] = $allLimits[$resource];
                }
            }

            if ($limitedResources === []) {
                return [
                    'namespace' => $namespace,
                    'limits' => [],
                    'usage' => [],
                ];
            }

            if (DB::connection()->transactionLevel() < 1) {
                throw new InvalidArgumentException(
                    'Namespace durable-state quota admission requires an active transaction.',
                );
            }

            $namespaceRow = WorkflowNamespace::query()
                ->where('name', $namespace)
                ->lockForUpdate()
                ->first();

            if (! $namespaceRow instanceof WorkflowNamespace) {
                throw new InvalidArgumentException('Namespace durable-state quota namespace does not exist.');
            }

            return [
                'namespace' => $namespace,
                'limits' => $limitedResources,
                'usage' => $this->usage($namespace, array_keys($limitedResources)),
            ];
        } catch (NamespaceDurableStateException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable($namespace, $exception);
        }
    }

    /**
     * Reject only a resource whose durable cardinality increased past its
     * effective limit. Existing over-limit namespaces may still perform
     * mutations that hold or reduce cardinality, such as completing a run or
     * refreshing an existing worker registration.
     *
     * @param  array{namespace: string, limits: array<string, int>, usage: array<string, int>}  $snapshot
     */
    public function assertNoIncreasePastLimit(array $snapshot): void
    {
        if ($snapshot['limits'] === []) {
            return;
        }

        $namespace = $snapshot['namespace'];

        try {
            $currentUsage = $this->usage($namespace, array_keys($snapshot['limits']));
        } catch (Throwable $exception) {
            throw $this->unavailable($namespace, $exception);
        }

        foreach ($snapshot['limits'] as $resource => $limit) {
            $before = $snapshot['usage'][$resource];
            $current = $currentUsage[$resource];

            if ($current <= $before || $current <= $limit) {
                continue;
            }

            throw $this->exhausted($namespace, $resource, $current, $limit);
        }
    }

    /**
     * @return array{
     *     limits: array<string, int|null>,
     *     usage: array<string, int|null>,
     *     remaining: array<string, int|null>,
     *     rejections_this_minute: int,
     *     rejections_by_reason: array<string, int>,
     *     configuration_status: string,
     *     measurement_status: string,
     *     label_cardinality_policy: array<string, string>
     * }
     */
    public function metrics(string $namespace): array
    {
        $namespace = $this->normalizeNamespace($namespace);

        try {
            $limits = $this->resourceLimits($namespace);
            $configurationStatus = 'valid';
        } catch (Throwable) {
            $limits = array_fill_keys(array_keys(self::LIMIT_FIELDS), null);
            $configurationStatus = 'invalid';
        }

        try {
            $measured = $this->usage($namespace, array_keys(self::LIMIT_FIELDS));
            $usage = $measured;
            $measurementStatus = 'available';
        } catch (Throwable) {
            $usage = array_fill_keys(array_keys(self::LIMIT_FIELDS), null);
            $measurementStatus = 'unavailable';
        }

        $remaining = [];
        foreach (array_keys(self::LIMIT_FIELDS) as $resource) {
            $remaining[$resource] = $limits[$resource] === null || $usage[$resource] === null
                ? null
                : max(0, $limits[$resource] - $usage[$resource]);
        }

        $byReason = [];
        $bucket = $this->minuteBucket();
        foreach ($this->reasons() as $reason) {
            $byReason[$reason] = $this->cacheValue(
                $this->rejectionCounterKey($namespace, $bucket, $reason),
            );
        }

        return [
            'limits' => $limits,
            'usage' => $usage,
            'remaining' => $remaining,
            'rejections_this_minute' => array_sum($byReason),
            'rejections_by_reason' => $byReason,
            'configuration_status' => $configurationStatus,
            'measurement_status' => $measurementStatus,
            'label_cardinality_policy' => [
                'namespace' => 'request_scope_not_label',
                'reason' => 'finite_reason_inventory',
            ],
        ];
    }

    /** @return array<string, int|null> */
    public function resourceLimits(string $namespace): array
    {
        $namespace = $this->normalizeNamespace($namespace);
        $defaults = $this->validatedConfiguredObject('limits');
        $hardLimits = $this->validatedConfiguredObject('hard_limits');
        $overrides = $this->configuredOverrides();
        $namespaceOverride = $overrides[$namespace] ?? [];

        $resolved = [];
        foreach (self::LIMIT_FIELDS as $resource => $field) {
            $default = $defaults[$field] ?? null;
            $hard = $hardLimits[$field] ?? null;
            $override = array_key_exists($field, $namespaceOverride)
                ? $this->limitValue(
                    $namespaceOverride[$field],
                    "server.namespace_durable_state.overrides.{$namespace}.{$field}",
                )
                : null;
            $configured = $override ?? $default;
            $candidates = array_values(array_filter(
                [$configured, $hard, self::ABSOLUTE_MAXIMUMS[$resource]],
                static fn (?int $value): bool => $value !== null,
            ));

            $resolved[$resource] = $configured === null && $hard === null
                ? null
                : min($candidates);
        }

        return $resolved;
    }

    /** @param list<string> $resources @return array<string, int> */
    private function usage(string $namespace, array $resources): array
    {
        $usage = [];

        foreach ($resources as $resource) {
            $usage[$resource] = match ($resource) {
                self::WORKFLOW_INSTANCES => (int) WorkflowInstance::query()
                    ->where('namespace', $namespace)
                    ->count(),
                self::WORKFLOW_RUNS => (int) WorkflowRun::query()
                    ->where('namespace', $namespace)
                    ->count(),
                self::OPEN_WORKFLOW_RUNS => (int) WorkflowRun::query()
                    ->where('namespace', $namespace)
                    ->whereIn('status', [
                        RunStatus::Pending->value,
                        RunStatus::Running->value,
                        RunStatus::Waiting->value,
                    ])
                    ->count(),
                self::SCHEDULES => (int) WorkflowSchedule::query()
                    ->where('namespace', $namespace)
                    ->count(),
                self::SCHEDULE_HISTORY_EVENTS => (int) WorkflowScheduleHistoryEvent::query()
                    ->where('namespace', $namespace)
                    ->count(),
                self::WORKER_REGISTRATIONS => (int) WorkerRegistration::query()
                    ->where('namespace', $namespace)
                    ->count(),
                self::WORKFLOW_HISTORY_EVENTS => (int) WorkflowHistoryEvent::query()
                    ->whereIn('workflow_run_id', $this->runIdsForNamespace($namespace))
                    ->count(),
                self::WORKFLOW_TASKS => (int) WorkflowTask::query()
                    ->where('namespace', $namespace)
                    ->count(),
                self::PENDING_WORKFLOW_TASKS => (int) WorkflowTask::query()
                    ->where('namespace', $namespace)
                    ->where('status', TaskStatus::Ready->value)
                    ->count(),
                self::WORKFLOW_TIMERS => (int) WorkflowTimer::query()
                    ->whereIn('workflow_run_id', $this->runIdsForNamespace($namespace))
                    ->count(),
                self::PENDING_WORKFLOW_TIMERS => (int) WorkflowTimer::query()
                    ->whereIn('workflow_run_id', $this->runIdsForNamespace($namespace))
                    ->where('status', TimerStatus::Pending->value)
                    ->count(),
                self::WORKFLOW_RUN_WAITS => (int) WorkflowRunWait::query()
                    ->whereIn('workflow_instance_id', $this->instanceIdsForNamespace($namespace))
                    ->count(),
                self::OPEN_WORKFLOW_RUN_WAITS => (int) WorkflowRunWait::query()
                    ->whereIn('workflow_instance_id', $this->instanceIdsForNamespace($namespace))
                    ->where('status', 'open')
                    ->count(),
                self::WORKFLOW_COMMANDS => (int) WorkflowCommand::query()
                    ->whereIn('workflow_instance_id', $this->instanceIdsForNamespace($namespace))
                    ->count(),
                self::WORKFLOW_STREAMS => (int) WorkflowDurableStream::query()
                    ->where('namespace', $namespace)
                    ->count() + (int) WorkflowInboundStream::query()
                    ->where('namespace', $namespace)
                    ->count(),
                self::WORKFLOW_STREAM_ITEMS => (int) WorkflowDurableStreamItem::query()
                    ->where('namespace', $namespace)
                    ->count() + (int) WorkflowInboundStreamItem::query()
                    ->where('namespace', $namespace)
                    ->count(),
                default => throw new InvalidArgumentException("Unknown namespace durable-state resource [{$resource}]."),
            };
        }

        return $usage;
    }

    private function runIdsForNamespace(string $namespace)
    {
        return WorkflowRun::query()
            ->select('id')
            ->where('namespace', $namespace);
    }

    private function instanceIdsForNamespace(string $namespace)
    {
        return WorkflowInstance::query()
            ->select('id')
            ->where('namespace', $namespace);
    }

    /** @return array<string, int|null> */
    private function validatedConfiguredObject(string $name): array
    {
        $configured = $this->configuredObject($name);
        $validated = [];

        foreach ($configured as $field => $value) {
            $validated[$field] = $this->limitValue(
                $value,
                "server.namespace_durable_state.{$name}.{$field}",
            );
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    private function configuredObject(string $name): array
    {
        $value = config("server.namespace_durable_state.{$name}", []);

        if (! is_array($value)) {
            throw new InvalidArgumentException("server.namespace_durable_state.{$name} must be a JSON object.");
        }

        $this->assertKnownFields($value, "server.namespace_durable_state.{$name}");

        return $value;
    }

    /** @return array<string, array<string, int|null>> */
    private function configuredOverrides(): array
    {
        $configured = config('server.namespace_durable_state.overrides', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('server.namespace_durable_state.overrides must be a JSON object.');
        }

        $overrides = [];

        foreach ($configured as $configuredNamespace => $values) {
            if (! is_string($configuredNamespace) || trim($configuredNamespace) === '') {
                throw new InvalidArgumentException(
                    'server.namespace_durable_state.overrides keys must be non-empty namespace names.',
                );
            }

            $namespace = $this->normalizeNamespace($configuredNamespace);
            if (array_key_exists($namespace, $overrides)) {
                throw new InvalidArgumentException(
                    "server.namespace_durable_state.overrides contains duplicate normalized namespace [{$namespace}].",
                );
            }

            if (! is_array($values)) {
                throw new InvalidArgumentException(
                    "server.namespace_durable_state.overrides.{$configuredNamespace} must be a JSON object.",
                );
            }

            $path = "server.namespace_durable_state.overrides.{$configuredNamespace}";
            $this->assertKnownFields($values, $path);

            $validated = [];
            foreach ($values as $field => $value) {
                $validated[$field] = $this->limitValue($value, "{$path}.{$field}");
            }

            $overrides[$namespace] = $validated;
        }

        return $overrides;
    }

    /** @param array<string, mixed> $values */
    private function assertKnownFields(array $values, string $path): void
    {
        $unknown = array_diff(array_keys($values), array_values(self::LIMIT_FIELDS));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s contains unsupported field [%s].',
                $path,
                (string) reset($unknown),
            ));
        }
    }

    private function limitValue(mixed $value, string $path): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException("{$path} must be null or a non-negative integer.");
    }

    /** @param list<string> $resources @return list<string> */
    private function normalizeResources(array $resources): array
    {
        $normalized = array_values(array_unique($resources));

        foreach ($normalized as $resource) {
            if (! is_string($resource) || ! array_key_exists($resource, self::LIMIT_FIELDS)) {
                throw new InvalidArgumentException('Unknown namespace durable-state resource.');
            }
        }

        return $normalized;
    }

    private function normalizeNamespace(string $namespace): string
    {
        $namespace = strtolower(trim($namespace));

        if ($namespace === '') {
            throw new InvalidArgumentException('Namespace durable-state quota requires a namespace.');
        }

        return $namespace;
    }

    private function exhausted(
        string $namespace,
        string $resource,
        int $current,
        int $limit,
    ): NamespaceDurableStateException {
        $reason = "namespace_{$resource}_exhausted";
        $retryable = self::RETRYABLE_RESOURCES[$resource];
        $this->recordRejection($namespace, $reason);

        return new NamespaceDurableStateException(
            reason: $reason,
            status: 429,
            retryable: $retryable,
            message: sprintf(
                'Namespace durable-state capacity for %s is exhausted.',
                str_replace('_', ' ', $resource),
            ),
            resource: $resource,
            currentValue: $current,
            configuredLimit: $limit,
            retryAfterSeconds: $retryable ? 60 : null,
        );
    }

    private function unavailable(string $namespace, Throwable $previous): NamespaceDurableStateException
    {
        $this->recordRejection($namespace, self::UNAVAILABLE_REASON);

        return new NamespaceDurableStateException(
            reason: self::UNAVAILABLE_REASON,
            status: 503,
            retryable: true,
            message: 'Namespace durable-state quota could not be evaluated.',
            retryAfterSeconds: 1,
            previous: $previous,
        );
    }

    /** @return list<string> */
    private function reasons(): array
    {
        return [
            ...array_map(
                static fn (string $resource): string => "namespace_{$resource}_exhausted",
                array_keys(self::LIMIT_FIELDS),
            ),
            self::UNAVAILABLE_REASON,
        ];
    }

    private function recordRejection(string $namespace, string $reason): void
    {
        $shouldLog = false;

        try {
            $store = $this->cache->store();
            $bucket = $this->minuteBucket();
            $key = $this->rejectionCounterKey($namespace, $bucket, $reason);

            if (! $store->add($key, 1, 120)) {
                $store->increment($key);
            }

            $shouldLog = $store->add($this->rejectionLogKey($namespace, $bucket, $reason), true, 120);
        } catch (Throwable) {
            // Database state remains authoritative; telemetry is best effort.
        }

        if ($shouldLog) {
            Log::warning('Namespace durable-state quota rejected a mutation.', [
                'namespace' => $namespace,
                'reason' => $reason,
            ]);
        }
    }

    private function cacheValue(string $key): int
    {
        try {
            if (! $this->cache->available()) {
                return 0;
            }

            return max(0, (int) $this->cache->store()->get($key, 0));
        } catch (Throwable) {
            return 0;
        }
    }

    private function minuteBucket(): int
    {
        return intdiv(now()->getTimestamp(), 60);
    }

    private function rejectionCounterKey(string $namespace, int $bucket, string $reason): string
    {
        return sprintf(
            'server:namespace-durable-state:rejections:%s:%d:%s',
            hash('sha256', $namespace),
            $bucket,
            $reason,
        );
    }

    private function rejectionLogKey(string $namespace, int $bucket, string $reason): string
    {
        return sprintf(
            'server:namespace-durable-state:rejection-log:%s:%d:%s',
            hash('sha256', $namespace),
            $bucket,
            $reason,
        );
    }
}
