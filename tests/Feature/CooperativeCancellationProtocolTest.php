<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\CooperativeCancellationPolicy;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

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

    public function test_reregistration_cannot_upgrade_an_old_claim(): void
    {
        [$workflowId] = $this->start();
        $this->register('same-id', false);
        $this->poll('same-id', '1.19')->assertOk();
        $this->register('same-id', true);
        $this->requestCancellation($workflowId)->assertStatus(409)
            ->assertJsonPath('reason', 'active_claim_cancellation_not_supported');
    }

    public function test_reregistration_does_not_erase_a_capable_claims_original_proof(): void
    {
        [$workflowId] = $this->start();
        $this->register('same-id', true);
        $task = $this->poll('same-id')->json('task');
        $this->register('same-id', false);
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

    private function start(): array
    {
        $response = $this->withHeaders($this->controlHeaders())->postJson('/api/workflows', [
            'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'cooperative', 'input' => ['Ada'],
        ])->assertCreated();

        return [$response->json('workflow_id'), $response->json('run_id')];
    }

    private function register(string $workerId, bool $capable): void
    {
        WorkerRegistration::query()->updateOrCreate(['namespace' => 'default', 'worker_id' => $workerId], [
            'task_queue' => 'cooperative', 'runtime' => 'php', 'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capabilities' => $capable ? [CooperativeCancellationPolicy::CAPABILITY] : [],
            'max_concurrent_workflow_tasks' => 1, 'last_heartbeat_at' => now(), 'status' => 'active',
        ]);
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
