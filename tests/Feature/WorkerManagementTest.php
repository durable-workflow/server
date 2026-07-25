<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\StandaloneWorkerVisibility;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class WorkerManagementTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

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

    private function apiHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    // ── List ─────────────────────────────────────────────────────────

    public function test_list_returns_empty_array_when_no_workers_registered(): void
    {
        $response = $this->getJson('/api/workers', $this->apiHeaders());

        $response->assertOk();
        $response->assertJsonPath('workers', []);
    }

    public function test_list_returns_registered_workers_with_expected_structure(): void
    {
        $this->createWorker('worker-a', 'queue-alpha', 'php');
        $this->createWorker('worker-b', 'queue-beta', 'python');

        $response = $this->getJson('/api/workers', $this->apiHeaders());

        $response->assertOk();
        $response->assertJsonCount(2, 'workers');

        $workers = $response->json('workers');

        // Ordered by last_heartbeat_at desc — both are now(), so stable order
        $ids = array_column($workers, 'worker_id');
        self::assertContains('worker-a', $ids);
        self::assertContains('worker-b', $ids);

        // Verify full structure on first worker
        $first = collect($workers)->firstWhere('worker_id', 'worker-a');
        self::assertSame('default', $first['namespace']);
        self::assertSame('queue-alpha', $first['task_queue']);
        self::assertSame('php', $first['runtime']);
        self::assertArrayHasKey('last_heartbeat_at', $first);
        self::assertArrayHasKey('registered_at', $first);
        self::assertArrayHasKey('supported_workflow_types', $first);
        self::assertArrayHasKey('supported_activity_types', $first);
    }

    public function test_list_filters_by_task_queue(): void
    {
        $this->createWorker('worker-a', 'queue-alpha', 'php');
        $this->createWorker('worker-b', 'queue-beta', 'python');

        $response = $this->getJson('/api/workers?task_queue=queue-alpha', $this->apiHeaders());

        $response->assertOk();
        $response->assertJsonCount(1, 'workers');
        $response->assertJsonPath('workers.0.worker_id', 'worker-a');
    }

    public function test_list_filters_by_status(): void
    {
        $this->createWorker('worker-active', 'queue', 'php');

        WorkerRegistration::query()->create([
            'worker_id' => 'worker-inactive',
            'namespace' => 'default',
            'task_queue' => 'queue',
            'runtime' => 'php',
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'last_heartbeat_at' => now(),
            'status' => 'draining',
        ]);

        $response = $this->getJson('/api/workers?status=draining', $this->apiHeaders());

        $response->assertOk();
        $response->assertJsonCount(1, 'workers');
        $response->assertJsonPath('workers.0.worker_id', 'worker-inactive');
    }

    public function test_list_is_namespace_scoped(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'other',
            'description' => 'Other namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $this->createWorker('worker-default', 'queue', 'php', 'default');
        $this->createWorker('worker-other', 'queue', 'php', 'other');

        $response = $this->getJson('/api/workers', $this->apiHeaders('default'));
        $response->assertOk();
        $response->assertJsonCount(1, 'workers');
        $response->assertJsonPath('workers.0.worker_id', 'worker-default');

        $response = $this->getJson('/api/workers', $this->apiHeaders('other'));
        $response->assertOk();
        $response->assertJsonCount(1, 'workers');
        $response->assertJsonPath('workers.0.worker_id', 'worker-other');
    }

    public function test_list_hides_stale_workers_by_default_and_exposes_them_explicitly(): void
    {
        config(['server.workers.stale_after_seconds' => 60]);

        WorkerRegistration::query()->create([
            'worker_id' => 'worker-stale',
            'namespace' => 'default',
            'task_queue' => 'queue',
            'runtime' => 'php',
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'last_heartbeat_at' => now()->subSeconds(120),
            'status' => 'active',
        ]);

        $this->createWorker('worker-fresh', 'queue', 'php');

        $response = $this->getJson('/api/workers', $this->apiHeaders());
        $response->assertOk();
        $response->assertJsonCount(1, 'workers');
        $response->assertJsonPath('workers.0.worker_id', 'worker-fresh');
        $response->assertJsonPath('workers.0.status', 'active');

        $response = $this->getJson('/api/workers?status=stale', $this->apiHeaders());
        $response->assertOk();
        $response->assertJsonCount(1, 'workers');
        $response->assertJsonPath('workers.0.worker_id', 'worker-stale');
        $response->assertJsonPath('workers.0.status', 'stale');
    }

    public function test_status_filter_treats_expired_heartbeats_as_stale(): void
    {
        config(['server.workers.stale_after_seconds' => 60]);

        WorkerRegistration::query()->create([
            'worker_id' => 'worker-expired-draining',
            'namespace' => 'default',
            'task_queue' => 'queue',
            'runtime' => 'php',
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'last_heartbeat_at' => now()->subSeconds(120),
            'status' => 'draining',
        ]);

        $this->createWorker('worker-fresh-draining', 'queue', 'php');
        WorkerRegistration::query()
            ->where('worker_id', 'worker-fresh-draining')
            ->update(['status' => 'draining']);

        $response = $this->getJson('/api/workers?status=draining', $this->apiHeaders());
        $response->assertOk();
        $response->assertJsonCount(1, 'workers');
        $response->assertJsonPath('workers.0.worker_id', 'worker-fresh-draining');

        $response = $this->getJson('/api/workers?status=stale', $this->apiHeaders());
        $response->assertOk();
        $response->assertJsonCount(1, 'workers');
        $response->assertJsonPath('workers.0.worker_id', 'worker-expired-draining');

        $response = $this->getJson('/api/workers?status=active', $this->apiHeaders());
        $response->assertOk();
        $response->assertJsonPath('workers', []);
    }

    // ── Show ─────────────────────────────────────────────────────────

    public function test_show_returns_worker_details(): void
    {
        $this->createWorker('worker-a', 'queue-alpha', 'php');

        $response = $this->getJson('/api/workers/worker-a', $this->apiHeaders());

        $response->assertOk();
        $response->assertJsonPath('worker_id', 'worker-a');
        $response->assertJsonPath('task_queue', 'queue-alpha');
        $response->assertJsonPath('runtime', 'php');
        $response->assertJsonPath('namespace', 'default');
        $response->assertJsonPath('status', 'active');

        $data = $response->json();
        self::assertArrayHasKey('last_heartbeat_at', $data);
        self::assertArrayHasKey('registered_at', $data);
        self::assertArrayHasKey('updated_at', $data);
        self::assertArrayHasKey('supported_workflow_types', $data);
        self::assertArrayHasKey('supported_activity_types', $data);
        self::assertArrayHasKey('max_concurrent_workflow_tasks', $data);
        self::assertArrayHasKey('max_concurrent_activity_tasks', $data);
    }

    public function test_show_returns_404_for_unknown_worker(): void
    {
        $response = $this->getJson('/api/workers/nonexistent', $this->apiHeaders());

        $response->assertNotFound();
        $response->assertJsonPath('reason', 'worker_not_found');
    }

    public function test_show_is_namespace_scoped(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'other',
            'description' => 'Other namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $this->createWorker('worker-a', 'queue', 'php', 'other');

        // Worker exists in 'other' namespace, not visible from 'default'
        $response = $this->getJson('/api/workers/worker-a', $this->apiHeaders('default'));
        $response->assertNotFound();

        $response = $this->getJson('/api/workers/worker-a', $this->apiHeaders('other'));
        $response->assertOk();
        $response->assertJsonPath('worker_id', 'worker-a');
    }

    public function test_show_marks_stale_worker(): void
    {
        config(['server.workers.stale_after_seconds' => 60]);

        WorkerRegistration::query()->create([
            'worker_id' => 'worker-stale',
            'namespace' => 'default',
            'task_queue' => 'queue',
            'runtime' => 'php',
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'last_heartbeat_at' => now()->subSeconds(120),
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/workers/worker-stale', $this->apiHeaders());

        $response->assertOk();
        $response->assertJsonPath('status', 'stale');
    }

    public function test_show_returns_task_slots_and_process_metrics(): void
    {
        WorkerRegistration::query()->create([
            'worker_id' => 'worker-slots',
            'namespace' => 'default',
            'task_queue' => 'queue',
            'runtime' => 'python',
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 8,
            'max_concurrent_activity_tasks' => 4,
            'max_concurrent_worker_sessions' => 2,
            'available_workflow_slots' => 6,
            'available_activity_slots' => 3,
            'available_session_slots' => 2,
            'process_metrics' => [
                'cpu_percent' => 12.5,
                'memory_bytes' => 419430400,
                'process_uptime_seconds' => 900,
                'process_id' => 4242,
                'host' => 'worker-host-01',
            ],
            'heartbeat_interval_seconds' => 30,
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/workers/worker-slots', $this->apiHeaders());

        $response->assertOk();
        $response->assertJsonPath('task_slots.workflow_available', 6);
        $response->assertJsonPath('task_slots.activity_available', 3);
        $response->assertJsonPath('task_slots.session_available', 2);
        $response->assertJsonPath('task_slots.workflow_capacity', 8);
        $response->assertJsonPath('task_slots.activity_capacity', 4);
        $response->assertJsonPath('task_slots.session_capacity', 2);
        $response->assertJsonPath('process_metrics.cpu_percent', 12.5);
        $response->assertJsonPath('process_metrics.memory_bytes', 419430400);
        $response->assertJsonPath('process_metrics.process_uptime_seconds', 900);
        $response->assertJsonPath('heartbeat_interval_seconds', 30);
        $response->assertJsonPath('stale_after_seconds', (int) config('server.workers.stale_after_seconds'));
    }

    public function test_list_advertises_stale_after_window(): void
    {
        config(['server.workers.stale_after_seconds' => 90]);

        $this->createWorker('worker-a', 'queue-alpha', 'php');

        $response = $this->getJson('/api/workers', $this->apiHeaders());

        $response->assertOk();
        $response->assertJsonPath('stale_after_seconds', 90);
    }

    // ── Destroy (Deregister) ─────────────────────────────────────────

    public function test_deregister_removes_worker(): void
    {
        $this->createWorker('worker-a', 'queue', 'php');

        $response = $this->deleteJson('/api/workers/worker-a', [], $this->apiHeaders());

        $response->assertOk();
        $response->assertJsonPath('worker_id', 'worker-a');
        $response->assertJsonPath('outcome', 'deregistered');

        self::assertNull(
            WorkerRegistration::query()
                ->where('worker_id', 'worker-a')
                ->where('namespace', 'default')
                ->first()
        );
    }

    public function test_deregister_recovers_a_leased_workflow_task_for_a_replacement_worker(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-orderly-worker-handoff',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'handoff-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders())->assertCreated();

        $this->registerWorker(
            workerId: 'handoff-worker-1',
            taskQueue: 'handoff-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $firstPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'handoff-worker-1',
            'task_queue' => 'handoff-queue',
        ], $this->workerHeaders());

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-orderly-worker-handoff')
            ->assertJsonPath('task.lease_owner', 'handoff-worker-1');

        $firstTaskId = (string) $firstPoll->json('task.task_id');

        $this->deleteJson('/api/workers/handoff-worker-1', [], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('outcome', 'deregistered')
            ->assertJsonPath('recovered_workflow_task_count', 1);

        self::assertFalse(WorkflowTask::query()
            ->whereKey($firstTaskId)
            ->where('status', TaskStatus::Leased->value)
            ->where('lease_owner', 'handoff-worker-1')
            ->exists());

        $this->registerWorker(
            workerId: 'handoff-worker-2',
            taskQueue: 'handoff-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'handoff-worker-2',
            'task_queue' => 'handoff-queue',
            'timeout_seconds' => 0,
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-orderly-worker-handoff')
            ->assertJsonPath('task.lease_owner', 'handoff-worker-2');
    }

    public function test_deregister_removes_worker_compatibility_heartbeats(): void
    {
        $this->createWorker('worker-a', 'queue', 'php');
        $this->createWorker('worker-b', 'queue', 'php');
        $now = now();

        DB::table('workflow_worker_compatibility_heartbeats')->insert([
            'worker_id' => 'worker-a',
            'scope_key' => 'default:queue:a',
            'namespace' => 'default',
            'queue' => 'queue',
            'supported' => json_encode(['build-v1'], JSON_THROW_ON_ERROR),
            'recorded_at' => $now,
            'expires_at' => $now->copy()->addMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workflow_worker_compatibility_heartbeats')->insert([
            'worker_id' => 'worker-b',
            'scope_key' => 'default:queue:b',
            'namespace' => 'default',
            'queue' => 'queue',
            'supported' => json_encode(['build-v2'], JSON_THROW_ON_ERROR),
            'recorded_at' => $now,
            'expires_at' => $now->copy()->addMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->deleteJson('/api/workers/worker-a', [], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('outcome', 'deregistered');

        self::assertFalse(DB::table('workflow_worker_compatibility_heartbeats')
            ->where('worker_id', 'worker-a')
            ->where('namespace', 'default')
            ->exists());
        self::assertTrue(DB::table('workflow_worker_compatibility_heartbeats')
            ->where('worker_id', 'worker-b')
            ->where('namespace', 'default')
            ->exists());
    }

    public function test_deregister_forgets_worker_compatibility_snapshot(): void
    {
        if (! method_exists(WorkerCompatibilityFleet::class, 'forgetWorkerForNamespace')) {
            $this->markTestSkipped('workflow package does not expose targeted compatibility forgetting yet.');
        }

        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });
        WorkerCompatibilityFleet::clear();

        $this->createWorker('worker-a', 'queue', 'php');
        $this->createWorker('worker-b', 'queue', 'php');
        StandaloneWorkerVisibility::recordCompatibility('default', 'worker-a', 'queue', 'build-v1');
        StandaloneWorkerVisibility::recordCompatibility('default', 'worker-b', 'queue', 'build-v2');

        $buildV1BeforeDeregister = WorkerCompatibilityFleet::detailsForNamespace('default', 'build-v1', null, 'queue');
        $buildV2BeforeDeregister = WorkerCompatibilityFleet::detailsForNamespace('default', 'build-v2', null, 'queue');

        self::assertSame(['worker-a', 'worker-b'], array_column($buildV1BeforeDeregister, 'worker_id'));
        self::assertTrue($buildV1BeforeDeregister[0]['supports_required']);
        self::assertFalse($buildV1BeforeDeregister[1]['supports_required']);
        self::assertSame(['worker-a', 'worker-b'], array_column($buildV2BeforeDeregister, 'worker_id'));
        self::assertFalse($buildV2BeforeDeregister[0]['supports_required']);
        self::assertTrue($buildV2BeforeDeregister[1]['supports_required']);

        $this->deleteJson('/api/workers/worker-a', [], $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('outcome', 'deregistered');

        $buildV1AfterDeregister = WorkerCompatibilityFleet::detailsForNamespace('default', 'build-v1', null, 'queue');
        $buildV2AfterDeregister = WorkerCompatibilityFleet::detailsForNamespace('default', 'build-v2', null, 'queue');

        self::assertSame(['worker-b'], array_column($buildV1AfterDeregister, 'worker_id'));
        self::assertFalse($buildV1AfterDeregister[0]['supports_required']);
        self::assertSame(['worker-b'], array_column($buildV2AfterDeregister, 'worker_id'));
        self::assertTrue($buildV2AfterDeregister[0]['supports_required']);

        StandaloneWorkerVisibility::recordCompatibility('default', 'worker-a', 'queue', 'build-v1');

        $buildV1AfterReregister = WorkerCompatibilityFleet::detailsForNamespace('default', 'build-v1', null, 'queue');

        self::assertSame(['worker-a', 'worker-b'], array_column($buildV1AfterReregister, 'worker_id'));
        self::assertTrue($buildV1AfterReregister[0]['supports_required']);
        self::assertFalse($buildV1AfterReregister[1]['supports_required']);
    }

    public function test_deregister_returns_404_for_unknown_worker(): void
    {
        $response = $this->deleteJson('/api/workers/nonexistent', [], $this->apiHeaders());

        $response->assertNotFound();
        $response->assertJsonPath('reason', 'worker_not_found');
    }

    public function test_deregister_is_namespace_scoped(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'other',
            'description' => 'Other namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $this->createWorker('worker-a', 'queue', 'php', 'other');

        // Cannot deregister from wrong namespace
        $response = $this->deleteJson('/api/workers/worker-a', [], $this->apiHeaders('default'));
        $response->assertNotFound();

        // Worker still exists
        self::assertNotNull(
            WorkerRegistration::query()
                ->where('worker_id', 'worker-a')
                ->where('namespace', 'other')
                ->first()
        );

        // Can deregister from correct namespace
        $response = $this->deleteJson('/api/workers/worker-a', [], $this->apiHeaders('other'));
        $response->assertOk();
        $response->assertJsonPath('outcome', 'deregistered');
    }

    public function test_list_reflects_deregistration(): void
    {
        $this->createWorker('worker-a', 'queue', 'php');
        $this->createWorker('worker-b', 'queue', 'python');

        $this->deleteJson('/api/workers/worker-a', [], $this->apiHeaders());

        $response = $this->getJson('/api/workers', $this->apiHeaders());
        $response->assertOk();
        $response->assertJsonCount(1, 'workers');
        $response->assertJsonPath('workers.0.worker_id', 'worker-b');
    }

    // ── Auth ─────────────────────────────────────────────────────────

    public function test_endpoints_require_authentication_when_enabled(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'secret']);

        $this->getJson('/api/workers', $this->apiHeaders())->assertUnauthorized();
        $this->getJson('/api/workers/any', $this->apiHeaders())->assertUnauthorized();
        $this->deleteJson('/api/workers/any', [], $this->apiHeaders())->assertUnauthorized();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function createWorker(
        string $workerId,
        string $taskQueue,
        string $runtime,
        string $namespace = 'default',
    ): void {
        WorkerRegistration::query()->create([
            'worker_id' => $workerId,
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'runtime' => $runtime,
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 100,
            'max_concurrent_activity_tasks' => 100,
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);
    }
}
