<?php

namespace Tests\Source;

use App\Models\RuntimePayloadCompletionBudget;
use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\CooperativeCancellationPolicy;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\PreparedLocalActivityPolicy;
use App\Support\RuntimePayloadCompletionContext;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\StoragePressureFixture;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\CancellationScopeAdmission;
use Workflow\V2\Contracts\CancellationScopeTaskBridge;
use Workflow\V2\Contracts\PreparedLocalActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Models\ActivityAttempt;
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
    use StoragePressureFixture;

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

    public function test_scope_prefix_requires_no_local_activity_capability_and_replays_before_opening(): void
    {
        $task = $this->claim(capabilities: [CooperativeCancellationPolicy::CAPABILITY]);
        $claim = WorkflowTask::query()->findOrFail($task['task_id']);
        $originalLease = $claim->lease_expires_at->toISOString();
        $first = $this->scopePrefix($task)->assertOk()->assertJsonPath('checkpointed', true)
            ->assertJsonPath('duplicate', false)->assertJsonPath('start_sequence', 1)->assertJsonPath('next_sequence', 2)->json();
        OpenApiSchema::fromFile(resource_path('platform-protocol-specs/worker-protocol-api.openapi.yaml'))
            ->assertReferenceMatches('#/components/schemas/CancellationScopeCheckpointResponse/allOf/1', json_decode(json_encode($first, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR));
        $this->assertIsString($first['history_refresh_page_token']);
        $before = WorkflowHistoryEvent::query()->count();
        $this->scopePrefix($task)->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('fingerprint', $first['fingerprint']);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->openScope($task, ['sequence' => 2])->assertOk()->assertJsonPath('opened', true);
        $this->assertSame($originalLease, $claim->refresh()->lease_expires_at->toISOString());
        $this->assertSame('leased', $claim->status->value);
        $this->assertArrayHasKey('portable_scope_checkpoint', $claim->payload);
        $this->assertArrayNotHasKey('portable_local_checkpoint', $claim->payload);
        $this->assertSame(0, ActivityExecution::query()->count());
    }

    public static function refusedScopePrefixes(): array
    {
        return [
            'wrong owner' => [['lease_owner' => 'other-worker'], 'lease_owner_mismatch'],
            'wrong attempt' => [['workflow_task_attempt' => 99], 'workflow_task_attempt_mismatch'],
            'future sequence' => [['start_sequence' => 2], 'cancellation_scope_checkpoint_sequence_mismatch'],
            'terminal command' => [['commands' => [['type' => 'complete_workflow', 'result' => Serializer::serializeWithCodec('avro', 'done')]]], 'invalid_cancellation_scope_checkpoint_commands'],
        ];
    }

    #[DataProvider('refusedScopePrefixes')]
    public function test_scope_prefix_refuses_before_committing_history(array $overrides, string $reason): void
    {
        $task = $this->claim(capabilities: [CooperativeCancellationPolicy::CAPABILITY]);
        $before = WorkflowHistoryEvent::query()->count();
        $this->scopePrefix($task, $overrides)->assertStatus(409)->assertJsonPath('reason', $reason);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    public function test_scope_prefix_changed_retry_and_replaced_claim_are_fenced(): void
    {
        $task = $this->claim(capabilities: [CooperativeCancellationPolicy::CAPABILITY]);
        $this->scopePrefix($task)->assertOk();
        $before = WorkflowHistoryEvent::query()->count();
        $this->scopePrefix($task, ['commands' => [['type' => 'record_side_effect', 'result' => Serializer::serializeWithCodec('avro', 'changed')]]])
            ->assertStatus(409)->assertJsonPath('reason', 'cancellation_scope_checkpoint_mismatch');
        WorkflowTask::query()->findOrFail($task['task_id'])->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        $replacement = $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $task['lease_owner'], 'task_queue' => 'scope-admission',
        ])->assertOk()->json('task');
        $this->scopePrefix($task)->assertStatus(409)->assertJsonPath('reason', 'workflow_task_attempt_mismatch');
        $this->openScope($replacement, ['sequence' => 2])->assertOk()->assertJsonPath('opened', true);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'SideEffectRecorded')->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'CancellationScopeOpened')->count());
    }

    public function test_scope_prefix_rejects_legacy_missing_backend_and_incompatible_issued_claim(): void
    {
        $task = $this->claim(capabilities: [CooperativeCancellationPolicy::CAPABILITY]);
        $this->scopePrefix($task, version: '1.19')->assertStatus(409)->assertJsonPath('reason', 'cancellation_scope_checkpoint_requires_protocol_1_20');
        $bridge = app(WorkflowTaskBridge::class);
        $this->app->instance(WorkflowTaskBridge::class, $this->createMock(WorkflowTaskBridge::class));
        $this->scopePrefix($task)->assertStatus(409)->assertJsonPath('reason', 'cancellation_scope_checkpoint_unavailable');
        $this->app->instance(WorkflowTaskBridge::class, $bridge);
        $claim = WorkflowTask::query()->findOrFail($task['task_id']);
        $payload = $claim->payload;
        $payload['_server_workflow_claim']['capabilities'] = [];
        $claim->forceFill(['payload' => $payload])->save();
        $this->scopePrefix($task)->assertStatus(409)->assertJsonPath('reason', 'active_claim_cancellation_not_supported');
    }

    public function test_scope_prefix_hides_other_namespaces_and_refuses_stale_workers(): void
    {
        $task = $this->claim(capabilities: [CooperativeCancellationPolicy::CAPABILITY]);
        WorkflowNamespace::query()->create(['name' => 'other', 'retention_days' => 30, 'status' => 'active']);
        $before = WorkflowHistoryEvent::query()->count();
        $this->scopePrefix($task, namespace: 'other')->assertNotFound();
        WorkerRegistration::query()->where('worker_id', $task['lease_owner'])->update(['last_heartbeat_at' => now()->subMinutes(10)]);
        $this->scopePrefix($task)->assertStatus(409)->assertJsonPath('reason', 'stale_worker_registration');
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    private function scopePrefix(array $task, array $overrides = [], string $version = '1.20', string $namespace = 'default')
    {
        return $this->withHeaders(['X-Namespace' => $namespace, WorkerProtocol::HEADER => $version])
            ->postJson("/api/worker/workflow-tasks/{$task['task_id']}/cancellation-scopes/checkpoint", array_replace([
                'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
                'checkpoint_id' => 'scope-prefix', 'start_sequence' => 1,
                'commands' => [['type' => 'record_side_effect', 'result' => Serializer::serializeWithCodec('avro', 'before-scope')]],
            ], $overrides));
    }

    public function test_scope_prefix_uploads_during_draining_share_one_fenced_claim_allowance(): void
    {
        $task = $this->claim(capabilities: [CooperativeCancellationPolicy::CAPABILITY]);
        $directory = storage_path('framework/testing/scope-prefix-uploads');
        WorkflowNamespace::query()->where('name', 'default')->update(['external_payload_storage' => [
            'driver' => 'local', 'enabled' => true, 'threshold_bytes' => 32,
            'config' => ['uri' => 'file://'.$directory],
        ]]);
        $this->configureStoragePressure('draining');
        $payload = Serializer::serializeWithCodec('avro', str_repeat('before-scope', 200));
        $context = [
            'schema' => RuntimePayloadCompletionContext::PREPARED_SCHEMA, 'kind' => 'workflow',
            'task_id' => $task['task_id'], 'attempt' => $task['workflow_task_attempt'], 'lease_owner' => $task['lease_owner'],
            'operation' => 'cancellation_scope_checkpoint', 'checkpoint_id' => 'scope-prefix', 'slot' => ['commands', 0, 'result'],
        ];
        $upload = fn (array $value) => $this->call('POST', '/api/external-payloads/v1', [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_NAMESPACE' => 'default',
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_CODEC' => 'avro', 'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SIZE' => (string) strlen($payload),
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SHA256' => hash('sha256', $payload),
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_COMPLETION' => json_encode($value, JSON_THROW_ON_ERROR),
        ], $payload);
        try {
            $reference = $upload($context)->assertCreated()->json('reference');
            $this->scopePrefix($task, ['commands' => [['type' => 'record_side_effect', 'result' => ['codec' => 'avro', 'external_payload' => $reference]]]])
                ->assertOk()->assertJsonPath('checkpointed', true);
            $stored = WorkflowHistoryEvent::query()->where('event_type', 'SideEffectRecorded')->sole()->payload['result'];
            $this->assertSame('avro', $stored['codec']);
            $this->assertSame(hash('sha256', $payload), $stored['external_storage']['sha256']);
            $fetch = $this->withHeaders([
                'X-Namespace' => 'default', 'X-Durable-Workflow-Payload-Codec' => $reference['codec'],
                'X-Durable-Workflow-Payload-Size' => (string) $reference['size_bytes'],
                'X-Durable-Workflow-Payload-SHA256' => $reference['sha256'],
            ])->get('/api/external-payloads/v1/'.$reference['reference_id'])->assertOk();
            $this->assertSame($payload, $fetch->streamedContent());
            $legacy = array_diff_key($context, ['checkpoint_id' => true]);
            $legacy['schema'] = RuntimePayloadCompletionContext::SCHEMA;
            $legacy['operation'] = 'complete';
            $upload($legacy)->assertCreated();
            $this->assertSame(1, RuntimePayloadCompletionBudget::query()->count());
            $this->openScope($task, ['sequence' => 2])->assertOk()->assertJsonPath('opened', true);
            $claim = WorkflowTask::query()->findOrFail($task['task_id']);
            $claim->forceFill(['lease_owner' => 'replacement', 'attempt_count' => $task['workflow_task_attempt'] + 1])->save();
            $context['checkpoint_id'] = 'new-prefix';
            $upload($context)->assertStatus(409);
        } finally {
            $this->removeStoragePressure();
            File::deleteDirectory($directory);
        }
    }

    public function test_scope_opening_records_once_and_preserves_the_live_claim(): void
    {
        $task = $this->claim();
        $claim = WorkflowTask::query()->findOrFail($task['task_id']);
        $originalLease = $claim->lease_expires_at->toISOString();
        $before = WorkflowHistoryEvent::query()->count();
        $response = $this->openScope($task)->assertOk()->assertJsonPath('opened', true)
            ->assertJsonPath('duplicate', false)->assertJsonPath('claim_released', false)
            ->assertJsonPath('parent_scope_id', 'root')->assertJsonPath('shield_parent', false)
            ->assertJsonPath('created_task_ids', []);
        OpenApiSchema::fromFile(resource_path('platform-protocol-specs/worker-protocol-api.openapi.yaml'))
            ->assertReferenceMatches('#/components/schemas/CancellationScopeOpeningResponse/allOf/1', json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR));
        $opened = $response->json();
        $this->assertSame($before + 1, WorkflowHistoryEvent::query()->count());
        $this->openScope($task)->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('history_event_id', $opened['history_event_id'])->assertJsonPath('scope_id', $opened['scope_id']);
        $this->assertSame($before + 1, WorkflowHistoryEvent::query()->count());
        $this->assertSame('leased', $claim->refresh()->status->value);
        $this->assertSame($originalLease, $claim->lease_expires_at->toISOString());
        $this->complete($task, $opened['scope_id'], 'start_timer')->assertOk()->assertJsonPath('recorded', true);
        $this->assertSame($opened['scope_id'], WorkflowHistoryEvent::query()->where('event_type', 'TimerScheduled')->sole()->payload['cancellation_scope_id']);
    }

    public function test_nested_shield_is_recorded_and_changed_replay_is_refused(): void
    {
        $task = $this->claim();
        $parent = $this->openScope($task)->assertOk()->json('scope_id');
        $nested = $this->openScope($task, ['sequence' => 2, 'parent_scope_id' => $parent, 'shield_parent' => true])
            ->assertOk()->assertJsonPath('parent_scope_id', $parent)->assertJsonPath('shield_parent', true)->json('scope_id');
        $this->assertNotSame($parent, $nested);
        $before = WorkflowHistoryEvent::query()->count();
        $this->openScope($task, ['sequence' => 2, 'parent_scope_id' => $parent, 'shield_parent' => false])
            ->assertStatus(409)->assertJsonPath('reason', 'cancellation_scope_replay_mismatch');
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    public function test_replacement_claim_reuses_the_scope_boundary_and_fences_the_old_attempt(): void
    {
        $task = $this->claim();
        $opened = $this->openScope($task)->assertOk()->json();
        WorkflowTask::query()->findOrFail($task['task_id'])->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        $replacement = $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $task['lease_owner'], 'task_queue' => 'scope-admission',
        ])->assertOk()->json('task');
        $this->assertSame($task['task_id'], $replacement['task_id']);
        $this->assertSame($task['workflow_task_attempt'] + 1, $replacement['workflow_task_attempt']);
        $before = WorkflowHistoryEvent::query()->count();
        $this->openScope($replacement)->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('history_event_id', $opened['history_event_id'])->assertJsonPath('scope_id', $opened['scope_id']);
        $this->openScope($task)->assertStatus(409)->assertJsonPath('reason', 'workflow_task_attempt_mismatch');
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    public static function refusedScopeOpenings(): array
    {
        return [
            'wrong owner' => [['lease_owner' => 'other-worker'], 'lease_owner_mismatch'],
            'wrong attempt' => [['workflow_task_attempt' => 99], 'workflow_task_attempt_mismatch'],
            'unknown parent' => [['parent_scope_id' => 'foreign-scope'], 'cancellation_scope_parent_not_recorded'],
            'future sequence' => [['sequence' => 2], 'cancellation_scope_sequence_mismatch'],
        ];
    }

    #[DataProvider('refusedScopeOpenings')]
    public function test_invalid_scope_opening_cannot_mutate_history(array $overrides, string $reason): void
    {
        $task = $this->claim();
        $before = WorkflowHistoryEvent::query()->count();
        $this->openScope($task, $overrides)->assertStatus(409)->assertJsonPath('opened', false)->assertJsonPath('reason', $reason);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    public function test_foreign_namespace_scope_opening_does_not_reveal_or_mutate_the_task(): void
    {
        $task = $this->claim();
        WorkflowNamespace::query()->create(['name' => 'other', 'retention_days' => 30, 'status' => 'active']);
        $before = WorkflowHistoryEvent::query()->count();
        $this->openScope($task, namespace: 'other')->assertNotFound()->assertJsonPath('reason', 'task_not_found');
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    public function test_stale_worker_cannot_open_a_scope_with_a_live_task_lease(): void
    {
        $task = $this->claim();
        WorkerRegistration::query()->where('worker_id', $task['lease_owner'])->update(['status' => 'inactive']);
        $before = WorkflowHistoryEvent::query()->count();
        $this->openScope($task)->assertStatus(409)->assertJsonPath('reason', 'stale_worker_registration');
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    public function test_expired_claim_cannot_open_or_replay_a_scope(): void
    {
        $task = $this->claim();
        $this->openScope($task)->assertOk();
        $before = WorkflowHistoryEvent::query()->count();
        WorkflowTask::query()->findOrFail($task['task_id'])->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        $this->openScope($task)->assertStatus(409)->assertJsonPath('reason', 'cancellation_scope_workflow_claim_mismatch');
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    public function test_incompatible_issued_claim_cannot_be_upgraded_by_current_registration(): void
    {
        $task = $this->claim();
        $claim = WorkflowTask::query()->findOrFail($task['task_id']);
        $payload = $claim->payload;
        $payload['_server_workflow_claim']['capabilities'] = [];
        $claim->forceFill(['payload' => $payload])->save();
        $before = WorkflowHistoryEvent::query()->count();
        $this->openScope($task)->assertStatus(409)->assertJsonPath('reason', 'active_claim_cancellation_not_supported');
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    public function test_scope_opening_rejects_legacy_protocol_and_missing_optional_backend(): void
    {
        $task = $this->claim();
        $this->openScope($task, version: '1.19')->assertStatus(409)->assertJsonPath('reason', 'cancellation_scope_requires_protocol_1_20');
        $this->assertInstanceOf(CancellationScopeTaskBridge::class, app(WorkflowTaskBridge::class));
        $this->app->instance(WorkflowTaskBridge::class, \Mockery::mock(WorkflowTaskBridge::class));
        $this->openScope($task)->assertStatus(409)->assertJsonPath('reason', 'cancellation_scope_opening_unavailable')
            ->assertJsonPath('unavailable', ['installed_runtime_scope_opening']);
    }

    public function test_scope_opening_requires_authenticated_worker_authority(): void
    {
        $task = $this->claim();
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'scope-test-token']);
        $before = WorkflowHistoryEvent::query()->count();
        $this->openScope($task)->assertUnauthorized();
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->withHeaders(['Authorization' => 'Bearer scope-test-token']);
        $this->openScope($task)->assertOk()->assertJsonPath('opened', true);
    }

    public static function malformedScopeOpenings(): array
    {
        return [
            [['sequence' => 0]], [['workflow_task_attempt' => null]], [['lease_owner' => '']],
            [['parent_scope_id' => '']], [['parent_scope_id' => str_repeat('x', 256)]], [['shield_parent' => 'invalid']],
        ];
    }

    #[DataProvider('malformedScopeOpenings')]
    public function test_malformed_scope_opening_is_rejected_before_history(array $overrides): void
    {
        $task = $this->claim();
        $before = WorkflowHistoryEvent::query()->count();
        $this->openScope($task, $overrides)->assertStatus(422);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    private function openScope(array $task, array $overrides = [], string $version = '1.20', string $namespace = 'default')
    {
        return $this->withHeaders(['X-Namespace' => $namespace, WorkerProtocol::HEADER => $version])
            ->postJson("/api/worker/workflow-tasks/{$task['task_id']}/cancellation-scopes/open", array_replace([
                'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'], 'sequence' => 1,
            ], $overrides));
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

    public static function singleLocalOperations(): array
    {
        return [['prepare'], ['recover']];
    }

    #[DataProvider('singleLocalOperations')]
    public function test_single_local_rejects_unknown_scope_before_payload_resolution(string $operation): void
    {
        $task = $this->claim();
        $storage = \Mockery::mock(NamespaceExternalPayloadStorage::class);
        $storage->shouldNotReceive('driverFor');
        $this->app->instance(NamespaceExternalPayloadStorage::class, $storage);
        $before = WorkflowHistoryEvent::query()->count();
        $this->singleLocal($task, $operation, 'unknown-scope', arguments: ['codec' => 'avro', 'invalid_reference' => true])
            ->assertConflict()->assertJsonPath('recorded', false)->assertJsonPath('reason', 'local_activity_scope_not_recorded');
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->assertDatabaseCount('activity_executions', 0);
        $this->assertDatabaseCount('activity_attempts', 0);
    }

    public static function malformedSingleLocalScopes(): array
    {
        $cases = [];
        foreach (self::singleLocalOperations() as [$operation]) {
            foreach (self::malformedScopes() as [$scope]) {
                $cases[] = [$operation, $scope];
            }
        }

        return $cases;
    }

    #[DataProvider('malformedSingleLocalScopes')]
    public function test_single_local_validates_scope_syntax_before_payload_resolution(string $operation, mixed $scope): void
    {
        $task = $this->claim();
        $this->singleLocal($task, $operation, $scope, arguments: ['codec' => 'avro', 'invalid_reference' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('descriptor.cancellation_scope_id');
        $this->assertDatabaseCount('activity_executions', 0);
        $this->assertDatabaseCount('activity_attempts', 0);
    }

    #[DataProvider('singleLocalOperations')]
    public function test_single_local_rejects_a_legacy_request_before_payload_resolution(string $operation): void
    {
        $task = $this->claim();
        $this->singleLocal($task, $operation, 'root', arguments: ['codec' => 'avro', 'invalid_reference' => true], version: '1.19')
            ->assertConflict()->assertJsonPath('reason', 'prepared_local_activity_not_supported')
            ->assertJsonPath('unavailable', ['request_protocol']);
        $this->assertDatabaseCount('activity_executions', 0);
    }

    #[DataProvider('singleLocalOperations')]
    public function test_single_local_rejects_a_backend_without_scope_admission_before_payload_resolution(string $operation): void
    {
        $task = $this->claim();
        $older = \Mockery::mock(PreparedLocalActivityTaskBridge::class);
        $older->shouldNotReceive('prepareLocalActivity');
        $older->shouldNotReceive('recoverLocalActivity');
        $this->app->instance(WorkflowTaskBridge::class, $older);
        $this->singleLocal($task, $operation, 'root', arguments: ['codec' => 'avro', 'invalid_reference' => true])
            ->assertConflict()->assertJsonPath('reason', 'cancellation_scope_membership_unavailable')
            ->assertJsonPath('unavailable', ['installed_runtime_scope_admission']);
        $this->assertDatabaseCount('activity_executions', 0);
    }

    #[DataProvider('singleLocalOperations')]
    public function test_single_local_cannot_use_a_scope_before_its_recorded_sequence(string $operation): void
    {
        $task = $this->claim();
        $scope = CancellationScopeHistory::open(WorkflowRun::query()->findOrFail($task['run_id']),
            WorkflowTask::query()->findOrFail($task['task_id']), 1, '1.20')->payload['scope_id'];
        $this->singleLocal($task, $operation, $scope, sequence: 1, arguments: ['codec' => 'avro', 'invalid_reference' => true])
            ->assertConflict()->assertJsonPath('reason', 'local_activity_scope_not_recorded');
        $this->assertDatabaseCount('activity_executions', 0);
    }

    #[DataProvider('singleLocalOperations')]
    public function test_single_local_cannot_use_a_scope_from_another_run(string $operation): void
    {
        $other = $this->claim();
        $scope = CancellationScopeHistory::open(WorkflowRun::query()->findOrFail($other['run_id']),
            WorkflowTask::query()->findOrFail($other['task_id']), 1, '1.20')->payload['scope_id'];
        $task = $this->claim('other-scope-worker');
        $this->singleLocal($task, $operation, $scope, arguments: ['codec' => 'avro', 'invalid_reference' => true])
            ->assertConflict()->assertJsonPath('reason', 'local_activity_scope_not_recorded');
        $this->assertDatabaseCount('activity_executions', 0);
    }

    public function test_single_local_records_scope_and_recovers_once_after_claim_replacement(): void
    {
        $task = $this->claim();
        $scope = CancellationScopeHistory::open(WorkflowRun::query()->findOrFail($task['run_id']),
            WorkflowTask::query()->findOrFail($task['task_id']), 1, '1.20')->payload['scope_id'];
        $first = $this->singleLocal($task, 'prepare', $scope)->assertOk()->assertJsonPath('prepared', true)->json();
        $before = WorkflowHistoryEvent::query()->count();
        $this->singleLocal($task, 'prepare', $scope)->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('activity_attempt_id', $first['activity_attempt_id']);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->assertSame($scope, ActivityExecution::query()->sole()->activity_options['cancellation_scope_id']);
        ActivityAttempt::query()->findOrFail($first['activity_attempt_id'])->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        WorkflowTask::query()->findOrFail($task['task_id'])->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        $replacement = $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'scope-worker', 'task_queue' => 'scope-admission',
        ])->assertOk()->json('task');
        $this->assertSame($task['task_id'], $replacement['task_id']);
        $this->assertSame($task['workflow_task_attempt'] + 1, $replacement['workflow_task_attempt']);
        $recovered = $this->singleLocal($replacement, 'recover', $scope)->assertOk()->assertJsonPath('recovered', true)
            ->assertJsonPath('claim_released', true)->assertJsonPath('callback_stop_state', 'unknown')->json();
        $before = WorkflowHistoryEvent::query()->count();
        $this->singleLocal($replacement, 'recover', $scope)->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('event_id', $recovered['event_id']);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->assertSame($scope, ActivityExecution::query()->sole()->activity_options['cancellation_scope_id']);
    }

    public function test_single_local_omitted_scope_keeps_preparation_compatible(): void
    {
        $task = $this->claim();
        $this->singleLocal($task, 'prepare', sequence: 1, includeScope: false)->assertOk()->assertJsonPath('prepared', true);
        $this->assertArrayNotHasKey('cancellation_scope_id', ActivityExecution::query()->sole()->activity_options);
    }

    private function singleLocal(array $task, string $operation, mixed $scope = null, int $sequence = 2, mixed $arguments = null, string $version = '1.20', bool $includeScope = true)
    {
        return $this->withHeaders(['X-Namespace' => 'default', WorkerProtocol::HEADER => $version])
            ->postJson("/api/worker/workflow-tasks/{$task['task_id']}/local-activities/{$operation}", [
                'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
                'worker_attempt_id' => 'scope-local-attempt', 'sequence' => $sequence,
                'descriptor' => ['type' => 'record_local_activity', 'activity_type' => 'opaque-local', 'payload_codec' => 'avro',
                    'arguments' => $arguments ?? Serializer::serializeWithCodec('avro', ['Ada']),
                    'retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [2]],
                    ...($includeScope ? ['cancellation_scope_id' => $scope] : [])],
            ]);
    }

    private function claim(string $worker = 'scope-worker', ?array $capabilities = null): array
    {
        $this->withHeaders(['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'])
            ->postJson('/api/workflows', ['workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'scope-admission', 'input' => ['Ada']])->assertCreated();
        $this->withHeaders($this->headers())->postJson('/api/worker/register', [
            'worker_id' => $worker, 'task_queue' => 'scope-admission', 'runtime' => 'php',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capabilities' => $capabilities ?? [CooperativeCancellationPolicy::CAPABILITY, PreparedLocalActivityPolicy::CAPABILITY, PreparedLocalActivityPolicy::GROUP_CAPABILITY],
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(), 'max_concurrent_workflow_tasks' => 1,
            'process_metrics' => ['host' => 'test-worker', 'process_started_at' => now()->toISOString(), 'process_id' => 10],
        ])->assertCreated();

        return $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $worker, 'task_queue' => 'scope-admission',
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
