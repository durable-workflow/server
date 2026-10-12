<?php

namespace Tests\Source;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\NexusCooperativeCancellationPolicy;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Workflow\Serializers\Avro;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\ServiceOperationCancellation;

/** Real package source overlay, outside the locked-package feature suite. */
class NexusTargetCancellationAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(class_exists(ServiceOperationCancellation::class), 'Exact Nexus cancellation source is required.');
        Queue::fake();
        config(['server.worker_protocol.version' => '1.20']);
        WorkflowNamespace::query()->create(['name' => 'default', 'retention_days' => 30, 'status' => 'active']);
    }

    public function test_original_unsupported_target_is_refused_before_command_history_or_call_mutation(): void
    {
        $this->register('target', 'target-queue', []);
        [$caller, $call, $target] = $this->admitCall();
        $before = [$caller->getRawOriginal(), $call->getRawOriginal(), $target->getRawOriginal(), WorkflowHistoryEvent::query()->count()];
        $this->cancel($caller)->assertConflict()
            ->assertJsonPath('reason', 'nexus_cooperative_cancellation_not_supported')
            ->assertJsonPath('retryable', false)->assertJsonPath('service_call.service_call_id', $call->id)
            ->assertJsonPath('target.run_id', $target->id)->assertJsonPath('available_workers.0.worker_id', 'target');
        $this->assertSame($before, [$caller->fresh()->getRawOriginal(), $call->fresh()->getRawOriginal(),
            $target->fresh()->getRawOriginal(), WorkflowHistoryEvent::query()->count()]);
    }

    public function test_original_target_absence_is_retryable_and_later_admission_keeps_duplicate_budget(): void
    {
        [$caller, $call, $target] = $this->admitCall();
        $before = WorkflowHistoryEvent::query()->count();
        $this->cancel($caller)->assertStatus(503)
            ->assertJsonPath('reason', 'nexus_cooperative_cancellation_temporarily_unavailable')
            ->assertJsonPath('retryable', true)->assertJsonPath('target.run_id', $target->id);
        $this->assertNull($caller->fresh()->cancellation_request_command_id);
        $this->assertNull($target->fresh()->cancellation_request_command_id);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->register('target', 'target-queue', ['cooperative_cancellation']);
        $original = $this->cancel($caller)->assertStatus(202)->json('cancellation_request');
        WorkerRegistration::query()->where('worker_id', 'target')->delete();
        $this->travel(5)->seconds();
        $this->cancel($caller, 300)->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('cancellation_request', $original);
        $this->assertSame($call->id, $call->fresh()->id);
    }

    public function test_claim_created_after_preflight_cannot_be_upgraded_at_admission(): void
    {
        $this->register('target', 'target-queue', ['cooperative_cancellation']);
        [$caller, , $target] = $this->admitCall();
        $this->assertNull(NexusCooperativeCancellationPolicy::targetUnavailable($target));
        $this->register('target', 'target-queue', []);
        $claim = $this->withHeaders($this->workerHeaders('1.19'))->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'target', 'task_queue' => 'target-queue'])->assertOk()->json('task');
        $this->assertSame($target->id, $claim['run_id']);
        WorkerRegistration::query()->where('worker_id', 'target')->update(['capabilities' => ['cooperative_cancellation']]);
        $this->register('second', 'target-queue', ['cooperative_cancellation']);
        $this->cancel($caller)->assertConflict()->assertJsonPath('unavailable', ['target_active_claim_cancellation_not_supported'])
            ->assertJsonPath('active_claims.0.task_id', $claim['task_id']);
        $this->assertNull($caller->fresh()->cancellation_request_command_id);
        $this->assertNull($target->fresh()->cancellation_request_command_id);
    }

    private function admitCall(): array
    {
        $this->register('caller', 'caller-queue', ['cooperative_cancellation', 'nexus_cooperative_cancellation']);
        $endpoint = WorkflowServiceEndpoint::query()->create(['namespace' => 'default', 'endpoint_name' => 'service']);
        $service = WorkflowService::query()->create(['namespace' => 'default', 'workflow_service_endpoint_id' => $endpoint->id, 'service_name' => 'cleanup']);
        WorkflowServiceOperation::query()->create([
            'namespace' => 'default', 'workflow_service_endpoint_id' => $endpoint->id, 'workflow_service_id' => $service->id,
            'operation_name' => 'start', 'operation_mode' => 'sync', 'handler_binding_kind' => 'start_workflow',
            'handler_target_reference' => 'tests.nexus-target',
            'handler_binding' => ['workflow_type' => 'tests.nexus-target', 'queue' => 'target-queue'],
            'deadline_policy' => ['timeout_seconds' => 180],
            'cancellation_policy' => ['allow_cancel' => true, 'propagate_to_linked_workflow' => true],
        ]);
        $started = $this->withHeaders($this->controlHeaders())->postJson('/api/workflows', [
            'workflow_type' => 'tests.nexus-caller', 'task_queue' => 'caller-queue', 'input' => []])->assertCreated();
        $task = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'caller', 'task_queue' => 'caller-queue'])->assertOk()->json('task');
        $this->withHeaders($this->workerHeaders())->postJson('/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'lease_owner' => 'caller', 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [['type' => 'start_service_operation', 'endpoint_name' => 'service', 'service_name' => 'cleanup',
                'operation_name' => 'start', 'wait_for' => 'completed', 'wait_timeout_seconds' => 0, 'request_payload' => Avro::envelope([])]],
        ])->assertOk();
        $call = WorkflowServiceCall::query()->sole();
        $this->assertTrue($call->metadata['control_plane']['started']);
        $this->assertSame('started_new', $call->metadata['control_plane']['outcome']);

        return [WorkflowRun::query()->findOrFail($started->json('run_id')), $call,
            WorkflowRun::query()->findOrFail($call->linked_workflow_run_id)];
    }

    private function cancel(WorkflowRun $run, int $timeout = 30): TestResponse
    {
        return $this->withHeaders($this->controlHeaders())->postJson('/api/workflows/'.$run->workflow_instance_id.'/request-cancellation', [
            'reason' => 'original-request', 'cleanup_timeout_seconds' => $timeout]);
    }

    private function register(string $id, string $queue, array $capabilities): void
    {
        $this->withHeaders($this->workerHeaders($capabilities === [] ? '1.19' : '1.20'))->postJson('/api/worker/register', [
            'worker_id' => $id, 'task_queue' => $queue, 'runtime' => 'php', 'sdk_version' => 'source-test',
            'supported_workflow_types' => ['tests.nexus-caller', 'tests.nexus-target'], 'capabilities' => $capabilities,
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest()])->assertCreated();
    }

    private function controlHeaders(): array
    {
        return ['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'];
    }

    private function workerHeaders(string $version = '1.20'): array
    {
        return ['X-Namespace' => 'default', WorkerProtocol::HEADER => $version];
    }
}
