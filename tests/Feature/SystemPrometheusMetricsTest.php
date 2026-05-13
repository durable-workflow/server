<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;

class SystemPrometheusMetricsTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-12 12:00:00');
        $this->createNamespace('default');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prometheus_metrics_summary_is_granular_by_queue_workflow_and_activity_type(): void
    {
        $runA = $this->workflowRun(
            workflowId: 'checkout-1',
            workflowType: 'CheckoutWorkflow',
            queue: 'checkout',
            status: 'completed',
            statusBucket: 'completed',
            durationMs: 1500,
        );
        $runB = $this->workflowRun(
            workflowId: 'checkout-2',
            workflowType: 'CheckoutWorkflow',
            queue: 'checkout',
            status: 'failed',
            statusBucket: 'failed',
            durationMs: 3000,
        );
        $this->workflowRun(
            workflowId: 'refund-1',
            workflowType: 'RefundWorkflow',
            queue: 'refunds',
            status: 'running',
            statusBucket: 'running',
        );

        $this->activity($runA, 'ChargeCard', 'checkout', 'completed', 700);
        $this->activity($runB, 'ChargeCard', 'checkout', 'failed', 2000);
        $this->task($runA, 'workflow', 'ready', 'checkout');
        $this->task($runA, 'activity', 'leased', 'checkout', leaseExpired: true);
        $this->task($runB, 'activity', 'ready', 'checkout', delayed: true);

        WorkerRegistration::query()->create([
            'worker_id' => 'worker-a',
            'namespace' => 'default',
            'task_queue' => 'checkout',
            'runtime' => 'php',
            'supported_workflow_types' => ['CheckoutWorkflow'],
            'supported_activity_types' => ['ChargeCard'],
            'max_concurrent_workflow_tasks' => 4,
            'max_concurrent_activity_tasks' => 8,
            'available_workflow_slots' => 3,
            'available_activity_slots' => 6,
            'last_heartbeat_at' => now(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson(
            '/api/system/prometheus-metrics',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $response->assertOk()
            ->assertJsonPath('schema', 'durable-workflow.prometheus-metrics.v1')
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('cardinality.series_limits.workflows.limit', 100)
            ->assertJsonPath('series.workflows.0.task_queue', 'checkout')
            ->assertJsonPath('series.workflows.0.workflow_type', 'CheckoutWorkflow')
            ->assertJsonPath('series.workflows.0.started_total', 2)
            ->assertJsonPath('series.workflows.0.completed_total', 1)
            ->assertJsonPath('series.workflows.0.failed_total', 1)
            ->assertJsonPath('series.workflows.0.latency_seconds.count', 2)
            ->assertJsonPath('series.activities.0.task_queue', 'checkout')
            ->assertJsonPath('series.activities.0.workflow_type', 'CheckoutWorkflow')
            ->assertJsonPath('series.activities.0.activity_type', 'ChargeCard')
            ->assertJsonPath('series.activities.0.started_total', 2)
            ->assertJsonPath('series.activities.0.failed_total', 1)
            ->assertJsonPath('series.activities.0.latency_seconds.count', 2)
            ->assertJsonPath('series.task_queues.0.task_queue', 'checkout')
            ->assertJsonPath('series.task_queues.0.workflow_ready_tasks', 1)
            ->assertJsonPath('series.task_queues.0.activity_leased_tasks', 1)
            ->assertJsonPath('series.task_queues.0.activity_expired_leases', 1)
            ->assertJsonPath('series.task_queues.0.activity_delayed_tasks', 1)
            ->assertJsonPath('series.task_queues.0.active_pollers', 1)
            ->assertJsonPath('series.task_queues.0.available_activity_slots', 6);
    }

    public function test_prometheus_workflow_failed_total_counts_only_raw_failed_status(): void
    {
        $this->workflowRun(
            workflowId: 'billing-failed',
            workflowType: 'BillingWorkflow',
            queue: 'billing',
            status: 'failed',
            statusBucket: 'failed',
            durationMs: 1000,
        );
        $this->workflowRun(
            workflowId: 'billing-cancelled',
            workflowType: 'BillingWorkflow',
            queue: 'billing',
            status: 'cancelled',
            statusBucket: 'failed',
            durationMs: 1200,
        );
        $this->workflowRun(
            workflowId: 'billing-terminated',
            workflowType: 'BillingWorkflow',
            queue: 'billing',
            status: 'terminated',
            statusBucket: 'failed',
            durationMs: 1400,
        );
        $this->workflowRun(
            workflowId: 'billing-completed',
            workflowType: 'BillingWorkflow',
            queue: 'billing',
            status: 'completed',
            statusBucket: 'completed',
            durationMs: 1600,
        );
        $this->workflowRun(
            workflowId: 'billing-running',
            workflowType: 'BillingWorkflow',
            queue: 'billing',
            status: 'running',
            statusBucket: 'running',
        );

        $response = $this->getJson(
            '/api/system/prometheus-metrics',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $response->assertOk()
            ->assertJsonCount(1, 'series.workflows')
            ->assertJsonPath('series.workflows.0.task_queue', 'billing')
            ->assertJsonPath('series.workflows.0.workflow_type', 'BillingWorkflow')
            ->assertJsonPath('series.workflows.0.started_total', 5)
            ->assertJsonPath('series.workflows.0.completed_total', 1)
            ->assertJsonPath('series.workflows.0.failed_total', 1)
            ->assertJsonPath('series.workflows.0.cancelled_total', 1)
            ->assertJsonPath('series.workflows.0.terminated_total', 1)
            ->assertJsonPath('series.workflows.0.running', 1);
    }

    public function test_prometheus_metrics_summary_enforces_bounded_series_limits_and_discloses_cardinality(): void
    {
        config([
            'server.metrics.prometheus_workflow_series_limit' => 1,
            'server.metrics.prometheus_activity_series_limit' => 1,
            'server.metrics.prometheus_task_queue_series_limit' => 1,
        ]);

        $checkoutA = $this->workflowRun(
            workflowId: 'checkout-a',
            workflowType: 'CheckoutWorkflow',
            queue: 'checkout',
            status: 'completed',
            statusBucket: 'completed',
            durationMs: 500,
        );
        $checkoutB = $this->workflowRun(
            workflowId: 'checkout-b',
            workflowType: 'CheckoutWorkflow',
            queue: 'checkout',
            status: 'completed',
            statusBucket: 'completed',
            durationMs: 700,
        );
        $refund = $this->workflowRun(
            workflowId: 'refund-a',
            workflowType: 'RefundWorkflow',
            queue: 'refunds',
            status: 'failed',
            statusBucket: 'failed',
            durationMs: 900,
        );

        $this->activity($checkoutA, 'ChargeCard', 'checkout', 'completed', 200);
        $this->activity($checkoutB, 'ChargeCard', 'checkout', 'completed', 300);
        $this->activity($refund, 'RefundPayment', 'refunds', 'failed', 400);
        $this->task($checkoutA, 'workflow', 'ready', 'checkout');
        $this->task($refund, 'workflow', 'ready', 'refunds');

        $response = $this->getJson(
            '/api/system/prometheus-metrics',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $response->assertOk()
            ->assertJsonCount(1, 'series.workflows')
            ->assertJsonCount(1, 'series.activities')
            ->assertJsonCount(1, 'series.task_queues')
            ->assertJsonPath('series.workflows.0.task_queue', 'checkout')
            ->assertJsonPath('series.workflows.0.workflow_type', 'CheckoutWorkflow')
            ->assertJsonPath('series.workflows.0.started_total', 2)
            ->assertJsonPath('series.activities.0.activity_type', 'ChargeCard')
            ->assertJsonPath('series.activities.0.started_total', 2)
            ->assertJsonPath('series.task_queues.0.task_queue', 'checkout')
            ->assertJsonPath('series_count', 3)
            ->assertJsonPath('observed_series_count', 6)
            ->assertJsonPath('suppressed_series_count', 3)
            ->assertJsonPath('cardinality.series_limits.workflows.observed_series_count', 2)
            ->assertJsonPath('cardinality.series_limits.workflows.reported_series_count', 1)
            ->assertJsonPath('cardinality.series_limits.workflows.suppressed_series_count', 1)
            ->assertJsonPath('cardinality.series_limits.workflows.suppressed_started_total', 1)
            ->assertJsonPath('cardinality.series_limits.workflows.truncated', true)
            ->assertJsonPath('cardinality.series_limits.activities.observed_series_count', 2)
            ->assertJsonPath('cardinality.series_limits.activities.suppressed_series_count', 1)
            ->assertJsonPath('cardinality.series_limits.activities.suppressed_started_total', 1)
            ->assertJsonPath('cardinality.series_limits.task_queues.observed_series_count', 2)
            ->assertJsonPath('cardinality.series_limits.task_queues.suppressed_series_count', 1)
            ->assertJsonPath('cardinality.metric_label_sets.dw_activity_executions_total.activity_type.limit', 1)
            ->assertJsonPath('cardinality.metric_label_sets.dw_task_queue_runtime_state.task_queue.limit', 1);

        $policy = require base_path('config/dw-bounded-growth.php');
        $prometheusMetrics = [];

        foreach (($policy['metrics'] ?? []) as $metric => $entry) {
            if (($entry['surface'] ?? null) === 'GET /api/system/prometheus-metrics') {
                $prometheusMetrics[] = $metric;
            }
        }

        sort($prometheusMetrics);

        $responseMetrics = array_keys($response->json('cardinality.metric_label_sets'));
        sort($responseMetrics);

        $this->assertSame(
            $prometheusMetrics,
            $responseMetrics,
            'Every /api/system/prometheus-metrics metric must expose bounded cardinality disclosure.',
        );

        foreach ($prometheusMetrics as $metric) {
            $declaredDimensions = array_keys($policy['metrics'][$metric]['dimensions'] ?? []);
            sort($declaredDimensions);

            $runtimeDimensions = array_keys($response->json("cardinality.metric_label_sets.{$metric}"));
            sort($runtimeDimensions);

            $this->assertSame(
                $declaredDimensions,
                $runtimeDimensions,
                "{$metric} runtime label-set disclosure must match the bounded-growth policy dimensions.",
            );
        }
    }

    public function test_prometheus_metrics_summary_discloses_lower_bounds_after_bounded_candidate_scan(): void
    {
        config([
            'server.metrics.prometheus_workflow_series_limit' => 2,
            'server.metrics.prometheus_activity_series_limit' => 2,
            'server.metrics.prometheus_task_queue_series_limit' => 2,
        ]);

        foreach (range(1, 5) as $index) {
            $queue = sprintf('queue-%02d', $index);
            $workflowType = sprintf('Workflow%02d', $index);
            $activityType = sprintf('Activity%02d', $index);
            $runId = $this->workflowRun(
                workflowId: sprintf('workflow-%02d', $index),
                workflowType: $workflowType,
                queue: $queue,
                status: 'completed',
                statusBucket: 'completed',
                durationMs: 1000 + $index,
            );

            $this->activity($runId, $activityType, $queue, 'completed', 100 + $index);
            $this->task($runId, 'workflow', 'ready', $queue);
        }

        $response = $this->getJson(
            '/api/system/prometheus-metrics',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $response->assertOk()
            ->assertJsonCount(2, 'series.workflows')
            ->assertJsonCount(2, 'series.activities')
            ->assertJsonCount(2, 'series.task_queues')
            ->assertJsonPath('series_count', 6)
            ->assertJsonPath('observed_series_count', 9)
            ->assertJsonPath('observed_series_count_precision', 'lower_bound')
            ->assertJsonPath('suppressed_series_count', 3)
            ->assertJsonPath('suppressed_series_count_precision', 'lower_bound')
            ->assertJsonPath('cardinality.series_limits.workflows.observed_series_count', 3)
            ->assertJsonPath('cardinality.series_limits.workflows.observed_series_count_precision', 'lower_bound')
            ->assertJsonPath('cardinality.series_limits.workflows.reported_series_count', 2)
            ->assertJsonPath('cardinality.series_limits.workflows.suppressed_series_count', 1)
            ->assertJsonPath('cardinality.series_limits.workflows.suppressed_counts_precision', 'lower_bound')
            ->assertJsonPath('cardinality.series_limits.workflows.suppressed_started_total', 1)
            ->assertJsonPath('cardinality.series_limits.activities.observed_series_count', 3)
            ->assertJsonPath('cardinality.series_limits.activities.suppressed_started_total', 1)
            ->assertJsonPath('cardinality.series_limits.task_queues.observed_series_count', 3)
            ->assertJsonPath('cardinality.series_limits.task_queues.suppressed_counts_precision', 'lower_bound');

        $this->assertSame(
            ['queue-01', 'queue-02'],
            array_column($response->json('series.workflows'), 'task_queue'),
        );
        $this->assertSame(
            ['queue-01', 'queue-02'],
            array_column($response->json('series.activities'), 'task_queue'),
        );
        $this->assertSame(
            ['queue-01', 'queue-02'],
            array_column($response->json('series.task_queues'), 'task_queue'),
        );
    }

    public function test_task_queue_runtime_inventory_ignores_stale_historical_tasks_before_applying_limit(): void
    {
        config([
            'server.metrics.prometheus_task_queue_series_limit' => 3,
        ]);

        $old = now()->subHours(2);
        $staleWorkflow = $this->workflowRun(
            workflowId: 'stale-workflow',
            workflowType: 'StaleWorkflow',
            queue: 'aaa-archive',
            status: 'completed',
            statusBucket: 'completed',
        );
        $staleActivity = $this->workflowRun(
            workflowId: 'stale-activity',
            workflowType: 'StaleActivity',
            queue: 'aab-archive',
            status: 'failed',
            statusBucket: 'failed',
        );
        $recent = $this->workflowRun(
            workflowId: 'recent-finished',
            workflowType: 'RecentWorkflow',
            queue: 'recent-finished',
            status: 'completed',
            statusBucket: 'completed',
        );
        $active = $this->workflowRun(
            workflowId: 'active-work',
            workflowType: 'ActiveWorkflow',
            queue: 'z-live',
            status: 'running',
            statusBucket: 'running',
        );

        $this->task($staleWorkflow, 'workflow', 'completed', 'aaa-archive', createdAt: $old, lastDispatchedAt: $old);
        $this->task($staleActivity, 'activity', 'failed', 'aab-archive', createdAt: $old, lastDispatchedAt: $old);
        $this->task(
            $recent,
            'workflow',
            'completed',
            'recent-finished',
            createdAt: now()->subSeconds(30),
            lastDispatchedAt: now()->subSeconds(15),
        );
        $this->task($active, 'workflow', 'ready', 'z-live', createdAt: $old);

        WorkerRegistration::query()->create([
            'worker_id' => 'poller-only-worker',
            'namespace' => 'default',
            'task_queue' => 'poller-only',
            'runtime' => 'php',
            'supported_workflow_types' => ['PollerOnlyWorkflow'],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 2,
            'max_concurrent_activity_tasks' => 0,
            'available_workflow_slots' => 1,
            'available_activity_slots' => 0,
            'last_heartbeat_at' => now(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson(
            '/api/system/prometheus-metrics',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $response->assertOk()
            ->assertJsonPath('cardinality.series_limits.task_queues.observed_series_count', 3)
            ->assertJsonPath('cardinality.series_limits.task_queues.suppressed_series_count', 0);

        $queues = $response->json('series.task_queues');
        $this->assertSame(
            ['poller-only', 'recent-finished', 'z-live'],
            array_column($queues, 'task_queue'),
        );

        $byQueue = collect($queues)->keyBy('task_queue');

        $this->assertSame(1, $byQueue->get('poller-only')['active_pollers']);
        $this->assertSame(1, $byQueue->get('recent-finished')['workflow_tasks_added_last_minute']);
        $this->assertSame(1, $byQueue->get('recent-finished')['workflow_tasks_dispatched_last_minute']);
        $this->assertSame(1, $byQueue->get('z-live')['workflow_ready_tasks']);
        $this->assertFalse($byQueue->has('aaa-archive'));
        $this->assertFalse($byQueue->has('aab-archive'));
    }

    public function test_task_queue_runtime_aggregation_filters_stale_historical_rows(): void
    {
        config([
            'server.metrics.prometheus_task_queue_series_limit' => 1,
        ]);

        $old = now()->subHours(2);
        $active = $this->workflowRun(
            workflowId: 'active-work',
            workflowType: 'ActiveWorkflow',
            queue: 'z-live',
            status: 'running',
            statusBucket: 'running',
        );
        $staleCompleted = $this->workflowRun(
            workflowId: 'stale-completed-same-queue',
            workflowType: 'StaleWorkflow',
            queue: 'z-live',
            status: 'completed',
            statusBucket: 'completed',
        );
        $staleFailed = $this->workflowRun(
            workflowId: 'stale-failed-same-queue',
            workflowType: 'StaleWorkflow',
            queue: 'z-live',
            status: 'failed',
            statusBucket: 'failed',
        );

        $this->task($active, 'workflow', 'ready', 'z-live', createdAt: $old);
        $this->task($staleCompleted, 'workflow', 'completed', 'z-live', createdAt: $old, lastDispatchedAt: $old);
        $this->task($staleFailed, 'activity', 'failed', 'z-live', createdAt: $old, lastDispatchedAt: $old);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = (string) $query->sql;
        });

        $response = $this->getJson(
            '/api/system/prometheus-metrics',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $response->assertOk()
            ->assertJsonCount(1, 'series.task_queues')
            ->assertJsonPath('series.task_queues.0.task_queue', 'z-live')
            ->assertJsonPath('series.task_queues.0.workflow_ready_tasks', 1)
            ->assertJsonPath('series.task_queues.0.workflow_tasks_added_last_minute', 0)
            ->assertJsonPath('series.task_queues.0.workflow_tasks_dispatched_last_minute', 0)
            ->assertJsonPath('series.task_queues.0.activity_tasks_added_last_minute', 0)
            ->assertJsonPath('series.task_queues.0.activity_tasks_dispatched_last_minute', 0);

        $taskAggregationQueries = array_values(array_filter($queries, static function (string $sql): bool {
            $normalized = str_replace(['"', '`'], '', strtolower($sql));

            return str_contains($normalized, 'from workflow_tasks')
                && str_contains($normalized, 'group by coalesce(queue');
        }));

        $this->assertNotEmpty($taskAggregationQueries, 'Task-queue runtime scrape must aggregate workflow_tasks.');

        $whereClause = Str::after(
            str_replace(['"', '`'], '', strtolower($taskAggregationQueries[0])),
            ' where ',
        );

        $this->assertMatchesRegularExpression(
            '/task_type\s+in.*status\s+in.*created_at\s+>=.*last_dispatched_at\s+is\s+not\s+null.*last_dispatched_at\s+>=/s',
            $whereClause,
            'Task-queue runtime aggregation must filter to active or recent task rows before grouping.',
        );
    }

    private function workflowRun(
        string $workflowId,
        string $workflowType,
        string $queue,
        string $status,
        string $statusBucket,
        ?int $durationMs = null,
    ): string {
        $runId = (string) Str::ulid();
        $startedAt = now()->subSeconds(30);
        $closedAt = $durationMs === null ? null : $startedAt->copy()->addMilliseconds($durationMs);

        DB::table('workflow_runs')->insert([
            'id' => $runId,
            'workflow_instance_id' => $workflowId,
            'run_number' => 1,
            'workflow_class' => $workflowType,
            'workflow_type' => $workflowType,
            'namespace' => 'default',
            'status' => $status,
            'queue' => $queue,
            'started_at' => $startedAt,
            'closed_at' => $closedAt,
            'created_at' => $startedAt,
            'updated_at' => $closedAt ?? now(),
        ]);

        DB::table('workflow_run_summaries')->insert([
            'id' => $runId,
            'workflow_instance_id' => $workflowId,
            'run_number' => 1,
            'class' => $workflowType,
            'workflow_type' => $workflowType,
            'namespace' => 'default',
            'status' => $status,
            'status_bucket' => $statusBucket,
            'queue' => $queue,
            'started_at' => $startedAt,
            'closed_at' => $closedAt,
            'duration_ms' => $durationMs,
            'sort_timestamp' => $startedAt,
            'created_at' => $startedAt,
            'updated_at' => $closedAt ?? now(),
        ]);

        return $runId;
    }

    private function activity(string $runId, string $activityType, string $queue, string $status, int $durationMs): void
    {
        $startedAt = now()->subSeconds(10);

        DB::table('activity_executions')->insert([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $runId,
            'sequence' => 1,
            'activity_class' => $activityType,
            'activity_type' => $activityType,
            'status' => $status,
            'queue' => $queue,
            'started_at' => $startedAt,
            'closed_at' => $startedAt->copy()->addMilliseconds($durationMs),
            'created_at' => $startedAt,
            'updated_at' => $startedAt->copy()->addMilliseconds($durationMs),
        ]);
    }

    private function task(
        string $runId,
        string $taskType,
        string $status,
        string $queue,
        bool $delayed = false,
        bool $leaseExpired = false,
        ?CarbonInterface $createdAt = null,
        ?CarbonInterface $lastDispatchedAt = null,
    ): void {
        $createdAt ??= now()->subSeconds(20);
        $lastDispatchedAt ??= $status === 'leased' ? now()->subSeconds(10) : null;

        DB::table('workflow_tasks')->insert([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $runId,
            'namespace' => 'default',
            'task_type' => $taskType,
            'status' => $status,
            'queue' => $queue,
            'available_at' => $delayed ? now()->addMinute() : now()->subSecond(),
            'lease_expires_at' => $leaseExpired ? now()->subSecond() : null,
            'last_dispatched_at' => $lastDispatchedAt,
            'created_at' => $createdAt,
            'updated_at' => $lastDispatchedAt ?? $createdAt,
        ]);
    }
}
