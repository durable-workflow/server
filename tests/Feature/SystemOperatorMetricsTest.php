<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowInboundStream;
use App\Models\WorkflowInboundStreamItem;
use App\Support\ControlPlaneProtocol;
use App\Support\NamespaceCapacityEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class SystemOperatorMetricsTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
        $this->createNamespace('other');
        Cache::flush();
        WorkerCompatibilityFleet::clear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        WorkerCompatibilityFleet::clear();

        parent::tearDown();
    }

    public function test_operator_metrics_returns_full_snapshot_with_rollout_safety_keys(): void
    {
        $response = $this->getJson(
            '/api/system/operator-metrics',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $response->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('operator_metrics.backlog.tasks_added_last_minute', 0)
            ->assertJsonPath('operator_metrics.backlog.tasks_dispatched_last_minute', 0)
            ->assertJsonStructure([
                'namespace',
                'operator_metrics' => [
                    'generated_at',
                    'runs' => ['repair_needed', 'claim_failed', 'compatibility_blocked'],
                    'tasks' => [
                        'ready',
                        'ready_due',
                        'delayed',
                        'leased',
                        'dispatch_failed',
                        'claim_failed',
                        'dispatch_overdue',
                        'lease_expired',
                        'unhealthy',
                    ],
                    'backlog' => [
                        'runnable_tasks',
                        'delayed_tasks',
                        'leased_tasks',
                        'tasks_added_last_minute',
                        'tasks_dispatched_last_minute',
                        'unhealthy_tasks',
                        'repair_needed_runs',
                        'claim_failed_runs',
                        'compatibility_blocked_runs',
                    ],
                    'repair' => [
                        'missing_task_candidates',
                        'selected_missing_task_candidates',
                        'oldest_missing_run_started_at',
                        'max_missing_run_age_ms',
                    ],
                    'workers' => [
                        'required_compatibility',
                        'active_workers',
                        'active_worker_scopes',
                        'active_workers_supporting_required',
                        'fleet',
                    ],
                    'backend' => ['supported', 'issues'],
                    'structural_limits',
                    'repair_policy' => [
                        'redispatch_after_seconds',
                        'loop_throttle_seconds',
                        'scan_limit',
                        'failure_backoff_max_seconds',
                    ],
                ],
            ]);

        $this->assertIsArray($response->json('operator_metrics.workers.fleet'));
    }

    public function test_operator_metrics_fleet_entries_carry_full_per_scope_shape(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');

        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);
        config()->set('workflows.v2.compatibility.namespace', 'default');

        WorkerCompatibilityFleet::record(['build-a'], 'redis', 'default', 'worker-a');
        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'imports', 'worker-b');

        $response = $this->getJson(
            '/api/system/operator-metrics',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $response->assertOk();

        $fleet = $response->json('operator_metrics.workers.fleet');
        $this->assertIsArray($fleet);
        $this->assertCount(2, $fleet);

        $byWorker = [];
        foreach ($fleet as $entry) {
            $byWorker[$entry['worker_id']] = $entry;
        }

        $this->assertArrayHasKey('worker-a', $byWorker);
        $this->assertArrayHasKey('worker-b', $byWorker);

        $workerA = $byWorker['worker-a'];
        $this->assertSame(['build-a'], $workerA['supported']);
        $this->assertTrue($workerA['supports_required']);
        $this->assertSame('redis', $workerA['connection']);
        $this->assertSame('default', $workerA['queue']);
        $this->assertSame('default', $workerA['namespace']);
        $this->assertArrayHasKey('recorded_at', $workerA);
        $this->assertArrayHasKey('expires_at', $workerA);
        $this->assertArrayHasKey('source', $workerA);
        $this->assertArrayHasKey('host', $workerA);
        $this->assertArrayHasKey('process_id', $workerA);

        $workerB = $byWorker['worker-b'];
        $this->assertSame(['build-b'], $workerB['supported']);
        $this->assertFalse($workerB['supports_required']);
        $this->assertSame('imports', $workerB['queue']);

        $this->assertSame(2, $response->json('operator_metrics.workers.active_workers'));
        $this->assertSame(2, $response->json('operator_metrics.workers.active_worker_scopes'));
        $this->assertSame(1, $response->json('operator_metrics.workers.active_workers_supporting_required'));
        $this->assertSame('build-a', $response->json('operator_metrics.workers.required_compatibility'));
    }

    public function test_operator_metrics_scopes_to_namespace_header(): void
    {
        $response = $this->getJson(
            '/api/system/operator-metrics',
            $this->controlPlaneHeadersWithWorkerProtocol('other'),
        );

        $response->assertOk()
            ->assertJsonPath('namespace', 'other')
            ->assertJsonPath('operator_metrics.runs.total', 0);
    }

    public function test_operator_metrics_publishes_exact_namespace_capacity_windows_without_execution_ids(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');

        WorkflowRun::query()->create([
            'id' => 'capacity-default-run',
            'workflow_instance_id' => (string) Str::ulid(),
            'run_number' => 1,
            'workflow_class' => 'Tests\\Fixtures\\CapacityWorkflow',
            'workflow_type' => 'tests.capacity',
            'namespace' => 'default',
            'status' => 'completed',
            'arguments' => json_encode(['bounded' => true], JSON_THROW_ON_ERROR),
            'output' => json_encode(['ok' => true], JSON_THROW_ON_ERROR),
            'started_at' => now()->subMinutes(4),
            'closed_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(3),
        ]);
        WorkflowRun::query()->create([
            'id' => 'capacity-other-run',
            'workflow_instance_id' => (string) Str::ulid(),
            'run_number' => 1,
            'workflow_class' => 'Tests\\Fixtures\\CapacityWorkflow',
            'workflow_type' => 'tests.capacity',
            'namespace' => 'other',
            'status' => 'completed',
            'started_at' => now()->subMinutes(4),
            'closed_at' => now()->subMinutes(3),
        ]);
        $messageStream = WorkflowInboundStream::query()->create([
            'namespace' => 'default',
            'workflow_instance_id' => 'capacity-stream-instance',
            'stream_name' => 'orders',
            'last_position' => 2,
            'cursor_position' => 1,
            'cleanup_blocked_at' => now()->subMinute(),
            'cleanup_blocked_reason' => 'external_payload_storage_driver_unavailable',
            'cleanup_blocked_run_id' => 'capacity-blocked-run',
        ]);
        foreach ([
            [1, 'message-1', 'consumed-bytes', now()->subMinute()],
            [2, 'message-2', 'pending-bytes', null],
        ] as [$position, $messageId, $payload, $consumedAt]) {
            WorkflowInboundStreamItem::query()->create([
                'stream_id' => $messageStream->id,
                'namespace' => 'default',
                'workflow_instance_id' => 'capacity-stream-instance',
                'stream_name' => 'orders',
                'message_id' => $messageId,
                'position' => $position,
                'payload_codec' => 'avro',
                'payload_blob' => $payload,
                'payload_hash' => hash('sha256', "avro\0".$payload),
                'consumed_at' => $consumedAt,
            ]);
        }

        $response = $this->getJson(
            '/api/system/operator-metrics',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $response->assertOk()
            ->assertJsonPath('operator_metrics.capacity_evidence.schema', NamespaceCapacityEvidence::SCHEMA)
            ->assertJsonPath('operator_metrics.capacity_evidence.schema_version', NamespaceCapacityEvidence::VERSION)
            ->assertJsonPath('operator_metrics.capacity_evidence.namespace', 'default')
            ->assertJsonPath('operator_metrics.capacity_evidence.freshness.max_age_seconds', NamespaceCapacityEvidence::CACHE_TTL_SECONDS)
            ->assertJsonPath('operator_metrics.capacity_evidence.freshness.valid_until', '2026-08-12T12:00:30.000000Z')
            ->assertJsonPath('operator_metrics.capacity_evidence.supported_window_seconds', NamespaceCapacityEvidence::SUPPORTED_WINDOW_SECONDS)
            ->assertJsonPath('operator_metrics.capacity_evidence.windows.300.observation_window.duration_seconds', 300)
            ->assertJsonPath('operator_metrics.capacity_evidence.windows.300.runtime_evidence.throughput.workflow_starts.value', 1)
            ->assertJsonPath('operator_metrics.capacity_evidence.windows.300.runtime_evidence.throughput.workflow_completions.value', 1)
            ->assertJsonPath('operator_metrics.capacity_evidence.windows.300.runtime_evidence.latency.execution.available', true)
            ->assertJsonPath('operator_metrics.capacity_evidence.windows.300.runtime_evidence.growth.durable_payload_bytes.available', true)
            ->assertJsonPath('operator_metrics.capacity_evidence.windows.300.runtime_evidence.growth.message_stream_backlog_items.value', 1)
            ->assertJsonPath(
                'operator_metrics.capacity_evidence.windows.300.runtime_evidence.growth.message_stream_persisted_bytes.value',
                strlen('consumed-bytes') + strlen('pending-bytes'),
            )
            ->assertJsonPath('operator_metrics.capacity_evidence.windows.300.runtime_evidence.reliability.failures.value', 0)
            ->assertJsonPath(
                'operator_metrics.capacity_evidence.windows.300.runtime_evidence.reliability.message_stream_cleanup_blocked_instances.value',
                1,
            )
            ->assertJsonPath('operator_metrics.capacity_evidence.windows.300.sustained_evidence.observation_windows', 1)
            ->assertJsonPath('operator_metrics.capacity_evidence.cardinality.individual_execution_identifiers_included', false);

        $window = $response->json('operator_metrics.capacity_evidence.windows.300.observation_window');
        $this->assertSame(300, (int) Carbon::parse($window['starts_at'])->diffInSeconds(Carbon::parse($window['ends_at'])));

        $windows = $response->json('operator_metrics.capacity_evidence.windows');
        $this->assertCount(count(NamespaceCapacityEvidence::SUPPORTED_WINDOW_SECONDS), $windows);
        foreach (NamespaceCapacityEvidence::SUPPORTED_WINDOW_SECONDS as $duration) {
            $observation = $windows[(string) $duration]['observation_window'];
            $this->assertSame($duration, $observation['duration_seconds']);
            $this->assertSame(
                $duration,
                (int) Carbon::parse($observation['starts_at'])->diffInSeconds(Carbon::parse($observation['ends_at'])),
            );
        }

        foreach ([
            'throughput' => [
                'workflow_starts',
                'workflow_completions',
                'activity_dispatches',
                'activity_completions',
                'timers_scheduled',
                'timers_fired',
                'signals',
                'queries',
                'updates',
            ],
            'latency' => ['schedule_to_start', 'execution', 'replay', 'inspection'],
            'growth' => [
                'history_events',
                'history_payload_bytes',
                'durable_payload_bytes',
                'message_stream_backlog_items',
                'message_stream_persisted_bytes',
            ],
            'reliability' => [
                'retries',
                'timeouts',
                'failures',
                'stale_heartbeats',
                'overload_or_throttling',
                'message_stream_cleanup_blocked_instances',
            ],
        ] as $category => $dimensions) {
            $this->assertSame(
                $dimensions,
                array_keys($windows['300']['runtime_evidence'][$category]),
            );
        }

        $capacityEvidence = $response->json('operator_metrics.capacity_evidence');
        $encoded = json_encode($capacityEvidence, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('capacity-default-run', $encoded);
        $this->assertStringNotContainsString('capacity-other-run', $encoded);

        $keys = [];
        array_walk_recursive($capacityEvidence, static function (mixed $_value, string|int $key) use (&$keys): void {
            $keys[] = $key;
        });
        $this->assertSame([], array_values(array_intersect(
            ['workflow_id', 'run_id', 'task_id', 'worker_id'],
            $keys,
        )));
    }

    public function test_capacity_evidence_reuses_the_namespace_snapshot_inside_its_freshness_bound(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $collector = app(NamespaceCapacityEvidence::class);

        DB::enableQueryLog();
        $first = $collector->snapshot('default');
        $firstCollectionQueries = count(DB::getQueryLog());
        $this->assertGreaterThan(0, $firstCollectionQueries);

        DB::flushQueryLog();
        Carbon::setTestNow('2026-08-12 12:00:05');
        $second = $collector->snapshot('default');

        $this->assertSame([], DB::getQueryLog());
        $this->assertSame($first, $second);
        $this->assertSame(30, $second['freshness']['max_age_seconds']);
        $this->assertSame('2026-08-12T12:00:30.000000Z', $second['freshness']['valid_until']);
    }

    public function test_operator_dashboard_exposes_the_shared_workflow_summary(): void
    {
        $response = $this->getJson(
            '/api/system/operator-dashboard',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $response->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('dashboard.flows', 0)
            ->assertJsonStructure([
                'dashboard' => [
                    'flows',
                    'flows_per_minute',
                    'flows_past_hour',
                    'exceptions_past_hour',
                    'failed_flows_past_week',
                    'fleet_overview',
                    'workflow_type_health',
                    'needs_attention',
                    'fleet_trends_series',
                    'operator_metrics',
                ],
            ]);
    }

    public function test_operator_metrics_requires_control_plane_version_header(): void
    {
        $this->getJson('/api/system/operator-metrics', [
            'X-Namespace' => 'default',
        ])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'missing_control_plane_version');
    }

    public function test_dashboard_counts_utc_projections_in_a_local_application(): void
    {
        $originalTimezone = date_default_timezone_get();
        config(['app.timezone' => 'Europe/Kyiv']);
        date_default_timezone_set('Europe/Kyiv');
        $now = Carbon::parse('2026-10-07T07:42:00Z')->setTimezone('Europe/Kyiv');
        Carbon::setTestNow($now);

        try {
            foreach ([-1, 0, 1] as $seconds) {
                $started = $now->copy()->utc()->subHour()->addSeconds($seconds);
                WorkflowRunSummary::query()->create([
                    'id' => 'timezone-'.$seconds, 'workflow_instance_id' => 'timezone-'.$seconds,
                    'namespace' => 'default', 'workflow_type' => 'dashboard.test',
                    'class' => 'Dashboard', 'run_number' => 1, 'status' => 'completed', 'status_bucket' => 'completed',
                    'sort_timestamp' => $started, 'closed_at' => $started->copy()->setTimezone('Europe/Kyiv'),
                ]);
            }
            $this->getJson('/api/system/operator-dashboard/bounded', $this->controlPlaneHeadersWithWorkerProtocol())
                ->assertOk()->assertJsonPath('dashboard.flows_past_hour', 2);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_dashboard_does_not_delete_expired_worker_heartbeats(): void
    {
        foreach (['expired' => -60, 'active' => 60] as $worker => $offset) {
            DB::table('workflow_worker_compatibility_heartbeats')->insert([
                'worker_id' => $worker, 'scope_key' => $worker, 'namespace' => 'default',
                'supported' => '["build"]', 'recorded_at' => now(), 'expires_at' => now()->addSeconds($offset),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::statement('PRAGMA query_only = ON');
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->getJson('/api/system/operator-dashboard/bounded', $this->controlPlaneHeadersWithWorkerProtocol())
                ->assertOk()->assertJsonPath('dashboard.operator_metrics.workers.active_workers', 1);
            $this->assertSame(2, DB::table('workflow_worker_compatibility_heartbeats')->count());
            $writes = array_filter(DB::getQueryLog(), static fn (array $query): bool =>
                preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query']) === 1);
            $this->assertSame([], $writes);
        } finally {
            DB::statement('PRAGMA query_only = OFF');
            DB::disableQueryLog();
        }
    }

    public function test_bounded_dashboard_defers_history_audits_without_claiming_zero_drift(): void
    {
        $this->getJson('/api/system/operator-dashboard/bounded', $this->controlPlaneHeadersWithWorkerProtocol())
            ->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('dashboard.flows', 0)
            ->assertJsonPath('dashboard.operator_metrics.history_audit_evaluation', 'not_requested')
            ->assertJsonPath('dashboard.operator_metrics.capacity_evidence.namespace', 'default')
            ->assertJsonPath('dashboard.operator_metrics.capacity_evidence.cardinality.bounded', true)
            ->assertJsonPath('dashboard.operator_metrics.command_contracts.backfill_needed_runs', null)
            ->assertJsonPath('dashboard.operator_metrics.projections.run_waits.needs_rebuild', null)
            ->assertJsonPath('dashboard.operator_metrics.projections.run_timeline_entries.needs_rebuild', null)
            ->assertJsonPath('dashboard.operator_metrics.projections.run_timer_entries.needs_rebuild', null)
            ->assertJsonPath('dashboard.operator_metrics.projections.run_lineage_entries.needs_rebuild', null);

        $this->getJson('/api/system/operator-dashboard', $this->controlPlaneHeadersWithWorkerProtocol())
            ->assertOk()
            ->assertJsonMissingPath('dashboard.operator_metrics.history_audit_evaluation')
            ->assertJsonPath('dashboard.operator_metrics.projections.run_waits.needs_rebuild', 0);
    }

    public function test_workflow_type_dashboard_filters_volume_within_the_authenticated_namespace(): void
    {
        foreach ([
            ['maintenance', 'maintenance.scan', 'default', 'completed'],
            ['order-ok', 'orders.import', 'default', 'completed'],
            ['order-fail', 'orders.import', 'default', 'failed'],
            ['outside-order', 'orders.import', 'other', 'failed'],
        ] as [$id, $type, $namespace, $status]) {
            $identity = [
                'id' => $id, 'workflow_instance_id' => $id, 'run_number' => 1,
                'workflow_type' => $type, 'namespace' => $namespace, 'status' => $status,
                'closed_at' => now()->subMinute(), 'created_at' => now()->subMinutes(2),
            ];
            WorkflowRun::query()->create([...$identity, 'workflow_class' => 'Tests\\Fixtures\\ScopeWorkflow']);
            WorkflowRunSummary::query()->create([
                ...$identity, 'class' => 'Tests\\Fixtures\\ScopeWorkflow',
                'status_bucket' => $status, 'duration_ms' => 100,
            ]);
        }
        $path = '/api/system/operator-dashboard/bounded/workflow-types?'.http_build_query([
            'workflow_types' => '["orders.import"]',
        ], encoding_type: PHP_QUERY_RFC3986);

        $this->getJson($path, $this->controlPlaneHeadersWithWorkerProtocol())
            ->assertOk()
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('dashboard.flows', 2)
            ->assertJsonPath('dashboard.workflow_scope.namespace', 'default')
            ->assertJsonPath('dashboard.workflow_scope.workflow_types', ['orders.import'])
            ->assertJsonPath('dashboard.operator_metrics_scope.workflow_types', null)
            ->assertJsonPath('dashboard.operator_metrics.runs.total', 3)
            ->assertJsonPath('dashboard.operator_metrics.history_audit_evaluation', 'not_requested')
            ->assertJsonPath('dashboard.fleet_overview.trends.hour.completed', 1)
            ->assertJsonPath('dashboard.fleet_overview.trends.hour.failed', 1);

        $this->getJson($path, $this->controlPlaneHeadersWithWorkerProtocol('other'))
            ->assertOk()
            ->assertJsonPath('dashboard.flows', 1)
            ->assertJsonPath('dashboard.workflow_scope.namespace', 'other');
        $this->getJson('/api/system/operator-dashboard/bounded/workflow-types?workflow_types=%5B%5D', $this->controlPlaneHeadersWithWorkerProtocol())
            ->assertOk()
            ->assertJsonPath('dashboard.flows', 0)
            ->assertJsonPath('dashboard.workflow_scope.workflow_types', [])
            ->assertJsonPath('dashboard.operator_metrics.runs.total', 3);
        $this->getJson('/api/system/operator-dashboard/bounded', $this->controlPlaneHeadersWithWorkerProtocol())
            ->assertOk()
            ->assertJsonPath('dashboard.flows', 3);
    }

    #[DataProvider('invalidDashboardTypeSelections')]
    public function test_workflow_type_dashboard_rejects_malformed_scope_before_reading(string $encoded): void
    {
        $this->getJson('/api/system/operator-dashboard/bounded/workflow-types?'.http_build_query([
            'workflow_types' => $encoded,
        ], encoding_type: PHP_QUERY_RFC3986), $this->controlPlaneHeadersWithWorkerProtocol())
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'validation_failed')
            ->assertJsonValidationErrors('workflow_types');
    }

    public static function invalidDashboardTypeSelections(): array
    {
        return [
            'malformed json' => ['['],
            'object' => ['{}'],
            'scalar' => ['"orders.import"'],
            'null' => ['null'],
            'empty type' => ['[""]'],
            'non-string type' => ['[1]'],
            'oversized type' => [json_encode([str_repeat('x', 256)], JSON_THROW_ON_ERROR)],
            'oversized encoded query' => [json_encode([str_repeat('/', 1500)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
        ];
    }

    public function test_workflow_type_dashboard_requires_control_plane_version_before_scope_validation(): void
    {
        $this->getJson('/api/system/operator-dashboard/bounded/workflow-types', ['X-Namespace' => 'default'])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'missing_control_plane_version');
    }

    public function test_bounded_dashboard_requires_control_plane_version_header(): void
    {
        $this->getJson('/api/system/operator-dashboard/bounded', [
            'X-Namespace' => 'default',
        ])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'missing_control_plane_version');
    }

    public function test_operator_dashboard_requires_control_plane_version_header(): void
    {
        $this->getJson('/api/system/operator-dashboard', [
            'X-Namespace' => 'default',
        ])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'missing_control_plane_version');
    }
}
