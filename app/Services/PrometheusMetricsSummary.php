<?php

namespace App\Services;

use App\Models\WorkerRegistration;
use App\Support\BoundedMetricPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\StatusBucket;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Support\StandaloneWorkerVisibility;

class PrometheusMetricsSummary
{
    public const WORKFLOW_RUNS_METRIC = 'dw_workflow_runs_total';
    public const WORKFLOW_RUN_LATENCY_METRIC = 'dw_workflow_run_latency_seconds';
    public const ACTIVITY_EXECUTIONS_METRIC = 'dw_activity_executions_total';
    public const ACTIVITY_EXECUTION_LATENCY_METRIC = 'dw_activity_execution_latency_seconds';
    public const TASK_QUEUE_RUNTIME_METRIC = 'dw_task_queue_runtime_state';

    private const DEFAULT_WORKFLOW_SERIES_LIMIT = 100;
    private const DEFAULT_ACTIVITY_SERIES_LIMIT = 100;
    private const DEFAULT_TASK_QUEUE_SERIES_LIMIT = 100;
    private const MAX_SERIES_LIMIT = 500;

    private const LATENCY_BUCKETS_SECONDS = [
        0.1,
        0.5,
        1.0,
        2.5,
        5.0,
        10.0,
        30.0,
        60.0,
        300.0,
        900.0,
    ];

    /**
     * @return array<string, mixed>
     */
    public function snapshot(string $namespace, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $workflowLimit = $this->seriesLimit(
            'prometheus_workflow_series_limit',
            self::DEFAULT_WORKFLOW_SERIES_LIMIT,
        );
        $activityLimit = $this->seriesLimit(
            'prometheus_activity_series_limit',
            self::DEFAULT_ACTIVITY_SERIES_LIMIT,
        );
        $taskQueueLimit = $this->seriesLimit(
            'prometheus_task_queue_series_limit',
            self::DEFAULT_TASK_QUEUE_SERIES_LIMIT,
        );

        $workflows = $this->workflowSeries($namespace, $workflowLimit);
        $activities = $this->activitySeries($namespace, $activityLimit);
        $taskQueues = $this->taskQueueSeries($namespace, $now, $taskQueueLimit);

        return [
            'schema' => 'durable-workflow.prometheus-metrics.v1',
            'generated_at' => $now->toJSON(),
            'namespace' => $namespace,
            'latency_buckets_seconds' => self::LATENCY_BUCKETS_SECONDS,
            'series' => [
                'workflows' => $workflows['series'],
                'activities' => $activities['series'],
                'task_queues' => $taskQueues['series'],
            ],
            'series_count' => count($workflows['series']) + count($activities['series']) + count($taskQueues['series']),
            'observed_series_count' => $workflows['observed_series_count']
                + $activities['observed_series_count']
                + $taskQueues['observed_series_count'],
            'observed_series_count_precision' => $this->combinedPrecision($workflows, $activities, $taskQueues),
            'suppressed_series_count' => $workflows['suppressed_series_count']
                + $activities['suppressed_series_count']
                + $taskQueues['suppressed_series_count'],
            'suppressed_series_count_precision' => $this->combinedPrecision($workflows, $activities, $taskQueues),
            'cardinality' => [
                'series_limits' => [
                    'workflows' => $this->seriesLimitDisclosure(
                        $workflows,
                        ['task_queue', 'workflow_type'],
                        'bounded_task_queue_and_workflow_type_ascending',
                    ),
                    'activities' => $this->seriesLimitDisclosure(
                        $activities,
                        ['task_queue', 'workflow_type', 'activity_type'],
                        'bounded_task_queue_workflow_type_and_activity_type_ascending',
                    ),
                    'task_queues' => $this->seriesLimitDisclosure(
                        $taskQueues,
                        ['task_queue'],
                        'task_queue_name_ascending',
                    ),
                ],
                'metric_label_sets' => $this->metricLabelSets($workflows, $activities, $taskQueues),
            ],
        ];
    }

