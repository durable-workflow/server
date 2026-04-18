<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'X-Durable-Workflow-Protocol-Version' => '1.0',
        ];
    }

    // ── Registration ─────────────────────────────────────────────────

    public function test_register_creates_worker_and_returns_201(): void
    {
        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'worker_id' => 'py-worker-1',
                'task_queue' => 'default',
                'runtime' => 'python',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('worker_id', 'py-worker-1')
            ->assertJsonPath('registered', true)
            ->assertHeader('X-Durable-Workflow-Protocol-Version', '1.0');

        $worker = WorkerRegistration::query()
            ->where('worker_id', 'py-worker-1')
            ->where('namespace', 'default')
            ->first();

        $this->assertNotNull($worker);
        $this->assertSame('default', $worker->task_queue);
        $this->assertSame('python', $worker->runtime);
        $this->assertSame('active', $worker->status);
    }

    public function test_register_auto_generates_worker_id_when_omitted(): void
    {
        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
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
        foreach (['php', 'python', 'typescript', 'go', 'java'] as $runtime) {
            $response = $this->withHeaders($this->workerHeaders())
                ->postJson('/api/worker/register', [
                    'worker_id' => "worker-{$runtime}",
                    'task_queue' => 'default',
                    'runtime' => $runtime,
                ]);

            $response->assertStatus(201);
        }

        $this->assertSame(5, WorkerRegistration::query()->count());
    }

    public function test_register_rejects_unsupported_runtime(): void
    {
        $response = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
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
                'worker_id' => 'py-worker-1',
                'task_queue' => 'default',
                'runtime' => 'python',
                'sdk_version' => '0.1.0',
            ])
            ->assertStatus(201);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
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
                'worker_id' => 'typed-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
                'supported_workflow_types' => ['order.process', 'order.cancel'],
                'supported_activity_types' => ['email.send', 'payment.charge'],
            ])
            ->assertStatus(201);

        $worker = WorkerRegistration::query()
            ->where('worker_id', 'typed-worker')
            ->first();

        $this->assertSame(['order.process', 'order.cancel'], $worker->supported_workflow_types);
        $this->assertSame(['email.send', 'payment.charge'], $worker->supported_activity_types);
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
                'worker_id' => 'shared-id',
                'task_queue' => 'default',
                'runtime' => 'php',
            ])
            ->assertStatus(201);

        $this->withHeaders($this->workerHeaders('staging'))
            ->postJson('/api/worker/register', [
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
            ->assertJsonPath('acknowledged', true);
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
