<?php

namespace Tests\Feature;

use App\Models\SearchAttributeDefinition;
use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\CooperativeCancellationPolicy;
use App\Support\PreparedLocalActivityPolicy;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\StoragePressureFixture;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;

final class PreparedLocalActivityProtocolTest extends TestCase
{
    use RefreshDatabase;
    use StoragePressureFixture;

    private ?string $payloadDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'server.worker_protocol.version' => '1.20',
            'workflows.v2.workflow_task_lease_seconds' => 10,
            'workflows.v2.types.workflows' => ['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class],
        ]);
        WorkflowNamespace::query()->create([
            'name' => 'default', 'description' => 'Test', 'retention_days' => 30, 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeStoragePressure();
        if ($this->payloadDirectory !== null) {
            File::deleteDirectory($this->payloadDirectory);
        }
        parent::tearDown();
    }

    public function test_default_protocol_does_not_advertise_or_admit_prepared_callbacks(): void
    {
        config(['server.worker_protocol.version' => '1.19']);
        $this->withHeaders($this->headers('1.19'))->postJson('/api/worker/workflow-tasks/missing/local-activities/prepare', [])
            ->assertStatus(409)->assertJsonPath('reason', 'prepared_local_activity_not_supported')
            ->assertJsonPath('server_capabilities.prepared_local_activities', false)
            ->assertJsonPath('unavailable', fn ($reasons) => in_array('server_protocol', $reasons, true) && in_array('request_protocol', $reasons, true));
    }

    public function test_actual_bound_bridge_must_supply_the_optional_role(): void
    {
        $this->app->instance(WorkflowTaskBridge::class, \Mockery::mock(WorkflowTaskBridge::class));
        $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/missing/local-activities/prepare', [])
            ->assertStatus(409)->assertJsonPath('reason', 'prepared_local_activity_not_supported')
            ->assertJsonPath('server_capabilities.prepared_local_activities', false)
            ->assertJsonFragment(['installed_runtime_prepared_local_activity']);
    }

    public function test_prepared_capability_requires_cooperation_and_protocol_1_20(): void
    {
        foreach (['1.19', '1.20'] as $version) {
            $this->withHeaders($this->headers($version))->postJson('/api/worker/register', [
                'worker_id' => 'incorrect', 'task_queue' => 'prepared', 'runtime' => 'php',
                'capabilities' => [PreparedLocalActivityPolicy::CAPABILITY],
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            ])->assertStatus(409)->assertJsonPath('reason', 'prepared_local_activity_capability_mismatch');
        }
        $this->assertSame(0, WorkerRegistration::query()->count());
    }

    public function test_preparation_records_admission_before_callback_and_duplicate_keeps_authority(): void
    {
        $task = $this->claim();
        $response = $this->prepare($task);
        $this->assertSame(200, $response->status(), json_encode($response->json('reason'), JSON_THROW_ON_ERROR));
        $prepared = $response->assertJsonPath('prepared', true)->json();
        $attempt = ActivityAttempt::query()->findOrFail($prepared['activity_attempt_id']);
        $this->assertNotSame('sdk-local-1', $attempt->id);
        $this->assertSame('sdk-local-1', $attempt->worker_attempt_id);
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame($task['task_id'], $attempt->workflow_task_id);
        $hosting = WorkflowTask::query()->findOrFail($task['task_id']);
        $this->assertSame(TaskStatus::Leased, $hosting->status);
        $this->assertNotNull(PreparedLocalActivityPolicy::originalClaim($hosting, $attempt, $task['lease_owner'], $task['workflow_task_attempt']));
        $before = $attempt->getAttributes();
        $this->travel(2)->seconds();
        $this->prepare($task)->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('activity_attempt_id', $attempt->id)
            ->assertJsonPath('lease_expires_at', $prepared['lease_expires_at']);
        $this->assertSame($before, $attempt->refresh()->getAttributes());
        $this->assertSame(1, $this->eventCount($task, HistoryEventType::ActivityScheduled));
        $this->assertSame(1, $this->eventCount($task, HistoryEventType::ActivityStarted));
        $this->assertSame(0, WorkflowTask::query()->where('task_type', 'activity')->count());
    }

    public function test_claim_cannot_be_upgraded_by_later_registration_edit(): void
    {
        $task = $this->claim(prepared: false);
        WorkerRegistration::query()->where('worker_id', 'original')->sole()->forceFill([
            'capabilities' => [CooperativeCancellationPolicy::CAPABILITY, PreparedLocalActivityPolicy::CAPABILITY],
        ])->save();
        $this->prepare($task)->assertStatus(409)->assertJsonFragment(['worker_claim_capability']);
        $this->assertSame(0, ActivityAttempt::query()->count());
    }

    public function test_malformed_and_non_avro_arguments_are_refused_before_admission(): void
    {
        $task = $this->claim();
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/CodecRegression/worker-malformed-serialized-payload.json')), true, flags: JSON_THROW_ON_ERROR);
        $badBytes = base64_decode($fixture['framing']['wire_base64'], true);
        $this->prepare($task, ['descriptor' => $this->descriptor(['arguments' => $badBytes])])
            ->assertStatus(422)->assertJsonValidationErrors('descriptor.arguments');
        $this->prepare($task, ['descriptor' => $this->descriptor(['payload_codec' => 'json'])])
            ->assertStatus(422)->assertJsonValidationErrors('descriptor.payload_codec');
        $this->assertSame(0, ActivityAttempt::query()->count());
        $this->assertSame(0, $this->eventCount($task, HistoryEventType::ActivityScheduled));
    }

    public function test_inline_envelopes_preserve_exact_arguments_and_result_bytes(): void
    {
        $task = $this->claim();
        $input = Serializer::serializeWithCodec('avro', ['Ada', ['nested' => true]]);
        $prepared = $this->prepare($task, ['descriptor' => $this->descriptor(['arguments' => ['codec' => 'avro', 'blob' => $input]])])
            ->assertOk()->json();
        $this->assertSame($input, ActivityExecution::query()->findOrFail($prepared['activity_execution_id'])->arguments);
        $result = Serializer::serializeWithCodec('avro', ['binary' => AvroBinaryValue::fromBytes("\0\xff")]);
        $this->localRequest($task, "{$prepared['activity_attempt_id']}/outcome", ['report' => [
            'outcome' => 'completed', 'result' => ['codec' => 'avro', 'blob' => $result],
        ]])->assertOk()->assertJsonPath('recorded', true);
        $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $task['run_id'])
            ->where('event_type', HistoryEventType::ActivityCompleted)->sole();
        $this->assertSame($result, $event->payload['result']);
    }

    public function test_external_arguments_and_result_references_are_namespace_bound(): void
    {
        $task = $this->claim();
        $this->payloadDirectory = sys_get_temp_dir().'/dw-prepared-payload-'.getmypid();
        foreach (['default', 'other'] as $namespace) {
            WorkflowNamespace::query()->updateOrCreate(['name' => $namespace], [
                'description' => 'Fixture', 'retention_days' => 30, 'status' => 'active',
                'external_payload_storage' => ['driver' => 'local', 'enabled' => true, 'threshold_bytes' => 4096,
                    'config' => ['uri' => 'file://'.$this->payloadDirectory.'/'.$namespace]],
            ]);
        }
        $arguments = Serializer::serializeWithCodec('avro', ['external arguments']);
        $foreign = $this->uploadPayload($arguments, 'other');
        $this->prepare($task, ['descriptor' => $this->descriptor(['arguments' => ['codec' => 'avro', 'external_payload' => $foreign]])])
            ->assertStatus(404)->assertJsonPath('reason', 'external_payload_not_found');
        $this->assertSame(0, ActivityAttempt::query()->count());
        $reference = $this->uploadPayload($arguments, 'default');
        $prepared = $this->prepare($task, ['descriptor' => $this->descriptor(['arguments' => ['codec' => 'avro', 'external_payload' => $reference]])])
            ->assertOk()->json();
        $this->assertSame($arguments, ActivityExecution::query()->findOrFail($prepared['activity_execution_id'])->arguments);
        $this->localRequest($task, "{$prepared['activity_attempt_id']}/outcome", ['report' => [
            'outcome' => 'completed', 'result' => ['codec' => 'avro', 'external_payload' => $foreign],
        ]])->assertStatus(404)->assertJsonPath('reason', 'external_payload_not_found');
        $this->assertSame(0, $this->eventCount($task, HistoryEventType::ActivityCompleted));
        $result = Serializer::serializeWithCodec('avro', ['external result']);
        $resultReference = $this->uploadPayload($result, 'default');
        $this->localRequest($task, "{$prepared['activity_attempt_id']}/outcome", ['report' => [
            'outcome' => 'completed', 'result' => ['codec' => 'avro', 'external_payload' => $resultReference],
        ]])->assertOk()->assertJsonPath('recorded', true);
        $this->assertSame($result, ActivityExecution::query()->findOrFail($prepared['activity_execution_id'])->result);
    }

    public function test_checkpoint_commits_prefix_keeps_claim_and_reuses_completion_validation(): void
    {
        $task = $this->claim();
        $claim = WorkflowTask::query()->findOrFail($task['task_id']);
        $expiry = $claim->lease_expires_at->toISOString();
        $body = ['checkpoint_id' => 'prefix-1', 'start_sequence' => 1, 'commands' => [
            ['type' => 'record_side_effect', 'result' => Serializer::serializeWithCodec('avro', 7)],
        ]];
        $this->localRequest($task, 'checkpoint', $body)->assertOk()->assertJsonPath('checkpointed', true)
            ->assertJsonPath('next_sequence', 2);
        $this->localRequest($task, 'checkpoint', $body)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, $this->eventCount($task, HistoryEventType::SideEffectRecorded));
        $this->assertSame(TaskStatus::Leased, $claim->refresh()->status);
        $this->assertSame($expiry, $claim->lease_expires_at->toISOString());
        $this->localRequest($task, 'checkpoint', [...$body, 'commands' => [['type' => 'start_timer', 'delay_seconds' => 2]]])
            ->assertStatus(409)->assertJsonPath('reason', 'local_activity_checkpoint_mismatch');
        $this->localRequest($task, 'checkpoint', [...$body, 'commands' => [[
            'type' => 'start_timer', 'delay_seconds' => 1, 'cancellation_policy' => 'try_cancel',
        ]]])->assertStatus(422)->assertJsonValidationErrors('commands.0.cancellation_policy');
        $this->localRequest($task, 'checkpoint', [...$body, 'commands' => [['type' => 'complete_workflow']]])
            ->assertStatus(409)->assertJsonPath('reason', 'invalid_local_activity_checkpoint_commands');
        $this->prepare($task, ['sequence' => 2])->assertOk()->assertJsonPath('prepared', true);
    }

    public function test_checkpoint_rejects_completion_only_metadata(): void
    {
        $task = $this->claim();
        $this->localRequest($task, 'checkpoint', ['checkpoint_id' => 'one', 'start_sequence' => 1, 'commands' => [],
            'message_stream_cursors' => [['stream_name' => 'input', 'cursor' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('message_stream_cursors');
        $this->assertArrayNotHasKey('portable_local_checkpoint', WorkflowTask::query()->findOrFail($task['task_id'])->payload);
    }

    public function test_sequential_checkpoints_keep_typed_search_history_identity(): void
    {
        SearchAttributeDefinition::query()->create(['namespace' => 'default', 'name' => 'Tier', 'type' => 'keyword']);
        $task = $this->claim(extraCapabilities: ['typed_search_attributes']);
        foreach ([1 => 'basic', 2 => 'pro'] as $sequence => $value) {
            $body = ['checkpoint_id' => "search-{$sequence}", 'start_sequence' => $sequence, 'commands' => [[
                'type' => 'upsert_search_attributes', 'attributes' => ['Tier' => $value], 'attribute_types' => ['Tier' => 'keyword'],
            ]]];
            $this->localRequest($task, 'checkpoint', $body)->assertOk()->assertJsonPath('checkpointed', true);
            $this->localRequest($task, 'checkpoint', $body)->assertOk()->assertJsonPath('duplicate', true);
        }
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $task['run_id'])
            ->where('event_type', HistoryEventType::SearchAttributesUpserted)->orderBy('sequence')->get();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame(['Tier' => 'keyword'], $event->payload['attribute_types']);
        }
        $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/complete", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [
                ['type' => 'upsert_search_attributes', 'attributes' => ['Tier' => 'enterprise'], 'attribute_types' => ['Tier' => 'keyword']],
                ['type' => 'complete_workflow'],
            ],
        ])->assertOk()->assertJsonPath('recorded', true)->assertJsonPath('run_status', 'completed');
        $final = WorkflowHistoryEvent::query()->where('workflow_run_id', $task['run_id'])
            ->where('event_type', HistoryEventType::SearchAttributesUpserted)->orderByDesc('sequence')->firstOrFail();
        $this->assertSame(3, $final->payload['sequence']);
        $this->assertSame(['Tier' => 'keyword'], $final->payload['attribute_types']);
    }

    public function test_supervisor_renews_both_leases_without_application_heartbeats(): void
    {
        $task = $this->claim();
        $prepared = $this->prepare($task)->assertOk()->json();
        $attempt = ActivityAttempt::query()->findOrFail($prepared['activity_attempt_id']);
        $heartbeat = $attempt->last_heartbeat_at->toISOString();
        $execution = ActivityExecution::query()->findOrFail($prepared['activity_execution_id'])->getAttributes();
        $this->travel(4)->seconds();
        $this->localRequest($task, "{$attempt->id}/control", ['renew_lease' => true])->assertOk()
            ->assertJsonPath('active', true)->assertJsonPath('renewed', true);
        $this->assertSame($heartbeat, $attempt->refresh()->last_heartbeat_at->toISOString());
        $this->assertSame($execution, ActivityExecution::query()->findOrFail($prepared['activity_execution_id'])->getAttributes());
        $this->assertSame($attempt->lease_expires_at->toISOString(), WorkflowTask::query()->findOrFail($task['task_id'])->lease_expires_at->toISOString());
        $this->assertSame(1, $this->eventCount($task, HistoryEventType::ActivityStarted));
    }

    public function test_same_claim_history_refresh_and_completion_do_not_repeat_a_prepared_callback(): void
    {
        $task = $this->claim();
        $this->localRequest($task, 'checkpoint', ['checkpoint_id' => 'first', 'start_sequence' => 1, 'commands' => [[
            'type' => 'record_side_effect', 'result' => Serializer::serializeWithCodec('avro', 7),
        ]]])->assertOk();
        $prepared = $this->prepare($task, ['sequence' => 2])->assertOk()->json();
        $receipt = $this->localRequest($task, "{$prepared['activity_attempt_id']}/outcome", ['report' => $this->success()])->assertOk()->json();
        $history = $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/history", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'next_history_page_token' => $receipt['history_refresh_page_token'],
        ])->assertOk()->json('history_events');
        $this->assertContains('SideEffectRecorded', array_column($history, 'event_type'));
        $this->assertContains('ActivityCompleted', array_column($history, 'event_type'));
        $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/complete", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [['type' => 'complete_workflow', 'result' => $this->success()['result'], 'payload_codec' => 'avro']],
        ])->assertOk()->assertJsonPath('recorded', true)->assertJsonPath('run_status', 'completed');
        $this->assertSame(1, $this->eventCount($task, HistoryEventType::ActivityScheduled));
        $this->assertSame(1, $this->eventCount($task, HistoryEventType::ActivityStarted));
        $this->assertSame(1, $this->eventCount($task, HistoryEventType::ActivityCompleted));
        $this->assertSame(1, $this->eventCount($task, HistoryEventType::SideEffectRecorded));
        $this->assertSame(TaskStatus::Completed, WorkflowTask::query()->findOrFail($task['task_id'])->status);
    }

    public function test_application_heartbeat_records_progress_without_renewing_either_lease(): void
    {
        $task = $this->claim();
        $prepared = $this->prepare($task, ['descriptor' => $this->descriptor([
            'heartbeat_timeout' => 3, 'start_to_close_timeout' => 60, 'schedule_to_close_timeout' => 120,
        ])])->assertOk()->json();
        $lease = $prepared['lease_expires_at'];
        $this->travel(2)->seconds();
        $heartbeat = $this->localRequest($task, "{$prepared['activity_attempt_id']}/heartbeat", ['progress' => ['message' => 'working']])
            ->assertOk()->assertJsonPath('active', true)->assertJsonPath('heartbeat_recorded', true)
            ->assertJsonPath('renewed', false)->json();
        $this->assertSame($lease, $heartbeat['lease_expires_at']);
        $this->assertSame($lease, $heartbeat['workflow_lease_expires_at']);
        $this->assertSame($prepared['start_to_close_deadline_at'], $heartbeat['start_to_close_deadline_at']);
        $this->assertSame($prepared['schedule_to_close_deadline_at'], $heartbeat['schedule_to_close_deadline_at']);
        $this->assertNotSame($prepared['heartbeat_deadline_at'], $heartbeat['heartbeat_deadline_at']);
        $event = WorkflowHistoryEvent::query()->whereKey($heartbeat['heartbeat_history_event_id'])->firstOrFail();
        $this->assertSame(['message' => 'working'], $event->payload['progress']);
        $this->assertSame($task['run_id'], $event->workflow_run_id);
        $this->travel(2)->seconds();
        $this->localRequest($task, "{$prepared['activity_attempt_id']}/control", ['renew_lease' => true])->assertOk()->assertJsonPath('active', true);
        $this->assertSame(1, $this->eventCount($task, HistoryEventType::ActivityHeartbeatRecorded));
    }

    public function test_expired_or_stale_claims_cannot_record_application_heartbeats(): void
    {
        $task = $this->claim();
        $prepared = $this->prepare($task, ['descriptor' => $this->descriptor(['heartbeat_timeout' => 3])])->assertOk()->json();
        WorkerRegistration::query()->where('worker_id', 'original')->sole()->forceFill(['status' => 'draining'])->save();
        $this->localRequest($task, "{$prepared['activity_attempt_id']}/heartbeat", ['progress' => ['message' => 'stale']])
            ->assertStatus(409)->assertJsonPath('reason', 'stale_worker_registration')->assertJsonPath('heartbeat_recorded', false);
        WorkerRegistration::query()->where('worker_id', 'original')->sole()->forceFill(['status' => 'active'])->save();
        $this->travel(3)->seconds();
        $this->localRequest($task, "{$prepared['activity_attempt_id']}/heartbeat")->assertStatus(409)
            ->assertJsonPath('reason', 'local_activity_deadline_expired')->assertJsonPath('heartbeat_recorded', false);
        $this->assertSame(0, $this->eventCount($task, HistoryEventType::ActivityHeartbeatRecorded));
    }

    public function test_shielded_cleanup_is_bound_to_canonical_delivery_and_original_budget(): void
    {
        [$task, $descriptor, $accepted] = $this->cleanupClaim();
        $this->prepare($task, ['sequence' => 2])->assertStatus(409)->assertJsonPath('reason', 'cancellation_requested');
        $this->localRequest($task, 'checkpoint', ['checkpoint_id' => 'cleanup-prefix', 'start_sequence' => 2, 'commands' => [[
            'type' => 'record_side_effect', 'marker_id' => 'cleanup', 'result' => $this->success()['result'], 'payload_codec' => 'avro',
        ]]])->assertOk()->assertJsonPath('next_sequence', 3);
        $response = $this->prepare($task, ['sequence' => 3, 'descriptor' => $descriptor]);
        $this->assertSame(200, $response->status(), json_encode($response->json('reason'), JSON_THROW_ON_ERROR));
        $prepared = $response->assertOk()
            ->assertJsonPath('cancellation_cleanup.request_id', $accepted['request_id'])
            ->assertJsonPath('cancellation_cleanup.root_request_id', $accepted['request_id'])
            ->assertJsonPath('cancellation_cleanup.cleanup_deadline_at', $accepted['cleanup_deadline_at'])->json();
        $this->assertSame($accepted['cleanup_deadline_at'], $prepared['schedule_to_close_deadline_at']);
        $this->localRequest($task, "{$prepared['activity_attempt_id']}/heartbeat", ['progress' => ['message' => 'cleaning']])
            ->assertOk()->assertJsonPath('active', true)->assertJsonPath('heartbeat_recorded', true);
        $this->localRequest($task, "{$prepared['activity_attempt_id']}/control", ['renew_lease' => true])->assertOk()->assertJsonPath('active', true);
        $this->localRequest($task, "{$prepared['activity_attempt_id']}/outcome", ['report' => $this->success()])->assertOk()->assertJsonPath('recorded', true);
        $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/complete", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [['type' => 'complete_workflow']],
        ])->assertOk()->assertJsonPath('run_status', 'cancelled');
        $this->assertSame(1, $this->eventCount($task, HistoryEventType::CooperativeCancellationDelivered));
        $this->assertSame(1, $this->eventCount($task, HistoryEventType::ActivityCompleted));
    }

    public function test_stale_registration_cannot_publish_cleanup_despite_accepted_cancellation(): void
    {
        [$task, $descriptor] = $this->cleanupClaim();
        $response = $this->prepare($task, ['sequence' => 2, 'descriptor' => $descriptor]);
        $this->assertSame(200, $response->status(), json_encode($response->json('reason'), JSON_THROW_ON_ERROR));
        $prepared = $response->assertOk()->json();
        $attempt = $prepared['activity_attempt_id'];
        $before = ActivityAttempt::query()->findOrFail($attempt)->getAttributes();
        WorkerRegistration::query()->where('worker_id', 'original')->sole()->forceFill(['status' => 'draining'])->save();
        $this->localRequest($task, "{$attempt}/outcome", ['report' => $this->success()])->assertStatus(409)
            ->assertJsonPath('reason', 'stale_worker_registration')->assertJsonPath('recorded', false);
        $this->localRequest($task, "{$attempt}/heartbeat")->assertStatus(409)->assertJsonPath('heartbeat_recorded', false);
        $this->assertSame($before, ActivityAttempt::query()->findOrFail($attempt)->getAttributes());
        $this->assertSame(0, $this->eventCount($task, HistoryEventType::ActivityCompleted));
        $this->assertSame(0, $this->eventCount($task, HistoryEventType::ActivityHeartbeatRecorded));
    }

    public function test_changed_registration_cannot_renew_or_publish_a_fresh_result(): void
    {
        $task = $this->claim();
        $attempt = $this->prepare($task)->assertOk()->json('activity_attempt_id');
        WorkerRegistration::query()->where('worker_id', 'original')->sole()->forceFill(['status' => 'draining'])->save();
        $this->localRequest($task, "{$attempt}/control", ['renew_lease' => true])->assertStatus(409)
            ->assertJsonPath('active', false)->assertJsonPath('renewed', false)->assertJsonPath('stop_required', true)
            ->assertJsonPath('reason', 'stale_worker_registration');
        $this->localRequest($task, "{$attempt}/outcome", ['report' => $this->success()])->assertStatus(409)
            ->assertJsonPath('recorded', false)->assertJsonPath('reason', 'stale_worker_registration');
        $this->assertSame(0, $this->eventCount($task, HistoryEventType::ActivityCompleted));
    }

    public function test_success_receipt_is_idempotent_after_takeover_and_changed_result_is_refused(): void
    {
        $task = $this->claim();
        $attempt = $this->prepare($task)->assertOk()->json('activity_attempt_id');
        $first = $this->localRequest($task, "{$attempt}/outcome", ['report' => $this->success()])->assertOk()
            ->assertJsonPath('recorded', true)->assertJsonPath('claim_released', false)->json();
        $this->replaceClaim($task);
        $this->localRequest($task, "{$attempt}/outcome", ['report' => $this->success()])->assertOk()
            ->assertJsonPath('duplicate', true)->assertJsonPath('event_id', $first['event_id']);
        $this->localRequest($task, "{$attempt}/outcome", ['report' => $this->success('changed')])->assertStatus(409)
            ->assertJsonPath('reason', 'local_activity_outcome_mismatch');
        $this->assertSame(1, $this->eventCount($task, HistoryEventType::ActivityCompleted));
    }

    public function test_failure_schedules_one_durable_retry_and_retains_the_total_deadline(): void
    {
        $task = $this->claim();
        $descriptor = $this->descriptor(['schedule_to_close_timeout' => 60, 'retry_policy' => [
            'max_attempts' => 2, 'backoff_seconds' => [2],
        ]]);
        $first = $this->prepare($task, ['descriptor' => $descriptor])->assertOk()->json();
        $failure = ['outcome' => 'failed', 'message' => 'retry me', 'exception_type' => \RuntimeException::class];
        $result = $this->localRequest($task, "{$first['activity_attempt_id']}/outcome", ['report' => $failure])
            ->assertOk()->assertJsonPath('recorded', true)->assertJsonPath('claim_released', true)
            ->assertJsonPath('event_type', 'ActivityRetryScheduled')->json();
        $this->localRequest($task, "{$first['activity_attempt_id']}/outcome", ['report' => $failure])
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('event_id', $result['event_id']);
        $this->assertCount(1, $result['created_task_ids']);
        $this->assertSame(TaskStatus::Completed, WorkflowTask::query()->findOrFail($task['task_id'])->status);
        $this->assertSame(0, WorkflowTask::query()->where('task_type', 'activity')->count());
        $this->poll('original')->assertOk()->assertJsonPath('task', null);
        $this->travel(2)->seconds();
        $retry = $this->poll('original')->assertOk()->json('task');
        $this->assertSame($result['created_task_ids'][0], $retry['task_id']);
        $second = $this->prepare($retry, ['worker_attempt_id' => 'sdk-local-2', 'descriptor' => $descriptor])
            ->assertOk()->assertJsonPath('attempt_number', 2)->json();
        $this->assertNotSame($first['activity_attempt_id'], $second['activity_attempt_id']);
        $this->assertSame($first['schedule_to_close_deadline_at'], $second['schedule_to_close_deadline_at']);
        $this->localRequest($retry, "{$second['activity_attempt_id']}/outcome", ['report' => $this->success()])
            ->assertOk()->assertJsonPath('recorded', true);
    }

    public function test_cold_recovery_records_unknown_stop_then_retries_under_original_budget(): void
    {
        $task = $this->claim();
        $descriptor = $this->descriptor(['schedule_to_close_timeout' => 60, 'retry_policy' => [
            'max_attempts' => 2, 'backoff_seconds' => [2],
        ]]);
        $first = $this->prepare($task, ['descriptor' => $descriptor])->assertOk()->json();
        ActivityAttempt::query()->findOrFail($first['activity_attempt_id'])->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        $replacement = $this->replaceClaim($task);
        $body = ['sequence' => 1, 'descriptor' => $descriptor];
        $recovered = $this->localRequest($replacement, 'recover', $body)->assertOk()
            ->assertJsonPath('recovered', true)->assertJsonPath('claim_released', true)
            ->assertJsonPath('callback_stop_state', 'unknown')->json();
        $this->localRequest($replacement, 'recover', $body)->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('event_id', $recovered['event_id']);
        $this->localRequest($task, "{$first['activity_attempt_id']}/outcome", ['report' => $this->success()])
            ->assertStatus(409)->assertJsonPath('reason', 'stale_activity_attempt');
        $this->assertSame(0, $this->eventCount($task, HistoryEventType::ActivityCancellationAcknowledged));
        $this->travel(2)->seconds();
        $retry = $this->poll('replacement')->assertOk()->json('task');
        $this->assertSame($recovered['created_task_ids'][0], $retry['task_id']);
        $second = $this->prepare($retry, ['worker_attempt_id' => 'sdk-recovered', 'descriptor' => $descriptor])
            ->assertOk()->assertJsonPath('attempt_number', 2)->json();
        $this->assertSame($first['schedule_to_close_deadline_at'], $second['schedule_to_close_deadline_at']);
        $this->assertSame(0, WorkflowTask::query()->where('task_type', 'activity')->count());
    }

    public function test_accepted_cancellation_refuses_publication_without_releasing_cleanup_claim(): void
    {
        $task = $this->claim();
        $attempt = $this->prepare($task)->assertOk()->json('activity_attempt_id');
        $this->withHeaders($this->controlHeaders())->postJson("/api/workflows/{$task['workflow_id']}/request-cancellation", [])
            ->assertStatus(202);
        $this->localRequest($task, "{$attempt}/outcome", ['report' => $this->success()])->assertStatus(409)
            ->assertJsonPath('recorded', false)->assertJsonPath('fenced', true)->assertJsonPath('reason', 'cancellation_requested');
        $this->assertSame(TaskStatus::Leased, WorkflowTask::query()->findOrFail($task['task_id'])->status);
        $this->assertSame(0, $this->eventCount($task, HistoryEventType::ActivityCompleted));
        $this->assertSame(1, $this->eventCount($task, HistoryEventType::ActivityCancelled));
        $this->assertSame(0, $this->eventCount($task, HistoryEventType::ActivityCancellationAcknowledged));
    }

    public function test_original_supervisor_observes_cancellation_and_records_join_after_takeover(): void
    {
        $task = $this->claim();
        $attempt = $this->prepare($task)->assertOk()->json('activity_attempt_id');
        $replacement = $this->replaceClaim($task);
        $accepted = $this->withHeaders($this->controlHeaders())->postJson("/api/workflows/{$task['workflow_id']}/request-cancellation", [
            'cleanup_timeout_seconds' => 30,
        ])->assertStatus(202)->json('cancellation_request');
        $this->localRequest($task, "{$attempt}/control", ['renew_lease' => true])->assertOk()
            ->assertJsonPath('active', false)->assertJsonPath('renewed', false)
            ->assertJsonPath('stop_required', true)->assertJsonPath('cancellation_request.request_id', $accepted['request_id'])
            ->assertJsonPath('cancellation_request.cleanup_deadline_at', $accepted['cleanup_deadline_at']);
        $this->localRequest($task, "{$attempt}/acknowledge-cancellation", ['request_id' => $accepted['request_id']])
            ->assertOk()->assertJsonPath('acknowledged', true);
        $this->localRequest($task, "{$attempt}/acknowledge-cancellation", ['request_id' => $accepted['request_id']])
            ->assertOk()->assertJsonPath('duplicate', true);
        $current = WorkflowTask::query()->findOrFail($task['task_id']);
        $this->assertSame(TaskStatus::Leased, $current->status);
        $this->assertSame($replacement['lease_owner'], $current->lease_owner);
        $this->assertSame($replacement['workflow_task_attempt'], $current->attempt_count);
    }

    public function test_wrong_owner_epoch_namespace_and_unknown_attempt_cannot_act(): void
    {
        $task = $this->claim();
        $attempt = $this->prepare($task)->assertOk()->json('activity_attempt_id');
        foreach ([['lease_owner' => 'different'], ['workflow_task_attempt' => 99]] as $wrong) {
            $this->localRequest($task, "{$attempt}/control", $wrong)->assertStatus(409)
                ->assertJsonFragment(['original_prepared_local_claim']);
        }
        WorkflowNamespace::query()->create(['name' => 'other', 'description' => 'Other', 'retention_days' => 30, 'status' => 'active']);
        $this->withHeaders([...$this->headers(), 'X-Namespace' => 'other'])
            ->postJson("/api/worker/workflow-tasks/{$task['task_id']}/local-activities/{$attempt}/control", [
                'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            ])->assertStatus(404)->assertJsonPath('reason', 'activity_attempt_not_found');
        $this->localRequest($task, 'missing/control')->assertStatus(404);
        $this->withHeaders($this->headers('1.19'))->postJson("/api/worker/workflow-tasks/{$task['task_id']}/local-activities/{$attempt}/control", [])
            ->assertStatus(409)->assertJsonFragment(['request_protocol']);
    }

    public function test_quota_refusal_rolls_back_admission_and_original_authority_receipt(): void
    {
        $task = $this->claim();
        $before = WorkflowHistoryEvent::query()->count();
        config(['server.namespace_durable_state.limits' => ['max_workflow_history_events' => $before + 1]]);
        $this->prepare($task)->assertStatus(429)->assertJsonPath('reason', 'namespace_workflow_history_events_exhausted');
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->assertSame(0, ActivityAttempt::query()->count());
        $this->assertArrayNotHasKey('_server_prepared_local_activity_claims', WorkflowTask::query()->findOrFail($task['task_id'])->payload);
    }

    public function test_draining_allows_an_existing_claim_to_commit_prefix_prepare_and_finish(): void
    {
        $task = $this->claim();
        $this->configureStoragePressure('draining');
        $this->localRequest($task, 'checkpoint', ['checkpoint_id' => 'drain', 'start_sequence' => 1, 'commands' => [[
            'type' => 'record_side_effect', 'result' => Serializer::serializeWithCodec('avro', 7),
        ]]])->assertOk()->assertJsonPath('checkpointed', true);
        $prepared = $this->prepare($task, ['sequence' => 2])->assertOk()->json();
        $this->localRequest($task, "{$prepared['activity_attempt_id']}/control", ['renew_lease' => true])
            ->assertOk()->assertJsonPath('renewed', true);
        $this->localRequest($task, "{$prepared['activity_attempt_id']}/heartbeat", ['progress' => ['message' => 'draining']])
            ->assertOk()->assertJsonPath('heartbeat_recorded', true)->assertJsonPath('renewed', false);
        $this->localRequest($task, "{$prepared['activity_attempt_id']}/outcome", ['report' => $this->success()])
            ->assertOk()->assertJsonPath('recorded', true);
    }

    public function test_draining_preserves_original_cancellation_observation_and_join_receipt(): void
    {
        $task = $this->claim();
        $attempt = $this->prepare($task)->assertOk()->json('activity_attempt_id');
        $pending = $this->withHeaders($this->controlHeaders())->postJson("/api/workflows/{$task['workflow_id']}/request-cancellation", [])
            ->assertStatus(202)->json('cancellation_request');
        $this->configureStoragePressure('draining');
        $this->localRequest($task, "{$attempt}/control")->assertOk()->assertJsonPath('stop_required', true)
            ->assertJsonPath('cancellation_request.request_id', $pending['request_id']);
        $this->localRequest($task, "{$attempt}/acknowledge-cancellation", ['request_id' => $pending['request_id']])
            ->assertOk()->assertJsonPath('acknowledged', true);
    }

    public function test_storage_fence_refuses_all_local_mutations_without_changing_leases_or_history(): void
    {
        $task = $this->claim();
        $attemptId = $this->prepare($task)->assertOk()->json('activity_attempt_id');
        $attempt = ActivityAttempt::query()->findOrFail($attemptId);
        $before = $attempt->getAttributes();
        $history = WorkflowHistoryEvent::query()->count();
        $this->configureStoragePressure('fenced');
        foreach (['prepare', 'recover', 'checkpoint', "{$attemptId}/control", "{$attemptId}/heartbeat", "{$attemptId}/outcome", "{$attemptId}/acknowledge-cancellation"] as $path) {
            $this->localRequest($task, $path)->assertStatus(503)->assertJsonPath('request_admitted', false);
        }
        $this->assertSame($before, $attempt->refresh()->getAttributes());
        $this->assertSame($history, WorkflowHistoryEvent::query()->count());
    }

    private function cleanupClaim(): array
    {
        $task = $this->claim();
        $accepted = $this->withHeaders($this->controlHeaders())->postJson("/api/workflows/{$task['workflow_id']}/request-cancellation", [
            'cleanup_timeout_seconds' => 30,
        ])->assertStatus(202)->json('cancellation_request');
        $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/deliver-cancellation", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'request_id' => $accepted['request_id'], 'sequence' => 1, 'call_kind' => 'timer',
        ])->assertOk()->assertJsonPath('delivered', true);
        $delivery = WorkflowHistoryEvent::query()->where('workflow_run_id', $task['run_id'])
            ->where('event_type', HistoryEventType::CooperativeCancellationDelivered)->sole();

        return [$task, $this->descriptor(['cancellation_cleanup' => [
            'request_id' => $accepted['request_id'], 'delivery_history_event_id' => $delivery->id,
        ]]), $accepted];
    }

    private function claim(bool $prepared = true, array $extraCapabilities = []): array
    {
        if (! PreparedLocalActivityPolicy::backendSupported()) {
            $this->markTestSkipped('Prepared-local source binding required. Published protocol 1.19 refusal is covered separately.');
        }
        $this->withHeaders($this->controlHeaders())->postJson('/api/workflows', [
            'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'prepared', 'input' => ['Ada'],
        ])->assertCreated();
        $this->register('original', $prepared, $extraCapabilities);

        return $this->poll('original')->assertOk()->json('task');
    }

    private function register(string $id, bool $prepared = true, array $extraCapabilities = []): void
    {
        $this->withHeaders($this->headers())->postJson('/api/worker/register', [
            'worker_id' => $id, 'task_queue' => 'prepared', 'runtime' => 'php',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capabilities' => [CooperativeCancellationPolicy::CAPABILITY, ...($prepared ? [PreparedLocalActivityPolicy::CAPABILITY] : []), ...$extraCapabilities],
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'max_concurrent_workflow_tasks' => 1,
            'process_metrics' => ['host' => 'test-worker', 'process_started_at' => now()->toISOString(), 'process_id' => $id === 'original' ? 10 : 20],
        ])->assertCreated();
    }

    private function poll(string $id): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson('/api/worker/workflow-tasks/poll', ['worker_id' => $id, 'task_queue' => 'prepared']);
    }

    private function replaceClaim(array $task): array
    {
        // Exercise the real poll takeover using an expired workflow claim.
        // Keep the prepared callback lease live to test original stop authority.
        WorkflowTask::query()->findOrFail($task['task_id'])->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        $this->register('replacement');
        $replacement = $this->poll('replacement')->assertOk()->json('task');
        $this->assertSame($task['task_id'], $replacement['task_id']);
        $this->assertSame($task['workflow_task_attempt'] + 1, $replacement['workflow_task_attempt']);

        return $replacement;
    }

    private function prepare(array $task, array $overrides = []): TestResponse
    {
        return $this->localRequest($task, 'prepare', ['sequence' => 1, 'worker_attempt_id' => 'sdk-local-1', 'descriptor' => $this->descriptor(), ...$overrides]);
    }

    private function descriptor(array $overrides = []): array
    {
        return ['type' => 'record_local_activity', 'activity_type' => 'opaque-local',
            'arguments' => Serializer::serializeWithCodec('avro', ['Ada']), 'payload_codec' => 'avro', ...$overrides];
    }

    private function success(string $value = 'done'): array
    {
        return ['outcome' => 'completed', 'result' => Serializer::serializeWithCodec('avro', $value), 'payload_codec' => 'avro'];
    }

    private function localRequest(array $task, string $path, array $body = []): TestResponse
    {
        $response = $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/local-activities/{$path}", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'], ...$body,
        ]);
        if ($response->status() === 200) {
            $operation = substr($path, strrpos('/'.$path, '/'));
            $schema = match ($operation) {
                'prepare' => 'PreparedLocalPreparationResult', 'recover' => 'PreparedLocalRecoveryResult',
                'checkpoint' => 'PreparedLocalCheckpointResult', 'control', 'heartbeat' => 'PreparedLocalControlResult',
                'outcome' => 'PreparedLocalOutcomeResult', 'acknowledge-cancellation' => 'PreparedLocalStopResult',
            };
            OpenApiSchema::fromFile(resource_path('platform-protocol-specs/worker-protocol-api.openapi.yaml'))
                ->assertReferenceMatches('#/components/schemas/'.$schema, json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR));
        }

        return $response;
    }

    private function uploadPayload(string $payload, string $namespace): array
    {
        return $this->call('POST', '/api/external-payloads/v1', [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_NAMESPACE' => $namespace,
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_CODEC' => 'avro',
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SIZE' => (string) strlen($payload),
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SHA256' => hash('sha256', $payload),
        ], $payload)->assertCreated()->json('reference');
    }

    private function eventCount(array $task, HistoryEventType $type): int
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $task['run_id'])->where('event_type', $type)->count();
    }

    private function headers(string $version = '1.20'): array
    {
        return ['X-Namespace' => 'default', WorkerProtocol::HEADER => $version];
    }

    private function controlHeaders(): array
    {
        return ['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'];
    }
}
