<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\Support\WorkerCompatibilityFleet;

/**
 * Pins the first-class worker deployment lifecycle HTTP surface
 * (`/api/deployments/...`) frozen by the workflow package's
 * docs/architecture/worker-deployment.md contract. The legacy
 * `/api/task-queues/{taskQueue}/build-ids/{drain|resume}` routes are
 * covered separately; this suite covers the new shape.
 */
class DeploymentLifecycleTest extends TestCase
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

    public function test_index_lists_deployments_projected_from_workers_and_rollouts(): void
    {
        $this->createWorker('w1', 'ingest', 'v1');
        $this->createWorker('w2', 'ingest', 'v2');

        $response = $this->getJson('/api/deployments', $this->apiHeaders());

        $response->assertOk();
        $this->assertSame('default', $response->json('namespace'));

        $names = collect($response->json('deployments'))->pluck('name')->all();
        $this->assertContains('default/ingest@v1', $names);
        $this->assertContains('default/ingest@v2', $names);
    }

    public function test_show_returns_the_deployment_shape_for_an_active_build(): void
    {
        $this->createWorker('w1', 'ingest', 'v1');

        $response = $this->getJson(
            '/api/deployments/'.rawurlencode('default/ingest@v1'),
            $this->apiHeaders(),
        );

        $response->assertOk();
        $response->assertJsonPath('name', 'default/ingest@v1');
        $response->assertJsonPath('namespace', 'default');
        $response->assertJsonPath('task_queue', 'ingest');
        $response->assertJsonPath('build_id', 'v1');
        $response->assertJsonPath('state', 'active');
        $response->assertJsonPath('compatibility_policy', 'pinned');
        $response->assertJsonPath('accepts_new_work', true);
    }

    public function test_show_returns_404_for_unknown_deployment(): void
    {
        $this->getJson(
            '/api/deployments/'.rawurlencode('default/ingest@unknown-build'),
            $this->apiHeaders(),
        )->assertNotFound();
    }

    public function test_promote_refuses_with_machine_readable_blockages_when_no_compatible_workers(): void
    {
        // Pre-create the deployment row so the promote target exists,
        // but leave the fleet empty so the planner reports
        // no_compatible_workers.
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'build_id' => 'v3',
            'drain_intent' => 'active',
            'required_compatibility' => 'v3',
        ]);

        $response = $this->postJson(
            '/api/deployments/'.rawurlencode('default/ingest@v3').'/promote',
            [],
            $this->apiHeaders(),
        );

        $response->assertStatus(409);
        $response->assertJsonPath('reason', 'no_compatible_workers');
        $response->assertJsonPath('rejection_reason', 'no_compatible_workers');
        $response->assertJsonPath('outcome', 'rejected_no_compatible_workers');
        $response->assertJsonPath('command_status', 'rejected');
        $response->assertJsonPath('command_source', 'control_plane');
        $blockages = $response->json('blockages');
        $this->assertIsArray($blockages);
        $this->assertNotEmpty($blockages);

        $reasons = array_column($blockages, 'reason');
        $this->assertContains('no_compatible_workers', $reasons);

        // Every blockage carries a structured scope and an
        // expected_resolution string so operator UIs can route
        // diagnoses without parsing the message.
        $first = $blockages[0];
        $this->assertSame('default', $first['scope']['namespace']);
        $this->assertSame('ingest', $first['scope']['task_queue']);
        $this->assertSame('v3', $first['scope']['build_id']);
        $this->assertNotNull($first['expected_resolution']);
    }

    public function test_promote_responses_identify_effective_new_start_selection_for_competing_promotions(): void
    {
        $this->createWorker('w-v1', 'ingest', 'v1');
        $this->createWorker('w-v2', 'ingest', 'v2');

        $this->travelTo(now()->startOfSecond());

        $this->postJson(
            '/api/deployments/'.rawurlencode('default/ingest@v1').'/promote',
            [],
            $this->apiHeaders(),
        )->assertOk()
            ->assertJsonPath('new_start_selected', true);

        $this->postJson(
            '/api/deployments/'.rawurlencode('default/ingest@v2').'/promote',
            [],
            $this->apiHeaders(),
        )->assertOk()
            ->assertJsonPath('new_start_selected', true);

        $this->postJson(
            '/api/deployments/'.rawurlencode('default/ingest@v1').'/promote',
            [],
            $this->apiHeaders(),
        )->assertOk()
            ->assertJsonPath('new_start_selected', false);

        $deployments = $this->getJson('/api/deployments', $this->apiHeaders())
            ->assertOk()
            ->json('deployments');
        $byName = collect($deployments)->keyBy('name');

        $this->assertFalse($byName['default/ingest@v1']['new_start_selected']);
        $this->assertTrue($byName['default/ingest@v2']['new_start_selected']);
    }

    public function test_drain_moves_the_deployment_to_draining_state(): void
    {
        $this->createWorker('w1', 'ingest', 'v1');

        $response = $this->postJson(
            '/api/deployments/'.rawurlencode('default/ingest@v1').'/drain',
            [],
            $this->apiHeaders(),
        );

        $response->assertOk();
        $response->assertJsonPath('state', 'draining');
        $response->assertJsonPath('accepts_new_work', false);

        $rollout = WorkerBuildIdRollout::query()
            ->where('namespace', 'default')
            ->where('task_queue', 'ingest')
            ->where('build_id', 'v1')
            ->first();

        $this->assertNotNull($rollout);
        $this->assertSame('draining', $rollout->drain_intent);
        $this->assertNotNull($rollout->drained_at);

        $worker = WorkerRegistration::query()
            ->where('worker_id', 'w1')
            ->firstOrFail();

        $this->assertSame('draining', $worker->status);
    }

    public function test_resume_returns_a_draining_deployment_to_active(): void
    {
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'build_id' => 'v1',
            'drain_intent' => 'draining',
            'drained_at' => now(),
        ]);

        $response = $this->postJson(
            '/api/deployments/'.rawurlencode('default/ingest@v1').'/resume',
            [],
            $this->apiHeaders(),
        );

        $response->assertOk();
        $response->assertJsonPath('state', 'active');
        $response->assertJsonPath('accepts_new_work', true);

        $rollout = WorkerBuildIdRollout::query()
            ->where('namespace', 'default')
            ->where('task_queue', 'ingest')
            ->where('build_id', 'v1')
            ->first();

        $this->assertNotNull($rollout);
        $this->assertSame('active', $rollout->drain_intent);
        $this->assertNull($rollout->drained_at);
    }

    public function test_resume_clears_draining_status_from_matching_workers(): void
    {
        $this->createWorker('w1', 'ingest', 'v1');

        WorkerRegistration::query()
            ->where('worker_id', 'w1')
            ->update(['status' => 'draining']);

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'build_id' => 'v1',
            'drain_intent' => 'draining',
            'drained_at' => now(),
        ]);

        $this->postJson(
            '/api/deployments/'.rawurlencode('default/ingest@v1').'/resume',
            [],
            $this->apiHeaders(),
        )->assertOk();

        $worker = WorkerRegistration::query()
            ->where('worker_id', 'w1')
            ->firstOrFail();

        $this->assertSame('active', $worker->status);
    }

    public function test_rollback_marks_the_deployment_terminal_and_refuses_a_second_call(): void
    {
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'build_id' => 'v1',
            'drain_intent' => 'active',
            'promoted_at' => now()->subMinutes(5),
        ]);

        $first = $this->postJson(
            '/api/deployments/'.rawurlencode('default/ingest@v1').'/rollback',
            [],
            $this->apiHeaders(),
        );

        $first->assertOk();
        $first->assertJsonPath('state', 'rolled_back');
        $first->assertJsonPath('accepts_new_work', false);

        $second = $this->postJson(
            '/api/deployments/'.rawurlencode('default/ingest@v1').'/rollback',
            [],
            $this->apiHeaders(),
        );

        $second->assertStatus(409);
        $reasons = array_column($second->json('blockages'), 'reason');
        $this->assertContains('incompatible_policy', $reasons);
    }

    public function test_namespace_mismatch_in_path_is_rejected(): void
    {
        $response = $this->postJson(
            '/api/deployments/'.rawurlencode('other-ns/ingest@v1').'/promote',
            [],
            $this->apiHeaders('default'),
        );

        $response->assertStatus(400);
        $this->assertSame('namespace_mismatch', $response->json('error'));
    }

    private function apiHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    private function createWorker(string $workerId, string $taskQueue, ?string $build): void
    {
        WorkerRegistration::query()->create([
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
            'status' => 'active',
        ]);
    }
}
