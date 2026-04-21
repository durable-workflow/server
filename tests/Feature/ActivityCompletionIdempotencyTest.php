<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\WorkflowStub;

/**
 * The standalone server runs in service mode (external workers poll over HTTP),
 * so the PHP queue never receives workflow or activity task jobs. Before the
 * fix, TaskDispatcher and TaskBackendCapabilities still ran the queue-driver
 * capability check on every task lifecycle event. With a sync queue driver the
 * check raised an error-severity issue, which caused
 *   - activity completions to return 500 after the outcome was already committed
 *     (the exception fired from DB::afterCommit inside the transaction),
 * and the SDK retry then saw 409 stale_attempt because the completion really
 * had landed on the first request.
 *
 * AppServiceProvider now defaults workflows.v2.task_dispatch_mode=poll when the
 * server runs in service mode, so the capability check correctly marks sync-ish
 * queue backends as acceptable and the task lifecycle no longer throws.
 */
class ActivityCompletionIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public function test_activity_complete_returns_success_then_stale_attempt_on_retry_in_service_mode(): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            [
                'description' => 'Default namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-external-complete-idem');
        $start = $workflow->start('Idem');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $this->registerWorker('php-worker-complete-idem', 'external-activities');

        $workerHeaders = $this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];

        $poll = $this->withHeaders($workerHeaders)
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-complete-idem',
                'task_queue' => 'external-activities',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attemptId = (string) $poll->json('task.activity_attempt_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $this->assertNotSame('', $taskId);
        $this->assertNotSame('', $attemptId);

        // First complete call — service mode must not throw from the post-commit
        // dispatch path even though the underlying queue driver is sync.
        $complete = $this->withHeaders($workerHeaders)
            ->postJson("/api/worker/activity-tasks/{$taskId}/complete", [
                'activity_attempt_id' => $attemptId,
                'lease_owner' => $leaseOwner,
                'result' => 'Hello, Idem!',
            ]);

        $complete->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonMissingPath('control_plane')
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true);

        // Idempotent retry — if the SDK retries after a transient disconnect,
        // it should see stale_attempt, not a 500.
        $retry = $this->withHeaders($workerHeaders)
            ->postJson("/api/worker/activity-tasks/{$taskId}/complete", [
                'activity_attempt_id' => $attemptId,
                'lease_owner' => $leaseOwner,
                'result' => 'Hello, Idem!',
            ]);

        $retry->assertStatus(409)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonMissingPath('control_plane')
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('reason', 'stale_attempt');
    }
}
