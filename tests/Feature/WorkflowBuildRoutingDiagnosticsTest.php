<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\AwaitApprovalWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class WorkflowBuildRoutingDiagnosticsTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    private const TYPE = 'tests.await-approval-workflow';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createNamespace('default');
        $this->configureWorkflowTypes([self::TYPE => AwaitApprovalWorkflow::class]);
    }

    public function test_run_debug_explains_drain_exit_resume_and_compatible_recovery(): void
    {
        [$run, $task] = $this->startPinnedRun();
        $this->postJson('/api/task-queues/diagnostic-queue/build-ids/drain', ['build_id' => 'v1'], $this->apiHeaders())
            ->assertOk();
        $this->getJson('/api/workflows/routing-diagnostic', $this->apiHeaders())
            ->assertOk()->assertJsonPath('compatibility_status', 'compatible');

        $finding = $this->routingFinding('workflow_build_draining');
        self::assertSame('draining', $finding['routing_status']);
        self::assertSame($task->id, $finding['task_id']);
        self::assertSame('v1', $finding['required_build_id']);
        self::assertSame('diagnostic-queue', $finding['task_queue']);
        self::assertStringContainsString('Resume does not restart an exited process', $finding['expected_resolution']);
        $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'diagnostic-v1', 'task_queue' => 'diagnostic-queue', 'build_id' => 'v1',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('poll_status', 'draining');

        $this->deleteJson('/api/workers/diagnostic-v1', [], $this->apiHeaders())->assertOk();
        $this->routingFinding('workflow_build_draining');
        $this->postJson('/api/task-queues/diagnostic-queue/build-ids/resume', ['build_id' => 'v1'], $this->apiHeaders())
            ->assertOk();

        $finding = $this->routingFinding('no_eligible_workflow_worker');
        self::assertSame('no_eligible_worker', $finding['routing_status']);
        self::assertSame($task->id, $finding['task_id']);
        self::assertStringContainsString('registers and polls', $finding['next_event']);
        self::assertStringContainsString('does not start a worker process', $finding['expected_resolution']);
        self::assertSame(TaskStatus::Ready, $task->fresh()->status);
        self::assertSame('v1', $run->fresh()->compatibility);

        $this->registerBuild('replacement-v1', 'v1');
        $this->assertNoRoutingFinding();
        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'replacement-v1', 'task_queue' => 'diagnostic-queue', 'build_id' => 'v1',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('task.task_id', $task->id);
        $this->postJson("/api/worker/workflow-tasks/{$task->id}/complete", [
            'lease_owner' => 'replacement-v1',
            'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
            'commands' => [['type' => 'complete_workflow']],
        ], $this->workerHeaders())->assertOk()->assertJsonPath('run_status', 'completed');
        $this->assertNoRoutingFinding();
    }

    #[DataProvider('ineligibleWorkers')]
    public function test_run_debug_does_not_count_an_ineligible_worker(string $case): void
    {
        [, $task] = $this->startPinnedRun();
        WorkerRegistration::query()->where('worker_id', 'diagnostic-v1')->delete();
        WorkerCompatibilityFleet::clear();
        $task->forceFill(['connection' => 'expected-connection'])->save();

        $namespace = $case === 'other namespace' ? 'other' : 'default';
        $queue = $case === 'other queue' ? 'other-queue' : 'diagnostic-queue';
        $build = $case === 'other build' ? 'v2' : 'v1';
        $types = $case === 'unsupported workflow type' ? [] : [self::TYPE];
        $this->createNamespace($namespace);
        $this->registerWorker('candidate', $queue, $namespace, supportedWorkflowTypes: $types);
        WorkerRegistration::query()->where('worker_id', 'candidate')->update([
            'build_id' => $build,
            'status' => match ($case) {
                'draining status' => 'draining',
                'superseded status' => WorkerRegistration::STATUS_SUPERSEDED,
                default => 'active',
            },
            'last_heartbeat_at' => $case === 'stale registration' ? now()->subSeconds(121) : now(),
        ]);
        config(['server.workers.stale_after_seconds' => 120, 'workflows.v2.compatibility.heartbeat_ttl_seconds' => 30]);
        WorkerCompatibilityFleet::recordForNamespace(
            $namespace, [$build],
            connection: $case === 'other connection' ? 'other-connection' : 'expected-connection',
            queue: $queue, workerId: 'candidate',
        );
        if ($case === 'expired compatibility heartbeat') {
            $this->travel(31)->seconds();
        }

        $finding = $this->routingFinding('no_eligible_workflow_worker');
        self::assertSame('v1', $finding['required_build_id']);
        self::assertSame('expected-connection', $finding['connection']);
    }

    public static function ineligibleWorkers(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'draining status', 'superseded status', 'stale registration', 'other namespace', 'other queue',
            'other build', 'unsupported workflow type', 'other connection', 'expired compatibility heartbeat',
        ]);
    }

    public function test_future_work_and_an_ordinary_wait_are_not_reported_as_blocked(): void
    {
        [$run, $task] = $this->startPinnedRun();
        $this->postJson('/api/task-queues/diagnostic-queue/build-ids/drain', ['build_id' => 'v1'], $this->apiHeaders())->assertOk();
        $task->forceFill(['available_at' => now()->addHour()])->save();
        $this->assertNoRoutingFinding();

        $task->forceFill(['status' => TaskStatus::Completed])->save();
        $run->forceFill(['status' => RunStatus::Waiting])->save();
        $this->assertNoRoutingFinding();
    }

    public function test_a_healthy_run_ignores_another_namespace_or_queues_drain(): void
    {
        $this->startPinnedRun();
        $this->createNamespace('other');
        $this->postJson('/api/task-queues/diagnostic-queue/build-ids/drain', ['build_id' => 'v1'], $this->apiHeaders('other'))->assertOk();
        $this->postJson('/api/task-queues/other-queue/build-ids/drain', ['build_id' => 'v1'], $this->apiHeaders())->assertOk();
        $this->assertNoRoutingFinding();
    }

    public function test_terminal_runs_do_not_report_remaining_ready_tasks_as_routing_blocked(): void
    {
        [$run] = $this->startPinnedRun();
        $this->postJson('/api/task-queues/diagnostic-queue/build-ids/drain', ['build_id' => 'v1'], $this->apiHeaders())->assertOk();
        $run->forceFill(['status' => RunStatus::Completed, 'closed_at' => now()])->save();
        $this->assertNoRoutingFinding();
    }

    public function test_an_unversioned_build_drain_has_an_explicit_recovery_finding(): void
    {
        $this->registerWorker('unversioned', 'diagnostic-queue', supportedWorkflowTypes: [self::TYPE]);
        $this->postJson('/api/workflows', [
            'workflow_id' => 'routing-diagnostic', 'workflow_type' => self::TYPE,
            'task_queue' => 'diagnostic-queue',
        ], $this->apiHeaders())->assertCreated();
        $this->postJson('/api/task-queues/diagnostic-queue/build-ids/drain', ['build_id' => null], $this->apiHeaders())->assertOk();
        $finding = $this->routingFinding('workflow_build_draining');
        self::assertNull($finding['required_build_id']);
        self::assertStringContainsString('the unversioned build', $finding['message']);
    }

    private function startPinnedRun(): array
    {
        $this->registerBuild('diagnostic-v1', 'v1');
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'routing-diagnostic', 'workflow_type' => self::TYPE,
            'task_queue' => 'diagnostic-queue',
        ], $this->apiHeaders())->assertCreated();
        $run = WorkflowRun::query()->findOrFail($start->json('run_id'));
        self::assertSame('v1', $run->compatibility);
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->where('status', 'ready')->firstOrFail();
        $this->registerBuild('diagnostic-v2', 'v2');
        $this->postJson('/api/task-queues/diagnostic-queue/build-ids/promote', ['build_id' => 'v2'], $this->apiHeaders())->assertOk();

        return [$run, $task];
    }

    private function registerBuild(string $id, string $build): void
    {
        $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => $id, 'task_queue' => 'diagnostic-queue',
            'runtime' => 'rust', 'sdk_version' => 'durable-workflow-rust/3.4.0',
            'build_id' => $build, 'supported_workflow_types' => [self::TYPE],
        ], $this->workerHeaders())->assertCreated();
    }

    private function routingFinding(string $code): array
    {
        $response = $this->getJson('/api/workflows/routing-diagnostic/debug', $this->apiHeaders())->assertOk();
        $finding = collect($response->json('findings'))->firstWhere('code', $code);
        self::assertIsArray($finding, $response->getContent());

        return $finding;
    }

    private function assertNoRoutingFinding(): void
    {
        $this->getJson('/api/workflows/routing-diagnostic/debug', $this->apiHeaders())
            ->assertOk()->assertJsonMissing(['code' => 'workflow_build_draining'])
            ->assertJsonMissing(['code' => 'no_eligible_workflow_worker']);
    }
}
