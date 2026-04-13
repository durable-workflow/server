<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerManagementTest extends TestCase
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

    public function test_list_marks_stale_workers(): void
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

        $workers = $response->json('workers');
        $stale = collect($workers)->firstWhere('worker_id', 'worker-stale');
        $fresh = collect($workers)->firstWhere('worker_id', 'worker-fresh');

        self::assertSame('stale', $stale['status']);
        self::assertSame('active', $fresh['status']);
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
