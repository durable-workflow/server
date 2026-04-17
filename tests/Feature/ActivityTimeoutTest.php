<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\NamespaceWorkflowScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingActivity;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowExecutor;

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

        $workflow = \Workflow\V2\WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-deadline-poll');
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

    public function test_activity_poll_response_omits_deadlines_when_none_set(): void
    {
        Queue::fake();

        $this->createNamespace('default');

        $workflow = \Workflow\V2\WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-no-deadline-poll');
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
            ->assertJsonPath('capabilities.activity_timeouts', true);
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
        \App\Models\WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => $namespace],
            [
                'task_queue' => $taskQueue,
                'runtime' => 'php',
                'supported_workflow_types' => [],
                'supported_activity_types' => [],
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

    /**
     * Create a workflow with an activity, then expire the activity's
     * start-to-close deadline so it appears in timeout scans.
     */
    private function setUpExpiredActivity(): string
    {
        $workflow = \Workflow\V2\WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-timeout-test-' . uniqid());
        $start = $workflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($start->runId());

        // Simulate that the activity was claimed and is now running with an expired deadline.
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $start->runId())
            ->firstOrFail();

        $execution->forceFill([
            'status' => ActivityStatus::Running->value,
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
