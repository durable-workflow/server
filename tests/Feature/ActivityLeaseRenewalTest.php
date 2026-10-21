<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\NamespaceWorkflowScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\WorkflowStub;

class ActivityLeaseRenewalTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createNamespace('default');
        $this->createNamespace('other');
        config(['workflows.v2.activity_task_lease_seconds' => 5]);
        $this->travelTo(Carbon::parse('2026-10-21T01:00:00.123456Z'));
    }

    public function test_runtime_renewal_keeps_one_attempt_without_application_progress_or_history(): void
    {
        $task = $this->claimActivity();
        $execution = ActivityExecution::query()->findOrFail($task['activity_execution_id']);
        $before = $execution->getAttributes();
        $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $task['run_id'])->get()->toArray();

        for ($renewal = 0; $renewal < 3; $renewal++) {
            $this->travel(4)->seconds();
            $this->postJson($this->renewalPath($task), $this->ownership($task), $this->workerHeaders())
                ->assertOk()
                ->assertJsonPath('lease_renewed', true)
                ->assertJsonPath('can_continue', true)
                ->assertJsonPath('heartbeat_recorded', false)
                ->assertJsonPath('workflow_task_id', $task['task_id'])
                ->assertJsonPath('activity_attempt_id', $task['activity_attempt_id'])
                ->assertJsonPath('attempt_number', 1)
                ->assertJsonPath('lease_owner', $task['lease_owner'])
                ->assertJsonPath('server_time', now()->toJSON())
                ->assertJsonPath('lease_expires_at', now()->addSeconds(5)->toJSON())
                ->assertJsonPath('server_capabilities.activity_lease_renewal', true);
        }

        $this->assertSame($before, $execution->fresh()->getAttributes());
        $this->assertSame($history, WorkflowHistoryEvent::query()->where('workflow_run_id', $task['run_id'])->get()->toArray());
        $this->assertSame(1, ActivityAttempt::query()->where('activity_execution_id', $execution->id)->count());
    }

    public function test_poll_and_observation_preserve_original_workflow_deadlines_after_renewal(): void
    {
        $runDeadline = now()->addSeconds(30);
        $executionDeadline = now()->addSeconds(60);
        $task = $this->claimActivity(runDeadlines: [
            'run_deadline_at' => $runDeadline,
            'execution_deadline_at' => $executionDeadline,
        ]);
        $this->assertSame($runDeadline->toJSON(), $task['deadlines']['run'] ?? null);
        $this->assertSame($executionDeadline->toJSON(), $task['deadlines']['execution'] ?? null);
        $runBefore = WorkflowRun::query()->findOrFail($task['run_id'])->getAttributes();

        $this->travel(4)->seconds();
        $this->postJson($this->renewalPath($task), $this->ownership($task), $this->workerHeaders())
            ->assertOk()->assertJsonPath('lease_renewed', true);
        $this->postJson('/api/worker/activity-tasks/'.$task['task_id'].'/status', $this->ownership($task), $this->workerHeaders())
            ->assertOk()->assertJsonPath('can_continue', true)
            ->assertJsonPath('deadlines.run', $runDeadline->toJSON())
            ->assertJsonPath('deadlines.execution', $executionDeadline->toJSON());
        $this->assertSame($runBefore, WorkflowRun::query()->findOrFail($task['run_id'])->getAttributes());
    }

    public function test_absent_workflow_deadlines_are_not_invented(): void
    {
        $task = $this->claimActivity();
        $this->assertArrayNotHasKey('run', $task['deadlines'] ?? []);
        $this->assertArrayNotHasKey('execution', $task['deadlines'] ?? []);
    }

    public function test_renewal_refuses_wrong_owner_and_namespace_without_extending_the_lease(): void
    {
        $task = $this->claimActivity();
        $before = ActivityAttempt::query()->findOrFail($task['activity_attempt_id'])->getAttributes();
        $this->postJson($this->renewalPath($task), [
            ...$this->ownership($task), 'lease_owner' => 'another-worker',
        ], $this->workerHeaders())->assertConflict()->assertJsonPath('reason', 'lease_owner_mismatch');
        $this->postJson($this->renewalPath($task), $this->ownership($task), $this->workerHeaders('other'))
            ->assertNotFound()->assertJsonPath('reason', 'task_not_found');
        $this->assertSame($before, ActivityAttempt::query()->findOrFail($task['activity_attempt_id'])->getAttributes());
    }

    public function test_an_expired_lease_cannot_be_revived(): void
    {
        $task = $this->claimActivity();
        $this->travel(5)->seconds();
        $this->postJson($this->renewalPath($task), $this->ownership($task), $this->workerHeaders())
            ->assertConflict()
            ->assertJsonPath('lease_renewed', false)
            ->assertJsonPath('reason', 'lease_expired');
    }

    public function test_only_worker_credentials_can_renew_an_original_activity(): void
    {
        $task = $this->claimActivity();
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.role_tokens' => ['worker' => 'worker-token', 'operator' => 'operator-token'],
        ]);
        $this->postJson($this->renewalPath($task), $this->ownership($task), $this->workerHeaders())
            ->assertUnauthorized();
        $this->postJson($this->renewalPath($task), $this->ownership($task), [
            ...$this->workerHeaders(), 'Authorization' => 'Bearer operator-token',
        ])->assertForbidden();
        $this->postJson($this->renewalPath($task), $this->ownership($task), [
            ...$this->workerHeaders(), 'Authorization' => 'Bearer worker-token',
        ])->assertOk()->assertJsonPath('lease_renewed', true);
    }

    public function test_a_lost_worker_session_does_not_extend_the_activity_lease(): void
    {
        $task = $this->claimActivity(session: [
            'session_id' => 'renewal-session',
            'lease_seconds' => 2,
            'ttl_seconds' => 30,
            'queue' => 'external-activities',
        ]);
        $before = ActivityAttempt::query()->findOrFail($task['activity_attempt_id'])->lease_expires_at->toJSON();
        $this->travel(2)->seconds();
        $this->postJson($this->renewalPath($task), $this->ownership($task), $this->workerHeaders())
            ->assertConflict()
            ->assertJsonPath('lease_renewed', false)
            ->assertJsonPath('can_continue', false)
            ->assertJsonPath('reason', 'session_not_active');
        $this->assertSame($before, ActivityAttempt::query()->findOrFail($task['activity_attempt_id'])->lease_expires_at->toJSON());
    }

    public static function businessTimeouts(): array
    {
        return [
            'heartbeat' => ['heartbeat_timeout', 'heartbeat_timeout'],
            'start to close' => ['start_to_close_timeout', 'start_to_close_timeout'],
            'schedule to close' => ['schedule_to_close_timeout', 'schedule_to_close_timeout'],
        ];
    }

    #[DataProvider('businessTimeouts')]
    public function test_runtime_renewal_never_extends_business_timeout(string $field, string $reason): void
    {
        $task = $this->claimActivity([$field => 3]);
        $execution = ActivityExecution::query()->findOrFail($task['activity_execution_id']);
        $before = $execution->getAttributes();
        $this->travel(3)->seconds();
        $this->postJson($this->renewalPath($task), $this->ownership($task), $this->workerHeaders())
            ->assertConflict()->assertJsonPath('lease_renewed', false)->assertJsonPath('reason', $reason);
        $this->assertSame($before, $execution->fresh()->getAttributes());
    }

    public function test_custom_bridge_without_renewal_is_not_advertised_or_called(): void
    {
        $this->app->instance(ActivityTaskBridge::class, \Mockery::mock(ActivityTaskBridge::class));
        $this->postJson('/api/worker/activity-tasks/unknown/renew-lease', [
            'activity_attempt_id' => 'unknown', 'lease_owner' => 'worker',
        ], $this->workerHeaders())
            ->assertUnprocessable()
            ->assertJsonPath('lease_renewed', false)
            ->assertJsonPath('reason', 'activity_lease_renewal_unavailable')
            ->assertJsonPath('server_capabilities.activity_lease_renewal', false);
    }

    private function claimActivity(array $policy = [], ?array $session = null, array $runDeadlines = []): array
    {
        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'renewal-workflow');
        $start = $workflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($start->runId());
        if ($runDeadlines !== []) {
            WorkflowRun::query()->findOrFail($start->runId())->forceFill($runDeadlines)->save();
        }
        $execution = ActivityExecution::query()->where('workflow_run_id', $start->runId())->firstOrFail();
        $execution->forceFill([
            'retry_policy' => [...$execution->retry_policy, ...$policy],
            'activity_options' => [...($execution->activity_options ?? []), 'worker_session' => $session],
            'schedule_to_close_deadline_at' => isset($policy['schedule_to_close_timeout'])
                ? now()->addSeconds($policy['schedule_to_close_timeout']) : null,
        ])->save();
        $this->registerWorker('renewal-worker', 'external-activities', capabilities: ['worker_sessions']);

        return $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'renewal-worker', 'task_queue' => 'external-activities',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('server_time', now()->toJSON())->json('task');
    }

    private function ownership(array $task): array
    {
        return ['activity_attempt_id' => $task['activity_attempt_id'], 'lease_owner' => $task['lease_owner']];
    }

    private function renewalPath(array $task): string
    {
        return '/api/worker/activity-tasks/'.$task['task_id'].'/renew-lease';
    }
}
