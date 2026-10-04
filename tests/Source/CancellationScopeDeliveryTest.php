<?php

namespace Tests\Source;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\CooperativeCancellationPolicy;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\PreparedLocalActivityPolicy;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\StoragePressureFixture;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\PreparedCancellationScopeTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\CancellationScopeRequests;
use Workflow\V2\Support\CooperativeCancellationDelivery;
use Workflow\V2\Support\ParallelChildGroup;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

/** Source-only preparation commits outside the test's transaction. */
final class CancellationScopeDeliveryTest extends TestCase
{
    use DatabaseMigrations;
    use StoragePressureFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['server.worker_protocol.version' => '1.20',
            'server.auth.driver' => 'token',
            'server.auth.role_tokens' => ['operator' => 'fixture-operator', 'worker' => 'fixture-worker'],
            'workflows.v2.types.workflows' => ['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class]]);
        WorkflowNamespace::query()->create(['name' => 'default', 'retention_days' => 30, 'status' => 'active']);
    }

    public function test_preparation_and_delivery_reuse_first_boundary_with_recoverable_original_claim(): void
    {
        [$task, $scope, $request] = $this->claim();
        $before = WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal();
        $prepared = $this->mutate('prepare', $task, $scope, $request)->assertOk()
            ->assertJsonPath('prepared', true)->assertJsonPath('delivered', false)
            ->assertJsonPath('claim_released', false)->assertJsonPath('activity_members', [])->json();
        $this->assertContract($prepared);
        $this->assertRecoverableHostingClaim($before, $task['task_id']);
        $this->mutate('prepare', $task, $scope, $request)->assertOk()
            ->assertJsonPath('preparation_history_event_id', $prepared['preparation_history_event_id'])
            ->assertJsonPath('cancellation', $prepared['cancellation'])
            ->assertJsonPath('authority_deadline_at', $prepared['authority_deadline_at']);
        $this->assertRecoverableHostingClaim($before, $task['task_id']);
        $delivery = $this->mutate('deliver', $task, $scope, $request)->assertOk()->assertJsonPath('delivered', true)->json();
        $this->assertContract($delivery);
        $this->mutate('deliver', $task, $scope, $request)->assertOk()->assertJsonPath('history_event_id', $delivery['history_event_id']);
        $this->assertRecoverableHostingClaim($before, $task['task_id']);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeDeliveryPrepared')->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeDelivered')->count());
        $this->assertSame($prepared['preparation_history_event_id'], $delivery['preparation_history_event_id']);
    }

    public static function phases(): array
    {
        return [['prepare'], ['deliver']];
    }

    public function test_accepted_unprepared_request_recovers_through_watchdog_and_http_poll(): void
    {
        [$task, $scope, $request] = $this->claim();
        $accepted = WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeRequested')->sole();
        $before = WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal();
        $historyBefore = WorkflowHistoryEvent::query()->count();
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeDeliveryPrepared')->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeDelivered')->count());
        $this->travel(11)->seconds();
        $this->mutate('prepare', $task, $scope, $request)->assertConflict()
            ->assertJsonPath('reason', 'cancellation_scope_workflow_claim_mismatch');
        $this->assertSame($historyBefore, WorkflowHistoryEvent::query()->count());
        $this->assertSame($before, WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal());
        $repair = TaskWatchdog::runPass(respectThrottle: false, runIds: [$task['run_id']]);
        $this->assertSame([], $repair['existing_task_failures']);
        $this->assertSame(1, $repair['repaired_existing_tasks']);
        $replacement = $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'delivery-owner', 'task_queue' => 'scoped-delivery',
        ])->assertOk()->json('task');
        $this->assertSame($task['task_id'], $replacement['task_id']);
        $this->assertSame($task['workflow_task_attempt'] + 1, $replacement['workflow_task_attempt']);
        $run = WorkflowRun::query()->findOrFail($task['run_id']);
        $replacementBefore = WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal();
        $duplicate = CancellationScopeRequests::request($run, $scope, '1.20', 300);
        $this->assertSame($accepted->id, $duplicate->id);
        $this->assertEquals($accepted->payload, $duplicate->payload);
        $this->assertSame($replacementBefore, WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal());
        $this->mutate('prepare', $task, $scope, $request)->assertConflict()
            ->assertJsonPath('reason', 'workflow_task_attempt_mismatch');
        $prepared = $this->mutate('prepare', $replacement, $scope, $request)->assertOk()->json();
        $this->assertContract($prepared);
        $this->assertSame($request, $prepared['request_id']);
        $this->assertSame($accepted->payload['cancellation']['root_context']['cleanup_deadline_at'], $prepared['authority_deadline_at']);
        $this->assertNull($run->fresh()->cancellation_request_command_id);
        $this->assertRecoverableHostingClaim($replacementBefore, $task['task_id']);
        $this->mutate('deliver', $replacement, $scope, $request)->assertOk()
            ->assertJsonPath('delivered', true)->assertJsonPath('preparation_history_event_id', $prepared['preparation_history_event_id']);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeRequested')->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeDeliveryPrepared')->count());
    }

    #[DataProvider('phases')]
    public function test_invalid_authority_and_namespace_are_refused_without_effects(string $phase): void
    {
        [$task, $scope, $request] = $this->claim();
        WorkflowNamespace::query()->create(['name' => 'other', 'retention_days' => 30, 'status' => 'active']);
        $before = WorkflowHistoryEvent::query()->count();
        $original = WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal();
        $this->mutate($phase, $task, $scope, $request, ['lease_owner' => 'other'])->assertConflict()->assertJsonPath('reason', 'lease_owner_mismatch');
        $this->mutate($phase, $task, $scope, $request, ['workflow_task_attempt' => 99])->assertConflict()->assertJsonPath('reason', 'workflow_task_attempt_mismatch');
        $this->mutate($phase, $task, $scope, $request, namespace: 'other')->assertNotFound()->assertJsonPath('reason', 'task_not_found');
        $this->mutate($phase, $task, $scope, $request, role: 'operator')->assertForbidden();
        $this->mutate($phase, $task, $scope, $request, version: '1.19')->assertConflict()->assertJsonPath('reason', 'cancellation_scope_requires_protocol_1_20');
        $this->mutate($phase, $task, $scope, $request, version: '1.21')->assertBadRequest();
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->assertSame($original, WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal());
    }

    #[DataProvider('phases')]
    public function test_claim_capability_and_fresh_registration_are_required(string $phase): void
    {
        [$task, $scope, $request] = $this->claim();
        $before = WorkflowHistoryEvent::query()->count();
        $claim = WorkflowTask::query()->findOrFail($task['task_id']);
        $payload = $claim->payload;
        $claim->forceFill(['payload' => []])->save();
        $this->mutate($phase, $task, $scope, $request)->assertConflict()->assertJsonPath('reason', 'active_claim_cancellation_not_supported');
        $claim->forceFill(['payload' => $payload])->save();
        WorkerRegistration::query()->where('worker_id', 'delivery-owner')->update(['last_heartbeat_at' => now()->subHour()]);
        $this->mutate($phase, $task, $scope, $request)->assertConflict()->assertJsonPath('reason', 'stale_worker_registration');
        WorkerRegistration::query()->where('worker_id', 'delivery-owner')->update(['last_heartbeat_at' => now()]);
        $claim->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        $this->mutate($phase, $task, $scope, $request)->assertConflict()->assertJsonPath('reason', 'cancellation_scope_workflow_claim_mismatch');
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    #[DataProvider('phases')]
    public function test_missing_optional_backend_is_explicit(string $phase): void
    {
        [$task, $scope, $request] = $this->claim();
        $before = WorkflowHistoryEvent::query()->count();
        $this->app->instance(WorkflowTaskBridge::class, \Mockery::mock(WorkflowTaskBridge::class));
        $this->mutate($phase, $task, $scope, $request)->assertConflict()->assertJsonPath('reason', 'cancellation_scope_delivery_unavailable')
            ->assertJsonPath('unavailable', ['installed_runtime_scope_preparation_delivery']);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    public function test_delivery_without_preparation_and_changed_retry_are_refused(): void
    {
        [$task, $scope, $request] = $this->claim();
        $this->mutate('deliver', $task, $scope, $request)->assertConflict()->assertJsonPath('reason', 'cancellation_scope_delivery_not_prepared');
        $prepared = $this->mutate('prepare', $task, $scope, $request)->assertOk()->json();
        $before = WorkflowHistoryEvent::query()->count();
        $this->mutate('prepare', $task, $scope, $request, ['sequence' => 3])->assertConflict();
        $this->mutate('deliver', $task, $scope, $request, ['sequence' => 3])->assertConflict();
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->mutate('prepare', $task, $scope, $request)->assertOk()->assertJsonPath('preparation_history_event_id', $prepared['preparation_history_event_id']);
    }

    public function test_preparation_and_delivery_remain_available_during_storage_drain(): void
    {
        [$task, $scope, $request] = $this->claim();
        $this->configureStoragePressure('draining');
        try {
            $this->mutate('prepare', $task, $scope, $request)->assertOk()->assertJsonPath('prepared', true);
            $this->mutate('deliver', $task, $scope, $request)->assertOk()->assertJsonPath('delivered', true);
        } finally {
            $this->removeStoragePressure();
        }
    }

    public static function malformed(): array
    {
        return [[['scope_id' => '']], [['request_id' => null]], [['sequence' => 0]], [['call_kind' => 'unknown']],
            [['sequence_span' => 0]], [['operation_sequence' => 0]], [['workflow_task_attempt' => 0]]];
    }

    #[DataProvider('malformed')]
    public function test_malformed_boundary_is_rejected_before_history(array $overrides): void
    {
        [$task, $scope, $request] = $this->claim();
        $before = WorkflowHistoryEvent::query()->count();
        foreach (['prepare', 'deliver'] as $phase) {
            $this->mutate($phase, $task, $scope, $request, $overrides)->assertUnprocessable();
        }
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    #[DataProvider('phases')]
    public function test_claim_replaced_after_server_checks_is_refused_by_native_before_commit(string $phase): void
    {
        [$task, $scope, $request] = $this->claim();
        if ($phase === 'deliver') {
            $this->mutate('prepare', $task, $scope, $request)->assertOk();
        }
        $before = WorkflowHistoryEvent::query()->count();
        $native = app(WorkflowTaskBridge::class);
        $method = $phase === 'prepare' ? 'prepareCancellationScopeDelivery' : 'deliverCancellationScope';
        $bridge = \Mockery::mock(WorkflowTaskBridge::class.', '.PreparedCancellationScopeTaskBridge::class);
        $bridge->shouldReceive($method)->once()->andReturnUsing(function (...$arguments) use ($task, $native, $method): array {
            $this->assertSame(0, DB::connection()->transactionLevel());
            WorkflowTask::query()->findOrFail($task['task_id'])->forceFill(['lease_owner' => 'replacement'])->save();

            return $native->$method(...$arguments);
        });
        $this->app->instance(WorkflowTaskBridge::class, $bridge);
        $this->mutate($phase, $task, $scope, $request)->assertConflict()->assertJsonPath('reason', 'cancellation_scope_workflow_claim_mismatch');
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    public function test_replacement_claim_reuses_preparation_and_original_deadline(): void
    {
        [$task, $scope, $request] = $this->claim();
        $prepared = $this->mutate('prepare', $task, $scope, $request)->assertOk()->json();
        $this->travel(11)->seconds();
        $repair = TaskWatchdog::runPass(respectThrottle: false, runIds: [$task['run_id']]);
        $this->assertSame([], $repair['existing_task_failures']);
        $this->assertSame(1, $repair['repaired_existing_tasks']);
        $replacement = $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'delivery-owner', 'task_queue' => 'scoped-delivery',
        ])->assertOk()->json('task');
        $this->assertSame($task['task_id'], $replacement['task_id']);
        $this->assertSame($task['workflow_task_attempt'] + 1, $replacement['workflow_task_attempt']);
        $this->mutate('prepare', $task, $scope, $request)->assertConflict()->assertJsonPath('reason', 'workflow_task_attempt_mismatch');
        $this->mutate('prepare', $replacement, $scope, $request)->assertOk()
            ->assertJsonPath('preparation_history_event_id', $prepared['preparation_history_event_id'])
            ->assertJsonPath('cancellation', $prepared['cancellation'])
            ->assertJsonPath('authority_deadline_at', $prepared['authority_deadline_at']);
        $this->mutate('deliver', $replacement, $scope, $request)->assertOk()->assertJsonPath('delivered', true);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeDeliveryPrepared')->count());
    }

    public function test_timer_dispatch_reuses_original_preparation_and_fences_late_jobs(): void
    {
        [$task, $scope, $request] = $this->claim(timer: true);
        $prepared = $this->mutate('prepare', $task, $scope, $request, ['call_kind' => 'timer'])->assertOk()->json();
        $before = WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal();
        $timer = WorkflowTimer::query()->sole();
        $this->assertSame($timer->id, $prepared['timer_members'][0]['timer_id']);
        $response = $this->mutate('deliver', $task, $scope, $request, ['call_kind' => 'timer'])->assertOk()
            ->assertJsonPath('delivered', true)->assertJsonPath('prepared', true)
            ->assertJsonPath('timer_cancellations.0.fenced', true)
            ->assertJsonPath('timer_cancellations.0.timer_id', $timer->id)
            ->assertJsonPath('preparation_history_event_id', $prepared['preparation_history_event_id'])->json();
        $this->assertContract($response);
        $this->assertContract($prepared);
        $this->assertRecoverableHostingClaim($before, $task['task_id']);
        $duplicate = $this->mutate('deliver', $task, $scope, $request, ['call_kind' => 'timer'])->assertOk()->json();
        $this->assertSame($response['timer_cancellations'], $duplicate['timer_cancellations']);
        $this->assertSame($response['history_event_id'], $duplicate['history_event_id']);
        $this->assertSame($prepared['authority_deadline_at'], $duplicate['authority_deadline_at']);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeDelivered')->count());
        $this->assertSame(TimerStatus::Cancelled, $timer->fresh()->status);
        $timerTask = WorkflowTask::query()->where('task_type', TaskType::Timer)->sole();
        $timerTask->forceFill(['status' => TaskStatus::Ready, 'available_at' => now()->subSecond()])->save();
        $timer->refresh()->forceFill(['status' => TimerStatus::Pending])->save();
        $historyBefore = WorkflowHistoryEvent::query()->count();
        $this->app->call([new RunTimerTask($timerTask->id), 'handle']);
        $this->assertSame($historyBefore, WorkflowHistoryEvent::query()->count());
        $this->assertSame(TaskStatus::Cancelled, $timerTask->fresh()->status);
        $this->assertSame(TimerStatus::Cancelled, $timer->fresh()->status);
        $this->assertSame(1, WorkflowTask::query()->where('task_type', TaskType::Workflow)->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('event_type', 'TimerFired')->count());
    }

    #[DataProvider('waits')]
    public function test_scoped_wait_delivery_retains_original_membership_and_claim(string $kind, ?int $timeout): void
    {
        [$task, $scope, $request] = $this->claim();
        $run = WorkflowRun::query()->findOrFail($task['run_id']);
        $command = array_filter(['type' => 'open_'.$kind.'_wait', 'cancellation_scope_id' => $scope,
            'timeout_seconds' => $timeout, ...($kind === 'signal' ? ['signal_name' => 'ready'] : ['condition_key' => 'ready'])],
            static fn (mixed $value): bool => $value !== null);
        $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/complete", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [$command],
        ])->assertOk();
        $this->assertSame(TaskStatus::Completed, WorkflowTask::query()->findOrFail($task['task_id'])->status);
        $run->tasks()->create(['namespace' => $run->namespace, 'task_type' => TaskType::Workflow, 'status' => TaskStatus::Ready,
            'available_at' => now(), 'connection' => $run->connection, 'queue' => $run->queue, 'compatibility' => $run->compatibility]);
        $task = $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'delivery-owner', 'task_queue' => 'scoped-delivery',
        ])->assertOk()->json('task');
        $before = WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal();
        $prepared = $this->mutate('prepare', $task, $scope, $request, ['call_kind' => $kind])->assertOk()->json();
        $this->assertContract($prepared);
        $this->assertCount(1, $prepared['wait_members']);
        $this->assertSame($kind, $prepared['wait_members'][0]['kind']);
        $this->assertSame($timeout !== null, $prepared['wait_members'][0]['timer_id'] !== null);
        $response = $this->mutate('deliver', $task, $scope, $request, ['call_kind' => $kind])->assertOk()
            ->assertJsonPath('delivered', true)->assertJsonPath('wait_cancellations.0.cancelled', true)->json();
        $this->assertContract($response);
        $duplicate = $this->mutate('deliver', $task, $scope, $request, ['call_kind' => $kind])->assertOk()->json();
        $this->assertSame($response['wait_cancellations'], $duplicate['wait_cancellations']);
        $this->assertSame($response['history_event_id'], $duplicate['history_event_id']);
        $this->assertSame($prepared['wait_members'], $duplicate['wait_members']);
        $this->assertSame($prepared['authority_deadline_at'], $duplicate['authority_deadline_at']);
        $this->assertRecoverableHostingClaim($before, $task['task_id']);
        $marker = WorkflowHistoryEvent::query()->where('event_type', $kind === 'signal' ? 'SignalWaitCancelled' : 'ConditionWaitCancelled')->sole();
        $this->assertSame($prepared['preparation_history_event_id'], $marker->payload['cancellation_scope']['preparation_history_event_id']);
        if ($timeout !== null) {
            $this->assertSame(TimerStatus::Cancelled, $run->timers()->sole()->status);
            $this->assertSame(TaskStatus::Cancelled, $run->tasks()->where('task_type', TaskType::Timer)->sole()->status);
        }
    }

    public static function waits(): iterable
    {
        foreach (['signal', 'condition'] as $kind) {
            yield $kind.':untimed' => [$kind, null];
            yield $kind.':timed' => [$kind, 5];
        }
    }

    #[DataProvider('childPolicies')]
    public function test_child_delivery_reconciles_original_policy_receipts_without_releasing_the_host_claim(string $policy): void
    {
        [$task, $scope, $request] = $this->claim(childPolicy: $policy);
        $before = WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal();
        $prepared = $this->mutate('prepare', $task, $scope, $request, ['call_kind' => 'child'])->assertOk()->json();
        $this->assertContract($prepared);
        $this->assertCount(1, $prepared['child_members']);
        $scheduled = WorkflowHistoryEvent::query()->where('event_type', 'ChildWorkflowScheduled')->sole();
        $member = $prepared['child_members'][0];
        $this->assertSame($scheduled->payload['child_call_id'], $member['child_call_id']);
        $this->assertSame($scheduled->payload['child_workflow_instance_id'], $member['child_workflow_instance_id']);
        $this->assertSame($scheduled->payload['child_workflow_run_id'], $member['child_workflow_run_id']);
        $this->assertSame($policy, $member['cancellation_policy']);
        $this->assertSame($prepared['child_members'], $this->mutate('prepare', $task, $scope, $request,
            ['call_kind' => 'child'])->assertOk()->json('child_members'));
        $this->assertNull(WorkflowRun::query()->findOrFail($member['child_workflow_run_id'])->cancellation_request_command_id);
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeDelivered')->count());
        $response = $this->mutate('deliver', $task, $scope, $request, ['call_kind' => 'child']);
        if ($policy === 'wait_cancellation_completed') {
            $response->assertConflict()->assertJsonPath('delivered', false)
                ->assertJsonPath('reason', 'cancellation_scope_child_completion_not_established');
        } else {
            $response->assertOk()->assertJsonPath('delivered', true);
        }
        $delivery = $response->json();
        $this->assertContract($delivery);
        $this->assertCount(1, $delivery['child_cancellations']);
        $receipt = $delivery['child_cancellations'][0];
        $this->assertSame($member['child_call_id'], $receipt['child_call_id']);
        $this->assertSame($member['child_workflow_run_id'], $receipt['child_workflow_run_id']);
        $this->assertSame($policy, $receipt['policy']);
        $target = WorkflowStub::loadRun($member['child_workflow_run_id']);
        $context = CooperativeCancellationDelivery::context($target->run()->fresh());
        $this->assertSame($policy === 'abandon', $context === null);
        $this->assertFalse($target->run()->fresh()->status->isTerminal());
        if ($context !== null) {
            $this->assertSame($prepared['cancellation'], $context->scopeOrigin->toArray());
            $this->assertSame($prepared['authority_deadline_at'], $context->deadline()->toISOString());
            $this->assertSame($context->requestId, $receipt['request_id']);
        }
        $historyBefore = WorkflowHistoryEvent::query()->count();
        $duplicate = $this->mutate('deliver', $task, $scope, $request, ['call_kind' => 'child'])->json();
        $this->assertContract($duplicate);
        $this->assertSame($receipt, $duplicate['child_cancellations'][0]);
        $this->assertSame($delivery['authority_deadline_at'], $duplicate['authority_deadline_at']);
        $this->assertSame($historyBefore, WorkflowHistoryEvent::query()->count());
        if ($policy === 'wait_cancellation_completed') {
            $this->assertFalse($receipt['ready']);
            $this->assertNull($receipt['resolution_history_event_id']);
            $target->attemptCancel('canonical terminal fixture');
            $resolved = $this->mutate('deliver', $task, $scope, $request, ['call_kind' => 'child'])
                ->assertOk()->assertJsonPath('delivered', true)->json();
            $this->assertContract($resolved);
            $this->assertSame($receipt['history_event_id'], $resolved['child_cancellations'][0]['history_event_id']);
            $this->assertSame($receipt['request_id'], $resolved['child_cancellations'][0]['request_id']);
            $this->assertTrue($resolved['child_cancellations'][0]['ready']);
            $this->assertNotNull($resolved['child_cancellations'][0]['resolution_history_event_id']);
            $this->assertSame($prepared['authority_deadline_at'], $resolved['authority_deadline_at']);
        } else {
            $this->assertTrue($receipt['ready']);
            $this->assertSame($delivery['history_event_id'], $duplicate['history_event_id']);
        }
        $this->assertRecoverableHostingClaim($before, $task['task_id']);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'ChildCancellationRequested')->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeDelivered')->count());
    }

    public static function childPolicies(): iterable
    {
        foreach (['try_cancel', 'wait_cancellation_completed', 'abandon'] as $policy) {
            yield $policy => [$policy];
        }
    }

    public function test_scoped_cleanup_prepares_and_reuses_original_runtime_snapshot(): void
    {
        [$task, $scope, $request, $proof] = $this->cleanupClaim();
        $response = $this->cleanupLocal($task, 'prepare', $scope, $proof)->assertOk()
            ->assertJsonPath('prepared', true)->assertJsonPath('duplicate', false);
        $first = $response->json();
        $snapshot = $first['cancellation_cleanup'];
        $this->assertCleanupContract($snapshot);
        $this->assertSame($scope, $snapshot['scope_id']);
        $this->assertSame($scope, $snapshot['operation_scope_id']);
        $this->assertSame($request, $snapshot['request_id']);
        $this->assertSame($proof['delivery_history_event_id'], $snapshot['delivery_history_event_id']);
        $this->assertSame($snapshot['authority_deadline_at'], $first['schedule_to_close_deadline_at']);
        $before = WorkflowHistoryEvent::query()->count();
        $duplicate = $this->cleanupLocal($task, 'prepare', $scope, $proof)->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('activity_attempt_id', $first['activity_attempt_id'])->json();
        $duplicateSnapshot = $duplicate['cancellation_cleanup'];
        ksort($snapshot);
        ksort($duplicateSnapshot);
        $this->assertSame($snapshot, $duplicateSnapshot);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->assertNull(WorkflowRun::query()->findOrFail($task['run_id'])->cancellation_request_command_id);
    }

    public function test_scoped_cleanup_recovery_keeps_original_proof_and_refuses_stale_publication(): void
    {
        [$task, $scope, , $proof] = $this->cleanupClaim();
        $first = $this->cleanupLocal($task, 'prepare', $scope, $proof)->assertOk()->json();
        ActivityAttempt::query()->findOrFail($first['activity_attempt_id'])->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        WorkflowTask::query()->findOrFail($task['task_id'])->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        $replacement = $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'delivery-owner', 'task_queue' => 'scoped-delivery',
        ])->assertOk()->json('task');
        $this->assertSame($task['task_id'], $replacement['task_id']);
        $this->assertSame($task['workflow_task_attempt'] + 1, $replacement['workflow_task_attempt']);
        $recovery = $this->cleanupLocal($replacement, 'recover', $scope, $proof)->assertOk()
            ->assertJsonPath('recovered', true)->assertJsonPath('claim_released', true)
            ->assertJsonPath('callback_stop_state', 'unknown')->json();
        $snapshot = ActivityExecution::query()->findOrFail($first['activity_execution_id'])->activity_options['cancellation_cleanup'];
        $this->assertCleanupContract($snapshot);
        $expected = $first['cancellation_cleanup'];
        ksort($expected);
        ksort($snapshot);
        $this->assertSame($expected, $snapshot);
        $before = WorkflowHistoryEvent::query()->count();
        $this->cleanupLocal($replacement, 'recover', $scope, $proof)->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('event_id', $recovery['event_id']);
        $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/local-activities/{$first['activity_attempt_id']}/outcome", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'report' => ['outcome' => 'completed', 'result' => Serializer::serializeWithCodec('avro', 'stale'), 'payload_codec' => 'avro'],
        ])->assertConflict()->assertJsonPath('recorded', false);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('event_type', 'ActivityCompleted')->count());
        $retryTask = $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'delivery-owner', 'task_queue' => 'scoped-delivery',
        ])->assertOk()->json('task');
        $this->assertSame($recovery['created_task_ids'][0], $retryTask['task_id']);
        $retryResponse = $this->cleanupLocal($retryTask, 'prepare', $scope, $proof, workerAttempt: 'replacement-cleanup-attempt');
        $this->assertSame(200, $retryResponse->status(), json_encode($retryResponse->json('reason'), JSON_THROW_ON_ERROR));
        $retry = $retryResponse->assertJsonPath('prepared', true)->assertJsonPath('attempt_number', 2)->json();
        $this->assertNotSame($first['activity_attempt_id'], $retry['activity_attempt_id']);
        $retrySnapshot = $retry['cancellation_cleanup'];
        ksort($retrySnapshot);
        $this->assertSame($expected, $retrySnapshot);
        $this->assertSame($first['schedule_to_close_deadline_at'], $retry['schedule_to_close_deadline_at']);
        $body = [
            'lease_owner' => $retryTask['lease_owner'], 'workflow_task_attempt' => $retryTask['workflow_task_attempt'],
            'report' => ['outcome' => 'completed', 'result' => Serializer::serializeWithCodec('avro', 'resumed'), 'payload_codec' => 'avro'],
        ];
        OpenApiSchema::fromFile(resource_path('platform-protocol-specs/worker-protocol-api.openapi.yaml'))
            ->assertReferenceMatches('#/components/schemas/PreparedLocalOutcomeRequest',
                json_decode(json_encode($body, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR));
        $outcome = $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$retryTask['task_id']}/local-activities/{$retry['activity_attempt_id']}/outcome", $body);
        $this->assertSame(200, $outcome->status(), json_encode($outcome->json('reason'), JSON_THROW_ON_ERROR));
        $outcome->assertJsonPath('recorded', true)->assertJsonPath('claim_released', false);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeDelivered')->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'ActivityCompleted')->count());
        $this->assertNull(WorkflowRun::query()->findOrFail($task['run_id'])->cancellation_request_command_id);
    }

    public static function forgedCleanupProofs(): iterable
    {
        foreach (['prepare', 'recover'] as $operation) {
            foreach (['scope_id', 'request_id', 'delivery_history_event_id', 'cleanup_deadline_at'] as $field) {
                yield $operation.':'.$field => [$operation, $field];
            }
        }
    }

    #[DataProvider('forgedCleanupProofs')]
    public function test_scoped_cleanup_rejects_forged_authority_before_payload_resolution(string $operation, string $field): void
    {
        [$task, $scope, , $proof] = $this->cleanupClaim();
        $proof[$field] = 'invented-authority';
        $storage = \Mockery::mock(NamespaceExternalPayloadStorage::class);
        $storage->shouldNotReceive('driverFor');
        $this->app->instance(NamespaceExternalPayloadStorage::class, $storage);
        $before = WorkflowHistoryEvent::query()->count();
        $this->cleanupLocal($task, $operation, $scope, $proof, ['codec' => 'avro', 'invalid_reference' => true])
            ->assertConflict()->assertJsonPath('recorded', false)->assertJsonPath('reason', 'operation_scope_cancellation_prepared');
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->assertSame(0, ActivityExecution::query()->count());
        $this->assertSame(0, ActivityAttempt::query()->count());
    }

    public static function cleanupGroupValidity(): iterable
    {
        yield 'original proofs' => [false];
        yield 'second proof invalid' => [true];
    }

    #[DataProvider('cleanupGroupValidity')]
    public function test_scoped_cleanup_group_validates_all_proofs_before_any_sibling(bool $invalidSecond): void
    {
        [$task, $scope, , $proof] = $this->cleanupClaim();
        $commands = [];
        foreach ([0, 1] as $index) {
            $commands[] = [...$this->cleanupDescriptor($scope, $proof), 'type' => 'prepare_local_activity',
                ...ParallelChildGroup::itemMetadata(3, 2, $index, 'activity')];
        }
        if ($invalidSecond) {
            $commands[1]['cancellation_cleanup']['request_id'] = 'invented-authority';
            $commands[1]['arguments'] = ['codec' => 'avro', 'invalid_reference' => true];
        }
        $before = WorkflowHistoryEvent::query()->count();
        $response = $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/local-activities/checkpoint-group", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'checkpoint_id' => 'scoped-cleanup-group', 'start_sequence' => 3, 'commands' => $commands,
        ]);
        if ($invalidSecond) {
            $response->assertConflict()->assertJsonPath('recorded', false)->assertJsonPath('reason', 'operation_scope_cancellation_prepared');
            $this->assertSame($before, WorkflowHistoryEvent::query()->count());
            $this->assertSame(0, ActivityExecution::query()->count());
            $this->assertSame(0, ActivityAttempt::query()->count());
        } else {
            $response->assertOk()->assertJsonPath('checkpointed', true);
            $this->assertCount(2, $response->json('local_activities'));
            $this->assertSame(2, ActivityExecution::query()->count());
            foreach (ActivityExecution::query()->get() as $execution) {
                $this->assertCleanupContract($execution->activity_options['cancellation_cleanup']);
                $this->assertSame($proof['delivery_history_event_id'], $execution->activity_options['cancellation_cleanup']['delivery_history_event_id']);
            }
        }
    }

    private function cleanupClaim(): array
    {
        [$task, $scope, $request] = $this->claim(prepared: true);
        $this->mutate('prepare', $task, $scope, $request, ['call_kind' => 'local_activity'])->assertOk();
        $delivery = $this->mutate('deliver', $task, $scope, $request, ['call_kind' => 'local_activity'])->assertOk()->json();
        $proof = ['scope_id' => $scope, 'request_id' => $request, 'delivery_history_event_id' => $delivery['history_event_id']];
        OpenApiSchema::fromFile(resource_path('platform-protocol-specs/worker-protocol-api.openapi.yaml'))
            ->assertReferenceMatches('#/components/schemas/PreparedLocalCleanupProof', (object) $proof);

        return [$task, $scope, $request, $proof];
    }

    private function cleanupDescriptor(string $scope, array $proof, mixed $arguments = null): array
    {
        return ['type' => 'record_local_activity', 'activity_type' => 'opaque-local', 'payload_codec' => 'avro',
            'arguments' => $arguments ?? Serializer::serializeWithCodec('avro', []),
            'retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [0]],
            'cancellation_scope_id' => $scope, 'cancellation_cleanup' => $proof];
    }

    private function cleanupLocal(array $task, string $operation, string $scope, array $proof, mixed $arguments = null,
        string $workerAttempt = 'scoped-cleanup-attempt')
    {
        $body = [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'worker_attempt_id' => $workerAttempt, 'sequence' => 3,
            'descriptor' => $this->cleanupDescriptor($scope, $proof, $arguments),
        ];

        return $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/local-activities/{$operation}", $body);
    }

    private function assertCleanupContract(array $snapshot): void
    {
        OpenApiSchema::fromFile(resource_path('platform-protocol-specs/worker-protocol-api.openapi.yaml'))
            ->assertReferenceMatches('#/components/schemas/PreparedLocalCleanupSnapshot',
                json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR));
    }

    private function claim(bool $timer = false, ?string $childPolicy = null, bool $prepared = false): array
    {
        $this->withHeaders($this->headers('operator'))->postJson('/api/workflows', [
            'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'scoped-delivery', 'input' => ['Ada'],
        ])->assertCreated();
        $this->withHeaders($this->headers())->postJson('/api/worker/register', [
            'worker_id' => 'delivery-owner', 'task_queue' => 'scoped-delivery', 'runtime' => 'php',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capabilities' => [CooperativeCancellationPolicy::CAPABILITY,
                ...($prepared ? [PreparedLocalActivityPolicy::CAPABILITY, PreparedLocalActivityPolicy::GROUP_CAPABILITY] : [])],
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(), 'max_concurrent_workflow_tasks' => 1,
        ])->assertCreated();
        $task = $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'delivery-owner', 'task_queue' => 'scoped-delivery',
        ])->assertOk()->json('task');
        $scope = $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/cancellation-scopes/open", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'], 'sequence' => 1,
        ])->assertOk()->json('scope_id');
        if ($timer) {
            $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/cancellation-scopes/checkpoint", [
                'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
                'checkpoint_id' => 'timer-prefix', 'start_sequence' => 2,
                'commands' => [['type' => 'start_timer', 'delay_seconds' => 60, 'cancellation_scope_id' => $scope]],
            ])->assertOk()->assertJsonPath('checkpointed', true);
        }
        if ($childPolicy !== null) {
            $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/cancellation-scopes/checkpoint", [
                'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
                'checkpoint_id' => 'child-prefix', 'start_sequence' => 2,
                'commands' => [['type' => 'start_child_workflow', 'workflow_type' => 'tests.external-greeting-workflow',
                    'arguments' => Serializer::serializeWithCodec('avro', ['child']),
                    'payload_codec' => 'avro', 'cancellation_scope_id' => $scope, 'cancellation_policy' => $childPolicy]],
            ])->assertOk()->assertJsonPath('checkpointed', true);
        }
        $beforeRequest = WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal();
        $request = CancellationScopeRequests::request(WorkflowRun::query()->findOrFail($task['run_id']), $scope, '1.20', 30);
        $this->assertRecoverableHostingClaim($beforeRequest, $task['task_id'], shortened: true);

        return [$task, $scope, $request->payload['request_id']];
    }

    private function mutate(string $phase, array $task, string $scope, string $request, array $overrides = [],
        string $namespace = 'default', string $role = 'worker', string $version = '1.20')
    {
        return $this->withHeaders($this->headers($role, $namespace, $version))
            ->postJson("/api/worker/workflow-tasks/{$task['task_id']}/cancellation-scopes/{$phase}", array_replace([
                'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
                'scope_id' => $scope, 'request_id' => $request, 'sequence' => 2, 'call_kind' => 'activity',
            ], $overrides));
    }

    private function headers(string $role = 'worker', string $namespace = 'default', string $version = '1.20'): array
    {
        return ['Authorization' => 'Bearer fixture-'.$role, 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2', WorkerProtocol::HEADER => $version];
    }

    private function assertContract(array $response): void
    {
        OpenApiSchema::fromFile(resource_path('platform-protocol-specs/worker-protocol-api.openapi.yaml'))
            ->assertReferenceMatches('#/components/schemas/CancellationScopeDeliveryResponse/allOf/1',
                json_decode(json_encode($response, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR));
    }
}
