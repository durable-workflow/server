<?php

namespace Tests\Source;

use App\Models\WorkflowNamespace;
use App\Support\CooperativeCancellationPolicy;
use App\Support\PreparedLocalActivityPolicy;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\PreparedLocalActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ParallelChildGroup;

/** Requires an exact Native candidate overlay; never skipped in its Source lane. */
final class PreparedLocalCancellationPolicyTest extends TestCase
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
        WorkflowNamespace::query()->create(['name' => 'default', 'description' => 'Test', 'retention_days' => 30, 'status' => 'active']);
        $this->assertSame(['try_cancel', 'wait_cancellation_completed'], PreparedLocalActivityPolicy::cancellationPolicies());
    }

    public static function policies(): array
    {
        return [['try_cancel'], ['wait_cancellation_completed']];
    }

    #[DataProvider('policies')]
    public function test_explicit_policy_is_admitted_once_and_canonical_history_preserves_it(string $policy): void
    {
        $task = $this->claim();
        $descriptor = $this->descriptor($policy);
        $first = $this->local($task, 'prepare', $descriptor)->assertOk()->assertJsonPath('prepared', true)->json();
        $before = WorkflowHistoryEvent::query()->count();
        $this->local($task, 'prepare', $descriptor)->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('activity_attempt_id', $first['activity_attempt_id']);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->assertSame($policy, ActivityExecution::query()->sole()->activity_options['cancellation_policy']);
        foreach ([HistoryEventType::ActivityScheduled, HistoryEventType::ActivityStarted] as $eventType) {
            $event = WorkflowHistoryEvent::query()->where('event_type', $eventType)->sole();
            $this->assertSame('local', $event->payload['execution_mode']);
            $this->assertSame($policy, $event->payload['activity']['cancellation_policy']);
        }
        $changed = $policy === 'try_cancel' ? 'wait_cancellation_completed' : 'try_cancel';
        $this->local($task, 'prepare', $this->descriptor($changed))->assertStatus(409)->assertJsonPath('reason', 'local_activity_preparation_mismatch');
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
    }

    public function test_default_protocol_and_an_older_optional_role_do_not_advertise_policies(): void
    {
        config(['server.worker_protocol.version' => '1.19']);
        $this->assertSame([], WorkerProtocol::serverCapabilities()['prepared_local_activity_cancellation_policies']);
        config(['server.worker_protocol.version' => '1.20']);
        $task = $this->claim();
        $older = \Mockery::mock(PreparedLocalActivityTaskBridge::class);
        $this->app->instance(WorkflowTaskBridge::class, $older);
        $this->assertTrue(PreparedLocalActivityPolicy::backendSupported());
        $this->assertSame([], WorkerProtocol::serverCapabilities()['prepared_local_activity_cancellation_policies']);
        $this->local($task, 'prepare', $this->descriptor('try_cancel'))->assertStatus(409)
            ->assertJsonPath('unavailable', ['installed_runtime_local_activity_cancellation_policy']);
        $this->assertDatabaseCount('activity_executions', 0);
    }

    #[DataProvider('policies')]
    public function test_replay_requires_the_specific_policy_supported_by_the_installed_backend(string $policy): void
    {
        $task = $this->claim();
        $this->local($task, 'prepare', $this->descriptor($policy))->assertOk();
        $restricted = \Mockery::mock(PreparedLocalActivityTaskBridge::class.', '.LocalCancellationPolicyBridge::class);
        $restricted->shouldReceive('supportedLocalActivityCancellationPolicies')->andReturn(['try_cancel']);
        $this->app->instance(WorkflowTaskBridge::class, $restricted);
        $this->assertSame(['try_cancel'], WorkerProtocol::serverCapabilities()['prepared_local_activity_cancellation_policies']);
        $this->assertSame($policy === 'try_cancel', PreparedLocalActivityPolicy::canReplayRun($task['run_id'], $this->capabilities(), '1.20'));
        $this->assertFalse(PreparedLocalActivityPolicy::canReplayRun($task['run_id'], $this->capabilities(false), '1.20'));
        $this->local($task, 'recover', $this->descriptor('wait_cancellation_completed'))->assertStatus(409)
            ->assertJsonPath('unavailable', ['installed_runtime_local_activity_cancellation_policy']);
    }

    public function test_registration_requires_preparation_cooperation_and_protocol_1_20(): void
    {
        foreach ([['1.20', []], ['1.20', [CooperativeCancellationPolicy::CAPABILITY]],
            ['1.20', [PreparedLocalActivityPolicy::CAPABILITY]],
            ['1.19', [CooperativeCancellationPolicy::CAPABILITY, PreparedLocalActivityPolicy::CAPABILITY]]] as [$version, $capabilities]) {
            $this->register('unsupported', [...$capabilities, PreparedLocalActivityPolicy::CANCELLATION_POLICIES_CAPABILITY], $version)
                ->assertStatus(409)->assertJsonPath('registered', false)
                ->assertJsonPath('reason', 'prepared_local_activity_cancellation_policy_capability_mismatch');
        }
        $this->assertDatabaseCount('workflow_worker_registrations', 0);
    }

    public function test_registration_changes_cannot_upgrade_an_existing_claim_or_resolve_its_payload(): void
    {
        $task = $this->claim(false);
        $before = WorkflowHistoryEvent::query()->count();
        $this->register('original', $this->capabilities())->assertCreated();
        $descriptor = [...$this->descriptor('try_cancel'), 'arguments' => ['codec' => 'avro', 'invalid_reference' => true]];
        foreach (['prepare', 'recover'] as $operation) {
            $this->local($task, $operation, $descriptor)->assertStatus(409)
                ->assertJsonPath('reason', 'prepared_local_activity_cancellation_policy_not_supported')
                ->assertJsonPath('worker_id', 'original')->assertJsonPath('requested_policy', 'try_cancel')
                ->assertJsonPath('required_capability', PreparedLocalActivityPolicy::CANCELLATION_POLICIES_CAPABILITY)
                ->assertJsonPath('unavailable', ['worker_claim_cancellation_policy_capability']);
        }
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->assertDatabaseCount('activity_executions', 0);
        $this->assertDatabaseCount('activity_attempts', 0);
    }

    public function test_omission_preserves_admission_for_an_older_prepared_claim(): void
    {
        $task = $this->claim(false);
        $descriptor = $this->descriptor('try_cancel');
        unset($descriptor['cancellation_policy']);
        $this->local($task, 'prepare', $descriptor)->assertOk()->assertJsonPath('prepared', true);
        $this->assertArrayNotHasKey('cancellation_policy', ActivityExecution::query()->sole()->activity_options);
        WorkflowTask::query()->findOrFail($task['task_id'])->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        $this->register('legacy', $this->capabilities(false))->assertCreated();
        $this->assertSame($task['task_id'], $this->poll('legacy')->assertOk()->json('task.task_id'));
    }

    public static function invalidPolicies(): array
    {
        return [['abandon'], [null], ['unknown'], [true]];
    }

    #[DataProvider('invalidPolicies')]
    public function test_invalid_or_abandoned_local_policy_is_refused_before_payload_and_admission(mixed $policy): void
    {
        $task = $this->claim();
        $before = WorkflowHistoryEvent::query()->count();
        $this->local($task, 'prepare', [...$this->descriptor($policy), 'schedule_to_close_timeout' => 30,
            'arguments' => ['codec' => 'avro', 'invalid_reference' => true]])->assertStatus(409)
            ->assertJsonPath('recorded', false)->assertJsonPath('unavailable', ['local_activity_cancellation_policy']);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->assertDatabaseCount('activity_executions', 0);
    }

    #[DataProvider('policies')]
    public function test_replacement_requires_the_recorded_policy_and_recovers_without_changing_its_lifetime(string $policy): void
    {
        $task = $this->claim();
        $descriptor = [...$this->descriptor($policy), 'schedule_to_close_timeout' => 60,
            'retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [2]]];
        $first = $this->local($task, 'prepare', $descriptor)->assertOk()->json();
        ActivityAttempt::query()->findOrFail($first['activity_attempt_id'])->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        WorkflowTask::query()->findOrFail($task['task_id'])->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        $this->register('legacy', $this->capabilities(false))->assertCreated();
        $history = WorkflowHistoryEvent::query()->count();
        $this->poll('legacy')->assertOk()->assertJsonPath('task', null);
        // Poll maintenance records the expired lease's repair request even
        // when this poller cannot receive or replay the resulting claim.
        $this->assertSame($history + 1, WorkflowHistoryEvent::query()->count());
        $this->assertSame(HistoryEventType::RepairRequested, WorkflowHistoryEvent::query()->where('workflow_run_id', $task['run_id'])->orderByDesc('sequence')->firstOrFail()->event_type);
        $this->assertSame($task['workflow_task_attempt'], WorkflowTask::query()->findOrFail($task['task_id'])->attempt_count);
        $this->register('replacement', $this->capabilities())->assertCreated();
        $replacement = $this->poll('replacement')->assertOk()->json('task');
        $this->assertSame($task['task_id'], $replacement['task_id']);
        $this->assertSame($task['workflow_task_attempt'] + 1, $replacement['workflow_task_attempt']);
        $recovered = $this->local($replacement, 'recover', $descriptor)->assertOk()
            ->assertJsonPath('recovered', true)->assertJsonPath('claim_released', true)->assertJsonPath('callback_stop_state', 'unknown')->json();
        $this->local($replacement, 'recover', $descriptor)->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('event_id', $recovered['event_id']);
        $this->travel(2)->seconds();
        $retry = $this->poll('replacement')->assertOk()->json('task');
        $second = $this->local($retry, 'prepare', $descriptor, 'sdk-recovered')->assertOk()->assertJsonPath('attempt_number', 2)->json();
        $this->assertSame($first['schedule_to_close_deadline_at'], $second['schedule_to_close_deadline_at']);
        $this->assertSame($policy, ActivityExecution::query()->sole()->activity_options['cancellation_policy']);
    }

    #[DataProvider('policies')]
    public function test_group_policy_is_checked_before_any_child_or_local_member_is_committed(string $policy): void
    {
        $task = $this->claim(false, true);
        $before = WorkflowHistoryEvent::query()->count();
        $this->group($task, $policy)->assertStatus(409)->assertJsonPath('unavailable', ['worker_claim_cancellation_policy_capability']);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->assertDatabaseCount('workflow_runs', 1);
        $this->assertDatabaseCount('activity_executions', 0);
        $this->assertArrayNotHasKey('portable_local_group_checkpoint', WorkflowTask::query()->findOrFail($task['task_id'])->payload);
    }

    #[DataProvider('policies')]
    public function test_capable_group_records_the_explicit_local_policy(string $policy): void
    {
        $task = $this->claim(true, true);
        $this->group($task, $policy)->assertOk()->assertJsonPath('checkpointed', true);
        $event = WorkflowHistoryEvent::query()->where('event_type', HistoryEventType::ActivityScheduled)->sole();
        $this->assertSame('local', $event->payload['execution_mode']);
        $this->assertSame($policy, $event->payload['activity']['cancellation_policy']);
        $this->assertDatabaseCount('workflow_runs', 2);
        $this->assertDatabaseCount('activity_executions', 1);
    }

    private function capabilities(bool $policy = true, bool $group = false): array
    {
        return [CooperativeCancellationPolicy::CAPABILITY, PreparedLocalActivityPolicy::CAPABILITY,
            ...($policy ? [PreparedLocalActivityPolicy::CANCELLATION_POLICIES_CAPABILITY] : []),
            ...($group ? [PreparedLocalActivityPolicy::GROUP_CAPABILITY] : [])];
    }

    private function claim(bool $policy = true, bool $group = false): array
    {
        $this->withHeaders(['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'])->postJson('/api/workflows', [
            'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'local-policy', 'input' => ['Ada'],
        ])->assertCreated();
        $this->register('original', $this->capabilities($policy, $group))->assertCreated();

        return $this->poll('original')->assertOk()->json('task');
    }

    private function register(string $id, array $capabilities, string $version = '1.20'): TestResponse
    {
        return $this->withHeaders($this->headers($version))->postJson('/api/worker/register', [
            'worker_id' => $id, 'task_queue' => 'local-policy', 'runtime' => 'php',
            'supported_workflow_types' => ['tests.external-greeting-workflow'], 'capabilities' => $capabilities,
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(), 'max_concurrent_workflow_tasks' => 1,
            'process_metrics' => ['host' => 'test-worker', 'process_started_at' => now()->toISOString(), 'process_id' => $id === 'original' ? 10 : 20],
        ]);
    }

    private function poll(string $id): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', ['worker_id' => $id, 'task_queue' => 'local-policy']);
    }

    private function descriptor(mixed $policy): array
    {
        return ['type' => 'record_local_activity', 'activity_type' => 'opaque-local',
            'arguments' => Serializer::serializeWithCodec('avro', ['Ada']), 'payload_codec' => 'avro', 'cancellation_policy' => $policy];
    }

    private function local(array $task, string $operation, array $descriptor, string $workerAttemptId = 'sdk-local-1'): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/local-activities/{$operation}", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'sequence' => 1, 'worker_attempt_id' => $workerAttemptId, 'descriptor' => $descriptor,
        ]);
    }

    private function group(array $task, string $policy): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/local-activities/checkpoint-group", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'checkpoint_id' => 'policy-group', 'start_sequence' => 1, 'commands' => [[
                'type' => 'start_child_workflow', 'workflow_type' => 'tests.external-greeting-workflow',
                'arguments' => Serializer::serializeWithCodec('avro', ['child']), 'payload_codec' => 'avro',
                ...ParallelChildGroup::itemMetadata(1, 2, 0, 'mixed'),
            ], [...$this->descriptor($policy), 'type' => 'prepare_local_activity',
                ...ParallelChildGroup::itemMetadata(1, 2, 1, 'mixed')]],
        ]);
    }

    private function headers(string $version = '1.20'): array
    {
        return ['X-Namespace' => 'default', WorkerProtocol::HEADER => $version];
    }
}

interface LocalCancellationPolicyBridge
{
    public function supportedLocalActivityCancellationPolicies(): array;
}
