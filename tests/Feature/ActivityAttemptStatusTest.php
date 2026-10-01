<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkerSessionLease;
use App\Models\WorkflowNamespace;
use App\Support\BackendLockPressureException;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\StoragePressureFixture;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityCancellation;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\WorkflowStub;

class ActivityAttemptStatusTest extends TestCase
{
    use RefreshDatabase;
    use StoragePressureFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['server.worker_protocol.version' => '1.20']);
        WorkflowNamespace::query()->create([
            'name' => 'default', 'description' => 'Test', 'retention_days' => 30, 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeStoragePressure();
        parent::tearDown();
    }

    public function test_observation_preserves_user_progress_lease_and_registration(): void
    {
        $task = $this->lease();
        $execution = ActivityExecution::query()->findOrFail($task['activity_execution_id']);
        $execution->forceFill(['retry_policy' => ['heartbeat_timeout' => 10]])->save();
        $this->withHeaders($this->headers())->postJson($this->path($task, 'heartbeat'), $this->fence($task) + [
            'message' => 'authored progress', 'current' => 1, 'total' => 3,
        ])->assertOk()->assertJsonPath('heartbeat_recorded', true);
        $before = $this->snapshot($task);
        $this->travel(2)->seconds();
        $response = $this->observe($task)->assertOk()
            ->assertJsonPath('can_continue', true)
            ->assertJsonPath('cancel_requested', false)
            ->assertJsonPath('heartbeat_recorded', false)
            ->assertJsonPath('task_id', $task['task_id'])
            ->assertJsonPath('activity_attempt_id', $task['activity_attempt_id'])
            ->assertJsonPath('lease_owner', $task['lease_owner']);
        $this->assertSame($before, $this->snapshot($task));
        OpenApiSchema::fromFile(base_path('resources/platform-protocol-specs/worker-protocol-api.openapi.yaml'))
            ->assertReferenceMatches('#/components/schemas/ActivityTaskStatusResponse/allOf/1', json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR));
    }

    public function test_pending_request_does_not_cancel_before_canonical_delivery(): void
    {
        $task = $this->lease();
        $this->withHeaders($this->headers())->postJson("/api/workflows/{$task['workflow_id']}/request-cancellation", [])
            ->assertStatus(202)->assertJsonPath('accepted', true);
        $before = $this->snapshot($task);
        $this->observe($task)->assertOk()->assertJsonPath('can_continue', true)
            ->assertJsonPath('cancel_requested', false)->assertJsonPath('heartbeat_recorded', false);
        $this->assertSame($before, $this->snapshot($task));
    }

    public function test_required_session_is_observed_without_renewal(): void
    {
        $task = $this->lease();
        $session = $this->requireSession($task);
        $before = $session->fresh()->getRawOriginal();
        $this->travel(2)->seconds();
        $this->observe($task)->assertOk()->assertJsonPath('can_continue', true)
            ->assertJsonPath('worker_session.session_id', 'required-session')
            ->assertJsonPath('worker_session.lease_owner', $task['lease_owner']);
        $this->assertSame($before, $session->fresh()->getRawOriginal());
    }

    public static function sessionProvider(): array
    {
        return [['lease_expires_at'], ['ttl_expires_at'], ['lease_owner'], ['status'], ['absent']];
    }

    #[DataProvider('sessionProvider')]
    public function test_required_session_loss_refuses_continuation_without_repair(string $field): void
    {
        $task = $this->lease();
        $session = $this->requireSession($task);
        if ($field === 'absent') {
            $session->delete();
        } else {
            $value = match ($field) {
                'lease_owner' => 'replacement-worker',
                'status' => 'closed',
                default => now(),
            };
            $session->forceFill([$field => $value])->save();
        }
        $before = WorkerSessionLease::query()->get()->map->getRawOriginal()->all();
        $this->observe($task)->assertOk()->assertJsonPath('can_continue', false)
            ->assertJsonPath('reason', 'worker_session_unavailable');
        $this->assertSame($before, WorkerSessionLease::query()->get()->map->getRawOriginal()->all());
    }

    public function test_operator_credentials_cannot_observe_worker_attempts(): void
    {
        $task = $this->lease();
        config(['server.auth.driver' => 'token', 'server.auth.role_tokens' => [
            'operator' => 'fixture-operator', 'worker' => 'fixture-worker',
        ]]);
        $this->withHeaders($this->headers() + ['Authorization' => 'Bearer fixture-operator'])
            ->postJson($this->path($task), $this->fence($task))->assertForbidden()->assertJsonPath('reason', 'forbidden');
        $this->withHeaders($this->headers() + ['Authorization' => 'Bearer fixture-worker'])
            ->postJson($this->path($task), $this->fence($task))->assertOk()->assertJsonPath('can_continue', true);
    }

    public function test_expired_lease_cannot_continue_before_repair_runs(): void
    {
        $task = $this->lease();
        $before = $this->snapshot($task);
        $this->travel(5)->minutes();
        $this->observe($task)->assertOk()->assertJsonPath('can_continue', false)
            ->assertJsonPath('reason', 'lease_expired')->assertJsonPath('heartbeat_recorded', false);
        $this->assertSame($before, $this->snapshot($task));
        $this->assertSame(TaskStatus::Leased, WorkflowTask::query()->findOrFail($task['task_id'])->status);
    }

    public static function deadlineProvider(): array
    {
        return [
            ['heartbeat_deadline_at', 'heartbeat_timeout'],
            ['close_deadline_at', 'start_to_close_timeout'],
            ['schedule_to_close_deadline_at', 'schedule_to_close_timeout'],
        ];
    }

    #[DataProvider('deadlineProvider')]
    public function test_expired_application_deadline_is_not_hidden_by_observation(string $field, string $reason): void
    {
        $task = $this->lease();
        ActivityExecution::query()->findOrFail($task['activity_execution_id'])
            ->forceFill([$field => now()])->save();
        $before = $this->snapshot($task);
        $this->observe($task)->assertOk()->assertJsonPath('can_continue', false)
            ->assertJsonPath('reason', $reason)->assertJsonPath('heartbeat_recorded', false);
        $this->assertSame($before, $this->snapshot($task));
        $this->assertSame(ActivityStatus::Running, ActivityExecution::query()->findOrFail($task['activity_execution_id'])->status);
    }

    public function test_canonical_activity_cancellation_stops_without_another_history_event(): void
    {
        $task = $this->lease();
        $execution = ActivityExecution::query()->findOrFail($task['activity_execution_id']);
        ActivityCancellation::record(
            WorkflowRun::query()->findOrFail($task['run_id']), $execution,
            WorkflowTask::query()->findOrFail($task['task_id']),
        );
        $before = $this->snapshot($task);
        $this->observe($task)->assertOk()->assertJsonPath('can_continue', false)
            ->assertJsonPath('cancel_requested', true)->assertJsonPath('heartbeat_recorded', false);
        $this->assertSame($before, $this->snapshot($task));
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $task['run_id'])
            ->where('event_type', HistoryEventType::ActivityCancelled->value)->count());
    }

    public static function closureProvider(): array
    {
        return [['cancel', 'run_cancelled'], ['terminate', 'run_terminated']];
    }

    #[DataProvider('closureProvider')]
    public function test_terminal_close_is_observed_without_progress(string $action, string $reason): void
    {
        $task = $this->lease();
        $this->withHeaders($this->headers())->postJson("/api/workflows/{$task['workflow_id']}/{$action}", [])
            ->assertSuccessful();
        $before = $this->snapshot($task);
        $this->observe($task)->assertOk()->assertJsonPath('can_continue', false)
            ->assertJsonPath('cancel_requested', true)->assertJsonPath('reason', $reason);
        $this->assertSame($before, $this->snapshot($task));
    }

    public function test_namespace_task_attempt_and_owner_fences_are_required(): void
    {
        $task = $this->lease();
        $other = $this->lease('other');
        $this->observe($task, ['lease_owner' => 'different'])->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch');
        $this->observe($task, ['activity_attempt_id' => $other['activity_attempt_id']])->assertStatus(409)
            ->assertJsonPath('reason', 'task_mismatch');
        $this->observe($task, ['activity_attempt_id' => 'absent'])->assertNotFound()
            ->assertJsonPath('reason', 'attempt_not_found');
        WorkflowNamespace::query()->create([
            'name' => 'isolated', 'description' => 'Other', 'retention_days' => 30, 'status' => 'active',
        ]);
        $this->observe($task, namespace: 'isolated')->assertNotFound()->assertJsonPath('reason', 'task_not_found');
        $this->withHeaders($this->headers())->postJson($this->path($task), [])->assertStatus(422)
            ->assertJsonValidationErrors(['activity_attempt_id', 'lease_owner']);
    }

    public function test_closed_old_attempt_cannot_continue_after_reclaim(): void
    {
        $task = $this->lease();
        $model = WorkflowTask::query()->findOrFail($task['task_id']);
        $model->forceFill(['status' => TaskStatus::Ready, 'lease_owner' => null, 'lease_expires_at' => null])->save();
        $replacement = app(ActivityTaskBridge::class)->claim($model->id, 'replacement-worker');
        $this->assertNotNull($replacement);
        $this->assertNotSame($task['activity_attempt_id'], $replacement['activity_attempt_id']);
        $before = $this->snapshot($task);
        $this->observe($task)->assertOk()->assertJsonPath('can_continue', false);
        $this->assertSame($before, $this->snapshot($task));
    }

    public function test_lock_pressure_does_not_claim_permission_to_continue(): void
    {
        $task = $this->lease();
        $this->mock(ActivityTaskBridge::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('status')->andThrow(new BackendLockPressureException('database is locked'));
            $mock->shouldNotReceive('heartbeat');
        });
        $this->observe($task)->assertStatus(503)->assertHeader('Retry-After', '1')
            ->assertJsonPath('reason', 'backend_lock_pressure')->assertJsonPath('recorded', false)
            ->assertJsonPath('activity_attempt_id', $task['activity_attempt_id'])
            ->assertJsonMissingPath('can_continue');
    }

    public function test_read_is_available_when_storage_is_draining_or_fenced(): void
    {
        $task = $this->lease();
        $this->configureStoragePressure();
        $before = $this->snapshot($task);
        foreach (['draining', 'fenced'] as $state) {
            $this->observeStoragePressure($state);
            $this->observe($task)->assertOk()->assertJsonPath('can_continue', true)
                ->assertJsonPath('heartbeat_recorded', false);
        }
        $this->assertSame($before, $this->snapshot($task));
    }

    public function test_default_and_old_protocols_do_not_enable_observation(): void
    {
        $task = $this->lease();
        config(['server.worker_protocol.version' => '1.19']);
        $this->observe($task)->assertStatus(400)->assertJsonPath('reason', 'unsupported_protocol_version');
        $old = $this->headers();
        $old[WorkerProtocol::HEADER] = '1.19';
        $this->withHeaders($old)->postJson($this->path($task), $this->fence($task))
            ->assertStatus(422)->assertJsonPath('reason', 'activity_attempt_status_unavailable');
        config(['server.worker_protocol.version' => '1.20']);
        $this->withHeaders($old)->postJson($this->path($task), $this->fence($task))
            ->assertStatus(422)->assertJsonPath('reason', 'activity_attempt_status_unavailable');
    }

    private function lease(string $suffix = 'one'): array
    {
        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'status-'.$suffix);
        $start = $workflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);
        $id = WorkflowTask::query()->where('workflow_run_id', $start->runId())
            ->where('task_type', 'workflow')->where('status', 'ready')->value('id');
        (new RunWorkflowTask($id))->handle(app(WorkflowExecutor::class));
        WorkerRegistration::query()->updateOrCreate(['worker_id' => 'status-worker-'.$suffix, 'namespace' => 'default'], [
            'task_queue' => 'external-activities', 'runtime' => 'php',
            'supported_activity_types' => ['tests.external-greeting-activity'],
            'last_heartbeat_at' => now(), 'status' => 'active',
        ]);

        return $this->withHeaders($this->headers())->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'status-worker-'.$suffix, 'task_queue' => 'external-activities',
        ])->assertOk()->json('task');
    }

    private function requireSession(array $task): WorkerSessionLease
    {
        ActivityExecution::query()->findOrFail($task['activity_execution_id'])
            ->forceFill(['activity_options' => ['worker_session' => [
                'session_id' => 'required-session', 'lease_seconds' => 120,
            ]]])->save();

        return WorkerSessionLease::query()->create([
            'namespace' => 'default', 'session_id' => 'required-session', 'queue' => 'external-activities',
            'status' => 'active', 'lease_owner' => $task['lease_owner'], 'lease_expires_at' => now()->addSeconds(120),
            'ttl_expires_at' => now()->addMinutes(30), 'last_heartbeat_at' => now(),
        ]);
    }

    private function headers(string $namespace = 'default'): array
    {
        return ['X-Namespace' => $namespace, 'X-Durable-Workflow-Control-Plane-Version' => '2', WorkerProtocol::HEADER => '1.20'];
    }

    private function path(array $task, string $action = 'status'): string
    {
        return "/api/worker/activity-tasks/{$task['task_id']}/{$action}";
    }

    private function fence(array $task): array
    {
        return ['activity_attempt_id' => $task['activity_attempt_id'], 'lease_owner' => $task['lease_owner']];
    }

    private function observe(array $task, array $overrides = [], string $namespace = 'default'): TestResponse
    {
        return $this->withHeaders($this->headers($namespace))->postJson($this->path($task), array_replace($this->fence($task), $overrides));
    }

    private function snapshot(array $task): array
    {
        return [
            ActivityExecution::query()->findOrFail($task['activity_execution_id'])->getRawOriginal(),
            ActivityAttempt::query()->findOrFail($task['activity_attempt_id'])->getRawOriginal(),
            WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal(),
            WorkerRegistration::query()->where('worker_id', $task['lease_owner'])->firstOrFail()->getRawOriginal(),
            WorkflowHistoryEvent::query()->where('workflow_run_id', $task['run_id'])->count(),
        ];
    }
}
