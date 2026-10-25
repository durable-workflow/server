<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkerSessionLease;
use App\Support\ActivityTaskPollRequestStore;
use App\Support\ServerPollingCache;
use App\Support\WorkerProtocol;
use App\Support\WorkerSessionRegistry;
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

    public function test_session_conflicts_keep_http_status_separate_from_session_status(): void
    {
        $this->registerWorkerThroughProtocol('session-holder', 'session-queue');
        $this->registerWorkerThroughProtocol('session-contender', 'session-queue');

        $this->postJson('/api/worker/sessions', [
            'worker_id' => 'session-holder',
            'session_id' => 'contested-session',
            'queue' => 'session-queue',
            'lease_seconds' => 120,
        ], $this->workerHeaders())->assertCreated();

        $this->postJson('/api/worker/sessions', [
            'worker_id' => 'session-contender',
            'session_id' => 'contested-session',
            'queue' => 'session-queue',
        ], $this->workerHeaders())
            ->assertStatus(409)
            ->assertJsonPath('reason', 'session_owned_by_another_worker')
            ->assertJsonPath('status', 409)
            ->assertJsonPath('session.status', 'active')
            ->assertJsonPath('session.lease_owner', 'session-holder');

        $this->postJson('/api/worker/sessions/contested-session/heartbeat', [
            'worker_id' => 'session-contender',
        ], $this->workerHeaders())
            ->assertStatus(409)
            ->assertJsonPath('reason', 'session_owner_mismatch')
            ->assertJsonPath('session.status', 'active');

        $this->deleteJson('/api/worker/sessions/contested-session', [
            'worker_id' => 'session-contender',
        ], $this->workerHeaders())
            ->assertStatus(409)
            ->assertJsonPath('reason', 'session_owner_mismatch')
            ->assertJsonPath('session.status', 'active');

        $this->deleteJson('/api/worker/sessions/contested-session', [
            'worker_id' => 'session-holder',
        ], $this->workerHeaders())->assertOk();

        $this->postJson('/api/worker/sessions', [
            'worker_id' => 'session-contender',
            'session_id' => 'contested-session',
            'queue' => 'session-queue',
        ], $this->workerHeaders())
            ->assertStatus(409)
            ->assertJsonPath('reason', 'session_closed')
            ->assertJsonPath('status', 409)
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

        $otherToken = $this->registerWorkerThroughProtocol(
            workerId: 'gpu-heartbeat-renewal-other-worker',
            taskQueue: 'gpu-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
            capabilities: ['gpu:nvidia-l4'],
        );

        // Hand the session to the other worker's current registration.
        WorkerSessionLease::query()
            ->where('namespace', 'default')
            ->where('session_id', 'gpu-heartbeat-renewal')
            ->update([
                'lease_owner' => 'gpu-heartbeat-renewal-other-worker',
                'lease_owner_incarnation' => hash('sha256', (string) $otherToken),
            ]);

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

    public function test_reacquiring_an_expired_lease_preserves_the_absolute_session_ttl(): void
    {
        $this->registerWorkerThroughProtocol(workerId: 'holder-first', taskQueue: 'session-ttl');
        $this->registerWorkerThroughProtocol(workerId: 'holder-next', taskQueue: 'session-ttl');
        $options = [
            'session_id' => 'absolute-ttl',
            'queue' => 'session-ttl',
            'lease_seconds' => 5,
            'ttl_seconds' => 30,
        ];
        $created = $this->postJson('/api/worker/sessions', [
            ...$options,
            'worker_id' => 'holder-first',
        ], $this->workerHeaders())->assertCreated();
        $deadline = $created->json('session.ttl_expires_at');

        $this->travel(6)->seconds();
        $this->postJson('/api/worker/sessions', [
            ...$options,
            'worker_id' => 'holder-next',
        ], $this->workerHeaders())->assertOk()
            ->assertJsonPath('outcome', 'reacquired')
            ->assertJsonPath('session.ttl_expires_at', $deadline);
        $this->travel(25)->seconds();
        $this->postJson('/api/worker/sessions', [
            ...$options,
            'worker_id' => 'holder-next',
        ], $this->workerHeaders())->assertConflict()
            ->assertJsonPath('reason', 'session_reacquire_disallowed')
            ->assertJsonPath('session.failure_reason', 'ttl_expired');
    }

    public function test_absolute_ttl_remains_terminal_after_a_lease_was_already_marked_expired(): void
    {
        $this->registerWorkerThroughProtocol(workerId: 'holder-expired', taskQueue: 'session-ttl');
        $options = [
            'session_id' => 'already-expired-lease',
            'queue' => 'session-ttl',
            'worker_id' => 'holder-expired',
            'lease_seconds' => 5,
            'ttl_seconds' => 30,
        ];
        $this->postJson('/api/worker/sessions', $options, $this->workerHeaders())->assertCreated();
        $this->travel(6)->seconds();
        $this->getJson('/api/worker-sessions/already-expired-lease', $this->apiHeaders())->assertOk()
            ->assertJsonPath('session.failure_reason', 'lease_expired');
        $this->travel(25)->seconds();
        $this->getJson('/api/worker-sessions/already-expired-lease', $this->apiHeaders())->assertOk()
            ->assertJsonPath('session.failure_reason', 'ttl_expired');
        $this->postJson('/api/worker/sessions', $options, $this->workerHeaders())->assertConflict()
            ->assertJsonPath('reason', 'session_reacquire_disallowed');
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  list<string>  $supportedActivityTypes
     * @param  list<string>  $capabilities
     */
    public function test_session_numbers_have_portable_maxima_on_every_path(): void
    {
        Queue::fake();
        $this->registerWorkerThroughProtocol(
            workerId: 'bounded-worker',
            taskQueue: 'gpu-activities',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
            capabilities: ['gpu:nvidia-l4'],
        );
        $maxima = [
            'lease_seconds' => WorkerSessionRegistry::MAX_LEASE_SECONDS,
            'ttl_seconds' => WorkerSessionRegistry::MAX_TTL_SECONDS,
            'max_concurrent_activities' => WorkerSessionRegistry::MAX_CONCURRENT_ACTIVITIES,
        ];
        $this->assertSame([86400, 2592000, 1000], array_values($maxima));

        foreach ($maxima as $field => $maximum) {
            foreach ([$maximum + 1, PHP_INT_MAX] as $value) {
                $this->postJson('/api/worker/sessions', [
                    'worker_id' => 'bounded-worker', 'session_id' => "too-large-{$field}", $field => $value,
                ], $this->workerHeaders())
                    ->assertStatus(422)
                    ->assertJsonPath("errors.{$field}.0", sprintf(
                        'The %s field must not be greater than %d.', str_replace('_', ' ', $field), $maximum,
                    ));
            }
        }
        $this->assertSame(0, WorkerSessionLease::query()->count(), 'refused creates record nothing');

        $this->postJson('/api/worker/sessions', ['worker_id' => 'bounded-worker', 'session_id' => 'at-maxima', ...$maxima],
            $this->workerHeaders())
            ->assertCreated()
            ->assertJsonPath('session.lease_seconds', 86400)
            ->assertJsonPath('session.ttl_seconds', 2592000)
            ->assertJsonPath('session.max_concurrent_activities', 1000);
        $leaseExpiresAt = WorkerSessionLease::query()->where('session_id', 'at-maxima')->value('lease_expires_at');

        foreach ([86401, PHP_INT_MAX] as $value) {
            $this->postJson('/api/worker/sessions/at-maxima/heartbeat', ['worker_id' => 'bounded-worker', 'lease_seconds' => $value],
                $this->workerHeaders())
                ->assertStatus(422)
                ->assertJsonPath('errors.lease_seconds.0', 'The lease seconds field must not be greater than 86400.');
        }
        $this->assertEquals($leaseExpiresAt, WorkerSessionLease::query()->where('session_id', 'at-maxima')->value('lease_expires_at'),
            'a refused heartbeat renews nothing');
        $this->postJson('/api/worker/sessions/at-maxima/heartbeat', ['worker_id' => 'bounded-worker', 'lease_seconds' => 86400],
            $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('outcome', 'heartbeat_recorded');

        $this->configureWorkflowTypes(['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class]);
        $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-session-maxima', 'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'gpu-activities', 'input' => ['Ada'],
        ], $this->apiHeaders())->assertCreated();
        $poll = $this->postJson('/api/worker/workflow-tasks/poll', ['worker_id' => 'bounded-worker', 'task_queue' => 'gpu-activities'],
            $this->workerHeaders());
        $poll->assertOk();
        foreach ($maxima as $field => $maximum) {
            $errors = $this->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $poll->json('task.task_id')), [
                'lease_owner' => $poll->json('task.lease_owner'),
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'schedule_activity',
                    'activity_type' => 'tests.external-greeting-activity',
                    'arguments' => Serializer::serializeWithCodec((string) config('workflows.serializer'), ['Ada']),
                    'worker_session' => ['session_id' => 'command-maxima', $field => PHP_INT_MAX],
                ]],
            ], $this->workerHeaders())
                ->assertStatus(422)
                ->json('errors');
            $this->assertSame(
                sprintf('The commands.0.worker_session.%s field must not be greater than %d.', $field, $maximum),
                $errors["commands.0.worker_session.{$field}"][0] ?? null,
                json_encode($errors),
            );
        }
        $this->assertSame(0, ActivityExecution::query()->count(), 'refused commands schedule nothing');
    }

    public function test_a_same_id_replacement_reacquires_the_session_and_fences_the_old_process(): void
    {
        // The worker declares that it sends its registration token on every
        // explicit session request, so the server requires it.
        $register = fn (): ?string => $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker',
            taskQueue: 'gpu-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
            capabilities: ['gpu:nvidia-l4', WorkerSessionRegistry::REGISTRATION_TOKEN_CAPABILITY],
        );
        $holder = static fn (): ?string => WorkerSessionLease::query()->where('session_id', 'gpu-state')->value('lease_owner_incarnation');
        $row = static fn (): array => WorkerSessionLease::query()->where('session_id', 'gpu-state')->firstOrFail()->getAttributes();
        $create = fn (?string $token = null, array $options = []) => $this->postJson('/api/worker/sessions', array_filter([
            'worker_id' => 'gpu-worker', 'session_id' => 'gpu-state', 'requirements' => ['gpu:nvidia-l4'], 'registration_token' => $token,
            ...$options,
        ]), $this->workerHeaders());
        $heartbeat = fn (?string $token = null) => $this->postJson('/api/worker/sessions/gpu-state/heartbeat',
            array_filter(['worker_id' => 'gpu-worker', 'registration_token' => $token, 'lease_seconds' => 900]), $this->workerHeaders());
        $close = fn (?string $token = null) => $this->deleteJson('/api/worker/sessions/gpu-state',
            array_filter(['worker_id' => 'gpu-worker', 'registration_token' => $token]), $this->workerHeaders());

        $first = $register();
        $this->assertIsString($first);
        $create($first)->assertCreated()->assertJsonPath('outcome', 'created');
        $this->assertSame(hash('sha256', $first), $holder(), 'the session keeps a digest, never the token');

        // A worker heartbeat keeps the registration, so the holder reuses it.
        $this->postJson('/api/worker/heartbeat', ['worker_id' => 'gpu-worker'], $this->workerHeaders())->assertOk();
        $create($first)->assertOk()->assertJsonPath('outcome', 'reused');

        // A same-ID re-registration rotates the token: the session is orphaned.
        $second = $register();
        $this->assertIsString($second);
        $this->assertNotSame($first, $second);
        $heartbeat($second)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'session_not_active')
            ->assertJsonPath('session.status', 'orphaned')
            ->assertJsonPath('session.failure_reason', 'worker_registration_replaced');
        $heartbeat($first)->assertStatus(409)->assertJsonPath('reason', 'stale_worker_registration');
        $create($first)->assertStatus(409)->assertJsonPath('reason', 'stale_worker_registration');
        $this->assertSame(hash('sha256', $first), $holder(), 'a stale token changes nothing');

        // The replacement reacquires, never reuses, and becomes the holder.
        $create($second)->assertOk()->assertJsonPath('outcome', 'reacquired')->assertJsonPath('session.status', 'active');
        $this->assertSame(hash('sha256', $second), $holder());
        $heartbeat($second)->assertOk()->assertJsonPath('outcome', 'heartbeat_recorded');

        // The stale process, with its old token or with none, can neither
        // reuse, renew nor close the replacement's session, and changes nothing.
        $before = $row();
        foreach ([$first, null] as $stale) {
            $create($stale, ['lease_seconds' => 5, 'max_concurrent_activities' => 9])
                ->assertStatus(409)->assertJsonPath('reason', 'stale_worker_registration');
            $heartbeat($stale)->assertStatus(409)->assertJsonPath('reason', 'stale_worker_registration');
            $close($stale)->assertStatus(409)->assertJsonPath('reason', 'stale_worker_registration');
            $this->assertSame($before, $row(), 'a refused request changes nothing');
        }
        $close($second)->assertOk()->assertJsonPath('outcome', 'closed');
    }

    public function test_a_deregistered_worker_can_neither_renew_nor_close_its_session(): void
    {
        // Deregistration deletes the registration and leaves the session for
        // holder-loss handling. The former process, with its token or without,
        // is refused and changes nothing: a token worker deregistered through
        // the fenced route, a published worker through the plain one.
        foreach ([true, false] as $sendsToken) {
            $workerId = $sendsToken ? 'gpu-token-worker' : 'gpu-published-worker';
            $sessionId = $workerId.'-state';
            $token = $this->registerWorkerThroughProtocol(
                workerId: $workerId,
                taskQueue: 'gpu-activities',
                supportedActivityTypes: ['tests.external-greeting-activity'],
                capabilities: ['gpu:nvidia-l4', ...($sendsToken ? [WorkerSessionRegistry::REGISTRATION_TOKEN_CAPABILITY] : [])],
            );
            $this->assertIsString($token);
            $this->postJson('/api/worker/sessions', array_filter(['worker_id' => $workerId, 'session_id' => $sessionId,
                'requirements' => ['gpu:nvidia-l4'], 'registration_token' => $sendsToken ? $token : null]), $this->workerHeaders())
                ->assertCreated();
            $row = static fn (): array => WorkerSessionLease::query()->where('session_id', $sessionId)->firstOrFail()->getAttributes();
            $before = $row();
            if ($sendsToken) {
                $this->postJson("/api/worker/registrations/{$workerId}/deregister", ['registration_token' => $token],
                    $this->workerHeaders())->assertOk();
            } else {
                $this->deleteJson("/api/worker/registrations/{$workerId}", [], $this->workerHeaders())->assertOk();
            }
            foreach ([null, $token] as $sent) {
                $this->postJson("/api/worker/sessions/{$sessionId}/heartbeat",
                    array_filter(['worker_id' => $workerId, 'registration_token' => $sent, 'lease_seconds' => 900]), $this->workerHeaders())
                    ->assertStatus(412)->assertJsonPath('reason', 'worker_not_registered')
                    ->assertJsonPath('error', 'Worker must be registered before renewing a worker session.');
                $this->deleteJson("/api/worker/sessions/{$sessionId}",
                    array_filter(['worker_id' => $workerId, 'registration_token' => $sent]), $this->workerHeaders())
                    ->assertStatus(412)->assertJsonPath('reason', 'worker_not_registered')
                    ->assertJsonPath('error', 'Worker must be registered before closing a worker session.');
                $this->assertSame($before, $row(), 'a refused request changes nothing');
            }
        }
    }

    public function test_a_superseded_registration_can_neither_create_renew_nor_close_its_session(): void
    {
        // Plain deregistration commits the registration as superseded before it
        // deletes the row, and lease recovery supersedes a stale holder. Until
        // the worker registers again, its session requests are refused.
        foreach ([true, false] as $sendsToken) {
            $workerId = $sendsToken ? 'gpu-token-worker' : 'gpu-published-worker';
            $sessionId = $workerId.'-state';
            $token = $this->registerWorkerThroughProtocol(
                workerId: $workerId,
                taskQueue: 'gpu-activities',
                supportedActivityTypes: ['tests.external-greeting-activity'],
                capabilities: ['gpu:nvidia-l4', ...($sendsToken ? [WorkerSessionRegistry::REGISTRATION_TOKEN_CAPABILITY] : [])],
            );
            $sent = $sendsToken ? $token : null;
            $this->postJson('/api/worker/sessions', array_filter(['worker_id' => $workerId, 'session_id' => $sessionId,
                'requirements' => ['gpu:nvidia-l4'], 'registration_token' => $sent]), $this->workerHeaders())->assertCreated();
            WorkerRegistration::query()->where('worker_id', $workerId)->update(['status' => WorkerRegistration::STATUS_SUPERSEDED]);
            $row = static fn (): array => WorkerSessionLease::query()->where('session_id', $sessionId)->firstOrFail()->getAttributes();
            $before = $row();
            foreach ([
                $this->postJson('/api/worker/sessions', array_filter(['worker_id' => $workerId, 'session_id' => $sessionId,
                    'requirements' => ['gpu:nvidia-l4'], 'registration_token' => $sent, 'lease_seconds' => 5]), $this->workerHeaders()),
                $this->postJson("/api/worker/sessions/{$sessionId}/heartbeat",
                    array_filter(['worker_id' => $workerId, 'registration_token' => $sent, 'lease_seconds' => 900]), $this->workerHeaders()),
                $this->deleteJson("/api/worker/sessions/{$sessionId}",
                    array_filter(['worker_id' => $workerId, 'registration_token' => $sent]), $this->workerHeaders()),
            ] as $refused) {
                $refused->assertStatus(409)->assertJsonPath('reason', 'worker_registration_superseded')
                    ->assertJsonPath('remediation', 'Restart and register the worker with a new worker_id.');
            }
            $this->assertSame($before, $row(), 'a refused request changes nothing');
        }
    }

    public function test_without_the_token_capability_a_tokenless_request_acts_for_the_current_registration(): void
    {
        // Published workers do not send a registration token. For them the
        // published contract holds: a request without one acts for the worker
        // ID's current registration, while a token that is sent must be current.
        $register = fn (): ?string => $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker',
            taskQueue: 'gpu-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
            capabilities: ['gpu:nvidia-l4'],
        );
        $create = fn (?string $token = null) => $this->postJson('/api/worker/sessions', array_filter([
            'worker_id' => 'gpu-worker', 'session_id' => 'gpu-legacy', 'requirements' => ['gpu:nvidia-l4'], 'registration_token' => $token,
        ]), $this->workerHeaders());
        $heartbeat = fn (?string $token = null) => $this->postJson('/api/worker/sessions/gpu-legacy/heartbeat',
            array_filter(['worker_id' => 'gpu-worker', 'registration_token' => $token]), $this->workerHeaders());

        $first = $register();
        $create()->assertCreated()->assertJsonPath('outcome', 'created');
        $second = $register();
        $heartbeat()->assertStatus(409)->assertJsonPath('session.failure_reason', 'worker_registration_replaced');
        $create()->assertOk()->assertJsonPath('outcome', 'reacquired');
        $this->assertSame(hash('sha256', (string) $second),
            WorkerSessionLease::query()->where('session_id', 'gpu-legacy')->value('lease_owner_incarnation'));
        $heartbeat()->assertOk()->assertJsonPath('outcome', 'heartbeat_recorded');
        $heartbeat($first)->assertStatus(409)->assertJsonPath('reason', 'stale_worker_registration');
        $this->deleteJson('/api/worker/sessions/gpu-legacy', ['worker_id' => 'gpu-worker'], $this->workerHeaders())
            ->assertOk()->assertJsonPath('outcome', 'closed');
    }

    public function test_a_same_id_replacement_reacquires_a_session_through_an_activity_claim(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes(['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class]);
        $register = fn (): ?string => $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker',
            taskQueue: 'gpu-activities',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
            supportedActivityTypes: ['tests.external-greeting-activity'],
            capabilities: ['gpu:nvidia-l4'],
        );
        $first = $register();
        $this->postJson('/api/worker/sessions', ['worker_id' => 'gpu-worker', 'session_id' => 'gpu-claim', 'requirements' => ['gpu:nvidia-l4']],
            $this->workerHeaders())->assertCreated();
        $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-session-replacement', 'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'gpu-activities', 'input' => ['Ada'],
        ], $this->apiHeaders())->assertCreated();
        $poll = $this->postJson('/api/worker/workflow-tasks/poll', ['worker_id' => 'gpu-worker', 'task_queue' => 'gpu-activities'],
            $this->workerHeaders());
        $this->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $poll->json('task.task_id')), [
            'lease_owner' => $poll->json('task.lease_owner'),
            'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
            'commands' => [[
                'type' => 'schedule_activity',
                'activity_type' => 'tests.external-greeting-activity',
                'arguments' => Serializer::serializeWithCodec((string) config('workflows.serializer'), ['Ada']),
                'worker_session' => ['session_id' => 'gpu-claim', 'requirements' => ['gpu:nvidia-l4']],
            ]],
        ], $this->workerHeaders())->assertOk();

        $second = $register();
        $this->assertNotSame($first, $second);
        $this->getJson('/api/worker-sessions/gpu-claim', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('session.status', 'orphaned')
            ->assertJsonPath('session.failure_reason', 'worker_registration_replaced');
        $this->postJson('/api/worker/activity-tasks/poll', ['worker_id' => 'gpu-worker', 'task_queue' => 'gpu-activities'],
            $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.worker_session.session_id', 'gpu-claim')
            ->assertJsonPath('task.worker_session.lease_owner', 'gpu-worker');
        $this->assertSame(hash('sha256', (string) $second),
            WorkerSessionLease::query()->where('session_id', 'gpu-claim')->value('lease_owner_incarnation'));
        // Published PHP keeps the old failure reason on a reacquired session
        // (Server #737), so only the status is asserted here.
        $this->getJson('/api/worker-sessions/gpu-claim', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('session.status', 'active');
    }

    private function registerWorkerThroughProtocol(
        string $workerId,
        string $taskQueue,
        array $supportedWorkflowTypes = [],
        array $supportedActivityTypes = [],
        array $capabilities = [],
        int $maxConcurrentWorkerSessions = 10,
        ?string $protocolVersion = null,
    ): ?string {
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

        return $this->postJson('/api/worker/register', $registration, $protocolVersion === null
            ? $this->workerHeaders()
            : $this->workerProtocolHeaders($protocolVersion))
            ->assertCreated()
            ->json('registration_token');
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
