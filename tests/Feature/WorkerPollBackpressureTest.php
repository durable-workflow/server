<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
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

    public function test_workflow_poll_exhaustion_returns_retryable_backpressure(): void
    {
        $this->assertBackpressuredPoll('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'backpressured-worker',
            'task_queue' => 'backpressure-queue',
            'poll_request_id' => 'workflow-backpressure-1',
            'timeout_seconds' => 1,
        ], 'workflow_task', 'worker');
    }

    public function test_activity_poll_exhaustion_returns_retryable_backpressure(): void
    {
        $this->assertBackpressuredPoll('/api/worker/activity-tasks/poll', [
            'worker_id' => 'backpressured-worker',
            'task_queue' => 'backpressure-queue',
            'poll_request_id' => 'activity-backpressure-1',
            'timeout_seconds' => 1,
        ], 'activity_task', 'worker');
    }

    public function test_query_poll_exhaustion_returns_retryable_backpressure(): void
    {
        $this->assertBackpressuredPoll('/api/worker/query-tasks/poll', [
            'worker_id' => 'backpressured-worker',
            'task_queue' => 'backpressure-queue',
            'poll_request_id' => 'query-backpressure-1',
            'timeout_seconds' => 1,
        ], 'query_task', 'query-task');
    }

    public function test_php_worker_keeps_protocol_level_backpressure_for_release_compatibility(): void
    {
        WorkerRegistration::query()
            ->where('worker_id', 'backpressured-worker')
            ->update(['runtime' => 'php']);

        $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'backpressured-worker',
            'task_queue' => 'backpressure-queue',
            'poll_request_id' => 'php-workflow-backpressure-1',
            'timeout_seconds' => 1,
        ], $this->workerHeaders())
            ->assertOk()
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty')
            ->assertJsonPath('reason', 'long_poll_capacity_exhausted');
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
            ->assertStatus(429)
            ->assertHeader('Retry-After', '1')
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'unavailable')
            ->assertJsonPath('reason', 'long_poll_capacity_exhausted')
            ->assertJsonPath('task_kind', $taskKind)
            ->assertJsonPath('wait_pool', $waitPool)
            ->assertJsonPath('retry_after_seconds', 1);
    }
}
