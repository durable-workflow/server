<?php

namespace Tests\Source;

use App\Models\WorkflowNamespace;
use App\Support\CooperativeCancellationPolicy;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\CancellationCascadeView;

/** Exact Native source overlay qualification, deliberately outside locked-package feature CI. */
class CancellationCascadeDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(class_exists(CancellationCascadeView::class), 'This qualification requires the exact Native cancellation inspection source.');
        Queue::fake();
        config([
            'server.worker_protocol.version' => '1.20',
            'workflows.v2.types.workflows' => ['tests.external-greeting-workflow' => ExternalGreetingWorkflow::class],
        ]);
        WorkflowNamespace::query()->create([
            'name' => 'default', 'description' => 'Test', 'retention_days' => 30, 'status' => 'active',
        ]);
        $this->travelTo(now()->startOfSecond());
    }

    public function test_requested_delivered_and_completed_cleanup_share_the_original_budget(): void
    {
        [$workflowId, $runId] = $this->start();
        $task = $this->poll()->json('task');
        $original = $this->requestCancellation($workflowId)->assertStatus(202)->json('cancellation_request');
        $before = WorkflowHistoryEvent::query()->count();
        $requested = $this->debug($workflowId)->assertOk()
            ->assertJsonPath('cancellation_cascade_supported', true)
            ->assertJsonPath('cancellation_cascade.schema', 'durable-workflow.cancellation-cascade/v1')
            ->assertJsonPath('cancellation_cascade.selected_run_id', $runId)
            ->assertJsonPath('cancellation_cascade.root.root_request_id', $original['request_id'])
            ->assertJsonPath('cancellation_cascade.root.cleanup_deadline_at', $original['cleanup_deadline_at'])
            ->assertJsonPath('cancellation_cascade.runs.0.lifecycle', 'requested')
            ->assertJsonPath('cancellation_cascade.inspection_complete', true)
            ->assertJsonPath('cancellation_cascade.truncated', false);
        $this->assertSame($before, WorkflowHistoryEvent::query()->count(), 'Diagnostics must not mutate history.');
        $this->assertSame([], $requested->json('cancellation_cascade.runs.0.activity_stops'));

        $this->deliver($task, $original['request_id'])->assertOk()->assertJsonPath('delivered', true);
        $this->debug($workflowId)->assertOk()
            ->assertJsonPath('cancellation_cascade.runs.0.lifecycle', 'delivered')
            ->assertJsonPath('cancellation_cascade.runs.0.delivery.sequence', 1);
        $this->complete($task, [['type' => 'complete_workflow']])->assertOk()->assertJsonPath('run_status', 'cancelled');
        $finished = $this->debug($workflowId, $runId)->assertOk()
            ->assertJsonPath('cancellation_cascade.runs.0.lifecycle', 'cancelled')
            ->assertJsonPath('cancellation_cascade.runs.0.cleanup.outcome', 'completed')
            ->assertJsonPath('cancellation_cascade.runs.0.cleanup.cleanup_deadline_at', $original['cleanup_deadline_at'])
            ->assertJsonPath('cancellation_cascade.inspection_complete', true)
            ->json('cancellation_cascade');
        $this->travel(10)->seconds();
        $this->requestCancellation($workflowId, 300)->assertOk()
            ->assertJsonPath('duplicate', true)->assertJsonPath('cancellation_request.request_id', $original['request_id'])
            ->assertJsonPath('cancellation_request.cleanup_deadline_at', $original['cleanup_deadline_at']);
        $this->assertSame($finished, $this->debug($workflowId, $runId)->assertOk()->json('cancellation_cascade'));
    }

    public function test_child_propagation_is_visible_from_either_run_without_inventing_a_second_root(): void
    {
        [$workflowId, $runId] = $this->start();
        $task = $this->poll()->json('task');
        $this->complete($task, [[
            'type' => 'start_child_workflow', 'workflow_type' => 'tests.external-greeting-workflow',
            'cancellation_policy' => 'wait_cancellation_completed',
        ]])->assertOk();
        $link = WorkflowLink::query()->where('parent_workflow_run_id', $runId)->where('link_type', 'child_workflow')->sole();
        $original = $this->requestCancellation($workflowId)->assertStatus(202)->json('cancellation_request');
        // The child was scheduled first. Hold its claim so the following poll obtains the parent cancellation task.
        $childTask = $this->poll('child')->assertOk()->json('task');
        $this->assertSame($link->child_workflow_run_id, $childTask['run_id']);
        $parentTask = $this->poll('parent')->assertOk()->json('task');
        $this->assertSame($runId, $parentTask['run_id']);
        $this->deliver($parentTask, $original['request_id'], 'child')->assertOk()
            ->assertJsonPath('delivered', false)->assertJsonPath('reason', 'cancellation_waiting_for_child');

        foreach ([[$workflowId, $runId], [$link->child_workflow_instance_id, $link->child_workflow_run_id]] as [$selectedWorkflow, $selectedRun]) {
            $view = $this->debug($selectedWorkflow, $selectedRun)->assertOk()
                ->assertJsonPath('cancellation_cascade.selected_run_id', $selectedRun)
                ->assertJsonPath('cancellation_cascade.root.root_request_id', $original['request_id'])
                ->assertJsonPath('cancellation_cascade.root.cleanup_deadline_at', $original['cleanup_deadline_at'])
                ->assertJsonPath('cancellation_cascade.inspection_complete', true)->json('cancellation_cascade');
            $this->assertCount(2, $view['runs']);
            $this->assertCount(1, $view['edges']);
            $this->assertSame($runId, $view['edges'][0]['parent_run_id']);
            $this->assertSame($link->child_workflow_run_id, $view['edges'][0]['child_run_id']);
            $this->assertSame('requested', $view['runs'][1]['lifecycle']);
            $this->assertTrue($view['runs'][1]['same_root_budget']);
            $this->assertNotSame($original['request_id'], $view['runs'][1]['request']['request_id']);
            $this->assertSame($original['request_id'], $view['runs'][1]['request']['parent_request_id']);
            $this->assertSame('wait_cancellation_completed', $view['runs'][0]['child_propagation'][0]['policy']);
        }
    }

    public function test_a_deadline_expiry_is_a_terminal_outcome_separate_from_completed_cleanup(): void
    {
        [$workflowId] = $this->start();
        $task = $this->poll()->json('task');
        $original = $this->requestCancellation($workflowId, 1)->assertStatus(202)->json('cancellation_request');
        $this->travel(2)->seconds();
        $this->deliver($task, $original['request_id'])->assertStatus(409)->assertJsonPath('reason', 'run_cancelled');
        $this->debug($workflowId)->assertOk()
            ->assertJsonPath('cancellation_cascade.runs.0.projected_status', 'cancelled')
            ->assertJsonPath('cancellation_cascade.runs.0.lifecycle', 'deadline_expired')
            ->assertJsonPath('cancellation_cascade.runs.0.cleanup.outcome', 'deadline_expired')
            ->assertJsonPath('cancellation_cascade.runs.0.delivery', null)
            ->assertJsonPath('cancellation_cascade.inspection_complete', true);
    }

    public function test_missing_canonical_history_cannot_be_presented_as_a_successful_cascade(): void
    {
        [$workflowId, $runId] = $this->start();
        $this->requestCancellation($workflowId)->assertStatus(202);
        WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::CooperativeCancellationRequested->value)->delete();
        $view = $this->debug($workflowId)->assertOk()
            ->assertJsonPath('cancellation_cascade.root', null)
            ->assertJsonPath('cancellation_cascade.inspection_complete', false)->json('cancellation_cascade');
        $this->assertContains('request_history_unavailable', array_column($view['findings'], 'code'));
    }

    public function test_related_foreign_runs_and_their_cancellation_metadata_are_not_exposed(): void
    {
        [$workflowId, $runId] = $this->start();
        $this->requestCancellation($workflowId)->assertStatus(202);
        [$foreignWorkflow, $foreignRunId] = $this->start();
        $this->requestCancellation($foreignWorkflow)->assertStatus(202);
        $foreign = WorkflowRun::query()->findOrFail($foreignRunId);
        $foreign->instance->forceFill(['namespace' => 'foreign'])->save();
        $foreign->forceFill(['namespace' => 'foreign'])->save();
        WorkflowLink::query()->create([
            'parent_workflow_instance_id' => $workflowId, 'parent_workflow_run_id' => $runId,
            'child_workflow_instance_id' => $foreignWorkflow, 'child_workflow_run_id' => $foreignRunId,
            'link_type' => 'child_workflow', 'sequence' => 99, 'is_primary_parent' => false,
        ]);
        $view = $this->debug($workflowId)->assertOk()
            ->assertJsonPath('cancellation_cascade.inspection_complete', false)
            ->assertJsonPath('cancellation_cascade.edges.0.child_run_id', null)->json('cancellation_cascade');
        $encoded = json_encode($view, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($foreignWorkflow, $encoded);
        $this->assertStringNotContainsString($foreignRunId, $encoded);
        $this->debug($foreignWorkflow)->assertNotFound();
        $this->debug($workflowId, $foreignRunId)->assertNotFound();
    }

    public function test_run_scoped_debug_keeps_the_historical_cancellation_selection(): void
    {
        [$workflowId, $runId] = $this->start();
        $original = $this->requestCancellation($workflowId)->assertStatus(202)->json('cancellation_request');
        $selected = WorkflowRun::query()->findOrFail($runId);
        $replacement = $selected->replicate(['id']);
        $replacement->run_number = $selected->run_number + 1;
        $replacement->cancellation_request_command_id = null;
        $replacement->cancellation_requested_at = null;
        $replacement->cancellation_deadline_at = null;
        $replacement->save();
        $selected->instance->forceFill(['current_run_id' => $replacement->id])->save();
        $this->debug($workflowId)->assertOk()->assertJsonPath('run_id', $replacement->id)
            ->assertJsonPath('cancellation_cascade', null);
        $historical = $this->debug($workflowId, $runId)->assertOk()
            ->assertJsonPath('run_id', $runId)->assertJsonPath('cancellation_cascade.selected_run_id', $runId)
            ->assertJsonPath('cancellation_cascade.root.root_request_id', $original['request_id']);
        $this->assertStringNotContainsString($replacement->id, json_encode($historical->json('cancellation_cascade'), JSON_THROW_ON_ERROR));
    }

    private function start(): array
    {
        $started = $this->postJson('/api/workflows', [
            'workflow_type' => 'tests.external-greeting-workflow', 'task_queue' => 'cooperative', 'input' => ['Ada'],
        ], $this->controlHeaders())->assertCreated();

        return [$started->json('workflow_id'), $started->json('run_id')];
    }

    private function poll(string $workerId = 'inspector'): TestResponse
    {
        $this->postJson('/api/worker/register', [
            'worker_id' => $workerId, 'task_queue' => 'cooperative', 'runtime' => 'php',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capabilities' => [CooperativeCancellationPolicy::CAPABILITY],
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'max_concurrent_workflow_tasks' => 2,
        ], $this->workerHeaders())->assertCreated();

        return $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => $workerId, 'task_queue' => 'cooperative',
        ], $this->workerHeaders())->assertOk();
    }

    private function requestCancellation(string $workflowId, int $timeout = 30): TestResponse
    {
        return $this->postJson("/api/workflows/{$workflowId}/request-cancellation", [
            'reason' => 'Maintenance', 'cleanup_timeout_seconds' => $timeout,
        ], $this->controlHeaders());
    }

    private function deliver(array $task, string $requestId, string $kind = 'activity'): TestResponse
    {
        return $this->postJson("/api/worker/workflow-tasks/{$task['task_id']}/deliver-cancellation", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'request_id' => $requestId, 'sequence' => 1, 'call_kind' => $kind,
        ], $this->workerHeaders());
    }

    private function complete(array $task, array $commands): TestResponse
    {
        return $this->postJson("/api/worker/workflow-tasks/{$task['task_id']}/complete", [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => $commands,
        ], $this->workerHeaders());
    }

    private function debug(string $workflowId, ?string $runId = null): TestResponse
    {
        $target = $runId === null ? "/api/workflows/{$workflowId}" : "/api/workflows/{$workflowId}/runs/{$runId}";

        return $this->getJson($target.'/debug', $this->controlHeaders());
    }

    private function controlHeaders(): array
    {
        return ['X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'];
    }

    private function workerHeaders(): array
    {
        return ['X-Namespace' => 'default', WorkerProtocol::HEADER => '1.20'];
    }
}
