<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\WorkflowReplayEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class WorkflowDefinitionEvolutionTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    private const TYPE = 'remote.definition-evolution';

    private const QUEUE = 'definition-evolution';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        WorkerCompatibilityFleet::clear();
        $this->createNamespace('default');
        config(['server.polling.timeout' => 0, 'workflows.v2.compatibility.pin_to_recorded_fingerprint' => true]);
    }

    public function test_default_replacement_can_claim_when_the_recorded_definition_is_gone(): void
    {
        [$run, $task, $started] = $this->original();
        $original = [$started->getRawOriginal('payload'), $run->getRawOriginal('run_deadline_at'), $run->getRawOriginal('execution_deadline_at')];
        $this->deleteJson('/api/workers/original-worker', [], $this->apiHeaders())->assertOk();
        $this->worker('replacement-worker', 'definition-v2');

        $this->poll('replacement-worker')->assertOk()
            ->assertJsonPath('task.run_id', $run->id)
            ->assertJsonPath('task.task_id', $task->id);

        self::assertSame($original, [
            $started->fresh()->getRawOriginal('payload'), $run->fresh()->getRawOriginal('run_deadline_at'), $run->fresh()->getRawOriginal('execution_deadline_at'),
        ]);
        self::assertSame(1, $task->fresh()->attempt_count);
        self::assertSame(0, $task->fresh()->repair_count);
    }

    public function test_explicit_opt_out_does_not_wait_for_a_matching_peer(): void
    {
        [$run, $task] = $this->original();
        $this->worker('replacement-worker', 'definition-v2');
        config(['workflows.v2.compatibility.pin_to_recorded_fingerprint' => false]);

        $this->poll('replacement-worker')->assertOk()
            ->assertJsonPath('task.run_id', $run->id)
            ->assertJsonPath('task.task_id', $task->id);
    }

    public function test_default_keeps_the_matching_registered_peer_preference(): void
    {
        [, $task] = $this->original();
        $this->worker('replacement-worker', 'definition-v2');

        $this->poll('replacement-worker')->assertOk()->assertJsonPath('task', null);
        self::assertSame(TaskStatus::Ready, $task->fresh()->status);
        self::assertNull($task->fresh()->lease_owner);
        self::assertSame(0, $task->fresh()->attempt_count);

        $this->poll('original-worker')->assertOk()->assertJsonPath('task.task_id', $task->id);
    }

    #[DataProvider('ineligibleRecordedWorkers')]
    public function test_an_ineligible_recorded_peer_does_not_strand_a_replacement(array $attributes): void
    {
        [, $task] = $this->original();
        WorkerRegistration::query()->where('worker_id', 'original-worker')->firstOrFail()
            ->forceFill($attributes)->save();
        $this->worker('replacement-worker', 'definition-v2');

        $this->poll('replacement-worker')->assertOk()->assertJsonPath('task.task_id', $task->id);
        self::assertSame(1, $task->fresh()->attempt_count);
        self::assertSame(0, $task->fresh()->repair_count);
    }

    public static function ineligibleRecordedWorkers(): array
    {
        return [
            'stale heartbeat' => [['last_heartbeat_at' => '2000-01-01 00:00:00']],
            'missing heartbeat' => [['last_heartbeat_at' => null]],
            'draining' => [['status' => 'draining']],
            'other namespace' => [['namespace' => 'other']],
            'other queue' => [['task_queue' => 'other']],
            'other type' => [['supported_workflow_types' => ['other']]],
            'no workflow capacity' => [['max_concurrent_workflow_tasks' => 0]],
            'missing definition' => [['workflow_definition_fingerprints' => []]],
        ];
    }

    public function test_busy_matching_peer_retains_preference_without_inventing_slot_authority(): void
    {
        [, $task] = $this->original();
        WorkerRegistration::query()->where('worker_id', 'original-worker')->update(['available_workflow_slots' => 0]);
        $this->worker('replacement-worker', 'definition-v2');

        $this->poll('replacement-worker')->assertOk()->assertJsonPath('task', null);
        self::assertSame(TaskStatus::Ready, $task->fresh()->status);
        self::assertSame(0, $task->fresh()->attempt_count);
    }

    public function test_fallback_does_not_remove_the_original_build_pin(): void
    {
        [$run, $task] = $this->original();
        $run->forceFill(['compatibility' => 'build-v1'])->save();
        $task->forceFill(['compatibility' => 'build-v1'])->save();
        $this->deleteJson('/api/workers/original-worker', [], $this->apiHeaders())->assertOk();
        $this->worker('replacement-worker', 'definition-v2', ['build_id' => 'build-v2']);

        $this->poll('replacement-worker', ['build_id' => 'build-v2'])->assertOk()->assertJsonPath('task', null);
        self::assertSame(TaskStatus::Ready, $task->fresh()->status);
        self::assertSame(0, $task->fresh()->attempt_count);
        self::assertSame('build-v1', $run->fresh()->compatibility);
    }

    public function test_a_matching_peer_on_another_build_cannot_suppress_a_pinned_replacement(): void
    {
        [$run, $task] = $this->original();
        $run->forceFill(['compatibility' => 'build-v1'])->save();
        $task->forceFill(['compatibility' => 'build-v1'])->save();
        WorkerRegistration::query()->where('worker_id', 'original-worker')->update(['build_id' => 'build-v2']);
        $this->worker('replacement-worker', 'definition-v2', ['build_id' => 'build-v1']);

        $this->poll('replacement-worker', ['build_id' => 'build-v1'])->assertOk()->assertJsonPath('task.task_id', $task->id);
        self::assertSame('build-v1', $run->fresh()->compatibility);
    }

    public function test_matching_peer_must_support_the_recorded_replay_protocol(): void
    {
        [$run, $task] = $this->original();
        $worker = WorkerRegistration::query()->where('worker_id', 'original-worker')->firstOrFail();
        $worker->forceFill(['process_metrics' => [WorkflowReplayEligibility::PROTOCOL_METRIC => '1.16']])->save();
        WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->max('sequence') + 1,
            'event_type' => 'TimerScheduled',
            'payload' => ['condition_wait_occurrence_id' => 'original-condition-occurrence'],
        ]);
        $this->worker('replacement-worker', 'definition-v2');

        $this->poll('replacement-worker')->assertOk()->assertJsonPath('task.task_id', $task->id);
    }

    public function test_client_process_metrics_cannot_spoof_the_saved_protocol(): void
    {
        $this->worker('original-worker', 'definition-v1', [
            'process_metrics' => [WorkflowReplayEligibility::PROTOCOL_METRIC => '999.0', 'memory_bytes' => 123],
        ]);
        $worker = WorkerRegistration::query()->where('worker_id', 'original-worker')->firstOrFail();
        self::assertSame($this->workerHeaders()['X-Durable-Workflow-Protocol-Version'], WorkflowReplayEligibility::recordedProtocol($worker));
        self::assertSame(123, $worker->process_metrics['memory_bytes']);

        $this->postJson('/api/worker/heartbeat', [
            'worker_id' => 'original-worker', 'process_metrics' => [WorkflowReplayEligibility::PROTOCOL_METRIC => '999.0', 'memory_bytes' => 456],
        ], $this->workerHeaders())->assertOk();
        self::assertSame($this->workerHeaders()['X-Durable-Workflow-Protocol-Version'], WorkflowReplayEligibility::recordedProtocol($worker->fresh()));
        self::assertSame(456, $worker->fresh()->process_metrics['memory_bytes']);
    }

    public function test_run_debug_queue_and_repair_distinguish_fallback_from_a_real_block(): void
    {
        [$run, $task] = $this->original();
        $this->deleteJson('/api/workers/original-worker', [], $this->apiHeaders())->assertOk();
        $this->worker('replacement-worker', 'definition-v2');
        $this->getJson('/api/workflows/definition-original', $this->apiHeaders())->assertOk()
            ->assertJsonPath('definition_routing.status', 'replay_required')
            ->assertJsonPath('definition_routing.blocked', false)
            ->assertJsonPath('definition_routing.recorded_fingerprint', 'definition-v1');
        $this->getJson('/api/workflows/definition-original/runs/'.$run->id, $this->apiHeaders())->assertOk()
            ->assertJsonPath('definition_routing.status', 'replay_required');
        $this->getJson('/api/workflows/definition-original/debug', $this->apiHeaders())->assertOk()
            ->assertJsonPath('definition_routing.blocked', false)
            ->assertJsonMissing(['code' => 'workflow_definition_routing_blocked']);

        WorkerRegistration::query()->where('worker_id', 'replacement-worker')->firstOrFail()
            ->forceFill(['workflow_definition_fingerprints' => []])->save();
        $this->getJson('/api/workflows/definition-original', $this->apiHeaders())->assertOk()
            ->assertJsonPath('definition_routing.status', 'worker_definition_missing')
            ->assertJsonPath('definition_routing.blocked', true);
        $this->getJson('/api/workflows/definition-original/debug', $this->apiHeaders())->assertOk()
            ->assertJsonPath('diagnostic_status', 'needs_attention')
            ->assertJsonFragment(['code' => 'workflow_definition_routing_blocked']);
        $this->getJson('/api/task-queues/'.self::QUEUE, $this->apiHeaders())->assertOk()
            ->assertJsonPath('definition_routing.blocked_sample_count', 1)
            ->assertJsonPath('definition_routing.runs.0.run_id', $run->id)
            ->assertJsonPath('repair.needs_attention', true);
        $repair = $this->postJson('/api/workflows/definition-original/repair', [
            'request_id' => 'definition-routing-repair',
        ], $this->apiHeaders())->assertOk()
            ->assertJsonPath('definition_routing.status', 'worker_definition_missing')
            ->assertJsonPath('definition_routing.blocked', true);
        $command = WorkflowCommand::query()->findOrFail($repair->json('command_id'));
        self::assertSame($command->commandReason(), $repair->json('reason'));
        self::assertSame($command->outcome->value, $repair->json('outcome'));
        self::assertSame(0, $task->fresh()->attempt_count);
        self::assertSame(0, $task->fresh()->repair_count);

        $this->createNamespace('other');
        $this->getJson('/api/task-queues/'.self::QUEUE, $this->apiHeaders('other'))->assertOk()
            ->assertJsonPath('definition_routing.runs', []);
    }

    #[DataProvider('notRunnableTasks')]
    public function test_definition_drift_is_not_a_ready_task_block_when_delivery_is_not_due(string $case): void
    {
        [$run, $task] = $this->original();
        $this->deleteJson('/api/workers/original-worker', [], $this->apiHeaders())->assertOk();
        if ($case === 'terminal') {
            $run->forceFill(['status' => 'completed', 'closed_at' => now()])->save();
        } elseif ($case === 'leased') {
            $task->forceFill(['status' => TaskStatus::Leased, 'lease_owner' => 'original-worker'])->save();
        } else {
            $task->forceFill(['available_at' => now()->addMinutes(1)])->save();
        }
        $this->getJson('/api/workflows/definition-original', $this->apiHeaders())->assertOk()
            ->assertJsonPath('definition_routing.runnable_task', false)
            ->assertJsonPath('definition_routing.blocked', false);
        $this->getJson('/api/workflows/definition-original/debug', $this->apiHeaders())->assertOk()
            ->assertJsonMissing(['code' => 'workflow_definition_routing_blocked']);
    }

    public static function notRunnableTasks(): array
    {
        return [['terminal'], ['leased'], ['future']];
    }

    private function original(): array
    {
        $this->worker('original-worker', 'definition-v1');
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'definition-original', 'workflow_type' => self::TYPE, 'task_queue' => self::QUEUE,
            'run_timeout_seconds' => 60, 'execution_timeout_seconds' => 120,
        ], $this->apiHeaders())->assertCreated();
        $run = WorkflowRun::query()->findOrFail($start->json('run_id'));
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->firstOrFail();
        $started = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', 'WorkflowStarted')->firstOrFail();
        self::assertSame('worker', $started->payload['workflow_definition_fingerprint_source']);
        self::assertSame('definition-v1', $started->payload['workflow_definition_fingerprint']);

        return [$run, $task, $started];
    }

    private function worker(string $id, string $fingerprint, array $overrides = []): void
    {
        $this->postJson('/api/worker/register', [
            'worker_id' => $id, 'task_queue' => self::QUEUE, 'runtime' => 'python', 'sdk_version' => '2.6.1',
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'supported_workflow_types' => [self::TYPE],
            'workflow_definition_fingerprints' => [self::TYPE => $fingerprint],
            'workflow_command_contracts' => [self::TYPE => ['signals' => [], 'queries' => [], 'updates' => [], 'update_validators' => []]],
            ...$overrides,
        ], $this->workerHeaders())->assertCreated();
    }

    private function poll(string $id, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $id, 'task_queue' => self::QUEUE, 'timeout_seconds' => 0,
            ...$overrides,
        ], $this->workerHeaders());
    }
}
