<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\LongPoller;
use App\Support\LongPollSignalStore;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Fixtures\ExternalGreetingActivity;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ActivityTaskBridge as ActivityTaskBridgeContract;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\WorkflowStub;

class ActivityWorkerProtocolTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $externalExecutorConfigFixturePaths = [];

    protected function tearDown(): void
    {
        foreach ($this->externalExecutorConfigFixturePaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->externalExecutorConfigFixturePaths = [];

        parent::tearDown();
    }

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
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath(
                'server_capabilities.supported_workflow_task_commands',
                WorkerProtocol::supportedWorkflowTaskCommands(),
            )
            ->assertJsonPath('task.workflow_id', $workflow->id())
            ->assertJsonPath('task.run_id', $start->runId())
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity');

        $this->assertSame(
            $poll->json('task.activity_execution_id'),
            $poll->json('task.idempotency_key'),
            'activity poll responses must expose the stable activity execution id as the idempotency key',
        );

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
        $expectedChannels = [
            ...$signals->activityTaskPollChannels('default', null, 'external-activities'),
            ...$signals->queryTaskPollChannels('default', 'external-activities'),
        ];

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
                ->with(null, 'external-activities', 10, null, 'default', ['tests.external-greeting-activity'])
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
            ->assertJsonPath('task.idempotency_key', $execution->id)
            ->assertJsonPath('task.attempt_number', 1)
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.lease_owner', 'php-activity-worker-bridge')
            ->assertJsonPath('task.lease_expires_at', $leaseExpiresAt);
    }

    public function test_activity_poll_uses_bridge_as_the_only_ready_task_source(): void
    {
        // Source-of-truth contract: the package bridge owns ready-task
        // discovery, including activity_type filtering. If it returns no
        // candidates, the server must not run its own workflow_tasks to
        // activity_executions query as a second predicate source.
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            [
                'description' => 'Default namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-bridge-only-source');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $this->mock(ActivityTaskBridgeContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('poll')
                ->once()
                ->with(null, 'external-activities', 10, null, 'default', ['tests.external-greeting-activity'])
                ->andReturn([]);

            $mock->shouldNotReceive('claimStatus');
        });

        $this->registerWorker(
            'php-activity-worker-bridge-only-source',
            'external-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-worker-bridge-only-source',
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_activity_poll_drops_bridge_candidates_outside_registered_types_without_local_dispatch_query(): void
    {
        // The bridge is the only source of ready-task candidates. The
        // server still guards against a polluted bridge response before
        // claiming, but it must not compensate by materialising a
        // second local dispatch query from workflow_tasks/activity_executions.
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-disjoint-types-routing');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', TaskType::Activity->value)
            ->firstOrFail();

        $taskQueue = is_string($task->queue) && $task->queue !== ''
            ? $task->queue
            : 'external-activities';

        $this->mock(ActivityTaskBridgeContract::class, function (MockInterface $mock) use ($taskQueue): void {
            $mock->shouldReceive('poll')
                ->twice()
                ->andReturn([[
                    'task_id' => 'phantom-activity-bridge-task',
                    'workflow_run_id' => 'phantom-activity-bridge-run',
                    'workflow_instance_id' => 'phantom-activity-bridge-instance',
                    'activity_execution_id' => 'phantom-activity-bridge-execution',
                    'activity_type' => 'phantom.unrelated-activity-type',
                    'activity_class' => null,
                    'connection' => null,
                    'queue' => $taskQueue,
                    'compatibility' => null,
                    'available_at' => now()->subSecond()->toJSON(),
                    'priority' => 5,
                    'fairness_key' => null,
                    'fairness_weight' => 1,
                ]]);

            $mock->shouldNotReceive('claimStatus');
        });

        $this->registerWorker(
            'php-activity-worker-with-matching-type',
            $taskQueue,
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $this->registerWorker(
            'php-activity-worker-with-disjoint-type',
            $taskQueue,
            supportedActivityTypes: ['tests.disjoint-other-activity'],
        );

        // Worker registered for a disjoint activity type must come back
        // empty: the only ready activity task on the queue is for a
        // type the disjoint-typed worker did not register for, and the
        // bridge phantom carries a type neither worker registered for.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-worker-with-disjoint-type',
                'task_queue' => $taskQueue,
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        // Even the worker registered for the execution's stored
        // activity_type comes back empty if the bridge did not surface
        // that task. The real bridge's typed poll contract is covered
        // separately by package-level bridge tests; this test prevents
        // a second server-owned predicate from returning.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-worker-with-matching-type',
                'task_queue' => $taskQueue,
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

    public function test_worker_with_empty_supported_activity_types_receives_no_activity_tasks(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-no-capability');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        // A worker that advertised no activity types at register time is
        // not an activity worker — registered capabilities are
        // authoritative for routing, so the server short-circuits the poll
        // instead of dispatching activity tasks to a worker that cannot
        // run them.
        $this->registerWorker(
            'php-workflow-only-on-activity-queue',
            'external-activities',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
            supportedActivityTypes: [],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-workflow-only-on-activity-queue',
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'no_activity_capability');
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
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');

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

    public function test_activity_worker_skips_bridge_returned_task_with_unregistered_activity_type(): void
    {
        // Defense-in-depth contract: the bridge poll filters by
        // activity_type at the SQL level, but the server's claim loop
        // must independently re-check the execution's stored
        // activity_type against the worker's registered list before
        // claiming. If the bridge ever returned a task whose
        // activity_type is not in the worker's
        // supported_activity_types — because of a stale index, a
        // relaxed predicate, or a future bridge change — the server
        // must still refuse to lease it. This is the activity-side
        // counterpart of the polyglot Scenario 2 guard: it keeps the
        // polyglot two-worker shape correct even if the bridge filter
        // ever loosens.
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-defense-in-depth');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', TaskType::Activity->value)
            ->firstOrFail();

        $executionId = is_array($task->payload ?? null)
            ? ($task->payload['activity_execution_id'] ?? null)
            : null;

        // Simulate a bridge poll that returns the activity task even
        // though its activity_type is not in the requesting worker's
        // registered list.
        $this->mock(ActivityTaskBridgeContract::class, function (MockInterface $mock) use (
            $task,
            $start,
            $workflow,
            $executionId,
        ): void {
            $mock->shouldReceive('poll')
                ->andReturn([[
                    'task_id' => $task->id,
                    'workflow_run_id' => $start->runId(),
                    'workflow_instance_id' => $workflow->id(),
                    'activity_execution_id' => $executionId,
                    'activity_type' => 'tests.external-greeting-activity',
                    'activity_class' => 'tests.external-greeting-activity',
                    'connection' => null,
                    'queue' => 'external-activities',
                    'compatibility' => null,
                    'available_at' => now()->subSecond()->toJSON(),
                ]]);
        });

        $this->registerWorker(
            'php-activity-strict-types',
            'external-activities',
            supportedActivityTypes: ['some.unrelated-activity'],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-strict-types',
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_activity_poll_response_includes_matching_external_executor_config_mapping(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $this->useExternalExecutorConfigFixture([
            'schema' => 'durable-workflow.external-executor.config',
            'version' => 1,
            'defaults' => [
                'task_queue' => 'external-activities',
                'auth_ref' => 'ops-profile',
            ],
            'auth_refs' => [
                'ops-profile' => [
                    'type' => 'profile',
                    'profile' => 'production',
                    'token' => 'must-not-leak',
                ],
            ],
            'carriers' => [
                'artisan-operator' => [
                    'type' => 'invocable_http',
                    'url' => 'https://handlers.example.test/durable/activity',
                    'method' => 'POST',
                    'timeout_seconds' => 45,
                    'retry_policy' => [
                        'max_attempts' => 3,
                        'backoff_seconds' => [1, 3],
                        'retryable_status_codes' => [408, 429, 503],
                    ],
                    'secret' => 'must-not-leak',
                    'capabilities' => ['activity_task'],
                ],
            ],
            'mappings' => [
                [
                    'name' => 'greeting.external',
                    'kind' => 'activity',
                    'activity_type' => 'tests.external-greeting-activity',
                    'carrier' => 'artisan-operator',
                    'handler' => 'App\\Durable\\Handlers\\Greeting',
                ],
            ],
        ]);

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-config-mapping');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $this->registerWorker(
            'php-activity-config-mapping',
            'external-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-config-mapping',
                'task_queue' => 'external-activities',
            ]);

        $activityAttemptId = (string) $poll->json('task.activity_attempt_id');
        $taskId = (string) $poll->json('task.task_id');

        $poll->assertOk()
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.external_executor.schema', 'durable-workflow.external-executor.config.mapping')
            ->assertJsonPath('task.external_executor.name', 'greeting.external')
            ->assertJsonPath('task.external_executor.task_queue', 'external-activities')
            ->assertJsonPath('task.external_executor.handler', 'App\\Durable\\Handlers\\Greeting')
            ->assertJsonPath('task.external_executor.auth_ref', 'ops-profile')
            ->assertJsonPath('task.external_executor.auth.token', 'redacted')
            ->assertJsonPath('task.external_executor.carrier.name', 'artisan-operator')
            ->assertJsonPath('task.external_executor.carrier.type', 'invocable_http')
            ->assertJsonPath('task.external_executor.carrier.target.url', 'https://handlers.example.test/durable/activity')
            ->assertJsonPath('task.external_executor.carrier.target.secret', 'redacted')
            ->assertJsonPath('task.external_executor.dispatch.state', 'poll_delivered')
            ->assertJsonPath('task.external_executor.dispatch.carrier_type', 'invocable_http')
            ->assertJsonPath('task.external_executor.dispatch.method', 'POST')
            ->assertJsonPath('task.external_executor.dispatch.timeout_seconds', 45)
            ->assertJsonPath(
                'task.external_executor.dispatch.request_content_type',
                'application/vnd.durable-workflow.external-task-input+json',
            )
            ->assertJsonPath(
                'task.external_executor.dispatch.response_content_type',
                'application/vnd.durable-workflow.external-task-result+json',
            )
            ->assertJsonPath('task.external_executor.dispatch.idempotency_key', $activityAttemptId)
            ->assertJsonPath('task.external_executor.dispatch.idempotency_key_source', 'task.activity_attempt_id')
            ->assertJsonPath('task.external_executor.dispatch.transport_retry_policy.max_attempts', 3)
            ->assertJsonPath('task.external_executor.dispatch.transport_retry_policy.backoff_seconds', [1, 3])
            ->assertJsonPath('task.external_executor.dispatch.transport_retry_policy.retryable_status_codes', [408, 429, 503])
            ->assertJsonPath('task.external_executor.dispatch.transport_retry_policy.authority', 'carrier_transport_only')
            ->assertJsonPath(
                'task.external_executor.dispatch.transport_retry_policy.durable_retry_boundary',
                'activity_retry_policy_after_result_reporting',
            )
            ->assertJsonPath(
                'task.external_executor.dispatch.failure_mapping.transport_timeout',
                'failure.kind=timeout classification=deadline_exceeded',
            )
            ->assertJsonPath(
                'task.external_executor.dispatch.result_reporting.complete_path',
                "/api/worker/activity-tasks/{$taskId}/complete",
            )
            ->assertJsonPath('task.external_executor.dispatch.result_reporting.ownership_fields.0', 'activity_attempt_id')
            ->assertJsonPath('task.external_executor.dispatch.result_reporting.ownership_fields.1', 'lease_owner');
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
                    ->withArgs(static function (string $attemptId, array $failure, ?string $codec) {
                        return $attemptId !== ''
                            && $codec === null
                            && ($failure['message'] ?? null) === 'Connection timeout calling external service.'
                            && ($failure['type'] ?? null) === 'TimeoutException'
                            && ($failure['class'] ?? null) === 'App\\Activities\\TimeoutActivity'
                            && ($failure['kind'] ?? null) === 'timeout'
                            && ($failure['retryable'] ?? null) === true
                            && ($failure['non_retryable'] ?? null) === false
                            && ($failure['timeout_type'] ?? null) === 'start_to_close'
                            && ($failure['cancelled'] ?? null) === false
                            && ($failure['malformed_output'] ?? null) === false;
                    })
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
                    'class' => 'App\\Activities\\TimeoutActivity',
                    'stack_trace' => 'at HttpClient::send(Client.php:120)',
                    'kind' => 'timeout',
                    'retryable' => true,
                    'non_retryable' => false,
                    'timeout_type' => 'start_to_close',
                    'cancelled' => false,
                    'malformed_output' => false,
                ],
            ]);

        $fail->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null);
    }

    public function test_fail_activity_task_threads_codec_from_details_envelope(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-fail-codec');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $this->registerWorker('php-worker-fail-codec', 'external-activities');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-fail-codec',
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
                        'lease_owner' => 'php-worker-fail-codec',
                    ]);

                $mock->shouldReceive('fail')
                    ->once()
                    ->withArgs(function (string $attemptId, array $failure, ?string $codec) {
                        return $codec === 'avro'
                            && ($failure['details'] ?? null) === Serializer::serializeWithCodec('avro', ['retry_after' => 30])
                            && ($failure['details_payload_codec'] ?? null) === 'avro'
                            && ($failure['runtime_diagnostics']['class'] ?? null) === 'App\\Activities\\TimeoutActivity'
                            && ($failure['runtime_diagnostics']['file'] ?? null) === '/app/src/TimeoutActivity.php';
                    })
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
                    'message' => 'Connection timeout.',
                    'type' => 'TimeoutException',
                    'details' => [
                        'codec' => 'avro',
                        'blob' => Serializer::serializeWithCodec('avro', ['retry_after' => 30]),
                    ],
                    'runtime_diagnostics' => [
                        'class' => 'App\\Activities\\TimeoutActivity',
                        'file' => '/app/src/TimeoutActivity.php',
                    ],
                ],
            ]);

        $fail->assertOk()
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true);
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

    public function test_fail_activity_task_validates_structured_failure_classification(): void
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
                    'message' => 'Malformed carrier output.',
                    'kind' => 'unknown_kind',
                    'timeout_type' => 'forever',
                ],
            ]);

        $fail->assertStatus(422)
            ->assertJsonValidationErrors(['failure.kind', 'failure.timeout_type']);
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

    /**
     * @param  array<string>|null  $supportedWorkflowTypes
     * @param  array<string>|null  $supportedActivityTypes
     */
    private function registerWorker(
        string $workerId,
        string $taskQueue,
        string $namespace = 'default',
        ?array $supportedWorkflowTypes = null,
        ?array $supportedActivityTypes = null,
    ): void {
        // Default to declaring the activity types this test suite drives so
        // tests that don't care about capability filtering still receive
        // activity tasks under the registered-capability-authoritative
        // routing rule. Tests asserting the no-capability path pass an
        // explicit [] for supportedActivityTypes.
        $supportedWorkflowTypes ??= ['tests.external-greeting-workflow'];
        $supportedActivityTypes ??= ['tests.external-greeting-activity'];

        WorkerRegistration::query()->updateOrCreate(
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

    /**
     * @param  array<string, mixed>  $document
     */
    private function useExternalExecutorConfigFixture(array $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dw-executor-config-');

        if ($path === false) {
            $this->fail('Could not allocate a tempfile for the external executor config fixture.');
        }

        $contents = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($contents)) {
            $this->fail('Could not encode the external executor config fixture.');
        }

        file_put_contents($path, $contents);
        config(['server.external_executor.config_path' => $path]);

        $this->externalExecutorConfigFixturePaths[] = $path;

        return $path;
    }

    private function workerHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
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
