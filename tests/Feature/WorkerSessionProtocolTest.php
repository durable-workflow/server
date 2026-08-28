<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkerSessionLease;
use App\Support\ActivityTaskPollRequestStore;
use App\Support\ServerPollingCache;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ActivityTaskBridge as ActivityTaskBridgeContract;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowTask;

class WorkerSessionProtocolTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'server.polling.timeout' => 0,
            'server.workers.stale_after_seconds' => 60,
        ]);

        $this->createNamespace('default');
    }

    public function test_worker_protocol_manages_session_lifecycle_and_visibility(): void
    {
        $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker-1',
            taskQueue: 'gpu-activities',
            capabilities: ['gpu:nvidia-l4'],
            maxConcurrentWorkerSessions: 2,
        );

        $create = $this->postJson('/api/worker/sessions', [
            'worker_id' => 'gpu-worker-1',
            'session_id' => 'gpu-render',
            'queue' => 'gpu-activities',
            'requirements' => ['gpu:nvidia-l4'],
            'lease_seconds' => 120,
            'ttl_seconds' => 600,
            'max_concurrent_activities' => 1,
        ], $this->workerHeaders());

        $create->assertCreated()
            ->assertJsonPath('admitted', true)
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('session.session_id', 'gpu-render')
            ->assertJsonPath('session.lease_owner', 'gpu-worker-1')
            ->assertJsonPath('session.lease_seconds', 120)
            ->assertJsonPath('session.ttl_seconds', 600)
            ->assertJsonPath('session.max_concurrent_activities', 1)
            ->assertJsonPath('server_capabilities.worker_session_verbs', ['create', 'heartbeat', 'close']);

        $heartbeat = $this->postJson('/api/worker/sessions/gpu-render/heartbeat', [
            'worker_id' => 'gpu-worker-1',
            'lease_seconds' => 180,
        ], $this->workerHeaders());

        $heartbeat->assertOk()
            ->assertJsonPath('admitted', true)
            ->assertJsonPath('outcome', 'heartbeat_recorded')
            ->assertJsonPath('session.session_id', 'gpu-render');

        $visibility = $this->getJson('/api/worker-sessions', $this->apiHeaders());

        $visibility->assertOk()
            ->assertJsonPath('metrics.active', 1)
            ->assertJsonPath('sessions.0.session_id', 'gpu-render')
            ->assertJsonPath('sessions.0.status', 'active');

        $close = $this->deleteJson('/api/worker/sessions/gpu-render', [
            'worker_id' => 'gpu-worker-1',
        ], $this->workerHeaders());

        $close->assertOk()
            ->assertJsonPath('admitted', true)
            ->assertJsonPath('outcome', 'closed')
            ->assertJsonPath('session.status', 'closed');

        $this->deleteJson('/api/worker/sessions/gpu-render', [
            'worker_id' => 'gpu-worker-1',
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('admitted', true)
            ->assertJsonPath('outcome', 'already_closed')
            ->assertJsonPath('session.status', 'closed');

        $this->getJson('/api/worker-sessions/gpu-render', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('session.status', 'closed');
    }

    public function test_activity_poll_admits_worker_session_only_to_capable_holder(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-session-activity',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'external-workflows',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorkerThroughProtocol(
            workerId: 'workflow-worker',
            taskQueue: 'external-workflows',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $workflowPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'workflow-worker',
            'task_queue' => 'external-workflows',
        ], $this->workerHeaders());

        $workflowPoll->assertOk();

        $scheduleActivity = $this->postJson(
            sprintf('/api/worker/workflow-tasks/%s/complete', $workflowPoll->json('task.task_id')),
            [
                'lease_owner' => $workflowPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $workflowPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec(
                            (string) config('workflows.serializer'),
                            ['Ada'],
                        ),
                        'worker_session' => [
                            'session_id' => 'gpu-render-sequence',
                            'queue' => 'gpu-activities',
                            'requirements' => ['gpu:nvidia-l4'],
                            'lease_seconds' => 120,
                            'ttl_seconds' => 600,
                            'max_concurrent_activities' => 1,
                        ],
                    ],
                ],
            ],
            $this->workerHeaders(),
        );

        $scheduleActivity->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', (string) $start->json('run_id'))
            ->where('activity_type', 'tests.external-greeting-activity')
            ->firstOrFail();

        $this->assertSame('gpu-activities', $execution->queue);
        $this->assertSame('gpu-activities', $execution->activity_options['queue'] ?? null);
        $this->assertSame('gpu-activities', $execution->activity_options['worker_session']['queue'] ?? null);

        $this->registerWorkerThroughProtocol(
            workerId: 'cpu-worker',
            taskQueue: 'gpu-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'cpu-worker',
            'task_queue' => 'gpu-activities',
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('poll_status', 'empty')
            ->assertJsonPath('task', null);

        $legacyProtocol = $this->protocolVersionBefore(WorkerProtocol::workerSessionMinimumProtocolVersion());

        $this->registerWorkerThroughProtocol(
            workerId: 'legacy-gpu-worker',
            taskQueue: 'gpu-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
            capabilities: ['gpu:nvidia-l4'],
            protocolVersion: $legacyProtocol,
        );

        $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'legacy-gpu-worker',
            'task_queue' => 'gpu-activities',
        ], $this->workerProtocolHeaders($legacyProtocol))
            ->assertOk()
            ->assertJsonPath('poll_status', 'empty')
            ->assertJsonPath('task', null);

        $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker',
            taskQueue: 'gpu-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
            capabilities: ['gpu:nvidia-l4'],
        );

        $activityPoll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'gpu-worker',
            'task_queue' => 'gpu-activities',
        ], $this->workerHeaders());

        $activityPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.task_queue', 'gpu-activities')
            ->assertJsonPath('task.worker_session.session_id', 'gpu-render-sequence')
            ->assertJsonPath('task.worker_session.lease_owner', 'gpu-worker');

        $this->getJson('/api/worker-sessions/gpu-render-sequence', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('session.status', 'active')
            ->assertJsonPath('session.lease_owner', 'gpu-worker')
            ->assertJsonPath('session.active_activity_count', 1);

        $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker-2',
            taskQueue: 'gpu-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
            capabilities: ['gpu:nvidia-l4'],
        );

        $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'gpu-worker-2',
            'task_queue' => 'gpu-activities',
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('poll_status', 'empty')
            ->assertJsonPath('task', null);
    }

    public function test_legacy_duplicate_activity_poll_wait_result_does_not_replay_worker_session_task(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-session-mixed-protocol-race',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'external-workflows',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorkerThroughProtocol(
            workerId: 'mixed-protocol-workflow-worker',
            taskQueue: 'external-workflows',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $workflowPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'mixed-protocol-workflow-worker',
            'task_queue' => 'external-workflows',
        ], $this->workerHeaders());

        $workflowPoll->assertOk();

        $this->postJson(
            sprintf('/api/worker/workflow-tasks/%s/complete', $workflowPoll->json('task.task_id')),
            [
                'lease_owner' => $workflowPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $workflowPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec(
                            (string) config('workflows.serializer'),
                            ['Ada'],
                        ),
                        'worker_session' => [
                            'session_id' => 'gpu-mixed-protocol-race',
                            'queue' => 'gpu-activities',
                            'requirements' => ['gpu:nvidia-l4'],
                            'lease_seconds' => 120,
                            'ttl_seconds' => 600,
                            'max_concurrent_activities' => 1,
                        ],
                    ],
                ],
            ],
            $this->workerHeaders(),
        )->assertOk();

        $pollRequestId = 'mixed-protocol-session-poll';

        $store = new class(app(ServerPollingCache::class), $pollRequestId) extends ActivityTaskPollRequestStore
        {
            public bool $forceWaitResult = true;

            public int $recordedSessionResults = 0;

            public int $waitCalls = 0;

            /**
             * @var array<string, mixed>|null
             */
            private ?array $task = null;

            /**
             * @param  array<string, mixed>  $task
             */
            public function observeTask(array $task): void
            {
                $this->task = $task;
            }

            /**
             * Bind this store before the first activity-poll request so Laravel's
             * cached route controller keeps the instrumented poll store.
             */
            public function __construct(
                ServerPollingCache $cache,
                private readonly string $pollRequestId,
            ) {
                parent::__construct($cache);
            }

            public function tryStart(
                string $namespace,
                string $taskQueue,
                ?string $buildId,
                string $leaseOwner,
                string $pollRequestId,
            ): bool {
                if ($pollRequestId === $this->pollRequestId && $this->forceWaitResult) {
                    return false;
                }

                return parent::tryStart($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);
            }

            /**
             * @return array{resolved: bool, task: array<string, mixed>|null, poll_status: string|null}
             */
            public function waitForResult(
                string $namespace,
                string $taskQueue,
                ?string $buildId,
                string $leaseOwner,
                string $pollRequestId,
                ?int $timeoutMilliseconds = null,
            ): array {
                if ($pollRequestId === $this->pollRequestId && $this->forceWaitResult && $this->task !== null) {
                    $this->waitCalls++;

                    return [
                        'resolved' => true,
                        'task' => $this->task,
                        'poll_status' => 'leased',
                    ];
                }

                return parent::waitForResult(
                    $namespace,
                    $taskQueue,
                    $buildId,
                    $leaseOwner,
                    $pollRequestId,
                    $timeoutMilliseconds,
                );
            }

            /**
             * @param  array<string, mixed>|null  $task
             */
            public function rememberResult(
                string $namespace,
                string $taskQueue,
                ?string $buildId,
                string $leaseOwner,
                string $pollRequestId,
                ?array $task,
                string $pollStatus,
            ): void {
                if (
                    $pollRequestId === $this->pollRequestId
                    && is_array($task)
                    && ($task['activity_execution_id'] ?? null) === ($this->task['activity_execution_id'] ?? null)
                ) {
                    $this->recordedSessionResults++;
                }

                parent::rememberResult(
                    $namespace,
                    $taskQueue,
                    $buildId,
                    $leaseOwner,
                    $pollRequestId,
                    $task,
                    $pollStatus,
                );
            }

            protected function pause(int $milliseconds): void
            {
                throw new \RuntimeException('The fake wait result should avoid sleeping.');
            }
        };

        app()->instance(ActivityTaskPollRequestStore::class, $store);

        $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker-mixed-protocol',
            taskQueue: 'gpu-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
            capabilities: ['gpu:nvidia-l4'],
        );

        $currentPoll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'gpu-worker-mixed-protocol',
            'task_queue' => 'gpu-activities',
            'poll_request_id' => 'current-protocol-session-poll',
        ], $this->workerHeaders());

        $currentPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.worker_session.session_id', 'gpu-mixed-protocol-race')
            ->assertJsonPath('task.worker_session.lease_owner', 'gpu-worker-mixed-protocol');

        $task = $currentPoll->json('task');
        $this->assertIsArray($task);

        $store->observeTask($task);

        $legacyProtocol = $this->protocolVersionBefore(WorkerProtocol::workerSessionMinimumProtocolVersion());

        $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'gpu-worker-mixed-protocol',
            'task_queue' => 'gpu-activities',
            'poll_request_id' => $pollRequestId,
        ], $this->workerProtocolHeaders($legacyProtocol))
            ->assertOk()
            ->assertJsonPath('poll_status', 'empty')
            ->assertJsonPath('task', null);

        $this->assertSame(1, $store->waitCalls);
        $this->assertSame(1, $store->recordedSessionResults);

        $store->forceWaitResult = false;

        $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'gpu-worker-mixed-protocol',
            'task_queue' => 'gpu-activities',
            'poll_request_id' => $pollRequestId,
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.task_id', $task['task_id'])
            ->assertJsonPath('task.activity_attempt_id', $task['activity_attempt_id'])
            ->assertJsonPath('task.worker_session.session_id', 'gpu-mixed-protocol-race');
    }

    public function test_activity_poll_does_not_admit_worker_session_when_task_claim_fails(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $workflowId = 'wf-worker-session-lost-claim';

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId,
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'external-workflows',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorkerThroughProtocol(
            workerId: 'workflow-worker-lost-claim',
            taskQueue: 'external-workflows',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $workflowPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'workflow-worker-lost-claim',
            'task_queue' => 'external-workflows',
        ], $this->workerHeaders());

        $workflowPoll->assertOk();

        $this->postJson(
            sprintf('/api/worker/workflow-tasks/%s/complete', $workflowPoll->json('task.task_id')),
            [
                'lease_owner' => $workflowPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $workflowPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec(
                            (string) config('workflows.serializer'),
                            ['Ada'],
                        ),
                        'worker_session' => [
                            'session_id' => 'gpu-lost-claim',
                            'queue' => 'gpu-activities',
                            'requirements' => ['gpu:nvidia-l4'],
                            'lease_seconds' => 120,
                            'ttl_seconds' => 600,
                            'max_concurrent_activities' => 1,
                        ],
                    ],
                ],
            ],
            $this->workerHeaders(),
        )->assertOk();

        $runId = (string) $start->json('run_id');

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'activity')
            ->firstOrFail();

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();

        $recordedAt = now()->toJSON();

        $this->mock(ActivityTaskBridgeContract::class, function (MockInterface $mock) use (
            $execution,
            $recordedAt,
            $runId,
            $task,
            $workflowId,
        ): void {
            $mock->shouldReceive('poll')
                ->once()
                ->with(null, 'gpu-activities', 10, null, 'default', ['tests.external-greeting-activity'])
                ->andReturn([
                    [
                        'task_id' => $task->id,
                        'workflow_run_id' => $runId,
                        'workflow_instance_id' => $workflowId,
                        'activity_execution_id' => $execution->id,
                        'activity_type' => 'tests.external-greeting-activity',
                        'activity_class' => null,
                        'connection' => null,
                        'queue' => 'gpu-activities',
                        'compatibility' => null,
                        'available_at' => $recordedAt,
                    ],
                ]);

            $mock->shouldReceive('claimStatus')
                ->once()
                ->with($task->id, 'gpu-worker-lost-claim')
                ->andReturn([
                    'claimed' => false,
                    'task_id' => $task->id,
                    'workflow_instance_id' => null,
                    'workflow_run_id' => null,
                    'activity_execution_id' => null,
                    'activity_attempt_id' => null,
                    'attempt_number' => null,
                    'activity_type' => null,
                    'activity_class' => null,
                    'idempotency_key' => null,
                    'payload_codec' => null,
                    'arguments' => null,
                    'retry_policy' => null,
                    'connection' => null,
                    'queue' => null,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'reason' => 'task_not_ready',
                    'reason_detail' => 'The task is no longer ready.',
                    'retry_after_seconds' => null,
                    'backend_error' => null,
                    'compatibility_reason' => null,
                ]);
        });

        $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker-lost-claim',
            taskQueue: 'gpu-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
            capabilities: ['gpu:nvidia-l4'],
        );

        $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'gpu-worker-lost-claim',
            'task_queue' => 'gpu-activities',
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('poll_status', 'empty')
            ->assertJsonPath('task', null);

        $this->assertFalse(
            WorkerSessionLease::query()
                ->where('namespace', 'default')
                ->where('session_id', 'gpu-lost-claim')
                ->exists(),
        );
    }

    public function test_activity_heartbeat_reports_worker_session_renewal_failure(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-session-heartbeat-renewal',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'external-workflows',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorkerThroughProtocol(
            workerId: 'workflow-heartbeat-renewal-worker',
            taskQueue: 'external-workflows',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $workflowPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'workflow-heartbeat-renewal-worker',
            'task_queue' => 'external-workflows',
        ], $this->workerHeaders());

        $workflowPoll->assertOk();

        $this->postJson(
            sprintf('/api/worker/workflow-tasks/%s/complete', $workflowPoll->json('task.task_id')),
            [
                'lease_owner' => $workflowPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $workflowPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec(
                            (string) config('workflows.serializer'),
                            ['Ada'],
                        ),
                        'worker_session' => [
                            'session_id' => 'gpu-heartbeat-renewal',
                            'queue' => 'gpu-activities',
                            'requirements' => ['gpu:nvidia-l4'],
                            'lease_seconds' => 120,
                            'ttl_seconds' => 600,
                        ],
                    ],
                ],
            ],
            $this->workerHeaders(),
        )->assertOk();

        $this->registerWorkerThroughProtocol(
            workerId: 'gpu-heartbeat-renewal-worker',
            taskQueue: 'gpu-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
            capabilities: ['gpu:nvidia-l4'],
        );

        $activityPoll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'gpu-heartbeat-renewal-worker',
            'task_queue' => 'gpu-activities',
        ], $this->workerHeaders());

        $activityPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.worker_session.session_id', 'gpu-heartbeat-renewal')
            ->assertJsonPath('task.worker_session.lease_owner', 'gpu-heartbeat-renewal-worker');

        $this->registerWorkerThroughProtocol(
            workerId: 'gpu-heartbeat-renewal-other-worker',
            taskQueue: 'gpu-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
            capabilities: ['gpu:nvidia-l4'],
        );

        WorkerSessionLease::query()
            ->where('namespace', 'default')
            ->where('session_id', 'gpu-heartbeat-renewal')
            ->update(['lease_owner' => 'gpu-heartbeat-renewal-other-worker']);

        $taskId = (string) $activityPoll->json('task.task_id');
        $attemptId = (string) $activityPoll->json('task.activity_attempt_id');

        $heartbeat = $this->postJson("/api/worker/activity-tasks/{$taskId}/heartbeat", [
            'activity_attempt_id' => $attemptId,
            'lease_owner' => 'gpu-heartbeat-renewal-worker',
            'message' => 'renewing session-bound work',
        ], $this->workerHeaders());

        $heartbeat->assertStatus(409)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('lease_owner', 'gpu-heartbeat-renewal-worker')
            ->assertJsonPath('can_continue', false)
            ->assertJsonPath('heartbeat_recorded', false)
            ->assertJsonPath('reason', 'session_owner_mismatch')
            ->assertJsonPath('error', 'Worker session lease is owned by another worker.')
            ->assertJsonPath('worker_session.session_id', 'gpu-heartbeat-renewal')
            ->assertJsonPath('worker_session.status', 'active')
            ->assertJsonPath('worker_session.lease_owner', 'gpu-heartbeat-renewal-other-worker')
            ->assertJsonPath('worker_session_renewal.admitted', false)
            ->assertJsonPath('worker_session_renewal.reason', 'session_owner_mismatch');

        $this->assertNull(ActivityAttempt::query()->find($attemptId)?->last_heartbeat_at);
    }

    public function test_worker_session_activity_commands_are_protocol_fenced_for_mixed_server_rollouts(): void
    {
        Queue::fake();

        $protocolVersion = $this->protocolVersionBefore(WorkerProtocol::workerSessionMinimumProtocolVersion());

        config([
            'server.worker_protocol.version' => $protocolVersion,
        ]);

        $workerHeaders = $this->workerProtocolHeaders($protocolVersion);

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-session-rollout-fence',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'external-workflows',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->postJson('/api/worker/register', [
            'worker_id' => 'rollout-fenced-workflow-worker',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
        ], $workerHeaders)
            ->assertCreated()
            ->assertJsonPath('server_capabilities.worker_sessions.supported', false)
            ->assertJsonPath('server_capabilities.worker_session_verbs', []);

        $workflowPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'rollout-fenced-workflow-worker',
            'task_queue' => 'external-workflows',
        ], $workerHeaders);

        $workflowPoll->assertOk();

        $this->postJson(
            sprintf('/api/worker/workflow-tasks/%s/complete', $workflowPoll->json('task.task_id')),
            [
                'lease_owner' => $workflowPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $workflowPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec(
                            (string) config('workflows.serializer'),
                            ['Ada'],
                        ),
                        'worker_session' => [
                            'session_id' => 'gpu-rollout-fenced',
                            'queue' => 'gpu-activities',
                        ],
                    ],
                ],
            ],
            $workerHeaders,
        )
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected')
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('reason', 'worker_sessions_unavailable')
            ->assertJsonPath('minimum_protocol_version', WorkerProtocol::workerSessionMinimumProtocolVersion());
    }

    public function test_worker_session_lifecycle_endpoints_are_protocol_fenced_for_mixed_server_rollouts(): void
    {
        $protocolVersion = $this->protocolVersionBefore(WorkerProtocol::workerSessionMinimumProtocolVersion());

        config([
            'server.worker_protocol.version' => $protocolVersion,
        ]);

        $workerHeaders = $this->workerProtocolHeaders($protocolVersion);

        $this->postJson('/api/worker/register', [
            'worker_id' => 'rollout-fenced-session-worker',
            'task_queue' => 'gpu-activities',
            'runtime' => 'php',
            'capabilities' => ['gpu:nvidia-l4'],
            'max_concurrent_worker_sessions' => 1,
        ], $workerHeaders)->assertCreated();

        $this->postJson('/api/worker/sessions', [
            'worker_id' => 'rollout-fenced-session-worker',
            'session_id' => 'gpu-rollout-lifecycle',
            'queue' => 'gpu-activities',
            'requirements' => ['gpu:nvidia-l4'],
        ], $workerHeaders)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'worker_sessions_unavailable')
            ->assertJsonPath('server_capabilities.worker_sessions.supported', false)
            ->assertJsonPath('server_capabilities.worker_session_verbs', [])
            ->assertJsonPath('minimum_protocol_version', WorkerProtocol::workerSessionMinimumProtocolVersion());
    }

    public function test_worker_session_lifecycle_endpoints_reject_requests_below_feature_protocol(): void
    {
        $protocolVersion = $this->protocolVersionBefore(WorkerProtocol::workerSessionMinimumProtocolVersion());

        $this->registerWorkerThroughProtocol(
            workerId: 'legacy-session-worker',
            taskQueue: 'gpu-activities',
            capabilities: ['gpu:nvidia-l4'],
            maxConcurrentWorkerSessions: 1,
            protocolVersion: $protocolVersion,
        );

        $this->postJson('/api/worker/sessions', [
            'worker_id' => 'legacy-session-worker',
            'session_id' => 'gpu-legacy-lifecycle',
            'queue' => 'gpu-activities',
            'requirements' => ['gpu:nvidia-l4'],
        ], $this->workerProtocolHeaders($protocolVersion))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'worker_sessions_unavailable')
            ->assertJsonPath('supported_version', WorkerProtocol::VERSION)
            ->assertJsonPath('requested_version', $protocolVersion)
            ->assertJsonPath('minimum_protocol_version', WorkerProtocol::workerSessionMinimumProtocolVersion());
    }

    public function test_stale_session_holder_is_marked_orphaned_and_can_be_reacquired(): void
    {
        $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker-stale',
            taskQueue: 'gpu-activities',
            capabilities: ['gpu:nvidia-l4'],
        );

        $this->postJson('/api/worker/sessions', [
            'worker_id' => 'gpu-worker-stale',
            'session_id' => 'gpu-orphan',
            'queue' => 'gpu-activities',
            'requirements' => ['gpu:nvidia-l4'],
            'lease_seconds' => 120,
            'ttl_seconds' => 600,
        ], $this->workerHeaders())->assertCreated();

        WorkerRegistration::query()
            ->where('worker_id', 'gpu-worker-stale')
            ->update(['last_heartbeat_at' => now()->subMinutes(5)]);

        $this->getJson('/api/worker-sessions/gpu-orphan', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('session.status', 'orphaned')
            ->assertJsonPath('session.failure_reason', 'worker_heartbeat_stale');

        $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker-reacquire',
            taskQueue: 'gpu-activities',
            capabilities: ['gpu:nvidia-l4'],
        );

        $reacquire = $this->postJson('/api/worker/sessions', [
            'worker_id' => 'gpu-worker-reacquire',
            'session_id' => 'gpu-orphan',
            'queue' => 'gpu-activities',
            'requirements' => ['gpu:nvidia-l4'],
            'lease_seconds' => 120,
            'ttl_seconds' => 600,
        ], $this->workerHeaders());

        $reacquire->assertOk()
            ->assertJsonPath('admitted', true)
            ->assertJsonPath('outcome', 'reacquired')
            ->assertJsonPath('session.status', 'active')
            ->assertJsonPath('session.lease_owner', 'gpu-worker-reacquire');
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  list<string>  $supportedActivityTypes
     * @param  list<string>  $capabilities
     */
    private function registerWorkerThroughProtocol(
        string $workerId,
        string $taskQueue,
        array $supportedWorkflowTypes = [],
        array $supportedActivityTypes = [],
        array $capabilities = [],
        int $maxConcurrentWorkerSessions = 10,
        ?string $protocolVersion = null,
    ): void {
        $requestedProtocol = $protocolVersion ?? WorkerProtocol::VERSION;
        $workerSupportsSessions = version_compare(
            $requestedProtocol,
            WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
            '>=',
        );
        if ($workerSupportsSessions) {
            $capabilities = array_values(array_unique([...$capabilities, 'worker_sessions']));
        }

        $registration = [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'runtime' => 'php',
            'supported_workflow_types' => $supportedWorkflowTypes,
            'supported_activity_types' => $supportedActivityTypes,
            'capabilities' => $capabilities,
            'max_concurrent_worker_sessions' => $maxConcurrentWorkerSessions,
        ];
        if ($workerSupportsSessions) {
            $registration['capability_manifest'] = array_replace(
                $this->portableWorkerAffinityRefusalManifest(),
                [
                    'worker_sessions' => [
                        'supported' => true,
                        'minimum_protocol_version' => WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
                        'implementation' => 'worker_session_protocol_test',
                    ],
                ],
            );
        }

        $this->postJson('/api/worker/register', $registration, $protocolVersion === null
            ? $this->workerHeaders()
            : $this->workerProtocolHeaders($protocolVersion))
            ->assertCreated();
    }

    /**
     * @return array<string, string>
     */
    private function workerProtocolHeaders(string $protocolVersion): array
    {
        return [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => $protocolVersion,
        ];
    }

    private function protocolVersionBefore(string $version): string
    {
        $parts = array_map('intval', explode('.', $version));
        $major = max(0, $parts[0] ?? 1);
        $minor = max(0, $parts[1] ?? 0);

        if ($minor > 0) {
            return sprintf('%d.%d', $major, $minor - 1);
        }

        return sprintf('%d.9', max(0, $major - 1));
    }
}
