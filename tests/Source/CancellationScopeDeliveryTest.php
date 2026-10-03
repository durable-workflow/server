<?php

namespace Tests\Source;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\CooperativeCancellationPolicy;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\StoragePressureFixture;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;
use Workflow\V2\Contracts\PreparedCancellationScopeTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\CancellationScopeRequests;

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

    public function test_preparation_and_delivery_reuse_first_boundary_without_releasing_or_renewing_claim(): void
    {
        [$task, $scope, $request] = $this->claim();
        $before = WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal();
        $prepared = $this->mutate('prepare', $task, $scope, $request)->assertOk()
            ->assertJsonPath('prepared', true)->assertJsonPath('delivered', false)
            ->assertJsonPath('claim_released', false)->assertJsonPath('activity_members', [])->json();
        $this->assertContract($prepared);
        $this->mutate('prepare', $task, $scope, $request)->assertOk()
            ->assertJsonPath('preparation_history_event_id', $prepared['preparation_history_event_id'])
            ->assertJsonPath('cancellation', $prepared['cancellation'])
            ->assertJsonPath('authority_deadline_at', $prepared['authority_deadline_at']);
        $delivery = $this->mutate('deliver', $task, $scope, $request)->assertOk()->assertJsonPath('delivered', true)->json();
        $this->assertContract($delivery);
        $this->mutate('deliver', $task, $scope, $request)->assertOk()->assertJsonPath('history_event_id', $delivery['history_event_id']);
        $this->assertSame($before, WorkflowTask::query()->findOrFail($task['task_id'])->getRawOriginal());
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeDeliveryPrepared')->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeDelivered')->count());
        $this->assertSame($prepared['preparation_history_event_id'], $delivery['preparation_history_event_id']);
    }

    public static function phases(): array
    {
        return [['prepare'], ['deliver']];
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
        $this->travel(1)->seconds();
        $claim = WorkflowTask::query()->findOrFail($task['task_id']);
        $claim->forceFill(['attempt_count' => $task['workflow_task_attempt'] + 1])->save();
        CooperativeCancellationPolicy::bindClaim($claim, CooperativeCancellationPolicy::workerSnapshot(
            WorkerRegistration::query()->where('worker_id', 'delivery-owner')->sole(), '1.20'));
        $this->mutate('prepare', $task, $scope, $request)->assertConflict()->assertJsonPath('reason', 'workflow_task_attempt_mismatch');
        $task['workflow_task_attempt']++;
        $this->mutate('prepare', $task, $scope, $request)->assertOk()
            ->assertJsonPath('preparation_history_event_id', $prepared['preparation_history_event_id'])
            ->assertJsonPath('cancellation', $prepared['cancellation'])
            ->assertJsonPath('authority_deadline_at', $prepared['authority_deadline_at']);
        $this->mutate('deliver', $task, $scope, $request)->assertOk()->assertJsonPath('delivered', true);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeDeliveryPrepared')->count());
    }

    public function test_missing_timer_actor_keeps_preparation_and_returns_explicit_diagnostic(): void
    {
        [$task, $scope, $request] = $this->claim(timer: true);
        $prepared = $this->mutate('prepare', $task, $scope, $request, ['call_kind' => 'timer'])->assertOk()->json();
        $response = $this->mutate('deliver', $task, $scope, $request, ['call_kind' => 'timer'])->assertConflict()
            ->assertJsonPath('delivered', false)->assertJsonPath('prepared', true)
            ->assertJsonPath('reason', 'cancellation_scope_operation_delivery_unavailable')
            ->assertJsonPath('unavailable', ['scoped_timer_delivery'])
            ->assertJsonPath('preparation_history_event_id', $prepared['preparation_history_event_id'])->json();
        $this->assertContract($response);
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeDelivered')->count());
        $this->assertSame('pending', WorkflowTimer::query()->sole()->status->value);
    }

    private function claim(bool $timer = false): array
    {
        $this->withHeaders($this->headers('operator'))->postJson('/api/workflows', [
            'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'scoped-delivery', 'input' => ['Ada'],
        ])->assertCreated();
        $this->withHeaders($this->headers())->postJson('/api/worker/register', [
            'worker_id' => 'delivery-owner', 'task_queue' => 'scoped-delivery', 'runtime' => 'php',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capabilities' => [CooperativeCancellationPolicy::CAPABILITY],
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
        $request = CancellationScopeRequests::request(WorkflowRun::query()->findOrFail($task['run_id']), $scope, '1.20', 30);

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