    /**
     * @return array{
     *     series: list<array<string, mixed>>,
     *     limit: int,
     *     observed_series_count: int,
     *     observed_series_count_precision: string,
     *     suppressed_series_count: int,
     *     suppressed_started_total: int,
     *     suppressed_counts_precision: string,
     *     truncated: bool
     * }
     */
    private function workflowSeries(string $namespace, int $limit): array
    {
        $durationExpr = 'duration_ms / 1000.0';
        $candidates = $this->workflowSeriesCandidates($namespace, $limit);
        $reportedKeys = array_slice($candidates, 0, $limit);
        $overflowKeys = array_slice($candidates, $limit);

        $rows = DB::table('workflow_run_summaries')
            ->selectRaw("COALESCE(queue, 'default') as task_queue")
            ->selectRaw('workflow_type')
            ->selectRaw('COUNT(*) as started_total')
            ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_total", [RunStatus::Completed->value])
            ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_total", [RunStatus::Failed->value])
            ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled_total", [RunStatus::Cancelled->value])
            ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as terminated_total", [RunStatus::Terminated->value])
            ->selectRaw("SUM(CASE WHEN status_bucket = ? THEN 1 ELSE 0 END) as running", [StatusBucket::Running->value])
            ->selectRaw('SUM(CASE WHEN duration_ms IS NOT NULL THEN 1 ELSE 0 END) as latency_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN duration_ms IS NOT NULL THEN {$durationExpr} ELSE 0 END), 0) as latency_sum")
            ->where('namespace', $namespace)
            ->groupByRaw("COALESCE(queue, 'default'), workflow_type");

        $this->whereWorkflowSeriesIn($rows, $candidates);
        $this->selectLatencyBuckets($rows, $durationExpr, 'duration_ms IS NOT NULL');

        $rows = $rows->get()->keyBy(
            fn (object $row): string => $this->workflowSeriesKey((string) $row->task_queue, (string) $row->workflow_type),
        );

        $series = [];
        foreach ($reportedKeys as $key) {
            $row = $rows->get($this->workflowSeriesKey($key['task_queue'], $key['workflow_type']));

            if (! $row) {
                continue;
            }

            $series[] = [
                'task_queue' => (string) $row->task_queue,
                'workflow_type' => (string) $row->workflow_type,
                'started_total' => (int) $row->started_total,
                'completed_total' => (int) $row->completed_total,
                'failed_total' => (int) $row->failed_total,
                'cancelled_total' => (int) $row->cancelled_total,
                'terminated_total' => (int) $row->terminated_total,
                'running' => (int) $row->running,
                'latency_seconds' => $this->latencyFromRow($row),
            ];
        }

        $suppressedStarted = 0;
        foreach ($overflowKeys as $key) {
            $row = $rows->get($this->workflowSeriesKey($key['task_queue'], $key['workflow_type']));
            $suppressedStarted += $row ? (int) $row->started_total : 0;
        }

        return $this->seriesSnapshot(
            $series,
            $limit,
            count($candidates),
            $suppressedStarted,
            count($candidates) > $limit,
        );
    }

    /**
     * @return array{
     *     series: list<array<string, mixed>>,
     *     limit: int,
     *     observed_series_count: int,
     *     observed_series_count_precision: string,
     *     suppressed_series_count: int,
     *     suppressed_started_total: int,
     *     suppressed_counts_precision: string,
     *     truncated: bool
     * }
     */
    private function activitySeries(string $namespace, int $limit): array
    {
        $durationExpr = $this->activityDurationSecondsExpression();
        $durationAvailable = 'activity_executions.started_at IS NOT NULL AND activity_executions.closed_at IS NOT NULL';
        $candidates = $this->activitySeriesCandidates($namespace, $limit);
        $reportedKeys = array_slice($candidates, 0, $limit);
        $overflowKeys = array_slice($candidates, $limit);

        $rows = DB::table('activity_executions')
            ->join('workflow_runs', 'workflow_runs.id', '=', 'activity_executions.workflow_run_id')
            ->selectRaw("COALESCE(activity_executions.queue, workflow_runs.queue, 'default') as task_queue")
            ->selectRaw('workflow_runs.workflow_type as workflow_type')
            ->selectRaw('activity_executions.activity_type as activity_type')
            ->selectRaw('COUNT(*) as started_total')
            ->selectRaw(
                "SUM(CASE WHEN activity_executions.status = ? THEN 1 ELSE 0 END) as completed_total",
                [ActivityStatus::Completed->value],
            )
            ->selectRaw(
                "SUM(CASE WHEN activity_executions.status = ? THEN 1 ELSE 0 END) as failed_total",
                [ActivityStatus::Failed->value],
            )
            ->selectRaw(
                "SUM(CASE WHEN activity_executions.status = ? THEN 1 ELSE 0 END) as cancelled_total",
                [ActivityStatus::Cancelled->value],
            )
            ->selectRaw(
                "SUM(CASE WHEN activity_executions.status IN (?, ?) THEN 1 ELSE 0 END) as running",
                [ActivityStatus::Pending->value, ActivityStatus::Running->value],
            )
            ->selectRaw("SUM(CASE WHEN {$durationAvailable} THEN 1 ELSE 0 END) as latency_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$durationAvailable} THEN {$durationExpr} ELSE 0 END), 0) as latency_sum")
            ->where('workflow_runs.namespace', $namespace)
            ->groupByRaw("COALESCE(activity_executions.queue, workflow_runs.queue, 'default'), workflow_runs.workflow_type, activity_executions.activity_type");

        $this->whereActivitySeriesIn($rows, $candidates);
        $this->selectLatencyBuckets($rows, $durationExpr, $durationAvailable);

        $rows = $rows->get()->keyBy(
            fn (object $row): string => $this->activitySeriesKey(
                (string) $row->task_queue,
                (string) $row->workflow_type,
                (string) $row->activity_type,
            ),
        );

        $series = [];
        foreach ($reportedKeys as $key) {
            $row = $rows->get($this->activitySeriesKey(
                $key['task_queue'],
                $key['workflow_type'],
                $key['activity_type'],
            ));

            if (! $row) {
                continue;
            }

            $series[] = [
                'task_queue' => (string) $row->task_queue,
                'workflow_type' => (string) $row->workflow_type,
                'activity_type' => (string) $row->activity_type,
                'started_total' => (int) $row->started_total,
                'completed_total' => (int) $row->completed_total,
                'failed_total' => (int) $row->failed_total,
                'cancelled_total' => (int) $row->cancelled_total,
                'running' => (int) $row->running,
                'latency_seconds' => $this->latencyFromRow($row),
            ];
        }

        $suppressedStarted = 0;
        foreach ($overflowKeys as $key) {
            $row = $rows->get($this->activitySeriesKey(
                $key['task_queue'],
                $key['workflow_type'],
                $key['activity_type'],
            ));
            $suppressedStarted += $row ? (int) $row->started_total : 0;
        }

        return $this->seriesSnapshot(
            $series,
            $limit,
            count($candidates),
            $suppressedStarted,
            count($candidates) > $limit,
        );
    }

    /**
     * @return array{
     *     series: list<array<string, mixed>>,
     *     limit: int,
     *     observed_series_count: int,
     *     observed_series_count_precision: string,
     *     suppressed_series_count: int,
     *     suppressed_started_total: int,
     *     suppressed_counts_precision: string,
     *     truncated: bool
     * }
     */
    private function taskQueueSeries(string $namespace, CarbonInterface $now, int $limit): array
    {
        $queues = [];
        $reportedQueues = $this->taskQueueCandidates($namespace, $now, $limit);
        $observed = count($reportedQueues);
        $truncated = $observed > $limit;
        $reportedQueues = array_slice($reportedQueues, 0, $limit);

        foreach ($this->taskRows($namespace, $now, $reportedQueues) as $row) {
            $queue = (string) $row->task_queue;
            $queues[$queue] ??= $this->emptyTaskQueue($queue);
            $taskType = (string) $row->task_type;
            $status = (string) $row->status;
            $prefix = $taskType === TaskType::Activity->value ? 'activity' : 'workflow';

            if ($status === TaskStatus::Ready->value) {
                $queues[$queue]["{$prefix}_ready_tasks"] += (int) $row->count;
                $queues[$queue]["{$prefix}_delayed_tasks"] += (int) $row->delayed_count;
                $queues[$queue]["{$prefix}_ready_due_tasks"] += max(0, (int) $row->count - (int) $row->delayed_count);
            }

            if ($status === TaskStatus::Leased->value) {
                $queues[$queue]["{$prefix}_leased_tasks"] += (int) $row->count;
                $queues[$queue]["{$prefix}_expired_leases"] += (int) $row->expired_lease_count;
            }

            $queues[$queue]["{$prefix}_tasks_added_last_minute"] += (int) $row->added_last_minute;
            $queues[$queue]["{$prefix}_tasks_dispatched_last_minute"] += (int) $row->dispatched_last_minute;
        }

        foreach ($this->workerRows($namespace, $now, $reportedQueues) as $row) {
            $queue = (string) $row->task_queue;
            $queues[$queue] ??= $this->emptyTaskQueue($queue);
            $queues[$queue]['active_pollers'] = (int) $row->active_pollers;
            $queues[$queue]['workflow_slot_capacity'] = (int) $row->workflow_slot_capacity;
            $queues[$queue]['activity_slot_capacity'] = (int) $row->activity_slot_capacity;
            $queues[$queue]['available_workflow_slots'] = (int) $row->available_workflow_slots;
            $queues[$queue]['available_activity_slots'] = (int) $row->available_activity_slots;
        }

        ksort($queues);

        return $this->seriesSnapshot(array_values($queues), $limit, $observed, 0, $truncated);
    }

    /**
     * @param  list<string>  $queues
     */
    private function taskRows(string $namespace, CarbonInterface $now, array $queues): \Illuminate\Support\Collection
    {
        $oneMinuteAgo = $now->copy()->subMinute();

        return DB::table('workflow_tasks')
            ->selectRaw("COALESCE(queue, 'default') as task_queue")
            ->selectRaw('task_type')
            ->selectRaw('status')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw(
                'SUM(CASE WHEN status = ? AND available_at > ? THEN 1 ELSE 0 END) as delayed_count',
                [TaskStatus::Ready->value, $now],
            )
            ->selectRaw(
                'SUM(CASE WHEN status = ? AND lease_expires_at IS NOT NULL AND lease_expires_at <= ? THEN 1 ELSE 0 END) as expired_lease_count',
                [TaskStatus::Leased->value, $now],
            )
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as added_last_minute', [$oneMinuteAgo])
            ->selectRaw('SUM(CASE WHEN last_dispatched_at IS NOT NULL AND last_dispatched_at >= ? THEN 1 ELSE 0 END) as dispatched_last_minute', [$oneMinuteAgo])
            ->where('namespace', $namespace)
            ->whereIn('task_type', [TaskType::Workflow->value, TaskType::Activity->value])
            ->where(function ($query) use ($oneMinuteAgo): void {
                $this->whereTaskRuntimeVisible($query, $oneMinuteAgo);
            })
            ->where(function ($query) use ($queues): void {
                $this->whereQueueLabelIn($query, 'queue', $queues);
            })
            ->groupByRaw("COALESCE(queue, 'default'), task_type, status")
            ->get();
    }

    /**
     * @param  list<string>  $queues
     */
    private function workerRows(string $namespace, CarbonInterface $now, array $queues): \Illuminate\Support\Collection
    {
        $staleCutoff = $now->copy()->subSeconds($this->workerStaleAfterSeconds());

        return WorkerRegistration::query()
            ->selectRaw("COALESCE(task_queue, 'default') as task_queue")
            ->selectRaw('COUNT(*) as active_pollers')
            ->selectRaw('COALESCE(SUM(max_concurrent_workflow_tasks), 0) as workflow_slot_capacity')
            ->selectRaw('COALESCE(SUM(max_concurrent_activity_tasks), 0) as activity_slot_capacity')
            ->selectRaw('COALESCE(SUM(COALESCE(available_workflow_slots, max_concurrent_workflow_tasks)), 0) as available_workflow_slots')
            ->selectRaw('COALESCE(SUM(COALESCE(available_activity_slots, max_concurrent_activity_tasks)), 0) as available_activity_slots')
            ->where('namespace', $namespace)
            ->where('status', 'active')
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '>=', $staleCutoff)
            ->where(function ($query) use ($queues): void {
                $this->whereQueueLabelIn($query, 'task_queue', $queues);
            })
            ->groupByRaw("COALESCE(task_queue, 'default')")
            ->get();
    }

