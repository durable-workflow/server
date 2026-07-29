<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\ActivityTaskPollRequestStore;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\ActivityTaskBridge as ActivityTaskBridgeContract;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\WorkflowStub;

class ActivityTimeoutTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    // ── Activity Timeout Status Endpoint ────────────────────────────

    public function test_activity_timeout_status_returns_empty_when_no_expired(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/activity-timeouts')
            ->assertOk()
            ->assertJsonPath('expired_count', 0)
            ->assertJsonPath('expired_execution_ids', [])
            ->assertJsonPath('scan_limit', 100)
            ->assertJsonPath('scan_pressure', false);
    }

    public function test_activity_timeout_status_detects_expired_executions(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $this->setUpExpiredActivity();

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/activity-timeouts');

        $response->assertOk()
            ->assertJsonPath('expired_count', 1)
            ->assertJsonPath('scan_pressure', false);

        $this->assertCount(1, $response->json('expired_execution_ids'));
    }

    public function test_activity_timeout_status_respects_limit_query(): void
    {
        Queue::fake();

        $this->setUpExpiredActivity();
        $this->setUpExpiredActivity();

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/activity-timeouts?limit=1');

        $response->assertOk()
            ->assertJsonPath('expired_count', 1)
            ->assertJsonPath('scan_limit', 1)
            ->assertJsonPath('scan_pressure', true);

        $this->assertCount(1, $response->json('expired_execution_ids'));
    }

    // ── Activity Timeout Enforce Pass Endpoint ──────────────────────

    public function test_activity_timeout_enforce_pass_with_no_expired(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/activity-timeouts/pass')
            ->assertOk()
            ->assertJsonPath('processed', 0)
            ->assertJsonPath('enforced', 0)
            ->assertJsonPath('skipped', 0)
            ->assertJsonPath('failed', 0)
            ->assertJsonPath('results', []);
    }

    public function test_activity_timeout_enforce_pass_enforces_expired_execution(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $executionId = $this->setUpExpiredActivity();

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/activity-timeouts/pass');

        $response->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('enforced', 1)
            ->assertJsonPath('skipped', 0)
            ->assertJsonPath('failed', 0);

        $results = $response->json('results');
        $this->assertCount(1, $results);
        $this->assertSame($executionId, $results[0]['execution_id']);
        $this->assertSame('enforced', $results[0]['outcome']);
    }

    public function test_activity_timeout_enforce_pass_with_specific_execution_ids(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/activity-timeouts/pass', [
                'execution_ids' => ['non-existent-id'],
            ])
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('enforced', 0)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('results.0.outcome', 'skipped')
            ->assertJsonPath('results.0.reason', 'execution_not_found');
    }

    public function test_accepted_heartbeat_fences_expired_scanner_snapshot(): void
    {
        Queue::fake();

        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        $lease = $this->leaseHeartbeatActivity('wf-heartbeat-race', 'heartbeat-race-worker', 10);
        $this->assertSame(
            $startedAt->copy()->addSeconds(10)->toIso8601String(),
            $lease['execution']->fresh()->heartbeat_deadline_at?->toIso8601String(),
        );

        Carbon::setTestNow($startedAt->copy()->addSeconds(11));

        $snapshot = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/activity-timeouts')
            ->assertOk();
        $this->assertContains($lease['execution']->id, $snapshot->json('expired_execution_ids'));

        $this->mock(ActivityTaskBridgeContract::class, function (MockInterface $mock) use ($lease): void {
            $mock->shouldReceive('status')
                ->once()
                ->with($lease['attempt_id'])
                ->andReturn([
                    'workflow_task_id' => $lease['task_id'],
                    'lease_owner' => $lease['lease_owner'],
                    'reason' => null,
                ]);

            $mock->shouldReceive('heartbeat')
                ->once()
                ->with($lease['attempt_id'], ['message' => 'still healthy'])
                ->andReturnUsing(function () use ($lease): array {
                    $heartbeatAt = now();
                    $execution = $lease['execution']->fresh();
                    $execution->forceFill([
                        'last_heartbeat_at' => $heartbeatAt,
                    ])->save();

                    return [
                        'activity_execution_id' => $execution->id,
                        'activity_attempt_id' => $lease['attempt_id'],
                        'lease_owner' => $lease['lease_owner'],
                        'cancel_requested' => false,
                        'can_continue' => true,
                        'reason' => null,
                        'run_closed_reason' => null,
                        'run_closed_at' => null,
                        'heartbeat_recorded' => true,
                        'lease_expires_at' => $heartbeatAt->copy()->addMinute()->toJSON(),
                        'last_heartbeat_at' => $heartbeatAt->toJSON(),
                    ];
                });
        });

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/activity-tasks/{$lease['task_id']}/heartbeat", [
                'activity_attempt_id' => $lease['attempt_id'],
                'lease_owner' => $lease['lease_owner'],
                'message' => 'still healthy',
            ])
            ->assertOk()
            ->assertJsonPath('can_continue', true)
            ->assertJsonPath('heartbeat_recorded', true);

        $this->assertSame(
            $startedAt->copy()->addSeconds(21)->toIso8601String(),
            $lease['execution']->fresh()->heartbeat_deadline_at?->toIso8601String(),
        );

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/activity-timeouts/pass', [
                'execution_ids' => [$lease['execution']->id],
            ])
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('enforced', 0)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('results.0.reason', 'no_deadline_expired');

        $this->assertSame(
            0,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $lease['run_id'])
                ->where('event_type', HistoryEventType::ActivityTimedOut->value)
                ->count(),
        );

        Carbon::setTestNow();
    }

    public function test_frequent_heartbeats_survive_multiple_timeout_scanner_intervals(): void
    {
        Queue::fake();

        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        $lease = $this->leaseHeartbeatActivity('wf-heartbeat-cadence', 'heartbeat-cadence-worker', 3);

        foreach ([1, 2, 3, 4, 5] as $offset) {
            Carbon::setTestNow($startedAt->copy()->addSeconds($offset));

            $this->withHeaders($this->workerHeaders())
                ->postJson("/api/worker/activity-tasks/{$lease['task_id']}/heartbeat", [
                    'activity_attempt_id' => $lease['attempt_id'],
                    'lease_owner' => $lease['lease_owner'],
                    'current' => $offset,
                    'total' => 5,
                ])
                ->assertOk()
                ->assertJsonPath('can_continue', true)
                ->assertJsonPath('heartbeat_recorded', true);

            $this->withHeaders($this->apiHeaders())
                ->postJson('/api/system/activity-timeouts/pass')
                ->assertOk()
                ->assertJsonPath('processed', 0)
                ->assertJsonPath('enforced', 0);
        }

        $execution = $lease['execution']->fresh();
        $this->assertSame(ActivityStatus::Running, $execution->status);
        $this->assertSame(
            $startedAt->copy()->addSeconds(8)->toIso8601String(),
            $execution->heartbeat_deadline_at?->toIso8601String(),
        );
        $this->assertSame(
            5,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $lease['run_id'])
                ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded->value)
                ->count(),
        );
        $this->assertSame(
            0,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $lease['run_id'])
                ->where('event_type', HistoryEventType::ActivityTimedOut->value)
                ->count(),
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/activity-tasks/{$lease['task_id']}/complete", [
                'activity_attempt_id' => $lease['attempt_id'],
                'lease_owner' => $lease['lease_owner'],
                'result' => 'healthy result',
            ])
            ->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null);

        Carbon::setTestNow();
    }

    public function test_genuinely_expired_attempt_keeps_existing_worker_fencing(): void
    {
        Queue::fake();

        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        $lease = $this->leaseHeartbeatActivity('wf-heartbeat-expired', 'heartbeat-expired-worker', 2);

        Carbon::setTestNow($startedAt->copy()->addSeconds(3));

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/activity-timeouts/pass')
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('enforced', 1);

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/activity-tasks/{$lease['task_id']}/heartbeat", [
                'activity_attempt_id' => $lease['attempt_id'],
                'lease_owner' => $lease['lease_owner'],
            ])
            ->assertOk()
            ->assertJsonPath('can_continue', false)
            ->assertJsonPath('heartbeat_recorded', false)
            ->assertJsonPath('reason', 'attempt_closed');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/activity-tasks/{$lease['task_id']}/complete", [
                'activity_attempt_id' => $lease['attempt_id'],
                'lease_owner' => $lease['lease_owner'],
                'result' => 'too late',
            ])
            ->assertConflict()
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('reason', 'stale_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/activity-tasks/{$lease['task_id']}/fail", [
                'activity_attempt_id' => $lease['attempt_id'],
                'lease_owner' => $lease['lease_owner'],
                'failure' => [
                    'message' => 'too late',
                ],
            ])
            ->assertConflict()
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('reason', 'stale_attempt');

        $this->assertSame(
            1,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $lease['run_id'])
                ->where('event_type', HistoryEventType::ActivityTimedOut->value)
                ->count(),
        );

        Carbon::setTestNow();
    }

    // ── Activity Timeout Enforce Artisan Command ────────────────────

    public function test_artisan_command_reports_no_expired_executions(): void
    {
        $this->artisan('activity:timeout-enforce')
            ->assertExitCode(0)
            ->expectsOutputToContain('No expired activity executions.');
    }

    public function test_artisan_command_enforces_expired_executions(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $this->setUpExpiredActivity();

        $this->artisan('activity:timeout-enforce')
            ->assertExitCode(0)
            ->expectsOutputToContain('Enforcing 1 expired activity execution(s)...')
            ->expectsOutputToContain('Done: 1 enforced, 0 skipped, 0 failed.');
    }

    public function test_artisan_command_respects_limit_option(): void
    {
        Queue::fake();

        $this->createNamespace('default');
        $this->setUpExpiredActivity();

        $this->artisan('activity:timeout-enforce', ['--limit' => 0])
            ->assertExitCode(0);
    }

    // ── Activity Poll Response Deadline Surfacing ────────────────────

    public function test_activity_poll_response_includes_deadlines_when_set(): void
    {
        Queue::fake();

        $this->createNamespace('default');

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-deadline-poll');
        $start = $workflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($start->runId());

        // Set schedule_to_close deadline and a start_to_close_timeout in the
        // retry policy BEFORE the claim. The claimer reads start_to_close_timeout
        // from the retry_policy and sets close_deadline_at during claim.
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $start->runId())
            ->firstOrFail();

        $scheduleToClose = now()->addHour();

        $execution->forceFill([
            'schedule_to_close_deadline_at' => $scheduleToClose,
            'retry_policy' => array_merge(
                is_array($execution->retry_policy) ? $execution->retry_policy : [],
                ['start_to_close_timeout' => 1800],
            ),
        ])->save();

        $this->registerWorker('deadline-worker', 'external-activities');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'deadline-worker',
                'task_queue' => 'external-activities',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflow->id())
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity');

        $deadlines = $poll->json('task.deadlines');
        $this->assertIsArray($deadlines);
        $this->assertArrayHasKey('schedule_to_close', $deadlines);
        $this->assertArrayHasKey('start_to_close', $deadlines);
        $this->assertArrayNotHasKey('schedule_to_start', $deadlines);
        $this->assertArrayNotHasKey('heartbeat', $deadlines);
    }

    public function test_activity_redelivery_preserves_deadlines_without_new_attempt_or_dispatch(): void
    {
        Queue::fake();

        config([
            'server.admission.queue_overrides' => [
                'default:external-activities' => [
                    'activity_tasks' => [
                        'max_dispatches_per_minute' => 1,
                    ],
                ],
            ],
        ]);

        $this->createNamespace('default');

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-deadline-redelivery');
        $start = $workflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($start->runId());

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $start->runId())
            ->firstOrFail();

        $execution->forceFill([
            'schedule_deadline_at' => now()->addMinutes(10),
            'schedule_to_close_deadline_at' => now()->addHour(),
            'retry_policy' => array_merge(
                is_array($execution->retry_policy) ? $execution->retry_policy : [],
                [
                    'start_to_close_timeout' => 1800,
                    'heartbeat_timeout' => 30,
                ],
            ),
        ])->save();

        $this->registerWorker('deadline-redelivery-worker', 'external-activities');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'deadline-redelivery-worker',
                'task_queue' => 'external-activities',
                'poll_request_id' => 'deadline-redelivery-poll',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.workflow_id', $workflow->id())
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.attempt_number', 1);

        $firstTask = $firstPoll->json('task');
        $this->assertIsArray($firstTask);
        $this->assertIsArray($firstTask['deadlines'] ?? null);
        $this->assertArrayHasKey('schedule_to_start', $firstTask['deadlines']);
        $this->assertArrayHasKey('start_to_close', $firstTask['deadlines']);
        $this->assertArrayHasKey('schedule_to_close', $firstTask['deadlines']);
        $this->assertArrayHasKey('heartbeat', $firstTask['deadlines']);

        $taskId = (string) $firstTask['task_id'];
        $attemptId = (string) $firstTask['activity_attempt_id'];
        $attemptCount = ActivityAttempt::query()
            ->where('workflow_task_id', $taskId)
            ->count();

        $this->assertSame(1, $attemptCount);

        app(ActivityTaskPollRequestStore::class)->forgetResult(
            'default',
            'external-activities',
            null,
            'deadline-redelivery-worker',
            'deadline-redelivery-poll',
        );

        $redelivery = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'deadline-redelivery-worker',
                'task_queue' => 'external-activities',
                'poll_request_id' => 'deadline-redelivery-poll',
            ]);

        $redelivery->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.activity_attempt_id', $attemptId)
            ->assertJsonPath('task.attempt_number', 1);

        $redeliveredTask = $redelivery->json('task');
        $this->assertSame($firstTask, $redeliveredTask);
        $this->assertSame(1, ActivityAttempt::query()
            ->where('workflow_task_id', $taskId)
            ->count());

        $leasedTask = WorkflowTask::query()->findOrFail($taskId);
        $this->assertSame(1, (int) $leasedTask->attempt_count);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/external-activities')
            ->assertOk()
            ->assertJsonPath('admission.activity_tasks.server_max_dispatches_per_minute', 1)
            ->assertJsonPath('admission.activity_tasks.server_dispatch_count_this_minute', 1)
            ->assertJsonPath('admission.activity_tasks.server_remaining_dispatch_capacity', 0);
    }

    public function test_activity_poll_response_omits_deadlines_when_none_set(): void
    {
        Queue::fake();

        $this->createNamespace('default');

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-no-deadline-poll');
        $start = $workflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($start->runId());

        $this->registerWorker('no-deadline-worker', 'external-activities');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'no-deadline-worker',
                'task_queue' => 'external-activities',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflow->id());

        $this->assertArrayNotHasKey('deadlines', $poll->json('task'));
    }

    // ── Cluster Info ────────────────────────────────────────────────

    public function test_cluster_info_advertises_activity_timeouts_capability(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('capabilities.activity_timeouts', true)
            ->assertJsonPath('capabilities.activity_retry_policy', true);
    }

    // ── Auth ────────────────────────────────────────────────────────

    public function test_activity_timeout_endpoints_require_auth(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'test-token']);

        $this->getJson('/api/system/activity-timeouts')
            ->assertUnauthorized();

        $this->postJson('/api/system/activity-timeouts/pass')
            ->assertUnauthorized();
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function registerWorker(
        string $workerId,
        string $taskQueue,
        string $namespace = 'default',
    ): void {
        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => $namespace],
            [
                'task_queue' => $taskQueue,
                'runtime' => 'php',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'supported_activity_types' => ['tests.external-greeting-activity'],
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
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }

    /**
     * @return array{
     *     run_id: string,
     *     execution: ActivityExecution,
     *     task_id: string,
     *     attempt_id: string,
     *     lease_owner: string
     * }
     */
    private function leaseHeartbeatActivity(
        string $workflowId,
        string $workerId,
        int $heartbeatTimeout,
    ): array {
        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, $workflowId);
        $start = $workflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($start->runId());

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $start->runId())
            ->firstOrFail();
        $execution->forceFill([
            'retry_policy' => array_merge(
                is_array($execution->retry_policy) ? $execution->retry_policy : [],
                [
                    'max_attempts' => 1,
                    'start_to_close_timeout' => 60,
                    'heartbeat_timeout' => $heartbeatTimeout,
                ],
            ),
        ])->save();

        $this->registerWorker($workerId, 'external-activities');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.lease_owner', $workerId);

        return [
            'run_id' => $start->runId(),
            'execution' => $execution,
            'task_id' => (string) $poll->json('task.task_id'),
            'attempt_id' => (string) $poll->json('task.activity_attempt_id'),
            'lease_owner' => (string) $poll->json('task.lease_owner'),
        ];
    }

    /**
     * Create a workflow with an activity, then expire the activity's
     * start-to-close deadline so it appears in timeout scans.
     */
    private function setUpExpiredActivity(): string
    {
        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-timeout-test-'.uniqid());
        $start = $workflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($start->runId());

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $start->runId())
            ->firstOrFail();

        $activityTask = WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', 'activity')
            ->where('status', 'ready')
            ->firstOrFail();

        /** @var ActivityTaskBridgeContract $bridge */
        $bridge = app(ActivityTaskBridgeContract::class);
        $claim = $bridge->claim($activityTask->id, 'timeout-test-worker');
        $this->assertIsArray($claim);

        $execution->forceFill([
            'close_deadline_at' => now()->subMinute(),
        ])->save();

        return (string) $execution->id;
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

        $job = new RunWorkflowTask($taskId);
        $job->handle(app(WorkflowExecutor::class));
    }
}
