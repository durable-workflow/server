<?php

namespace Tests\Feature;

use App\Models\WorkerBuildIdRollout;
use App\Support\ActivityTaskPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingActivity;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

class VersionedActivityRoutingTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    /** @return array<string, array{string, string, bool}> */
    public static function routingCases(): array
    {
        $cases = [];
        foreach (['default', 'tenant-main'] as $namespace) {
            foreach (['build-a', 'build-b'] as $build) {
                foreach ([false, true] as $pinned) {
                    $cases[$namespace.'-'.$build.'-'.($pinned ? 'pinned' : 'unpinned')] = [$namespace, $build, $pinned];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('routingCases')]
    public function test_two_live_builds_route_activities_and_preserve_pins(string $namespace, string $build, bool $pinned): void
    {
        [$workflowId, $runId, $activity] = $this->scheduleActivity($namespace, $build, $pinned);
        $headers = $this->controlPlaneHeadersWithWorkerProtocol($namespace);
        $workerId = 'worker-'.$build;

        if ($pinned) {
            $otherBuild = $build === 'build-a' ? 'build-b' : 'build-a';
            $this->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'worker-'.$otherBuild,
                'task_queue' => 'rolling',
                'timeout_seconds' => 0,
            ], $headers)->assertOk()->assertJsonPath('task', null);
            $this->assertSame(TaskStatus::Ready, $activity->fresh()->status);
            $this->assertSame(0, $activity->fresh()->attempt_count);
        }

        $claim = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'rolling',
            'timeout_seconds' => 0,
        ], $headers)->assertOk()
            ->assertJsonPath('task.task_id', $activity->id)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.attempt_number', 1)
            ->json('task');

        $this->postJson('/api/worker/activity-tasks/'.$activity->id.'/complete', [
            'lease_owner' => $claim['lease_owner'],
            'activity_attempt_id' => $claim['activity_attempt_id'],
            'result' => 'Hello, Ada!',
        ], $headers)->assertOk()->assertJsonPath('recorded', true);

        $resumed = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'rolling',
            'timeout_seconds' => 0,
        ], $headers)->assertOk()->assertJsonPath('task.run_id', $runId)->json('task');
        $this->postJson('/api/worker/workflow-tasks/'.$resumed['task_id'].'/complete', [
            'lease_owner' => $resumed['lease_owner'],
            'workflow_task_attempt' => $resumed['workflow_task_attempt'],
            'commands' => [[
                'type' => 'complete_workflow',
                'result' => Serializer::serializeWithCodec('avro', 'Hello, Ada!'),
            ]],
        ], $headers)->assertOk()->assertJsonPath('recorded', true);

        $this->getJson('/api/workflows/'.$workflowId.'/runs/'.$runId, $headers)
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('output', 'Hello, Ada!');
        $this->getJson('/api/workflows/'.$workflowId.'/runs/'.$runId.'/history', $headers)
            ->assertOk()->assertJsonCount(6, 'events')->assertJsonPath('events.5.event_type', 'WorkflowCompleted');
        $tasks = WorkflowTask::query()->where('workflow_run_id', $runId)->get();
        $this->assertCount(3, $tasks);
        foreach ($tasks as $task) {
            $this->assertSame(TaskStatus::Completed, $task->status);
            $this->assertSame(1, $task->attempt_count);
            $this->assertSame(0, $task->repair_count);
            $this->assertSame($pinned ? $build : null, $task->compatibility);
        }
        $this->assertSame($pinned ? $build : null, WorkflowRun::query()->findOrFail($runId)->compatibility);
    }

    public function test_future_activity_hint_matches_unpinned_and_matching_builds_only(): void
    {
        [, , $activity] = $this->scheduleActivity('tenant-main', 'build-a', false);
        $activity->update(['available_at' => now()->addSeconds(7)]);
        $poller = app(ActivityTaskPoller::class);
        $nextReadyAt = new ReflectionMethod(ActivityTaskPoller::class, 'nextVisibleReadyAt');
        $expected = $activity->fresh()->available_at->format('Y-m-d H:i:s.u');

        foreach (['build-a', 'build-b'] as $build) {
            $actual = $nextReadyAt->invoke($poller, 'tenant-main', 'rolling', $build);
            $this->assertInstanceOf(\DateTimeInterface::class, $actual);
            $this->assertSame($expected, $actual->format('Y-m-d H:i:s.u'));
        }

        $activity->update(['compatibility' => 'build-a']);
        $this->assertNull($nextReadyAt->invoke($poller, 'tenant-main', 'rolling', 'build-b'));
        $this->assertNull($nextReadyAt->invoke($poller, 'tenant-other', 'rolling', 'build-a'));
        $this->assertNull($nextReadyAt->invoke($poller, 'tenant-main', 'other', 'build-a'));
    }

    /** @return array{string, string, WorkflowTask} */
    private function scheduleActivity(string $namespace, string $build, bool $pinned): array
    {
        Queue::fake();
        $this->createNamespace($namespace);
        config([
            'workflows.v2.types.workflows' => ['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class],
            'workflows.v2.types.activities' => ['tests.external-greeting-activity' => ExternalGreetingActivity::class],
        ]);
        $headers = $this->controlPlaneHeadersWithWorkerProtocol($namespace);
        foreach (['build-a', 'build-b'] as $workerBuild) {
            $this->postJson('/api/worker/register', [
                'worker_id' => 'worker-'.$workerBuild,
                'task_queue' => 'rolling',
                'runtime' => 'php',
                'build_id' => $workerBuild,
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'supported_activity_types' => ['tests.external-greeting-activity'],
            ], $headers)->assertCreated();
        }

        $workflowId = 'rolling-'.$namespace.'-'.$build.'-'.($pinned ? 'pinned' : 'unpinned');
        $input = [
            'workflow_id' => $workflowId,
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'rolling',
            'input' => ['Ada'],
        ];
        if ($pinned) {
            WorkerBuildIdRollout::query()->create([
                'namespace' => $namespace,
                'task_queue' => 'rolling',
                'build_id' => $build,
                'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
                'promoted_at' => now()->subSecond(),
            ]);
        }
        $start = $this->postJson('/api/workflows', $input, $headers)->assertCreated();
        $runId = (string) $start->json('run_id');
        $this->assertSame($pinned ? $build : null, WorkflowRun::query()->findOrFail($runId)->compatibility);
        $claim = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-'.$build,
            'task_queue' => 'rolling',
            'timeout_seconds' => 0,
        ], $headers)->assertOk()->assertJsonPath('task.run_id', $runId)->json('task');
        $this->postJson('/api/worker/workflow-tasks/'.$claim['task_id'].'/complete', [
            'lease_owner' => $claim['lease_owner'],
            'workflow_task_attempt' => $claim['workflow_task_attempt'],
            'commands' => [[
                'type' => 'schedule_activity',
                'activity_type' => 'tests.external-greeting-activity',
                'arguments' => Serializer::serializeWithCodec('avro', ['Ada']),
                'queue' => 'rolling',
            ]],
        ], $headers)->assertOk()->assertJsonPath('recorded', true);
        $activity = WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)->sole();
        $this->assertSame(TaskStatus::Ready, $activity->status);
        $this->assertSame(0, $activity->attempt_count);

        return [$workflowId, $runId, $activity];
    }
}
