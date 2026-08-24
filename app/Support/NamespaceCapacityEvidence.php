<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\WorkflowInboundStream;
use App\Models\WorkflowInboundStreamItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

final class NamespaceCapacityEvidence
{
    public const SCHEMA = 'durable-workflow.v2.namespace-capacity-evidence';

    public const VERSION = 2;

    public const CACHE_TTL_SECONDS = 30;

    /** @var list<int> */
    public const SUPPORTED_WINDOW_SECONDS = [60, 300, 900, 3600, 21600, 86400];

    /** @return array<string, mixed> */
    public function snapshot(string $namespace): array
    {
        $generatedAt = now();

        return Cache::remember(
            self::cacheKey($namespace),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->collect($namespace, $generatedAt),
        );
    }

    private static function cacheKey(string $namespace): string
    {
        return 'durable-workflow:operator:capacity-evidence:v'.self::VERSION.':'.hash('sha256', $namespace);
    }

    /** @return array<string, mixed> */
    private function collect(string $namespace, CarbonInterface $generatedAt): array
    {
        $windows = [];

        foreach (self::SUPPORTED_WINDOW_SECONDS as $duration) {
            $end = Carbon::instance($generatedAt->toDateTime());
            $start = $end->copy()->subSeconds($duration);
            $windows[(string) $duration] = $this->window($namespace, $start, $end, $duration);
        }

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::VERSION,
            'generated_at' => $generatedAt->toJSON(),
            'freshness' => [
                'strategy' => 'namespace_snapshot_cache',
                'max_age_seconds' => self::CACHE_TTL_SECONDS,
                'valid_until' => $generatedAt->copy()->addSeconds(self::CACHE_TTL_SECONDS)->toJSON(),
            ],
            'namespace' => $namespace,
            'supported_window_seconds' => self::SUPPORTED_WINDOW_SECONDS,
            'windows' => $windows,
            'cardinality' => [
                'bounded' => true,
                'dimensions' => ['namespace'],
                'prohibited_dimensions' => [
                    'workflow_id',
                    'run_id',
                    'task_id',
                    'worker_id',
                    'arbitrary_customer_label',
                ],
                'individual_execution_identifiers_included' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function window(
        string $namespace,
        CarbonInterface $start,
        CarbonInterface $end,
        int $duration,
    ): array {
        $runs = WorkflowRun::query()->where('namespace', $namespace);
        $tasks = WorkflowTask::query()->where('namespace', $namespace);
        $runIds = WorkflowRun::query()->where('namespace', $namespace)->select('id');
        $activities = ActivityExecution::query()->whereIn('workflow_run_id', clone $runIds);
        $attempts = ActivityAttempt::query()->whereIn('workflow_run_id', clone $runIds);
        $commands = WorkflowCommand::query()->whereIn('workflow_run_id', clone $runIds);
        $history = WorkflowHistoryEvent::query()->whereIn('workflow_run_id', clone $runIds);
        $timers = WorkflowTimer::query()->whereIn('workflow_run_id', clone $runIds);
        $messageStreams = WorkflowInboundStream::query()->where('namespace', $namespace);
        $messageStreamItems = WorkflowInboundStreamItem::query()->where('namespace', $namespace);

        $observationWindow = [
            'starts_at' => $start->toJSON(),
            'ends_at' => $end->toJSON(),
            'duration_seconds' => $duration,
        ];

        return [
            'observation_window' => $observationWindow,
            'runtime_evidence' => [
                'throughput' => [
                    'workflow_starts' => $this->windowCount($this->countBetween($runs, 'started_at', $start, $end)),
                    'workflow_completions' => $this->windowCount($this->countBetween(
                        (clone $runs)->where('status', 'completed'),
                        'closed_at',
                        $start,
                        $end,
                    )),
                    'activity_dispatches' => $this->windowCount($this->countBetween($attempts, 'started_at', $start, $end)),
                    'activity_completions' => $this->windowCount($this->countBetween(
                        (clone $activities)->where('status', 'completed'),
                        'closed_at',
                        $start,
                        $end,
                    )),
                    'timers_scheduled' => $this->windowCount($this->countBetween($timers, 'created_at', $start, $end)),
                    'timers_fired' => $this->windowCount($this->countBetween($timers, 'fired_at', $start, $end)),
                    'signals' => $this->windowCount($this->countBetween(
                        (clone $commands)->where('command_type', 'signal'),
                        'accepted_at',
                        $start,
                        $end,
                    )),
                    'queries' => $this->unavailable('query_telemetry_unavailable'),
                    'updates' => $this->windowCount($this->countBetween(
                        (clone $commands)->where('command_type', 'update'),
                        'accepted_at',
                        $start,
                        $end,
                    )),
                ],
                'latency' => [
                    'schedule_to_start' => $this->durationDistribution(
                        $activities,
                        'created_at',
                        'started_at',
                        'started_at',
                        $start,
                        $end,
                    ),
                    'execution' => $this->durationDistribution(
                        $runs,
                        'started_at',
                        'closed_at',
                        'closed_at',
                        $start,
                        $end,
                    ),
                    'replay' => $this->durationDistribution(
                        (clone $tasks)->where('task_type', 'workflow')->where('status', 'completed'),
                        'leased_at',
                        'updated_at',
                        'updated_at',
                        $start,
                        $end,
                    ),
                    'inspection' => $this->unavailable('inspection_telemetry_unavailable'),
                ],
                'growth' => [
                    'history_events' => $this->windowCount($this->countBetween($history, 'recorded_at', $start, $end)),
                    'history_payload_bytes' => $this->windowBytes($this->sumBytesBetween(
                        $history,
                        ['payload'],
                        'recorded_at',
                        $start,
                        $end,
                    )),
                    'durable_payload_bytes' => $this->windowBytes($this->durablePayloadBytes(
                        $runs,
                        $tasks,
                        $activities,
                        $commands,
                        $history,
                        $start,
                        $end,
                    )),
                    'message_stream_backlog_items' => $this->gauge((int) (clone $messageStreamItems)
                        ->whereNull('consumed_at')
                        ->count()),
                    'message_stream_persisted_bytes' => $this->gaugeBytes($this->sumCurrentBytes(
                        $messageStreamItems,
                        ['payload_blob'],
                    )),
                ],
                'reliability' => [
                    'retries' => $this->windowCount($this->countBetween(
                        (clone $attempts)->where('attempt_number', '>', 1),
                        'started_at',
                        $start,
                        $end,
                    )),
                    'timeouts' => $this->windowCount($this->countBetween(
                        (clone $attempts)->where('status', 'expired'),
                        'closed_at',
                        $start,
                        $end,
                    )),
                    'failures' => $this->windowCount(
                        $this->countBetween((clone $runs)->where('status', 'failed'), 'closed_at', $start, $end)
                        + $this->countBetween((clone $attempts)->where('status', 'failed'), 'closed_at', $start, $end)
                        + $this->countBetween((clone $tasks)->where('status', 'failed'), 'updated_at', $start, $end),
                    ),
                    'stale_heartbeats' => $this->gauge((int) (clone $activities)
                        ->where('status', 'running')
                        ->whereNotNull('heartbeat_deadline_at')
                        ->where('heartbeat_deadline_at', '<=', $end)
                        ->count()),
                    'overload_or_throttling' => $this->windowCount(
                        $this->countBetween(
                            (clone $tasks)->whereNotNull('last_dispatch_error'),
                            'last_dispatch_attempt_at',
                            $start,
                            $end,
                        ) + $this->countBetween(
                            (clone $tasks)->whereNotNull('last_claim_error'),
                            'last_claim_failed_at',
                            $start,
                            $end,
                        ),
                    ),
                    'message_stream_cleanup_blocked_instances' => $this->gauge((int) (clone $messageStreams)
                        ->whereNotNull('cleanup_blocked_at')
                        ->distinct()
                        ->count('workflow_instance_id')),
                ],
            ],
            'sustained_evidence' => [
                'observation_windows' => 1,
                'upgrade_breach_windows' => [],
                'downgrade_clear_windows' => [],
                'minimum_windows_required_for_recommendation' => 3,
            ],
        ];
    }

    private function countBetween(
        Builder $query,
        string $column,
        CarbonInterface $start,
        CarbonInterface $end,
    ): int {
        return (int) (clone $query)->whereBetween($column, [$start, $end])->count();
    }

    /** @return array<string, mixed> */
    private function durationDistribution(
        Builder $query,
        string $startedColumn,
        string $endedColumn,
        string $windowColumn,
        CarbonInterface $start,
        CarbonInterface $end,
    ): array {
        $eligible = (clone $query)
            ->whereNotNull($startedColumn)
            ->whereNotNull($endedColumn)
            ->whereBetween($windowColumn, [$start, $end]);
        $population = (int) (clone $eligible)->count();

        if ($population === 0) {
            return $this->unavailable('insufficient_samples');
        }

        $rows = $eligible->latest($windowColumn)->limit(10000)->get([$startedColumn, $endedColumn]);
        $samples = [];

        foreach ($rows as $row) {
            try {
                $from = $row->getAttribute($startedColumn);
                $to = $row->getAttribute($endedColumn);
                $from = $from instanceof CarbonInterface ? $from : Carbon::parse((string) $from);
                $to = $to instanceof CarbonInterface ? $to : Carbon::parse((string) $to);
                $samples[] = max(0.0, (float) $from->diffInMilliseconds($to));
            } catch (\Throwable) {
                continue;
            }
        }

        return $samples === []
            ? $this->unavailable('insufficient_samples')
            : [
                'available' => true,
                'samples_ms' => $samples,
                'population_count' => $population,
                'sample_limit' => 10000,
                'sample_truncated' => $population > count($samples),
                'source' => 'durable_workflow_service',
            ];
    }

    private function durablePayloadBytes(
        Builder $runs,
        Builder $tasks,
        Builder $activities,
        Builder $commands,
        Builder $history,
        CarbonInterface $start,
        CarbonInterface $end,
    ): int {
        return $this->sumBytesBetween($history, ['payload'], 'recorded_at', $start, $end)
            + $this->sumBytesBetween($tasks, ['payload'], 'created_at', $start, $end)
            + $this->sumBytesBetween($commands, ['payload'], 'created_at', $start, $end)
            + $this->sumBytesBetween($runs, ['arguments'], 'created_at', $start, $end)
            + $this->sumBytesBetween($runs, ['output'], 'closed_at', $start, $end)
            + $this->sumBytesBetween($activities, ['arguments'], 'created_at', $start, $end)
            + $this->sumBytesBetween($activities, ['result', 'exception'], 'closed_at', $start, $end);
    }

    /** @param list<string> $columns */
    private function sumBytesBetween(
        Builder $query,
        array $columns,
        string $windowColumn,
        CarbonInterface $start,
        CarbonInterface $end,
    ): int {
        $model = $query->getModel();
        $connection = $model->getConnection();
        $grammar = $connection->getQueryGrammar();
        $parts = [];

        foreach ($columns as $column) {
            $wrapped = $grammar->wrap($model->qualifyColumn($column));
            $parts[] = match ($connection->getDriverName()) {
                'pgsql' => "OCTET_LENGTH(COALESCE(CAST({$wrapped} AS TEXT), ''))",
                'sqlsrv' => "DATALENGTH(COALESCE({$wrapped}, ''))",
                default => "LENGTH(COALESCE({$wrapped}, ''))",
            };
        }

        $value = (clone $query)
            ->whereBetween($windowColumn, [$start, $end])
            ->selectRaw('COALESCE(SUM('.implode(' + ', $parts).'), 0) AS aggregate_bytes')
            ->value('aggregate_bytes');

        return max(0, (int) $value);
    }

    /** @param list<string> $columns */
    private function sumCurrentBytes(Builder $query, array $columns): int
    {
        $model = $query->getModel();
        $connection = $model->getConnection();
        $grammar = $connection->getQueryGrammar();
        $parts = [];

        foreach ($columns as $column) {
            $wrapped = $grammar->wrap($model->qualifyColumn($column));
            $parts[] = match ($connection->getDriverName()) {
                'pgsql' => "OCTET_LENGTH(COALESCE(CAST({$wrapped} AS TEXT), ''))",
                'sqlsrv' => "DATALENGTH(COALESCE({$wrapped}, ''))",
                default => "LENGTH(COALESCE({$wrapped}, ''))",
            };
        }

        $value = (clone $query)
            ->selectRaw('COALESCE(SUM('.implode(' + ', $parts).'), 0) AS aggregate_bytes')
            ->value('aggregate_bytes');

        return max(0, (int) $value);
    }

    /** @return array{available: true, value: int, unit: string, kind: string, source: string} */
    private function windowCount(int $value): array
    {
        return $this->measurement($value, 'count', 'window_count');
    }

    /** @return array{available: true, value: int, unit: string, kind: string, source: string} */
    private function windowBytes(int $value): array
    {
        return $this->measurement($value, 'bytes', 'window_count');
    }

    /** @return array{available: true, value: int, unit: string, kind: string, source: string} */
    private function gauge(int $value): array
    {
        return $this->measurement($value, 'count', 'gauge');
    }

    /** @return array{available: true, value: int, unit: string, kind: string, source: string} */
    private function gaugeBytes(int $value): array
    {
        return $this->measurement($value, 'bytes', 'gauge');
    }

    /** @return array{available: true, value: int, unit: string, kind: string, source: string} */
    private function measurement(int $value, string $unit, string $kind): array
    {
        return [
            'available' => true,
            'value' => max(0, $value),
            'unit' => $unit,
            'kind' => $kind,
            'source' => 'durable_workflow_service',
        ];
    }

    /** @return array{available: false, source: string, reason: string} */
    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'source' => 'not_available',
            'reason' => $reason,
        ];
    }
}
