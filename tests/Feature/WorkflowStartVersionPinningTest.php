<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use App\Support\WorkflowStartVersionPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkerCompatibilityFleet;

/**
 * Verifies that a workflow started against worker build v1 stays
 * bound to v1 — i.e. the run and its first workflow task are
 * stamped with that build id at start time, and the workflow-task
 * poller refuses to deliver the task to a worker advertising a
 * different build.
 *
 * This is the durable backing for the Replay 2026 worker-versioning
 * acceptance: deploying a v2 worker into the same task queue must
 * not break the v1-started workflow.
 */
class WorkflowStartVersionPinningTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        WorkerCompatibilityFleet::clear();
        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        WorkerCompatibilityFleet::clear();

        parent::tearDown();
    }

    public function test_pins_new_run_to_promoted_build_id_when_one_exists(): void
    {
        $this->seedWorker('w-v1', taskQueue: 'shared', buildId: 'v1.0.0');
        $this->seedWorker('w-v2', taskQueue: 'shared', buildId: 'v2.0.0');

        // Operator has explicitly promoted v2 — new starts go there.
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'shared',
            'build_id' => 'v2.0.0',
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
            'promoted_at' => now()->subMinute(),
        ]);

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'shared',
                'input' => ['name' => 'Ada'],
            ]);

        $response->assertCreated();
        $runId = (string) $response->json('run_id');

        $run = WorkflowRun::query()->findOrFail($runId);
        self::assertSame('v2.0.0', $run->compatibility);

        $task = WorkflowTask::query()->where('workflow_run_id', $runId)->firstOrFail();
        self::assertSame('v2.0.0', $task->compatibility);
    }

    public function test_pins_to_single_active_build_id_when_no_rollout_promoted_yet(): void
    {
        $this->seedWorker('w-v1-only', taskQueue: 'isolated', buildId: 'v1.0.0');

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'isolated',
            ]);

        $response->assertCreated();
        $runId = (string) $response->json('run_id');

        $run = WorkflowRun::query()->findOrFail($runId);
        self::assertSame('v1.0.0', $run->compatibility);
    }

    public function test_promoted_unversioned_cohort_overrides_single_build_fallback(): void
    {
        $this->seedWorker('w-v1-only', taskQueue: 'unversioned-first', buildId: 'v1.0.0');

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'unversioned-first',
            'build_id' => WorkerBuildIdRollout::UNVERSIONED_KEY,
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
            'promoted_at' => now()->subMinute(),
        ]);

        $resolver = $this->app->make(WorkflowStartVersionPin::class);
        self::assertNull($resolver->resolve('default', 'unversioned-first'));

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'unversioned-first',
            ]);

        $response->assertCreated();
        $run = WorkflowRun::query()->findOrFail((string) $response->json('run_id'));
        self::assertNull($run->compatibility);
    }

    public function test_leaves_run_unpinned_when_multiple_active_builds_and_no_promotion(): void
    {
        $this->seedWorker('w-v1', taskQueue: 'mixed', buildId: 'v1.0.0');
        $this->seedWorker('w-v2', taskQueue: 'mixed', buildId: 'v2.0.0');

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'mixed',
            ]);

        $response->assertCreated();
        $runId = (string) $response->json('run_id');

        $run = WorkflowRun::query()->findOrFail($runId);
        self::assertNull($run->compatibility);
    }

    public function test_resolver_prefers_most_recent_promotion(): void
    {
        $this->seedWorker('w-v1', taskQueue: 'shared', buildId: 'v1.0.0');
        $this->seedWorker('w-v2', taskQueue: 'shared', buildId: 'v2.0.0');

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'shared',
            'build_id' => 'v1.0.0',
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
            'promoted_at' => now()->subHour(),
        ]);
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'shared',
            'build_id' => 'v2.0.0',
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
            'promoted_at' => now()->subMinute(),
        ]);

        $resolver = $this->app->make(WorkflowStartVersionPin::class);
        self::assertSame('v2.0.0', $resolver->resolve('default', 'shared'));
    }

    public function test_resolver_breaks_promotion_ties_by_latest_rollout_row(): void
    {
        $this->seedWorker('w-v1', taskQueue: 'shared', buildId: 'v1.0.0');
        $this->seedWorker('w-v2', taskQueue: 'shared', buildId: 'v2.0.0');

        $promotedAt = now()->subMinute();

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'shared',
            'build_id' => 'v1.0.0',
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
            'promoted_at' => $promotedAt,
        ]);
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'shared',
            'build_id' => 'v2.0.0',
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
            'promoted_at' => $promotedAt,
        ]);

        $resolver = $this->app->make(WorkflowStartVersionPin::class);
        self::assertSame('v2.0.0', $resolver->resolve('default', 'shared'));
    }

    public function test_resolver_skips_rolled_back_promotion(): void
    {
        $this->seedWorker('w-v2', taskQueue: 'shared', buildId: 'v2.0.0');

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'shared',
            'build_id' => 'v2.0.0',
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
            'promoted_at' => now()->subHour(),
            'rolled_back_at' => now()->subMinute(),
        ]);

        $resolver = $this->app->make(WorkflowStartVersionPin::class);
        // Single active build_id remains as the fallback.
        self::assertSame('v2.0.0', $resolver->resolve('default', 'shared'));
    }

    public function test_resolver_skips_draining_promotion(): void
    {
        $this->seedWorker('w-v1', taskQueue: 'shared', buildId: 'v1.0.0');
        $this->seedWorker('w-v2', taskQueue: 'shared', buildId: 'v2.0.0');

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'shared',
            'build_id' => 'v2.0.0',
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_DRAINING,
            'promoted_at' => now()->subHour(),
            'drained_at' => now()->subMinute(),
        ]);

        $resolver = $this->app->make(WorkflowStartVersionPin::class);
        self::assertNull($resolver->resolve('default', 'shared'));
    }

    public function test_compatibility_is_surfaced_on_workflow_show_endpoint(): void
    {
        $this->seedWorker('w-v1', taskQueue: 'show-q', buildId: 'v1.0.0');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'show-pinned',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'show-q',
            ])->assertCreated();

        $show = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/show-pinned')
            ->assertOk();

        self::assertSame('v1.0.0', $show->json('compatibility'));
    }

    public function test_incompatible_worker_poll_surfaces_compatibility_blocked_without_claiming_pinned_task(): void
    {
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'worker_id' => 'blocked-worker-v1',
                'task_queue' => 'blocked-q',
                'runtime' => 'php',
                'sdk_version' => '1.0.0',
                'build_id' => 'v1.0.0',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
            ])
            ->assertCreated();

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-compatible-poll-blocked',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'blocked-q',
            ])
            ->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'worker_id' => 'blocked-worker-v2',
                'task_queue' => 'blocked-q',
                'runtime' => 'php',
                'sdk_version' => '1.0.0',
                'build_id' => 'v2.0.0',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
            ])
            ->assertCreated();

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'blocked-worker-v2',
                'task_queue' => 'blocked-q',
                'build_id' => 'v2.0.0',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'compatibility_blocked');

        $task = WorkflowTask::query()->where('workflow_run_id', $runId)->firstOrFail();
        self::assertSame(TaskStatus::Ready, $task->status);
        self::assertNull($task->lease_owner);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-compatible-poll-blocked')
            ->assertOk()
            ->assertJsonPath('compatibility', 'v1.0.0')
            ->assertJsonPath('compatibility_status', 'compatible')
            ->assertJsonPath('compatibility_supported_in_fleet', true);
    }

    public function test_no_compatible_worker_poll_surfaces_explicit_status_without_claiming_pinned_task(): void
    {
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'worker_id' => 'blocked-worker-v1',
                'task_queue' => 'no-compatible-q',
                'runtime' => 'php',
                'sdk_version' => '1.0.0',
                'build_id' => 'v1.0.0',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
            ])
            ->assertCreated();

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-no-compatible-poll',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'no-compatible-q',
            ])
            ->assertCreated();

        $runId = (string) $start->json('run_id');
        WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'blocked-worker-v1')
            ->delete();
        WorkerCompatibilityFleet::clear();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'worker_id' => 'blocked-worker-v2',
                'task_queue' => 'no-compatible-q',
                'runtime' => 'php',
                'sdk_version' => '1.0.0',
                'build_id' => 'v2.0.0',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
            ])
            ->assertCreated();

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'blocked-worker-v2',
                'task_queue' => 'no-compatible-q',
                'build_id' => 'v2.0.0',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'no_compatible_worker');

        $task = WorkflowTask::query()->where('workflow_run_id', $runId)->firstOrFail();
        self::assertSame(TaskStatus::Ready, $task->status);
        self::assertNull($task->lease_owner);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-no-compatible-poll')
            ->assertOk()
            ->assertJsonPath('compatibility', 'v1.0.0')
            ->assertJsonPath('compatibility_status', 'no_compatible_worker')
            ->assertJsonPath('compatibility_supported_in_fleet', false);
    }

    private function seedWorker(string $workerId, string $taskQueue, string $buildId): void
    {
        WorkerRegistration::query()->create([
            'worker_id' => $workerId,
            'namespace' => 'default',
            'task_queue' => $taskQueue,
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => $buildId,
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 100,
            'max_concurrent_activity_tasks' => 100,
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);
    }
}
