<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskQueueBuildIdsTest extends TestCase
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

    public function test_returns_empty_build_ids_when_no_workers_registered(): void
    {
        $response = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders());

        $response->assertOk();
        $response->assertExactJson([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'stale_after_seconds' => 60,
            'build_ids' => [],
        ]);
    }

    public function test_aggregates_active_workers_per_build_id(): void
    {
        $this->createWorker('w1', 'ingest', build: 'v2025.01.20-a3f');
        $this->createWorker('w2', 'ingest', build: 'v2025.01.20-a3f', sdk: 'python-1.4.2');
        $this->createWorker('w3', 'ingest', build: 'v2025.01.21-b41', runtime: 'python');

        $response = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders());

        $response->assertOk();
        $response->assertJsonCount(2, 'build_ids');

        $byId = collect($response->json('build_ids'))
            ->keyBy('build_id')
            ->all();

        self::assertSame(2, $byId['v2025.01.20-a3f']['active_worker_count']);
        self::assertSame(0, $byId['v2025.01.20-a3f']['stale_worker_count']);
        self::assertSame(2, $byId['v2025.01.20-a3f']['total_worker_count']);
        self::assertSame('active', $byId['v2025.01.20-a3f']['rollout_status']);

        self::assertSame(1, $byId['v2025.01.21-b41']['active_worker_count']);
        self::assertSame('active', $byId['v2025.01.21-b41']['rollout_status']);
    }

    public function test_groups_unversioned_workers_under_null_build_id(): void
    {
        $this->createWorker('w-unversioned', 'ingest', build: null);
        $this->createWorker('w-versioned', 'ingest', build: 'v2025.01.20-a3f');

        $response = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders());

        $response->assertOk();
        $entries = $response->json('build_ids');
        $unversioned = collect($entries)->firstWhere('build_id', null);

        self::assertNotNull($unversioned, 'expected an unversioned cohort entry');
        self::assertSame(1, $unversioned['active_worker_count']);
        self::assertSame('active', $unversioned['rollout_status']);
    }

    public function test_marks_only_stale_build_ids_as_stale_only(): void
    {
        $this->createWorkerWithHeartbeat('w-old', 'ingest', build: 'v-old', heartbeatAgo: 600);

        $response = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders());

        $response->assertOk();
        $entry = collect($response->json('build_ids'))->firstWhere('build_id', 'v-old');

        self::assertSame(0, $entry['active_worker_count']);
        self::assertSame(1, $entry['stale_worker_count']);
        self::assertSame('stale_only', $entry['rollout_status']);
    }

    public function test_marks_active_with_draining_when_active_and_draining_workers_coexist(): void
    {
        $this->createWorker('w-active', 'ingest', build: 'v-mixed');

        WorkerRegistration::query()->create([
            'worker_id' => 'w-draining',
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'v-mixed',
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 0,
            'max_concurrent_activity_tasks' => 0,
            'last_heartbeat_at' => now(),
            'status' => 'draining',
        ]);

        $response = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders());

        $response->assertOk();
        $entry = collect($response->json('build_ids'))->firstWhere('build_id', 'v-mixed');

        self::assertSame(1, $entry['active_worker_count']);
        self::assertSame(1, $entry['draining_worker_count']);
        self::assertSame('active_with_draining', $entry['rollout_status']);
    }

    public function test_marks_only_draining_workers_as_draining_status(): void
    {
        WorkerRegistration::query()->create([
            'worker_id' => 'w-draining',
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'v-old',
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 0,
            'max_concurrent_activity_tasks' => 0,
            'last_heartbeat_at' => now(),
            'status' => 'draining',
        ]);

        $response = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders());

        $response->assertOk();
        $entry = collect($response->json('build_ids'))->firstWhere('build_id', 'v-old');

        self::assertSame(0, $entry['active_worker_count']);
        self::assertSame(1, $entry['draining_worker_count']);
        self::assertSame('draining', $entry['rollout_status']);
    }

    public function test_collects_distinct_runtimes_and_sdk_versions_per_build_id(): void
    {
        $this->createWorker('w-py-a', 'ingest', build: 'v1', runtime: 'python', sdk: 'sdk-1.0');
        $this->createWorker('w-py-b', 'ingest', build: 'v1', runtime: 'python', sdk: 'sdk-1.1');
        $this->createWorker('w-php', 'ingest', build: 'v1', runtime: 'php', sdk: 'sdk-1.0');

        $response = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders());

        $entry = collect($response->json('build_ids'))->firstWhere('build_id', 'v1');
        self::assertSame(['php', 'python'], $entry['runtimes']);
        self::assertSame(['sdk-1.0', 'sdk-1.1'], $entry['sdk_versions']);
    }

    public function test_orders_build_ids_active_first_then_draining_then_stale_with_unversioned_last(): void
    {
        $this->createWorkerWithHeartbeat('w-stale', 'ingest', build: 'v-stale', heartbeatAgo: 600);
        $this->createWorker('w-unversioned-active', 'ingest', build: null);
        $this->createWorker('w-active', 'ingest', build: 'v-active');

        $response = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders());

        $order = array_map(
            static fn (array $entry) => [$entry['build_id'], $entry['rollout_status']],
            $response->json('build_ids'),
        );

        self::assertSame([
            ['v-active', 'active'],
            [null, 'active'],
            ['v-stale', 'stale_only'],
        ], $order);
    }

    public function test_filters_to_one_task_queue(): void
    {
        $this->createWorker('w-ingest', 'ingest', build: 'v1');
        $this->createWorker('w-other', 'shipping', build: 'v1');

        $response = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders());

        $response->assertOk();
        $response->assertJsonCount(1, 'build_ids');
        $response->assertJsonPath('task_queue', 'ingest');
    }

    public function test_is_namespace_scoped(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'other',
            'description' => 'Other namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $this->createWorker('w-default', 'ingest', build: 'v1', namespace: 'default');
        $this->createWorker('w-other', 'ingest', build: 'v2', namespace: 'other');

        $defaultResponse = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders('default'));
        $defaultResponse->assertOk();
        $defaultResponse->assertJsonCount(1, 'build_ids');
        $defaultResponse->assertJsonPath('build_ids.0.build_id', 'v1');

        $otherResponse = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders('other'));
        $otherResponse->assertOk();
        $otherResponse->assertJsonCount(1, 'build_ids');
        $otherResponse->assertJsonPath('build_ids.0.build_id', 'v2');
    }

    public function test_requires_authentication_when_token_driver_configured(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'secret']);

        $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders())
            ->assertUnauthorized();
    }

    public function test_exposes_first_seen_at_and_last_heartbeat_at_on_each_cohort(): void
    {
        $earlier = now()->subDays(2)->startOfSecond();
        $later = now()->subMinutes(5)->startOfSecond();

        $old = new WorkerRegistration([
            'worker_id' => 'w-old',
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'v1',
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 0,
            'max_concurrent_activity_tasks' => 0,
            'last_heartbeat_at' => $earlier,
            'status' => 'active',
        ]);
        $old->timestamps = false;
        $old->created_at = $earlier;
        $old->updated_at = $earlier;
        $old->save();

        $new = new WorkerRegistration([
            'worker_id' => 'w-new',
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'v1',
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 0,
            'max_concurrent_activity_tasks' => 0,
            'last_heartbeat_at' => $later,
            'status' => 'active',
        ]);
        $new->timestamps = false;
        $new->created_at = now()->subHours(1)->startOfSecond();
        $new->updated_at = $later;
        $new->save();

        $response = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders());

        $entry = collect($response->json('build_ids'))->firstWhere('build_id', 'v1');
        self::assertSame($earlier->toJSON(), $entry['first_seen_at']);
        self::assertSame($later->toJSON(), $entry['last_heartbeat_at']);
    }

    private function apiHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    private function createWorker(
        string $workerId,
        string $taskQueue,
        ?string $build = null,
        string $runtime = 'php',
        ?string $sdk = '1.0.0',
        string $namespace = 'default',
    ): void {
        WorkerRegistration::query()->create([
            'worker_id' => $workerId,
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'runtime' => $runtime,
            'sdk_version' => $sdk,
            'build_id' => $build,
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 100,
            'max_concurrent_activity_tasks' => 100,
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);
    }

    private function createWorkerWithHeartbeat(
        string $workerId,
        string $taskQueue,
        ?string $build,
        int $heartbeatAgo,
    ): void {
        WorkerRegistration::query()->create([
            'worker_id' => $workerId,
            'namespace' => 'default',
            'task_queue' => $taskQueue,
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => $build,
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 0,
            'max_concurrent_activity_tasks' => 0,
            'last_heartbeat_at' => now()->subSeconds($heartbeatAgo),
            'status' => 'active',
        ]);
    }
}
