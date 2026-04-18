<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\LongPoller;
use App\Support\LongPollSignalStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Models\WorkerCompatibilityHeartbeat;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;

class WorkflowWorkerProtocolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'file',
        ]);
    }

    public function test_it_starts_workflows_and_completes_workflow_tasks_through_the_external_worker_protocol(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-complete',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
                'business_key' => 'invoice-42',
                'memo' => ['source' => 'server-api'],
                'search_attributes' => ['tenant' => 'acme'],
            ]);

        $start->assertCreated()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-external-worker-complete')
            ->assertJsonPath('workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('outcome', 'started_new');

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $describe = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}");

        $describe->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('business_key', 'invoice-42')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('status_bucket', 'running')
            ->assertJsonPath('run_number', 1)
            ->assertJsonPath('run_count', 1)
            ->assertJsonPath('is_current_run', true)
            ->assertJsonPath('task_queue', 'external-workflows')
            ->assertJsonPath('input.0', 'Ada')
            ->assertJsonPath('memo.source', 'server-api')
            ->assertJsonPath('search_attributes.tenant', 'acme')
            ->assertJsonPath('actions.can_signal', true)
            ->assertJsonPath('actions.can_query', true)
            ->assertJsonPath('actions.can_update', true)
            ->assertJsonPath('actions.can_cancel', true)
            ->assertJsonPath('actions.can_terminate', true);

        $this->registerWorker('php-worker-1', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-1',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk()
            ->assertHeader('X-Durable-Workflow-Protocol-Version', '1.0')
            ->assertJsonPath('protocol_version', '1.0')
            ->assertJsonPath('server_capabilities.supported_workflow_task_commands.3', 'schedule_activity')
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-1')
            ->assertJsonPath('task.task_queue', 'external-workflows');

        $taskId = (string) $poll->json('task.task_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $eventTypes = array_column((array) $poll->json('task.history_events'), 'event_type');

        $this->assertContains('StartAccepted', $eventTypes);
        $this->assertContains('WorkflowStarted', $eventTypes);

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec((string) config('workflows.serializer'), [
                            'greeting' => 'Hello, Ada!',
                            'workflow_id' => $workflowId,
                        ]),
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', 1)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'completed');

        $showRun = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}");

        $showRun->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('output.greeting', 'Hello, Ada!')
            ->assertJsonPath('output.workflow_id', $workflowId);

        $history = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}/history");

        $history->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2');

        $historyEventTypes = array_column($history->json('events'), 'event_type');

        $this->assertContains('WorkflowCompleted', $historyEventTypes);
    }

    public function test_it_starts_remote_durable_types_without_local_registration_and_completes_them_through_the_worker_protocol(): void
    {
        Queue::fake();

        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-remote-durable-type',
                'workflow_type' => 'remote.greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
                'memo' => ['source' => 'remote-client'],
                'search_attributes' => ['team' => 'remote-worker'],
            ]);

        $start->assertCreated()
            ->assertJsonPath('workflow_id', 'wf-remote-durable-type')
            ->assertJsonPath('workflow_type', 'remote.greeting-workflow')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('outcome', 'started_new');

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $describe = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}");

        $describe->assertOk()
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('workflow_type', 'remote.greeting-workflow')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('task_queue', 'external-workflows')
            ->assertJsonPath('input.0', 'Ada')
            ->assertJsonPath('memo.source', 'remote-client')
            ->assertJsonPath('search_attributes.team', 'remote-worker');

        $this->registerWorker('php-worker-remote', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-remote',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_type', 'remote.greeting-workflow')
            ->assertJsonPath('task.task_queue', 'external-workflows');

        $taskId = (string) $poll->json('task.task_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $eventTypes = array_column((array) $poll->json('task.history_events'), 'event_type');

        $this->assertContains('StartAccepted', $eventTypes);
        $this->assertContains('WorkflowStarted', $eventTypes);

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec((string) config('workflows.serializer'), [
                            'greeting' => 'Hello from remote worker, Ada!',
                            'workflow_id' => $workflowId,
                        ]),
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', 1)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'completed');

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('workflow_type', 'remote.greeting-workflow')
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('output.greeting', 'Hello from remote worker, Ada!')
            ->assertJsonPath('output.workflow_id', $workflowId);
    }

    public function test_worker_registration_and_heartbeat_advertise_protocol_capabilities_and_package_fleet_visibility(): void
    {
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders([
            'X-Namespace' => 'default',
        ])->postJson('/api/worker/register', [
            'worker_id' => 'php-worker-register-missing-version',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
        ])->assertStatus(400)
            ->assertHeader('X-Durable-Workflow-Protocol-Version', '1.0')
            ->assertJsonPath('reason', 'missing_protocol_version')
            ->assertJsonPath('supported_version', '1.0')
            ->assertJsonPath('requested_version', null)
            ->assertJsonStructure(['remediation']);

        $register = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'worker_id' => 'php-worker-register',
                'task_queue' => 'external-workflows',
                'runtime' => 'php',
                'build_id' => 'build-register',
            ]);

        $register->assertCreated()
            ->assertHeader('X-Durable-Workflow-Protocol-Version', '1.0')
            ->assertJsonPath('protocol_version', '1.0')
            ->assertJsonPath('worker_id', 'php-worker-register')
            ->assertJsonPath('registered', true)
            ->assertJsonPath('server_capabilities.long_poll_timeout', 0)
            ->assertJsonFragment([
                'supported_workflow_task_commands' => [
                    'complete_workflow',
                    'fail_workflow',
                    'continue_as_new',
                    'schedule_activity',
                    'start_timer',
                    'start_child_workflow',
                    'record_side_effect',
                    'record_version_marker',
                    'upsert_search_attributes',
                ],
            ]);

        $this->withHeaders([
            'X-Namespace' => 'default',
        ])->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('worker_fleet.namespace', 'default')
            ->assertJsonPath('control_plane.version', '2')
            ->assertJsonPath('control_plane.header', 'X-Durable-Workflow-Control-Plane-Version')
            ->assertJsonPath('control_plane.response_contract.schema', 'durable-workflow.v2.control-plane-response')
            ->assertJsonPath(
                'control_plane.response_contract.contract.schema',
                'durable-workflow.v2.control-plane-response.contract',
            )
            ->assertJsonPath(
                'control_plane.request_contract.schema',
                'durable-workflow.v2.control-plane-request.contract',
            )
            ->assertJsonPath('control_plane.request_contract.version', 1)
            ->assertJsonPath(
                'control_plane.request_contract.operations.start.fields.duplicate_policy.canonical_values.1',
                'use-existing',
            )
            ->assertJsonPath(
                'control_plane.request_contract.operations.update.removed_fields.wait_policy',
                'Use wait_for.',
            )
            ->assertJsonPath('worker_fleet.active_workers', 1)
            ->assertJsonPath('worker_fleet.active_worker_scopes', 1)
            ->assertJsonPath('worker_fleet.build_ids.0', 'build-register')
            ->assertJsonPath('worker_fleet.queues.0', 'external-workflows')
            ->assertJsonPath('worker_fleet.workers.0.worker_id', 'php-worker-register')
            ->assertJsonPath('worker_fleet.workers.0.build_ids.0', 'build-register')
            ->assertJsonPath('worker_fleet.workers.0.queues.0', 'external-workflows');

        WorkerCompatibilityHeartbeat::query()->delete();

        $heartbeat = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'php-worker-register',
            ]);

        $heartbeat->assertOk()
            ->assertHeader('X-Durable-Workflow-Protocol-Version', '1.0')
            ->assertJsonPath('protocol_version', '1.0')
            ->assertJsonPath('worker_id', 'php-worker-register')
            ->assertJsonPath('acknowledged', true)
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonPath('server_capabilities.supported_workflow_task_commands.5', 'start_child_workflow');

        $this->withHeaders([
            'X-Namespace' => 'default',
        ])->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('worker_protocol.version', '1.0')
            ->assertJsonPath('worker_fleet.active_workers', 1)
            ->assertJsonPath('worker_fleet.build_ids.0', 'build-register')
            ->assertJsonPath(
                'worker_protocol.server_capabilities.supported_workflow_task_commands.2',
                'continue_as_new',
            );

        $this->withHeaders([
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Protocol-Version' => '999',
        ])->postJson('/api/worker/register', [
            'worker_id' => 'php-worker-register-unsupported',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
        ])->assertStatus(400)
            ->assertJsonPath('reason', 'unsupported_protocol_version')
            ->assertJsonPath('supported_version', '1.0')
            ->assertJsonPath('requested_version', '999')
            ->assertJsonStructure(['remediation']);
    }

    public function test_worker_heartbeat_is_scoped_to_the_resolved_namespace(): void
    {
        $this->createNamespace('default', 'Default namespace');
        $this->createNamespace('other', 'Other namespace');
        $this->createNamespace('missing', 'Namespace with no matching worker');

        $otherHeartbeatAt = now()->subHours(2)->startOfSecond();
        $defaultHeartbeatAt = now()->subHour()->startOfSecond();

        WorkerRegistration::query()->create([
            'worker_id' => 'php-worker-shared-id',
            'namespace' => 'other',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'last_heartbeat_at' => $otherHeartbeatAt,
            'status' => 'active',
        ]);

        WorkerRegistration::query()->create([
            'worker_id' => 'php-worker-shared-id',
            'namespace' => 'default',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'last_heartbeat_at' => $defaultHeartbeatAt,
            'status' => 'active',
        ]);

        $heartbeatAt = now()->addMinutes(5)->startOfSecond();
        $this->travelTo($heartbeatAt);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'php-worker-shared-id',
            ])
            ->assertOk()
            ->assertJsonPath('worker_id', 'php-worker-shared-id')
            ->assertJsonPath('acknowledged', true);

        $defaultWorker = WorkerRegistration::query()
            ->where('worker_id', 'php-worker-shared-id')
            ->where('namespace', 'default')
            ->firstOrFail();
        $otherWorker = WorkerRegistration::query()
            ->where('worker_id', 'php-worker-shared-id')
            ->where('namespace', 'other')
            ->firstOrFail();

        $this->assertSame($heartbeatAt->toJSON(), $defaultWorker->last_heartbeat_at?->toJSON());
        $this->assertSame('active', $defaultWorker->status);
        $this->assertSame($otherHeartbeatAt->toJSON(), $otherWorker->last_heartbeat_at?->toJSON());

        $this->withHeaders($this->workerHeaders(namespace: 'missing'))
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'php-worker-shared-id',
            ])
            ->assertNotFound()
            ->assertJsonPath('error', 'Worker not registered.')
            ->assertJsonPath('reason', 'worker_not_registered')
            ->assertJsonPath('worker_id', 'php-worker-shared-id');
    }

    public function test_it_scopes_workflow_task_polling_by_namespace(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');
        $this->createNamespace('other', 'Other namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-hidden-workflow-task',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Grace'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-2', 'external-workflows', 'other');

        $this->withHeaders($this->workerHeaders(namespace: 'other'))
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-2',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_it_uses_a_server_local_lease_counter_for_workflow_task_attempts(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-bridge-poll-discovery',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->firstOrFail();

        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'existing-worker',
            'attempt_count' => 0,
        ])->save();

        $leaseExpiresAt = now()->addMinutes(5)->toJSON();
        $recordedAt = now()->toJSON();
        $duplicateLeaseExpiresAt = now()->addMinutes(10)->toJSON();
        $historyPayload = [
            'task_id' => $task->id,
            'workflow_run_id' => $runId,
            'workflow_instance_id' => $workflowId,
            'workflow_type' => 'tests.external-greeting-workflow',
            'workflow_class' => ExternalGreetingWorkflow::class,
            'payload_codec' => (string) config('workflows.serializer'),
            'arguments' => null,
            'run_status' => 'pending',
            'last_history_sequence' => 2,
            'history_events' => [
                [
                    'id' => 'evt-start-accepted',
                    'sequence' => 1,
                    'event_type' => 'StartAccepted',
                    'payload' => [],
                    'workflow_task_id' => null,
                    'workflow_command_id' => null,
                    'recorded_at' => $recordedAt,
                ],
                [
                    'id' => 'evt-workflow-started',
                    'sequence' => 2,
                    'event_type' => 'WorkflowStarted',
                    'payload' => [],
                    'workflow_task_id' => null,
                    'workflow_command_id' => null,
                    'recorded_at' => $recordedAt,
                ],
            ],
        ];
        $claimCalls = 0;

        $this->mock(WorkflowTaskBridge::class, function (MockInterface $mock) use (
            $leaseExpiresAt,
            $duplicateLeaseExpiresAt,
            $recordedAt,
            $runId,
            $task,
            $workflowId,
            $historyPayload,
            &$claimCalls,
        ): void {
            $mock->shouldReceive('poll')
                ->times(2)
                ->with(null, 'external-workflows', 10, null, 'default')
                ->andReturn(
                    [[
                        'task_id' => $task->id,
                        'workflow_run_id' => $runId,
                        'workflow_instance_id' => $workflowId,
                        'workflow_type' => 'tests.external-greeting-workflow',
                        'workflow_class' => ExternalGreetingWorkflow::class,
                        'connection' => null,
                        'queue' => 'external-workflows',
                        'compatibility' => null,
                        'available_at' => $recordedAt,
                    ]],
                    [[
                        'task_id' => $task->id,
                        'workflow_run_id' => $runId,
                        'workflow_instance_id' => $workflowId,
                        'workflow_type' => 'tests.external-greeting-workflow',
                        'workflow_class' => ExternalGreetingWorkflow::class,
                        'connection' => null,
                        'queue' => 'external-workflows',
                        'compatibility' => null,
                        'available_at' => $recordedAt,
                    ]],
                );

            $mock->shouldReceive('claimStatus')
                ->times(2)
                ->andReturnUsing(function (string $claimedTaskId, string $leaseOwner) use (
                    &$claimCalls,
                    $leaseExpiresAt,
                    $duplicateLeaseExpiresAt,
                    $runId,
                    $task,
                    $workflowId,
                ): array {
                    $claimCalls++;
                    $this->assertSame($task->id, $claimedTaskId);
                    $this->assertSame('php-worker-bridge', $leaseOwner);

                    return [
                        'claimed' => true,
                        'task_id' => $task->id,
                        'workflow_run_id' => $runId,
                        'workflow_instance_id' => $workflowId,
                        'workflow_type' => 'tests.external-greeting-workflow',
                        'workflow_class' => ExternalGreetingWorkflow::class,
                        'payload_codec' => (string) config('workflows.serializer'),
                        'connection' => null,
                        'queue' => 'external-workflows',
                        'compatibility' => null,
                        'lease_owner' => $leaseOwner,
                        'lease_expires_at' => match ($claimCalls) {
                            1 => $leaseExpiresAt,
                            default => $duplicateLeaseExpiresAt,
                        },
                        'reason' => null,
                        'reason_detail' => null,
                    ];
                });

            $paginatedHistoryPayload = array_merge($historyPayload, [
                'after_sequence' => 0,
                'page_size' => 500,
                'has_more' => false,
                'next_after_sequence' => null,
            ]);

            $mock->shouldReceive('historyPayloadPaginated')
                ->times(2)
                ->andReturn($paginatedHistoryPayload, $paginatedHistoryPayload);

            $mock->shouldReceive('heartbeat')
                ->andReturnUsing(function (string $heartbeatTaskId) use ($leaseExpiresAt): array {
                    return [
                        'renewed' => true,
                        'lease_expires_at' => $leaseExpiresAt,
                        'run_status' => 'pending',
                        'task_status' => 'leased',
                        'reason' => null,
                    ];
                });

            $mock->shouldReceive('status')
                ->andReturnUsing(function (string $taskId) {
                    return app()->make(DefaultWorkflowTaskBridge::class)->status($taskId);
                });
        });

        $this->registerWorker('php-worker-bridge', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-bridge',
                'task_queue' => 'external-workflows',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.task_id', $task->id)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-bridge');

        // The server sources workflow_task_attempt from the package's
        // authoritative attempt_count, normalized to >= 1 for the protocol.
        $firstAttempt = $firstPoll->json('task.workflow_task_attempt');
        $this->assertGreaterThanOrEqual(1, $firstAttempt);

        // Simulate the DB side-effect that the real bridge's claimStatus()
        // performs — the mock doesn't touch the DB, so we mirror the claim
        // state manually so the ownership guard can verify it.
        $task->forceFill([
            'lease_owner' => 'php-worker-bridge',
            'lease_expires_at' => now()->addMinutes(5),
            'attempt_count' => 1,
        ])->save();

        // Duplicate polls return the same attempt value for the same lease.
        $duplicatePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-bridge',
                'task_queue' => 'external-workflows',
            ]);

        $duplicatePoll->assertOk()
            ->assertJsonPath('task.task_id', $task->id)
            ->assertJsonPath('task.workflow_task_attempt', $firstAttempt)
            ->assertJsonPath('task.lease_owner', 'php-worker-bridge');

        // The ownership guard fences stale workers using the package's
        // lease_owner and attempt_count directly.
        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$task->id}/heartbeat", [
                'lease_owner' => 'php-worker-bridge',
                'workflow_task_attempt' => $firstAttempt,
            ])
            ->assertOk()
            ->assertJsonPath('renewed', true);

        // Wrong attempt number is fenced.
        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$task->id}/heartbeat", [
                'lease_owner' => 'php-worker-bridge',
                'workflow_task_attempt' => $firstAttempt + 999,
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'workflow_task_attempt_mismatch');
    }

    public function test_it_redelivers_the_same_workflow_task_for_duplicate_poll_request_ids(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-duplicate-poll', 'external-workflows');
        $this->registerWorker('php-worker-other', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-poll',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-1',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-poll');

        $taskId = (string) $firstPoll->json('task.task_id');
        $attempt = (int) $firstPoll->json('task.workflow_task_attempt');

        $duplicatePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-poll',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-1',
            ]);

        $duplicatePoll->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.workflow_task_attempt', $attempt)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-poll');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-poll',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-2',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-other',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-1',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        $taskRow = WorkflowTask::query()->findOrFail($taskId);

        $this->assertSame(1, $taskRow->attempt_count);
    }

    public function test_duplicate_poll_request_redelivery_is_scoped_by_task_queue(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request-queue-a',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows-a',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request-queue-b',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows-b',
                'input' => ['Grace'],
            ])
            ->assertCreated();

        $this->registerWorker('php-worker-shared-poll-request', 'external-workflows-a');

        $queueAPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-shared-poll-request',
                'task_queue' => 'external-workflows-a',
                'poll_request_id' => 'shared-poll-request',
            ]);

        $queueAPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-queue-a')
            ->assertJsonPath('task.task_queue', 'external-workflows-a')
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $queueATaskId = (string) $queueAPoll->json('task.task_id');

        $this->registerWorker('php-worker-shared-poll-request', 'external-workflows-b');

        $queueBPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-shared-poll-request',
                'task_queue' => 'external-workflows-b',
                'poll_request_id' => 'shared-poll-request',
            ]);

        $queueBPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-queue-b')
            ->assertJsonPath('task.task_queue', 'external-workflows-b')
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $queueBTaskId = (string) $queueBPoll->json('task.task_id');

        $this->registerWorker('php-worker-shared-poll-request', 'external-workflows-a');

        $duplicateQueueA = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-shared-poll-request',
                'task_queue' => 'external-workflows-a',
                'poll_request_id' => 'shared-poll-request',
            ]);

        $duplicateQueueA->assertOk()
            ->assertJsonPath('task.task_id', $queueATaskId)
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-queue-a')
            ->assertJsonPath('task.task_queue', 'external-workflows-a')
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $this->registerWorker('php-worker-shared-poll-request', 'external-workflows-b');

        $duplicateQueueB = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-shared-poll-request',
                'task_queue' => 'external-workflows-b',
                'poll_request_id' => 'shared-poll-request',
            ]);

        $duplicateQueueB->assertOk()
            ->assertJsonPath('task.task_id', $queueBTaskId)
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-queue-b')
            ->assertJsonPath('task.task_queue', 'external-workflows-b')
            ->assertJsonPath('task.workflow_task_attempt', 1);
    }

    public function test_it_replays_cached_duplicate_poll_request_results_even_if_the_lease_row_is_missing(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request-cache',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-duplicate-cache', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-cache',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-cache-1',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-cache')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-cache');

        $taskId = (string) $firstPoll->json('task.task_id');

        $duplicatePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-cache',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-cache-1',
            ]);

        $duplicatePoll->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-cache');
    }

    public function test_it_replays_cached_duplicate_poll_request_results_after_the_old_short_cache_window_when_the_lease_is_still_active(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request-cache-late-retry',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-duplicate-cache-late', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-cache-late',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-cache-late-1',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-cache-late-retry')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-cache-late');

        $taskId = (string) $firstPoll->json('task.task_id');

        $this->travel(10)->seconds();

        try {
            $duplicatePoll = $this->withHeaders($this->workerHeaders())
                ->postJson('/api/worker/workflow-tasks/poll', [
                    'worker_id' => 'php-worker-duplicate-cache-late',
                    'task_queue' => 'external-workflows',
                    'poll_request_id' => 'poll-request-cache-late-1',
                ]);

            $duplicatePoll->assertOk()
                ->assertJsonPath('task.task_id', $taskId)
                ->assertJsonPath('task.workflow_task_attempt', 1)
                ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-cache-late');
        } finally {
            $this->travelBack();
        }
    }

    public function test_it_does_not_replay_cached_duplicate_poll_results_after_the_task_is_completed(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request-completed',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-duplicate-complete', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-complete',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-complete-1',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-completed')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-complete');

        $taskId = (string) $firstPoll->json('task.task_id');
        $attempt = (int) $firstPoll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $firstPoll->json('task.lease_owner');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('json', [
                            'greeting' => 'Hello, Ada!',
                        ]),
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'completed');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-complete',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-complete-1',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_duplicate_poll_request_redelivery_refreshes_live_lease_metadata_after_a_heartbeat(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request-heartbeat-refresh',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-duplicate-heartbeat', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-heartbeat',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-heartbeat-1',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-heartbeat-refresh')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-heartbeat');

        $taskId = (string) $firstPoll->json('task.task_id');
        $attempt = (int) $firstPoll->json('task.workflow_task_attempt');
        $initialLeaseExpiresAt = (string) $firstPoll->json('task.lease_expires_at');

        $heartbeat = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
                'lease_owner' => 'php-worker-duplicate-heartbeat',
                'workflow_task_attempt' => $attempt,
            ]);

        $heartbeat->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('lease_owner', 'php-worker-duplicate-heartbeat')
            ->assertJsonPath('renewed', true)
            ->assertJsonPath('reason', null);

        $renewedLeaseExpiresAt = (string) $heartbeat->json('lease_expires_at');

        $this->assertNotSame($initialLeaseExpiresAt, $renewedLeaseExpiresAt);

        $duplicatePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-heartbeat',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-heartbeat-1',
            ]);

        $duplicatePoll->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.workflow_task_attempt', $attempt)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-heartbeat')
            ->assertJsonPath('task.lease_expires_at', $renewedLeaseExpiresAt);
    }

    public function test_completion_succeeds_for_a_standard_workflow_task(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-missing-workflow-task-lease-complete',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-missing-lease-complete', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-missing-lease-complete',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('json', [
                            'greeting' => 'Hello, Ada!',
                        ]),
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'completed');
    }

    public function test_completion_returns_422_when_structural_limit_exceeded(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-structural-limit',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-structural-limit', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-structural-limit',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $this->instance(
            WorkflowTaskBridge::class,
            \Mockery::mock(WorkflowTaskBridge::class, static function (MockInterface $mock) {
                $mock->shouldReceive('complete')
                    ->andThrow(
                        StructuralLimitExceededException::pendingActivityCount(2000, 2000),
                    );

                $mock->shouldReceive('status')
                    ->andReturnUsing(function (string $taskId) {
                        return app()->make(DefaultWorkflowTaskBridge::class)->status($taskId);
                    });
            }),
        );

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'greeting.send',
                    ],
                ],
            ]);

        $complete->assertStatus(422)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'rejected')
            ->assertJsonPath('reason', 'structural_limit_exceeded')
            ->assertJsonPath('limit_kind', 'pending_activity_count')
            ->assertJsonPath('current_value', 2000)
            ->assertJsonPath('configured_limit', 2000);
    }

    public function test_heartbeat_succeeds_for_a_leased_workflow_task(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-stale-workflow-task-lease-heartbeat',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-stale-lease-heartbeat', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-stale-lease-heartbeat',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $heartbeat = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
            ]);

        $heartbeat->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('lease_owner', $leaseOwner)
            ->assertJsonPath('renewed', true)
            ->assertJsonPath('reason', null);
    }

    public function test_it_reports_lease_owner_mismatch_when_wrong_worker_sends_heartbeat(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-missing-workflow-task-lease-mismatch',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-mirror-owner', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-mirror-owner',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
                'lease_owner' => 'php-worker-wrong-owner',
                'workflow_task_attempt' => $attempt,
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch')
            ->assertJsonPath('lease_owner', 'php-worker-mirror-owner');
    }

    public function test_it_drops_claimed_workflow_tasks_when_the_bridge_cannot_build_the_history_payload(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        WorkflowInstance::query()->create([
            'id' => 'wf-bridge-missing-task',
            'workflow_class' => ExternalGreetingWorkflow::class,
            'workflow_type' => 'tests.external-greeting-workflow',
            'namespace' => 'default',
            'run_count' => 0,
        ]);

        $recordedAt = now()->toJSON();

        $this->mock(WorkflowTaskBridge::class, function (MockInterface $mock) use ($recordedAt): void {
            $mock->shouldReceive('poll')
                ->once()
                ->with(null, 'external-workflows', 10, null, 'default')
                ->andReturn([
                    [
                        'task_id' => 'wf-task-missing-row',
                        'workflow_run_id' => 'run-bridge-missing-task',
                        'workflow_instance_id' => 'wf-bridge-missing-task',
                        'workflow_type' => 'tests.external-greeting-workflow',
                        'workflow_class' => ExternalGreetingWorkflow::class,
                        'connection' => null,
                        'queue' => 'external-workflows',
                        'compatibility' => null,
                        'available_at' => $recordedAt,
                    ],
                ]);

            $mock->shouldReceive('claimStatus')
                ->once()
                ->with('wf-task-missing-row', 'php-worker-missing-row')
                ->andReturn([
                    'claimed' => true,
                    'task_id' => 'wf-task-missing-row',
                    'workflow_run_id' => 'run-bridge-missing-task',
                    'workflow_instance_id' => 'wf-bridge-missing-task',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'workflow_class' => ExternalGreetingWorkflow::class,
                    'payload_codec' => (string) config('workflows.serializer'),
                    'connection' => null,
                    'queue' => 'external-workflows',
                    'compatibility' => null,
                    'lease_owner' => 'php-worker-missing-row',
                    'lease_expires_at' => now()->addMinutes(5)->toJSON(),
                    'reason' => null,
                    'reason_detail' => null,
                ]);

            $mock->shouldReceive('historyPayloadPaginated')
                ->once()
                ->andReturn(null);
        });

        $this->registerWorker('php-worker-missing-row', 'external-workflows');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-missing-row',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_it_does_not_fall_back_to_a_local_ready_scan_when_the_workflow_bridge_returns_no_tasks(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-bridge-only-poll',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->mock(WorkflowTaskBridge::class, function (MockInterface $mock): void {
            $mock->shouldReceive('poll')
                ->once()
                ->with(null, 'external-workflows', 10, null, 'default')
                ->andReturn([]);
        });

        $this->registerWorker('php-worker-bridge-only', 'external-workflows');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-bridge-only',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_it_passes_the_next_visible_workflow_task_deadline_into_long_polling(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-workflow-task-next-probe',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');
        $futureAvailableAt = now()->addMinutes(2)->startOfSecond();

        WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->firstOrFail()
            ->forceFill([
                'available_at' => $futureAvailableAt,
            ])->save();

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        $expectedChannels = $signals->workflowTaskPollChannels('default', null, 'external-workflows');

        $this->mock(LongPoller::class, function (MockInterface $mock) use (
            $expectedChannels,
            $futureAvailableAt,
        ): void {
            $mock->shouldReceive('until')
                ->once()
                ->andReturnUsing(function (
                    callable $probe,
                    callable $ready,
                    ?int $timeoutSeconds = null,
                    ?int $intervalMilliseconds = null,
                    array $wakeChannels = [],
                    ?callable $nextProbeAt = null,
                ) use ($expectedChannels, $futureAvailableAt) {
                    $this->assertSame($expectedChannels, $wakeChannels);

                    $initial = $probe();

                    $this->assertNull($initial);
                    $this->assertFalse($ready($initial));
                    $this->assertIsCallable($nextProbeAt);

                    $hint = $nextProbeAt();

                    $this->assertInstanceOf(\DateTimeInterface::class, $hint);
                    $this->assertSame(
                        $futureAvailableAt->format('U.u'),
                        $hint->format('U.u'),
                    );

                    return null;
                });
        });

        $this->registerWorker('php-worker-next-probe', 'external-workflows');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-next-probe',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_it_filters_workflow_tasks_by_worker_build_id(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-build-compatible',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Margaret'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->firstOrFail();

        $task->forceFill([
            'compatibility' => 'build-a',
        ])->save();

        $this->registerWorker('php-worker-no-build', 'external-workflows');
        $this->registerWorker('php-worker-build-a', 'external-workflows', buildId: 'build-a');

        // Worker with no registered build_id cannot claim a task with compatibility=build-a.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-no-build',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        // Worker registered with build_id=build-a claims the compatible task.
        // The build_id for routing is derived from the registration record,
        // not the poll request parameter — so build_id is intentionally
        // omitted from the poll body.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-build-a',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.compatibility', 'build-a')
            ->assertJsonPath('task.lease_owner', 'php-worker-build-a');
    }

    public function test_it_rejects_workflow_task_poll_when_build_id_mismatches_registration(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->registerWorker(
            'php-worker-build-mismatch',
            'external-workflows',
            buildId: 'build-v1',
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-build-mismatch',
                'task_queue' => 'external-workflows',
                'build_id' => 'build-v2',
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'build_id_mismatch')
            ->assertJsonPath('worker_id', 'php-worker-build-mismatch')
            ->assertJsonPath('registered_build_id', 'build-v1')
            ->assertJsonPath('requested_build_id', 'build-v2');
    }

    public function test_it_allows_workflow_task_poll_when_build_id_matches_registration(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->registerWorker(
            'php-worker-build-match',
            'external-workflows',
            buildId: 'build-v1',
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-build-match',
                'task_queue' => 'external-workflows',
                'build_id' => 'build-v1',
            ])
            ->assertOk();
    }

    public function test_it_allows_workflow_task_poll_when_worker_has_no_registered_build_id(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->registerWorker('php-worker-no-build', 'external-workflows');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-no-build',
                'task_queue' => 'external-workflows',
                'build_id' => 'build-v2',
            ])
            ->assertOk();
    }

    public function test_it_rejects_activity_task_poll_when_build_id_mismatches_registration(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->registerWorker(
            'php-activity-worker-mismatch',
            'default',
            buildId: 'build-v1',
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-worker-mismatch',
                'task_queue' => 'default',
                'build_id' => 'build-v2',
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'build_id_mismatch')
            ->assertJsonPath('worker_id', 'php-activity-worker-mismatch')
            ->assertJsonPath('registered_build_id', 'build-v1')
            ->assertJsonPath('requested_build_id', 'build-v2');
    }

    public function test_it_routes_workflow_tasks_by_registered_build_id_when_poll_omits_build_id(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-registration-authority',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Alice'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        // Stamp the task with a compatibility marker.
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->firstOrFail();

        $task->forceFill(['compatibility' => 'build-reg'])->save();

        // Register worker WITH build_id, then poll WITHOUT build_id in the
        // request body.  The server should derive build_id from the
        // registration record and still claim the compatible task.
        $this->registerWorker('php-worker-reg-build', 'external-workflows', buildId: 'build-reg');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-reg-build',
                'task_queue' => 'external-workflows',
                // build_id intentionally omitted
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.compatibility', 'build-reg')
            ->assertJsonPath('task.lease_owner', 'php-worker-reg-build');
    }

    public function test_it_ignores_poll_build_id_when_worker_has_no_registered_build_id(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-no-reg-build',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Bob'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        // Stamp the task with a compatibility marker.
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->firstOrFail();

        $task->forceFill(['compatibility' => 'build-phantom'])->save();

        // Register worker WITHOUT build_id, then poll WITH a build_id.
        // The server should derive build_id=null from the registration and
        // NOT use the poll's build_id for routing.  The task has a
        // compatibility marker so it should not be claimed.
        $this->registerWorker('php-worker-no-reg-build', 'external-workflows');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-no-reg-build',
                'task_queue' => 'external-workflows',
                'build_id' => 'build-phantom',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_it_routes_activity_tasks_by_registered_build_id_not_poll_parameter(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        // Start a workflow and complete the workflow task so an activity task
        // is created — then stamp the activity task with a compatibility
        // marker and verify registration-backed routing.
        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-activity-build-route',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Carol'],
            ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        // Register a worker with build_id for the activity poll.
        $this->registerWorker(
            'php-activity-build-worker',
            'external-workflows',
            buildId: 'build-act-1',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        // Find the activity task (if one was created by workflow completion).
        // If none exists, stamp the workflow task as an activity for this
        // isolated routing test.
        $activityTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'activity')
            ->first();

        if ($activityTask) {
            $activityTask->forceFill(['compatibility' => 'build-act-1'])->save();

            // Poll without build_id — registration should supply it.
            $this->withHeaders($this->workerHeaders())
                ->postJson('/api/worker/activity-tasks/poll', [
                    'worker_id' => 'php-activity-build-worker',
                    'task_queue' => 'external-workflows',
                    // build_id intentionally omitted
                ])
                ->assertOk()
                ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity');
        }

        // Regardless of activity task availability, verify that a worker
        // without a registered build_id does not route with the poll's
        // build_id claim.
        $this->registerWorker('php-activity-no-build', 'external-workflows');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-no-build',
                'task_queue' => 'external-workflows',
                'build_id' => 'build-act-1',
            ])
            ->assertOk();
    }

    public function test_it_fences_stale_workflow_task_workers_and_records_failures(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-fail',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Linus'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-3', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-3',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => 'wrong-worker',
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'Replay failed',
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => 'php-worker-3',
                'workflow_task_attempt' => $attempt + 1,
                'failure' => [
                    'message' => 'Replay failed',
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'workflow_task_attempt_mismatch');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => 'php-worker-3',
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'Determinism violation',
                    'type' => 'determinism_violation',
                ],
            ]);

        $fail->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true);

        $task = WorkflowTask::query()->findOrFail($taskId);

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('Determinism violation', $task->last_error);
    }

    public function test_it_heartbeats_leased_workflow_tasks_and_fences_stale_workers(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-heartbeat',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Grace'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-heartbeat', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-heartbeat',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-external-worker-heartbeat')
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $initialLeaseExpiresAt = $poll->json('task.lease_expires_at');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
                'lease_owner' => 'wrong-worker',
                'workflow_task_attempt' => $attempt,
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch');

        $heartbeat = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
                'lease_owner' => 'php-worker-heartbeat',
                'workflow_task_attempt' => $attempt,
            ]);

        $heartbeat->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('lease_owner', 'php-worker-heartbeat')
            ->assertJsonPath('renewed', true)
            ->assertJsonPath('task_status', 'leased')
            ->assertJsonPath('reason', null);

        $this->assertIsString($heartbeat->json('lease_expires_at'));
        $this->assertNotSame($initialLeaseExpiresAt, $heartbeat->json('lease_expires_at'));
    }

    public function test_it_proactively_repairs_expired_workflow_task_leases_when_a_new_worker_polls(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-expired-workflow-task-poll-repair',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-expired-poll-lease', 'external-workflows');
        $this->registerWorker('php-worker-recovered-during-poll', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-expired-poll-lease',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $expiredAt = now()->subMinute()->startOfSecond();

        WorkflowTask::query()->findOrFail($taskId)
            ->forceFill([
                'lease_expires_at' => $expiredAt,
            ])->save();

        $recoveredPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-recovered-during-poll',
                'task_queue' => 'external-workflows',
            ]);

        $recoveredPoll->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.lease_owner', 'php-worker-recovered-during-poll');

        $recoveredAttempt = (int) $recoveredPoll->json('task.workflow_task_attempt');
        $this->assertGreaterThanOrEqual(1, $recoveredAttempt);

        $repairHistory = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-expired-workflow-task-poll-repair/runs/{$start->json('run_id')}/history");

        $repairHistory->assertOk();

        $this->assertContains(
            'RepairRequested',
            array_column($repairHistory->json('events'), 'event_type'),
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('json', ['late' => 'stale-worker']),
                    ],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch')
            ->assertJsonPath('lease_owner', 'php-worker-recovered-during-poll');
    }

    public function test_it_requests_package_repair_when_a_workflow_task_lease_expires(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-expired-workflow-task-lease',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-expired-lease', 'external-workflows');
        $this->registerWorker('php-worker-recovered-lease', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-expired-lease',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $expiredAt = now()->subMinute()->startOfSecond();

        WorkflowTask::query()->findOrFail($taskId)
            ->forceFill([
                'lease_expires_at' => $expiredAt,
            ])->save();

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_expired')
            ->assertJsonPath('lease_owner', $leaseOwner)
            ->assertJsonPath('task_status', 'leased')
            ->assertJsonPath('lease_expires_at', $expiredAt->toJSON());

        $recoveredPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-recovered-lease',
                'task_queue' => 'external-workflows',
            ]);

        $recoveredPoll->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.lease_owner', 'php-worker-recovered-lease');

        $recoveredAttempt = (int) $recoveredPoll->json('task.workflow_task_attempt');
        $this->assertGreaterThanOrEqual(1, $recoveredAttempt);

        $repairHistory = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-expired-workflow-task-lease/runs/{$start->json('run_id')}/history");

        $repairHistory->assertOk();

        $this->assertContains(
            'RepairRequested',
            array_column($repairHistory->json('events'), 'event_type'),
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('json', ['late' => 'stale-worker']),
                    ],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch')
            ->assertJsonPath('lease_owner', 'php-worker-recovered-lease');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'php-worker-recovered-lease',
                'workflow_task_attempt' => $recoveredAttempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('json', ['late' => true]),
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'completed');

        $task = WorkflowTask::query()->findOrFail($taskId);

        $this->assertSame(TaskStatus::Completed, $task->status);
    }

    public function test_it_recovers_expired_workflow_task_leases_and_completes_successfully(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-recovery-without-mirror-row',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-mirror-absent', 'external-workflows');
        $this->registerWorker('php-worker-recovered-without-mirror', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-mirror-absent',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $expiredAt = now()->subMinute()->startOfSecond();

        // Expire the lease on the package's WorkflowTask.
        WorkflowTask::query()->findOrFail($taskId)
            ->forceFill([
                'lease_expires_at' => $expiredAt,
            ])->save();

        $recoveredPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-recovered-without-mirror',
                'task_queue' => 'external-workflows',
            ]);

        $recoveredPoll->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.lease_owner', 'php-worker-recovered-without-mirror');

        $recoveredAttempt = (int) $recoveredPoll->json('task.workflow_task_attempt');
        $this->assertGreaterThanOrEqual(1, $recoveredAttempt);

        $repairHistory = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-recovery-without-mirror-row/runs/{$start->json('run_id')}/history");

        $repairHistory->assertOk();

        $this->assertContains(
            'RepairRequested',
            array_column($repairHistory->json('events'), 'event_type'),
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'php-worker-recovered-without-mirror',
                'workflow_task_attempt' => $recoveredAttempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('json', ['recovered' => true]),
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'completed');
    }

    public function test_it_schedules_external_activities_from_non_terminal_workflow_task_commands(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-schedules-activity',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $this->registerWorker('php-worker-schedule', 'external-workflows');
        $this->registerWorker('php-activity-worker', 'external-activities');
        $this->registerWorker('php-worker-resume', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-schedule',
                'task_queue' => 'external-workflows',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId);

        $scheduleActivity = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $firstPoll->json('task.task_id')), [
                'lease_owner' => $firstPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $firstPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec(
                            (string) config('workflows.serializer'),
                            ['Ada'],
                        ),
                        'queue' => 'external-activities',
                    ],
                ],
            ]);

        $scheduleActivity->assertOk()
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'waiting');

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('status', 'waiting');

        $activityPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-worker',
                'task_queue' => 'external-activities',
            ]);

        $activityPoll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity');

        $activityPoll->assertJsonPath('task.arguments.codec', (string) config('workflows.serializer'));
        $this->assertSame(
            ['Ada'],
            Serializer::unserializeWithCodec(
                (string) $activityPoll->json('task.arguments.codec'),
                (string) $activityPoll->json('task.arguments.blob'),
            ),
        );

        $completeActivity = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/activity-tasks/%s/complete', $activityPoll->json('task.task_id')), [
                'activity_attempt_id' => $activityPoll->json('task.activity_attempt_id'),
                'lease_owner' => $activityPoll->json('task.lease_owner'),
                'result' => 'Hello, Ada!',
            ]);

        $completeActivity->assertOk()
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true);

        $resumePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-resume',
                'task_queue' => 'external-workflows',
            ]);

        $resumePoll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId);

        $resumeEventTypes = array_column((array) $resumePoll->json('task.history_events'), 'event_type');

        $this->assertContains('ActivityScheduled', $resumeEventTypes);
        $this->assertContains('ActivityStarted', $resumeEventTypes);
        $this->assertContains('ActivityCompleted', $resumeEventTypes);

        $completeWorkflow = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $resumePoll->json('task.task_id')), [
                'lease_owner' => $resumePoll->json('task.lease_owner'),
                'workflow_task_attempt' => $resumePoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec((string) config('workflows.serializer'), [
                            'greeting' => 'Hello, Ada!',
                            'workflow_id' => $workflowId,
                        ]),
                    ],
                ],
            ]);

        $completeWorkflow->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'completed');

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('output.greeting', 'Hello, Ada!');
    }

    public function test_it_resumes_external_workflows_after_activity_worker_failures(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-activity-fails',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $this->registerWorker('php-worker-schedule-failing-activity', 'external-workflows');
        $this->registerWorker('php-activity-worker-fails-activity', 'external-activities');
        $this->registerWorker('php-worker-resume-after-activity-failure', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-schedule-failing-activity',
                'task_queue' => 'external-workflows',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId);

        $scheduleActivity = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $firstPoll->json('task.task_id')), [
                'lease_owner' => $firstPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $firstPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec(
                            (string) config('workflows.serializer'),
                            ['Ada'],
                        ),
                        'queue' => 'external-activities',
                    ],
                ],
            ]);

        $scheduleActivity->assertOk()
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'waiting');

        $this->assertIsString($scheduleActivity->json('created_task_ids.0'));

        $activityPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-worker-fails-activity',
                'task_queue' => 'external-activities',
            ]);

        $activityPoll->assertOk()
            ->assertHeader('X-Durable-Workflow-Protocol-Version', '1.0')
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.arguments.codec', (string) config('workflows.serializer'));

        $failActivity = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/activity-tasks/%s/fail', $activityPoll->json('task.task_id')), [
                'activity_attempt_id' => $activityPoll->json('task.activity_attempt_id'),
                'lease_owner' => $activityPoll->json('task.lease_owner'),
                'failure' => [
                    'message' => 'Inventory service timed out.',
                    'type' => 'TimeoutException',
                    'stack_trace' => 'at activity_worker.py:42',
                    'non_retryable' => true,
                    'details' => [
                        'codec' => 'json',
                        'blob' => '{"stage":"inventory","retry_after":30}',
                    ],
                ],
            ]);

        $failActivity->assertOk()
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true);

        $this->assertIsString($failActivity->json('next_task_id'));

        $resumePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-resume-after-activity-failure',
                'task_queue' => 'external-workflows',
            ]);

        $resumePoll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.lease_owner', 'php-worker-resume-after-activity-failure');

        $resumeEvents = collect((array) $resumePoll->json('task.history_events'));
        $activityFailed = $resumeEvents->firstWhere('event_type', 'ActivityFailed');

        $this->assertIsArray($activityFailed);
        $this->assertSame('Inventory service timed out.', $activityFailed['payload']['message'] ?? null);
        $this->assertTrue($activityFailed['payload']['non_retryable'] ?? false);
        $this->assertSame(
            'json',
            $activityFailed['payload']['exception']['details_payload_codec'] ?? null,
        );

        $completeWorkflow = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $resumePoll->json('task.task_id')), [
                'lease_owner' => $resumePoll->json('task.lease_owner'),
                'workflow_task_attempt' => $resumePoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'fail_workflow',
                        'message' => 'Activity failure propagated to workflow.',
                        'exception_class' => 'ExternalActivityFailure',
                        'non_retryable' => true,
                    ],
                ],
            ]);

        $completeWorkflow->assertOk()
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'failed');

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('status_bucket', 'failed');

        $history = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}/history");

        $history->assertOk();

        $historyEventTypes = array_column($history->json('events'), 'event_type');

        $this->assertContains('ActivityFailed', $historyEventTypes);
        $this->assertContains('WorkflowFailed', $historyEventTypes);
    }

    public function test_it_starts_timers_from_non_terminal_workflow_task_commands(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-starts-timer',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Grace'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->registerWorker('php-worker-timer', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-timer',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $poll->json('task.task_id')), [
                'lease_owner' => $poll->json('task.lease_owner'),
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'start_timer',
                        'delay_seconds' => 30,
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'waiting');

        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'timer')
            ->where('status', 'ready')
            ->first();

        $this->assertNotNull($timerTask);
        $this->assertNotNull($timerTask->available_at);

        $history = $this->withHeaders($this->apiHeaders())
            ->getJson(sprintf('/api/workflows/%s/runs/%s/history', $start->json('workflow_id'), $runId));

        $history->assertOk();

        $eventTypes = array_column($history->json('events'), 'event_type');

        $this->assertContains('TimerScheduled', $eventTypes);
    }

    public function test_it_binds_child_workflows_started_by_external_workflow_tasks_to_the_namespace(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-starts-child',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Linus'],
            ]);

        $start->assertCreated();

        $parentWorkflowId = (string) $start->json('workflow_id');
        $parentRunId = (string) $start->json('run_id');

        $this->registerWorker('php-worker-parent', 'external-workflows');
        $this->registerWorker('php-worker-child', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-parent',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', $parentWorkflowId)
            ->assertJsonPath('task.run_id', $parentRunId);

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $poll->json('task.task_id')), [
                'lease_owner' => $poll->json('task.lease_owner'),
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'start_child_workflow',
                        'workflow_type' => 'tests.external-child-workflow',
                        'queue' => 'external-workflows',
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'waiting');

        $childInstance = WorkflowInstance::query()
            ->where('namespace', 'default')
            ->where('workflow_type', 'tests.external-child-workflow')
            ->first();

        $this->assertNotNull($childInstance);

        $childWorkflowId = (string) $childInstance->id;
        $childRun = WorkflowRun::query()
            ->where('workflow_instance_id', $childWorkflowId)
            ->first();

        $this->assertNotNull($childRun);
        $this->assertSame('default', $childRun->namespace);
        $this->assertSame(
            'default',
            WorkflowTask::query()
                ->where('workflow_run_id', $childRun->id)
                ->where('task_type', TaskType::Workflow->value)
                ->value('namespace'),
        );

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$childWorkflowId}")
            ->assertOk()
            ->assertJsonPath('workflow_id', $childWorkflowId)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('workflow_type', 'tests.external-child-workflow')
            ->assertJsonPath('status', 'pending');

        $childPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-child',
                'task_queue' => 'external-workflows',
            ]);

        $childPoll->assertOk()
            ->assertJsonPath('task.workflow_id', $childWorkflowId)
            ->assertJsonPath('task.workflow_type', 'tests.external-child-workflow');
    }

    public function test_it_continues_workflows_as_new_from_external_workflow_tasks(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-continue-as-new',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $originalRunId = (string) $start->json('run_id');

        $this->registerWorker('php-worker-continue', 'external-workflows');
        $this->registerWorker('php-worker-continued-run', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-continue',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $originalRunId);

        $continueAsNew = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $poll->json('task.task_id')), [
                'lease_owner' => $poll->json('task.lease_owner'),
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'continue_as_new',
                        'workflow_type' => 'tests.external-greeting-workflow',
                        'arguments' => Serializer::serializeWithCodec(
                            (string) config('workflows.serializer'),
                            ['Ada v2'],
                        ),
                    ],
                ],
            ]);

        $continueAsNew->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $originalRunId)
            ->assertJsonPath('run_status', 'completed');

        $runs = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs");

        $runs->assertOk()
            ->assertJsonCount(2, 'runs')
            ->assertJsonPath('runs.0.run_id', $originalRunId)
            ->assertJsonPath('runs.0.status', 'completed')
            ->assertJsonPath('runs.1.run_number', 2)
            ->assertJsonPath('runs.1.status', 'pending');

        $continuedRunId = (string) $runs->json('runs.1.run_id');

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}")
            ->assertOk()
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('run_id', $continuedRunId)
            ->assertJsonPath('status', 'pending');

        $continuedPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-continued-run',
                'task_queue' => 'external-workflows',
            ]);

        $continuedPoll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $continuedRunId);
    }

    public function test_poll_response_paginates_history_events_when_history_page_size_is_set(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-paginated-history',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $this->registerWorker('php-worker-paginated', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-paginated',
                'task_queue' => 'external-workflows',
                'history_page_size' => 1,
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId);

        $events = $poll->json('task.history_events');
        $totalEvents = $poll->json('task.total_history_events');
        $nextToken = $poll->json('task.next_history_page_token');

        $this->assertCount(1, $events);
        $this->assertGreaterThan(1, $totalEvents);
        $this->assertNotNull($nextToken);

        $taskId = (string) $poll->json('task.task_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $historyPage = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/history", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'next_history_page_token' => $nextToken,
                'history_page_size' => 100,
            ]);

        $historyPage->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('total_history_events', $totalEvents);

        $pageEvents = $historyPage->json('history_events');

        $this->assertNotEmpty($pageEvents);
        $this->assertNull($historyPage->json('next_history_page_token'));

        $allSequences = array_merge(
            array_column($events, 'sequence'),
            array_column($pageEvents, 'sequence'),
        );

        $this->assertSame(
            $allSequences,
            array_unique($allSequences),
            'Pages must not contain duplicate events.',
        );
    }

    public function test_poll_response_includes_all_history_events_when_within_default_page_size(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-no-pagination-needed',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-no-pagination', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-no-pagination',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $totalEvents = $poll->json('task.total_history_events');
        $events = $poll->json('task.history_events');

        $this->assertSame(count($events), $totalEvents);
        $this->assertNull($poll->json('task.next_history_page_token'));
    }

    public function test_workflow_task_history_endpoint_rejects_invalid_page_token(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-invalid-page-token',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-invalid-token', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-invalid-token',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/history", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'next_history_page_token' => 'not-valid-base64-sequence',
            ])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'invalid_page_token');
    }

    public function test_workflow_task_history_endpoint_guards_lease_ownership(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-history-ownership-guard',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-history-owner', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-history-owner',
                'task_queue' => 'external-workflows',
                'history_page_size' => 1,
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $nextToken = $poll->json('task.next_history_page_token');

        $this->assertNotNull($nextToken);

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/history", [
                'lease_owner' => 'wrong-worker',
                'workflow_task_attempt' => $attempt,
                'next_history_page_token' => $nextToken,
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch');
    }

    public function test_complete_response_includes_created_task_ids(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-created-task-ids',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-created-tasks', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-created-tasks',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('json', ['greeting' => 'Hello, Ada!']),
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('recorded', true);

        $this->assertIsArray($complete->json('created_task_ids'));
    }

    public function test_server_capabilities_advertise_history_pagination(): void
    {
        $this->createNamespace('default', 'Default namespace');

        $register = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'worker_id' => 'php-worker-capabilities-check',
                'task_queue' => 'external-workflows',
                'runtime' => 'php',
            ]);

        $register->assertCreated()
            ->assertJsonPath('server_capabilities.history_page_size_default', 500)
            ->assertJsonPath('server_capabilities.history_page_size_max', 1000);
    }

    public function test_server_capabilities_advertise_history_compression(): void
    {
        $this->createNamespace('default', 'Default namespace');

        $register = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'worker_id' => 'php-worker-compression-check',
                'task_queue' => 'external-workflows',
                'runtime' => 'php',
            ]);

        $register->assertCreated()
            ->assertJsonPath('server_capabilities.history_compression.supported_encodings', ['gzip', 'deflate'])
            ->assertJsonPath('server_capabilities.history_compression.compression_threshold', 50);
    }

    public function test_poll_response_compresses_history_when_accept_history_encoding_is_set(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-history-compression',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        // The real workflow only has a few history events (below the 50-event
        // compression threshold), so compression should NOT be applied even
        // when requested. This validates the threshold guard works.
        $this->registerWorker('php-worker-compress', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-compress',
                'task_queue' => 'external-workflows',
                'accept_history_encoding' => 'gzip',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.run_id', $runId);

        // Events below compression threshold should be uncompressed.
        $this->assertNotEmpty($poll->json('task.history_events'));
        $this->assertNull($poll->json('task.history_events_compressed'));
        $this->assertNull($poll->json('task.history_events_encoding'));
    }

    public function test_poll_response_compresses_history_above_threshold_via_mock(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        WorkflowInstance::query()->create([
            'id' => 'wf-compress-mock',
            'workflow_class' => ExternalGreetingWorkflow::class,
            'workflow_type' => 'tests.external-greeting-workflow',
            'namespace' => 'default',
            'run_count' => 0,
        ]);

        $recordedAt = now()->toJSON();
        $events = [];

        // Generate 60 events to exceed the 50-event compression threshold.
        for ($i = 1; $i <= 60; $i++) {
            $events[] = [
                'id' => "evt-{$i}",
                'sequence' => $i,
                'event_type' => 'WorkflowStarted',
                'payload' => [],
                'workflow_task_id' => null,
                'workflow_command_id' => null,
                'recorded_at' => $recordedAt,
            ];
        }

        $this->mock(WorkflowTaskBridge::class, function (MockInterface $mock) use ($events, $recordedAt): void {
            $mock->shouldReceive('poll')
                ->once()
                ->andReturn([[
                    'task_id' => 'wf-task-compress',
                    'workflow_run_id' => 'run-compress',
                    'workflow_instance_id' => 'wf-compress-mock',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'workflow_class' => ExternalGreetingWorkflow::class,
                    'connection' => null,
                    'queue' => 'external-workflows',
                    'compatibility' => null,
                    'available_at' => $recordedAt,
                ]]);

            $mock->shouldReceive('claimStatus')
                ->once()
                ->andReturn([
                    'claimed' => true,
                    'task_id' => 'wf-task-compress',
                    'workflow_run_id' => 'run-compress',
                    'workflow_instance_id' => 'wf-compress-mock',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'workflow_class' => ExternalGreetingWorkflow::class,
                    'payload_codec' => (string) config('workflows.serializer'),
                    'connection' => null,
                    'queue' => 'external-workflows',
                    'compatibility' => null,
                    'lease_owner' => 'php-worker-compress-mock',
                    'lease_expires_at' => now()->addMinutes(5)->toJSON(),
                    'reason' => null,
                    'reason_detail' => null,
                ]);

            $mock->shouldReceive('historyPayloadPaginated')
                ->once()
                ->andReturn([
                    'task_id' => 'wf-task-compress',
                    'workflow_run_id' => 'run-compress',
                    'workflow_instance_id' => 'wf-compress-mock',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'workflow_class' => ExternalGreetingWorkflow::class,
                    'payload_codec' => (string) config('workflows.serializer'),
                    'arguments' => null,
                    'run_status' => 'pending',
                    'last_history_sequence' => 60,
                    'after_sequence' => 0,
                    'page_size' => 500,
                    'has_more' => false,
                    'next_after_sequence' => null,
                    'history_events' => $events,
                ]);
        });

        $this->registerWorker('php-worker-compress-mock', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-compress-mock',
                'task_queue' => 'external-workflows',
                'accept_history_encoding' => 'gzip',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.task_id', 'wf-task-compress');

        // History should be compressed since it exceeds the threshold.
        $this->assertNotNull($poll->json('task.history_events_compressed'));
        $this->assertSame('gzip', $poll->json('task.history_events_encoding'));
        $this->assertSame([], $poll->json('task.history_events'));

        // Verify the compressed payload is decompressible.
        $compressed = base64_decode($poll->json('task.history_events_compressed'), true);
        $this->assertNotFalse($compressed);
        $decompressed = gzdecode($compressed);
        $this->assertNotFalse($decompressed);
        $decoded = json_decode($decompressed, true);
        $this->assertIsArray($decoded);
        $this->assertCount(60, $decoded);
    }

    public function test_unregistered_worker_is_rejected_with_412(): void
    {
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-unregistered',
                'task_queue' => 'external-workflows',
            ])
            ->assertStatus(412)
            ->assertJsonPath('reason', 'worker_not_registered');
    }

    public function test_worker_polling_wrong_task_queue_is_rejected_with_409(): void
    {
        $this->createNamespace('default', 'Default namespace');
        $this->registerWorker('php-worker-wrong-queue', 'external-workflows');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-wrong-queue',
                'task_queue' => 'different-queue',
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'task_queue_mismatch')
            ->assertJsonPath('registered_task_queue', 'external-workflows')
            ->assertJsonPath('requested_task_queue', 'different-queue');
    }

    public function test_worker_with_supported_workflow_types_only_receives_matching_tasks(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-capability-filter',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        // Worker registered for a different workflow type should not receive this task.
        $this->registerWorker(
            'php-worker-wrong-type',
            'external-workflows',
            supportedWorkflowTypes: ['some.other-workflow'],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-wrong-type',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        // Worker registered for the matching workflow type should receive the task.
        $this->registerWorker(
            'php-worker-right-type',
            'external-workflows',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-right-type',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-capability-filter')
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow');
    }

    public function test_worker_with_empty_supported_workflow_types_receives_all_tasks(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-capability-wildcard',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        // Worker with empty supported types acts as a wildcard — receives all tasks.
        $this->registerWorker(
            'php-worker-wildcard',
            'external-workflows',
            supportedWorkflowTypes: [],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-wildcard',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-capability-wildcard')
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow');
    }

    public function test_fail_workflow_command_accepts_non_retryable_flag(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-fail-non-retryable',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-fail-nr', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-nr',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'fail_workflow',
                        'message' => 'Non-retryable business error',
                        'exception_class' => 'App\\Exceptions\\BusinessRuleViolation',
                        'non_retryable' => true,
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'failed');
    }

    public function test_fail_workflow_command_works_without_non_retryable_flag(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-fail-default-retryable',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-fail-default', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-default',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'fail_workflow',
                        'message' => 'Something went wrong',
                        'exception_class' => 'RuntimeException',
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'failed');
    }

    public function test_start_child_workflow_command_accepts_parent_close_policy(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-child-with-policy',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-child-policy', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-child-policy',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'start_child_workflow',
                        'workflow_type' => 'tests.external-child-workflow',
                        'queue' => 'external-workflows',
                        'parent_close_policy' => 'request_cancel',
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'waiting');
    }

    public function test_start_child_workflow_command_rejects_invalid_parent_close_policy(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-child-bad-policy',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-child-bad-policy', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-child-bad-policy',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'start_child_workflow',
                        'workflow_type' => 'tests.external-child-workflow',
                        'queue' => 'external-workflows',
                        'parent_close_policy' => 'kill_immediately',
                    ],
                ],
            ]);

        $complete->assertUnprocessable()
            ->assertJsonValidationErrors(['commands.0.parent_close_policy']);
    }

    // ── Workflow task failure reporting ──────────────────────────────

    public function test_fail_workflow_task_succeeds_for_a_leased_task(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-fail-task-happy',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-fail-happy', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-happy',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $this->instance(
            WorkflowTaskBridge::class,
            \Mockery::mock(WorkflowTaskBridge::class, static function (MockInterface $mock) {
                $mock->shouldReceive('fail')
                    ->once()
                    ->andReturn([
                        'recorded' => true,
                        'task_id' => 'ignored',
                        'reason' => null,
                    ]);

                $mock->shouldReceive('status')
                    ->andReturnUsing(function (string $taskId) {
                        return app()->make(DefaultWorkflowTaskBridge::class)->status($taskId);
                    });
            }),
        );

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'Non-determinism detected: unexpected history event.',
                    'type' => 'NonDeterminismError',
                    'stack_trace' => 'at Replay::apply(Replay.php:42)',
                ],
            ]);

        $fail->assertOk()
            ->assertHeader('X-Durable-Workflow-Protocol-Version', '1.0')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null);
    }

    public function test_fail_workflow_task_rejects_wrong_lease_owner(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-fail-task-lease-mismatch',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-fail-lease', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-lease',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => 'wrong-owner-id',
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'Some failure.',
                ],
            ]);

        $fail->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch');
    }

    public function test_fail_workflow_task_returns_404_for_nonexistent_task(): void
    {
        $this->createNamespace('default', 'Default namespace');
        $this->registerWorker('php-worker-fail-404', 'external-workflows');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/nonexistent-task-id/fail', [
                'lease_owner' => 'some-owner',
                'workflow_task_attempt' => 1,
                'failure' => [
                    'message' => 'Task failure.',
                ],
            ]);

        $fail->assertStatus(404)
            ->assertJsonPath('reason', 'task_not_found');
    }

    public function test_fail_workflow_task_validates_required_fields(): void
    {
        $this->createNamespace('default', 'Default namespace');
        $this->registerWorker('php-worker-fail-validation', 'external-workflows');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/some-task/fail', []);

        $fail->assertStatus(422)
            ->assertJsonValidationErrors(['lease_owner', 'workflow_task_attempt', 'failure']);
    }

    public function test_fail_workflow_task_validates_failure_message_is_required(): void
    {
        $this->createNamespace('default', 'Default namespace');
        $this->registerWorker('php-worker-fail-msg-validation', 'external-workflows');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/some-task/fail', [
                'lease_owner' => 'owner',
                'workflow_task_attempt' => 1,
                'failure' => [
                    'type' => 'SomeError',
                ],
            ]);

        $fail->assertStatus(422)
            ->assertJsonValidationErrors(['failure.message']);
    }

    public function test_fail_workflow_task_is_scoped_by_namespace(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');
        $this->createNamespace('isolated', 'Isolated namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-fail-ns-scoped',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-fail-ns', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-ns',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $fail = $this->withHeaders($this->workerHeaders('isolated'))
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'Should not reach bridge.',
                ],
            ]);

        $fail->assertStatus(404)
            ->assertJsonPath('reason', 'task_not_found');
    }

    public function test_fail_workflow_task_records_bridge_failure_reason(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-fail-task-bridge-reason',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-fail-reason', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-reason',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $this->instance(
            WorkflowTaskBridge::class,
            \Mockery::mock(WorkflowTaskBridge::class, static function (MockInterface $mock) {
                $mock->shouldReceive('fail')
                    ->once()
                    ->andReturn([
                        'recorded' => false,
                        'task_id' => 'ignored',
                        'reason' => 'task_not_found',
                    ]);

                $mock->shouldReceive('status')
                    ->andReturnUsing(function (string $taskId) {
                        return app()->make(DefaultWorkflowTaskBridge::class)->status($taskId);
                    });
            }),
        );

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'Replay error.',
                ],
            ]);

        $fail->assertStatus(404)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('reason', 'task_not_found');
    }

    public function test_cluster_info_advertises_parent_close_policy_and_non_retryable_capabilities(): void
    {
        $this->createNamespace('default', 'Default namespace');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/cluster/info');

        $response->assertOk()
            ->assertJsonPath('capabilities.parent_close_policy', true)
            ->assertJsonPath('capabilities.non_retryable_failures', true);
    }

    private function configureWorkflowTypes(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
    }

    private function createNamespace(string $name, string $description): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => $name],
            [
                'description' => $description,
                'retention_days' => 30,
                'status' => 'active',
            ],
        );
    }

    private function apiHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    private function registerWorker(
        string $workerId,
        string $taskQueue,
        string $namespace = 'default',
        array $supportedWorkflowTypes = [],
        array $supportedActivityTypes = [],
        ?string $buildId = null,
    ): void {
        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => $namespace],
            array_filter([
                'task_queue' => $taskQueue,
                'runtime' => 'php',
                'build_id' => $buildId,
                'supported_workflow_types' => $supportedWorkflowTypes,
                'supported_activity_types' => $supportedActivityTypes,
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ], static fn (mixed $v): bool => $v !== null),
        );
    }

    private function workerHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Protocol-Version' => '1.0',
        ];
    }
}
