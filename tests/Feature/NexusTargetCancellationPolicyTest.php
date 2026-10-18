<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\NexusCooperativeCancellationPolicy;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

class NexusTargetCancellationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['server.worker_protocol.version' => '1.20',
            'workflows.v2.types.workflows' => []]);
        WorkflowNamespace::query()->create(['name' => 'default', 'retention_days' => 30, 'status' => 'active']);
    }

    public function test_known_unsupported_target_reports_original_worker_without_mutation(): void
    {
        $run = $this->start();
        $this->register('old', []);
        $before = $run->getRawOriginal();
        $history = $run->historyEvents()->count();
        $diagnostic = NexusCooperativeCancellationPolicy::targetUnavailable($run);
        $this->assertSame('nexus_cooperative_cancellation_not_supported', $diagnostic['reason']);
        $this->assertFalse($diagnostic['retryable']);
        $this->assertSame(['cooperative_cancellation'], $diagnostic['required_capabilities']);
        $this->assertSame('old', $diagnostic['available_workers'][0]['worker_id']);
        $this->assertSame($run->id, $diagnostic['target']['run_id']);
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        $this->assertSame($history, $run->historyEvents()->count());
    }

    #[DataProvider('unavailableMatchingWorkers')]
    public function test_absence_or_nonmatching_worker_is_retryable(string $mismatch): void
    {
        $run = $this->start();
        if ($mismatch !== 'absent') {
            $worker = $this->register('available-elsewhere', ['cooperative_cancellation']);
            $changes = match ($mismatch) {
                'namespace' => ['namespace' => 'another'],
                'queue' => ['task_queue' => 'another'],
                'type' => ['supported_workflow_types' => ['another']],
                'build' => ['build_id' => 'another'],
                'stale' => ['last_heartbeat_at' => now()->subDay()],
                'definition' => ['workflow_definition_fingerprints' => ['tests.external-greeting-workflow' => 'another']],
            };
            $worker->forceFill($changes)->save();
            if ($mismatch === 'build') {
                $run->forceFill(['compatibility' => 'original'])->save();
            }
            if ($mismatch === 'definition') {
                $started = $run->historyEvents()->where('event_type', HistoryEventType::WorkflowStarted)->sole();
                $started->forceFill(['payload' => [...$started->payload,
                    'workflow_definition_fingerprint_source' => 'worker', 'workflow_definition_fingerprint' => 'original']])->save();
            }
        }
        $diagnostic = NexusCooperativeCancellationPolicy::targetUnavailable($run);
        $this->assertTrue($diagnostic['retryable']);
        $this->assertSame('nexus_cooperative_cancellation_temporarily_unavailable', $diagnostic['reason']);
        $this->assertSame(['target_matching_worker_unavailable'], $diagnostic['unavailable']);
        $this->assertSame([], $diagnostic['available_workers']);
    }

    public static function unavailableMatchingWorkers(): array
    {
        return array_map(static fn ($value) => [$value], ['absent', 'namespace', 'queue', 'type', 'build', 'stale', 'definition']);
    }

    #[DataProvider('retainedServiceBoundaries')]
    public function test_target_service_boundary_requires_both_flags(bool $resolved, bool $delivered): void
    {
        $run = $this->start();
        $this->register('ordinary', ['cooperative_cancellation']);
        WorkflowHistoryEvent::record($run, HistoryEventType::ServiceCallStarted, [
            'sequence' => 1, 'service_call_id' => 'nested-call', 'operation_mode' => 'sync', 'wait_for' => 'completed']);
        if ($resolved) {
            $run->forceFill(['cancellation_request_command_id' => 'original-request'])->save();
            WorkflowHistoryEvent::record($run, HistoryEventType::CooperativeCancellationRequested, ['workflow_command_id' => 'original-request']);
            WorkflowHistoryEvent::record($run, HistoryEventType::ServiceCallCancelled, ['sequence' => 1]);
        }
        if ($delivered) {
            WorkflowHistoryEvent::record($run, HistoryEventType::CooperativeCancellationDelivered, ['sequence' => 1, 'call_kind' => 'service_operation']);
        }
        $diagnostic = NexusCooperativeCancellationPolicy::targetUnavailable($run);
        $this->assertSame(['cooperative_cancellation', 'nexus_cooperative_cancellation'], $diagnostic['required_capabilities']);
        $this->register('nexus', ['cooperative_cancellation', 'nexus_cooperative_cancellation']);
        $this->assertNull(NexusCooperativeCancellationPolicy::targetUnavailable($run));
    }

    public static function retainedServiceBoundaries(): array
    {
        return ['pending' => [false, false], 'completed before delivery' => [true, false], 'cold retained delivery' => [true, true]];
    }

    public function test_completed_service_result_before_target_request_remains_ordinary_cleanup(): void
    {
        $run = $this->start();
        $this->register('ordinary', ['cooperative_cancellation']);
        WorkflowHistoryEvent::record($run, HistoryEventType::ServiceCallStarted, [
            'sequence' => 1, 'service_call_id' => 'completed-call', 'operation_mode' => 'sync', 'wait_for' => 'completed',
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ServiceCallCompleted, ['sequence' => 1]);
        $this->assertNull(NexusCooperativeCancellationPolicy::targetUnavailable($run));
    }

    public function test_second_capable_worker_and_same_id_change_cannot_upgrade_old_claim(): void
    {
        $run = $this->start();
        $worker = $this->register('original', []);
        $task = $this->claim('original', '1.19');
        $worker->forceFill(['capabilities' => ['cooperative_cancellation'], 'sdk_version' => 'replacement'])->save();
        $this->register('second', ['cooperative_cancellation']);
        $diagnostic = NexusCooperativeCancellationPolicy::targetUnavailable($run);
        $this->assertFalse($diagnostic['retryable']);
        $this->assertSame(['target_active_claim_cancellation_not_supported'], $diagnostic['unavailable']);
        $this->assertSame('original-sdk', $diagnostic['active_claims'][0]['sdk_version']);
        $this->assertSame($task->id, $diagnostic['active_claims'][0]['task_id']);
    }

    public function test_capable_claim_keeps_its_proof_but_cannot_cross_registration_incarnations(): void
    {
        $run = $this->start();
        $worker = $this->register('original', ['cooperative_cancellation']);
        $this->claim('original', '1.20');
        $worker->forceFill(['capabilities' => []])->save();
        $this->assertNull(NexusCooperativeCancellationPolicy::targetUnavailable($run));
        $worker->forceFill(['registration_token' => 'new-incarnation'])->save();
        $this->register('second', ['cooperative_cancellation']);
        $diagnostic = NexusCooperativeCancellationPolicy::targetUnavailable($run);
        $this->assertTrue($diagnostic['retryable']);
        $this->assertSame(['target_active_claim_incarnation_unavailable'], $diagnostic['unavailable']);
    }

    public function test_embedded_target_remains_backend_owned(): void
    {
        $run = $this->start();
        $run->forceFill(['workflow_class' => ExternalGreetingWorkflow::class])->save();
        $this->assertNull(NexusCooperativeCancellationPolicy::targetUnavailable($run));
    }

    private function start(): WorkflowRun
    {
        $response = $this->withHeaders(['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'])
            ->postJson('/api/workflows', ['workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'cooperative', 'input' => ['Ada']])
            ->assertCreated();

        return WorkflowRun::query()->findOrFail($response->json('run_id'));
    }

    private function register(string $id, array $capabilities): WorkerRegistration
    {
        $this->withHeaders(['X-Namespace' => 'default', WorkerProtocol::HEADER => $capabilities === [] ? '1.19' : '1.20'])
            ->postJson('/api/worker/register', ['worker_id' => $id, 'task_queue' => 'cooperative', 'runtime' => 'php',
                'sdk_version' => 'original-sdk', 'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'capabilities' => $capabilities, 'capability_manifest' => $this->portableWorkerAffinityRefusalManifest()])->assertCreated();

        return WorkerRegistration::query()->where('worker_id', $id)->sole();
    }

    private function claim(string $id, string $version): WorkflowTask
    {
        $task = $this->withHeaders(['X-Namespace' => 'default', WorkerProtocol::HEADER => $version])
            ->postJson('/api/worker/workflow-tasks/poll', ['worker_id' => $id, 'task_queue' => 'cooperative'])
            ->assertOk()->json('task');

        return WorkflowTask::query()->findOrFail($task['task_id']);
    }
}
