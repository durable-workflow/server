<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\ActivitiesConformanceWorkerRegistration;
use App\Support\ServerPollingCache;
use App\Support\WorkerCompatibilityHeartbeatRecorder;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkerControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);
    }

    private function workerHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }

    // ── Registration ─────────────────────────────────────────────────

    public function test_register_creates_worker_and_returns_201(): void
    {
        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'py-worker-1',
                'task_queue' => 'default',
                'runtime' => 'python',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('worker_id', 'py-worker-1')
            ->assertJsonPath('registered', true)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('task_queue', 'default')
            ->assertJsonPath('runtime', 'python')
            ->assertJsonPath('build_id', null)
            ->assertJsonPath('status', 'active')
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION);

        $worker = WorkerRegistration::query()
            ->where('worker_id', 'py-worker-1')
            ->where('namespace', 'default')
            ->first();

        $this->assertNotNull($worker);
        $this->assertSame('default', $worker->task_queue);
        $this->assertSame('python', $worker->runtime);
        $this->assertSame('active', $worker->status);
    }

    public function test_activities_harness_php_and_python_workers_register_against_current_protocol(): void
    {
        foreach (['php', 'python'] as $runtime) {
            $workerId = "activities-conformance-{$runtime}";
            $payload = ActivitiesConformanceWorkerRegistration::payload(
                $workerId,
                "activities-isolated-{$runtime}",
                $runtime,
                "synthetic-{$runtime}/test",
                ['activities.conformance.workflow-embedded-result'],
                ['activities.conformance.echo'],
            );

            $this->assertSame([], $payload['capabilities']);
            $this->assertSame(
                WorkerProtocol::PORTABLE_WORKER_AFFINITY_CAPABILITIES,
                array_keys($payload['capability_manifest']),
            );
            foreach ($payload['capability_manifest'] as $entry) {
                $this->assertFalse($entry['supported']);
                $this->assertSame(
                    WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
                    $entry['minimum_protocol_version'],
                );
                $this->assertSame(
                    ActivitiesConformanceWorkerRegistration::UNSUPPORTED_PORTABLE_AFFINITY_REASON,
                    $entry['reason'],
                );
            }

            $this->withHeaders($this->workerHeaders())
                ->postJson('/api/worker/register', $payload)
                ->assertCreated()
                ->assertJsonPath('registered', true)
                ->assertJsonPath('worker_id', $workerId)
                ->assertJsonPath('runtime', $runtime);
        }

        $this->assertSame(2, WorkerRegistration::query()
            ->whereIn('worker_id', ['activities-conformance-php', 'activities-conformance-python'])
            ->count());
    }

    public function test_register_advertises_heartbeat_interval_seconds(): void
    {
        config(['server.workers.heartbeat_interval_seconds' => 45]);

        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'cadence-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('heartbeat_interval_seconds', 45);
    }

    public function test_register_accepts_initial_task_slots_and_process_metrics(): void
    {
        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'init-state-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
                'max_concurrent_workflow_tasks' => 8,
                'max_concurrent_activity_tasks' => 4,
                'max_concurrent_worker_sessions' => 2,
                'task_slots' => [
                    'workflow_available' => 8,
                    'activity_available' => 4,
                    'session_available' => 2,
                ],
                'process_metrics' => [
                    'cpu_percent' => 0,
                    'memory_bytes' => 67108864,
                    'process_uptime_seconds' => 0,
                    'host' => 'init-host',
                    'process_started_at' => '2026-05-18T21:00:00Z',
                ],
                'heartbeat_interval_seconds' => 30,
            ]);

        $response->assertStatus(201);

        $worker = WorkerRegistration::query()
            ->where('worker_id', 'init-state-worker')
            ->where('namespace', 'default')
            ->firstOrFail();

        self::assertSame(8, $worker->available_workflow_slots);
        self::assertSame(4, $worker->available_activity_slots);
        self::assertSame(2, $worker->available_session_slots);
        self::assertSame(30, $worker->heartbeat_interval_seconds);
        self::assertIsArray($worker->process_metrics);
        self::assertSame(67108864, $worker->process_metrics['memory_bytes']);
        self::assertSame('2026-05-18T21:00:00Z', $worker->process_metrics['process_started_at']);
    }

    public function test_register_allows_workflow_only_worker_with_zero_activity_capacity(): void
    {
        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'timer-workflow-only-worker',
                'task_queue' => 'timers-normal-sleep',
                'runtime' => 'php',
                'supported_workflow_types' => ['timers.normal-sleep'],
                'supported_activity_types' => [],
                'max_concurrent_workflow_tasks' => 3,
                'max_concurrent_activity_tasks' => 0,
                'task_slots' => [
                    'workflow_available' => 3,
                    'activity_available' => 0,
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('worker_id', 'timer-workflow-only-worker')
            ->assertJsonPath('registered', true)
            ->assertJsonPath('task_queue', 'timers-normal-sleep')
            ->assertJsonPath('runtime', 'php');

        $worker = WorkerRegistration::query()
            ->where('worker_id', 'timer-workflow-only-worker')
            ->where('namespace', 'default')
            ->firstOrFail();

        self::assertSame(['timers.normal-sleep'], $worker->supported_workflow_types);
        self::assertSame([], $worker->supported_activity_types);
        self::assertSame(3, $worker->max_concurrent_workflow_tasks);
        self::assertSame(0, $worker->max_concurrent_activity_tasks);
        self::assertSame(3, $worker->available_workflow_slots);
        self::assertSame(0, $worker->available_activity_slots);
    }

    public function test_register_rejects_worker_with_no_task_capacity(): void
    {
        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'no-task-capacity-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
                'supported_workflow_types' => [],
                'supported_activity_types' => [],
                'max_concurrent_workflow_tasks' => 0,
                'max_concurrent_activity_tasks' => 0,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'validation_failed')
            ->assertJsonValidationErrors([
                'max_concurrent_workflow_tasks',
                'max_concurrent_activity_tasks',
            ]);

        self::assertSame(0, WorkerRegistration::query()
            ->where('worker_id', 'no-task-capacity-worker')
            ->count());
    }

    public function test_register_rejects_negative_and_non_numeric_task_capacity(): void
    {
        $cases = [
            ['negative-workflow-capacity-worker', ['max_concurrent_workflow_tasks' => -1], 'max_concurrent_workflow_tasks'],
            ['negative-activity-capacity-worker', ['max_concurrent_activity_tasks' => -1], 'max_concurrent_activity_tasks'],
            ['non-numeric-workflow-capacity-worker', ['max_concurrent_workflow_tasks' => 'many'], 'max_concurrent_workflow_tasks'],
            ['non-numeric-activity-capacity-worker', ['max_concurrent_activity_tasks' => 'many'], 'max_concurrent_activity_tasks'],
        ];

        foreach ($cases as [$workerId, $capacity, $field]) {
            $response = $this->withHeaders($this->workerHeaders())
                ->postJson('/api/worker/register', [
                    'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                    'worker_id' => $workerId,
                    'task_queue' => 'default',
                    'runtime' => 'python',
                    'supported_workflow_types' => ['orders.process'],
                    'supported_activity_types' => ['orders.charge'],
                ] + $capacity);

            $response->assertStatus(422)
                ->assertJsonPath('reason', 'validation_failed')
                ->assertJsonValidationErrors([$field]);

            self::assertSame(0, WorkerRegistration::query()
                ->where('worker_id', $workerId)
                ->count());
        }
    }

    public function test_register_auto_generates_worker_id_when_omitted(): void
    {
        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'task_queue' => 'default',
                'runtime' => 'php',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('registered', true);

        $workerId = $response->json('worker_id');
        $this->assertIsString($workerId);
        $this->assertNotEmpty($workerId);
    }

    public function test_register_accepts_all_supported_runtimes(): void
    {
        foreach (['php', 'python', 'rust', 'typescript', 'go', 'java', 'external'] as $runtime) {
            $response = $this->withHeaders($this->workerHeaders())
                ->postJson('/api/worker/register', [
                    'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                    'worker_id' => "worker-{$runtime}",
                    'task_queue' => 'default',
                    'runtime' => $runtime,
                ]);

            $response->assertStatus(201);
        }

        $this->assertSame(7, WorkerRegistration::query()->count());
    }

    public function test_register_rejects_unsupported_runtime(): void
    {
        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'worker-ruby',
                'task_queue' => 'default',
                'runtime' => 'ruby',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['runtime']);
    }

    public function test_register_requires_task_queue_and_runtime(): void
    {
        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['task_queue', 'runtime']);
    }

    public function test_register_updates_existing_worker_on_re_registration(): void
    {
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'py-worker-1',
                'task_queue' => 'default',
                'runtime' => 'python',
                'sdk_version' => '0.1.0',
            ])
            ->assertStatus(201);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'py-worker-1',
                'task_queue' => 'default',
                'runtime' => 'python',
                'sdk_version' => '0.2.0',
            ])
            ->assertStatus(201);

        $this->assertSame(1, WorkerRegistration::query()
            ->where('worker_id', 'py-worker-1')
            ->where('namespace', 'default')
            ->count());

        $worker = WorkerRegistration::query()
            ->where('worker_id', 'py-worker-1')
            ->first();

        $this->assertSame('0.2.0', $worker->sdk_version);
    }

    public function test_register_stores_supported_workflow_and_activity_types(): void
    {
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'typed-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
                'supported_workflow_types' => ['order.process', 'order.cancel'],
                'workflow_definition_fingerprints' => [
                    'order.process' => 'sha256:process',
                    'order.cancel' => 'sha256:cancel',
                ],
                'supported_activity_types' => ['email.send', 'payment.charge'],
            ])
            ->assertStatus(201);

        $worker = WorkerRegistration::query()
            ->where('worker_id', 'typed-worker')
            ->first();

        $this->assertSame(['order.process', 'order.cancel'], $worker->supported_workflow_types);
        $this->assertSame([
            'order.cancel' => 'sha256:cancel',
            'order.process' => 'sha256:process',
        ], $worker->workflow_definition_fingerprints);
        $this->assertSame(['email.send', 'payment.charge'], $worker->supported_activity_types);
    }

    public function test_register_rejects_changed_active_workflow_definition_for_same_worker(): void
    {
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'reload-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
                'supported_workflow_types' => ['order.process'],
                'workflow_definition_fingerprints' => [
                    'order.process' => 'sha256:old',
                ],
            ])
            ->assertStatus(201);

        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'reload-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
                'supported_workflow_types' => ['order.process'],
                'workflow_definition_fingerprints' => [
                    'order.process' => 'sha256:new',
                ],
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('reason', 'workflow_definition_changed')
            ->assertJsonPath('workflow_type', 'order.process');

        $worker = WorkerRegistration::query()
            ->where('worker_id', 'reload-worker')
            ->firstOrFail();

        $this->assertSame(['order.process' => 'sha256:old'], $worker->workflow_definition_fingerprints);
    }

    public function test_register_preserves_active_workflow_definition_fingerprint_when_omitted(): void
    {
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'legacy-reload-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
                'supported_workflow_types' => ['order.process'],
                'workflow_definition_fingerprints' => [
                    'order.process' => 'sha256:old',
                ],
            ])
            ->assertStatus(201);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'legacy-reload-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
                'supported_workflow_types' => ['order.process'],
            ])
            ->assertStatus(201);

        $worker = WorkerRegistration::query()
            ->where('worker_id', 'legacy-reload-worker')
            ->firstOrFail();

        $this->assertSame(['order.process' => 'sha256:old'], $worker->workflow_definition_fingerprints);
    }

    public function test_register_allows_same_workflow_definition_for_same_worker(): void
    {
        foreach (range(1, 2) as $_) {
            $this->withHeaders($this->workerHeaders())
                ->postJson('/api/worker/register', [
                    'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                    'worker_id' => 'stable-worker',
                    'task_queue' => 'default',
                    'runtime' => 'python',
                    'supported_workflow_types' => ['order.process'],
                    'workflow_definition_fingerprints' => [
                        'order.process' => 'sha256:same',
                    ],
                ])
                ->assertStatus(201);
        }
    }

    public function test_register_is_scoped_to_namespace(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'staging',
            'description' => 'Staging namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $this->withHeaders($this->workerHeaders('default'))
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'shared-id',
                'task_queue' => 'default',
                'runtime' => 'php',
            ])
            ->assertStatus(201);

        $this->withHeaders($this->workerHeaders('staging'))
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'shared-id',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])
            ->assertStatus(201);

        $this->assertSame(2, WorkerRegistration::query()
            ->where('worker_id', 'shared-id')
            ->count());
    }

    public function test_register_rejects_request_without_protocol_version_header(): void
    {
        $response = $this->withHeaders(['X-Namespace' => 'default'])
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'worker-no-version',
                'task_queue' => 'default',
                'runtime' => 'php',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('reason', 'missing_protocol_version');
    }

    // ── Heartbeat ────────────────────────────────────────────────────

    public function test_heartbeat_succeeds_for_registered_worker(): void
    {
        WorkerRegistration::query()->create([
            'worker_id' => 'heartbeat-worker',
            'namespace' => 'default',
            'task_queue' => 'default',
            'runtime' => 'php',
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'last_heartbeat_at' => now()->subMinutes(5),
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'heartbeat-worker',
            ]);

        $response->assertOk()
            ->assertJsonPath('worker_id', 'heartbeat-worker')
            ->assertJsonPath('acknowledged', true)
            ->assertJsonPath('heartbeat_interval_seconds', 10);
    }

    public function test_concurrent_request_processes_share_worker_compatibility_refresh_throttle(): void
    {
        config(['workflows.v2.compatibility.heartbeat_ttl_seconds' => 30]);
        Carbon::setTestNow(Carbon::parse('2026-07-17 02:00:00'));

        try {
            $this->withHeaders($this->workerHeaders())
                ->postJson('/api/worker/register', [
                    'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                    'worker_id' => 'compatibility-throttle-worker',
                    'task_queue' => 'replay-query',
                    'runtime' => 'external',
                    'build_id' => 'build-replay-query',
                ])
                ->assertCreated();

            $initialRecordedAt = DB::table('workflow_worker_compatibility_heartbeats')
                ->where('worker_id', 'compatibility-throttle-worker')
                ->value('recorded_at');

            Carbon::setTestNow(now()->addSecond());

            $separateRequestRecorder = new WorkerCompatibilityHeartbeatRecorder(
                app(ServerPollingCache::class),
            );
            $this->assertFalse($separateRequestRecorder->record(
                namespace: 'default',
                workerId: 'compatibility-throttle-worker',
                taskQueue: 'replay-query',
                buildId: 'build-replay-query',
            ));

            for ($request = 0; $request < 6; $request++) {
                $this->withHeaders($this->workerHeaders())
                    ->postJson('/api/worker/heartbeat', [
                        'worker_id' => 'compatibility-throttle-worker',
                    ])
                    ->assertOk();
            }

            $this->assertSame(
                (string) $initialRecordedAt,
                (string) DB::table('workflow_worker_compatibility_heartbeats')
                    ->where('worker_id', 'compatibility-throttle-worker')
                    ->value('recorded_at'),
            );

            Carbon::setTestNow(now()->addSeconds(10));

            $this->withHeaders($this->workerHeaders())
                ->postJson('/api/worker/heartbeat', [
                    'worker_id' => 'compatibility-throttle-worker',
                ])
                ->assertOk();

            $this->assertNotSame(
                (string) $initialRecordedAt,
                (string) DB::table('workflow_worker_compatibility_heartbeats')
                    ->where('worker_id', 'compatibility-throttle-worker')
                    ->value('recorded_at'),
            );
            $this->assertFalse((new WorkerCompatibilityHeartbeatRecorder(
                app(ServerPollingCache::class),
            ))->record(
                namespace: 'default',
                workerId: 'compatibility-throttle-worker',
                taskQueue: 'replay-query',
                buildId: 'build-replay-query',
            ));
            $this->assertSame(
                1,
                DB::table('workflow_worker_compatibility_heartbeats')
                    ->where('worker_id', 'compatibility-throttle-worker')
                    ->count(),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_heartbeat_records_task_slots_and_process_metrics(): void
    {
        WorkerRegistration::query()->create([
            'worker_id' => 'metrics-worker',
            'namespace' => 'default',
            'task_queue' => 'default',
            'runtime' => 'python',
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 10,
            'max_concurrent_activity_tasks' => 5,
            'max_concurrent_worker_sessions' => 2,
            'last_heartbeat_at' => now()->subMinutes(2),
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'metrics-worker',
                'task_slots' => [
                    'workflow_available' => 7,
                    'activity_available' => 5,
                    'session_available' => 1,
                ],
                'process_metrics' => [
                    'cpu_percent' => 8.25,
                    'memory_bytes' => 314572800,
                    'process_uptime_seconds' => 600,
                    'process_id' => 1234,
                    'host' => 'py-worker-01',
                    'process_started_at' => '2026-05-18T21:02:00Z',
                ],
                'heartbeat_interval_seconds' => 45,
            ]);

        $response->assertOk()
            ->assertJsonPath('acknowledged', true)
            ->assertJsonPath('heartbeat_interval_seconds', 10);

        $worker = WorkerRegistration::query()
            ->where('worker_id', 'metrics-worker')
            ->where('namespace', 'default')
            ->firstOrFail();

        self::assertSame(7, $worker->available_workflow_slots);
        self::assertSame(5, $worker->available_activity_slots);
        self::assertSame(1, $worker->available_session_slots);
        self::assertSame(45, $worker->heartbeat_interval_seconds);

        self::assertIsArray($worker->process_metrics);
        self::assertSame(8.25, $worker->process_metrics['cpu_percent']);
        self::assertSame(314572800, $worker->process_metrics['memory_bytes']);
        self::assertSame(600, $worker->process_metrics['process_uptime_seconds']);
        self::assertSame(1234, $worker->process_metrics['process_id']);
        self::assertSame('py-worker-01', $worker->process_metrics['host']);
        self::assertSame('2026-05-18T21:02:00Z', $worker->process_metrics['process_started_at']);
    }

    public function test_heartbeat_clamps_available_slots_to_capacity(): void
    {
        WorkerRegistration::query()->create([
            'worker_id' => 'overflow-worker',
            'namespace' => 'default',
            'task_queue' => 'default',
            'runtime' => 'python',
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 4,
            'max_concurrent_activity_tasks' => 4,
            'max_concurrent_worker_sessions' => 1,
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'overflow-worker',
                'task_slots' => [
                    'workflow_available' => 99,
                ],
            ])
            ->assertOk();

        $worker = WorkerRegistration::query()
            ->where('worker_id', 'overflow-worker')
            ->firstOrFail();

        self::assertSame(4, $worker->available_workflow_slots);
        self::assertNull($worker->available_activity_slots);
        self::assertNull($worker->available_session_slots);
    }

    public function test_heartbeat_returns_404_for_unregistered_worker(): void
    {
        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'nonexistent-worker',
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('error', 'Worker not registered.')
            ->assertJsonPath('reason', 'worker_not_registered')
            ->assertJsonPath('worker_id', 'nonexistent-worker');
    }

    public function test_heartbeat_requires_worker_id(): void
    {
        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['worker_id']);
    }

    public function test_heartbeat_is_scoped_to_namespace(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'other',
            'description' => 'Other namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        WorkerRegistration::query()->create([
            'worker_id' => 'ns-worker',
            'namespace' => 'default',
            'task_queue' => 'default',
            'runtime' => 'php',
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);

        $this->withHeaders($this->workerHeaders('other'))
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'ns-worker',
            ])
            ->assertStatus(404)
            ->assertJsonPath('reason', 'worker_not_registered');

        $this->withHeaders($this->workerHeaders('default'))
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'ns-worker',
            ])
            ->assertOk();
    }
}