    private function selectLatencyBuckets($query, string $durationExpr, string $durationAvailableSql): void
    {
        foreach (self::LATENCY_BUCKETS_SECONDS as $index => $bucket) {
            $query->selectRaw(
                "SUM(CASE WHEN {$durationAvailableSql} AND {$durationExpr} <= ? THEN 1 ELSE 0 END) as bucket_{$index}",
                [$bucket],
            );
        }
    }

    /**
     * @return array{count: int, sum: float, buckets: array<string, int>}
     */
    private function latencyFromRow(object $row): array
    {
        $buckets = [];
        foreach (self::LATENCY_BUCKETS_SECONDS as $index => $bucket) {
            $buckets[(string) $bucket] = (int) $row->{"bucket_{$index}"};
        }

        return [
            'count' => (int) $row->latency_count,
            'sum' => round((float) $row->latency_sum, 6),
            'buckets' => $buckets,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function emptyTaskQueue(string $queue): array
    {
        return [
            'task_queue' => $queue,
            'workflow_ready_tasks' => 0,
            'workflow_ready_due_tasks' => 0,
            'workflow_delayed_tasks' => 0,
            'workflow_leased_tasks' => 0,
            'workflow_expired_leases' => 0,
            'workflow_tasks_added_last_minute' => 0,
            'workflow_tasks_dispatched_last_minute' => 0,
            'activity_ready_tasks' => 0,
            'activity_ready_due_tasks' => 0,
            'activity_delayed_tasks' => 0,
            'activity_leased_tasks' => 0,
            'activity_expired_leases' => 0,
            'activity_tasks_added_last_minute' => 0,
            'activity_tasks_dispatched_last_minute' => 0,
            'active_pollers' => 0,
            'workflow_slot_capacity' => 0,
            'activity_slot_capacity' => 0,
            'available_workflow_slots' => 0,
            'available_activity_slots' => 0,
        ];
    }

    private function activityDurationSecondsExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => 'TIMESTAMPDIFF(MICROSECOND, activity_executions.started_at, activity_executions.closed_at) / 1000000.0',
            'pgsql' => 'EXTRACT(EPOCH FROM (activity_executions.closed_at - activity_executions.started_at))',
            default => '(julianday(activity_executions.closed_at) - julianday(activity_executions.started_at)) * 86400.0',
        };
    }

