<?php

namespace Tests\Feature;

use App\Models\WorkerRegistrationIncarnation;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\WorkflowStub;

class ActivityWorkerDeregistrationTest extends TestCase
{
    use DatabaseTruncation;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createNamespace('default');
        $this->travelTo(Carbon::parse('2026-10-21T05:00:00.123456Z'));
    }

    public static function deregistrationRoutes(): array
    {
        return ['fenced' => [true], 'legacy' => [false]];
    }

    #[DataProvider('deregistrationRoutes')]
    public function test_deregistration_settles_the_original_attempt_with_its_recorded_retry_policy(bool $fenced): void
    {
        [$task, $token] = $this->leaseActivity(2);
        $originalExpiry = WorkflowTask::query()->findOrFail($task['task_id'])->lease_expires_at->toJSON();
        $closedAt = now();
        $this->deregister($token, $fenced)->assertOk();

        $attempt = ActivityAttempt::query()->findOrFail($task['activity_attempt_id']);
        $this->assertSame(ActivityAttemptStatus::Expired, $attempt->status);
        $this->assertTrue($attempt->closed_at->equalTo($closedAt));
        $this->assertSame(TaskStatus::Cancelled, WorkflowTask::query()->findOrFail($task['task_id'])->status);
        $execution = ActivityExecution::query()->findOrFail($task['activity_execution_id']);
        $this->assertSame(ActivityStatus::Pending, $execution->status);
        $this->assertSame(1, $execution->attempt_count);
        $retry = WorkflowTask::query()->where('workflow_run_id', $task['run_id'])
            ->where('task_type', TaskType::Activity->value)->where('status', TaskStatus::Ready->value)->sole();
        $this->assertTrue($retry->available_at->equalTo($closedAt->copy()->addSeconds(7)));
        $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $task['run_id'])
            ->where('event_type', HistoryEventType::ActivityRetryScheduled->value)->sole();
        $this->assertSame($task['activity_attempt_id'], $event->payload['activity_attempt_id']);
        $this->assertSame('worker_deregistered', $event->payload['failure_reason']);
        $this->assertSame($originalExpiry, $event->payload['lease_expires_at']);
        $this->assertSame($closedAt->toJSON(), $event->payload['lease_revoked_at']);
        $this->assertSame(1, $event->payload['retry_after_attempt']);

        $historyCount = WorkflowHistoryEvent::query()->count();
        $this->postJson('/api/worker/activity-tasks/'.$task['task_id'].'/complete', [
            'activity_attempt_id' => $task['activity_attempt_id'], 'lease_owner' => $task['lease_owner'],
            'result' => 'late original result',
        ], $this->workerHeaders())->assertConflict()->assertJsonPath('recorded', false);
        $this->assertSame($historyCount, WorkflowHistoryEvent::query()->count());
        if ($fenced) {
            $this->deregister($token, true)->assertOk();
            $this->assertSame($historyCount, WorkflowHistoryEvent::query()->count());
        }
    }

    #[DataProvider('deregistrationRoutes')]
    public function test_deregistration_cannot_reset_an_exhausted_activity_retry_budget(bool $fenced): void
    {
        [$task, $token] = $this->leaseActivity(1);
        $this->deregister($token, $fenced)->assertOk();
        $this->assertSame(ActivityStatus::Failed, ActivityExecution::query()->findOrFail($task['activity_execution_id'])->status);
        $this->assertSame(ActivityAttemptStatus::Expired, ActivityAttempt::query()->findOrFail($task['activity_attempt_id'])->status);
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $task['run_id'])
            ->where('task_type', TaskType::Activity->value)->where('status', TaskStatus::Ready->value)->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $task['run_id'])
            ->where('event_type', HistoryEventType::ActivityFailed->value)->count());
    }

    public function test_activity_settlement_failure_rolls_back_the_fenced_registration_receipt(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite trigger fault injection.');
        }
        [$task, $token] = $this->leaseActivity(2);
        $before = WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal();
        $historyCount = WorkflowHistoryEvent::query()->count();
        DB::unprepared("CREATE TRIGGER reject_activity_shutdown BEFORE INSERT ON workflow_history_events WHEN NEW.event_type = 'ActivityRetryScheduled' BEGIN SELECT RAISE(ABORT, 'activity shutdown rejected'); END");
        try {
            $this->deregister($token, true)->assertStatus(500);
        } finally {
            DB::unprepared('DROP TRIGGER reject_activity_shutdown');
        }
        $this->assertSame($before, WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal());
        $this->assertSame($historyCount, WorkflowHistoryEvent::query()->count());
        $this->assertDatabaseHas('workflow_worker_registrations', ['worker_id' => 'activity-owner', 'status' => 'active']);
        $this->assertSame(WorkerRegistrationIncarnation::ACTIVE, WorkerRegistrationIncarnation::query()->findOrFail($token)->status);
    }

    public function test_completed_receipt_cannot_revoke_a_replacement_incarnation(): void
    {
        [$task, $token] = $this->leaseActivity(2);
        $this->deregister($token, true)->assertOk();
        $this->travel(7)->seconds();
        $replacementToken = $this->registerActivityWorker();
        $this->assertNotSame($token, $replacementToken);
        $replacement = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'activity-owner', 'task_queue' => 'external-activities',
        ], $this->workerHeaders())->assertOk()->json('task');
        $this->assertIsArray($replacement);
        $this->assertNotSame($task['activity_attempt_id'], $replacement['activity_attempt_id']);
        $before = ActivityAttempt::query()->findOrFail($replacement['activity_attempt_id'])->getRawOriginal();
        $historyCount = WorkflowHistoryEvent::query()->count();
        $this->deregister($token, true)->assertOk();
        $this->assertSame($before, ActivityAttempt::query()->findOrFail($replacement['activity_attempt_id'])->getRawOriginal());
        $this->assertSame($historyCount, WorkflowHistoryEvent::query()->count());
        $this->assertDatabaseHas('workflow_worker_registrations', ['registration_token' => $replacementToken, 'status' => 'active']);
    }

    public function test_revocation_cannot_replace_an_already_elapsed_business_timeout(): void
    {
        [$task, $token] = $this->leaseActivity(1);
        $execution = ActivityExecution::query()->findOrFail($task['activity_execution_id']);
        $execution->forceFill([
            'heartbeat_deadline_at' => now(),
            'retry_policy' => [...$execution->retry_policy, 'heartbeat_timeout' => 3],
        ])->save();
        $this->deregister($token, true)->assertOk();
        $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $task['run_id'])
            ->where('event_type', HistoryEventType::ActivityTimedOut->value)->sole();
        $this->assertSame('heartbeat', $event->payload['timeout_kind']);
        $this->assertArrayNotHasKey('lease_revoked_at', $event->payload);
    }

    public function test_revocation_refuses_foreign_namespace_and_owner_without_mutation(): void
    {
        [$task] = $this->leaseActivity(2);
        $before = WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal();
        $historyCount = WorkflowHistoryEvent::query()->count();
        foreach ([['other', 'activity-owner'], ['default', 'another-worker']] as [$namespace, $owner]) {
            $outcome = ActivityTimeoutEnforcer::revokeWorkerLease($task['task_id'], $namespace, $owner);
            $this->assertFalse($outcome['enforced']);
            $this->assertSame('lease_owner_mismatch', $outcome['reason']);
        }
        $this->assertSame($before, WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal());
        $this->assertSame($historyCount, WorkflowHistoryEvent::query()->count());
    }

    #[DataProvider('deregistrationRoutes')]
    public function test_contended_attempt_refuses_without_committing_the_registration(bool $fenced): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Two actual row-locking database connections are required.');
        }
        [$task, $token] = $this->leaseActivity(2);
        $before = WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal();
        config(['database.connections.activity_blocker' => DB::connection()->getConfig()]);
        $blocker = DB::connection('activity_blocker');
        $blocker->beginTransaction();
        try {
            $this->assertNotNull($blocker->table((new ActivityAttempt)->getTable())
                ->where('id', $task['activity_attempt_id'])->lockForUpdate()->first());
            $started = hrtime(true);
            $this->deregister($token, $fenced)->assertStatus(503)->assertJsonPath('reason', 'backend_lock_pressure');
            $this->assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
        } finally {
            $blocker->rollBack();
            DB::purge('activity_blocker');
        }
        $this->assertSame($before, WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal());
        $this->assertDatabaseHas('workflow_worker_registrations', ['worker_id' => 'activity-owner', 'status' => 'active']);
        $this->assertSame(WorkerRegistrationIncarnation::ACTIVE, WorkerRegistrationIncarnation::query()->findOrFail($token)->status);
    }

    private function leaseActivity(int $maxAttempts): array
    {
        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'deregister-original-activity');
        $start = $workflow->start('Ada');
        $this->runReadyWorkflowTask($start->runId());
        $execution = ActivityExecution::query()->where('workflow_run_id', $start->runId())->sole();
        $execution->forceFill(['retry_policy' => [
            ...$execution->retry_policy, 'max_attempts' => $maxAttempts, 'backoff_seconds' => [7],
        ]])->save();
        $token = $this->registerActivityWorker();
        $task = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'activity-owner', 'task_queue' => 'external-activities',
        ], $this->workerHeaders())->assertOk()->json('task');
        $this->assertIsArray($task);

        return [$task, $token];
    }

    private function registerActivityWorker(): string
    {
        return $this->postJson('/api/worker/register', [
            'worker_id' => 'activity-owner', 'task_queue' => 'external-activities', 'runtime' => 'php',
            'supported_workflow_types' => [], 'supported_activity_types' => ['tests.external-greeting-activity'],
            'capabilities' => [], 'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
        ], $this->workerHeaders())->assertCreated()->json('registration_token');
    }

    private function deregister(string $token, bool $fenced): TestResponse
    {
        return $fenced
            ? $this->postJson('/api/worker/registrations/activity-owner/deregister', ['registration_token' => $token], $this->workerHeaders())
            : $this->deleteJson('/api/worker/registrations/activity-owner', [], $this->workerHeaders());
    }
}
