<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\Support\WorkerCompatibilityFleet;

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

        WorkerCompatibilityFleet::clear();
    }

    protected function tearDown(): void
    {
        WorkerCompatibilityFleet::clear();

        parent::tearDown();
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

    public function test_promote_selects_build_id_for_new_starts_and_surfaces_rollout_state(): void
    {
        $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => 'w-v2',
            'task_queue' => 'ingest',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'v2',
        ], $this->workerHeaders())->assertCreated();

        $response = $this->postJson(
            '/api/task-queues/ingest/build-ids/promote',
            ['build_id' => 'v2'],
            $this->apiHeaders(),
        );

        $response->assertOk();
        $response->assertJsonPath('build_id', 'v2');
        $response->assertJsonPath('drain_intent', 'active');
        $response->assertJsonPath('new_start_selected', true);
        $response->assertJsonPath('deployment.state', 'promoted');
        self::assertIsString($response->json('promoted_at'));
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $response->json('promoted_at'),
        );
        self::assertSame($response->json('promoted_at'), $response->json('deployment.promoted_at'));

        $buildIds = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders());
        $entry = collect($buildIds->json('build_ids'))->firstWhere('build_id', 'v2');

        self::assertNotNull($entry);
        self::assertSame($response->json('promoted_at'), $entry['promoted_at']);
        self::assertTrue($entry['new_start_selected']);
    }

    public function test_resume_of_older_promoted_build_does_not_report_new_start_selected(): void
    {
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'build_id' => 'v1',
            'drain_intent' => 'draining',
            'drained_at' => now()->subMinutes(5),
            'promoted_at' => now()->subMinutes(10),
        ]);
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'build_id' => 'v2',
            'drain_intent' => 'active',
            'promoted_at' => now()->subMinute(),
        ]);

        $resume = $this->postJson(
            '/api/task-queues/ingest/build-ids/resume',
            ['build_id' => 'v1'],
            $this->apiHeaders(),
        );

        $resume->assertOk();
        $resume->assertJsonPath('build_id', 'v1');
        $resume->assertJsonPath('new_start_selected', false);

        $buildIds = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders())
            ->assertOk()
            ->json('build_ids');
        $entries = collect($buildIds)->keyBy('build_id');

        self::assertFalse($entries['v1']['new_start_selected']);
        self::assertTrue($entries['v2']['new_start_selected']);
    }

    public function test_new_start_selection_breaks_promotion_ties_by_latest_rollout_row(): void
    {
        $promotedAt = now()->subMinute();

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'build_id' => 'v1',
            'drain_intent' => 'active',
            'promoted_at' => $promotedAt,
        ]);
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'build_id' => 'v2',
            'drain_intent' => 'active',
            'promoted_at' => $promotedAt,
        ]);

        $buildIds = $this->getJson('/api/task-queues/ingest/build-ids', $this->apiHeaders())
            ->assertOk()
            ->json('build_ids');
        $entries = collect($buildIds)->keyBy('build_id');

        self::assertFalse($entries['v1']['new_start_selected']);
        self::assertTrue($entries['v2']['new_start_selected']);

        $this->postJson(
            '/api/task-queues/ingest/build-ids/resume',
            ['build_id' => 'v1'],
            $this->apiHeaders(),
        )->assertOk()
            ->assertJsonPath('new_start_selected', false);
    }

    public function test_promote_response_uses_the_same_tie_breaker_as_new_start_routing(): void
    {
        $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => 'w-v1',
            'task_queue' => 'ingest',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'v1',
        ], $this->workerHeaders())->assertCreated();
        $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => 'w-v2',
            'task_queue' => 'ingest',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'v2',
        ], $this->workerHeaders())->assertCreated();

        $this->travelTo(now());

        $this->postJson(
            '/api/task-queues/ingest/build-ids/promote',
            ['build_id' => 'v1'],
            $this->apiHeaders(),
        )->assertOk()
            ->assertJsonPath('new_start_selected', true);

        $this->postJson(
            '/api/task-queues/ingest/build-ids/promote',
            ['build_id' => 'v2'],
            $this->apiHeaders(),
        )->assertOk()
            ->assertJsonPath('new_start_selected', true);

        $this->postJson(
            '/api/task-queues/ingest/build-ids/promote',
            ['build_id' => 'v1'],
            $this->apiHeaders(),
        )->assertOk()
            ->assertJsonPath('new_start_selected', false);
    }

    public function test_promote_refuses_when_no_compatible_worker_is_visible(): void
    {
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'build_id' => 'v-missing',
            'drain_intent' => 'active',
            'required_compatibility' => 'v-missing',
        ]);

        $response = $this->postJson(
            '/api/task-queues/ingest/build-ids/promote',
            ['build_id' => 'v-missing'],
            $this->apiHeaders(),
        );

        $response->assertStatus(409);
        $response->assertJsonPath('reason', 'no_compatible_workers');
        $response->assertJsonPath('rejection_reason', 'no_compatible_workers');
        $response->assertJsonPath('outcome', 'rejected_no_compatible_workers');
        $response->assertJsonPath('command_status', 'rejected');
        $response->assertJsonPath('command_source', 'control_plane');
        self::assertIsString($response->json('message'));
        self::assertIsString($response->json('expected_resolution'));
        $reasons = array_column($response->json('blockages'), 'reason');
        self::assertContains('no_compatible_workers', $reasons);
    }

    public function test_promote_refuses_no_compatible_workers_when_other_builds_are_active(): void
    {
        $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => 'w-v1',
            'task_queue' => 'ingest',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'v1',
        ], $this->workerHeaders())->assertCreated();

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'build_id' => 'v2',
            'drain_intent' => 'active',
            'required_compatibility' => 'v2',
        ]);

        $response = $this->postJson(
            '/api/task-queues/ingest/build-ids/promote',
            ['build_id' => 'v2'],
            $this->apiHeaders(),
        );

        $response->assertStatus(409);
        $response->assertJsonPath('reason', 'no_compatible_workers');
        $response->assertJsonPath('rejection_reason', 'no_compatible_workers');
        self::assertContains('no_compatible_workers', array_column($response->json('blockages'), 'reason'));
        self::assertNotContains('missing_worker_heartbeat', array_column($response->json('blockages'), 'reason'));
    }

    public function test_promote_reports_missing_worker_heartbeat_when_required_build_is_stale(): void
    {
        WorkerRegistration::query()->create($this->workerAttributes(
            'w-stale-v2',
            'ingest',
            build: 'v2',
        ));
        WorkerRegistration::query()
            ->where('worker_id', 'w-stale-v2')
            ->update(['last_heartbeat_at' => now()->subMinutes(5)]);

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'build_id' => 'v2',
            'drain_intent' => 'active',
            'required_compatibility' => 'v2',
        ]);

        $response = $this->postJson(
            '/api/task-queues/ingest/build-ids/promote',
            ['build_id' => 'v2'],
            $this->apiHeaders(),
        );

        $response->assertStatus(409);
        $response->assertJsonPath('reason', 'missing_worker_heartbeat');
        $response->assertJsonPath('rejection_reason', 'missing_worker_heartbeat');
        self::assertContains('missing_worker_heartbeat', array_column($response->json('blockages'), 'reason'));
        self::assertNotContains('no_compatible_workers', array_column($response->json('blockages'), 'reason'));
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
        self::assertSame('draining', $entry['rollout_status']);
        self::assertSame(0, $entry['active_worker_count']);
        self::assertSame(1, $entry['draining_worker_count']);
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
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
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

    public function test_draining_workers_stop_polling_for_new_tasks_until_the_cohort_is_resumed(): void
    {
        WorkerRegistration::query()->create($this->workerAttributes('w-drain', 'ingest', build: 'v1'));

        $this->postJson(
            '/api/task-queues/ingest/build-ids/drain',
            ['build_id' => 'v1'],
            $this->apiHeaders(),
        )->assertOk();

        $worker = WorkerRegistration::query()->where('worker_id', 'w-drain')->firstOrFail();
        self::assertSame('draining', $worker->status);

        foreach ([
            ['/api/worker/workflow-tasks/poll', ['worker_id' => 'w-drain', 'task_queue' => 'ingest']],
            ['/api/worker/activity-tasks/poll', ['worker_id' => 'w-drain', 'task_queue' => 'ingest']],
            ['/api/worker/query-tasks/poll', ['worker_id' => 'w-drain', 'task_queue' => 'ingest']],
        ] as [$path, $body]) {
            $this->postJson($path, $body, $this->workerHeaders())
                ->assertStatus(409)
                ->assertJsonPath('task', null)
                ->assertJsonPath('poll_status', 'draining')
                ->assertJsonPath('reason', 'worker_draining')
                ->assertJsonPath('worker_id', 'w-drain')
                ->assertJsonPath('task_queue', 'ingest')
                ->assertJsonPath('registered_build_id', 'v1')
                ->assertJsonPath('worker_status', 'draining')
                ->assertJsonPath('drain_intent', 'draining');
        }
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
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
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
