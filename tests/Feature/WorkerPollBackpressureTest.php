<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\LongPollWaitSlotStore;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;

class WorkerPollBackpressureTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
        $this->registerWorker(
            workerId: 'backpressured-worker',
            taskQueue: 'backpressure-queue',
            supportedWorkflowTypes: ['BackpressureWorkflow'],
            supportedActivityTypes: ['BackpressureActivity'],
        );

        $worker = WorkerRegistration::query()
            ->where('worker_id', 'backpressured-worker')
            ->firstOrFail();
        $worker->forceFill([
            'runtime' => 'python',
            'capabilities' => ['query_tasks'],
        ])->save();

        config([
            'server.polling.timeout' => 1,
            'server.polling.max_concurrent_waits' => 0,
            'server.query_tasks.max_concurrent_poll_waits' => 0,
        ]);
    }

    public function test_rust_workflow_poll_exhaustion_returns_compatible_bounded_backpressure(): void
    {
        $this->setWorkerRuntime('rust');

        $this->assertBackpressuredPoll('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'backpressured-worker',
            'task_queue' => 'backpressure-queue',
            'poll_request_id' => 'workflow-backpressure-1',
            'timeout_seconds' => 1,
        ], 'workflow_task', 'worker');
    }

    public function test_python_activity_poll_exhaustion_returns_compatible_bounded_backpressure(): void
    {
        $this->assertBackpressuredPoll('/api/worker/activity-tasks/poll', [
            'worker_id' => 'backpressured-worker',
            'task_queue' => 'backpressure-queue',
            'poll_request_id' => 'activity-backpressure-1',
            'timeout_seconds' => 1,
        ], 'activity_task', 'worker');
    }

    public function test_php_query_poll_exhaustion_returns_compatible_bounded_backpressure(): void
    {
        $this->setWorkerRuntime('php');

        $this->assertBackpressuredPoll('/api/worker/query-tasks/poll', [
            'worker_id' => 'backpressured-worker',
            'task_queue' => 'backpressure-queue',
            'poll_request_id' => 'query-backpressure-1',
            'timeout_seconds' => 1,
        ], 'query_task', 'query-task');
    }

    public function test_backpressure_response_enforces_the_advertised_cooldown(): void
    {
        $startedAt = microtime(true);

        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'backpressured-worker',
                'task_queue' => 'backpressure-queue',
                'poll_request_id' => "bounded-workflow-backpressure-{$attempt}",
                'timeout_seconds' => 1,
            ], $this->workerHeaders())
                ->assertOk()
                ->assertHeader('Retry-After', '1')
                ->assertJsonPath('task', null)
                ->assertJsonPath('poll_status', 'empty')
                ->assertJsonPath('reason', 'long_poll_capacity_exhausted');
        }

        $this->assertGreaterThanOrEqual(
            1.9,
            microtime(true) - $startedAt,
            'Compatibility backpressure must bound clients that immediately repoll after an empty response.',
        );
        $worker = WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', 'backpressured-worker')
            ->firstOrFail();

        $this->assertSame('backpressure-queue', $worker->task_queue);
        $this->assertSame('active', $worker->status);
    }

    public function test_namespace_wait_exhaustion_does_not_consume_another_namespaces_slot(): void
    {
        config([
            'server.polling.max_concurrent_waits' => 2,
            'server.polling.max_concurrent_waits_per_namespace' => 1,
        ]);
        $this->createNamespace('tenant-b');
        $this->registerWorker(
            workerId: 'tenant-b-worker',
            taskQueue: 'backpressure-queue',
            namespace: 'tenant-b',
            supportedWorkflowTypes: ['BackpressureWorkflow'],
        );

        /** @var LongPollWaitSlotStore $waitSlots */
        $waitSlots = app(LongPollWaitSlotStore::class);
        $heldDefaultSlot = $waitSlots->tryAcquire(2, 'default');
        $this->assertNotNull($heldDefaultSlot);

        try {
            $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'backpressured-worker',
                'task_queue' => 'backpressure-queue',
                'poll_request_id' => 'default-namespace-capacity',
                'timeout_seconds' => 1,
            ], $this->workerHeaders())
                ->assertOk()
                ->assertJsonPath('reason', 'long_poll_capacity_exhausted');

            $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'tenant-b-worker',
                'task_queue' => 'backpressure-queue',
                'poll_request_id' => 'tenant-b-available-capacity',
                'timeout_seconds' => 1,
            ], $this->workerHeaders('tenant-b'))
                ->assertOk()
                ->assertJsonPath('task', null)
                ->assertJsonPath('poll_status', 'empty')
                ->assertJsonMissingPath('reason');
        } finally {
            $heldDefaultSlot->release();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertBackpressuredPoll(
        string $path,
        array $payload,
        string $taskKind,
        string $waitPool,
    ): void {
        $this->postJson($path, $payload, $this->workerHeaders())
            ->assertOk()
            ->assertHeader('Retry-After', '1')
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty')
            ->assertJsonPath('reason', 'long_poll_capacity_exhausted')
            ->assertJsonPath('task_kind', $taskKind)
            ->assertJsonPath('wait_pool', $waitPool)
            ->assertJsonPath('retry_after_seconds', 1);
    }

    private function setWorkerRuntime(string $runtime): void
    {
        WorkerRegistration::query()
            ->where('worker_id', 'backpressured-worker')
            ->update(['runtime' => $runtime]);
    }
}
