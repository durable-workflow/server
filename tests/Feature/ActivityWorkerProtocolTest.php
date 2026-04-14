<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\LongPollSignalStore;
use App\Support\LongPoller;
use App\Support\NamespaceWorkflowScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Fixtures\ExternalGreetingActivity;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\ActivityTaskBridge as ActivityTaskBridgeContract;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\WorkflowStub;

class ActivityWorkerProtocolTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_leases_and_completes_external_activity_tasks_with_namespaced_history_visibility(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            [
                'description' => 'Default namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-external-activity');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $describe = $this->withHeaders($this->workerHeaders())
            ->getJson("/api/workflows/{$workflow->id()}");

        $describe->assertOk()
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $start->runId())
            ->assertJsonPath('workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('input.0', 'Ada');

        $this->registerWorker('php-worker-1', 'external-activities');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-1',
                'task_queue' => 'external-activities',
            ]);

        $poll->assertOk()
            ->assertHeader('X-Durable-Workflow-Protocol-Version', '1.0')
            ->assertJsonPath('protocol_version', '1.0')
            ->assertJsonPath('server_capabilities.supported_workflow_task_commands.4', 'start_timer')
            ->assertJsonPath('task.workflow_id', $workflow->id())
            ->assertJsonPath('task.run_id', $start->runId())
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity');

        $taskId = (string) $poll->json('task.task_id');
        $attemptId = (string) $poll->json('task.activity_attempt_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $this->assertSame('php-worker-1', $leaseOwner);

        $heartbeat = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/activity-tasks/{$taskId}/heartbeat", [
                'activity_attempt_id' => $attemptId,
                'lease_owner' => $leaseOwner,
                'details' => [
                    'progress' => 50,
                ],
            ]);

        $heartbeat->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('lease_owner', $leaseOwner)
            ->assertJsonPath('cancel_requested', false)
            ->assertJsonPath('heartbeat_recorded', true);

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/activity-tasks/{$taskId}/complete", [
                'activity_attempt_id' => $attemptId,
                'lease_owner' => $leaseOwner,
                'result' => 'Hello, Ada!',
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true);

        $this->runWorkflowTask((string) $complete->json('next_task_id'));

        $showRun = $this->withHeaders($this->workerHeaders())
            ->getJson("/api/workflows/{$workflow->id()}/runs/{$start->runId()}");

        $showRun->assertOk()
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $start->runId())
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('output.greeting', 'Hello, Ada!');

        $runs = $this->withHeaders($this->workerHeaders())
            ->getJson("/api/workflows/{$workflow->id()}/runs");

        $runs->assertOk()
            ->assertJsonCount(1, 'runs')
            ->assertJsonPath('runs.0.run_id', $start->runId());

        $history = $this->withHeaders($this->workerHeaders())
            ->getJson("/api/workflows/{$workflow->id()}/runs/{$start->runId()}/history");

        $history->assertOk();

        $eventTypes = array_column($history->json('events'), 'event_type');

        $this->assertContains('ActivityStarted', $eventTypes);
        $this->assertContains('ActivityHeartbeatRecorded', $eventTypes);
        $this->assertContains('ActivityCompleted', $eventTypes);

        $export = $this->withHeaders($this->workerHeaders())
            ->getJson("/api/workflows/{$workflow->id()}/runs/{$start->runId()}/history/export");

        $export->assertOk()
            ->assertJsonPath('schema', 'durable-workflow.v2.history-export')
            ->assertJsonPath('workflow.instance_id', $workflow->id())
            ->assertJsonPath('workflow.run_id', $start->runId());
    }

    public function test_it_hides_workflows_and_activity_tasks_outside_the_resolved_namespace(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            [
                'description' => 'Default namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'other'],
            [
                'description' => 'Other namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-hidden-across-namespace');
        $start = $workflow->start('Grace');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $this->withHeaders($this->workerHeaders(namespace: 'other'))
            ->getJson("/api/workflows/{$workflow->id()}")
            ->assertNotFound();

        $this->registerWorker('php-worker-2', 'external-activities', 'other');

        $this->withHeaders($this->workerHeaders(namespace: 'other'))
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-2',
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_it_passes_the_next_visible_activity_task_deadline_into_long_polling(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            [
                'description' => 'Default namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-next-probe');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $futureAvailableAt = now()->addMinutes(2)->startOfSecond();

        WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', 'activity')
            ->firstOrFail()
            ->forceFill([
                'available_at' => $futureAvailableAt,
            ])->save();

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        $expectedChannels = $signals->activityTaskPollChannels('default', null, 'external-activities');

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

        $this->registerWorker('php-activity-worker-next-probe', 'external-activities');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-worker-next-probe',
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_it_uses_the_bridge_poll_contract_for_activity_task_discovery(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            [
                'description' => 'Default namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-bridge-activity-poll');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', 'activity')
            ->firstOrFail();

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $start->runId())
            ->firstOrFail();

        $leaseExpiresAt = now()->addMinutes(5)->toJSON();
        $recordedAt = now()->toJSON();

        $this->mock(ActivityTaskBridgeContract::class, function (MockInterface $mock) use (
            $execution,
            $leaseExpiresAt,
            $recordedAt,
            $start,
            $task,
            $workflow,
        ): void {
            $mock->shouldReceive('poll')
                ->once()
                ->with(null, 'external-activities', 10, null, 'default')
                ->andReturn([
                    [
                        'task_id' => $task->id,
                        'workflow_run_id' => $start->runId(),
                        'workflow_instance_id' => $workflow->id(),
                        'activity_execution_id' => $execution->id,
                        'activity_type' => 'tests.external-greeting-activity',
                        'activity_class' => ExternalGreetingActivity::class,
                        'connection' => null,
                        'queue' => 'external-activities',
                        'compatibility' => null,
                        'available_at' => $recordedAt,
                    ],
                ]);

            $mock->shouldReceive('claimStatus')
                ->once()
                ->with($task->id, 'php-activity-worker-bridge')
                ->andReturn([
                    'claimed' => true,
                    'task_id' => $task->id,
                    'workflow_instance_id' => $workflow->id(),
                    'workflow_run_id' => $start->runId(),
                    'activity_execution_id' => $execution->id,
                    'activity_attempt_id' => 'attempt-bridge-1',
                    'attempt_number' => 1,
                    'activity_type' => 'tests.external-greeting-activity',
                    'activity_class' => ExternalGreetingActivity::class,
                    'idempotency_key' => $execution->id,
                    'payload_codec' => (string) config('workflows.serializer'),
                    'arguments' => $execution->arguments,
                    'retry_policy' => null,
                    'connection' => null,
                    'queue' => 'external-activities',
                    'lease_owner' => 'php-activity-worker-bridge',
                    'lease_expires_at' => $leaseExpiresAt,
                    'reason' => null,
                    'reason_detail' => null,
                    'retry_after_seconds' => null,
                    'backend_error' => null,
                    'compatibility_reason' => null,
                ]);
        });

        $this->registerWorker('php-activity-worker-bridge', 'external-activities');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-worker-bridge',
                'task_queue' => 'external-activities',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.task_id', $task->id)
            ->assertJsonPath('task.workflow_id', $workflow->id())
            ->assertJsonPath('task.run_id', $start->runId())
            ->assertJsonPath('task.activity_execution_id', $execution->id)
            ->assertJsonPath('task.activity_attempt_id', 'attempt-bridge-1')
            ->assertJsonPath('task.attempt_number', 1)
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.lease_owner', 'php-activity-worker-bridge')
            ->assertJsonPath('task.lease_expires_at', $leaseExpiresAt);
    }

    public function test_it_does_not_fall_back_to_a_local_ready_scan_when_the_activity_bridge_returns_no_tasks(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            [
                'description' => 'Default namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-bridge-only-poll');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $this->mock(ActivityTaskBridgeContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('poll')
                ->once()
                ->with(null, 'external-activities', 10, null, 'default')
                ->andReturn([]);
        });

        $this->registerWorker('php-activity-worker-bridge-only', 'external-activities');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-worker-bridge-only',
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_unregistered_worker_is_rejected_when_polling_activity_tasks(): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-unregistered',
                'task_queue' => 'external-activities',
            ])
            ->assertStatus(412)
            ->assertJsonPath('reason', 'worker_not_registered');
    }

    public function test_worker_with_supported_activity_types_only_receives_matching_tasks(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-capability-filter');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        // Worker registered for a different activity type should not receive this task.
        $this->registerWorker(
            'php-activity-wrong-type',
            'external-activities',
            supportedActivityTypes: ['some.other-activity'],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-wrong-type',
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        // Worker registered for the matching activity type should receive the task.
        $this->registerWorker(
            'php-activity-right-type',
            'external-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-right-type',
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', $workflow->id())
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity');
    }

    // ── Activity task failure reporting ──────────────────────────────

    public function test_fail_activity_task_succeeds_for_a_leased_task(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-fail-happy');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $this->registerWorker('php-worker-fail-activity', 'external-activities');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-fail-activity',
                'task_queue' => 'external-activities',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attemptId = (string) $poll->json('task.activity_attempt_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $this->instance(
            ActivityTaskBridgeContract::class,
            \Mockery::mock(ActivityTaskBridgeContract::class, static function (MockInterface $mock) {
                $mock->shouldReceive('status')
                    ->andReturnUsing(static fn (string $id) => [
                        'reason' => null,
                        'workflow_task_id' => WorkflowTask::query()
                            ->where('task_type', 'activity')
                            ->orderByDesc('id')
                            ->value('id'),
                        'lease_owner' => 'php-worker-fail-activity',
                    ]);

                $mock->shouldReceive('fail')
                    ->once()
                    ->andReturn([
                        'recorded' => true,
                        'task_id' => 'ignored',
                        'reason' => null,
                        'next_task_id' => null,
                    ]);
            }),
        );

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/activity-tasks/{$taskId}/fail", [
                'activity_attempt_id' => $attemptId,
                'lease_owner' => $leaseOwner,
                'failure' => [
                    'message' => 'Connection timeout calling external service.',
                    'type' => 'TimeoutException',
                    'stack_trace' => 'at HttpClient::send(Client.php:120)',
                    'non_retryable' => false,
                ],
            ]);

        $fail->assertOk()
            ->assertHeader('X-Durable-Workflow-Protocol-Version', '1.0')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null);
    }

    public function test_fail_activity_task_returns_404_for_nonexistent_task(): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $this->registerWorker('php-worker-fail-404', 'external-activities');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/nonexistent-task-id/fail', [
                'activity_attempt_id' => 'nonexistent-attempt',
                'lease_owner' => 'php-worker-fail-404',
                'failure' => [
                    'message' => 'Should 404.',
                ],
            ]);

        $fail->assertStatus(404)
            ->assertJsonPath('reason', 'task_not_found');
    }

    public function test_fail_activity_task_validates_required_fields(): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/some-task/fail', []);

        $fail->assertStatus(422)
            ->assertJsonValidationErrors(['activity_attempt_id', 'lease_owner', 'failure']);
    }

    public function test_fail_activity_task_validates_failure_message_is_required(): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/some-task/fail', [
                'activity_attempt_id' => 'attempt-1',
                'lease_owner' => 'worker-1',
                'failure' => [
                    'type' => 'SomeError',
                ],
            ]);

        $fail->assertStatus(422)
            ->assertJsonValidationErrors(['failure.message']);
    }

    public function test_fail_activity_task_is_scoped_by_namespace(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'isolated'],
            ['description' => 'Isolated namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-fail-ns');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $this->registerWorker('php-worker-fail-ns', 'external-activities');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-fail-ns',
                'task_queue' => 'external-activities',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attemptId = (string) $poll->json('task.activity_attempt_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $fail = $this->withHeaders($this->workerHeaders('isolated'))
            ->postJson("/api/worker/activity-tasks/{$taskId}/fail", [
                'activity_attempt_id' => $attemptId,
                'lease_owner' => $leaseOwner,
                'failure' => [
                    'message' => 'Should not reach bridge.',
                ],
            ]);

        $fail->assertStatus(404)
            ->assertJsonPath('reason', 'task_not_found');
    }

    private function registerWorker(
        string $workerId,
        string $taskQueue,
        string $namespace = 'default',
        array $supportedWorkflowTypes = [],
        array $supportedActivityTypes = [],
    ): void {
        \App\Models\WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => $namespace],
            [
                'task_queue' => $taskQueue,
                'runtime' => 'php',
                'supported_workflow_types' => $supportedWorkflowTypes,
                'supported_activity_types' => $supportedActivityTypes,
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
    }

    private function workerHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
            'X-Durable-Workflow-Protocol-Version' => '1.0',
        ];
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        $taskId = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->orderBy('available_at')
            ->value('id');

        $this->assertIsString($taskId);

        $this->runWorkflowTask($taskId);
    }

    private function runWorkflowTask(string $taskId): void
    {
        $job = new RunWorkflowTask($taskId);
        $job->handle(app(WorkflowExecutor::class));
    }
}
