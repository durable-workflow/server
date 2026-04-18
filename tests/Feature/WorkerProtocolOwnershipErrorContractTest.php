<?php

namespace Tests\Feature;

use App\Support\ControlPlaneProtocol;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowTask;
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

        $heartbeatAttemptMismatch = $this->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
            'lease_owner' => 'workflow-owner-worker',
            'workflow_task_attempt' => $attempt + 1,
        ], $this->mixedWorkerHeaders());

        $this->assertWorkerProtocolError($heartbeatAttemptMismatch, 409, 'workflow_task_attempt_mismatch')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt + 1)
            ->assertJsonPath('current_attempt', $attempt);

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
        $workflowTaskId = (string) WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', 'workflow')
            ->value('id');

        $this->assertNotSame('', $workflowTaskId);

        $missingAttempt = $this->postJson("/api/worker/activity-tasks/{$taskId}/heartbeat", [
            'activity_attempt_id' => 'missing-attempt',
            'lease_owner' => 'activity-owner-worker',
        ], $this->mixedWorkerHeaders());

        $this->assertWorkerProtocolError($missingAttempt, 404, 'attempt_not_found')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', 'missing-attempt');

        $taskMismatch = $this->postJson("/api/worker/activity-tasks/{$workflowTaskId}/complete", [
            'activity_attempt_id' => $attemptId,
            'lease_owner' => 'activity-owner-worker',
            'result' => Serializer::serializeWithCodec('json', 'Hello, Ada!'),
        ], $this->mixedWorkerHeaders());

        $this->assertWorkerProtocolError($taskMismatch, 409, 'task_mismatch')
            ->assertJsonPath('task_id', $workflowTaskId)
            ->assertJsonPath('activity_attempt_id', $attemptId);

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

    /**
     * @return array<string, array{
     *     path: string,
     *     body: array<string, mixed>,
     *     status: int,
     *     reason: string,
     *     paths: array<string, mixed>
     * }>
     */
    public static function missingWorkerTaskProvider(): array
    {
        return [
            'workflow history task not found' => [
                'path' => '/api/worker/workflow-tasks/missing-workflow-task/history',
                'body' => [
                    'lease_owner' => 'missing-worker',
                    'workflow_task_attempt' => 1,
                    'next_history_page_token' => base64_encode('1'),
                ],
                'status' => 404,
                'reason' => 'task_not_found',
                'paths' => [
                    'task_id' => 'missing-workflow-task',
                    'workflow_task_attempt' => 1,
                ],
            ],
            'workflow heartbeat task not found' => [
                'path' => '/api/worker/workflow-tasks/missing-workflow-task/heartbeat',
                'body' => [
                    'lease_owner' => 'missing-worker',
                    'workflow_task_attempt' => 1,
                ],
                'status' => 404,
                'reason' => 'task_not_found',
                'paths' => [
                    'task_id' => 'missing-workflow-task',
                    'workflow_task_attempt' => 1,
                ],
            ],
            'workflow complete task not found' => [
                'path' => '/api/worker/workflow-tasks/missing-workflow-task/complete',
                'body' => [
                    'lease_owner' => 'missing-worker',
                    'workflow_task_attempt' => 1,
                    'commands' => [
                        [
                            'type' => 'complete_workflow',
                            'result' => Serializer::serializeWithCodec('json', ['ok' => true]),
                        ],
                    ],
                ],
                'status' => 404,
                'reason' => 'task_not_found',
                'paths' => [
                    'task_id' => 'missing-workflow-task',
                    'workflow_task_attempt' => 1,
                ],
            ],
            'workflow fail task not found' => [
                'path' => '/api/worker/workflow-tasks/missing-workflow-task/fail',
                'body' => [
                    'lease_owner' => 'missing-worker',
                    'workflow_task_attempt' => 1,
                    'failure' => [
                        'message' => 'Task disappeared before failure reporting.',
                    ],
                ],
                'status' => 404,
                'reason' => 'task_not_found',
                'paths' => [
                    'task_id' => 'missing-workflow-task',
                    'workflow_task_attempt' => 1,
                ],
            ],
            'activity heartbeat task not found' => [
                'path' => '/api/worker/activity-tasks/missing-activity-task/heartbeat',
                'body' => [
                    'activity_attempt_id' => 'missing-attempt',
                    'lease_owner' => 'missing-worker',
                ],
                'status' => 404,
                'reason' => 'task_not_found',
                'paths' => [
                    'task_id' => 'missing-activity-task',
                    'activity_attempt_id' => 'missing-attempt',
                ],
            ],
            'activity complete task not found' => [
                'path' => '/api/worker/activity-tasks/missing-activity-task/complete',
                'body' => [
                    'activity_attempt_id' => 'missing-attempt',
                    'lease_owner' => 'missing-worker',
                    'result' => Serializer::serializeWithCodec('json', 'ok'),
                ],
                'status' => 404,
                'reason' => 'task_not_found',
                'paths' => [
                    'task_id' => 'missing-activity-task',
                    'activity_attempt_id' => 'missing-attempt',
                ],
            ],
            'activity fail task not found' => [
                'path' => '/api/worker/activity-tasks/missing-activity-task/fail',
                'body' => [
                    'activity_attempt_id' => 'missing-attempt',
                    'lease_owner' => 'missing-worker',
                    'failure' => [
                        'message' => 'Task disappeared before failure reporting.',
                    ],
                ],
                'status' => 404,
                'reason' => 'task_not_found',
                'paths' => [
                    'task_id' => 'missing-activity-task',
                    'activity_attempt_id' => 'missing-attempt',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $paths
     */
    #[DataProvider('missingWorkerTaskProvider')]
    public function test_missing_worker_task_errors_use_worker_protocol_contract(
        string $path,
        array $body,
        int $status,
        string $reason,
        array $paths,
    ): void {
        $response = $this->postJson($path, $body, $this->mixedWorkerHeaders());

        $this->assertWorkerProtocolError($response, $status, $reason);

        foreach ($paths as $jsonPath => $expected) {
            $response->assertJsonPath($jsonPath, $expected);
        }
    }

    public function test_workflow_task_lease_state_errors_use_worker_protocol_contract(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-lease-state-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorker(
            workerId: 'workflow-lease-state-worker',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'workflow-lease-state-worker',
            'task_queue' => 'contract-queue',
        ], $this->mixedWorkerHeaders());

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-worker-lease-state-contract')
            ->assertJsonPath('task.lease_owner', 'workflow-lease-state-worker');

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $expiredAt = now()->subMinute()->startOfSecond();

        WorkflowTask::query()->findOrFail($taskId)->forceFill([
            'lease_expires_at' => $expiredAt,
        ])->save();

        $leaseExpired = $this->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
            'lease_owner' => 'workflow-lease-state-worker',
            'workflow_task_attempt' => $attempt,
        ], $this->mixedWorkerHeaders());

        $this->assertWorkerProtocolError($leaseExpired, 409, 'lease_expired')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('lease_owner', 'workflow-lease-state-worker')
            ->assertJsonPath('task_status', 'leased')
            ->assertJsonPath('lease_expires_at', $expiredAt->toJSON());

        WorkflowTask::query()->findOrFail($taskId)->forceFill([
            'status' => TaskStatus::Ready,
            'lease_owner' => null,
            'lease_expires_at' => null,
        ])->save();

        $notLeased = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'workflow-lease-state-worker',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'complete_workflow',
                    'result' => Serializer::serializeWithCodec('json', ['ok' => true]),
                ],
            ],
        ], $this->mixedWorkerHeaders());

        $this->assertWorkerProtocolError($notLeased, 409, 'task_not_leased')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt);
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
