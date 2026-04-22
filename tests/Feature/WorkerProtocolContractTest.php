<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkerProtocolContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            [
                'description' => 'Default namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );
    }

    public function test_worker_validation_errors_use_worker_protocol_contract_even_with_control_plane_header(): void
    {
        $this->withHeaders($this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ])->postJson('/api/worker/register', [
            'worker_id' => 'py-worker-invalid',
        ])->assertStatus(422)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'validation_failed')
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonPath('validation_errors.task_queue.0', 'The task queue field is required.')
            ->assertJsonPath('validation_errors.runtime.0', 'The runtime field is required.')
            ->assertJsonMissingPath('control_plane');
    }

    public function test_workflow_task_command_validation_errors_use_worker_protocol_contract(): void
    {
        Queue::fake();

        $start = $this->withHeaders($this->controlPlaneHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-command-validation',
                'workflow_type' => 'remote.command-validation',
                'task_queue' => 'contract-queue',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->createWorkerRegistration([
            'worker_id' => 'command-validation-worker',
            'task_queue' => 'contract-queue',
            'supported_workflow_types' => ['remote.command-validation'],
        ]);

        $poll = $this->withHeaders($this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ])->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'command-validation-worker',
            'task_queue' => 'contract-queue',
        ]);

        $poll->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('task.workflow_id', 'wf-command-validation');

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $response = $this->withHeaders($this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ])->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'command-validation-worker',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                ['type' => 'wait_condition'],
            ],
        ]);

        $response->assertStatus(422)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'validation_failed')
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonMissingPath('control_plane');

        $this->assertSame(
            'Workflow task command type [wait_condition] is not supported by the server yet.',
            $response->json('validation_errors')['commands.0.type'][0] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $command
     */
    #[DataProvider('invalidWorkflowTaskCommandScopeProvider')]
    public function test_workflow_task_command_rejects_retry_and_timeout_fields_outside_their_scope(
        array $command,
        string $errorField,
        string $expectedMessage,
    ): void {
        $response = $this->withHeaders($this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ])->postJson('/api/worker/workflow-tasks/missing-task/complete', [
            'lease_owner' => 'command-scope-worker',
            'workflow_task_attempt' => 1,
            'commands' => [$command],
        ]);

        $response->assertStatus(422)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'validation_failed')
            ->assertJsonMissingPath('control_plane');

        $this->assertSame(
            $expectedMessage,
            $response->json('validation_errors')[$errorField][0] ?? null,
        );
    }

    /**
     * @return array<string, array{command: array<string, mixed>, errorField: string, expectedMessage: string}>
     */
    public static function invalidWorkflowTaskCommandScopeProvider(): array
    {
        return [
            'retry policy on completion' => [
                'command' => [
                    'type' => 'complete_workflow',
                    'retry_policy' => ['max_attempts' => 2],
                ],
                'errorField' => 'commands.0.retry_policy',
                'expectedMessage' => 'retry_policy is only supported for schedule_activity and start_child_workflow commands.',
            ],
            'activity timeout on child command' => [
                'command' => [
                    'type' => 'start_child_workflow',
                    'workflow_type' => 'tests.external-child-workflow',
                    'start_to_close_timeout' => 30,
                ],
                'errorField' => 'commands.0.start_to_close_timeout',
                'expectedMessage' => 'start_to_close_timeout is only supported for schedule_activity commands.',
            ],
            'child timeout on activity command' => [
                'command' => [
                    'type' => 'schedule_activity',
                    'activity_type' => 'tests.external-activity',
                    'run_timeout_seconds' => 30,
                ],
                'errorField' => 'commands.0.run_timeout_seconds',
                'expectedMessage' => 'run_timeout_seconds is only supported for start_child_workflow commands.',
            ],
            'non retryable on completion' => [
                'command' => [
                    'type' => 'complete_workflow',
                    'non_retryable' => true,
                ],
                'errorField' => 'commands.0.non_retryable',
                'expectedMessage' => 'non_retryable is only supported for fail_workflow and fail_update commands.',
            ],
            'heartbeat exceeds start to close' => [
                'command' => [
                    'type' => 'schedule_activity',
                    'activity_type' => 'tests.external-activity',
                    'start_to_close_timeout' => 10,
                    'heartbeat_timeout' => 30,
                ],
                'errorField' => 'commands.0.heartbeat_timeout',
                'expectedMessage' => 'heartbeat_timeout cannot exceed start_to_close_timeout.',
            ],
            'string heartbeat exceeds string start to close' => [
                'command' => [
                    'type' => 'schedule_activity',
                    'activity_type' => 'tests.external-activity',
                    'start_to_close_timeout' => '10',
                    'heartbeat_timeout' => '30',
                ],
                'errorField' => 'commands.0.heartbeat_timeout',
                'expectedMessage' => 'heartbeat_timeout cannot exceed start_to_close_timeout.',
            ],
            'schedule to start exceeds schedule to close' => [
                'command' => [
                    'type' => 'schedule_activity',
                    'activity_type' => 'tests.external-activity',
                    'schedule_to_start_timeout' => 60,
                    'schedule_to_close_timeout' => 30,
                ],
                'errorField' => 'commands.0.schedule_to_start_timeout',
                'expectedMessage' => 'schedule_to_start_timeout cannot exceed schedule_to_close_timeout.',
            ],
            'string start to close exceeds string schedule to close' => [
                'command' => [
                    'type' => 'schedule_activity',
                    'activity_type' => 'tests.external-activity',
                    'start_to_close_timeout' => '60',
                    'schedule_to_close_timeout' => '30',
                ],
                'errorField' => 'commands.0.start_to_close_timeout',
                'expectedMessage' => 'start_to_close_timeout cannot exceed schedule_to_close_timeout.',
            ],
            'string schedule to start exceeds string schedule to close' => [
                'command' => [
                    'type' => 'schedule_activity',
                    'activity_type' => 'tests.external-activity',
                    'schedule_to_start_timeout' => '60',
                    'schedule_to_close_timeout' => '30',
                ],
                'errorField' => 'commands.0.schedule_to_start_timeout',
                'expectedMessage' => 'schedule_to_start_timeout cannot exceed schedule_to_close_timeout.',
            ],
            'child run exceeds execution' => [
                'command' => [
                    'type' => 'start_child_workflow',
                    'workflow_type' => 'tests.external-child-workflow',
                    'execution_timeout_seconds' => 60,
                    'run_timeout_seconds' => 120,
                ],
                'errorField' => 'commands.0.run_timeout_seconds',
                'expectedMessage' => 'run_timeout_seconds cannot exceed execution_timeout_seconds.',
            ],
            'string child run exceeds string execution' => [
                'command' => [
                    'type' => 'start_child_workflow',
                    'workflow_type' => 'tests.external-child-workflow',
                    'execution_timeout_seconds' => '60',
                    'run_timeout_seconds' => '120',
                ],
                'errorField' => 'commands.0.run_timeout_seconds',
                'expectedMessage' => 'run_timeout_seconds cannot exceed execution_timeout_seconds.',
            ],
        ];
    }

    /**
     * @return array<string, array{path: string, body: array<string, mixed>, errorFields: list<string>}>
     */
    public static function workerValidationEndpointProvider(): array
    {
        return [
            'worker.register' => [
                'path' => '/api/worker/register',
                'body' => ['worker_id' => 'py-worker-invalid'],
                'errorFields' => ['task_queue', 'runtime'],
            ],
            'worker.heartbeat' => [
                'path' => '/api/worker/heartbeat',
                'body' => [],
                'errorFields' => ['worker_id'],
            ],
            'workflow-tasks.poll' => [
                'path' => '/api/worker/workflow-tasks/poll',
                'body' => [],
                'errorFields' => ['worker_id', 'task_queue'],
            ],
            'workflow-tasks.history' => [
                'path' => '/api/worker/workflow-tasks/task-1/history',
                'body' => [],
                'errorFields' => ['lease_owner', 'workflow_task_attempt', 'next_history_page_token'],
            ],
            'workflow-tasks.heartbeat' => [
                'path' => '/api/worker/workflow-tasks/task-1/heartbeat',
                'body' => [],
                'errorFields' => ['lease_owner', 'workflow_task_attempt'],
            ],
            'workflow-tasks.complete' => [
                'path' => '/api/worker/workflow-tasks/task-1/complete',
                'body' => [],
                'errorFields' => ['lease_owner', 'workflow_task_attempt', 'commands'],
            ],
            'workflow-tasks.fail' => [
                'path' => '/api/worker/workflow-tasks/task-1/fail',
                'body' => [],
                'errorFields' => ['lease_owner', 'workflow_task_attempt', 'failure'],
            ],
            'query-tasks.poll' => [
                'path' => '/api/worker/query-tasks/poll',
                'body' => [],
                'errorFields' => ['worker_id', 'task_queue'],
            ],
            'query-tasks.complete' => [
                'path' => '/api/worker/query-tasks/task-1/complete',
                'body' => [],
                'errorFields' => ['lease_owner', 'query_task_attempt'],
            ],
            'query-tasks.fail' => [
                'path' => '/api/worker/query-tasks/task-1/fail',
                'body' => [],
                'errorFields' => ['lease_owner', 'query_task_attempt', 'failure'],
            ],
            'activity-tasks.poll' => [
                'path' => '/api/worker/activity-tasks/poll',
                'body' => [],
                'errorFields' => ['worker_id', 'task_queue'],
            ],
            'activity-tasks.complete' => [
                'path' => '/api/worker/activity-tasks/task-1/complete',
                'body' => [],
                'errorFields' => ['activity_attempt_id', 'lease_owner'],
            ],
            'activity-tasks.fail' => [
                'path' => '/api/worker/activity-tasks/task-1/fail',
                'body' => [],
                'errorFields' => ['activity_attempt_id', 'lease_owner', 'failure'],
            ],
            'activity-tasks.heartbeat' => [
                'path' => '/api/worker/activity-tasks/task-1/heartbeat',
                'body' => [],
                'errorFields' => ['activity_attempt_id', 'lease_owner'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $errorFields
     */
    #[DataProvider('workerValidationEndpointProvider')]
    public function test_worker_validation_errors_use_worker_protocol_contract_across_endpoints(
        string $path,
        array $body,
        array $errorFields,
    ): void {
        $response = $this->withHeaders($this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ])->postJson($path, $body);

        $response->assertStatus(422)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'validation_failed')
            ->assertJsonPath('message', static fn (mixed $message): bool => is_string($message) && $message !== '')
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonMissingPath('control_plane');

        foreach ($errorFields as $field) {
            $response->assertJsonPath(
                "errors.{$field}.0",
                static fn (mixed $message): bool => is_string($message) && $message !== '',
            )->assertJsonPath(
                "validation_errors.{$field}.0",
                static fn (mixed $message): bool => is_string($message) && $message !== '',
            );
        }
    }

    public function test_worker_authentication_errors_use_worker_protocol_contract(): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => 'worker-token',
        ]);

        $this->withHeaders($this->workerHeaders(withAuthorization: false))
            ->postJson('/api/worker/register', [
                'worker_id' => 'py-worker-auth',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])->assertUnauthorized()
            ->assertHeader('X-Durable-Workflow-Protocol-Version', '1.0')
            ->assertHeaderMissing('X-Durable-Workflow-Control-Plane-Version')
            ->assertJsonPath('protocol_version', '1.0')
            ->assertJsonPath('reason', 'unauthorized')
            ->assertJsonPath('message', 'Invalid or missing authentication token.')
            ->assertJsonPath('server_capabilities.long_poll_timeout', 0)
            ->assertJsonMissingPath('control_plane');
    }

    public function test_worker_authorization_errors_use_worker_protocol_contract(): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.role_tokens' => [
                'worker' => 'worker-token',
                'operator' => 'operator-token',
                'admin' => 'admin-token',
            ],
            'server.auth.backward_compatible' => true,
        ]);

        $this->withHeaders($this->workerHeaders(token: 'operator-token'))
            ->postJson('/api/worker/register', [
                'worker_id' => 'py-worker-wrong-role',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])->assertForbidden()
            ->assertHeader('X-Durable-Workflow-Protocol-Version', '1.0')
            ->assertHeaderMissing('X-Durable-Workflow-Control-Plane-Version')
            ->assertJsonPath('protocol_version', '1.0')
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('role', 'operator')
            ->assertJsonPath('allowed_roles.0', 'worker')
            ->assertJsonPath('server_capabilities.supported_workflow_task_commands.0', 'complete_workflow')
            ->assertJsonMissingPath('control_plane');
    }

    public function test_worker_heartbeat_not_registered_errors_use_worker_protocol_contract(): void
    {
        $this->withHeaders($this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ])->postJson('/api/worker/heartbeat', [
            'worker_id' => 'missing-worker',
        ])->assertNotFound()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('error', 'Worker not registered.')
            ->assertJsonPath('reason', 'worker_not_registered')
            ->assertJsonPath('worker_id', 'missing-worker')
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonMissingPath('control_plane');
    }

    /**
     * @return array<string, array{
     *     path: string,
     *     body: array<string, mixed>,
     *     registration: array<string, mixed>|null,
     *     status: int,
     *     reason: string,
     *     paths: array<string, mixed>
     * }>
     */
    public static function workerPollBusinessErrorProvider(): array
    {
        return [
            'workflow poll unregistered worker' => [
                'path' => '/api/worker/workflow-tasks/poll',
                'body' => [
                    'worker_id' => 'missing-workflow-poller',
                    'task_queue' => 'contract-queue',
                ],
                'registration' => null,
                'status' => 412,
                'reason' => 'worker_not_registered',
                'paths' => [
                    'worker_id' => 'missing-workflow-poller',
                ],
            ],
            'activity poll unregistered worker' => [
                'path' => '/api/worker/activity-tasks/poll',
                'body' => [
                    'worker_id' => 'missing-activity-poller',
                    'task_queue' => 'contract-queue',
                ],
                'registration' => null,
                'status' => 412,
                'reason' => 'worker_not_registered',
                'paths' => [
                    'worker_id' => 'missing-activity-poller',
                ],
            ],
            'workflow poll task queue mismatch' => [
                'path' => '/api/worker/workflow-tasks/poll',
                'body' => [
                    'worker_id' => 'workflow-queue-mismatch',
                    'task_queue' => 'requested-queue',
                ],
                'registration' => [
                    'worker_id' => 'workflow-queue-mismatch',
                    'task_queue' => 'registered-queue',
                ],
                'status' => 409,
                'reason' => 'task_queue_mismatch',
                'paths' => [
                    'worker_id' => 'workflow-queue-mismatch',
                    'registered_task_queue' => 'registered-queue',
                    'requested_task_queue' => 'requested-queue',
                ],
            ],
            'activity poll task queue mismatch' => [
                'path' => '/api/worker/activity-tasks/poll',
                'body' => [
                    'worker_id' => 'activity-queue-mismatch',
                    'task_queue' => 'requested-queue',
                ],
                'registration' => [
                    'worker_id' => 'activity-queue-mismatch',
                    'task_queue' => 'registered-queue',
                ],
                'status' => 409,
                'reason' => 'task_queue_mismatch',
                'paths' => [
                    'worker_id' => 'activity-queue-mismatch',
                    'registered_task_queue' => 'registered-queue',
                    'requested_task_queue' => 'requested-queue',
                ],
            ],
            'workflow poll build id mismatch' => [
                'path' => '/api/worker/workflow-tasks/poll',
                'body' => [
                    'worker_id' => 'workflow-build-mismatch',
                    'task_queue' => 'contract-queue',
                    'build_id' => 'build-requested',
                ],
                'registration' => [
                    'worker_id' => 'workflow-build-mismatch',
                    'task_queue' => 'contract-queue',
                    'build_id' => 'build-registered',
                ],
                'status' => 409,
                'reason' => 'build_id_mismatch',
                'paths' => [
                    'worker_id' => 'workflow-build-mismatch',
                    'registered_build_id' => 'build-registered',
                    'requested_build_id' => 'build-requested',
                ],
            ],
            'activity poll build id mismatch' => [
                'path' => '/api/worker/activity-tasks/poll',
                'body' => [
                    'worker_id' => 'activity-build-mismatch',
                    'task_queue' => 'contract-queue',
                    'build_id' => 'build-requested',
                ],
                'registration' => [
                    'worker_id' => 'activity-build-mismatch',
                    'task_queue' => 'contract-queue',
                    'build_id' => 'build-registered',
                ],
                'status' => 409,
                'reason' => 'build_id_mismatch',
                'paths' => [
                    'worker_id' => 'activity-build-mismatch',
                    'registered_build_id' => 'build-registered',
                    'requested_build_id' => 'build-requested',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>|null  $registration
     * @param  array<string, mixed>  $paths
     */
    #[DataProvider('workerPollBusinessErrorProvider')]
    public function test_worker_poll_business_errors_use_worker_protocol_contract(
        string $path,
        array $body,
        ?array $registration,
        int $status,
        string $reason,
        array $paths,
    ): void {
        if ($registration !== null) {
            $this->createWorkerRegistration($registration);
        }

        $response = $this->withHeaders($this->workerHeaders() + [
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ])->postJson($path, $body);

        $response->assertStatus($status)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', $reason)
            ->assertJsonPath('error', static fn (mixed $error): bool => is_string($error) && $error !== '')
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonMissingPath('control_plane');

        foreach ($paths as $jsonPath => $expected) {
            $response->assertJsonPath($jsonPath, $expected);
        }
    }

    /**
     * @return array<string, string>
     */
    private function workerHeaders(string $token = 'worker-token', bool $withAuthorization = true): array
    {
        $headers = [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];

        if ($withAuthorization) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function controlPlaneHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createWorkerRegistration(array $attributes): void
    {
        WorkerRegistration::query()->create($attributes + [
            'namespace' => 'default',
            'runtime' => 'python',
            'supported_workflow_types' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 100,
            'max_concurrent_activity_tasks' => 100,
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);
    }
}
