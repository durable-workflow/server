<?php

namespace Tests\Source;

use App\Models\WorkflowNamespace;
use App\Support\CooperativeCancellationPolicy;
use App\Support\PreparedLocalActivityPolicy;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\CancellationScopeAdmission;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\ParallelChildGroup;

/** Requires the exact Native scope candidate. Never skipped in the Source lane. */
final class CancellationScopeAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'server.worker_protocol.version' => '1.20',
            'workflows.v2.workflow_task_lease_seconds' => 10,
            'workflows.v2.types.workflows' => ['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class],
        ]);
        WorkflowNamespace::query()->create(['name' => 'default', 'retention_days' => 30, 'status' => 'active']);
        $this->assertTrue(class_exists(CancellationScopeHistory::class));
    }

    public static function operations(): array
    {
        return [['schedule_activity'], ['start_timer'], ['start_child_workflow']];
    }

    #[DataProvider('operations')]
    public function test_unknown_scope_is_refused_without_publishing_a_stream_item(string $type): void
    {
        $task = $this->claim();
        $before = WorkflowHistoryEvent::query()->count();
        $this->complete($task, 'unknown-scope', $type)->assertStatus(409)
            ->assertJsonPath('recorded', false)->assertJsonPath('reason', 'operation_scope_not_recorded');
        $this->assertDatabaseCount('workflow_durable_stream_items', 0);
        $this->assertDatabaseCount('workflow_durable_streams', 0);
        $this->assertDatabaseCount('activity_executions', 0);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    #[DataProvider('operations')]
    public function test_recorded_scope_reaches_durable_history_and_commits_the_stream(string $type): void
    {
        $task = $this->claim();
        $run = WorkflowRun::query()->findOrFail($task['run_id']);
        $scope = CancellationScopeHistory::open($run, WorkflowTask::query()->findOrFail($task['task_id']), 1, '1.20');
        $scopeId = $scope->payload['scope_id'];
        $this->complete($task, $scopeId, $type)->assertOk()->assertJsonPath('recorded', true);
        $this->assertDatabaseCount('workflow_durable_stream_items', 1);
        $eventType = match ($type) {
            'schedule_activity' => 'ActivityScheduled', 'start_timer' => 'TimerScheduled', 'start_child_workflow' => 'ChildWorkflowScheduled',
        };
        $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->where('event_type', $eventType)->sole();
        $this->assertSame($scopeId, $type === 'schedule_activity' ? $event->payload['activity']['cancellation_scope_id'] : $event->payload['cancellation_scope_id']);
    }

    public function test_invalid_scope_is_refused_before_resolving_a_payload(): void
    {
        $task = $this->claim();
        $this->complete($task, 'unknown-scope', arguments: ['codec' => 'avro', 'invalid_reference' => true])
            ->assertStatus(409)->assertJsonPath('reason', 'operation_scope_not_recorded');
        $this->assertDatabaseCount('workflow_durable_stream_items', 0);
        $this->assertDatabaseCount('activity_executions', 0);
    }

    public function test_a_runtime_without_the_optional_admission_role_cannot_silently_drop_membership(): void
    {
        $task = $this->claim();
        $current = app(WorkflowTaskBridge::class);
        $older = \Mockery::mock(WorkflowTaskBridge::class);
        $older->shouldReceive('status')->andReturnUsing(fn (string $id) => $current->status($id));
        $older->shouldNotReceive('complete');
        $this->app->instance(WorkflowTaskBridge::class, $older);
        $this->complete($task, 'root')->assertStatus(409)->assertJsonPath('reason', 'cancellation_scope_membership_unavailable')
            ->assertJsonPath('unavailable', ['installed_runtime_scope_admission']);
        $this->assertDatabaseCount('workflow_durable_stream_items', 0);
    }

    public function test_a_legacy_request_cannot_silently_drop_membership(): void
    {
        $task = $this->claim();
        $this->complete($task, 'root', version: '1.19')->assertStatus(409)
            ->assertJsonPath('reason', 'cancellation_scope_membership_unavailable')->assertJsonPath('unavailable', ['request_protocol']);
        $this->assertDatabaseCount('workflow_durable_stream_items', 0);
    }

    public static function malformedScopes(): array
    {
        return [[null], [''], [true], [str_repeat('x', 256)]];
    }

    #[DataProvider('malformedScopes')]
    public function test_malformed_membership_is_refused_before_effects(mixed $scope): void
    {
        $task = $this->claim();
        $this->complete($task, $scope)->assertStatus(422);
        $this->assertDatabaseCount('workflow_durable_stream_items', 0);
        $this->assertDatabaseCount('activity_executions', 0);
    }

    public function test_scope_on_an_unrelated_command_is_refused_before_effects(): void
    {
        $task = $this->claim();
        $this->complete($task, 'root', 'record_side_effect')->assertStatus(409)->assertJsonPath('reason', 'invalid_cancellation_scope_command');
        $this->assertDatabaseCount('workflow_durable_stream_items', 0);
    }

    public function test_admission_is_rechecked_under_the_run_lock_before_stream_effects(): void
    {
        $task = $this->claim();
        $current = app(WorkflowTaskBridge::class);
        $bridge = \Mockery::mock(WorkflowTaskBridge::class.', '.CancellationScopeAdmission::class);
        $bridge->shouldReceive('status')->andReturnUsing(fn (string $id) => $current->status($id));
        $bridge->shouldReceive('validateCancellationScopeMembership')->twice()->andReturn(null, 'operation_scope_not_recorded');
        $bridge->shouldNotReceive('complete');
        $this->app->instance(WorkflowTaskBridge::class, $bridge);
        $this->complete($task, 'root')->assertStatus(409)->assertJsonPath('reason', 'operation_scope_not_recorded');
        $this->assertDatabaseCount('workflow_durable_stream_items', 0);
        $this->assertDatabaseCount('activity_executions', 0);
    }

    public static function groupScopes(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('groupScopes')]
    public function test_retained_prefix_rejects_unknown_scope_or_commits_stream_once(bool $valid): void
    {
        $task = $this->claim();
        $run = WorkflowRun::query()->findOrFail($task['run_id']);
        $scope = CancellationScopeHistory::open($run, WorkflowTask::query()->findOrFail($task['task_id']), 1, '1.20')->payload['scope_id'];
        $body = ['lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'checkpoint_id' => 'scope-prefix', 'start_sequence' => 2,
            'commands' => $this->commandBatch($task, $valid ? $scope : 'unknown-scope', 'start_timer')];
        $route = "/api/worker/workflow-tasks/{$task['task_id']}/local-activities/checkpoint";
        $response = $this->withHeaders($this->headers())->postJson($route, $body);
        if ($valid) {
            $response->assertOk()->assertJsonPath('checkpointed', true);
            $before = WorkflowHistoryEvent::query()->count();
            $this->withHeaders($this->headers())->postJson($route, $body)->assertOk()->assertJsonPath('duplicate', true);
            $this->assertSame($before, WorkflowHistoryEvent::query()->count());
            $this->assertDatabaseCount('workflow_durable_stream_items', 1);
        } else {
            $response->assertStatus(409)->assertJsonPath('reason', 'operation_scope_not_recorded');
            $this->assertDatabaseCount('workflow_durable_stream_items', 0);
            $this->assertDatabaseCount('workflow_run_timers', 0);
        }
    }

    #[DataProvider('groupScopes')]
    public function test_atomic_group_preflights_local_membership_before_any_sibling(bool $valid): void
    {
        $task = $this->claim();
        $run = WorkflowRun::query()->findOrFail($task['run_id']);
        $scope = CancellationScopeHistory::open($run, WorkflowTask::query()->findOrFail($task['task_id']), 1, '1.20')->payload['scope_id'];
        $before = WorkflowHistoryEvent::query()->count();
        $response = $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/local-activities/checkpoint-group", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'checkpoint_id' => 'scope-group', 'start_sequence' => 2, 'commands' => [[
                'type' => 'start_child_workflow', 'workflow_type' => 'tests.external-greeting-workflow',
                'arguments' => Serializer::serializeWithCodec('avro', ['child']), 'payload_codec' => 'avro', 'cancellation_scope_id' => $scope,
                ...ParallelChildGroup::itemMetadata(2, 2, 0, 'mixed'),
            ], [
                'type' => 'prepare_local_activity', 'activity_type' => 'opaque-local', 'payload_codec' => 'avro',
                'arguments' => $valid ? Serializer::serializeWithCodec('avro', ['Ada']) : ['codec' => 'avro', 'invalid_reference' => true],
                'cancellation_scope_id' => $valid ? $scope : 'unknown-scope',
                ...ParallelChildGroup::itemMetadata(2, 2, 1, 'mixed'),
            ]],
        ]);
        if ($valid) {
            $response->assertOk()->assertJsonPath('checkpointed', true);
            $this->assertSame($scope, ActivityExecution::query()->sole()->activity_options['cancellation_scope_id']);
        } else {
            $response->assertStatus(409)->assertJsonPath('reason', 'local_activity_scope_not_recorded');
            $this->assertSame($before, WorkflowHistoryEvent::query()->count());
            $this->assertDatabaseCount('activity_executions', 0);
            $this->assertDatabaseCount('workflow_child_calls', 0);
        }
    }

    private function claim(): array
    {
        $this->withHeaders(['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'])
            ->postJson('/api/workflows', ['workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'scope-admission', 'input' => ['Ada']])->assertCreated();
        $this->withHeaders($this->headers())->postJson('/api/worker/register', [
            'worker_id' => 'scope-worker', 'task_queue' => 'scope-admission', 'runtime' => 'php',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capabilities' => [CooperativeCancellationPolicy::CAPABILITY, PreparedLocalActivityPolicy::CAPABILITY, PreparedLocalActivityPolicy::GROUP_CAPABILITY],
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(), 'max_concurrent_workflow_tasks' => 1,
            'process_metrics' => ['host' => 'test-worker', 'process_started_at' => now()->toISOString(), 'process_id' => 10],
        ])->assertCreated();

        return $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'scope-worker', 'task_queue' => 'scope-admission',
        ])->assertOk()->json('task');
    }

    private function complete(array $task, mixed $scopeId, string $type = 'schedule_activity', mixed $arguments = null, string $version = '1.20')
    {
        return $this->withHeaders(['X-Namespace' => 'default', WorkerProtocol::HEADER => $version])->postJson("/api/worker/workflow-tasks/{$task['task_id']}/complete", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => $this->commandBatch($task, $scopeId, $type, $arguments),
        ]);
    }

    private function commandBatch(array $task, mixed $scopeId, string $type, mixed $arguments = null): array
    {
        $identity = $task['workflow_command_id'] ?: $task['task_id'];
        $operation = match ($type) {
            'schedule_activity' => ['activity_type' => 'opaque-activity', 'arguments' => $arguments ?? Serializer::serializeWithCodec('avro', ['Ada']), 'payload_codec' => 'avro'],
            'start_timer' => ['delay_seconds' => 60],
            'start_child_workflow' => ['workflow_type' => 'tests.external-greeting-workflow', 'arguments' => Serializer::serializeWithCodec('avro', ['child']), 'payload_codec' => 'avro'],
            'record_side_effect' => ['result' => Serializer::serializeWithCodec('avro', null)],
        };

        return [[
            'type' => 'record_side_effect', 'result' => Serializer::serializeWithCodec('avro', null),
            'workflow_stream' => ['operation' => 'append', 'stream_name' => 'tokens', 'command_identity' => $identity,
                'command_ordinal' => 0, 'items' => [['payload' => Serializer::serializeWithCodec('avro', ['token']),
                    'payload_codec' => 'avro', 'idempotency_key' => "dw-stream:{$identity}:0:0"]]],
        ], [
            'type' => $type, ...$operation, 'cancellation_scope_id' => $scopeId,
        ]];
    }

    private function headers(): array
    {
        return ['X-Namespace' => 'default', WorkerProtocol::HEADER => '1.20'];
    }
}
