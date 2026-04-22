<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskQueueBuildIdDrainTest extends TestCase
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

        config(['server.workers.stale_after_seconds' => 60]);
    }

    public function test_drain_records_intent_and_returns_drained_timestamp(): void
    {
        $response = $this->postJson(
            '/api/task-queues/ingest/build-ids/drain',
            ['build_id' => 'v2025.01.21-b41'],
            $this->apiHeaders(),
        );

        $response->assertOk();
        $response->assertJsonPath('build_id', 'v2025.01.21-b41');
        $response->assertJsonPath('drain_intent', 'draining');

        self::assertNotNull($response->json('drained_at'));

        $rollout = WorkerBuildIdRollout::query()
            ->where('namespace', 'default')
            ->where('task_queue', 'ingest')
            ->where('build_id', 'v2025.01.21-b41')
            ->first();

        self::assertNotNull($rollout);
        self::assertSame('draining', $rollout->drain_intent);
        self::assertNotNull($rollout->drained_at);
    }

    public function test_drain_is_idempotent_and_preserves_original_drained_at(): void
    {
        $this->postJson(
            '/api/task-queues/ingest/build-ids/drain',
            ['build_id' => 'v1'],
            $this->apiHeaders(),
        )->assertOk();

        $first = WorkerBuildIdRollout::query()
            ->where('namespace', 'default')
            ->where('build_id', 'v1')
            ->firstOrFail();

        $this->travel(5)->minutes();

        $this->postJson(
            '/api/task-queues/ingest/build-ids/drain',
            ['build_id' => 'v1'],
            $this->apiHeaders(),
        )->assertOk();

        $second = WorkerBuildIdRollout::query()
            ->where('namespace', 'default')
            ->where('build_id', 'v1')
            ->firstOrFail();

        self::assertTrue(
            $first->drained_at->equalTo($second->drained_at),
            'drained_at should not shift on repeat drain calls',
        );
    }

    public function test_drain_accepts_unversioned_cohort_with_null_build_id(): void
    {
        $response = $this->postJson(
            '/api/task-queues/ingest/build-ids/drain',
            ['build_id' => null],
            $this->apiHeaders(),
        );

        $response->assertOk();
        $response->assertJsonPath('build_id', null);
        $response->assertJsonPath('drain_intent', 'draining');

        $rollout = WorkerBuildIdRollout::query()
            ->where('namespace', 'default')
            ->where('task_queue', 'ingest')
            ->where('build_id', '')
            ->first();

        self::assertNotNull($rollout, 'unversioned cohort should be stored with empty build_id sentinel');
    }

    public function test_drain_requires_build_id_key_in_body(): void
    {
        $this->postJson('/api/task-queues/ingest/build-ids/drain', [], $this->apiHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['build_id']);
    }

    public function test_resume_clears_drain_intent_and_clears_drained_at(): void
    {
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'build_id' => 'v1',
            'drain_intent' => 'draining',
            'drained_at' => now(),
        ]);

        $response = $this->postJson(
            '/api/task-queues/ingest/build-ids/resume',
            ['build_id' => 'v1'],
            $this->apiHeaders(),
        );

        $response->assertOk();
        $response->assertJsonPath('drain_intent', 'active');
        $response->assertJsonPath('drained_at', null);

        $rollout = WorkerBuildIdRollout::query()
            ->where('build_id', 'v1')
            ->firstOrFail();
        self::assertSame('active', $rollout->drain_intent);
        self::assertNull($rollout->drained_at);
    }

    public function test_resume_flips_draining_worker_rows_back_to_active(): void
    {
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'build_id' => 'v1',
            'drain_intent' => 'draining',
            'drained_at' => now(),
        ]);

        WorkerRegistration::query()->create($this->workerAttributes(
            'w-drained',
            'ingest',
            build: 'v1',
            status: 'draining',
        ));

        $this->postJson(
            '/api/task-queues/ingest/build-ids/resume',
            ['build_id' => 'v1'],
            $this->apiHeaders(),
        )->assertOk();

        $worker = WorkerRegistration::query()->where('worker_id', 'w-drained')->firstOrFail();
        self::assertSame('active', $worker->status);
    }

    public function test_resume_on_fresh_build_id_is_no_op(): void
    {
        $response = $this->postJson(
            '/api/task-queues/ingest/build-ids/resume',
            ['build_id' => 'v-never-drained'],
            $this->apiHeaders(),
        );

        $response->assertOk();
        $response->assertJsonPath('drain_intent', 'active');
        $response->assertJsonPath('drained_at', null);
    }

    public function test_build_ids_get_surfaces_drain_intent_for_cohort_with_workers(): void
    {
        WorkerRegistration::query()->create($this->workerAttributes('w1', 'ingest', build: 'v1'));

        $this->postJson(
            '/api/task-queues/ingest/build-ids/drain',
            ['build_id' => 'v1'],
            $this->apiHeaders(),
        )->assertOk();

        $response = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders());

        $entry = collect($response->json('build_ids'))->firstWhere('build_id', 'v1');
        self::assertNotNull($entry);
        self::assertSame('draining', $entry['drain_intent']);
        self::assertNotNull($entry['drained_at']);
        self::assertSame('active_with_draining', $entry['rollout_status']);
        self::assertSame(1, $entry['active_worker_count']);
    }

    public function test_build_ids_get_surfaces_drained_cohort_with_no_live_workers(): void
    {
        $this->postJson(
            '/api/task-queues/ingest/build-ids/drain',
            ['build_id' => 'v-ghost'],
            $this->apiHeaders(),
        )->assertOk();

        $response = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders());

        $entry = collect($response->json('build_ids'))->firstWhere('build_id', 'v-ghost');
        self::assertNotNull($entry, 'drained cohort must remain visible after workers go away');
        self::assertSame('draining', $entry['drain_intent']);
        self::assertSame('draining', $entry['rollout_status']);
        self::assertSame(0, $entry['total_worker_count']);
    }

    public function test_drain_stamps_new_registrations_with_draining_status(): void
    {
        $this->postJson(
            '/api/task-queues/ingest/build-ids/drain',
            ['build_id' => 'v1'],
            $this->apiHeaders(),
        )->assertOk();

        $this->postJson('/api/worker/register', [
            'worker_id' => 'w-new',
            'task_queue' => 'ingest',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'v1',
        ], $this->workerHeaders())->assertCreated();

        $worker = WorkerRegistration::query()->where('worker_id', 'w-new')->firstOrFail();
        self::assertSame('draining', $worker->status);
    }

    public function test_drain_stamps_heartbeat_updates_with_draining_status(): void
    {
        WorkerRegistration::query()->create($this->workerAttributes('w-heart', 'ingest', build: 'v1'));

        $this->postJson(
            '/api/task-queues/ingest/build-ids/drain',
            ['build_id' => 'v1'],
            $this->apiHeaders(),
        )->assertOk();

        $this->postJson('/api/worker/heartbeat', [
            'worker_id' => 'w-heart',
        ], $this->workerHeaders())->assertOk();

        $worker = WorkerRegistration::query()->where('worker_id', 'w-heart')->firstOrFail();
        self::assertSame('draining', $worker->status);
    }

    public function test_heartbeat_restores_active_after_cohort_resumes(): void
    {
        WorkerRegistration::query()->create($this->workerAttributes(
            'w-heart',
            'ingest',
            build: 'v1',
            status: 'draining',
        ));

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'build_id' => 'v1',
            'drain_intent' => 'active',
            'drained_at' => null,
        ]);

        $this->postJson('/api/worker/heartbeat', [
            'worker_id' => 'w-heart',
        ], $this->workerHeaders())->assertOk();

        $worker = WorkerRegistration::query()->where('worker_id', 'w-heart')->firstOrFail();
        self::assertSame('active', $worker->status);
    }

    public function test_drain_is_namespace_scoped(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'other',
            'description' => 'Other namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $this->postJson(
            '/api/task-queues/ingest/build-ids/drain',
            ['build_id' => 'v1'],
            $this->apiHeaders('default'),
        )->assertOk();

        $response = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders('other'));
        $response->assertOk();
        self::assertCount(0, $response->json('build_ids'));
    }

    public function test_drain_requires_authentication_when_token_driver_configured(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'secret']);

        $this->postJson(
            '/api/task-queues/ingest/build-ids/drain',
            ['build_id' => 'v1'],
            $this->apiHeaders(),
        )->assertUnauthorized();
    }

    private function apiHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    private function workerHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Protocol-Version' => '1.0',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workerAttributes(
        string $workerId,
        string $taskQueue,
        ?string $build = null,
        string $status = 'active',
    ): array {
        return [
            'worker_id' => $workerId,
            'namespace' => 'default',
            'task_queue' => $taskQueue,
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => $build,
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 100,
            'max_concurrent_activity_tasks' => 100,
            'last_heartbeat_at' => now(),
            'status' => $status,
        ];
    }
}
