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
use Workflow\V2\WorkflowStub;

class WorkerProtocolSuccessContractTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['server.polling.timeout' => 0]);

        $this->createNamespace('default');
    }

    /**
     * @return array<string, array{
     *     case: string,
     *     path: string,
     *     body: array<string, mixed>,
     *     status: int,
     *     structure: array<int|string, mixed>,
     *     paths: array<string, mixed>
     * }>
     */
    public static function workerSuccessProvider(): array
    {
        return [
            'worker.register' => [
                'case' => 'worker.register',
                'path' => '/api/worker/register',
                'body' => [
                    'worker_id' => 'worker-success',
                    'task_queue' => 'contract-queue',
                    'runtime' => 'python',
                    'sdk_version' => '1.0.0',
                    'supported_workflow_types' => ['ContractWorkflow'],
                    'supported_activity_types' => ['ContractActivity'],
                ],
                'status' => 201,
                'structure' => ['worker_id', 'registered', 'protocol_version', 'server_capabilities'],
                'paths' => ['worker_id' => 'worker-success', 'registered' => true],
            ],
            'worker.heartbeat' => [
                'case' => 'worker.heartbeat',
                'path' => '/api/worker/heartbeat',
                'body' => ['worker_id' => 'worker-success'],
                'status' => 200,
                'structure' => ['worker_id', 'acknowledged', 'protocol_version', 'server_capabilities'],
                'paths' => ['worker_id' => 'worker-success', 'acknowledged' => true],
            ],
            'workflow-tasks.poll_empty' => [
                'case' => 'workflow-tasks.poll_empty',
                'path' => '/api/worker/workflow-tasks/poll',
                'body' => [
                    'worker_id' => 'worker-success',
                    'task_queue' => 'contract-queue',
                    'poll_request_id' => 'poll-contract-1',
                ],
                'status' => 200,
                'structure' => ['task', 'protocol_version', 'server_capabilities'],
                'paths' => ['task' => null],
            ],
            'activity-tasks.poll_empty' => [
                'case' => 'activity-tasks.poll_empty',
                'path' => '/api/worker/activity-tasks/poll',
                'body' => [
                    'worker_id' => 'worker-success',
                    'task_queue' => 'contract-queue',
                ],
                'status' => 200,
                'structure' => ['task', 'protocol_version', 'server_capabilities'],
                'paths' => ['task' => null],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int|string, mixed>  $structure
     * @param  array<string, mixed>  $paths
     */
    #[DataProvider('workerSuccessProvider')]
    public function test_worker_success_responses_use_worker_protocol_contract(
        string $case,
        string $path,
        array $body,
        int $status,
        array $structure,
        array $paths,
    ): void {
        $this->prepareWorkerCase($case);

        $response = $this->postJson($path, $body, $this->workerProtocolHeaders());

        $response->assertStatus($status)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonPath('server_capabilities.supported_workflow_task_commands.0', 'complete_workflow')
            ->assertJsonMissingPath('control_plane')
            ->assertJsonStructure($structure);

        foreach ($paths as $jsonPath => $expected) {
            $response->assertJsonPath($jsonPath, $expected);
        }
    }

    public function test_leased_workflow_task_success_responses_use_worker_protocol_contract(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-success-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->registerWorker(
            workerId: 'worker-success-lifecycle',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-success-lifecycle',
            'task_queue' => 'contract-queue',
            'history_page_size' => 1,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', 'wf-worker-success-contract')
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'worker-success-lifecycle');

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $nextHistoryPageToken = $poll->json('task.next_history_page_token');

        $this->assertIsString($nextHistoryPageToken);

        $history = $this->postJson("/api/worker/workflow-tasks/{$taskId}/history", [
            'lease_owner' => 'worker-success-lifecycle',
            'workflow_task_attempt' => $attempt,
            'next_history_page_token' => $nextHistoryPageToken,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($history)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonStructure([
                'history_events',
                'total_history_events',
                'next_history_page_token',
            ]);

        $this->assertNotEmpty($history->json('history_events'));

        $heartbeat = $this->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
            'lease_owner' => 'worker-success-lifecycle',
            'workflow_task_attempt' => $attempt,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($heartbeat)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('lease_owner', 'worker-success-lifecycle')
            ->assertJsonPath('renewed', true)
            ->assertJsonPath('task_status', 'leased')
            ->assertJsonPath('reason', null);

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-success-lifecycle',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'complete_workflow',
                    'result' => Serializer::serializeWithCodec('json', [
                        'greeting' => 'Hello, Ada!',
                    ]),
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'completed')
            ->assertJsonStructure(['created_task_ids']);
    }

    public function test_leased_activity_task_success_responses_use_worker_protocol_contract(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(
            ExternalGreetingWorkflow::class,
            'wf-activity-success-contract',
        );
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $this->registerWorker(
            workerId: 'activity-worker-success-lifecycle',
            taskQueue: 'external-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $poll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'activity-worker-success-lifecycle',
            'task_queue' => 'external-activities',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', 'wf-activity-success-contract')
            ->assertJsonPath('task.run_id', $start->runId())
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.attempt_number', 1)
            ->assertJsonPath('task.task_queue', 'external-activities')
            ->assertJsonPath('task.lease_owner', 'activity-worker-success-lifecycle')
            ->assertJsonPath('task.arguments.codec', (string) config('workflows.serializer'))
            ->assertJsonMissingPath('task.activity_class');

        $this->assertSame(
            ['Ada'],
            Serializer::unserializeWithCodec(
                (string) $poll->json('task.arguments.codec'),
                (string) $poll->json('task.arguments.blob'),
            ),
        );

        $taskId = (string) $poll->json('task.task_id');
        $attemptId = (string) $poll->json('task.activity_attempt_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $heartbeat = $this->postJson("/api/worker/activity-tasks/{$taskId}/heartbeat", [
            'activity_attempt_id' => $attemptId,
            'lease_owner' => $leaseOwner,
            'message' => 'half done',
            'current' => 1,
            'total' => 2,
            'unit' => 'step',
            'details' => ['phase' => 'contract'],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($heartbeat)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('lease_owner', $leaseOwner)
            ->assertJsonPath('cancel_requested', false)
            ->assertJsonPath('can_continue', true)
            ->assertJsonPath('reason', null)
            ->assertJsonPath('heartbeat_recorded', true)
            ->assertJsonStructure(['lease_expires_at', 'last_heartbeat_at']);

        $complete = $this->postJson("/api/worker/activity-tasks/{$taskId}/complete", [
            'activity_attempt_id' => $attemptId,
            'lease_owner' => $leaseOwner,
            'result' => Serializer::serializeWithCodec('json', 'Hello, Ada!'),
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null)
            ->assertJsonStructure(['next_task_id']);
    }

    /**
     * @return array<string, array{command: string, expectedOutcome: string, expectedHeartbeatReason: string}>
     */
    public static function terminalActivityHeartbeatProvider(): array
    {
        return [
            'cancelled run' => [
                'command' => 'cancel',
                'expectedOutcome' => 'cancelled',
                'expectedHeartbeatReason' => 'run_cancelled',
            ],
            'terminated run' => [
                'command' => 'terminate',
                'expectedOutcome' => 'terminated',
                'expectedHeartbeatReason' => 'run_terminated',
            ],
        ];
    }

    #[DataProvider('terminalActivityHeartbeatProvider')]
    public function test_leased_activity_task_heartbeat_reports_terminal_run_state(
        string $command,
        string $expectedOutcome,
        string $expectedHeartbeatReason,
    ): void {
        Queue::fake();

        $workflowId = sprintf('wf-activity-%s-heartbeat-contract', $command);
        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, $workflowId);
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $this->registerWorker(
            workerId: "activity-worker-{$command}-heartbeat",
            taskQueue: 'external-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $poll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => "activity-worker-{$command}-heartbeat",
            'task_queue' => 'external-activities',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.lease_owner', "activity-worker-{$command}-heartbeat");

        $taskId = (string) $poll->json('task.task_id');
        $attemptId = (string) $poll->json('task.activity_attempt_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $controlPlane = $this->postJson("/api/workflows/{$workflowId}/{$command}", [
            'reason' => "operator {$command}",
        ], $this->apiHeaders());

        $controlPlane->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('outcome', $expectedOutcome);

        $heartbeat = $this->postJson("/api/worker/activity-tasks/{$taskId}/heartbeat", [
            'activity_attempt_id' => $attemptId,
            'lease_owner' => $leaseOwner,
            'message' => 'checking for cancellation',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($heartbeat)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('lease_owner', $leaseOwner)
            ->assertJsonPath('cancel_requested', true)
            ->assertJsonPath('can_continue', false)
            ->assertJsonPath('reason', $expectedHeartbeatReason)
            ->assertJsonPath('heartbeat_recorded', false);
    }

    public function test_leased_workflow_task_failure_response_uses_worker_protocol_contract(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-fail-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorker(
            workerId: 'worker-fail-lifecycle',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-fail-lifecycle',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', 'wf-worker-fail-contract')
            ->assertJsonPath('task.lease_owner', 'worker-fail-lifecycle');

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $fail = $this->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
            'lease_owner' => 'worker-fail-lifecycle',
            'workflow_task_attempt' => $attempt,
            'failure' => [
                'message' => 'Non-determinism detected: unexpected history event.',
                'type' => 'NonDeterminismError',
                'stack_trace' => 'at Replay::apply(Replay.php:42)',
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($fail)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null)
            ->assertJsonPath('next_task_id', null);
    }

    public function test_leased_activity_task_failure_response_uses_worker_protocol_contract(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(
            ExternalGreetingWorkflow::class,
            'wf-activity-fail-contract',
        );
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $this->registerWorker(
            workerId: 'activity-worker-fail-lifecycle',
            taskQueue: 'external-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $poll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'activity-worker-fail-lifecycle',
            'task_queue' => 'external-activities',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', 'wf-activity-fail-contract')
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.lease_owner', 'activity-worker-fail-lifecycle')
            ->assertJsonMissingPath('task.activity_class');

        $taskId = (string) $poll->json('task.task_id');
        $attemptId = (string) $poll->json('task.activity_attempt_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $fail = $this->postJson("/api/worker/activity-tasks/{$taskId}/fail", [
            'activity_attempt_id' => $attemptId,
            'lease_owner' => $leaseOwner,
            'failure' => [
                'message' => 'Connection timeout calling external service.',
                'type' => 'TimeoutException',
                'stack_trace' => 'at HttpClient::send(Client.php:120)',
                'non_retryable' => false,
                'details' => Serializer::serializeWithCodec('json', ['retry_after' => 30]),
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($fail)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null)
            ->assertJsonStructure(['next_task_id']);
    }

    private function prepareWorkerCase(string $case): void
    {
        if ($case === 'worker.register') {
            return;
        }

        $this->registerWorker(
            workerId: 'worker-success',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['ContractWorkflow'],
            supportedActivityTypes: ['ContractActivity'],
        );
    }

    private function assertWorkerProtocolSuccess(TestResponse $response, int $status = 200): TestResponse
    {
        $response->assertStatus($status)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonMissingPath('control_plane');

        return $response;
    }

    /**
     * Include both protocol headers to prove worker-plane routes keep the
     * worker envelope on success when mixed clients send extra metadata.
     *
     * @return array<string, string>
     */
    private function workerProtocolHeaders(): array
    {
        return $this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];
    }
}
