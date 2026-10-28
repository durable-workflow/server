<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SignalWaitingAckWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\SignalWaits;
use Workflow\V2\WorkflowStub;

final class WorkflowWaitingAcknowledgementSignalTest extends TestCase
{
    use RefreshDatabase;

    public static function timings(): iterable
    {
        yield 'during lease, definition available' => [true, true];
        yield 'after acknowledgement, definition available' => [false, true];
        yield 'during lease, durable contract only' => [true, false];
        yield 'after acknowledgement, durable contract only' => [false, false];
    }

    #[DataProvider('timings')]
    public function test_received_signal_gets_a_turn_after_an_ordinary_waiting_acknowledgement(bool $duringLease, bool $definitionAvailable): void
    {
        Queue::fake();
        config(['server.worker_protocol.version' => '1.20',
            'workflows.v2.types.workflows' => ['tests.signal-waiting-ack' => SignalWaitingAckWorkflow::class]]);
        WorkflowNamespace::query()->create(['name' => 'default', 'description' => 'Test', 'retention_days' => 30, 'status' => 'active']);
        $headers = ['X-Namespace' => 'default', WorkerProtocol::HEADER => '1.20'];
        $this->withHeaders(['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'])
            ->postJson('/api/workflows', ['workflow_type' => 'tests.signal-waiting-ack', 'task_queue' => 'waiting-ack', 'input' => []])->assertCreated();
        $run = WorkflowRun::query()->where('workflow_type', 'tests.signal-waiting-ack')->sole();
        $workflow = WorkflowStub::load($run->workflow_instance_id, 'default');
        $this->withHeaders($headers)->postJson('/api/worker/register', [
            'worker_id' => 'waiting-worker', 'task_queue' => $run->queue, 'runtime' => 'php',
            'supported_workflow_types' => ['tests.signal-waiting-ack'], 'capabilities' => [],
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'max_concurrent_workflow_tasks' => 1,
            'process_metrics' => ['host' => 'test-worker', 'process_started_at' => now()->toISOString(), 'process_id' => 10],
        ])->assertCreated();
        $initial = $this->withHeaders($headers)->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'waiting-worker', 'task_queue' => $run->queue,
        ])->assertOk()->json('task');
        $this->assertIsArray($initial);
        // Independent protocol waits allow a timer replay while signal waits
        // remain open. This tests HTTP admission and acknowledgement, not SDK authoring.
        $commands = [['type' => 'open_signal_wait', 'signal_name' => 'advance'],
            ['type' => 'open_signal_wait', 'signal_name' => 'finish'],
            ['type' => 'start_timer', 'delay_seconds' => 1]];
        $this->withHeaders($headers)->postJson("/api/worker/workflow-tasks/{$initial['task_id']}/complete", [
            'lease_owner' => $initial['lease_owner'], 'workflow_task_attempt' => $initial['workflow_task_attempt'],
            'commands' => $commands,
        ])->assertOk()->assertJsonPath('recorded', true);
        $timer = WorkflowTask::query()->where('workflow_run_id', $run->id)->where('task_type', 'timer')->sole();
        $this->travel(2)->seconds();
        (new RunTimerTask($timer->id))->handle();
        $issued = $this->withHeaders($headers)->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'waiting-worker', 'task_queue' => $run->queue,
        ])->assertOk()->json('task');
        $this->assertIsArray($issued);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->where('event_type', 'TimerFired')->count());
        $this->assertArrayNotHasKey('workflow_signal_id', WorkflowTask::query()->findOrFail($issued['task_id'])->payload);
        $history = app(WorkflowTaskBridge::class)->historyPayload($issued['task_id']);
        $this->assertNotContains('SignalReceived', array_column($history['history_events'], 'event_type'));
        $this->assertCount(2, array_filter(SignalWaits::forRun($run->fresh()), static fn ($wait) => $wait['status'] === 'open'));
        if (! $definitionAvailable) {
            config(['workflows.v2.types.workflows' => []]);
            $run->forceFill(['workflow_class' => 'UnavailableSignalWaitingAckWorkflow'])->save();
            $run->instance->forceFill(['workflow_class' => 'UnavailableSignalWaitingAckWorkflow'])->save();
        }
        $signal = $duringLease ? $workflow->signal('advance') : null;
        $this->withHeaders($headers)->postJson("/api/worker/workflow-tasks/{$issued['task_id']}/fail", [
            'lease_owner' => $issued['lease_owner'], 'workflow_task_attempt' => $issued['workflow_task_attempt'],
            'failure' => ['type' => 'WorkflowTaskWaitingForHistory', 'message' => 'history is not ready yet'],
        ])->assertOk()->assertJsonPath('outcome', 'waiting_for_history')->assertJsonPath('recorded', true);
        $signal ??= $workflow->signal('advance');
        $this->assertTrue($signal->accepted());
        $ready = WorkflowTask::query()->where('workflow_run_id', $run->id)->where('task_type', 'workflow')
            ->where('status', 'ready')->get();
        $this->assertCount(1, $ready);
        $this->assertSame($signal->commandId(), $ready->sole()->payload['workflow_command_id']);
    }
}
