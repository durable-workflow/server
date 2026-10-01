<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\CooperativeCancellationPolicy;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

class CooperativeCancellationProtocolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'server.worker_protocol.version' => '1.20',
            'workflows.v2.types.workflows' => ['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class],
        ]);
        WorkflowNamespace::query()->create([
            'name' => 'default', 'description' => 'Test', 'retention_days' => 30, 'status' => 'active',
        ]);
    }

    public function test_request_before_claim_routes_only_to_capable_workers_and_keeps_original_deadline(): void
    {
        [$workflowId, $runId] = $this->start();
        $this->register('old', false);
        $this->register('new', true);
        $accepted = $this->requestCancellation($workflowId, ['cleanup_timeout_seconds' => 120]);
        $accepted->assertStatus(202)->assertJsonPath('accepted', true)->assertJsonPath('run_id', $runId);
        $original = $accepted->json('cancellation_request');
        $this->assertFalse(WorkflowRun::query()->findOrFail($runId)->status->isTerminal());
        $this->poll('old', '1.19')->assertOk()->assertJsonPath('task', null);
        $this->poll('new')->assertOk()->assertJsonPath('task.cancellation_request', $original);
        $this->travel(10)->seconds();
        $this->requestCancellation($workflowId, ['cleanup_timeout_seconds' => 3600])
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('cancellation_request', $original);
        $this->assertSame(1, $this->eventCount($runId, HistoryEventType::CooperativeCancellationRequested));
    }

    public function test_active_claim_observes_request_on_heartbeat_and_cached_poll_without_losing_lease(): void
    {
        [$workflowId] = $this->start();
        $this->register('new', true);
        $task = $this->poll('new', pollRequestId: 'repeat')->json('task');
        $accepted = $this->requestCancellation($workflowId)->assertStatus(202);
        $pending = $accepted->json('cancellation_request');
        $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/heartbeat", [
            'lease_owner' => 'new', 'workflow_task_attempt' => $task['workflow_task_attempt'],
        ])->assertOk()->assertJsonPath('renewed', true)->assertJsonPath('cancellation_request', $pending);
        $this->poll('new', pollRequestId: 'repeat')->assertOk()
            ->assertJsonPath('task.task_id', $task['task_id'])->assertJsonPath('task.cancellation_request', $pending);
        $this->assertSame(TaskStatus::Leased, WorkflowTask::query()->findOrFail($task['task_id'])->status);
    }

    public function test_request_refuses_incompatible_claim_without_partial_command_or_history(): void
    {
        [$workflowId, $runId] = $this->start();
        $this->register('old', false);
        $this->poll('old', '1.19')->assertOk();
        $before = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count();
        $this->requestCancellation($workflowId)->assertStatus(409)
            ->assertJsonPath('reason', 'active_claim_cancellation_not_supported');
        $this->assertNull(WorkflowRun::query()->findOrFail($runId)->cancellation_request_command_id);
        $this->assertSame($before, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count());
    }

    public function test_mutating_registration_cannot_upgrade_an_old_claim(): void
    {
        [$workflowId] = $this->start();
        $this->register('same-id', false);
        $this->poll('same-id', '1.19')->assertOk();
        WorkerRegistration::query()->where('worker_id', 'same-id')->firstOrFail()->forceFill([
            'capabilities' => [CooperativeCancellationPolicy::CAPABILITY],
        ])->save();
        $this->requestCancellation($workflowId)->assertStatus(409)
            ->assertJsonPath('reason', 'active_claim_cancellation_not_supported');
    }

    public function test_mutating_registration_does_not_erase_a_capable_claims_original_proof(): void
    {
        [$workflowId] = $this->start();
        $this->register('same-id', true);
        $task = $this->poll('same-id')->json('task');
        WorkerRegistration::query()->where('worker_id', 'same-id')->firstOrFail()
            ->forceFill(['capabilities' => []])->save();
        $this->requestCancellation($workflowId)->assertStatus(202);
        $this->assertTrue(CooperativeCancellationPolicy::claimSupportsCancellation(
            WorkflowTask::query()->findOrFail($task['task_id']),
        ));
    }

    public function test_delivery_retry_records_one_marker_and_keeps_cleanup_authority(): void
    {
        [$workflowId, $runId] = $this->start();
        $this->register('new', true);
        $task = $this->poll('new')->json('task');
        $requestId = $this->requestCancellation($workflowId)->json('cancellation_request.request_id');
        $this->deliver($task, $requestId)->assertOk()->assertJsonPath('delivered', true)->assertJsonPath('sequence', 1);
        $this->deliver($task, $requestId)->assertOk()->assertJsonPath('delivered', true);
        $this->assertSame(1, $this->eventCount($runId, HistoryEventType::CooperativeCancellationDelivered));
        $this->assertSame(TaskStatus::Leased, WorkflowTask::query()->findOrFail($task['task_id'])->status);
        $this->deliver($task, $requestId, ['call_kind' => 'timer'])->assertStatus(409)
            ->assertJsonPath('reason', 'cancellation_delivery_mismatch');
    }

    public static function cancellationRequestTiming(): array
    {
        return ['before claim' => [true], 'after claim' => [false]];
    }

    #[DataProvider('cancellationRequestTiming')]
    public function test_prefix_completion_keeps_pending_cancellation_deliverable(bool $beforeClaim): void
    {
        [$workflowId, $runId] = $this->start();
        $this->register('original', true);
        $requestId = $beforeClaim
            ? $this->requestCancellation($workflowId)->assertStatus(202)->json('cancellation_request.request_id')
            : null;
        $task = $this->poll('original')->assertOk()->json('task');
        $requestId ??= $this->requestCancellation($workflowId)->assertStatus(202)->json('cancellation_request.request_id');

        $completed = $this->complete($task, [
            ['type' => 'record_side_effect', 'result' => Serializer::serializeWithCodec('avro', 7)],
            ['type' => 'record_side_effect', 'result' => Serializer::serializeWithCodec('avro', 8)],
        ])->assertOk()->assertJsonPath('recorded', true)->assertJsonPath('run_status', 'waiting');
        $this->assertCount(1, $completed->json('created_task_ids'));
        $this->assertSame(2, $this->eventCount($runId, HistoryEventType::SideEffectRecorded));
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::CooperativeCancellationDelivered));
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')->whereIn('status', ['ready', 'leased'])->count());
        $successor = WorkflowTask::query()->findOrFail($completed->json('created_task_ids.0'));
        $this->assertSame($requestId, $successor->payload['resume_source_id']);
        $this->assertSame('cancellation_request', $successor->payload['resume_source_kind']);

        // A repeated completion cannot publish a second successor or prefix.
        $this->complete($task, [
            ['type' => 'record_side_effect', 'result' => Serializer::serializeWithCodec('avro', 7)],
        ])->assertStatus(409);
        $this->assertSame(2, WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')->count());
        $this->assertSame(2, $this->eventCount($runId, HistoryEventType::SideEffectRecorded));

        $this->register('successor', true);
        $resumed = $this->poll('successor')->assertOk()->json('task');
        $this->assertSame($successor->id, $resumed['task_id']);
        $this->assertSame($requestId, $resumed['cancellation_request']['request_id']);
        $this->deliver($resumed, $requestId, ['sequence' => 3])->assertOk()->assertJsonPath('delivered', true);
        $this->complete($resumed, [['type' => 'complete_workflow']])->assertOk()
            ->assertJsonPath('run_status', 'cancelled')->assertJsonPath('created_task_ids', []);
        $this->assertSame(1, $this->eventCount($runId, HistoryEventType::CooperativeCancellationDelivered));
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')->whereIn('status', ['ready', 'leased'])->count());
    }

    public function test_delivery_fences_the_actual_claim_attempt_owner_and_request(): void
    {
        [$workflowId, $runId] = $this->start();
        $this->register('new', true);
        $task = $this->poll('new')->json('task');
        $requestId = $this->requestCancellation($workflowId)->json('cancellation_request.request_id');
        $this->deliver($task, $requestId, ['lease_owner' => 'other'])->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch');
        $this->deliver($task, $requestId, ['workflow_task_attempt' => 2])->assertStatus(409)
            ->assertJsonPath('reason', 'workflow_task_attempt_mismatch');
        $this->deliver($task, 'different')->assertStatus(409)->assertJsonPath('reason', 'cancellation_request_mismatch');
        $this->deliver($task, $requestId, ['sequence' => 9])->assertStatus(409)
            ->assertJsonPath('reason', 'cancellation_delivery_sequence_mismatch');
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::CooperativeCancellationDelivered));
    }

    public function test_worker_reloads_canonical_delivery_after_acknowledgment_loss_with_the_observed_token(): void
    {
        [$workflowId] = $this->start();
        $this->register('new', true);
        $task = $this->poll('new')->json('task');
        $pending = $this->requestCancellation($workflowId)->assertStatus(202)->json('cancellation_request');
        $this->deliver($task, $pending['request_id'])->assertOk();
        // The old task payload predates the request and marker. Read their
        // real canonical history rather than constructing an event locally.
        $history = $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/history", [
            'lease_owner' => 'new',
            'workflow_task_attempt' => $task['workflow_task_attempt'],
            'next_history_page_token' => $pending['history_refresh_page_token'],
        ])->assertOk();
        $markers = array_values(array_filter($history->json('history_events'), static fn ($event) => $event['event_type'] === HistoryEventType::CooperativeCancellationDelivered->value));
        $this->assertCount(1, $markers);
        $this->assertSame($pending['request_id'], $markers[0]['workflow_command_id']);
        $this->assertSame(1, $markers[0]['payload']['sequence']);
        $this->assertNotContains(HistoryEventType::CooperativeCancellationDelivered->value, array_column($task['history_events'], 'event_type'));
    }

    public function test_cleanup_deadline_revokes_delivery_authority(): void
    {
        [$workflowId, $runId] = $this->start();
        $this->register('new', true);
        $task = $this->poll('new')->json('task');
        $requestId = $this->requestCancellation($workflowId, ['cleanup_timeout_seconds' => 1])
            ->json('cancellation_request.request_id');
        $this->travel(2)->seconds();
        $this->deliver($task, $requestId)->assertStatus(409)->assertJsonPath('reason', 'run_cancelled');
        $this->assertSame('cancelled', WorkflowRun::query()->findOrFail($runId)->status->value);
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::CooperativeCancellationDelivered));
    }

    public function test_terminal_cancel_keeps_its_existing_semantics_and_request_is_separate(): void
    {
        [$workflowId, $runId] = $this->start();
        $this->withHeaders($this->controlHeaders())->postJson("/api/workflows/{$workflowId}/cancel", [])->assertOk();
        $this->requestCancellation($workflowId)->assertStatus(409)->assertJsonPath('reason', 'run_not_active');
        $this->assertSame('cancelled', WorkflowRun::query()->findOrFail($runId)->status->value);
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::CooperativeCancellationRequested));
    }

    public function test_request_is_unavailable_until_the_server_protocol_supports_it(): void
    {
        [$workflowId] = $this->start();
        config(['server.worker_protocol.version' => '1.19']);
        $this->requestCancellation($workflowId)->assertStatus(409)
            ->assertJsonPath('reason', 'cooperative_cancellation_not_supported');
    }

    public function test_discovery_matches_request_support_and_preserves_the_default_protocol(): void
    {
        foreach (['1.19' => false, '1.20' => true, '2.0' => false, 'malformed' => false] as $version => $supported) {
            config(['server.worker_protocol.version' => $version]);
            $this->withHeaders($this->controlHeaders())->getJson('/api/cluster/info')
                ->assertOk()
                ->assertJsonPath('worker_protocol.version', $version)
                ->assertJsonPath('worker_protocol.server_capabilities.cooperative_cancellation', $supported);
            $this->assertSame($supported, CooperativeCancellationPolicy::serverSupported());
        }

        $this->assertSame('1.19', WorkerProtocol::VERSION);
    }

    public function test_waiting_timer_is_interrupted_only_when_delivery_is_committed(): void
    {
        [$workflowId, $runId] = $this->start();
        $this->register('new', true);
        $task = $this->poll('new')->json('task');
        $this->complete($task, [['type' => 'start_timer', 'delay_seconds' => 300]])
            ->assertOk()->assertJsonPath('run_status', 'waiting');
        $requestId = $this->requestCancellation($workflowId)->assertStatus(202)->json('cancellation_request.request_id');
        $this->assertSame('pending', WorkflowTimer::query()->where('workflow_run_id', $runId)->firstOrFail()->status->value);
        $resumed = $this->poll('new')->assertOk()->json('task');
        $this->deliver($resumed, $requestId, ['call_kind' => 'timer'])->assertOk();
        $this->assertSame('cancelled', WorkflowTimer::query()->where('workflow_run_id', $runId)->firstOrFail()->status->value);
        $this->assertSame(1, $this->eventCount($runId, HistoryEventType::TimerCancelled));
        $this->complete($resumed, [['type' => 'complete_workflow']])->assertOk()->assertJsonPath('run_status', 'cancelled');
        $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowCancelled->value)->firstOrFail();
        $this->assertSame($requestId, $event->workflow_command_id);
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::WorkflowCompleted));
    }

    public function test_prior_committed_result_cannot_become_the_cancellation_boundary(): void
    {
        [$workflowId, $runId] = $this->start();
        $this->register('new', true);
        $task = $this->poll('new')->json('task');
        $run = WorkflowRun::query()->findOrFail($runId);
        // A completed timer represents a durable call resolved before the request.
        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'sequence' => 1, 'timer_id' => 'completed-timer', 'delay_seconds' => 1,
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::TimerFired, [
            'sequence' => 1, 'timer_id' => 'completed-timer',
        ]);
        $requestId = $this->requestCancellation($workflowId)->json('cancellation_request.request_id');
        $this->deliver($task, $requestId, ['call_kind' => 'timer'])->assertStatus(409)
            ->assertJsonPath('reason', 'cancellation_delivery_not_eligible');
        $this->deliver($task, $requestId, ['sequence' => 2])->assertOk()->assertJsonPath('sequence', 2);
        $this->assertSame(1, $this->eventCount($runId, HistoryEventType::CooperativeCancellationDelivered));
    }

    public function test_termination_during_cleanup_revokes_late_delivery_and_completion(): void
    {
        [$workflowId, $runId] = $this->start();
        $this->register('new', true);
        $task = $this->poll('new')->json('task');
        $requestId = $this->requestCancellation($workflowId)->json('cancellation_request.request_id');
        $this->deliver($task, $requestId)->assertOk();
        $this->withHeaders($this->controlHeaders())->postJson("/api/workflows/{$workflowId}/terminate", [])->assertOk();
        $this->deliver($task, $requestId)->assertStatus(409)->assertJsonPath('reason', 'task_not_leased');
        $this->complete($task, [['type' => 'complete_workflow']])->assertStatus(409)->assertJsonPath('can_continue', false);
        $this->assertSame('terminated', WorkflowRun::query()->findOrFail($runId)->status->value);
        $this->assertSame(1, $this->eventCount($runId, HistoryEventType::WorkflowTerminated));
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::WorkflowCompleted));
    }

    public function test_claim_proof_cannot_be_reused_for_another_attempt(): void
    {
        [$workflowId] = $this->start();
        $this->register('new', true);
        $task = $this->poll('new')->json('task');
        WorkflowTask::query()->findOrFail($task['task_id'])->increment('attempt_count');
        $this->requestCancellation($workflowId)->assertStatus(409)
            ->assertJsonPath('reason', 'active_claim_cancellation_not_supported');
    }

    public function test_real_reregistration_requires_a_new_claim_and_fences_the_old_attempt(): void
    {
        [$workflowId] = $this->start();
        $this->register('same-id', false);
        $oldTask = $this->poll('same-id', '1.19')->json('task');
        $this->register('same-id', true, '2026-09-30T00:01:00Z');
        $requestId = $this->requestCancellation($workflowId)->assertStatus(202)->json('cancellation_request.request_id');
        $newTask = $this->poll('same-id')->assertOk()->json('task');
        $this->assertSame($oldTask['task_id'], $newTask['task_id']);
        $this->assertSame(2, $newTask['workflow_task_attempt']);
        $this->deliver($oldTask, $requestId)->assertStatus(409)
            ->assertJsonPath('reason', 'workflow_task_attempt_mismatch');
        $this->deliver($newTask, $requestId)->assertOk();
    }

    public function test_cold_reclaim_preserves_delivery_and_can_commit_bounded_cleanup(): void
    {
        [$workflowId, $runId] = $this->start();
        $this->register('same-id', true);
        $task = $this->poll('same-id')->json('task');
        $requestId = $this->requestCancellation($workflowId)->json('cancellation_request.request_id');
        $this->deliver($task, $requestId)->assertOk();
        // The process dies after the marker commits, before the acknowledgment
        // or any cleanup submission. Re-registration is the real recovery path.
        $this->register('same-id', true, '2026-09-30T00:01:00Z');
        $cold = $this->poll('same-id')->assertOk()->json('task');
        $this->assertSame(2, $cold['workflow_task_attempt']);
        $markers = array_values(array_filter($cold['history_events'], static fn ($event) => $event['event_type'] === HistoryEventType::CooperativeCancellationDelivered->value));
        $this->assertCount(1, $markers);
        $this->assertSame(1, $markers[0]['payload']['sequence']);
        $this->deliver($cold, $requestId)->assertOk();
        $this->complete($cold, [['type' => 'start_timer', 'delay_seconds' => 1]])
            ->assertOk()->assertJsonPath('run_status', 'waiting');
        $timerTask = WorkflowTask::query()->where('workflow_run_id', $runId)->where('task_type', 'timer')
            ->where('status', TaskStatus::Ready->value)->firstOrFail();
        $this->travel(2)->seconds();
        (new RunTimerTask($timerTask->id))->handle();
        $resumed = $this->poll('same-id')->assertOk()->json('task');
        $this->complete($resumed, [['type' => 'complete_workflow']])->assertOk()->assertJsonPath('run_status', 'cancelled');
        $this->assertSame(1, $this->eventCount($runId, HistoryEventType::CooperativeCancellationDelivered));
        $this->assertSame(1, $this->eventCount($runId, HistoryEventType::TimerScheduled));
        $this->assertSame(1, $this->eventCount($runId, HistoryEventType::WorkflowCancelled));
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::WorkflowCompleted));
    }

    public function test_registration_cannot_advertise_cooperation_below_its_protocol_floor(): void
    {
        $this->withHeaders($this->headers('1.19'))->postJson('/api/worker/register', [
            'worker_id' => 'invalid', 'task_queue' => 'cooperative', 'runtime' => 'php',
            'capabilities' => [CooperativeCancellationPolicy::CAPABILITY],
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
        ])->assertStatus(409)->assertJsonPath('reason', 'cooperative_cancellation_protocol_mismatch');
        $this->assertFalse(WorkerRegistration::query()->where('worker_id', 'invalid')->exists());
    }

    public function test_new_admission_observation_and_delivery_fields_match_the_versioned_schemas(): void
    {
        [$workflowId] = $this->start();
        $this->register('new', true);
        $accepted = $this->requestCancellation($workflowId)->assertStatus(202);
        $specDirectory = dirname(__DIR__, 2).'/resources/platform-protocol-specs/';
        OpenApiSchema::fromFile($specDirectory.'control-plane-api.openapi.yaml')
            ->assertReferenceMatches('#/components/schemas/CooperativeCancellationAdmission', json_decode($accepted->getContent(), flags: JSON_THROW_ON_ERROR));
        $workerSpec = OpenApiSchema::fromFile($specDirectory.'worker-protocol-api.openapi.yaml');
        $poll = $this->poll('new')->assertOk();
        $task = $poll->json('task');
        $workerSpec->assertReferenceMatches('#/components/schemas/WorkflowTask', json_decode($poll->getContent(), flags: JSON_THROW_ON_ERROR)->task);
        $heartbeat = $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/heartbeat", [
            'lease_owner' => 'new', 'workflow_task_attempt' => $task['workflow_task_attempt'],
        ])->assertOk();
        $workerSpec->assertReferenceMatches('#/components/schemas/WorkflowTaskHeartbeatResponse/allOf/1', json_decode($heartbeat->getContent(), flags: JSON_THROW_ON_ERROR));
        $delivery = $this->deliver($task, $accepted->json('cancellation_request.request_id'))->assertOk();
        $workerSpec->assertReferenceMatches('#/components/schemas/CooperativeCancellationDeliveryResponse/allOf/1', json_decode($delivery->getContent(), flags: JSON_THROW_ON_ERROR));
    }

    public function test_run_target_and_namespace_are_checked_before_request_admission(): void
    {
        [$workflowId, $runId] = $this->start();
        $this->withHeaders($this->controlHeaders())->postJson(
            "/api/workflows/{$workflowId}/runs/missing/request-cancellation", [],
        )->assertNotFound()->assertJsonPath('reason', 'run_not_found');
        $accepted = $this->withHeaders($this->controlHeaders())->postJson(
            "/api/workflows/{$workflowId}/runs/{$runId}/request-cancellation", [],
        )->assertStatus(202);
        $this->assertSame($runId, $accepted->json('run_id'));
        $this->requestCancellation('missing')->assertNotFound()->assertJsonPath('reason', 'instance_not_found');
    }

    private function complete(array $task, array $commands): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/complete", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => $commands,
        ]);
    }

    private function start(): array
    {
        $response = $this->withHeaders($this->controlHeaders())->postJson('/api/workflows', [
            'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'cooperative', 'input' => ['Ada'],
        ])->assertCreated();

        return [$response->json('workflow_id'), $response->json('run_id')];
    }

    private function register(string $workerId, bool $capable, string $processStart = '2026-09-30T00:00:00Z'): void
    {
        $this->withHeaders($this->headers($capable ? '1.20' : '1.19'))->postJson('/api/worker/register', [
            'worker_id' => $workerId,
            'task_queue' => 'cooperative', 'runtime' => 'php', 'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capabilities' => $capable ? [CooperativeCancellationPolicy::CAPABILITY] : [],
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'max_concurrent_workflow_tasks' => 1,
            'process_metrics' => ['host' => 'test-worker', 'process_started_at' => $processStart, 'process_id' => 10],
        ])->assertCreated();
    }

    private function poll(string $workerId, string $version = '1.20', ?string $pollRequestId = null): TestResponse
    {
        return $this->withHeaders($this->headers($version))->postJson('/api/worker/workflow-tasks/poll', array_filter([
            'worker_id' => $workerId, 'task_queue' => 'cooperative', 'poll_request_id' => $pollRequestId,
        ], static fn ($value) => $value !== null));
    }

    private function requestCancellation(string $workflowId, array $body = []): TestResponse
    {
        return $this->withHeaders($this->controlHeaders())->postJson("/api/workflows/{$workflowId}/request-cancellation", $body);
    }

    private function deliver(array $task, string $requestId, array $overrides = []): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson("/api/worker/workflow-tasks/{$task['task_id']}/deliver-cancellation", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'request_id' => $requestId, 'sequence' => 1, 'call_kind' => 'activity', ...$overrides,
        ]);
    }

    private function eventCount(string $runId, HistoryEventType $type): int
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->where('event_type', $type->value)->count();
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