    private function workerStaleAfterSeconds(): int
    {
        $configured = config('server.workers.stale_after_seconds');
        $pollingTimeout = config('server.polling.timeout');

        return StandaloneWorkerVisibility::staleAfterSeconds(
            is_numeric($configured) ? (int) $configured : null,
            is_numeric($pollingTimeout) ? (int) $pollingTimeout : null,
        );
    }

    private function seriesLimit(string $configKey, int $default): int
    {
        $configured = config("server.metrics.{$configKey}", $default);
        $limit = is_numeric($configured) ? (int) $configured : $default;

        return max(1, min(self::MAX_SERIES_LIMIT, $limit));
    }

    /**
     * @return list<array{task_queue: string, workflow_type: string}>
     */
    private function workflowSeriesCandidates(string $namespace, int $limit): array
    {
        return DB::table('workflow_run_summaries')
            ->selectRaw("COALESCE(queue, 'default') as task_queue")
            ->selectRaw('workflow_type')
            ->distinct()
            ->where('namespace', $namespace)
            ->orderBy('task_queue')
            ->orderBy('workflow_type')
            ->limit($limit + 1)
            ->get()
            ->map(static fn (object $row): array => [
                'task_queue' => (string) $row->task_queue,
                'workflow_type' => (string) $row->workflow_type,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{task_queue: string, workflow_type: string, activity_type: string}>
     */
    private function activitySeriesCandidates(string $namespace, int $limit): array
    {
        return DB::table('activity_executions')
            ->join('workflow_runs', 'workflow_runs.id', '=', 'activity_executions.workflow_run_id')
            ->selectRaw("COALESCE(activity_executions.queue, workflow_runs.queue, 'default') as task_queue")
            ->selectRaw('workflow_runs.workflow_type as workflow_type')
            ->selectRaw('activity_executions.activity_type as activity_type')
            ->distinct()
            ->where('workflow_runs.namespace', $namespace)
            ->orderBy('task_queue')
            ->orderBy('workflow_type')
            ->orderBy('activity_type')
            ->limit($limit + 1)
            ->get()
            ->map(static fn (object $row): array => [
                'task_queue' => (string) $row->task_queue,
                'workflow_type' => (string) $row->workflow_type,
                'activity_type' => (string) $row->activity_type,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{task_queue: string, workflow_type: string}>  $keys
     */
    private function whereWorkflowSeriesIn($query, array $keys): void
    {
        if ($keys === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($query) use ($keys): void {
            foreach ($keys as $key) {
                $query->orWhere(function ($query) use ($key): void {
                    if ($key['task_queue'] === 'default') {
                        $query->where(function ($query): void {
                            $query->where('queue', 'default')
                                ->orWhereNull('queue');
                        });
                    } else {
                        $query->where('queue', $key['task_queue']);
                    }

                    $query->where('workflow_type', $key['workflow_type']);
                });
            }
        });
    }

    /**
     * @param  list<array{task_queue: string, workflow_type: string, activity_type: string}>  $keys
     */
    private function whereActivitySeriesIn($query, array $keys): void
    {
        if ($keys === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($query) use ($keys): void {
            foreach ($keys as $key) {
                $query->orWhere(function ($query) use ($key): void {
                    if ($key['task_queue'] === 'default') {
                        $query->where(function ($query): void {
                            $query->where('activity_executions.queue', 'default')
                                ->orWhere(function ($query): void {
                                    $query->whereNull('activity_executions.queue')
                                        ->where(function ($query): void {
                                            $query->where('workflow_runs.queue', 'default')
                                                ->orWhereNull('workflow_runs.queue');
                                        });
                                });
                        });
                    } else {
                        $query->where(function ($query) use ($key): void {
                            $query->where('activity_executions.queue', $key['task_queue'])
                                ->orWhere(function ($query) use ($key): void {
                                    $query->whereNull('activity_executions.queue')
                                        ->where('workflow_runs.queue', $key['task_queue']);
                                });
                        });
                    }

                    $query->where('workflow_runs.workflow_type', $key['workflow_type'])
                        ->where('activity_executions.activity_type', $key['activity_type']);
                });
            }
        });
    }

    private function workflowSeriesKey(string $taskQueue, string $workflowType): string
    {
        return $taskQueue."\x1F".$workflowType;
    }

    private function activitySeriesKey(string $taskQueue, string $workflowType, string $activityType): string
    {
        return $taskQueue."\x1F".$workflowType."\x1F".$activityType;
    }

    /**
     * @param  list<string>  $queues
     */
    private function whereQueueLabelIn($query, string $column, array $queues): void
    {
        if ($queues === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        foreach ($queues as $queue) {
            $query->orWhere(function ($query) use ($column, $queue): void {
                if ($queue === 'default') {
                    $query->where($column, 'default')
                        ->orWhereNull($column);

                    return;
                }

                $query->where($column, $queue);
            });
        }
    }

    /**
     * @param  array<string, mixed>  ...$snapshots
     */
    private function combinedPrecision(array ...$snapshots): string
    {
        foreach ($snapshots as $snapshot) {
            if (($snapshot['observed_series_count_precision'] ?? 'exact') !== 'exact') {
                return 'lower_bound';
            }
        }

        return 'exact';
    }

    /**
     * @return list<string>
     */
    private function taskQueueCandidates(string $namespace, CarbonInterface $now, int $limit): array
    {
        $workerTable = (new WorkerRegistration)->getTable();
        $staleCutoff = $now->copy()->subSeconds($this->workerStaleAfterSeconds());
        $reportingWindowStart = $now->copy()->subMinute();
        $taskQueues = DB::table('workflow_tasks')
            ->selectRaw("COALESCE(queue, 'default') as task_queue")
            ->distinct()
            ->where('namespace', $namespace)
            ->whereIn('task_type', [TaskType::Workflow->value, TaskType::Activity->value])
            ->where(function ($query) use ($reportingWindowStart): void {
                $this->whereTaskRuntimeVisible($query, $reportingWindowStart);
            })
            ->orderBy('task_queue')
            ->limit($limit + 1)
            ->pluck('task_queue')
            ->map(static fn (mixed $queue): string => (string) $queue)
            ->all();

        $workerQueues = DB::table($workerTable)
            ->selectRaw("COALESCE(task_queue, 'default') as task_queue")
            ->distinct()
            ->where('namespace', $namespace)
            ->where('status', 'active')
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '>=', $staleCutoff)
            ->orderBy('task_queue')
            ->limit($limit + 1)
            ->pluck('task_queue')
            ->map(static fn (mixed $queue): string => (string) $queue)
            ->all();

        $queues = array_values(array_unique([...$taskQueues, ...$workerQueues]));
        sort($queues, SORT_STRING);

        return array_slice($queues, 0, $limit + 1);
    }

    private function whereTaskRuntimeVisible($query, CarbonInterface $reportingWindowStart): void
    {
        $query->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->orWhere('created_at', '>=', $reportingWindowStart)
            ->orWhere(function ($query) use ($reportingWindowStart): void {
                $query->whereNotNull('last_dispatched_at')
                    ->where('last_dispatched_at', '>=', $reportingWindowStart);
            });
    }

    /**
     * @param  list<array<string, mixed>>  $series
     * @return array{
     *     series: list<array<string, mixed>>,
     *     limit: int,
     *     observed_series_count: int,
     *     observed_series_count_precision: string,
     *     suppressed_series_count: int,
     *     suppressed_started_total: int,
     *     suppressed_counts_precision: string,
     *     truncated: bool
     * }
     */
    private function seriesSnapshot(
        array $series,
        int $limit,
        int $observed,
        int $suppressedStartedTotal,
        bool $lowerBound,
    ): array
    {
        $precision = $lowerBound ? 'lower_bound' : 'exact';

        return [
            'series' => $series,
            'limit' => $limit,
            'observed_series_count' => $observed,
            'observed_series_count_precision' => $precision,
            'suppressed_series_count' => max(0, $observed - count($series)),
            'suppressed_started_total' => $suppressedStartedTotal,
            'suppressed_counts_precision' => $precision,
            'truncated' => $observed > count($series),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $labelDimensions
     * @return array<string, mixed>
     */
    private function seriesLimitDisclosure(array $snapshot, array $labelDimensions, string $selection): array
    {
        return [
            'label_dimensions' => $labelDimensions,
            'limit' => $snapshot['limit'],
            'observed_series_count' => $snapshot['observed_series_count'],
            'observed_series_count_precision' => $snapshot['observed_series_count_precision'],
            'reported_series_count' => count($snapshot['series']),
            'suppressed_series_count' => $snapshot['suppressed_series_count'],
            'suppressed_started_total' => $snapshot['suppressed_started_total'],
            'suppressed_counts_precision' => $snapshot['suppressed_counts_precision'],
            'truncated' => $snapshot['truncated'],
            'selection' => $selection,
        ];
    }

    /**
     * @param  array<string, mixed>  $workflows
     * @param  array<string, mixed>  $activities
     * @param  array<string, mixed>  $taskQueues
     * @return array<string, mixed>
     */
    private function metricLabelSets(array $workflows, array $activities, array $taskQueues): array
    {
        $workflowRuntime = [
            'task_queue' => [
                'limit' => $workflows['limit'],
                'selection' => 'bounded_task_queue_and_workflow_type_ascending',
            ],
            'workflow_type' => [
                'limit' => $workflows['limit'],
                'selection' => 'bounded_task_queue_and_workflow_type_ascending',
            ],
        ];
        $activityRuntime = [
            'task_queue' => [
                'limit' => $activities['limit'],
                'selection' => 'bounded_task_queue_workflow_type_and_activity_type_ascending',
            ],
            'workflow_type' => [
                'limit' => $activities['limit'],
                'selection' => 'bounded_task_queue_workflow_type_and_activity_type_ascending',
            ],
            'activity_type' => [
                'limit' => $activities['limit'],
                'selection' => 'bounded_task_queue_workflow_type_and_activity_type_ascending',
            ],
        ];

        return [
            self::WORKFLOW_RUNS_METRIC => BoundedMetricPolicy::labelSet(self::WORKFLOW_RUNS_METRIC, $workflowRuntime),
            self::WORKFLOW_RUN_LATENCY_METRIC => BoundedMetricPolicy::labelSet(self::WORKFLOW_RUN_LATENCY_METRIC, $workflowRuntime),
            self::ACTIVITY_EXECUTIONS_METRIC => BoundedMetricPolicy::labelSet(self::ACTIVITY_EXECUTIONS_METRIC, $activityRuntime),
            self::ACTIVITY_EXECUTION_LATENCY_METRIC => BoundedMetricPolicy::labelSet(self::ACTIVITY_EXECUTION_LATENCY_METRIC, $activityRuntime),
            self::TASK_QUEUE_RUNTIME_METRIC => BoundedMetricPolicy::labelSet(self::TASK_QUEUE_RUNTIME_METRIC, [
                'task_queue' => [
                    'limit' => $taskQueues['limit'],
                    'selection' => 'task_queue_name_ascending',
                ],
            ]),
        ];
    }
}
