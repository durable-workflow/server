<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\ControlPlaneProtocol;
use App\Support\LongPoller;
use App\Support\NamespaceWorkflowScope;
use App\Support\ServiceModeTimerDispatcher;
use App\Support\WorkerProtocol;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ConditionTimeoutWorkflow;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\MemoPayload;
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
                    'timeout_seconds' => 0,
                ],
                'status' => 200,
                'structure' => ['task', 'poll_status', 'protocol_version', 'server_capabilities'],
                'paths' => ['task' => null, 'poll_status' => 'empty'],
            ],
            'activity-tasks.poll_empty' => [
                'case' => 'activity-tasks.poll_empty',
                'path' => '/api/worker/activity-tasks/poll',
                'body' => [
                    'worker_id' => 'worker-success',
                    'task_queue' => 'contract-queue',
                    'timeout_seconds' => 0,
                ],
                'status' => 200,
                'structure' => ['task', 'poll_status', 'protocol_version', 'server_capabilities'],
                'paths' => ['task' => null, 'poll_status' => 'empty'],
            ],
            'query-tasks.poll_empty' => [
                'case' => 'query-tasks.poll_empty',
                'path' => '/api/worker/query-tasks/poll',
                'body' => [
                    'worker_id' => 'worker-success',
                    'task_queue' => 'contract-queue',
                ],
                'status' => 200,
                'structure' => ['task', 'poll_status', 'protocol_version', 'server_capabilities'],
                'paths' => ['task' => null, 'poll_status' => 'empty'],
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

        if ($case === 'worker.register') {
            $body['capability_manifest'] = $this->portableWorkerAffinityRefusalManifest();
        }

        $response = $this->postJson($path, $body, $this->workerProtocolHeaders());

        $response->assertStatus($status)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonPath('server_capabilities.poll_status', true)
            ->assertJsonPath('server_capabilities.supported_workflow_task_commands.0', 'complete_workflow')
            ->assertJsonPath('server_capabilities.activity_retry_policy', true)
            ->assertJsonPath('server_capabilities.activity_timeouts', true)
            ->assertJsonPath(
                'server_capabilities.local_activities.schema',
                'durable-workflow.v2.local-activity.contract',
            )
            ->assertJsonPath('server_capabilities.local_activities.version', 1)
            ->assertJsonPath('server_capabilities.local_activities.execution.mode', 'local')
            ->assertJsonPath(
                'server_capabilities.local_activities.execution.ordinary_activity_task_created',
                false,
            )
            ->assertJsonPath('server_capabilities.child_workflow_retry_policy', true)
            ->assertJsonPath('server_capabilities.child_workflow_timeouts', true)
            ->assertJsonPath('server_capabilities.parent_close_policy', true)
            ->assertJsonPath('server_capabilities.query_tasks', true)
            ->assertJsonPath('server_capabilities.query_task_poll_request_idempotency', true)
            ->assertJsonPath('server_capabilities.non_retryable_failures', true)
            ->assertJsonPath('server_capabilities.worker_status.fields.process_metrics', [
                'cpu_percent',
                'memory_bytes',
                'process_uptime_seconds',
                'process_id',
                'host',
                'process_started_at',
            ])
            ->assertJsonMissingPath('control_plane')
            ->assertJsonStructure($structure);

        foreach ($paths as $jsonPath => $expected) {
            $response->assertJsonPath($jsonPath, $expected);
        }
    }

    public function test_worker_registration_envelope_matches_the_published_openapi_schema(): void
    {
        $response = $this->postJson('/api/worker/register', [
            'worker_id' => 'worker-openapi-envelope',
            'task_queue' => 'contract-queue',
            'runtime' => 'rust',
            'capabilities' => ['typed_search_attributes'],
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
        ], $this->workerProtocolHeaders())->assertCreated();

        $envelope = json_decode((string) $response->getContent(), flags: JSON_THROW_ON_ERROR);

        OpenApiSchema::fromFile(
            dirname(__DIR__, 2).'/resources/platform-protocol-specs/worker-protocol-api.openapi.yaml',
        )->assertReferenceMatches('#/components/schemas/WorkerEnvelope', $envelope);
    }

    public function test_worker_protocol_1_1_registration_advertises_feature_floor_without_worker_sessions(): void
    {
        $protocolVersion = '1.1';

        $this->assertTrue(
            version_compare($protocolVersion, WorkerProtocol::workerSessionMinimumProtocolVersion(), '<'),
            'This test assumes worker sessions are gated above the protocol 1.1 feature floor.',
        );

        config(['server.worker_protocol.version' => $protocolVersion]);

        $response = $this->postJson('/api/worker/register', [
            'worker_id' => 'worker-protocol-1-1',
            'task_queue' => 'contract-queue',
            'runtime' => 'python',
            'sdk_version' => '1.1.0',
        ], [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => $protocolVersion,
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ]);

        $response->assertCreated()
            ->assertHeader(WorkerProtocol::HEADER, $protocolVersion)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertJsonPath('protocol_version', $protocolVersion)
            ->assertJsonPath('registered', true)
            ->assertJsonPath('server_capabilities.query_tasks', true)
            ->assertJsonPath('server_capabilities.query_task_poll_request_idempotency', true)
            ->assertJsonPath(
                'server_capabilities.local_activities.schema',
                'durable-workflow.v2.local-activity.contract',
            )
            ->assertJsonPath('server_capabilities.local_activities.supported', false)
            ->assertJsonPath('server_capabilities.sticky_execution.supported', false)
            ->assertJsonPath('server_capabilities.worker_session_verbs', [])
            ->assertJsonPath('server_capabilities.worker_sessions.supported', false)
            ->assertJsonPath(
                'server_capabilities.worker_sessions.minimum_protocol_version',
                WorkerProtocol::workerSessionMinimumProtocolVersion(),
            )
            ->assertJsonPath(
                'server_capabilities.worker_sessions.unavailable_reason',
                'worker_protocol_version_below_worker_session_minimum',
            )
            ->assertJsonMissingPath('control_plane');

        $this->assertNotContains(
            'record_local_activity',
            $response->json('server_capabilities.supported_workflow_task_commands'),
        );
    }

    public function test_workflow_task_poll_treats_null_timeout_seconds_as_default(): void
    {
        config(['server.polling.timeout' => 2]);

        $this->registerWorker(
            workerId: 'worker-null-workflow-timeout',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['ContractWorkflow'],
            supportedActivityTypes: ['ContractActivity'],
        );

        $this->mock(LongPoller::class, function (MockInterface $mock): void {
            $mock->shouldReceive('until')
                ->once()
                ->andReturnUsing(function (
                    callable $probe,
                    callable $ready,
                    ?int $timeoutSeconds = null,
                ) {
                    $this->assertNull($timeoutSeconds);

                    $initial = $probe();

                    $this->assertNull($initial);
                    $this->assertFalse($ready($initial));

                    return null;
                });
        });

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-null-workflow-timeout',
            'task_queue' => 'contract-queue',
            'timeout_seconds' => null,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');
    }

    public function test_activity_task_poll_treats_null_timeout_seconds_as_default(): void
    {
        config(['server.polling.timeout' => 2]);

        $this->registerWorker(
            workerId: 'worker-null-activity-timeout',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['ContractWorkflow'],
            supportedActivityTypes: ['ContractActivity'],
        );

        $this->mock(LongPoller::class, function (MockInterface $mock): void {
            $mock->shouldReceive('until')
                ->once()
                ->andReturnUsing(function (
                    callable $probe,
                    callable $ready,
                    ?int $timeoutSeconds = null,
                ) {
                    $this->assertNull($timeoutSeconds);

                    $initial = $probe();

                    $this->assertNull($initial);
                    $this->assertFalse($ready($initial));

                    return null;
                });
        });

        $poll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'worker-null-activity-timeout',
            'task_queue' => 'contract-queue',
            'timeout_seconds' => null,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');
    }

    public function test_query_task_poll_honors_request_timeout_seconds(): void
    {
        config(['server.query_tasks.timeout' => 2]);

        WorkerRegistration::query()->create([
            'worker_id' => 'worker-query-short-timeout',
            'namespace' => 'default',
            'task_queue' => 'contract-queue',
            'runtime' => 'php',
            'supported_workflow_types' => ['ContractWorkflow'],
            'supported_activity_types' => [],
            'capabilities' => ['query_tasks'],
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);

        $this->mock(LongPoller::class, function (MockInterface $mock): void {
            $mock->shouldReceive('until')
                ->once()
                ->andReturnUsing(function (
                    callable $probe,
                    callable $ready,
                    ?int $timeoutSeconds = null,
                    ?int $intervalMilliseconds = null,
                    array $wakeChannels = [],
                    ?callable $nextProbeAt = null,
                    bool $reserveWorkerWaitSlot = false,
                    string $waitSlotPool = 'worker',
                ) {
                    $this->assertSame(0, $timeoutSeconds);
                    $this->assertTrue($reserveWorkerWaitSlot);
                    $this->assertSame('query-task', $waitSlotPool);

                    $initial = $probe();

                    $this->assertNull($initial);
                    $this->assertFalse($ready($initial));

                    return null;
                });
        });

        $poll = $this->postJson('/api/worker/query-tasks/poll', [
            'worker_id' => 'worker-query-short-timeout',
            'task_queue' => 'contract-queue',
            'timeout_seconds' => 0,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');
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
            ->assertJsonPath('poll_status', 'leased')
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
                    'result' => Serializer::serializeWithCodec('avro', [
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

    public function test_worker_registration_reclaims_existing_leases_for_same_worker_identity(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-restart-reclaims-lease',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $workerId = 'worker-restart-reclaims-lease';
        $registration = [
            'worker_id' => $workerId,
            'task_queue' => 'contract-queue',
            'runtime' => 'php',
            'sdk_version' => '2.0.0-test',
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => [],
            'process_metrics' => [
                'host' => 'same-container-host',
                'process_id' => 1,
                'process_started_at' => '2026-06-04T00:00:00Z',
                'process_uptime_seconds' => 0,
            ],
        ];

        $this->postJson('/api/worker/register', $registration, $this->workerProtocolHeaders())
            ->assertCreated();

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.lease_owner', $workerId)
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $taskId = (string) $poll->json('task.task_id');
        $leasedTask = WorkflowTask::query()->findOrFail($taskId);

        $this->assertSame(TaskStatus::Leased, $leasedTask->status);
        $this->assertSame($workerId, $leasedTask->lease_owner);
        $this->assertNotNull($leasedTask->lease_expires_at);

        $this->postJson('/api/worker/register', $registration, $this->workerProtocolHeaders())
            ->assertCreated();

        $releasedTask = WorkflowTask::query()->findOrFail($taskId);

        $this->assertSame(TaskStatus::Ready, $releasedTask->status);
        $this->assertNull($releasedTask->lease_owner);
        $this->assertNull($releasedTask->leased_at);
        $this->assertNull($releasedTask->lease_expires_at);

        $replacementPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($replacementPoll)
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.lease_owner', $workerId)
            ->assertJsonPath('task.workflow_task_attempt', 2);
    }

    public function test_worker_registration_reclaims_existing_leases_from_non_active_worker_rows(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-restart-reclaims-non-active-lease',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $workerId = 'worker-restart-reclaims-non-active-lease';
        $registration = [
            'worker_id' => $workerId,
            'task_queue' => 'contract-queue',
            'runtime' => 'php',
            'sdk_version' => '2.0.0-test',
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => [],
            'process_metrics' => [
                'host' => 'same-container-host',
                'process_id' => 1,
                'process_started_at' => '2026-06-04T00:00:00Z',
                'process_uptime_seconds' => 0,
            ],
        ];

        $this->postJson('/api/worker/register', $registration, $this->workerProtocolHeaders())
            ->assertCreated();

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.lease_owner', $workerId)
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $taskId = (string) $poll->json('task.task_id');

        WorkerRegistration::query()
            ->where('namespace', 'default')
            ->where('worker_id', $workerId)
            ->update(['status' => 'draining']);

        $this->postJson('/api/worker/register', $registration, $this->workerProtocolHeaders())
            ->assertCreated()
            ->assertJsonPath('status', 'active');

        $releasedTask = WorkflowTask::query()->findOrFail($taskId);

        $this->assertSame(TaskStatus::Ready, $releasedTask->status);
        $this->assertNull($releasedTask->lease_owner);
        $this->assertNull($releasedTask->leased_at);
        $this->assertNull($releasedTask->lease_expires_at);

        $replacementPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($replacementPoll)
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.lease_owner', $workerId)
            ->assertJsonPath('task.workflow_task_attempt', 2);
    }

    public function test_workflow_task_poll_capability_filters_use_worker_protocol_contract(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-type-filter-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorker(
            workerId: 'worker-wrong-workflow-type',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.other-workflow'],
        );

        $wrongTypePoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-wrong-workflow-type',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($wrongTypePoll)
            ->assertJsonPath('task', null);

        $this->registerWorker(
            workerId: 'worker-matching-workflow-type',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $matchingTypePoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-matching-workflow-type',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($matchingTypePoll)
            ->assertJsonPath('task.workflow_id', 'wf-worker-type-filter-contract')
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('task.lease_owner', 'worker-matching-workflow-type');
    }

    public function test_update_backed_workflow_task_poll_exposes_resume_context(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-update-context-contract',
            'workflow_type' => 'tests.interactive-command-workflow',
            'task_queue' => 'contract-queue',
        ], $this->apiHeaders());

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $signal = $this->postJson('/api/workflows/wf-worker-update-context-contract/signal/advance', [
            'input' => ['Ada'],
            'request_id' => 'signal-update-context-1',
        ], $this->apiHeaders());

        $signal->assertAccepted();

        $this->runReadyWorkflowTask($runId);

        $update = $this->postJson('/api/workflows/wf-worker-update-context-contract/update/approve', [
            'input' => [true, 'worker-context'],
            'request_id' => 'update-context-1',
            'wait_for' => 'accepted',
        ], $this->apiHeaders());

        $update->assertAccepted()
            ->assertJsonPath('update_name', 'approve')
            ->assertJsonPath('update_status', 'accepted')
            ->assertJsonPath('wait_for', 'accepted');

        $updateId = (string) $update->json('update_id');
        $commandId = (string) $update->json('command_id');

        $this->registerWorker(
            workerId: 'worker-update-context-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.interactive-command-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-update-context-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', 'wf-worker-update-context-contract')
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_type', 'tests.interactive-command-workflow')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'worker-update-context-contract')
            ->assertJsonPath('task.workflow_wait_kind', 'update')
            ->assertJsonPath('task.open_wait_id', "update:{$updateId}")
            ->assertJsonPath('task.resume_source_kind', 'workflow_update')
            ->assertJsonPath('task.resume_source_id', $updateId)
            ->assertJsonPath('task.workflow_update_id', $updateId)
            ->assertJsonPath('task.workflow_command_id', $commandId)
            ->assertJsonPath('task.workflow_signal_id', null)
            ->assertJsonPath('task.child_call_id', null)
            ->assertJsonPath('task.timer_id', null);

        $eventTypes = array_column((array) $poll->json('task.history_events'), 'event_type');

        $this->assertContains('UpdateAccepted', $eventTypes);
    }

    public function test_external_worker_declared_update_queues_behind_earlier_signal_without_local_definition(): void
    {
        Queue::fake();

        $workflowType = 'tests.external-update-contract-workflow';
        $taskQueue = 'external-update-contract-queue';
        $workerId = 'worker-external-update-contract';

        $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'runtime' => 'php',
            'supported_workflow_types' => [$workflowType],
            'capabilities' => ['workflow_tasks'],
            'workflow_command_contracts' => [
                $workflowType => [
                    'queries' => [],
                    'query_contracts' => [],
                    'signals' => ['advance'],
                    'signal_contracts' => [
                        [
                            'name' => 'advance',
                            'parameters' => [],
                        ],
                    ],
                    'updates' => ['approve'],
                    'update_contracts' => [
                        [
                            'name' => 'approve',
                            'parameters' => [
                                [
                                    'name' => 'approved',
                                    'position' => 0,
                                    'required' => true,
                                    'variadic' => false,
                                    'default_available' => false,
                                    'type' => 'bool',
                                    'allows_null' => false,
                                ],
                                [
                                    'name' => 'source',
                                    'position' => 1,
                                    'required' => false,
                                    'variadic' => false,
                                    'default_available' => true,
                                    'default' => 'manual',
                                    'type' => 'string',
                                    'allows_null' => false,
                                ],
                            ],
                        ],
                    ],
                    'update_validators' => [],
                ],
            ],
        ], $this->workerProtocolHeaders())->assertCreated();

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-external-update-contract',
            'workflow_type' => $workflowType,
            'task_queue' => $taskQueue,
        ], $this->apiHeaders());

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $startPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($startPoll)
            ->assertJsonPath('task.workflow_id', 'wf-external-update-contract')
            ->assertJsonPath('task.workflow_type', $workflowType);

        $startTaskId = (string) $startPoll->json('task.task_id');
        $startAttempt = (int) $startPoll->json('task.workflow_task_attempt');

        $this->assertWorkerProtocolSuccess($this->postJson("/api/worker/workflow-tasks/{$startTaskId}/complete", [
            'lease_owner' => $workerId,
            'workflow_task_attempt' => $startAttempt,
            'commands' => [
                [
                    'type' => 'open_signal_wait',
                    'signal_name' => 'advance',
                    'timeout_seconds' => 300,
                ],
            ],
        ], $this->workerProtocolHeaders()));

        $signal = $this->postJson('/api/workflows/wf-external-update-contract/signal/advance', [
            'request_id' => 'external-signal-before-update-1',
        ], $this->apiHeaders());

        $signal->assertAccepted()
            ->assertJsonPath('signal_name', 'advance')
            ->assertJsonPath('command_status', 'accepted');

        $signalCommandId = (string) $signal->json('command_id');

        $update = $this->postJson('/api/workflows/wf-external-update-contract/update/approve', [
            'input' => [true, 'external-worker'],
            'request_id' => 'external-update-request-1',
            'wait_for' => 'accepted',
        ], $this->apiHeaders());

        $update->assertAccepted()
            ->assertJsonPath('workflow_id', 'wf-external-update-contract')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('update_name', 'approve')
            ->assertJsonPath('update_status', 'accepted')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('reason', null);

        $updateId = (string) $update->json('update_id');

        $accepted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'UpdateAccepted')
            ->firstOrFail();

        $this->assertSame('external-update-request-1', $accepted->payload['request_id'] ?? null);
        $this->assertSame('queued', $accepted->payload['ordering_state'] ?? null);
        $this->assertSame($signalCommandId, $accepted->payload['queued_behind_command_id'] ?? null);
        $this->assertSame('signal', $accepted->payload['queued_behind_command_type'] ?? null);

        $signalPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($signalPoll)
            ->assertJsonPath('task.workflow_id', 'wf-external-update-contract')
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_wait_kind', 'signal')
            ->assertJsonPath('task.workflow_command_id', $signalCommandId)
            ->assertJsonPath('task.workflow_update_id', null);

        $signalTaskId = (string) $signalPoll->json('task.task_id');
        $signalAttempt = (int) $signalPoll->json('task.workflow_task_attempt');

        $this->assertWorkerProtocolSuccess($this->postJson("/api/worker/workflow-tasks/{$signalTaskId}/complete", [
            'lease_owner' => $workerId,
            'workflow_task_attempt' => $signalAttempt,
            'commands' => [
                [
                    'type' => 'open_signal_wait',
                    'signal_name' => 'advance',
                    'timeout_seconds' => 300,
                ],
            ],
        ], $this->workerProtocolHeaders()));

        $updatePoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($updatePoll)
            ->assertJsonPath('task.workflow_id', 'wf-external-update-contract')
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_wait_kind', 'update')
            ->assertJsonPath('task.workflow_update_id', $updateId);

        $updateTaskId = (string) $updatePoll->json('task.task_id');
        $updateAttempt = (int) $updatePoll->json('task.workflow_task_attempt');
        $resultBlob = Serializer::serializeWithCodec('avro', [
            'approved' => true,
            'source' => 'external-worker',
        ]);

        $this->assertWorkerProtocolSuccess($this->postJson("/api/worker/workflow-tasks/{$updateTaskId}/complete", [
            'lease_owner' => $workerId,
            'workflow_task_attempt' => $updateAttempt,
            'commands' => [
                [
                    'type' => 'complete_update',
                    'update_id' => $updateId,
                    'result' => [
                        'codec' => 'avro',
                        'blob' => $resultBlob,
                    ],
                ],
            ],
        ], $this->workerProtocolHeaders()));

        /** @var WorkflowUpdate $closedUpdate */
        $closedUpdate = WorkflowUpdate::query()->findOrFail($updateId);
        $this->assertSame('completed', $closedUpdate->status->value);
        $this->assertSame($resultBlob, $closedUpdate->result);

        $duplicateFirst = $this->postJson('/api/workflows/wf-external-update-contract/update/approve', [
            'input' => [true, 'duplicate-first'],
            'request_id' => 'external-update-duplicate-1',
            'wait_for' => 'accepted',
        ], $this->apiHeaders());

        $duplicateFirst->assertAccepted();

        $duplicateSecond = $this->postJson('/api/workflows/wf-external-update-contract/update/approve', [
            'input' => [true, 'duplicate-second'],
            'request_id' => 'external-update-duplicate-1',
            'wait_for' => 'accepted',
        ], $this->apiHeaders());

        $duplicateSecond->assertAccepted()
            ->assertJsonPath('update_id', $duplicateFirst->json('update_id'));

        $duplicateAcceptedCount = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'UpdateAccepted')
            ->get()
            ->filter(static fn (WorkflowHistoryEvent $event): bool => ($event->payload['request_id'] ?? null) === 'external-update-duplicate-1')
            ->count();

        $this->assertSame(1, $duplicateAcceptedCount);
    }

    public function test_waiting_for_history_acknowledgments_advance_declared_signals_then_an_update(): void
    {
        Queue::fake();

        $workflowType = 'tests.external-waiting-message-workflow';
        $taskQueue = 'external-waiting-message-queue';
        $workerId = 'worker-external-waiting-message';
        $workflowId = 'wf-external-waiting-message';

        $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'runtime' => 'php',
            'supported_workflow_types' => [$workflowType],
            'capabilities' => ['workflow_tasks'],
            'workflow_command_contracts' => [
                $workflowType => [
                    'queries' => [],
                    'query_contracts' => [],
                    'signals' => ['increment'],
                    'signal_contracts' => [[
                        'name' => 'increment',
                        'parameters' => [[
                            'name' => 'amount',
                            'position' => 0,
                            'required' => true,
                            'variadic' => false,
                            'default_available' => false,
                            'type' => 'int',
                            'allows_null' => false,
                        ]],
                    ]],
                    'updates' => ['set'],
                    'update_validators' => [],
                    'update_contracts' => [[
                        'name' => 'set',
                        'parameters' => [[
                            'name' => 'value',
                            'position' => 0,
                            'required' => true,
                            'variadic' => false,
                            'default_available' => false,
                            'type' => 'int',
                            'allows_null' => false,
                        ]],
                    ]],
                ],
            ],
        ], $this->workerProtocolHeaders())->assertCreated();

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId,
            'workflow_type' => $workflowType,
            'task_queue' => $taskQueue,
        ], $this->apiHeaders());

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $startPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($startPoll)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId);

        $startTaskId = (string) $startPoll->json('task.task_id');
        $startAttempt = (int) $startPoll->json('task.workflow_task_attempt');

        $startComplete = $this->postJson("/api/worker/workflow-tasks/{$startTaskId}/complete", [
            'lease_owner' => $workerId,
            'workflow_task_attempt' => $startAttempt,
            'commands' => [[
                'type' => 'start_timer',
                'delay_seconds' => 300,
            ]],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($startComplete)
            ->assertJsonPath('run_status', 'waiting');

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->sole();
        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'timer')
            ->sole();

        foreach ([3, 5] as $index => $amount) {
            $this->postJson("/api/workflows/{$workflowId}/signal/increment", [
                'input' => [$amount],
                'request_id' => 'waiting-message-signal-'.($index + 1),
            ], $this->apiHeaders())
                ->assertAccepted()
                ->assertJsonPath('signal_name', 'increment')
                ->assertJsonPath('command_status', 'accepted');
        }

        $signals = WorkflowSignal::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('command_sequence')
            ->get();

        $this->assertCount(2, $signals);

        $firstSignalPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($firstSignalPoll)
            ->assertJsonPath('task.resume_source_kind', 'workflow_signal')
            ->assertJsonPath('task.workflow_signal_id', $signals[0]->id);

        $firstSignalTaskId = (string) $firstSignalPoll->json('task.task_id');
        $firstSignalAttempt = (int) $firstSignalPoll->json('task.workflow_task_attempt');
        $firstSignalAcknowledgment = $this->postJson(
            "/api/worker/workflow-tasks/{$firstSignalTaskId}/fail",
            [
                'lease_owner' => $workerId,
                'workflow_task_attempt' => $firstSignalAttempt,
                'failure' => [
                    'message' => 'workflow task waiting for scheduled history: unresolved timer',
                    'type' => 'WorkflowTaskWaitingForHistory',
                ],
            ],
            $this->workerProtocolHeaders(),
        );

        $this->assertWorkerProtocolSuccess($firstSignalAcknowledgment)
            ->assertJsonPath('outcome', 'waiting_for_history')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null);

        $secondSignalTaskId = $firstSignalAcknowledgment->json('next_task_id');
        $this->assertIsString($secondSignalTaskId);

        $update = $this->postJson("/api/workflows/{$workflowId}/update/set", [
            'input' => [13],
            'request_id' => 'waiting-message-update-1',
            'wait_for' => 'accepted',
        ], $this->apiHeaders());

        $update->assertAccepted()
            ->assertJsonPath('update_name', 'set')
            ->assertJsonPath('update_status', 'accepted')
            ->assertJsonPath('command_status', 'accepted');

        $updateId = (string) $update->json('update_id');
        $secondSignalPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($secondSignalPoll)
            ->assertJsonPath('task.task_id', $secondSignalTaskId)
            ->assertJsonPath('task.resume_source_kind', 'workflow_signal')
            ->assertJsonPath('task.workflow_signal_id', $signals[1]->id);

        $secondSignalAttempt = (int) $secondSignalPoll->json('task.workflow_task_attempt');
        $secondSignalAcknowledgment = $this->postJson(
            "/api/worker/workflow-tasks/{$secondSignalTaskId}/fail",
            [
                'lease_owner' => $workerId,
                'workflow_task_attempt' => $secondSignalAttempt,
                'failure' => [
                    'message' => 'workflow task waiting for scheduled history: unresolved timer',
                    'type' => 'WorkflowTaskWaitingForHistory',
                ],
            ],
            $this->workerProtocolHeaders(),
        );

        $this->assertWorkerProtocolSuccess($secondSignalAcknowledgment)
            ->assertJsonPath('outcome', 'waiting_for_history')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null);

        $updateTaskId = $secondSignalAcknowledgment->json('next_task_id');
        $this->assertIsString($updateTaskId);

        $signals = WorkflowSignal::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('command_sequence')
            ->get();

        $this->assertSame(['applied', 'applied'], $signals->pluck('status')->map->value->all());
        $this->assertNotNull($signals[0]->applied_at);
        $this->assertNotNull($signals[1]->applied_at);
        $this->assertSame(
            2,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', 'SignalApplied')
                ->count(),
        );

        $timer->refresh();
        $timerTask->refresh();
        $this->assertSame('pending', $timer->status->value);
        $this->assertSame('ready', $timerTask->status->value);
        $this->assertFalse(
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', 'TimerFired')
                ->exists(),
        );

        $updatePoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($updatePoll)
            ->assertJsonPath('task.task_id', $updateTaskId)
            ->assertJsonPath('task.resume_source_kind', 'workflow_update')
            ->assertJsonPath('task.workflow_update_id', $updateId);

        $updateAttempt = (int) $updatePoll->json('task.workflow_task_attempt');
        $resultBlob = Serializer::serializeWithCodec('avro', ['accepted' => true, 'value' => 13]);
        $updateComplete = $this->postJson("/api/worker/workflow-tasks/{$updateTaskId}/complete", [
            'lease_owner' => $workerId,
            'workflow_task_attempt' => $updateAttempt,
            'commands' => [[
                'type' => 'complete_update',
                'update_id' => $updateId,
                'result' => [
                    'codec' => 'avro',
                    'blob' => $resultBlob,
                ],
            ]],
            'update_validators' => [],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($updateComplete)
            ->assertJsonPath('run_status', 'waiting');

        $closedUpdate = WorkflowUpdate::query()->findOrFail($updateId);
        $this->assertSame('completed', $closedUpdate->status->value);
        $this->assertSame($resultBlob, $closedUpdate->result);
    }

    public function test_signal_backed_workflow_task_poll_exposes_resume_context(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-signal-context-contract',
            'workflow_type' => 'tests.interactive-command-workflow',
            'task_queue' => 'contract-queue',
        ], $this->apiHeaders());

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $signal = $this->postJson('/api/workflows/wf-worker-signal-context-contract/signal/advance', [
            'input' => ['Ada'],
            'request_id' => 'signal-context-1',
        ], $this->apiHeaders());

        $signal->assertAccepted()
            ->assertJsonPath('signal_name', 'advance')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('outcome', 'signal_received');

        $commandId = (string) $signal->json('command_id');

        /** @var WorkflowSignal $signalRecord */
        $signalRecord = WorkflowSignal::query()
            ->where('workflow_command_id', $commandId)
            ->sole();
        $signalRecord->forceFill([
            'workflow_sequence' => 1,
        ])->save();

        $this->registerWorker(
            workerId: 'worker-signal-context-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.interactive-command-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-signal-context-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', 'wf-worker-signal-context-contract')
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_type', 'tests.interactive-command-workflow')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'worker-signal-context-contract')
            ->assertJsonPath('task.workflow_wait_kind', 'signal')
            ->assertJsonPath('task.open_wait_id', "signal-application:{$signalRecord->id}")
            ->assertJsonPath('task.resume_source_kind', 'workflow_signal')
            ->assertJsonPath('task.resume_source_id', $signalRecord->id)
            ->assertJsonPath('task.workflow_signal_id', $signalRecord->id)
            ->assertJsonPath('task.signal_name', 'advance')
            ->assertJsonPath('task.signal_wait_id', $signalRecord->signal_wait_id)
            ->assertJsonPath('task.signal_arguments.codec', (string) $signalRecord->payload_codec)
            ->assertJsonPath('task.workflow_command_id', $commandId)
            ->assertJsonPath('task.workflow_update_id', null)
            ->assertJsonPath('task.child_call_id', null)
            ->assertJsonPath('task.timer_id', null);

        $eventTypes = array_column((array) $poll->json('task.history_events'), 'event_type');

        $this->assertContains('SignalReceived', $eventTypes);

        $this->assertSame(
            ['Ada'],
            Serializer::unserializeWithCodec(
                (string) $poll->json('task.signal_arguments.codec'),
                (string) $poll->json('task.signal_arguments.blob'),
            ),
        );

        $signalReceived = collect($poll->json('task.history_events'))
            ->first(static fn (array $event): bool => ($event['event_type'] ?? null) === 'SignalReceived');

        $this->assertIsArray($signalReceived);
        $this->assertSame(
            $poll->json('task.signal_arguments'),
            $signalReceived['payload']['arguments'] ?? null,
        );
        $this->assertSame(1, $signalReceived['payload']['workflow_sequence'] ?? null);
    }

    public function test_paginated_workflow_task_history_enriches_signal_received_arguments(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-signal-history-page-contract',
            'workflow_type' => 'tests.interactive-command-workflow',
            'task_queue' => 'contract-queue',
        ], $this->apiHeaders());

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $signal = $this->postJson('/api/workflows/wf-worker-signal-history-page-contract/signal/advance', [
            'input' => ['Ada'],
            'request_id' => 'signal-history-page-1',
        ], $this->apiHeaders());

        $signal->assertAccepted()
            ->assertJsonPath('signal_name', 'advance')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('outcome', 'signal_received');

        $this->registerWorker(
            workerId: 'worker-signal-history-page-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.interactive-command-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-signal-history-page-contract',
            'task_queue' => 'contract-queue',
            'history_page_size' => 1,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', 'wf-worker-signal-history-page-contract')
            ->assertJsonPath('task.workflow_wait_kind', 'signal')
            ->assertJsonPath('task.signal_name', 'advance');

        $this->assertIsString($poll->json('task.signal_arguments.codec'));
        $this->assertIsString($poll->json('task.signal_arguments.blob'));
        $this->assertIsString($poll->json('task.next_history_page_token'));
        $this->assertNotContains(
            'SignalReceived',
            array_column((array) $poll->json('task.history_events'), 'event_type'),
        );

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $history = $this->postJson("/api/worker/workflow-tasks/{$taskId}/history", [
            'lease_owner' => 'worker-signal-history-page-contract',
            'workflow_task_attempt' => $attempt,
            'next_history_page_token' => (string) $poll->json('task.next_history_page_token'),
            'history_page_size' => 100,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($history)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt);

        $signalReceived = collect($history->json('history_events'))
            ->first(static fn (array $event): bool => ($event['event_type'] ?? null) === 'SignalReceived');

        $this->assertIsArray($signalReceived);
        $this->assertSame(
            $poll->json('task.signal_arguments'),
            $signalReceived['payload']['arguments'] ?? null,
        );
        $this->assertSame(
            ['Ada'],
            Serializer::unserializeWithCodec(
                (string) ($signalReceived['payload']['arguments']['codec'] ?? ''),
                (string) ($signalReceived['payload']['arguments']['blob'] ?? ''),
            ),
        );
    }

    public function test_update_backed_workflow_task_complete_can_close_accepted_update(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-update-complete-contract',
            'workflow_type' => 'tests.interactive-command-workflow',
            'task_queue' => 'contract-queue',
        ], $this->apiHeaders());

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $signal = $this->postJson('/api/workflows/wf-worker-update-complete-contract/signal/advance', [
            'input' => ['Ada'],
            'request_id' => 'signal-update-complete-1',
        ], $this->apiHeaders());

        $signal->assertAccepted();

        $this->runReadyWorkflowTask($runId);

        $update = $this->postJson('/api/workflows/wf-worker-update-complete-contract/update/approve', [
            'input' => [true, 'worker-complete'],
            'request_id' => 'update-complete-1',
            'wait_for' => 'accepted',
        ], $this->apiHeaders());

        $update->assertAccepted()
            ->assertJsonPath('update_status', 'accepted');

        $updateId = (string) $update->json('update_id');
        $resultBlob = Serializer::serializeWithCodec('avro', [
            'approved' => true,
            'source' => 'worker-complete',
        ]);

        $this->registerWorker(
            workerId: 'worker-update-complete-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.interactive-command-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-update-complete-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_update_id', $updateId);

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-update-complete-contract',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'complete_update',
                    'update_id' => $updateId,
                    'result' => [
                        'codec' => 'avro',
                        'blob' => $resultBlob,
                    ],
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'waiting')
            ->assertJsonPath('created_task_ids', []);

        /** @var WorkflowUpdate $closedUpdate */
        $closedUpdate = WorkflowUpdate::query()->findOrFail($updateId);

        $this->assertSame('completed', $closedUpdate->status->value);
        $this->assertSame('update_completed', $closedUpdate->outcome->value);
        $this->assertSame($resultBlob, $closedUpdate->result);
        $this->assertNotNull($closedUpdate->closed_at);

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', ['UpdateAccepted', 'UpdateApplied', 'UpdateCompleted'])
            ->orderBy('sequence')
            ->get();

        $this->assertCount(3, $events);
        $this->assertSame('UpdateAccepted', $events[0]->event_type->value);
        $this->assertSame('UpdateApplied', $events[1]->event_type->value);
        $this->assertSame('UpdateCompleted', $events[2]->event_type->value);
        $this->assertSame($updateId, $events[2]->payload['update_id']);
        $this->assertSame($resultBlob, $events[2]->payload['result']);
    }

    public function test_update_backed_workflow_task_fail_can_close_accepted_update(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-update-fail-contract',
            'workflow_type' => 'tests.interactive-command-workflow',
            'task_queue' => 'contract-queue',
        ], $this->apiHeaders());

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $signal = $this->postJson('/api/workflows/wf-worker-update-fail-contract/signal/advance', [
            'input' => ['Ada'],
            'request_id' => 'signal-update-fail-1',
        ], $this->apiHeaders());

        $signal->assertAccepted();

        $this->runReadyWorkflowTask($runId);

        $update = $this->postJson('/api/workflows/wf-worker-update-fail-contract/update/approve', [
            'input' => [false, 'worker-fail'],
            'request_id' => 'update-fail-1',
            'wait_for' => 'accepted',
        ], $this->apiHeaders());

        $update->assertAccepted()
            ->assertJsonPath('update_status', 'accepted');

        $updateId = (string) $update->json('update_id');

        $this->registerWorker(
            workerId: 'worker-update-fail-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.interactive-command-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-update-fail-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_update_id', $updateId);

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-update-fail-contract',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'fail_update',
                    'update_id' => $updateId,
                    'message' => 'approval denied by worker',
                    'exception_class' => 'App\\Workflow\\ApprovalDenied',
                    'exception_type' => 'approval_denied',
                    'non_retryable' => true,
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'waiting')
            ->assertJsonPath('created_task_ids', []);

        /** @var WorkflowUpdate $closedUpdate */
        $closedUpdate = WorkflowUpdate::query()->findOrFail($updateId);

        $this->assertSame('failed', $closedUpdate->status->value);
        $this->assertSame('update_failed', $closedUpdate->outcome->value);
        $this->assertSame('approval denied by worker', $closedUpdate->failure_message);
        $this->assertNotNull($closedUpdate->failure_id);
        $this->assertNotNull($closedUpdate->closed_at);

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->findOrFail($closedUpdate->failure_id);

        $this->assertSame('workflow_command', $failure->source_kind);
        $this->assertSame('update', $failure->propagation_kind);
        $this->assertTrue($failure->non_retryable);
        $this->assertSame('App\\Workflow\\ApprovalDenied', $failure->exception_class);
        $this->assertSame('approval denied by worker', $failure->message);

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', ['UpdateAccepted', 'UpdateCompleted'])
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame('UpdateAccepted', $events[0]->event_type->value);
        $this->assertSame('UpdateCompleted', $events[1]->event_type->value);
        $this->assertSame($updateId, $events[1]->payload['update_id']);
        $this->assertSame($failure->id, $events[1]->payload['failure_id']);
        $this->assertSame('approval_denied', $events[1]->payload['exception_type']);
        $this->assertSame('App\\Workflow\\ApprovalDenied', $events[1]->payload['exception_class']);
        $this->assertSame('approval denied by worker', $events[1]->payload['message']);
        $this->assertTrue($events[1]->payload['non_retryable']);
    }

    public function test_continue_as_new_completion_uses_worker_protocol_contract(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-continue-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
            'memo' => [
                'obsolete' => 'remove-me',
                'tenant' => 'acme',
            ],
        ], $this->apiHeaders());

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $originalRunId = (string) $start->json('run_id');

        $this->registerWorker(
            workerId: 'worker-continue-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );
        $this->registerWorker(
            workerId: 'worker-continued-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-continue-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $originalRunId)
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('task.lease_owner', 'worker-continue-contract');

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-continue-contract',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'upsert_memo',
                    'entries' => MemoPayload::mapEnvelope([
                        'obsolete' => null,
                        'stage' => 'continued',
                    ]),
                ],
                [
                    'type' => 'continue_as_new',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'arguments' => Serializer::serializeWithCodec('avro', ['Ada v2']),
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $originalRunId)
            ->assertJsonPath('run_status', 'completed')
            ->assertJsonPath('reason', null)
            ->assertJsonStructure(['created_task_ids']);

        $runs = $this->getJson("/api/workflows/{$workflowId}/runs", $this->controlPlaneHeadersWithWorkerProtocol());

        $runs->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonCount(2, 'runs')
            ->assertJsonPath('runs.0.run_id', $originalRunId)
            ->assertJsonPath('runs.0.status', 'completed')
            ->assertJsonPath('runs.1.run_number', 2)
            ->assertJsonPath('runs.1.status', 'pending');

        $continuedRunId = (string) $runs->json('runs.1.run_id');

        $this->getJson(
            "/api/workflows/{$workflowId}/runs/{$continuedRunId}",
            $this->controlPlaneHeadersWithWorkerProtocol(),
        )->assertOk()
            ->assertJsonPath('memo', [
                'stage' => 'continued',
                'tenant' => 'acme',
            ]);

        $continuedPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-continued-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($continuedPoll)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $continuedRunId)
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('task.lease_owner', 'worker-continued-contract');
    }

    public function test_start_child_workflow_completion_uses_worker_protocol_contract(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-child-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $parentWorkflowId = (string) $start->json('workflow_id');
        $parentRunId = (string) $start->json('run_id');

        $this->registerWorker(
            workerId: 'worker-child-parent-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );
        $this->registerWorker(
            workerId: 'worker-child-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-child-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-child-parent-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', $parentWorkflowId)
            ->assertJsonPath('task.run_id', $parentRunId)
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('task.lease_owner', 'worker-child-parent-contract');

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-child-parent-contract',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'start_child_workflow',
                    'workflow_type' => 'tests.external-child-workflow',
                    'queue' => 'contract-queue',
                    'parent_close_policy' => 'request_cancel',
                    'arguments' => Serializer::serializeWithCodec('avro', ['child-input']),
                    'retry_policy' => [
                        'max_attempts' => 3,
                        'backoff_seconds' => [1, 5],
                        'non_retryable_error_types' => ['ValidationError'],
                    ],
                    'execution_timeout_seconds' => 600,
                    'run_timeout_seconds' => 120,
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $parentRunId)
            ->assertJsonPath('run_status', 'waiting')
            ->assertJsonPath('reason', null)
            ->assertJsonStructure(['created_task_ids']);

        $childPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-child-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($childPoll)
            ->assertJsonPath('task.workflow_type', 'tests.external-child-workflow')
            ->assertJsonPath('task.lease_owner', 'worker-child-contract');

        $this->assertNotSame($parentWorkflowId, $childPoll->json('task.workflow_id'));
    }

    public function test_child_workflow_completion_resume_uses_worker_protocol_contract(): void
    {
        Queue::fake();

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-child-resume-contract',
            'workflow_type' => 'remote.parent-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $parentWorkflowId = (string) $start->json('workflow_id');
        $parentRunId = (string) $start->json('run_id');

        $this->registerWorker(
            workerId: 'worker-child-resume-parent',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['remote.parent-workflow'],
        );
        $this->registerWorker(
            workerId: 'worker-child-resume-child',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['remote.child-workflow'],
        );
        $this->registerWorker(
            workerId: 'worker-child-resume-parent-again',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['remote.parent-workflow'],
        );

        $parentPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-child-resume-parent',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($parentPoll)
            ->assertJsonPath('task.workflow_id', $parentWorkflowId)
            ->assertJsonPath('task.run_id', $parentRunId)
            ->assertJsonPath('task.workflow_type', 'remote.parent-workflow')
            ->assertJsonPath('task.lease_owner', 'worker-child-resume-parent');

        $startChild = $this->postJson(
            sprintf('/api/worker/workflow-tasks/%s/complete', $parentPoll->json('task.task_id')),
            [
                'lease_owner' => $parentPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $parentPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'start_child_workflow',
                        'workflow_type' => 'remote.child-workflow',
                        'queue' => 'contract-queue',
                        'arguments' => Serializer::serializeWithCodec('avro', ['child-input']),
                    ],
                ],
            ],
            $this->workerProtocolHeaders(),
        );

        $this->assertWorkerProtocolSuccess($startChild)
            ->assertJsonPath('task_id', $parentPoll->json('task.task_id'))
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'waiting');

        $childPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-child-resume-child',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($childPoll)
            ->assertJsonPath('task.workflow_type', 'remote.child-workflow')
            ->assertJsonPath('task.lease_owner', 'worker-child-resume-child');

        $childRunId = (string) $childPoll->json('task.run_id');

        $completeChild = $this->postJson(
            sprintf('/api/worker/workflow-tasks/%s/complete', $childPoll->json('task.task_id')),
            [
                'lease_owner' => $childPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $childPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', [
                            'child_result' => 'ok',
                        ]),
                    ],
                ],
            ],
            $this->workerProtocolHeaders(),
        );

        $this->assertWorkerProtocolSuccess($completeChild)
            ->assertJsonPath('task_id', $childPoll->json('task.task_id'))
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $childRunId)
            ->assertJsonPath('run_status', 'completed');

        $parentResumePoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-child-resume-parent-again',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($parentResumePoll)
            ->assertJsonPath('task.workflow_id', $parentWorkflowId)
            ->assertJsonPath('task.run_id', $parentRunId)
            ->assertJsonPath('task.workflow_type', 'remote.parent-workflow')
            ->assertJsonPath('task.lease_owner', 'worker-child-resume-parent-again')
            ->assertJsonPath('task.workflow_wait_kind', 'child')
            ->assertJsonPath('task.resume_source_kind', 'child_workflow_run')
            ->assertJsonPath('task.resume_source_id', $childRunId)
            ->assertJsonPath('task.child_workflow_run_id', $childRunId)
            ->assertJsonPath('task.workflow_sequence', 1)
            ->assertJsonPath('task.workflow_event_type', 'ChildRunCompleted');

        $childCallId = $parentResumePoll->json('task.child_call_id');

        $this->assertIsString($childCallId);
        $this->assertNotSame('', $childCallId);
        $parentResumePoll->assertJsonPath('task.open_wait_id', sprintf('child:%s', $childCallId));

        $resumeEvents = collect((array) $parentResumePoll->json('task.history_events'));
        $childCompleted = $resumeEvents->firstWhere('event_type', 'ChildRunCompleted');

        $this->assertIsArray($childCompleted);
        $this->assertSame($childCallId, $childCompleted['payload']['child_call_id'] ?? null);
        $this->assertSame($childRunId, $childCompleted['payload']['child_workflow_run_id'] ?? null);
        $this->assertSame('completed', $childCompleted['payload']['child_status'] ?? null);

        $completeParent = $this->postJson(
            sprintf('/api/worker/workflow-tasks/%s/complete', $parentResumePoll->json('task.task_id')),
            [
                'lease_owner' => $parentResumePoll->json('task.lease_owner'),
                'workflow_task_attempt' => $parentResumePoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', [
                            'child_run_id' => $childRunId,
                            'child_call_id' => $childCallId,
                        ]),
                    ],
                ],
            ],
            $this->workerProtocolHeaders(),
        );

        $this->assertWorkerProtocolSuccess($completeParent)
            ->assertJsonPath('task_id', $parentResumePoll->json('task.task_id'))
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $parentRunId)
            ->assertJsonPath('run_status', 'completed');
    }

    public function test_schedule_activity_completion_uses_worker_protocol_contract(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-activity-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $this->registerWorker(
            workerId: 'worker-activity-scheduler-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );
        $this->registerWorker(
            workerId: 'activity-worker-scheduled-contract',
            taskQueue: 'contract-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );
        $this->registerWorker(
            workerId: 'worker-activity-resume-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-activity-scheduler-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('task.lease_owner', 'worker-activity-scheduler-contract');

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $codec = (string) config('workflows.serializer');

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-activity-scheduler-contract',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'schedule_activity',
                    'activity_type' => 'tests.external-greeting-activity',
                    'arguments' => Serializer::serializeWithCodec($codec, ['Ada']),
                    'queue' => 'contract-activities',
                    'retry_policy' => [
                        'max_attempts' => 4,
                        'backoff_seconds' => [1, 3, 9],
                        'non_retryable_error_types' => ['ValidationError'],
                    ],
                    'start_to_close_timeout' => 30,
                    'schedule_to_start_timeout' => 45,
                    'schedule_to_close_timeout' => 120,
                    'heartbeat_timeout' => 10,
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'waiting')
            ->assertJsonPath('reason', null)
            ->assertJsonStructure(['created_task_ids']);

        $this->assertNotEmpty($complete->json('created_task_ids'));

        $activityPoll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'activity-worker-scheduled-contract',
            'task_queue' => 'contract-activities',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($activityPoll)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.task_queue', 'contract-activities')
            ->assertJsonPath('task.lease_owner', 'activity-worker-scheduled-contract')
            ->assertJsonPath('task.arguments.codec', $codec)
            ->assertJsonPath('task.retry_policy.max_attempts', 4)
            ->assertJsonPath('task.retry_policy.backoff_seconds', [1, 3, 9])
            ->assertJsonPath('task.retry_policy.non_retryable_error_types', ['ValidationError'])
            ->assertJsonPath('task.retry_policy.start_to_close_timeout', 30)
            ->assertJsonPath('task.retry_policy.schedule_to_start_timeout', 45)
            ->assertJsonPath('task.retry_policy.schedule_to_close_timeout', 120)
            ->assertJsonPath('task.retry_policy.heartbeat_timeout', 10)
            ->assertJsonMissingPath('task.activity_class');

        $this->assertSame(
            ['Ada'],
            Serializer::unserializeWithCodec(
                (string) $activityPoll->json('task.arguments.codec'),
                (string) $activityPoll->json('task.arguments.blob'),
            ),
        );

        $activityTaskId = (string) $activityPoll->json('task.task_id');
        $activityAttemptId = (string) $activityPoll->json('task.activity_attempt_id');
        $activityExecutionId = (string) $activityPoll->json('task.activity_execution_id');

        $completeActivity = $this->postJson("/api/worker/activity-tasks/{$activityTaskId}/complete", [
            'activity_attempt_id' => $activityAttemptId,
            'lease_owner' => 'activity-worker-scheduled-contract',
            'result' => Serializer::serializeWithCodec('avro', 'Hello, Ada!'),
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($completeActivity)
            ->assertJsonPath('task_id', $activityTaskId)
            ->assertJsonPath('activity_attempt_id', $activityAttemptId)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null);

        $this->assertIsString($completeActivity->json('next_task_id'));

        $resumePoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-activity-resume-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($resumePoll)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('task.lease_owner', 'worker-activity-resume-contract')
            ->assertJsonPath('task.workflow_wait_kind', 'activity')
            ->assertJsonPath('task.open_wait_id', "activity:{$activityExecutionId}")
            ->assertJsonPath('task.resume_source_kind', 'activity_execution')
            ->assertJsonPath('task.resume_source_id', $activityExecutionId)
            ->assertJsonPath('task.activity_execution_id', $activityExecutionId)
            ->assertJsonPath('task.activity_attempt_id', $activityAttemptId)
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.workflow_event_type', 'ActivityCompleted');

        $resumeEvents = collect((array) $resumePoll->json('task.history_events'));
        $resumeEventTypes = $resumeEvents->pluck('event_type')->all();

        $this->assertContains('ActivityCompleted', $resumeEventTypes);

        $activityCompleted = $resumeEvents->firstWhere('event_type', 'ActivityCompleted');

        $this->assertIsArray($activityCompleted);
        $this->assertSame(
            $activityCompleted['payload']['sequence'] ?? null,
            $resumePoll->json('task.workflow_sequence'),
        );

        $history = $this->getJson(
            "/api/workflows/{$workflowId}/runs/{$runId}/history",
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $history->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER);

        $eventTypes = array_column($history->json('events'), 'event_type');

        $this->assertContains('ActivityScheduled', $eventTypes);
        $this->assertContains('ActivityStarted', $eventTypes);
        $this->assertContains('ActivityCompleted', $eventTypes);
    }

    public function test_failed_workflow_read_response_includes_activity_failure_context(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-failure-context-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $codec = (string) config('workflows.serializer');

        $this->registerWorker(
            workerId: 'worker-failure-context-scheduler',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );
        $this->registerWorker(
            workerId: 'activity-failure-context-worker',
            taskQueue: 'contract-compensations',
            supportedActivityTypes: ['cancel_flight'],
        );
        $this->registerWorker(
            workerId: 'worker-failure-context-resume',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-failure-context-scheduler',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll);

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-failure-context-scheduler',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'schedule_activity',
                    'activity_type' => 'cancel_flight',
                    'arguments' => Serializer::serializeWithCodec($codec, []),
                    'queue' => 'contract-compensations',
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('run_status', 'waiting');

        $activityPoll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'activity-failure-context-worker',
            'task_queue' => 'contract-compensations',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($activityPoll)
            ->assertJsonPath('task.activity_type', 'cancel_flight');

        $activityTaskId = (string) $activityPoll->json('task.task_id');
        $activityAttemptId = (string) $activityPoll->json('task.activity_attempt_id');

        $failActivity = $this->postJson("/api/worker/activity-tasks/{$activityTaskId}/fail", [
            'activity_attempt_id' => $activityAttemptId,
            'lease_owner' => 'activity-failure-context-worker',
            'failure' => [
                'message' => 'cancel_flight typed compensation failure',
                'type' => 'TypedCompensationError',
                'non_retryable' => true,
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($failActivity)
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonStructure(['next_task_id']);

        $resumePoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-failure-context-resume',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($resumePoll)
            ->assertJsonPath('task.workflow_event_type', 'ActivityFailed')
            ->assertJsonPath('task.activity_type', 'cancel_flight');

        $resumeTaskId = (string) $resumePoll->json('task.task_id');
        $resumeAttempt = (int) $resumePoll->json('task.workflow_task_attempt');

        $failWorkflow = $this->postJson("/api/worker/workflow-tasks/{$resumeTaskId}/complete", [
            'lease_owner' => 'worker-failure-context-resume',
            'workflow_task_attempt' => $resumeAttempt,
            'commands' => [
                [
                    'type' => 'fail_workflow',
                    'message' => 'compensation failed for unknown: activity failed',
                    'exception_class' => 'RuntimeException',
                    'exception_type' => 'RuntimeException',
                    'exception' => [
                        'class' => 'RuntimeException',
                        'type' => 'TypedCompensationError',
                        'message' => 'cancel_flight typed compensation failure',
                    ],
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($failWorkflow)
            ->assertJsonPath('run_status', 'failed');

        $state = $this->getJson("/api/workflows/{$workflowId}", $this->apiHeaders());

        $state->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('is_terminal', true)
            ->assertJsonPath('error', 'cancel_flight typed compensation failure')
            ->assertJsonPath('failure.message', 'cancel_flight typed compensation failure')
            ->assertJsonPath('failure.exception.type', 'TypedCompensationError')
            ->assertJsonPath('failure.exception.message', 'cancel_flight typed compensation failure')
            ->assertJsonPath('failure.activity_failures.0.activity_type', 'cancel_flight')
            ->assertJsonPath('failure.activity_failures.0.message', 'cancel_flight typed compensation failure')
            ->assertJsonPath('failure.failures.0.message', 'cancel_flight typed compensation failure')
            ->assertJsonPath('failures.0.message', 'cancel_flight typed compensation failure');
    }

    public function test_start_timer_completion_uses_worker_protocol_contract(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-timer-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $this->registerWorker(
            workerId: 'worker-timer-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-timer-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('task.lease_owner', 'worker-timer-contract');

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-timer-contract',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'start_timer',
                    'delay_seconds' => 30,
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'waiting')
            ->assertJsonPath('reason', null)
            ->assertJsonStructure(['created_task_ids']);

        $this->assertNotEmpty($complete->json('created_task_ids'));

        $history = $this->getJson(
            "/api/workflows/{$workflowId}/runs/{$runId}/history",
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $history->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER);

        $eventTypes = array_column($history->json('events'), 'event_type');

        $this->assertContains('TimerScheduled', $eventTypes);
    }

    public function test_service_mode_poll_dispatches_timer_job_on_local_queue(): void
    {
        Queue::fake();

        config([
            'server.mode' => 'service',
            'workflows.v2.compatibility.current' => null,
            'workflows.v2.compatibility.supported' => [],
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-service-timer-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorker(
            workerId: 'worker-service-timer-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-service-timer-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll);

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-service-timer-contract',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'start_timer',
                    'delay_seconds' => 0,
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('run_status', 'waiting')
            ->assertJsonStructure(['created_task_ids']);

        $timerTaskId = (string) $complete->json('created_task_ids.0');

        Queue::assertPushed(RunTimerTask::class, 1);
        Queue::assertPushed(
            RunTimerTask::class,
            static fn (RunTimerTask $job): bool => $job->taskId === $timerTaskId
                && $job->queue === null,
        );
    }

    public function test_database_queue_worker_fires_a_service_mode_timer_and_resumes_the_workflow(): void
    {
        config([
            'queue.default' => 'database',
            'server.mode' => 'service',
            'workflows.v2.compatibility.current' => null,
            'workflows.v2.compatibility.supported' => [],
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-database-queue-timer-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorker(
            workerId: 'worker-database-queue-timer-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-database-queue-timer-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll);

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-database-queue-timer-contract',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'start_timer',
                    'delay_seconds' => 0,
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('run_status', 'waiting')
            ->assertJsonStructure(['created_task_ids']);

        $timerTaskId = (string) $complete->json('created_task_ids.0');
        $queuedJob = DB::table('jobs')->sole();
        $queuedPayload = json_decode($queuedJob->payload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('default', $queuedJob->queue);
        $this->assertSame(RunTimerTask::class, $queuedPayload['displayName'] ?? null);
        $this->assertStringContainsString($timerTaskId, (string) ($queuedPayload['data']['command'] ?? ''));

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 1,
        ])->assertExitCode(0);

        $this->assertFalse(DB::table('jobs')->where('id', $queuedJob->id)->exists());

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()->findOrFail($timerTaskId);
        $this->assertSame(TaskStatus::Completed, $timerTask->status);

        $resumePoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-database-queue-timer-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($resumePoll)
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.workflow_wait_kind', 'timer')
            ->assertJsonPath('task.resume_source_kind', 'timer')
            ->assertJsonPath('task.workflow_event_type', 'TimerFired');
    }

    public function test_service_mode_timer_dispatch_does_not_swallow_database_queue_failure(): void
    {
        config([
            'queue.default' => 'database',
            'server.mode' => 'embedded',
            'workflows.v2.compatibility.current' => null,
            'workflows.v2.compatibility.supported' => [],
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-database-queue-failure-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorker(
            workerId: 'worker-database-queue-failure-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-database-queue-failure-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll);

        $taskId = (string) $poll->json('task.task_id');
        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-database-queue-failure-contract',
            'workflow_task_attempt' => (int) $poll->json('task.workflow_task_attempt'),
            'commands' => [
                [
                    'type' => 'start_timer',
                    'delay_seconds' => 0,
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete);
        $timerTaskId = (string) $complete->json('created_task_ids.0');
        $this->assertNotSame('', $timerTaskId);
        $this->assertSame(0, DB::table('jobs')->count());

        Schema::drop('jobs');
        config(['server.mode' => 'service']);

        $this->expectException(QueryException::class);

        app(ServiceModeTimerDispatcher::class)->dispatchCreatedTaskIds([$timerTaskId]);
    }

    public function test_worker_completion_dispatches_service_mode_timer_when_task_observer_is_suppressed(): void
    {
        Queue::fake();

        config([
            'server.mode' => 'service',
            'workflows.v2.compatibility.current' => null,
            'workflows.v2.compatibility.supported' => [],
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-service-timer-fallback-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorker(
            workerId: 'worker-service-timer-fallback-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-service-timer-fallback-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll);

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $complete = WorkflowTask::withoutEvents(
            fn (): TestResponse => $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'worker-service-timer-fallback-contract',
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'start_timer',
                        'delay_seconds' => 0,
                    ],
                ],
            ], $this->workerProtocolHeaders()),
        );

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('run_status', 'waiting')
            ->assertJsonStructure(['created_task_ids']);

        $timerTaskId = (string) $complete->json('created_task_ids.0');

        Queue::assertPushed(RunTimerTask::class, 1);
        Queue::assertPushed(
            RunTimerTask::class,
            static fn (RunTimerTask $job): bool => $job->taskId === $timerTaskId
                && $job->queue === null,
        );
    }

    public function test_service_mode_poll_recovers_due_timer_when_queue_delivery_was_missed(): void
    {
        Queue::fake();

        config([
            'server.mode' => 'service',
            'workflows.v2.compatibility.current' => null,
            'workflows.v2.compatibility.supported' => [],
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-service-timer-poll-recovery',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $this->registerWorker(
            workerId: 'worker-service-timer-poll-recovery',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-service-timer-poll-recovery',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll);

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-service-timer-poll-recovery',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'start_timer',
                    'delay_seconds' => 0,
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'waiting')
            ->assertJsonStructure(['created_task_ids']);

        $timerTaskId = (string) $complete->json('created_task_ids.0');

        Queue::assertPushed(RunTimerTask::class, 1);

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()->findOrFail($timerTaskId);
        $timerId = $timerTask->payload['timer_id'] ?? null;

        $this->assertIsString($timerId);
        $this->assertSame(TaskStatus::Ready, $timerTask->status);

        $dueAt = Carbon::parse('2026-05-20 12:00:00.500000');
        $timerTask->forceFill(['available_at' => $dueAt])->save();
        WorkflowTimer::query()
            ->findOrFail($timerId)
            ->forceFill(['fire_at' => $dueAt])
            ->save();

        Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00.750000'));

        try {
            $resumePoll = $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-service-timer-poll-recovery',
                'task_queue' => 'contract-queue',
            ], $this->workerProtocolHeaders());

            $this->assertWorkerProtocolSuccess($resumePoll)
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.workflow_id', $workflowId)
                ->assertJsonPath('task.run_id', $runId)
                ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow')
                ->assertJsonPath('task.lease_owner', 'worker-service-timer-poll-recovery')
                ->assertJsonPath('task.workflow_wait_kind', 'timer')
                ->assertJsonPath('task.resume_source_kind', 'timer')
                ->assertJsonPath('task.resume_source_id', $timerId)
                ->assertJsonPath('task.timer_id', $timerId)
                ->assertJsonPath('task.workflow_event_type', 'TimerFired');
        } finally {
            Carbon::setTestNow();
        }

        /** @var WorkflowTask $firedTimerTask */
        $firedTimerTask = WorkflowTask::query()->findOrFail($timerTaskId);

        $this->assertSame(TaskStatus::Completed, $firedTimerTask->status);
        Queue::assertPushed(RunTimerTask::class, 1);
    }

    public function test_service_mode_due_timer_recovery_skips_incompatible_backlog(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00'));

        try {
            config([
                'server.mode' => 'service',
                'server.polling.due_timer_recovery_scan_limit' => 2,
                'workflows.v2.compatibility.current' => null,
                'workflows.v2.compatibility.supported' => [],
                'workflows.v2.task_dispatch_mode' => 'poll',
            ]);

            $this->configureWorkflowTypes([
                'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
            ]);

            foreach (['build-a', 'build-b'] as $buildId) {
                $this->postJson('/api/worker/register', [
                    'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                    'worker_id' => "worker-service-timer-backlog-{$buildId}",
                    'task_queue' => 'contract-queue',
                    'runtime' => 'php',
                    'sdk_version' => '1.0.0',
                    'build_id' => $buildId,
                    'supported_workflow_types' => ['tests.external-greeting-workflow'],
                ], $this->workerProtocolHeaders())->assertCreated();
            }

            $cases = [
                [
                    'workflow_id' => 'wf-worker-service-timer-backlog-a-1',
                    'worker_id' => 'worker-service-timer-backlog-build-a',
                    'build_id' => 'build-a',
                    'available_at' => '2026-05-20 12:00:00',
                ],
                [
                    'workflow_id' => 'wf-worker-service-timer-backlog-a-2',
                    'worker_id' => 'worker-service-timer-backlog-build-a',
                    'build_id' => 'build-a',
                    'available_at' => '2026-05-20 12:00:01',
                ],
                [
                    'workflow_id' => 'wf-worker-service-timer-backlog-b',
                    'worker_id' => 'worker-service-timer-backlog-build-b',
                    'build_id' => 'build-b',
                    'available_at' => '2026-05-20 12:00:02',
                ],
            ];

            $timerTasks = [];

            foreach ($cases as $case) {
                $start = $this->postJson('/api/workflows', [
                    'workflow_id' => $case['workflow_id'],
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'task_queue' => 'contract-queue',
                    'input' => ['Ada'],
                ], $this->apiHeaders());

                $start->assertCreated();

                $runId = (string) $start->json('run_id');

                $this->assertNull(WorkflowRun::query()->whereKey($runId)->value('compatibility'));
                $this->assertNull(WorkflowTask::query()
                    ->where('workflow_run_id', $runId)
                    ->where('task_type', 'workflow')
                    ->where('status', TaskStatus::Ready->value)
                    ->value('compatibility'));

                WorkflowRun::query()
                    ->whereKey($runId)
                    ->update(['compatibility' => $case['build_id']]);

                $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
                    'worker_id' => $case['worker_id'],
                    'task_queue' => 'contract-queue',
                ], $this->workerProtocolHeaders());

                $this->assertWorkerProtocolSuccess($poll)
                    ->assertJsonPath('poll_status', 'leased')
                    ->assertJsonPath('task.workflow_id', $case['workflow_id']);

                $taskId = (string) $poll->json('task.task_id');
                $attempt = (int) $poll->json('task.workflow_task_attempt');

                $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                    'lease_owner' => $case['worker_id'],
                    'workflow_task_attempt' => $attempt,
                    'commands' => [
                        [
                            'type' => 'start_timer',
                            'delay_seconds' => 30,
                        ],
                    ],
                ], $this->workerProtocolHeaders());

                $this->assertWorkerProtocolSuccess($complete)
                    ->assertJsonPath('run_status', 'waiting')
                    ->assertJsonStructure(['created_task_ids']);

                $timerTaskId = (string) $complete->json('created_task_ids.0');

                /** @var WorkflowTask $timerTask */
                $timerTask = WorkflowTask::query()->findOrFail($timerTaskId);
                $timerId = $timerTask->payload['timer_id'] ?? null;

                $this->assertIsString($timerId);

                $timerTasks[$case['workflow_id']] = [
                    'task_id' => $timerTaskId,
                    'timer_id' => $timerId,
                    'build_id' => $case['build_id'],
                    'available_at' => $case['available_at'],
                ];
            }

            foreach ($timerTasks as $timer) {
                $availableAt = Carbon::parse($timer['available_at']);

                WorkflowTask::query()
                    ->whereKey($timer['task_id'])
                    ->update([
                        'available_at' => $availableAt,
                        'compatibility' => $timer['build_id'],
                    ]);

                WorkflowTimer::query()
                    ->whereKey($timer['timer_id'])
                    ->update(['fire_at' => $availableAt]);
            }

            Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:10'));

            $resumePoll = $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-service-timer-backlog-build-b',
                'task_queue' => 'contract-queue',
            ], $this->workerProtocolHeaders());

            $this->assertWorkerProtocolSuccess($resumePoll)
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.workflow_id', 'wf-worker-service-timer-backlog-b')
                ->assertJsonPath('task.workflow_wait_kind', 'timer')
                ->assertJsonPath('task.resume_source_kind', 'timer')
                ->assertJsonPath('task.workflow_event_type', 'TimerFired');

            $oldestIncompatibleTimerTask = WorkflowTask::query()
                ->findOrFail($timerTasks['wf-worker-service-timer-backlog-a-1']['task_id']);
            $secondIncompatibleTimerTask = WorkflowTask::query()
                ->findOrFail($timerTasks['wf-worker-service-timer-backlog-a-2']['task_id']);
            $compatibleTimerTask = WorkflowTask::query()
                ->findOrFail($timerTasks['wf-worker-service-timer-backlog-b']['task_id']);

            $this->assertSame(TaskStatus::Ready, $oldestIncompatibleTimerTask->status);
            $this->assertSame(TaskStatus::Ready, $secondIncompatibleTimerTask->status);
            $this->assertSame(TaskStatus::Completed, $compatibleTimerTask->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_timer_resume_task_exposes_worker_protocol_context(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-timer-resume-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $this->registerWorker(
            workerId: 'worker-timer-resume-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-timer-resume-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll);

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-timer-resume-contract',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'start_timer',
                    'delay_seconds' => 0,
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'waiting')
            ->assertJsonStructure(['created_task_ids']);

        $timerTaskId = (string) $complete->json('created_task_ids.0');

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()->findOrFail($timerTaskId);
        $timerId = $timerTask->payload['timer_id'] ?? null;

        $this->assertIsString($timerId);

        $this->app->call([new RunTimerTask($timerTaskId), 'handle']);

        $resumePoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-timer-resume-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($resumePoll)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('task.lease_owner', 'worker-timer-resume-contract')
            ->assertJsonPath('task.workflow_wait_kind', 'timer')
            ->assertJsonPath('task.open_wait_id', "timer:{$timerId}")
            ->assertJsonPath('task.resume_source_kind', 'timer')
            ->assertJsonPath('task.resume_source_id', $timerId)
            ->assertJsonPath('task.timer_id', $timerId)
            ->assertJsonPath('task.workflow_event_type', 'TimerFired');

        $resumeEvents = collect((array) $resumePoll->json('task.history_events'));
        $timerFired = $resumeEvents->firstWhere('event_type', 'TimerFired');

        $this->assertIsArray($timerFired);
        $this->assertSame($timerId, $timerFired['payload']['timer_id'] ?? null);
        $this->assertSame(
            $timerFired['payload']['sequence'] ?? null,
            $resumePoll->json('task.workflow_sequence'),
        );
    }

    public function test_condition_timeout_resume_task_exposes_worker_protocol_context(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-18 12:00:00'));

        try {
            $this->configureWorkflowTypes([
                'tests.condition-timeout-workflow' => ConditionTimeoutWorkflow::class,
            ]);

            $start = $this->postJson('/api/workflows', [
                'workflow_id' => 'wf-worker-condition-timeout-contract',
                'workflow_type' => 'tests.condition-timeout-workflow',
                'task_queue' => 'contract-queue',
            ], $this->apiHeaders());

            $start->assertCreated();

            $workflowId = (string) $start->json('workflow_id');
            $runId = (string) $start->json('run_id');

            $this->runReadyWorkflowTask($runId);

            /** @var WorkflowTask $timerTask */
            $timerTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', 'timer')
                ->where('status', 'ready')
                ->firstOrFail();

            $timerPayload = is_array($timerTask->payload) ? $timerTask->payload : [];
            $timerId = $timerPayload['timer_id'] ?? null;
            $conditionWaitId = $timerPayload['condition_wait_id'] ?? null;
            $conditionKey = $timerPayload['condition_key'] ?? null;
            $conditionDefinitionFingerprint = $timerPayload['condition_definition_fingerprint'] ?? null;

            $this->assertIsString($timerId);
            $this->assertIsString($conditionWaitId);
            $this->assertSame('approval.ready', $conditionKey);
            $this->assertIsString($conditionDefinitionFingerprint);

            $timerScheduled = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', 'TimerScheduled')
                ->where('payload->timer_id', $timerId)
                ->firstOrFail();

            $this->assertSame('condition_timeout', $timerScheduled->payload['timer_kind'] ?? null);
            $this->assertSame($conditionWaitId, $timerScheduled->payload['condition_wait_id'] ?? null);

            Carbon::setTestNow(Carbon::parse('2026-04-18 12:00:05'));

            $this->app->call([new RunTimerTask((string) $timerTask->id), 'handle']);

            $this->registerWorker(
                workerId: 'worker-condition-timeout-contract',
                taskQueue: 'contract-queue',
                supportedWorkflowTypes: ['tests.condition-timeout-workflow'],
            );

            $resumePoll = $this->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-condition-timeout-contract',
                'task_queue' => 'contract-queue',
            ], $this->workerProtocolHeaders());

            $this->assertWorkerProtocolSuccess($resumePoll)
                ->assertJsonPath('task.workflow_id', $workflowId)
                ->assertJsonPath('task.run_id', $runId)
                ->assertJsonPath('task.workflow_type', 'tests.condition-timeout-workflow')
                ->assertJsonPath('task.lease_owner', 'worker-condition-timeout-contract')
                ->assertJsonPath('task.workflow_wait_kind', 'condition')
                ->assertJsonPath('task.open_wait_id', $conditionWaitId)
                ->assertJsonPath('task.resume_source_kind', 'timer')
                ->assertJsonPath('task.resume_source_id', $timerId)
                ->assertJsonPath('task.timer_id', $timerId)
                ->assertJsonPath('task.condition_wait_id', $conditionWaitId)
                ->assertJsonPath('task.condition_key', 'approval.ready')
                ->assertJsonPath('task.condition_definition_fingerprint', $conditionDefinitionFingerprint)
                ->assertJsonPath('task.workflow_event_type', 'TimerFired');

            $resumeEvents = collect((array) $resumePoll->json('task.history_events'));
            $timerFired = $resumeEvents->firstWhere('event_type', 'TimerFired');

            $this->assertIsArray($timerFired);
            $this->assertSame($timerId, $timerFired['payload']['timer_id'] ?? null);
            $this->assertSame('condition_timeout', $timerFired['payload']['timer_kind'] ?? null);
            $this->assertSame($conditionWaitId, $timerFired['payload']['condition_wait_id'] ?? null);
            $this->assertSame('approval.ready', $timerFired['payload']['condition_key'] ?? null);
            $this->assertSame(
                $conditionDefinitionFingerprint,
                $timerFired['payload']['condition_definition_fingerprint'] ?? null,
            );
            $this->assertSame(
                $timerFired['payload']['sequence'] ?? null,
                $resumePoll->json('task.workflow_sequence'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_signal_resume_completion_records_condition_wait_satisfied_event(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-condition-signal-contract',
            'workflow_type' => 'tests.interactive-command-workflow',
            'task_queue' => 'contract-queue',
        ], $this->apiHeaders());

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->registerWorker(
            workerId: 'worker-condition-signal-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.interactive-command-workflow'],
        );

        $openPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-condition-signal-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($openPoll)
            ->assertJsonPath('task.workflow_id', 'wf-worker-condition-signal-contract')
            ->assertJsonPath('task.run_id', $runId);

        $openTaskId = (string) $openPoll->json('task.task_id');
        $openAttempt = (int) $openPoll->json('task.workflow_task_attempt');
        $openLeaseOwner = (string) $openPoll->json('task.lease_owner');
        $conditionWaitOccurrenceId = 'condition-occurrence-worker-contract-1';

        $open = $this->postJson("/api/worker/workflow-tasks/{$openTaskId}/complete", [
            'lease_owner' => $openLeaseOwner,
            'workflow_task_attempt' => $openAttempt,
            'commands' => [
                [
                    'type' => 'open_condition_wait',
                    'condition_wait_occurrence_id' => $conditionWaitOccurrenceId,
                    'condition_key' => 'approval.ready',
                    'condition_definition_fingerprint' => 'condition-fp-1',
                    'timeout_seconds' => 60,
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($open)
            ->assertJsonPath('run_status', 'waiting');

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'ConditionWaitOpened')
            ->firstOrFail();
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();
        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'timer')
            ->firstOrFail();
        $conditionWaitId = $opened->payload['condition_wait_id'] ?? null;

        $this->assertIsString($conditionWaitId);
        $this->assertSame(
            $conditionWaitOccurrenceId,
            $opened->payload['condition_wait_occurrence_id'] ?? null,
        );
        $this->assertSame('pending', $timer->status->value);
        $this->assertSame('ready', $timerTask->status->value);

        $signal = $this->postJson('/api/workflows/wf-worker-condition-signal-contract/signal/advance', [
            'input' => ['Ada'],
            'request_id' => 'condition-signal-1',
        ], $this->apiHeaders());

        $signal->assertAccepted()
            ->assertJsonPath('signal_name', 'advance')
            ->assertJsonPath('outcome', 'signal_received');

        $signalRecord = WorkflowSignal::query()
            ->where('workflow_run_id', $runId)
            ->where('signal_name', 'advance')
            ->sole();
        $signalId = (string) $signalRecord->id;

        $resumePoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-condition-signal-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($resumePoll)
            ->assertJsonPath('task.workflow_wait_kind', 'signal')
            ->assertJsonPath('task.resume_source_kind', 'workflow_signal')
            ->assertJsonPath('task.workflow_signal_id', $signalId)
            ->assertJsonPath('task.signal_name', 'advance');

        $resumeOpened = collect((array) $resumePoll->json('task.history_events'))
            ->firstWhere('event_type', 'ConditionWaitOpened');
        $this->assertIsArray($resumeOpened);
        $this->assertSame(
            $conditionWaitOccurrenceId,
            $resumeOpened['payload']['condition_wait_occurrence_id'] ?? null,
        );

        $resumeTaskId = (string) $resumePoll->json('task.task_id');
        $resumeAttempt = (int) $resumePoll->json('task.workflow_task_attempt');
        $resumeLeaseOwner = (string) $resumePoll->json('task.lease_owner');

        $complete = $this->postJson("/api/worker/workflow-tasks/{$resumeTaskId}/complete", [
            'lease_owner' => $resumeLeaseOwner,
            'workflow_task_attempt' => $resumeAttempt,
            'commands' => [
                [
                    'type' => 'complete_workflow',
                    'result' => Serializer::serialize(['approved' => true]),
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('run_status', 'completed');

        $satisfied = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'ConditionWaitSatisfied')
            ->firstOrFail();

        $this->assertSame($conditionWaitId, $satisfied->payload['condition_wait_id'] ?? null);
        $this->assertSame(
            $conditionWaitOccurrenceId,
            $satisfied->payload['condition_wait_occurrence_id'] ?? null,
        );
        $this->assertSame('approval.ready', $satisfied->payload['condition_key'] ?? null);
        $this->assertSame('condition-fp-1', $satisfied->payload['condition_definition_fingerprint'] ?? null);
        $this->assertSame($timer->id, $satisfied->payload['timer_id'] ?? null);
        $this->assertSame(60, $satisfied->payload['timeout_seconds'] ?? null);
        $this->assertSame($signalId, $satisfied->payload['workflow_signal_id'] ?? null);
        $this->assertSame('advance', $satisfied->payload['signal_name'] ?? null);

        $timer->refresh();
        $timerTask->refresh();

        $this->assertSame('cancelled', $timer->status->value);
        $this->assertSame('cancelled', $timerTask->status->value);

        $cancelled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'TimerCancelled')
            ->where('payload->timer_id', $timer->id)
            ->firstOrFail();

        $this->assertSame('condition_timeout', $cancelled->payload['timer_kind'] ?? null);
        $this->assertSame($conditionWaitId, $cancelled->payload['condition_wait_id'] ?? null);
        $this->assertSame(
            $conditionWaitOccurrenceId,
            $cancelled->payload['condition_wait_occurrence_id'] ?? null,
        );
    }

    public function test_memo_update_is_visible_and_idempotent_while_the_run_waits(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-memo-waiting',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'memo-waiting-queue',
            'input' => ['Ada'],
            'memo' => [
                'obsolete' => 'remove-me',
                'tenant' => 'acme',
            ],
        ], $this->apiHeaders());

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $this->registerWorker(
            workerId: 'worker-memo-waiting',
            taskQueue: 'memo-waiting-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-memo-waiting',
            'task_queue' => 'memo-waiting-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.run_id', $runId);

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $completion = [
            'lease_owner' => 'worker-memo-waiting',
            'workflow_task_attempt' => $attempt,
            'commands' => [[
                'type' => 'upsert_memo',
                'entries' => MemoPayload::mapEnvelope([
                    'obsolete' => null,
                    'stage' => 'waiting',
                ]),
            ]],
        ];

        $complete = $this->postJson(
            "/api/worker/workflow-tasks/{$taskId}/complete",
            $completion,
            $this->workerProtocolHeaders(),
        );

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'waiting');

        $show = $this->getJson(
            "/api/workflows/wf-worker-memo-waiting/runs/{$runId}",
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );
        $show->assertOk()
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('memo', [
                'stage' => 'waiting',
                'tenant' => 'acme',
            ]);

        $duplicate = $this->postJson(
            "/api/worker/workflow-tasks/{$taskId}/complete",
            $completion,
            $this->workerProtocolHeaders(),
        );
        $duplicate->assertStatus(409)
            ->assertJsonPath('reason', 'task_not_leased');

        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'MemoUpserted')
            ->count());
    }

    public function test_marker_memo_and_search_attribute_commands_use_worker_protocol_contract(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-marker-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
            'search_attributes' => [
                'obsolete' => 'remove-me',
                'tenant' => 'acme',
            ],
            'memo' => [
                'obsolete' => 'remove-me',
                'tenant' => 'acme',
            ],
        ], $this->apiHeaders());

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $this->registerWorker(
            workerId: 'worker-marker-contract',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-marker-contract',
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('task.lease_owner', 'worker-marker-contract');

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $sideEffectResult = Serializer::serializeWithCodec('avro', ['seed' => 123, 'source' => 'worker']);

        $legacyHeaders = $this->workerProtocolHeaders();
        $legacyHeaders[WorkerProtocol::HEADER] = '1.13';

        $unsupported = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-marker-contract',
            'workflow_task_attempt' => $attempt,
            'commands' => [[
                'type' => 'upsert_memo',
                'entries' => MemoPayload::mapEnvelope(['phase' => 'unsupported']),
            ]],
        ], $legacyHeaders);

        $unsupported->assertStatus(409)
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('reason', 'workflow_memo_updates_unavailable')
            ->assertJsonPath('minimum_protocol_version', '1.14')
            ->assertJsonPath('server_capabilities.workflow_memo_updates.supported', true);

        $invalid = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-marker-contract',
            'workflow_task_attempt' => $attempt,
            'commands' => [[
                'type' => 'upsert_memo',
                'entries' => MemoPayload::mapEnvelope([str_repeat('x', 65) => 'invalid']),
            ]],
        ], $this->workerProtocolHeaders());

        $invalid->assertStatus(422)
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('reason', 'workflow_memo_validation_failed');
        $invalidBody = $invalid->json();
        $this->assertSame(
            'Workflow v2 memo keys must match ^(?!-?[0-9]+$)[A-Za-z0-9_.:-]{1,64}$.',
            $invalidBody['validation_errors']['commands.0.entries'][0] ?? null,
        );

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-marker-contract',
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'record_side_effect',
                    'result' => $sideEffectResult,
                ],
                [
                    'type' => 'upsert_search_attributes',
                    'attributes' => [
                        'obsolete' => null,
                        'phase' => 'worker-contract',
                        'priority' => 3,
                    ],
                ],
                [
                    'type' => 'upsert_memo',
                    'entries' => MemoPayload::mapEnvelope([
                        'obsolete' => null,
                        'phase' => 'worker-contract',
                        'progress' => 3,
                    ]),
                ],
                [
                    'type' => 'record_version_marker',
                    'change_id' => 'worker-contract-marker',
                    'version' => 2,
                    'min_supported' => 1,
                    'max_supported' => 2,
                ],
                [
                    'type' => 'complete_workflow',
                    'result' => Serializer::serializeWithCodec((string) config('workflows.serializer'), [
                        'status' => 'done',
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
            ->assertJsonPath('reason', null)
            ->assertJsonPath('created_task_ids', []);

        $duplicate = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => 'worker-marker-contract',
            'workflow_task_attempt' => $attempt,
            'commands' => [[
                'type' => 'upsert_memo',
                'entries' => MemoPayload::mapEnvelope(['progress' => 3]),
            ]],
        ], $this->workerProtocolHeaders());

        $duplicate->assertStatus(409)
            ->assertJsonPath('reason', 'run_closed');

        $showRun = $this->getJson(
            "/api/workflows/{$workflowId}/runs/{$runId}",
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $showRun->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('memo', [
                'phase' => 'worker-contract',
                'progress' => 3,
                'tenant' => 'acme',
            ])
            ->assertJsonPath('search_attributes', [
                'phase' => 'worker-contract',
                'priority' => 3,
                'tenant' => 'acme',
            ]);

        $history = $this->getJson(
            "/api/workflows/{$workflowId}/runs/{$runId}/history",
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $history->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER);

        $events = collect($history->json('events'))->keyBy('event_type');

        $this->assertSame('avro', $events->get('SideEffectRecorded')['payload']['result']['codec'] ?? null);
        $this->assertSame($sideEffectResult, $events->get('SideEffectRecorded')['payload']['result']['blob'] ?? null);
        $this->assertSame(
            [
                'obsolete' => null,
                'phase' => 'worker-contract',
                'progress' => 3,
            ],
            MemoPayload::decodeEntries($events->get('MemoUpserted')['payload']['entries'] ?? []),
        );
        $this->assertSame(
            [
                'phase' => 'worker-contract',
                'progress' => 3,
                'tenant' => 'acme',
            ],
            MemoPayload::decodeEntries($events->get('MemoUpserted')['payload']['merged'] ?? []),
        );
        $this->assertSame(
            [
                'obsolete' => null,
                'phase' => 'worker-contract',
                'priority' => 3,
            ],
            $events->get('SearchAttributesUpserted')['payload']['attributes'] ?? null,
        );
        $this->assertSame(1, collect($history->json('events'))->where('event_type', 'MemoUpserted')->count());
        $this->assertSame(
            [
                'phase' => 'worker-contract',
                'priority' => 3,
                'tenant' => 'acme',
            ],
            $events->get('SearchAttributesUpserted')['payload']['merged'] ?? null,
        );
        $this->assertSame(
            'worker-contract-marker',
            $events->get('VersionMarkerRecorded')['payload']['change_id'] ?? null,
        );
        $this->assertSame(2, $events->get('VersionMarkerRecorded')['payload']['version'] ?? null);
        $this->assertTrue($events->has('WorkflowCompleted'));
    }

    public function test_workflow_task_history_page_compression_uses_worker_protocol_contract(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-history-page-compression-contract',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->registerWorker(
            workerId: 'worker-history-compression',
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'worker-history-compression',
            'task_queue' => 'contract-queue',
            'history_page_size' => 1,
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', 'wf-history-page-compression-contract')
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.lease_owner', 'worker-history-compression');

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $nextHistoryPageToken = $poll->json('task.next_history_page_token');

        $this->assertIsString($nextHistoryPageToken);

        $recordedAt = now()->toJSON();
        $events = [];

        for ($sequence = 2; $sequence <= 61; $sequence++) {
            $events[] = [
                'id' => "history-contract-{$sequence}",
                'sequence' => $sequence,
                'event_type' => 'MarkerRecorded',
                'payload' => ['sequence' => $sequence],
                'workflow_task_id' => $taskId,
                'workflow_command_id' => null,
                'recorded_at' => $recordedAt,
            ];
        }

        $defaultBridge = app()->make(DefaultWorkflowTaskBridge::class);

        $this->mock(WorkflowTaskBridge::class, function (MockInterface $mock) use ($defaultBridge, $events, $runId, $taskId): void {
            $mock->shouldReceive('status')
                ->andReturnUsing(static fn (string $taskId): array => $defaultBridge->status($taskId));

            $mock->shouldReceive('historyPayloadPaginated')
                ->once()
                ->with($taskId, 1, 100)
                ->andReturn([
                    'task_id' => $taskId,
                    'workflow_run_id' => $runId,
                    'workflow_instance_id' => 'wf-history-page-compression-contract',
                    'namespace' => 'default',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'workflow_class' => ExternalGreetingWorkflow::class,
                    'payload_codec' => (string) config('workflows.serializer'),
                    'arguments' => null,
                    'arguments_envelope' => null,
                    'run_status' => 'waiting',
                    'last_history_sequence' => 89,
                    'sticky_worker_id' => null,
                    'sticky_until' => null,
                    'sticky_replay_mode' => null,
                    'total_history_events' => 61,
                    'history_size_bytes' => 16384,
                    'history_fan_out' => 24,
                    'continue_as_new_recommended' => true,
                    'history_budget_pressure' => 'continue_as_new_recommended',
                    'history_budget_pressure_dimensions' => ['size_bytes', 'fan_out'],
                    'after_sequence' => 1,
                    'page_size' => 100,
                    'has_more' => false,
                    'next_after_sequence' => null,
                    'history_events' => $events,
                ]);
        });

        $history = $this->postJson("/api/worker/workflow-tasks/{$taskId}/history", [
            'lease_owner' => 'worker-history-compression',
            'workflow_task_attempt' => $attempt,
            'next_history_page_token' => $nextHistoryPageToken,
            'history_page_size' => 100,
            'accept_history_encoding' => 'gzip',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($history)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('history_events', [])
            ->assertJsonPath('history_events_encoding', 'gzip')
            ->assertJsonPath('total_history_events', 61)
            ->assertJsonPath('history_size_bytes', 16384)
            ->assertJsonPath('history_fan_out', 24)
            ->assertJsonPath('continue_as_new_recommended', true)
            ->assertJsonPath('history_budget_pressure', 'continue_as_new_recommended')
            ->assertJsonPath('history_budget_pressure_dimensions', ['size_bytes', 'fan_out'])
            ->assertJsonPath('next_history_page_token', null);

        $compressed = base64_decode((string) $history->json('history_events_compressed'), true);

        $this->assertNotFalse($compressed);

        $decompressed = gzdecode($compressed);

        $this->assertNotFalse($decompressed);

        $decoded = json_decode($decompressed, true);

        $this->assertIsArray($decoded);
        $this->assertCount(60, $decoded);
        $this->assertSame(2, $decoded[0]['sequence']);
        $this->assertSame(61, $decoded[59]['sequence']);
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
            'result' => Serializer::serializeWithCodec('avro', 'Hello, Ada!'),
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($complete)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null)
            ->assertJsonStructure(['next_task_id']);
    }

    public function test_activity_task_poll_capability_filters_use_worker_protocol_contract(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(
            ExternalGreetingWorkflow::class,
            'wf-activity-type-filter-contract',
        );
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $this->registerWorker(
            workerId: 'activity-worker-wrong-type',
            taskQueue: 'external-activities',
            supportedActivityTypes: ['tests.other-activity'],
        );

        $wrongTypePoll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'activity-worker-wrong-type',
            'task_queue' => 'external-activities',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($wrongTypePoll)
            ->assertJsonPath('task', null);

        $this->registerWorker(
            workerId: 'activity-worker-matching-type',
            taskQueue: 'external-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $matchingTypePoll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'activity-worker-matching-type',
            'task_queue' => 'external-activities',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($matchingTypePoll)
            ->assertJsonPath('task.workflow_id', 'wf-activity-type-filter-contract')
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.lease_owner', 'activity-worker-matching-type')
            ->assertJsonMissingPath('task.activity_class');
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
            ->assertJsonPath('run_closed_reason', $expectedOutcome)
            ->assertJsonPath('heartbeat_recorded', false);
        $this->assertNotNull($heartbeat->json('run_closed_at'));
    }

    /**
     * @return array<string, array{command: string, expectedOutcome: string, operation: string, expectedReason: string}>
     */
    public static function terminalActivityOutcomeProvider(): array
    {
        return [
            'complete after cancelled run' => [
                'command' => 'cancel',
                'expectedOutcome' => 'cancelled',
                'operation' => 'complete',
                'expectedReason' => 'run_cancelled',
            ],
            'fail after cancelled run' => [
                'command' => 'cancel',
                'expectedOutcome' => 'cancelled',
                'operation' => 'fail',
                'expectedReason' => 'run_cancelled',
            ],
            'complete after terminated run' => [
                'command' => 'terminate',
                'expectedOutcome' => 'terminated',
                'operation' => 'complete',
                'expectedReason' => 'run_terminated',
            ],
            'fail after terminated run' => [
                'command' => 'terminate',
                'expectedOutcome' => 'terminated',
                'operation' => 'fail',
                'expectedReason' => 'run_terminated',
            ],
        ];
    }

    #[DataProvider('terminalActivityOutcomeProvider')]
    public function test_leased_activity_task_outcomes_report_ignored_after_terminal_run(
        string $command,
        string $expectedOutcome,
        string $operation,
        string $expectedReason,
    ): void {
        $workflowId = sprintf('wf-activity-%s-after-%s-contract', $operation, $expectedOutcome);
        $workerId = sprintf('activity-worker-%s-after-%s', $operation, $expectedOutcome);
        $claim = $this->leaseExternalGreetingActivity($workflowId, $workerId);

        $controlPlane = $this->postJson("/api/workflows/{$workflowId}/{$command}", [
            'reason' => "operator {$command}",
        ], $this->apiHeaders());

        $controlPlane->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('outcome', $expectedOutcome);

        $response = $operation === 'complete'
            ? $this->postJson("/api/worker/activity-tasks/{$claim['task_id']}/complete", [
                'activity_attempt_id' => $claim['activity_attempt_id'],
                'lease_owner' => $claim['lease_owner'],
                'result' => Serializer::serializeWithCodec('avro', 'too late'),
            ], $this->workerProtocolHeaders())
            : $this->postJson("/api/worker/activity-tasks/{$claim['task_id']}/fail", [
                'activity_attempt_id' => $claim['activity_attempt_id'],
                'lease_owner' => $claim['lease_owner'],
                'failure' => [
                    'message' => 'too late',
                    'type' => 'ExternalError',
                    'details' => Serializer::serializeWithCodec('avro', ['phase' => 'late']),
                ],
            ], $this->workerProtocolHeaders());

        $this->assertTerminalActivityOutcome(
            $response,
            $expectedReason,
            $expectedOutcome,
            $claim['task_id'],
            $claim['activity_attempt_id'],
            $claim['lease_owner'],
        );
    }

    /**
     * @return array<string, array{command: string, expectedOutcome: string, expectedStopReason: string}>
     */
    public static function terminalWorkflowTaskProvider(): array
    {
        return [
            'cancelled run' => [
                'command' => 'cancel',
                'expectedOutcome' => 'cancelled',
                'expectedStopReason' => 'run_cancelled',
            ],
            'terminated run' => [
                'command' => 'terminate',
                'expectedOutcome' => 'terminated',
                'expectedStopReason' => 'run_terminated',
            ],
        ];
    }

    #[DataProvider('terminalWorkflowTaskProvider')]
    public function test_leased_workflow_task_reports_terminal_run_state(
        string $command,
        string $expectedOutcome,
        string $expectedStopReason,
    ): void {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $workflowId = sprintf('wf-worker-%s-terminal-contract', $command);
        $workerId = sprintf('worker-%s-terminal-lifecycle', $command);
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId,
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'contract-queue',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->registerWorker(
            workerId: $workerId,
            taskQueue: 'contract-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'contract-queue',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.lease_owner', $workerId);

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $controlPlane = $this->postJson("/api/workflows/{$workflowId}/{$command}", [
            'reason' => "operator {$command}",
        ], $this->apiHeaders());

        $controlPlane->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('outcome', $expectedOutcome);

        $history = $this->postJson("/api/worker/workflow-tasks/{$taskId}/history", [
            'lease_owner' => $workerId,
            'workflow_task_attempt' => $attempt,
            'next_history_page_token' => base64_encode('0'),
        ], $this->workerProtocolHeaders());

        $this->assertTerminalWorkflowTaskOutcome(
            $history,
            $expectedOutcome,
            $expectedStopReason,
            $taskId,
            $attempt,
            $workerId,
        )
            ->assertJsonMissingPath('outcome');

        $heartbeat = $this->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
            'lease_owner' => $workerId,
            'workflow_task_attempt' => $attempt,
        ], $this->workerProtocolHeaders());

        $this->assertTerminalWorkflowTaskOutcome(
            $heartbeat,
            $expectedOutcome,
            $expectedStopReason,
            $taskId,
            $attempt,
            $workerId,
        )
            ->assertJsonMissingPath('outcome');

        $complete = $this->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => $workerId,
            'workflow_task_attempt' => $attempt,
            'commands' => [
                [
                    'type' => 'complete_workflow',
                    'result' => Serializer::serializeWithCodec('avro', ['ignored' => true]),
                ],
            ],
        ], $this->workerProtocolHeaders());

        $this->assertTerminalWorkflowTaskOutcome(
            $complete,
            $expectedOutcome,
            $expectedStopReason,
            $taskId,
            $attempt,
            $workerId,
        )
            ->assertJsonMissingPath('outcome');

        $fail = $this->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
            'lease_owner' => $workerId,
            'workflow_task_attempt' => $attempt,
            'failure' => [
                'message' => 'too late',
                'type' => 'ReplayAborted',
            ],
        ], $this->workerProtocolHeaders());

        $this->assertTerminalWorkflowTaskOutcome(
            $fail,
            $expectedOutcome,
            $expectedStopReason,
            $taskId,
            $attempt,
            $workerId,
        )
            ->assertJsonMissingPath('outcome');
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
                'details' => Serializer::serializeWithCodec('avro', ['retry_after' => 30]),
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
            ->assertJsonPath('server_capabilities.activity_retry_policy', true)
            ->assertJsonPath('server_capabilities.activity_timeouts', true)
            ->assertJsonPath('server_capabilities.child_workflow_retry_policy', true)
            ->assertJsonPath('server_capabilities.child_workflow_timeouts', true)
            ->assertJsonPath('server_capabilities.parent_close_policy', true)
            ->assertJsonPath('server_capabilities.query_tasks', true)
            ->assertJsonPath('server_capabilities.query_task_poll_request_idempotency', true)
            ->assertJsonPath('server_capabilities.non_retryable_failures', true)
            ->assertJsonMissingPath('control_plane');

        return $response;
    }

    private function assertTerminalWorkflowTaskOutcome(
        TestResponse $response,
        string $runStatus,
        string $stopReason,
        string $taskId,
        int $attempt,
        string $leaseOwner,
    ): TestResponse {
        $this->assertWorkerProtocolSuccess($response, 409)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('error', 'Workflow run is already closed.')
            ->assertJsonPath('lease_owner', $leaseOwner)
            ->assertJsonPath('run_status', $runStatus)
            ->assertJsonPath('run_closed_reason', $runStatus)
            ->assertJsonPath('task_status', 'cancelled')
            ->assertJsonPath('reason', 'run_closed')
            ->assertJsonPath('stop_reason', $stopReason)
            ->assertJsonPath('cancel_requested', true)
            ->assertJsonPath('can_continue', false);

        $this->assertNotNull($response->json('run_closed_at'));

        return $response;
    }

    /**
     * @return array{task_id: string, activity_attempt_id: string, lease_owner: string}
     */
    private function leaseExternalGreetingActivity(string $workflowId, string $workerId): array
    {
        Queue::fake();

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, $workflowId);
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $this->registerWorker(
            workerId: $workerId,
            taskQueue: 'external-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $poll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => $workerId,
            'task_queue' => 'external-activities',
        ], $this->workerProtocolHeaders());

        $this->assertWorkerProtocolSuccess($poll)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.lease_owner', $workerId);

        return [
            'task_id' => (string) $poll->json('task.task_id'),
            'activity_attempt_id' => (string) $poll->json('task.activity_attempt_id'),
            'lease_owner' => (string) $poll->json('task.lease_owner'),
        ];
    }

    private function assertTerminalActivityOutcome(
        TestResponse $response,
        string $reason,
        string $runStatus,
        string $taskId,
        string $attemptId,
        string $leaseOwner,
    ): void {
        $this->assertWorkerProtocolSuccess($response, 409)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('outcome', 'ignored')
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('reason', $reason)
            ->assertJsonPath('next_task_id', null)
            ->assertJsonPath('error', 'Activity outcome ignored because the workflow run is already closed.')
            ->assertJsonPath('cancel_requested', true)
            ->assertJsonPath('can_continue', false)
            ->assertJsonPath('run_status', $runStatus)
            ->assertJsonPath('run_closed_reason', $runStatus)
            ->assertJsonPath('activity_status', 'cancelled')
            ->assertJsonPath('attempt_status', 'cancelled')
            ->assertJsonPath('task_status', 'cancelled')
            ->assertJsonPath('lease_owner', $leaseOwner)
            ->assertJsonPath('lease_expires_at', null);
        $this->assertNotNull($response->json('run_closed_at'));
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
