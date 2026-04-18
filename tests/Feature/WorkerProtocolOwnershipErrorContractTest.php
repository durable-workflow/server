<?php

namespace Tests\Feature;

use App\Support\ControlPlaneProtocol;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\WorkflowStub;

class WorkerProtocolOwnershipErrorContractTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    public function test_workflow_task_ownership_errors_use_worker_protocol_contract(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-ownership-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorker(
            workerId: 'workflow-owner-worker',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'workflow-owner-worker',
            'task_queue' => 'contract-queue',
            'history_page_size' => 1,
        ], $this->mixedWorkerHeaders());

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-worker-ownership-contract')
            ->assertJsonPath('task.lease_owner', 'workflow-owner-worker');

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $nextHistoryPageToken = $poll->json('task.next_history_page_token');

        $this->assertIsString($nextHistoryPageToken);

        $historyMismatch = $this->postJson("/api/worker/workflow-tasks/{$taskId}/history", [
            'lease_owner' => 'wrong-worker',
            'workflow_task_attempt' => $attempt,
            'next_history_page_token' => $nextHistoryPageToken,
        ], $this->mixedWorkerHeaders());

        $this->assertWorkerProtocolError($historyMismatch, 409, 'lease_owner_mismatch')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('lease_owner', 'workflow-owner-worker');

        $historyInvalidToken = $this->postJson("/api/worker/workflow-tasks/{$taskId}/history", [
            'lease_owner' => 'workflow-owner-worker',
            'workflow_task_attempt' => $attempt,
            'next_history_page_token' => 'not base64',
        ], $this->mixedWorkerHeaders());

        $this->assertWorkerProtocolError($historyInvalidToken, 400, 'invalid_page_token')
            ->assertJsonPath('task_id', $taskId);

        $heartbeat = $this->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
            'lease_owner' => 'wrong-worker',
            'workflow_task_attempt' => $attempt,
        ], $this->mixedWorkerHeaders());

        $this->assertWorkerProtocolError($heartbeat, 409, 'lease_owner_mismatch')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('lease_owner', 'workflow-owner-worker');

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'wrong-worker',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'complete_workflow',
                    'result' => Serializer::serializeWithCodec('json', ['ok' => true]),
                ],
            ],
        ], $this->mixedWorkerHeaders());

        $this->assertWorkerProtocolError($complete, 409, 'lease_owner_mismatch')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('lease_owner', 'workflow-owner-worker');

        $fail = $this->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
            'lease_owner' => 'wrong-worker',
            'workflow_task_attempt' => $attempt,
            'failure' => [
                'message' => 'Lost replay ownership.',
                'type' => 'OwnershipError',
            ],
        ], $this->mixedWorkerHeaders());

        $this->assertWorkerProtocolError($fail, 409, 'lease_owner_mismatch')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('lease_owner', 'workflow-owner-worker');
    }

    public function test_activity_task_ownership_errors_use_worker_protocol_contract(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(
            ExternalGreetingWorkflow::class,
            'wf-activity-ownership-contract',
        );
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $this->registerWorker(
            workerId: 'activity-owner-worker',
            taskQueue: 'external-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $poll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'activity-owner-worker',
            'task_queue' => 'external-activities',
        ], $this->mixedWorkerHeaders());

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-activity-ownership-contract')
            ->assertJsonPath('task.lease_owner', 'activity-owner-worker');

        $taskId = (string) $poll->json('task.task_id');
        $attemptId = (string) $poll->json('task.activity_attempt_id');

        $heartbeat = $this->postJson("/api/worker/activity-tasks/{$taskId}/heartbeat", [
            'activity_attempt_id' => $attemptId,
            'lease_owner' => 'wrong-activity-worker',
            'message' => 'still working',
        ], $this->mixedWorkerHeaders());

        $this->assertWorkerProtocolError($heartbeat, 409, 'lease_owner_mismatch')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('lease_owner', 'activity-owner-worker');

        $complete = $this->postJson("/api/worker/activity-tasks/{$taskId}/complete", [
            'activity_attempt_id' => $attemptId,
            'lease_owner' => 'wrong-activity-worker',
            'result' => Serializer::serializeWithCodec('json', 'Hello, Ada!'),
        ], $this->mixedWorkerHeaders());

        $this->assertWorkerProtocolError($complete, 409, 'lease_owner_mismatch')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('lease_owner', 'activity-owner-worker');

        $fail = $this->postJson("/api/worker/activity-tasks/{$taskId}/fail", [
            'activity_attempt_id' => $attemptId,
            'lease_owner' => 'wrong-activity-worker',
            'failure' => [
                'message' => 'Lost activity ownership.',
                'type' => 'OwnershipError',
            ],
        ], $this->mixedWorkerHeaders());

        $this->assertWorkerProtocolError($fail, 409, 'lease_owner_mismatch')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('lease_owner', 'activity-owner-worker');
    }

    private function assertWorkerProtocolError(TestResponse $response, int $status, string $reason): TestResponse
    {
        $response->assertStatus($status)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', $reason)
            ->assertJsonPath('error', static fn (mixed $error): bool => is_string($error) && $error !== '')
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonMissingPath('control_plane');

        return $response;
    }

    /**
     * Include both protocol headers to prove worker-plane routes keep the
     * worker envelope when mixed clients send extra control-plane metadata.
     *
     * @return array<string, string>
     */
    private function mixedWorkerHeaders(): array
    {
        return $this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];
    }
}
